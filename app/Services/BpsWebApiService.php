<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class BpsWebApiService
{
    private const GENERIC_TERMS = ['rata', 'rata-rata', 'jumlah', 'persentase', 'angka', 'nilai', 'tingkat', 'banyak', 'indeks', 'pembangunan', 'manusia', 'status'];

    private const VARIABLE_INDEX_CACHE_PREFIX = 'bps-webapi:variables:index:';

    public function hasApiKey(): bool
    {
        $configuredKey = config('services.bps.api_key');

        return is_string($configuredKey) && trim($configuredKey) !== '';
    }

    public function checkCanonicalCoverage(array $indicatorNames = []): array
    {
        $apiKey = $this->apiKey();
        if ($apiKey === null) {
            throw new RuntimeException('API key WebAPI BPS belum dikonfigurasi.');
        }

        $currentYear = now('Asia/Jakarta')->year;
        $maxLagYears = (int) config('bps_indicators.max_lag_years', 1);
        $regions = config('sumsel_regions', []);
        if (count($regions) !== 17) {
            throw new RuntimeException('Pemeriksaan kanonik membutuhkan tepat 17 wilayah kabupaten/kota.');
        }
        $province = $this->provinceFallbackRegion();
        $variables = [];
        foreach (config('bps_indicators.groups', []) as $group) {
            foreach ($group['variables'] ?? [] as $variable) {
                if ($indicatorNames !== [] && ! in_array($variable['name'] ?? null, $indicatorNames, true)) {
                    continue;
                }
                $variables[] = $variable;
            }
        }

        $observations = [];
        foreach ($variables as $variable) {
            $variableId = (int) ($variable['var_id'] ?? 0);
            $title = (string) ($variable['title'] ?? '');
            if ($variableId <= 0 || $title === '') {
                throw new RuntimeException('Peta indikator kanonik memuat variabel tanpa ID atau judul.');
            }

            $regional = $this->explicitVariableLevel($title) === 'kab_kota';
            $targets = array_merge($regions, [$province]);
            $pending = [];
            foreach ($targets as $target) {
                $label = $target['label'];
                if ($label !== $province['label'] && ! $regional) {
                    $observations[] = [
                        'name' => $variable['name'],
                        'var_id' => $variableId,
                        'title' => $title,
                        'region' => $label,
                        'latest_year' => null,
                        'value' => null,
                        'status' => 'not_applicable',
                    ];
                    continue;
                }
                $pending[$label] = $target;
            }

            $periods = $this->canonicalPeriods($variableId, $apiKey);
            $periods = array_values(array_filter(
                $periods,
                fn (array $period) => (int) ($period['th'] ?? 0) <= $currentYear
            ));
            foreach ($periods as $period) {
                if ($pending === []) {
                    break;
                }
                $year = (int) ($period['th'] ?? 0);
                $periodId = (int) ($period['th_id'] ?? 0);
                if ($year <= 0 || $periodId <= 0) {
                    continue;
                }
                $response = $this->canonicalDataForPeriod($variableId, $periodId, $apiKey, $title, (string) $year);
                if ($response === null) {
                    continue;
                }

                $values = $response->json('datacontent');
                if (! is_array($values)) {
                    continue;
                }
                $vervar = $response->json('vervar');
                foreach ($pending as $label => $target) {
                    $regionRow = $label === $province['label']
                        ? (isset($variable['vervar_label'])
                            ? $this->vervarRowByLabel($vervar, (string) $variable['vervar_label'])
                            : $this->provinceVervarRow($vervar))
                        : $this->regionVervarRow($target, $vervar);
                    if ($regionRow === null) {
                        continue;
                    }
                    $regionalValues = $this->valuesForRegion($values, $regionRow);
                    foreach ($regionalValues as $value) {
                        if (! is_scalar($value) || trim((string) $value) === '') {
                            continue;
                        }
                        $observations[] = [
                            'name' => $variable['name'],
                            'var_id' => $variableId,
                            'title' => $title,
                            'region' => $label,
                            'latest_year' => $year,
                            'value' => $value,
                            'status' => $year < $currentYear - $maxLagYears ? 'stale' : 'current',
                        ];
                        unset($pending[$label]);
                        break;
                    }
                }
            }

            foreach ($pending as $label => $_target) {
                $observations[] = [
                    'name' => $variable['name'],
                    'var_id' => $variableId,
                    'title' => $title,
                    'region' => $label,
                    'latest_year' => null,
                    'value' => null,
                    'status' => 'missing',
                ];
            }
        }

        return $observations;
    }

    public function verifyCanonicalGoldens(array $cases): array
    {
        $indicatorNames = array_values(array_unique(array_map(
            fn (array $case) => (string) ($case['indicator'] ?? ''),
            $cases
        )));
        $observations = $this->checkCanonicalCoverage($indicatorNames);
        $results = [];
        foreach ($cases as $case) {
            $indicatorName = (string) ($case['indicator'] ?? '');
            $question = (string) ($case['question'] ?? '');
            $regionName = (string) ($case['region'] ?? '');
            $expectedId = (int) ($case['expected_var_id'] ?? 0);
            $expectedYear = (int) ($case['expected_year'] ?? 0);
            $expectedValue = $case['expected_value'] ?? null;
            $routedVariables = $this->curatedVariables($this->keywords($question));
            $routedId = (int) ($routedVariables[0]['var_id'] ?? 0);
            $actual = null;
            foreach ($observations as $observation) {
                if ($observation['name'] === $indicatorName && $observation['region'] === $regionName) {
                    $actual = $observation;
                    break;
                }
            }

            $passed = $actual !== null
                && $routedId === $expectedId
                && $actual['var_id'] === $expectedId
                && $actual['status'] === 'current'
                && ($expectedYear === 0 || $actual['latest_year'] === $expectedYear)
                && (is_numeric($actual['value'] ?? null))
                && ($expectedValue === null || (float) $actual['value'] === (float) $expectedValue);
            $results[] = [
                'question' => (string) ($case['question'] ?? ''),
                'expected_indicator' => $indicatorName,
                'expected_var_id' => $expectedId,
                'routed_var_id' => $routedId,
                'region' => $regionName,
                'expected_year' => $expectedYear ?: null,
                'expected_value' => $expectedValue,
                'actual' => $actual,
                'passed' => $passed,
            ];
        }

        return $results;
    }

    public function refreshVariableIndex(): array
    {
        $catalog = $this->localCatalogIndex();
        $variables = array_map(
            fn (array $variable) => $variable + [
                'vertical' => null,
                'level' => $this->explicitVariableLevel((string) $variable['title']),
            ],
            $catalog['variables']
        );

        if ($variables === []) {
            throw new RuntimeException('Katalog CSV BPS tidak memuat tabel dinamis.');
        }

        $verticalEvidence = [];
        $verticalCounts = [];
        foreach ($variables as $variable) {
            if ($variable['vertical'] === null) {
                continue;
            }
            $verticalCounts[$variable['vertical']] = ($verticalCounts[$variable['vertical']] ?? 0) + 1;
            if ($variable['level'] !== null) {
                $subjectName = $variable['subject'] !== '' ? $variable['subject'] : 'subjek '.$variable['subject_id'];
                $verticalEvidence[$variable['vertical']][$variable['level']][$subjectName] = true;
            }
        }
        $verticalEvidenceReport = [];
        foreach ($verticalCounts as $vertical => $count) {
            $verticalEvidenceReport[$vertical] = array_map('array_keys', $verticalEvidence[$vertical] ?? []);
        }
        foreach ($variables as &$variable) {
            $variable['level'] ??= 'unknown';
        }
        unset($variable);

        Cache::put(self::VARIABLE_INDEX_CACHE_PREFIX.'1600', [
            'variables' => $variables,
            'vertical_evidence' => $verticalEvidenceReport,
            'vertical_counts' => $verticalCounts,
            'refreshed_at' => now()->toIso8601String(),
        ], now()->addHours(26));

        return [
            'count' => count($variables),
            'vertical_evidence' => $verticalEvidenceReport,
            'vertical_counts' => $verticalCounts,
        ];
    }

    public function isDataQuestion(string $question): bool
    {
        return preg_match('/\b(berapa|persen\w*|persentase|jumlah|total|warga|masyarakat|angka|nilai|laju|tingkat|indeks|pertumbuhan|inflasi|miskin\w*|kemiskinan|penduduk|pdrb|bruto|ekspor|impor|upah|penganggur\w*|ekonomi|produksi|harga|gini|ntp|tpt|ipm|rls|hls|sekolah|indikator|data|statistik)\b/i', $question) === 1;
    }

    private function hasDynamicDataIntent(string $question): bool
    {
        return $this->isDataQuestion($question)
            || $this->matchFallbackTopic($question) !== null
            || preg_match('/\b(perikan\w*|ikan|tenaga|angkatan|pendidikan|sekolah|rls|hls|kesehatan|pengeluaran|pendapatan|konsumsi|wisata\w*|hotel|transportasi|terbaru)\b/i', $question) === 1
            || $this->catalogVariableMatches($question);
    }

    public function isRegionalDataQuestion(string $question): bool
    {
        return $this->regionMentioned($question) !== null && $this->hasDynamicDataIntent($question);
    }

    public function isUnavailableDataContext(?string $context): bool
    {
        return is_string($context) && str_starts_with($context, '[WebAPI BPS][DATA_BELUM_TERSEDIA]');
    }

    public function unavailableAnswerFor(?string $context): ?string
    {
        if (! $this->isUnavailableDataContext($context)) {
            return null;
        }

        $message = trim(preg_replace('/^\[WebAPI BPS\](?:\[[^\]]+\])+\s*/u', '', $context) ?? '');
        if ($message === '') {
            return null;
        }

        return 'Maaf, '.$message.' Silakan cek https://sumsel.bps.go.id.';
    }

    public function ambiguousIndicatorAnswer(?string $context): ?string
    {
        $prefix = '[WebAPI BPS][INDIKATOR_AMBIGU] ';
        if (! is_string($context) || ! str_starts_with($context, $prefix)) {
            return null;
        }

        return trim(substr($context, strlen($prefix)));
    }

    public function contextFor(string $question): ?string
    {
        $apiKey = $this->apiKey();
        $catalog = $this->catalogIntent($question);
        if ($catalog !== null && $apiKey === null) {
            return '[WebAPI BPS] API key belum dikonfigurasi. Beri tahu pengguna bahwa data '.$catalog['label'].' belum dapat diambil dan jangan mengarang daftar atau jumlah.';
        }
        $regionalFallbackQuestion = $this->isRegionalDataQuestion($question);
        if ($this->isMonthlyBpsTopic($question) && $apiKey === null && ! $regionalFallbackQuestion) {
            return $this->monthlyReleaseUnavailableContext($question);
        }
        if ($apiKey === null && $this->isDataQuestion($question) && $this->isCurrentPeriodRequest($question)) {
            return '[WebAPI BPS][DATA_BELUM_TERSEDIA] Data untuk periode terbaru/tahun berjalan belum dapat diverifikasi tanpa koneksi WebAPI BPS. Jangan gunakan periode lama sebagai pengganti.';
        }

        $keywords = $this->keywords($question);
        if ($apiKey === null || ($keywords === [] && $catalog === null)) {
            return null;
        }

        try {
            $domain = $this->domainFor($question);
            if ($catalog !== null) {
                return $this->catalogContext($catalog, $domain, $apiKey);
            }

            if ($this->isStaticWageQuestion($question)) {
                $tableContext = $this->staticTableContext($keywords, $domain, $apiKey, $question);
                if ($tableContext !== null) {
                    return $tableContext;
                }
                if ($regionalFallbackQuestion) {
                    $tableContext = $this->staticTableContext($keywords, '1600', $apiKey, $question);
                    if ($tableContext !== null) {
                        return $this->provinceScopeFallbackContext($tableContext, $this->regionMentioned($question));
                    }
                }
            }

            if ($this->isMonthlyBpsTopic($question)) {
                $region = $this->regionMentioned($question);
                $releaseDomain = ($region['domain'] ?? null) ?: $domain;
                $pressReleaseContext = $this->pressReleaseContextFor($question, $releaseDomain, $apiKey);
                if ($pressReleaseContext !== null) {
                    return $pressReleaseContext;
                }

                if ($regionalFallbackQuestion) {
                    $pressReleaseContext = $this->pressReleaseContextFor($question, '1600', $apiKey);
                    if ($pressReleaseContext !== null) {
                        return $this->provinceScopeFallbackContext($pressReleaseContext, $this->regionMentioned($question));
                    }
                }

                return $this->monthlyReleaseUnavailableContext($question);
            }

            $dynamicContext = $this->dynamicContextFor($question, $keywords, $domain, $apiKey);
            if ($dynamicContext !== null && ! $this->isUnavailableDataContext($dynamicContext)) {
                return $dynamicContext;
            }

            if ($regionalFallbackQuestion
                && ($dynamicContext === null || $this->isUnavailableDataContext($dynamicContext))) {
                $provinceQuestion = $this->questionWithoutRegionalScope($question);
                $provinceContext = $this->dynamicContextFor(
                    $provinceQuestion,
                    $this->keywords($provinceQuestion),
                    '1600',
                    $apiKey
                );
                $provinceContext = $this->provinceOnlyDynamicContext($provinceContext);
                if ($provinceContext !== null) {
                    return $this->provinceScopeFallbackContext($provinceContext, $this->regionMentioned($question));
                }
                if ($dynamicContext !== null) {
                    return $dynamicContext;
                }
            }

            if ($dynamicContext !== null) {
                return $dynamicContext;
            }

            if ($this->isDataQuestion($question) && $this->isCurrentPeriodRequest($question)) {
                return '[WebAPI BPS][DATA_BELUM_TERSEDIA] Data untuk periode terbaru/tahun berjalan belum ditemukan. Jangan gunakan periode lama atau variabel yang kurang relevan sebagai pengganti.';
            }

            $tables = $this->matchingTables($keywords, $domain, $apiKey, $question);
            $tableDomain = $domain;
            if ($tables === [] && $regionalFallbackQuestion) {
                $tableDomain = '1600';
                $tables = $this->matchingTables($keywords, $tableDomain, $apiKey, $question);
            } elseif ($tables === []) {
                $domain = '0000';
                $tableDomain = $domain;
                $tables = $this->matchingTables($keywords, $domain, $apiKey, $question);
            }

            $context = [];
            foreach ($tables as $table) {
                $tableId = $table['table_id'] ?? null;
                if (! is_scalar($tableId) || ! isset($table['title'])) {
                    continue;
                }

                $detail = ! empty($table['related_match'])
                    ? $this->relatedTableSummary($table)
                    : $this->tableDetail(
                        (string) $tableId,
                        $tableDomain,
                        $apiKey,
                        (string) ($table['type'] ?? 'Tabel Statis'),
                        (string) ($table['year'] ?? '')
                    );
                if ($detail !== null) {
                    $context[] = '[Sumber: BPS WebAPI, '.$table['title'].'] '.$detail;
                }
            }

            if ($context !== []) {
                $tableContext = implode("\n\n", $context);
                if ($this->tablesNeedConfirmation($tables, $question)) {
                    return "[WebAPI BPS][KANDIDAT_TOPIK_TERKAIT]\n"
                        ."Topik yang diminta belum memiliki kecocokan yang persis. Tabel berikut "
                        ."hanya kandidat terkait; jangan menyajikan nilainya sebagai jawaban final. "
                        ."Tanyakan dengan sopan apakah salah satu tabel berikut yang dimaksud:\n\n"
                        .$tableContext;
                }

                return $regionalFallbackQuestion
                    ? $this->provinceScopeFallbackContext($tableContext, $this->regionMentioned($question))
                    : $tableContext;
            }

            if ($regionalFallbackQuestion) {
                return '[WebAPI BPS][DATA_BELUM_TERSEDIA] Data untuk wilayah yang diminta maupun data tingkat Provinsi Sumatera Selatan belum ditemukan pada pencarian WebAPI BPS. Jangan menyimpulkan bahwa data tidak tersedia di seluruh BPS.';
            }

            return null;
        } catch (ConnectionException) {
            if ($catalog !== null) {
                return '[WebAPI BPS] API tidak dapat dihubungi untuk mengambil '.$catalog['label'].'. Beri tahu pengguna bahwa data belum dapat diverifikasi dan jangan mengarang daftar atau jumlah.';
            }

            if ($this->isDataQuestion($question)) {
                return '[WebAPI BPS][DATA_BELUM_TERSEDIA] WebAPI BPS tidak dapat dihubungi untuk memeriksa variabel dan periode terbaru. Jangan mengganti dengan seri lama.';
            }

            return $this->isMonthlyBpsTopic($question)
                ? $this->monthlyReleaseUnavailableContext($question)
                : null;
        } catch (RuntimeException $exception) {
            report($exception);

            if ($this->isDataQuestion($question)) {
                return '[WebAPI BPS][DATA_BELUM_TERSEDIA] Variabel atau periode terbaru gagal diverifikasi melalui WebAPI BPS. Jangan mengganti dengan seri lama.';
            }

            throw $exception;
        }
    }

    public function catalogAnswerFor(string $question): ?string
    {
        $catalog = $this->catalogIntent($question);
        if ($catalog === null) {
            return null;
        }

        $apiKey = $this->apiKey();
        if ($apiKey === null) {
            return 'Maaf, API key WebAPI BPS belum dikonfigurasi sehingga arsip belum dapat dimuat.';
        }

        try {
            $result = $this->fetchCatalogForQuestion($catalog, $question, $this->domainFor($question), $apiKey);
        } catch (ConnectionException) {
            return 'Maaf, WebAPI BPS sedang tidak dapat dihubungi untuk mengambil '.$catalog['label'].'. Silakan coba kembali beberapa saat lagi.';
        }

        if (isset($result['error'])) {
            return 'Maaf, '.$catalog['label'].' belum dapat dimuat dari WebAPI BPS. Silakan coba kembali beberapa saat lagi.';
        }

        if ($result['items'] === []) {
            if (in_array($catalog['model'], ['pressrelease', 'publication', 'news'], true)) {
                return 'WebAPI BPS tidak menemukan hasil untuk '.$catalog['label'].' yang diminta.';
            }

            return 'Katalog CSV BPS tidak menemukan entri untuk '.$catalog['label'].'.';
        }

        $label = ucfirst($catalog['label']);
        $shown = count($result['items']);
        $isEditorial = in_array($catalog['model'], ['pressrelease', 'publication', 'news'], true);
        $sourceLabel = $isEditorial ? 'WebAPI BPS' : 'katalog CSV';
        $answer = "Berikut {$label} BPS Sumatera Selatan dari {$sourceLabel} (menampilkan {$shown} dari {$result['total']} entri):";
        foreach ($result['items'] as $index => $item) {
            $title = $item['judul'] ?? 'Judul tidak tersedia pada respons API';
            $details = [];
            foreach ([
                'tanggal' => 'rilis',
                'subjek' => 'subjek',
                'jumlah_tabel' => 'jumlah tabel',
                'tautan' => 'tautan',
                'ringkasan' => 'abstrak/isi API',
            ] as $key => $detailLabel) {
                if (isset($item[$key])) {
                    $details[] = $detailLabel.': '.$item[$key];
                }
            }

            $answer .= "\n".($index + 1).'. '.$title.($details === [] ? '' : ' ('.implode('; ', $details).')');
            if ($isEditorial && ! isset($item['tautan'])) {
                $answer .= ' (tautan sumber/PDF tidak tersedia pada respons API)';
            }
            if ($isEditorial && ! isset($item['ringkasan'])) {
                $answer .= ' (abstrak/isi tidak tersedia pada respons API)';
            }
        }
        if ($shown < $result['total']) {
            $answer .= "\nDaftar di atas hanya menampilkan sebagian entri katalog.";
        }

        return $answer;
    }

    public function fallbackAnswerFor(string $question): string
    {
        $config = config('chatbot_fallback');
        $notice = $config['notice'];
        $closing = $config['closing'];

        $apiKey = $this->apiKey();
        $catalog = $this->catalogIntent($question);
        if ($catalog !== null
            && in_array($catalog['model'], ['pressrelease', 'publication', 'news'], true)) {
            return $this->catalogAnswerFor($question)
                ?? $notice."\n\nArsip editorial belum dapat dimuat dari WebAPI BPS.\n\n".$closing;
        }
        if ($apiKey !== null && $this->isStaticWageQuestion($question)) {
            $tables = $this->matchingTables(
                $this->keywords($question),
                '1600',
                $apiKey,
                $question
            );
            if ($this->tablesNeedConfirmation($tables, $question)) {
                return $this->formatRelatedTableAnswer($tables);
            }
            $tableContext = $this->staticTableContext(
                $this->keywords($question),
                '1600',
                $apiKey,
                $question
            );
            if ($tableContext !== null) {
                return $notice."\n\n".$tableContext."\n\n".$closing;
            }
        }
        if ($apiKey !== null) {
            $canonicalAnswer = $this->canonicalIndicatorFallback($question, $apiKey, $notice, $closing);
            if ($canonicalAnswer !== null) {
                return $canonicalAnswer;
            }

            $relatedTables = $this->matchingTables(
                $this->keywords($question),
                '1600',
                $apiKey,
                $question
            );
            if ($this->tablesNeedConfirmation($relatedTables, $question)) {
                return $this->formatRelatedTableAnswer($relatedTables);
            }
        }

        $topics = array_slice($this->matchFallbackTopics($question), 0, 3);
        if ($this->hasSeparatePopulationTotalRequest($question)
            && collect($topics)->contains(fn (array $topic) => ($topic['key'] ?? null) === 'kemiskinan')) {
            $topics[] = [
                'key' => 'jumlah_penduduk',
                'label' => 'Jumlah penduduk',
                'keywords' => ['penduduk', 'warga'],
                'variables' => [
                    ['var_id' => 262, 'label' => 'Jumlah Penduduk Menurut Kabupaten/Kota'],
                ],
                'province_variables' => [
                    ['var_id' => 262, 'label' => 'Jumlah Penduduk Menurut Kabupaten/Kota'],
                ],
                'province_only' => false,
                'population_total' => true,
                'suppress_brs' => true,
            ];
        }
        $regions = array_slice($this->regionsMentioned($question), 0, 3);
        if ($regions === []) {
            $regions = [$this->provinceFallbackRegion()];
        }
        if ($topics !== []) {
            if ($apiKey === null) {
                return $notice."\n\nData variabel terbaru belum dapat diambil karena API key WebAPI BPS belum dikonfigurasi.\n\n".$closing;
            }

            return $this->fallbackForTopicRegions($question, $topics, $regions, $apiKey, $notice, $closing);
        }

        $region = $this->regionMentioned($question);
        $populationQuestion = $this->isPopulationTotalQuestion($question);
        $regionalDataQuestion = $this->isRegionalDataQuestion($question);
        $catalogVariableQuestion = $this->catalogVariableMatches($question);
        if ($regionalDataQuestion || $populationQuestion || $catalogVariableQuestion) {
            $label = $region['label'] ?? 'Provinsi Sumatera Selatan';
            if ($apiKey === null) {
                return $notice."\n\nData BPS untuk {$label} belum dapat diambil karena API key WebAPI BPS belum dikonfigurasi.\n\n".$closing;
            }

            try {
                $context = $this->contextFor($question);
                $details = $this->dynamicFallbackDetails($question, $region, $populationQuestion, $context);
                if ($details === null && $catalogVariableQuestion) {
                    $details = $this->catalogVariableFallback(
                        $question,
                        $region ?? $this->provinceFallbackRegion(),
                        $apiKey
                    );
                }
            } catch (ConnectionException) {
                $details = null;
            }
            if ($details !== null) {
                return $notice."\n\n".$details."\n\nDitampilkan pada ".now('Asia/Jakarta')->format('d-m-Y H:i').' WIB.'."\n\n".$closing;
            }

            return $notice."\n\nData statistik yang diminta belum ditemukan atau belum tersedia dalam katalog CSV BPS untuk {$label}.\n\n".$closing;
        }

        if ($apiKey === null) {
            return $notice."\n\nData terbaru belum dapat diambil karena API key WebAPI BPS belum dikonfigurasi.\n\n".$closing;
        }

        $items = [];
        $intro = $config['general_intro'];
        try {
            $items = $this->latestPressReleases($apiKey, null, 5);
        } catch (ConnectionException) {
            $items = [];
        }

        if ($items === []) {
            return $notice."\n\nData terbaru dari WebAPI BPS juga belum dapat diambil saat ini.\n\n".$closing;
        }

        $regionLabel = $region['label'] ?? (preg_match('/\b(kabupaten|kab|kota)\b/iu', $question) ? 'kabupaten/kota' : null);

        return $this->formatFallbackPressReleases($items, $notice, $intro, $closing, $regionLabel);
    }

    private function catalogVariableFallback(string $question, array $region, string $apiKey): ?string
    {
        $terms = $this->requiredVariableTerms($question);
        if ($terms === []) {
            return null;
        }
        $maleTerms = ['laki', 'pria', 'lelaki', 'cowok'];
        $femaleTerms = ['perempuan', 'wanita', 'cewek'];
        $genderPair = array_intersect($terms, $maleTerms) !== []
            && array_intersect($terms, $femaleTerms) !== [];
        $terms = array_values(array_unique(array_map(
            static function (string $term) use ($maleTerms, $femaleTerms): string {
                if (in_array($term, $maleTerms, true)) {
                    return 'laki';
                }
                if (in_array($term, $femaleTerms, true)) {
                    return 'perempuan';
                }

                return $term;
            },
            $terms
        )));
        $requestsDetail = $genderPair || preg_match(
            '/\b(menurut|berdasarkan|jenis\s+kelamin|laki-laki|perempuan|kabupaten|kota)\b/iu',
            $question
        ) === 1;
        $ranked = [];
        foreach ($this->localCatalogIndex()['variables'] as $variable) {
            $searchText = implode(' ', [
                (string) ($variable['title'] ?? ''),
                implode(' ', $variable['aliases'] ?? []),
            ]);
            $score = 0.0;
            $hasExactMatch = false;
            foreach ($terms as $term) {
                $termScore = $this->titleTermScore($searchText, $term);
                if ($termScore <= 0) {
                    $score = 0;
                    break;
                }
                $isExact = $this->titleHasExactTerm($searchText, $term);
                $hasExactMatch = $hasExactMatch || $isExact;
                $score += min(Str::length($term), 10) * ($isExact ? 2 : $termScore);
            }
            if ($score <= 0 || ! $hasExactMatch) {
                continue;
            }
            if (! $requestsDetail && preg_match(
                '/\b(menurut|berdasarkan|jenis\s+kelamin|laki-laki|perempuan|'
                .'kabupaten\s*\/\s*kota|per\s+kabupaten|per\s+kota)\b/iu',
                (string) ($variable['title'] ?? '')
            ) === 1) {
                $score -= 20;
            }
            $variable['_catalog_score'] = $score;
            $ranked[] = $variable;
        }
        if ($ranked === []) {
            return null;
        }

        usort($ranked, fn (array $left, array $right) => ($right['_catalog_score'] <=> $left['_catalog_score'])
            ?: (($right['latest_year'] ?? 0) <=> ($left['latest_year'] ?? 0)));
        $best = $ranked[0];
        $candidates = array_values(array_filter(
            $ranked,
            fn (array $candidate) => $candidate['_catalog_score'] === $best['_catalog_score']
                && ($candidate['latest_year'] ?? null) === ($best['latest_year'] ?? null)
        ));

        if (count($candidates) > 1) {
            $genderLabels = array_map(function (array $candidate): ?string {
                $title = Str::lower((string) ($candidate['title'] ?? ''));
                if (preg_match('/\b(laki-laki|laki laki)\b/u', $title) === 1) {
                    return 'Laki-laki';
                }
                if (preg_match('/\b(perempuan|wanita)\b/u', $title) === 1) {
                    return 'Perempuan';
                }

                return null;
            }, $candidates);
            $uniqueGenderLabels = array_values(array_unique($genderLabels));
            if (count($candidates) !== 2
                || count($uniqueGenderLabels) !== 2
                || ! in_array('Laki-laki', $uniqueGenderLabels, true)
                || ! in_array('Perempuan', $uniqueGenderLabels, true)) {
                $options = array_map(
                    fn (array $candidate) => '- '.$candidate['title']
                        .' (terbaru '.($candidate['latest_year'] ?? 'belum diketahui').')',
                    array_slice($candidates, 0, 5)
                );

                return 'Saya menemukan beberapa indikator yang sesuai di katalog BPS. Mohon pilih seri yang dimaksud:'."\n"
                    .implode("\n", $options);
            }
        } else {
            $candidates = [$best];
            $genderLabels = [null];
        }

        $details = [];
        $genderAnswers = [];
        foreach ($candidates as $index => $candidate) {
            $candidate['label'] = (string) $candidate['title'];
            $lookup = $this->fallbackVariableLookup($candidate, $question, $region, $apiKey);
            if ($lookup['context'] === null) {
                continue;
            }
            $answer = $this->dynamicFallbackDetails(
                $question,
                $region,
                false,
                $lookup['context']
            );
            if ($answer === null) {
                continue;
            }
            if ($lookup['year_notice'] !== null) {
                $answer = $lookup['year_notice']."\n".$answer;
            }
            if (($genderLabels[$index] ?? null) !== null) {
                $genderAnswers[$genderLabels[$index]] = [
                    'title' => $candidate['title'],
                    'answer' => $answer,
                ];
                $answer = $genderLabels[$index].":\n".$answer;
            }
            $details[] = $answer;
        }
        if ($details !== []) {
            $genderSummary = $this->formatMaritalStatusGenderSummary(
                $question,
                $region,
                $genderAnswers
            );
            if ($genderSummary !== null) {
                return $genderSummary;
            }

            return implode("\n\n", $details);
        }

        return 'Indikator ditemukan di katalog BPS, tetapi nilainya belum berhasil diverifikasi dari WebAPI BPS saat ini.';
    }

    private function formatMaritalStatusGenderSummary(string $question, array $region, array $genderAnswers): ?string
    {
        if (count($genderAnswers) !== 2
            || ! isset($genderAnswers['Laki-laki'], $genderAnswers['Perempuan'])
            || preg_match('/\bstatus\s+perkawinan\b/iu', $question) !== 1) {
            return null;
        }

        $sentences = [];
        $latestYears = [];
        foreach (['Laki-laki', 'Perempuan'] as $gender) {
            $series = $genderAnswers[$gender];
            if (preg_match('/\bstatus\s+perkawinan\b/iu', (string) ($series['title'] ?? '')) !== 1
                || ! preg_match(
                    '/Data WebAPI BPS untuk .*? tahun (\d+):\s*\R(.*)$/su',
                    (string) ($series['answer'] ?? ''),
                    $matches
                )) {
                return null;
            }

            $year = $matches[1];
            $latestYears[] = $year;
            $items = [];
            foreach (preg_split('/\R/u', trim($matches[2])) ?: [] as $line) {
                if (! preg_match('/^\s*([^:]+):\s*(.*?)\s*$/u', $line, $valueMatch)) {
                    continue;
                }
                $category = trim($valueMatch[1]);
                if (preg_match('/^jumlah$/iu', $category) === 1) {
                    continue;
                }
                $value = preg_replace('/\s*Persen$/iu', '%', trim($valueMatch[2])) ?? trim($valueMatch[2]);
                $items[] = Str::lower($category).' '.$value;
            }
            if ($items === []) {
                return null;
            }
            $lastItem = array_pop($items);
            $breakdown = $items === [] ? $lastItem : implode(', ', $items).' dan '.$lastItem;
            $genderLabel = $gender === 'Laki-laki' ? 'laki-laki' : 'perempuan';
            $sentences[] = "Pada penduduk {$genderLabel} berumur 10 tahun ke atas tahun {$year}, persentase status perkawinannya meliputi {$breakdown}.";
        }

        $latestYear = min($latestYears);
        $requestedYear = preg_match('/\b(20\d{2})\b/', $question, $yearMatch) === 1
            ? $yearMatch[1]
            : null;
        $yearNotice = $requestedYear !== null && in_array($requestedYear, $latestYears, true)
            ? ''
            : ($requestedYear !== null
                ? "Data {$requestedYear} belum tersedia. Data terbaru yang berhasil diverifikasi adalah tahun {$latestYear}. "
                : '');

        return $yearNotice.'Berikut data status perkawinan di '.$region['label'].': '.implode(' ', $sentences);
    }

    private function canonicalIndicatorFallback(
        string $question,
        string $apiKey,
        string $notice,
        string $closing
    ): ?string {
        if (preg_match('/\b(brs|berita\s+resmi\s+statistik|rilis|siaran\s+pers)\b/iu', $question) === 1) {
            return null;
        }

        $isPdrb = preg_match('/\b(pdrb|produk\s+domestik\s+regional\s+bruto)\b/iu', $question) === 1;
        $isUnemployment = preg_match('/\b(penganggur\w*|tpt)\b/iu', $question) === 1;
        if ($isPdrb === $isUnemployment || count($this->matchFallbackTopics($question)) > 1) {
            return null;
        }

        $requestedRegion = $this->regionMentioned($question);
        if ($isPdrb) {
            if (($requestedRegion['kind'] ?? null) !== 'region') {
                return null;
            }
            $indicatorName = preg_match('/\b(pertumbuhan|laju)\b/iu', $question) === 1
                ? 'pdrb_pertumbuhan_kab_kota'
                : (preg_match('/\b(adhk|konstan)\b/iu', $question) === 1
                    ? 'pdrb_adhk_kab_kota'
                    : 'pdrb_adhb_kab_kota');
            $dataRegion = $requestedRegion;
        } else {
            $indicatorName = 'tingkat_pengangguran';
            $dataRegion = $this->provinceFallbackRegion();
        }

        $variable = null;
        foreach (config('bps_indicators.groups', []) as $group) {
            foreach ($group['variables'] ?? [] as $candidate) {
                if (($candidate['name'] ?? null) === $indicatorName) {
                    $variable = $candidate;
                    break 2;
                }
            }
        }
        if ($variable === null) {
            throw new RuntimeException("Variabel kanonik {$indicatorName} tidak ditemukan.");
        }
        $variable['label'] = $variable['title'];

        $lookup = $this->fallbackVariableLookup($variable, $question, $dataRegion, $apiKey);
        if ($lookup['context'] === null) {
            return null;
        }
        $details = $this->dynamicFallbackDetails($question, $dataRegion, false, $lookup['context']);
        if ($details === null) {
            return null;
        }
        if ($lookup['year_notice'] !== null) {
            $details = $lookup['year_notice']."\n".$details;
        }
        if ($isUnemployment && ($requestedRegion['kind'] ?? null) === 'region') {
            $details = 'Tingkat pengangguran pada seri ini hanya tersedia untuk Provinsi Sumatera Selatan, bukan data khusus '
                .$requestedRegion['label'].".\n".$details;
        }

        return $notice."\n\n".$details."\n\nDitampilkan pada ".now('Asia/Jakarta')->format('d-m-Y H:i').' WIB.'."\n\n".$closing;
    }

    private function fallbackForTopicRegions(
        string $question,
        array $topics,
        array $regions,
        string $apiKey,
        string $notice,
        string $closing
    ): string {
        $sections = [];
        $releaseEntries = [];
        $brsOnlyEntries = [];
        $regionUnavailable = [];
        foreach ($topics as $topic) {
            foreach ($regions as $region) {
                if ($topic['brs_only'] ?? false) {
                    $brs = $this->fallbackPressReleaseForPair($apiKey, $topic, $region);
                    if ($brs !== null) {
                        $brsOnlyEntries[] = $brs;
                    } else {
                        $regionUnavailable[] = $region['label'].' untuk '.$topic['label'];
                    }

                    continue;
                }

                $sectionTitle = Str::ucfirst($topic['section_label'] ?? $topic['label']).' '.$region['label'];
                $sections[] = $sectionTitle.':';
                $regional = ($region['kind'] ?? null) === 'region';
                $regionalVariables = $regional ? ($topic['variables'] ?? []) : [];
                $provinceOnly = $regional && $this->isProvinceOnlyFallbackTopic($topic, $question);
                $useProvinceVariables = ! $regional || $provinceOnly || $regionalVariables === [];
                $variables = $useProvinceVariables
                    ? ($topic['province_variables'] ?? [])
                    : $regionalVariables;
                $selectedVariables = $this->selectFallbackVariables(
                    $variables,
                    $question,
                    ($topic['key'] ?? null) === 'kemiskinan'
                );
                $details = null;

                if ($selectedVariables !== []) {
                    $detailLines = [];
                    $provinceVariables = $this->selectFallbackVariables(
                        $topic['province_variables'] ?? [],
                        $question,
                        ($topic['key'] ?? null) === 'kemiskinan'
                    );
                    foreach ($selectedVariables as $selected) {
                        $dataRegion = $useProvinceVariables ? $this->provinceFallbackRegion() : $region;
                        $lookup = $this->fallbackVariableLookup($selected, $question, $dataRegion, $apiKey);
                        $variableDetails = null;
                        if ($lookup['context'] !== null) {
                            $variableDetails = $this->dynamicFallbackDetails(
                                $question,
                                $dataRegion,
                                (bool) ($topic['population_total'] ?? false),
                                $lookup['context']
                            );
                            if ($variableDetails !== null && $lookup['year_notice'] !== null) {
                                $variableDetails = $lookup['year_notice']."\n".$variableDetails;
                            }
                            if ($variableDetails !== null && $regional && $provinceOnly) {
                                $variableDetails = 'Indikator ini hanya tersedia pada tingkat Provinsi Sumatera Selatan; bukan data khusus '.$region['label'].".\n".$variableDetails;
                            } elseif ($variableDetails !== null && $regional && $dataRegion !== $region) {
                                $variableDetails = 'Data tingkat kabupaten/kota untuk indikator ini tidak tersedia; berikut data tingkat Provinsi Sumatera Selatan.'."\n".$variableDetails;
                            }
                        } elseif ($lookup['reason'] !== null
                            && $regional
                            && ! $provinceOnly
                            && ! $useProvinceVariables) {
                            $provinceVariable = $this->selectFallbackVariableForMissingRegion(
                                $provinceVariables,
                                $selected,
                                $question
                            );
                            if ($provinceVariable !== null) {
                                $provinceLookup = $this->fallbackVariableLookup(
                                    $provinceVariable,
                                    $question,
                                    $this->provinceFallbackRegion(),
                                    $apiKey
                                );
                                if ($provinceLookup['context'] !== null) {
                                    $variableDetails = $this->dynamicFallbackDetails(
                                        $question,
                                        $this->provinceFallbackRegion(),
                                        (bool) ($topic['population_total'] ?? false),
                                        $provinceLookup['context']
                                    );
                                    if ($variableDetails !== null) {
                                        $variableDetails = 'Data kabupaten/kota untuk indikator ini belum tersedia; berikut data tingkat Provinsi Sumatera Selatan.'."\n".$variableDetails;
                                        if ($provinceLookup['year_notice'] !== null) {
                                            $variableDetails = $provinceLookup['year_notice']."\n".$variableDetails;
                                        }
                                    }
                                }
                            }
                        }
                        if ($variableDetails !== null) {
                            $detailLines[] = $variableDetails;
                        } elseif ($lookup['reason'] !== null) {
                            $detailLines[] = 'Data '.$selected['label'].' belum ditemukan: '.$lookup['reason'];
                        }
                    }
                    $details = $detailLines === [] ? null : implode("\n\n", array_unique($detailLines));
                } elseif ($variables !== []) {
                    $legacyQuery = null;
                    if ($regional && ! $provinceOnly) {
                        $legacyQuery = ($topic['matched_keyword'] ?? $topic['keywords'][0]).' '.$region['aliases'][0];
                        if (preg_match('/\b(20\d{2})\b/', $question, $yearMatch)) {
                            $legacyQuery .= ' tahun '.$yearMatch[1];
                        }
                    }
                    try {
                        $legacyContext = $legacyQuery === null
                            ? null
                            : $this->dynamicContextFor($legacyQuery, $this->keywords($legacyQuery), '1600', $apiKey);
                    } catch (ConnectionException) {
                        $legacyContext = null;
                    }
                    if (is_string($legacyContext)
                        && ! $this->isUnavailableDataContext($legacyContext)
                        && ! str_starts_with($legacyContext, '[WebAPI BPS][INDIKATOR_AMBIGU]')) {
                        $details = $this->dynamicFallbackDetails($legacyQuery, $region, false, $legacyContext);
                    }
                    if ($details === null) {
                        $sections[] = 'Belum dapat dipastikan indikator yang dimaksud. Kandidat: '.implode(', ', array_column($variables, 'label')).'.';
                    }
                } else {
                    $sections[] = $provinceOnly
                        ? 'Indikator ini hanya tersedia tingkat Provinsi Sumatera Selatan; nilai variabel terverifikasi belum ditemukan.'
                        : ($regional
                            ? 'Indikator kabupaten/kota yang terverifikasi belum ditemukan.'
                            : 'Indikator provinsi yang terverifikasi belum ditemukan.');
                }

                if ($details !== null) {
                    $sections[] = $details;
                } else {
                    $regionUnavailable[] = $region['label'].' untuk '.$topic['label'];
                }

                if (! ($topic['suppress_brs'] ?? false)) {
                    $brs = $this->fallbackPressReleaseForPair($apiKey, $topic, $region);
                    if ($brs !== null) {
                        $releaseEntries[] = $brs;
                    }
                }
            }
        }

        $sections = array_merge($sections, $this->formatGroupedPressReleases($brsOnlyEntries, 5));
        $releaseLines = $this->formatGroupedPressReleases($releaseEntries, 5);
        $lines = [$notice, '', implode("\n\n", $sections)];
        if ($releaseLines !== []) {
            $lines[] = '';
            $lines[] = 'Berita Resmi Statistik pelengkap:';
            $lines = array_merge($lines, $releaseLines);
        }
        if ($sections === [] || ($releaseLines === [] && count($regionUnavailable) === count($topics) * count($regions))) {
            $lines[] = '';
            $lines[] = 'Data variabel maupun BRS yang sesuai belum ditemukan. Periksa ketersediaan indikator dan periode yang diminta pada https://sumsel.bps.go.id.';
        }
        $lines[] = '';
        $lines[] = 'Ditampilkan pada '.now('Asia/Jakarta')->format('d-m-Y H:i').' WIB.';
        $lines[] = '';
        $lines[] = $closing;

        return implode("\n", $lines);
    }

    private function selectFallbackVariables(
        array $variables,
        string $question,
        bool $returnAllWithoutSpecificMatch = false
    ): array
    {
        if ($variables === []) {
            return [];
        }
        if (count($variables) === 1) {
            return [$variables[0]];
        }

        $scores = [];
        foreach ($variables as $variable) {
            $scores[] = [
                'variable' => $variable,
                'score' => count(array_filter($variable['keywords'] ?? [], fn (string $keyword) => preg_match('/\b'.preg_quote($keyword, '/').'\b/iu', $question) === 1)),
            ];
        }
        usort($scores, fn (array $left, array $right) => $right['score'] <=> $left['score']);
        $bestScore = $scores[0]['score'] ?? 0;
        if ($bestScore === 0) {
            return $returnAllWithoutSpecificMatch ? $variables : [];
        }

        return array_values(array_map(
            fn (array $score) => $score['variable'],
            array_filter($scores, fn (array $score) => $score['score'] === $bestScore)
        ));
    }

    private function selectFallbackVariable(array $variables, string $question): ?array
    {
        $matches = $this->selectFallbackVariables($variables, $question);

        return count($matches) === 1 ? $matches[0] : null;
    }

    private function selectFallbackVariableForMissingRegion(
        array $provinceVariables,
        array $regionalVariable,
        string $question
    ): ?array {
        foreach ($provinceVariables as $provinceVariable) {
            if ((int) ($provinceVariable['var_id'] ?? 0) === (int) ($regionalVariable['var_id'] ?? -1)) {
                return $provinceVariable;
            }
        }

        $matches = $this->selectFallbackVariables($provinceVariables, $question);

        return count($matches) === 1 ? $matches[0] : null;
    }

    private function hasSeparatePopulationTotalRequest(string $question): bool
    {
        $questionWithoutPoorPopulation = preg_replace(
            '/\b(?:jumlah|total)?\s*(?:penduduk|warga)\s+miskin\w*\b/iu',
            ' ',
            $question
        ) ?? $question;

        return preg_match(
            '/\b(?:jumlah|total)\s+(?:total\s+)?(?:penduduk|warga)\b/iu',
            $questionWithoutPoorPopulation
        ) === 1;
    }

    private function isProvinceOnlyFallbackTopic(array $topic, string $question): bool
    {
        if ($topic['province_only'] ?? false) {
            return true;
        }

        foreach ($topic['province_only_keywords'] ?? [] as $keyword) {
            if (preg_match('/\b'.preg_quote($keyword, '/').'\b/iu', $question)) {
                return true;
            }
        }

        return false;
    }

    private function fallbackVariableLookup(array $variable, string $question, array $region, string $apiKey): array
    {
        $variableId = (int) ($variable['var_id'] ?? 0);
        try {
            $periods = $this->periodsForVariable($variableId, '1600', $apiKey);
        } catch (ConnectionException) {
            return ['context' => null, 'reason' => 'WebAPI BPS tidak dapat dihubungi untuk mengambil daftar tahun.', 'year_notice' => null];
        }
        $currentYear = now('Asia/Jakarta')->year;
        $requestedYear = preg_match('/\b(20\d{2})\b/', $question, $match)
            ? (int) $match[1]
            : (preg_match('/\b(tahun ini|tahun sekarang|sekarang|saat ini|terbaru|terkini|terakhir)\b/iu', $question)
                ? $currentYear
                : null);
        $eligiblePeriods = array_values(array_filter(
            $periods,
            fn (array $period) => (int) ($period['th'] ?? 0) <= min($requestedYear ?? $currentYear, $currentYear)
        ));
        $period = null;
        if ($requestedYear !== null) {
            foreach ($eligiblePeriods as $candidate) {
                if ((int) ($candidate['th'] ?? 0) === $requestedYear) {
                    $period = $candidate;
                    break;
                }
            }
        }
        $period ??= $eligiblePeriods[0] ?? null;
        if ($period === null) {
            return ['context' => null, 'reason' => 'daftar tahun untuk '.$variable['label'].' belum tersedia di WebAPI BPS.', 'year_notice' => null];
        }

        $year = (string) ($period['th'] ?? '');
        $yearNotice = $requestedYear !== null && (int) $year !== $requestedYear
            ? "Data {$requestedYear} belum dirilis. Data terbaru ".(($region['kind'] ?? null) === 'region' ? $region['label'] : 'Provinsi Sumatera Selatan')." ({$year}):"
            : null;
        try {
            $response = $this->dynamicData(
                $variableId,
                (int) ($period['th_id'] ?? 0),
                null,
                '1600',
                $apiKey,
                (string) ($variable['title'] ?? $variable['label']),
                $year
            );
        } catch (ConnectionException) {
            return ['context' => null, 'reason' => 'WebAPI BPS tidak dapat dihubungi untuk mengambil nilai indikator.', 'year_notice' => $yearNotice];
        }
        if ($response === null || ! is_array($response->json('datacontent'))) {
            return ['context' => null, 'reason' => 'nilai '.$variable['label'].' tahun '.$year.' tidak dikembalikan WebAPI BPS.', 'year_notice' => $yearNotice];
        }

        $regionRow = filled($variable['vervar_label'] ?? null)
            ? $this->vervarRowByLabel($response->json('vervar'), (string) $variable['vervar_label'])
            : (($region['kind'] ?? null) === 'province'
                ? $this->provinceVervarRow($response->json('vervar'))
                : $this->regionVervarRow($region, $response->json('vervar')));
        $subregionalRows = [];
        if ($regionRow === null
            && ($region['kind'] ?? null) === 'province'
            && ! filled($variable['vervar_label'] ?? null)) {
            foreach (config('sumsel_regions', []) as $subregion) {
                $subregionalRow = $this->regionVervarRow($subregion, $response->json('vervar'));
                if ($subregionalRow !== null) {
                    $subregionalRows[] = $subregionalRow;
                }
            }
        }
        $subregionalFallback = $subregionalRows !== [];
        if ($regionRow === null
            && ! $subregionalFallback
            && ($region['kind'] ?? null) === 'province'
            && ! filled($variable['vervar_label'] ?? null)) {
            $aggregateRow = $this->provinceAggregateVervarRow($response->json('vervar'));
            if ($aggregateRow !== null) {
                $regionRow = $aggregateRow;
                $variable['vervar_label'] = (string) $aggregateRow['label'];
                $variable['wilayah_cakupan'] = 'Provinsi Sumatera Selatan';
            }
        }
        if ($regionRow === null && ! $subregionalFallback) {
            return ['context' => null, 'reason' => 'baris '.$region['label'].' tidak tersedia pada indikator '.$variable['label'].'.', 'year_notice' => $yearNotice];
        }
        $values = $subregionalFallback
            ? $response->json('datacontent')
            : $this->valuesForRegion($response->json('datacontent'), $regionRow);
        if ($values === []) {
            return ['context' => null, 'reason' => 'nilai untuk '.$region['label'].' pada indikator '.$variable['label'].' tidak ditemukan.', 'year_notice' => $yearNotice];
        }

        $metadata = [
            'var_id' => $variableId,
            'title' => $variable['label'],
            'level' => $subregionalFallback
                ? 'kab_kota'
                : ((($region['kind'] ?? null) === 'province') ? 'province' : 'kab_kota'),
            'sifat_data' => $variable['sifat_data'] ?? null,
            'catatan_sumber' => $variable['catatan_sumber'] ?? null,
            'vervar_label' => $variable['vervar_label'] ?? null,
            'wilayah_cakupan' => ($variable['province_only'] ?? false)
                ? 'Provinsi Sumatera Selatan'
                : null,
        ];

        return [
            'context' => $this->formatDynamicContext(
                $metadata,
                $response,
                $year,
                null,
                $subregionalFallback ? null : $regionRow,
                $values,
                $subregionalFallback
            ),
            'reason' => null,
            'year_notice' => $subregionalFallback && $yearNotice !== null
                ? "Data {$requestedYear} belum dirilis. Data terbaru yang tersedia pada seri kabupaten/kota ({$year}):"
                : $yearNotice,
        ];
    }

    private function fallbackPressReleaseForPair(string $apiKey, array $topic, array $region): ?array
    {
        $pattern = $this->titlePatternForKeywords($topic['title_keywords']);
        $local = ($region['kind'] ?? null) === 'region';
        $provinceFallback = false;
        try {
            $items = $local
                ? $this->latestPressReleases($apiKey, $pattern, 1, 6, (string) $region['domain'])
                : $this->latestPressReleases($apiKey, $pattern, 1);
            $intro = $local
                ? str_replace(['{region}', '{topic}'], [$region['label'], $topic['label']], config('chatbot_fallback.regional_brs_intro'))
                : str_replace('{topic}', $topic['label'], config('chatbot_fallback.topic_intro'));

            if ($items === [] && $local) {
                $items = $this->latestPressReleases($apiKey, $this->regionalTopicTitlePattern($region, $topic), 1);
                $intro = str_replace(
                    ['{region}', '{topic}'],
                    [$region['label'], $topic['label']],
                    config('chatbot_fallback.regional_province_brs_intro')
                );
            }
            if ($items === [] && $local) {
                $items = $this->latestPressReleases($apiKey, $pattern, 1);
                $intro = str_replace('{topic}', $topic['label'], config('chatbot_fallback.topic_intro'));
                $provinceFallback = true;
            }
        } catch (ConnectionException) {
            return null;
        }

        if ($items === []) {
            return null;
        }

        return [
            'topic' => $topic['label'],
            'intro' => $intro,
            'title' => $items[0]['judul'],
            'date' => $items[0]['tanggal'] ?? null,
            'region' => $region['label'],
            'province_fallback' => $provinceFallback,
        ];
    }

    private function formatGroupedPressReleases(array $entries, int $limit): array
    {
        $groups = [];
        foreach ($entries as $entry) {
            $key = json_encode([
                $entry['topic'],
                $entry['title'],
                $entry['date'],
                $entry['province_fallback'],
                $entry['province_fallback'] ? null : $entry['intro'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (! isset($groups[$key])) {
                $groups[$key] = $entry;
                $groups[$key]['regions'] = [];
            }
            $groups[$key]['regions'][] = $entry['region'];
        }

        $lines = [];
        foreach (array_slice($groups, 0, $limit) as $group) {
            $intro = $group['intro'];
            if ($group['province_fallback']) {
                $regions = array_values(array_unique($group['regions']));
                $regionList = count($regions) > 1
                    ? implode(', ', array_slice($regions, 0, -1)).' dan '.end($regions)
                    : ($regions[0] ?? 'wilayah yang diminta');
                $intro .= ' Catatan: data berikut berlaku untuk tingkat Provinsi Sumatera Selatan, bukan untuk '
                    .$regionList.'.';
            }

            $lines[] = $intro."\n".$group['title']
                .($group['date'] !== null ? ' (rilis: '.$group['date'].')' : '');
        }

        return $lines;
    }

    private function catalogIntent(string $question): ?array
    {
        if (preg_match('/\b(berita\s+resmi\s+statistik|brs|rilis(?:an)?|siaran\s+pers)\b/i', $question)) {
            return [
                'model' => 'pressrelease',
                'label' => 'Berita Resmi Statistik terbaru',
                'limit' => 3,
                'pages' => 1,
                'dated' => true,
                'latest' => preg_match('/\b(terbaru|terkini|terakhir)\b/i', $question) === 1,
            ];
        }
        if (preg_match('/\b(publikasi|terbitan|buku\s+statistik|laporan|dalam\s+angka)\b/i', $question)) {
            return ['model' => 'publication', 'label' => 'publikasi terbaru', 'limit' => 3, 'pages' => 1, 'dated' => true];
        }
        $explicitNews = preg_match('/\b(berita|kegiatan)\b/i', $question) === 1;
        $explicitValue = preg_match('/\b(berapa|jumlah|nilai|angka|data|persen\w*|persentase|tingkat|laju)\b/i', $question) === 1;
        if (preg_match('/\b(berita|kegiatan|sensus|survei|survey)\b/i', $question)
            && ! ($explicitValue && ! $explicitNews && $this->isDataQuestion($question))) {
            return ['model' => 'news', 'label' => 'berita statistik terbaru', 'limit' => 3, 'pages' => 1, 'dated' => true];
        }
        if (preg_match('/\b(tabel\s+statis|tabel\s+statistik\s+statis|daftar\s+tabel)\b/i', $question)) {
            return ['model' => 'statictable', 'label' => 'daftar tabel statis', 'limit' => 100, 'pages' => 10, 'dated' => false];
        }
        if (preg_match('/\b(subjek|subject|topik\s+data)\b/i', $question)) {
            return ['model' => 'subject', 'label' => 'daftar subjek data', 'limit' => 100, 'pages' => 10, 'dated' => false];
        }

        return null;
    }

    private function catalogContext(array $catalog, string $domain, string $apiKey): string
    {
        $result = $this->fetchCatalogForQuestion($catalog, '', $domain, $apiKey);
        $isEditorial = in_array($catalog['model'], ['pressrelease', 'publication', 'news'], true);
        $source = $isEditorial ? 'WebAPI BPS' : 'katalog CSV BPS';
        if (isset($result['error'])) {
            return '['.$source.'] Data '.$catalog['label'].' belum dapat dibaca. Beri tahu pengguna bahwa data belum dapat diverifikasi dan jangan mengarang daftar atau jumlah.';
        }

        $prefix = '[Sumber: '.$source.'; '.$catalog['label'].'; jumlah hasil: '.$result['total'];
        if (count($result['items']) < $result['total']) {
            $prefix .= '; ditampilkan: '.count($result['items']);
        }
        $prefix .= ']';
        if ($result['items'] === []) {
            return $prefix.' Tidak ada entri yang cocok. Jangan menyimpulkan jumlah atau membuat daftar sendiri.';
        }

        $lines = [$prefix];
        foreach ($result['items'] as $index => $item) {
            $lines[] = ($index + 1).'. '.json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return implode("\n", $lines);
    }

    private function fetchCatalogForQuestion(array $catalog, string $question, string $domain, string $apiKey): array
    {
        $filters = $this->catalogFilters($catalog, $question);
        return $this->fetchCatalog($catalog, $domain, $apiKey, $filters);
    }

    private function catalogFilters(array $catalog, string $question): array
    {
        $filters = [];
        if (preg_match('/\byear(?:\/|\s+)(20\d{2})\b/i', $question, $match)
            || preg_match('/\btahun\s+(20\d{2})\b/i', $question, $match)
            || preg_match('/\b(20\d{2})\b/i', $question, $match)) {
            $filters['year'] = (int) $match[1];
        }

        if (preg_match('/\bmonth(?:\/|\s+)(1[0-2]|[1-9])\b/i', $question, $match)) {
            $filters['month'] = (int) $match[1];
        } elseif (preg_match('/\bbulan\s+(1[0-2]|[1-9])\b/i', $question, $match)) {
            $filters['month'] = (int) $match[1];
        } else {
            $months = [
                'januari' => 1, 'februari' => 2, 'maret' => 3, 'april' => 4,
                'mei' => 5, 'juni' => 6, 'juli' => 7, 'agustus' => 8,
                'september' => 9, 'oktober' => 10, 'november' => 11, 'desember' => 12,
            ];
            foreach ($months as $name => $number) {
                if (preg_match('/\b'.preg_quote($name, '/').'\b/i', $question)) {
                    $filters['month'] = $number;
                    break;
                }
            }
        }

        if (preg_match('/\bpage(?:\/|\s+)([1-9]\d*)\b/i', $question, $match)
            || preg_match('/\b(?:halaman|hal)\s+([1-9]\d*)\b/i', $question, $match)) {
            $filters['page'] = (int) $match[1];
        }

        if (preg_match('/\bkeyword\/([\pL\pN_-]+(?:%20[\pL\pN_-]+)*)/iu', $question, $match)) {
            $filters['keyword'] = str_replace('%20', ' ', $match[1]);
        } else {
            $keywordQuestion = preg_replace('/\b(berita|resmi|statistik|brs|rilis|publikasi|terbitan|buku|laporan|dalam|angka|tabel|statis|daftar|subjek|subject|topik|data|terbaru|terkini|terakhir|tahun|bulan|halaman|hal|year|month|page|keyword|bps|sumsel|sumatera|selatan|apa|saja|dari|untuk|pada|yang|dan|tentang|mengenai|ini|januari|februari|maret|april|mei|juni|juli|agustus|september|oktober|november|desember)\b/iu', ' ', $question) ?? '';
            $keywordQuestion = preg_replace('/\b(20\d{2}|1[0-2]|[1-9])\b/u', ' ', $keywordQuestion) ?? '';
            $keywordQuestion = trim(preg_replace('/\s+/u', ' ', $keywordQuestion) ?? '');
            if (mb_strlen($keywordQuestion) >= 3) {
                $filters['keyword'] = $keywordQuestion;
            }
        }
        return $filters;
    }

    private function fetchCatalog(array $catalog, string $domain, string $apiKey, array $filters = []): array
    {
        if (in_array($catalog['model'], ['pressrelease', 'publication', 'news'], true)) {
            return $this->fetchEditorialCatalog($catalog, $domain, $apiKey, $filters);
        }

        unset($apiKey);
        $rows = match ($catalog['model']) {
            'statictable' => array_values(array_filter(
                $this->csvCatalogRows(),
                fn (array $row) => ($row['jenis_tabel'] ?? '') === 'Tabel Statis'
                    && str_contains((string) ($row['link_json'] ?? ''), '/domain/'.$domain.'/')
            )),
            'subject' => $this->catalogSubjects($domain),
            default => [],
        };

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $rows = array_values(array_filter(
                $rows,
                fn (array $row) => $this->titleTermScore(
                    implode(' ', [
                        $row['judul'] ?? '',
                        $row['subjek'] ?? '',
                        $row['kategori_subjek'] ?? '',
                    ]),
                    $keyword
                ) > 0
            ));
        }

        $total = count($rows);
        $limit = max(1, (int) $catalog['limit']);
        $page = max(1, (int) ($filters['page'] ?? 1));
        $items = array_map(
            fn (array $row) => $this->formatCatalogItem([
                'title' => $row['judul'] ?? $row['subjek'] ?? '',
                'subject' => $row['subjek'] ?? '',
                'subcat' => $row['kategori_subjek'] ?? '',
                'table_id' => $row['id_tabel'] ?? '',
            ], (bool) $catalog['dated']),
            array_slice($rows, ($page - 1) * $limit, $limit)
        );

        return ['total' => $total, 'items' => array_values(array_filter($items))];
    }

    private function fetchEditorialCatalog(array $catalog, string $domain, string $apiKey, array $filters): array
    {
        $model = (string) $catalog['model'];
        $page = max(1, min(1000, (int) ($filters['page'] ?? 1)));
        $limit = max(1, min(3, (int) ($catalog['limit'] ?? 3)));
        $segments = ['list', 'model', $model, 'lang', 'ind', 'domain', $domain];
        foreach (['year', 'month'] as $filter) {
            if (isset($filters[$filter])) {
                $segments[] = $filter;
                $segments[] = (string) (int) $filters[$filter];
            }
        }
        $segments[] = 'page';
        $segments[] = (string) $page;
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $segments[] = 'keyword';
            $segments[] = str_replace('%20', '+', rawurlencode($keyword));
        }
        $segments[] = 'key';
        $segments[] = rawurlencode($apiKey);
        $listUrl = 'https://webapi.bps.go.id/v1/api/'.implode('/', $segments).'/';
        try {
            $payload = $this->fetchEditorialPayload($listUrl);
        } catch (RuntimeException $exception) {
            return ['error' => $exception->getMessage(), 'total' => 0, 'items' => []];
        }
        $data = $payload['data'] ?? [];
        if (! is_array($data)) {
            return ['error' => 'Format arsip WebAPI BPS tidak dikenali.', 'total' => 0, 'items' => []];
        }
        $rawItems = is_array($data[1] ?? null)
            ? $data[1]
            : array_values(array_filter(
                $data,
                fn ($item) => is_array($item) && (
                    isset($item['title']) || isset($item['judul'])
                    || isset($item['br_id']) || isset($item['pub_id'])
                    || isset($item['news_id'])
                )
            ));
        $hasMetadata = is_array($data[0] ?? null)
            && (isset($data[0]['pages']) || isset($data[0]['total']));
        if ($data !== [] && $rawItems === [] && ! $hasMetadata) {
            return ['error' => 'Format arsip WebAPI BPS tidak dikenali.', 'total' => 0, 'items' => []];
        }
        $rawItems = array_values(array_filter($rawItems, 'is_array'));
        usort($rawItems, function (array $left, array $right): int {
            $leftDate = $left['rl_date'] ?? $left['release_date'] ?? $left['pub_date'] ?? $left['date'] ?? '';
            $rightDate = $right['rl_date'] ?? $right['release_date'] ?? $right['pub_date'] ?? $right['date'] ?? '';

            return (strtotime((string) $rightDate) ?: 0) <=> (strtotime((string) $leftDate) ?: 0);
        });
        $meta = is_array($data[0] ?? null) ? $data[0] : [];
        $total = max(0, (int) ($meta['total'] ?? count($rawItems)));
        $items = [];
        foreach (array_slice($rawItems, 0, $limit) as $row) {
            $id = $row['br_id'] ?? $row['pub_id'] ?? $row['news_id'] ?? $row['id'] ?? null;
            $detail = [];
            if (is_scalar($id) && trim((string) $id) !== '') {
                $detailUrl = 'https://webapi.bps.go.id/v1/api/view/model/'.$model
                    .'/lang/ind/id/'.rawurlencode((string) $id).'/key/'.rawurlencode($apiKey).'/';
                try {
                    $detailPayload = $this->fetchEditorialPayload($detailUrl);
                    $detailData = $detailPayload['data'] ?? [];
                    if (is_array($detailData[1] ?? null)) {
                        $detail = $detailData[1][0] ?? [];
                    } elseif (is_array($detailData)) {
                        foreach ($detailData as $candidate) {
                            if (is_array($candidate) && (
                                isset($candidate['abstract']) || isset($candidate['title'])
                                || isset($candidate['br_id']) || isset($candidate['pub_id'])
                                || isset($candidate['news_id'])
                            )) {
                                $detail = $candidate;
                                break;
                            }
                        }
                    }
                } catch (ConnectionException|RuntimeException) {
                    $row['abstrak_tidak_tersedia'] = true;
                }
            } else {
                $row['abstrak_tidak_tersedia'] = true;
            }

            $formatted = $this->formatCatalogItem(array_merge($row, $detail), true);
            if ($id !== null) {
                $formatted['id'] = (string) $id;
            }
            if (($row['abstrak_tidak_tersedia'] ?? false) === true) {
                $formatted['abstrak_tidak_tersedia'] = true;
            } elseif (! isset($formatted['ringkasan'])) {
                $formatted['abstrak_tidak_tersedia'] = true;
            }
            if ($formatted !== []) {
                $items[] = $formatted;
            }
        }

        return [
            'total' => $total,
            'items' => $items,
            ...(! empty($payload['_catatan']) ? ['catatan_data' => $payload['_catatan']] : []),
        ];
    }

    private function fetchEditorialPayload(string $url): array
    {
        $ttl = $this->editorialFreshnessSeconds($url);
        $database = $this->bpsDatabase();
        $cacheIdentity = preg_replace('~/key/[^/]+/?$~', '/key/', $url) ?? $url;
        $cacheKey = hash('sha256', $cacheIdentity);
        $cachedPayload = null;
        if ($ttl > 0) {
            $cached = $database->table('editorial_cache')
                ->where('cache_key', $cacheKey)
                ->first();
            if ($cached !== null) {
                $decoded = json_decode((string) $cached->isi_json, true);
                if (is_array($decoded)) {
                    $cachedPayload = $decoded;
                    if (time() - (float) $cached->diambil_pada < $ttl) {
                        return $cachedPayload;
                    }
                }
            }
        }

        try {
            try {
                $response = Http::timeout(8)->get($url);
            } catch (ConnectionException) {
                throw new RuntimeException('WebAPI BPS tidak dapat diakses untuk memuat arsip saat ini.');
            }
            $payload = $response->json();
            if (strtolower((string) $response->header('data-availability')) === 'list-not-available') {
                $payload = ['status' => 'OK', 'data' => [['pages' => 1, 'total' => 0], []]];
            } elseif ($response->status() === 401 || $response->status() === 403) {
                throw new RuntimeException('API key BPS tidak diterima.');
            } elseif (! $response->successful()
                || ! is_array($payload)
                || ($payload['status'] ?? null) !== 'OK'
                || ! is_array($payload['data'] ?? null)) {
                throw new RuntimeException('WebAPI BPS tidak dapat memuat arsip yang diminta.');
            }
        } catch (ConnectionException|RuntimeException $exception) {
            if ($cachedPayload === null) {
                throw $exception;
            }
            $cachedPayload['_catatan'] = (
                'Arsip berasal dari penyimpanan sebelumnya karena WebAPI BPS belum dapat '
                .'memperbaruinya.'
            );

            return $cachedPayload;
        }

        if ($ttl > 0) {
            $database->table('editorial_cache')->upsert([[
            'cache_key' => $cacheKey,
            'isi_json' => json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ),
            'diambil_pada' => time(),
            ]], ['cache_key'], ['isi_json', 'diambil_pada']);
        }

        return $payload;
    }

    private function editorialFreshnessSeconds(string $url): int
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');
        if (str_contains($path, '/view/model/')) {
            return 24 * 3600;
        }
        if (! str_contains($path, '/list/model/')) {
            return 0;
        }
        $pathBeforeKey = explode('/key/', $path, 2)[0];
        if (preg_match('~/(keyword|year|month)/~', $pathBeforeKey) === 1) {
            return 3600;
        }

        return 0;
    }

    private function catalogSubjects(string $domain): array
    {
        if ($domain !== '1600') {
            return [];
        }
        $subjects = [];
        foreach ($this->csvCatalogRows() as $row) {
            $id = (string) ($row['id_subjek'] ?? '');
            if ($id !== '') {
                $subjects[$id] ??= $row;
            }
        }

        return array_values($subjects);
    }

    private function formatCatalogItem(array $item, bool $dated): array
    {
        $formatted = [];
        foreach ([
            'judul' => ['title', 'judul', 'name'],
            'tanggal' => $dated ? ['rl_date', 'release_date', 'pub_date', 'date', 'updt_date'] : [],
            'subjek' => ['sub_name', 'subject', 'subcat'],
            'jumlah_tabel' => ['ntable', 'ntabel'],
            'tautan' => ['pdf', 'pdf_url', 'url_pdf', 'url', 'link', 'permalink'],
            'ringkasan' => ['abstract', 'abstrak', 'description', 'content', 'isi'],
        ] as $label => $fields) {
            foreach ($fields as $field) {
                if (isset($item[$field]) && is_scalar($item[$field]) && trim((string) $item[$field]) !== '') {
                    $formatted[$label] = $label === 'jumlah_tabel' && is_numeric($item[$field])
                        ? (int) $item[$field]
                        : Str::limit(trim((string) $item[$field]), $label === 'ringkasan' ? 900 : 300);
                    break;
                }
            }
        }
        return $formatted;
    }

    private function dynamicContextFor(string $question, array $keywords, string $domain, string $apiKey): ?string
    {
        if (! $this->hasDynamicDataIntent($question)) {
            return null;
        }

        $terms = array_values(array_filter($keywords, fn (string $term) => ! preg_match('/^\d{4}$/', $term)));
        if (preg_match('/\b(?:q|triwulan)\s*(?:[1-4]|i{1,3}|iv)\b/i', $question) && ! in_array('triwulanan', $terms, true)) {
            $terms[] = 'triwulanan';
        }
        $synonyms = ['laju' => 'pertumbuhan', 'ekonomi' => 'pdrb', 'kemiskinan' => 'miskin'];
        $terms = array_values(array_unique(array_map(fn (string $term) => $synonyms[$term] ?? $term, $terms)));
        $terms = array_slice($terms, 0, 10);
        $requiredTerms = $this->requiredVariableTerms($question);
        if ($terms === []) {
            return $this->isRegionalDataQuestion($question)
                ? '[WebAPI BPS][DATA_BELUM_TERSEDIA][INDIKATOR_TIDAK_DITEMUKAN] Indikator yang diminta belum ditemukan di indeks WebAPI BPS.'
                : null;
        }

        $curatedVariables = $this->curatedVariables($terms);
        $searchVariables = $this->matchingVariables($terms, $question, $domain, $apiKey);
        $variablesById = [];
        foreach (array_merge($curatedVariables, $searchVariables) as $variable) {
            $variableId = $variable['var_id'] ?? null;
            $title = $variable['title'] ?? null;
            if (! is_scalar($variableId) || ! is_string($title)) {
                continue;
            }
            $matchesAllTerms = true;
            foreach ($requiredTerms as $term) {
                if ($this->titleTermScore($this->variableSearchText($variable), $term) === 0.0) {
                    $matchesAllTerms = false;
                    break;
                }
            }
            if (! $matchesAllTerms) {
                continue;
            }
            $key = (string) $variableId;
            if (isset($variablesById[$key])) {
                $score = max((float) ($variablesById[$key]['_score'] ?? 0), (float) ($variable['_score'] ?? 0));
                $variablesById[$key] = array_merge($variablesById[$key], $variable);
                $variablesById[$key]['_score'] = $score;
            } else {
                $variablesById[$key] = $variable;
            }
        }
        $variables = array_values($variablesById);
        if ($variables === []) {
            return '[WebAPI BPS][DATA_BELUM_TERSEDIA][INDIKATOR_TIDAK_DITEMUKAN] Indikator yang diminta belum ditemukan di indeks variabel WebAPI BPS.';
        }

        $requestedYear = preg_match('/\b(20\d{2})\b/', $question, $yearMatch)
            ? $yearMatch[1]
            : null;
        $requestedQuarter = $this->requestedQuarter($question);
        $region = $this->regionScopeMentioned($question);
        $compatibleVariables = [];
        foreach ($variables as $variable) {
            $variable['level'] = $this->explicitVariableLevel((string) ($variable['title'] ?? '')) ?? 'unknown';
            if ($region !== null) {
                if ($variable['level'] === 'subdistrict'
                    || (($region['kind'] ?? null) !== 'province' && $variable['level'] === 'province')) {
                    continue;
                }
                $variable['_score'] = (float) ($variable['_score'] ?? 0) + $this->regionalVariableScore($variable, $region);
            }
            $compatibleVariables[] = $variable;
        }
        if ($region !== null && $compatibleVariables === []) {
            return '[WebAPI BPS][DATA_BELUM_TERSEDIA][WILAYAH_TIDAK_TERSEDIA] Variabel yang ditemukan hanya menyediakan tingkat provinsi atau wilayah di bawah kabupaten/kota; data untuk '.$region['label'].' tidak tersedia.';
        }
        $variables = $compatibleVariables;

        $candidates = [];
        foreach ($variables as $variable) {
            $variableId = (int) ($variable['var_id'] ?? 0);
            if ($variableId === 0) {
                continue;
            }
            $periods = $this->periodsForVariable($variableId, $domain, $apiKey);
            $periods = array_values(array_filter(
                $periods,
                fn (array $period) => (int) ($period['th'] ?? 0) <= now('Asia/Jakarta')->year
            ));
            $periodsToCheck = $requestedYear === null
                ? array_slice($periods, 0, 1)
                : array_values(array_filter($periods, fn (array $period) => (string) ($period['th'] ?? '') === $requestedYear));
            $variable['_all_periods'] = $periods;
            $variable['_periods'] = $periodsToCheck;
            $variable['_latest_year'] = (int) ($periods[0]['th'] ?? 0);
            $variable['_selected_year'] = (int) ($periodsToCheck[0]['th'] ?? 0);
            $candidates[] = $variable;
        }

        usort($candidates, fn (array $left, array $right) => ($right['_score'] ?? 0) <=> ($left['_score'] ?? 0)
            ?: ($right['_latest_year'] ?? 0) <=> ($left['_latest_year'] ?? 0));
        $candidatesWithRequestedPeriod = array_values(array_filter($candidates, fn (array $candidate) => $candidate['_periods'] !== []));
        if ($requestedYear !== null && $candidatesWithRequestedPeriod === []) {
            $availableYears = [];
            foreach ($candidates as $candidate) {
                foreach ($candidate['_all_periods'] as $period) {
                    $year = (string) ($period['th'] ?? '');
                    if (ctype_digit($year)) {
                        $availableYears[] = (int) $year;
                    }
                }
            }
            $availableYears = array_values(array_unique($availableYears));
            rsort($availableYears);
            $availableYearsText = $availableYears === [] ? 'belum tersedia' : implode(', ', $availableYears);

            return '[WebAPI BPS][DATA_BELUM_TERSEDIA][TAHUN_TIDAK_TERSEDIA] Data untuk tahun '.$requestedYear.' tidak tersedia. Tahun yang tersedia pada katalog: '.$availableYearsText.'.';
        }
        $candidates = $candidatesWithRequestedPeriod;
        if ($candidates === []) {
            if ($requestedYear === null && $region === null) {
                return null;
            }

            return '[WebAPI BPS][DATA_BELUM_TERSEDIA][TAHUN_TIDAK_TERSEDIA] Indikator ditemukan, tetapi WebAPI BPS belum mengembalikan periode data yang dapat digunakan.';
        }

        $bestScore = max(array_map(fn (array $candidate) => (float) ($candidate['_score'] ?? 0), $candidates));
        $nearBestCandidates = array_values(array_filter(
            $candidates,
            fn (array $candidate) => (float) ($candidate['_score'] ?? 0) >= $bestScore - 5
        ));
        usort($nearBestCandidates, fn (array $left, array $right) => ($right['_selected_year'] ?? 0) <=> ($left['_selected_year'] ?? 0)
            ?: ($right['_score'] ?? 0) <=> ($left['_score'] ?? 0));
        $candidates = array_merge(
            $nearBestCandidates,
            array_values(array_filter(
                $candidates,
                fn (array $candidate) => (float) ($candidate['_score'] ?? 0) < $bestScore - 5
            ))
        );
        $best = $candidates[0];
        $ambiguous = array_values(array_filter($candidates, fn (array $candidate) => (float) ($candidate['_score'] ?? 0) === (float) ($best['_score'] ?? 0)
            && (int) ($candidate['_selected_year'] ?? 0) === (int) ($best['_selected_year'] ?? 0)));
        if (count($ambiguous) > 1) {
            $options = array_map(fn (array $candidate) => $candidate['title']
                .(filled($candidate['unit'] ?? null) ? ' ('.$candidate['unit'].')' : ''), array_slice($ambiguous, 0, 3));

            return '[WebAPI BPS][INDIKATOR_AMBIGU] Beberapa indikator paling cocok tersedia untuk tahun '.$best['_selected_year'].': '.implode('; ', $options).'. Mohon perjelas indikator yang dimaksud.';
        }

        $variable = $best;
        $variableId = (int) ($variable['var_id'] ?? 0);
        $period = $variable['_periods'][0];
        $isQuarterly = Str::contains(Str::lower($variable['title']), 'triwulan');
        $derivedPeriods = $isQuarterly ? $this->derivedPeriodsForVariable($variableId, $domain, $apiKey) : [];
        $quarters = array_values(array_filter($derivedPeriods, fn (array $period) => preg_match('/triwulan\s*([1-4]|i{1,3}|iv)/i', $period['turth'] ?? '') === 1));
        if ($requestedQuarter !== null) {
            $quarters = array_values(array_filter($quarters, fn (array $period) => $this->quarterNumber((string) ($period['turth'] ?? '')) === $requestedQuarter));
        } else {
            $quarters = array_reverse($quarters);
        }

        $dataOptions = $isQuarterly && $quarters !== [] ? $quarters : [null];
        $regionMissing = false;
        foreach ($dataOptions as $quarter) {
            $response = $this->dynamicData(
                $variableId,
                (int) $period['th_id'],
                $quarter['turth_id'] ?? null,
                $domain,
                $apiKey,
                (string) $variable['title'],
                (string) $period['th']
            );
            $values = $response?->json('datacontent');
            if (! is_array($values) || $values === []) {
                continue;
            }

            $regionRow = null;
            if ($region !== null) {
                $regionRow = $this->regionVervarRow($region, $response->json('vervar'));
                if ($regionRow === null) {
                    $regionMissing = true;

                    continue;
                }
            } elseif ($variableId === 51 && $this->isPopulationTotalQuestion($question)) {
                $regionRow = $this->provinceVervarRow($response->json('vervar'));
            }
            if ($regionRow !== null) {
                if (! is_scalar($regionRow['val'] ?? null)) {
                    $regionMissing = true;

                    continue;
                }
                $values = $this->valuesForRegion($values, $regionRow);
                if ($values === []) {
                    continue;
                }
            }
            if (($region !== null || ($variableId === 51 && $this->isPopulationTotalQuestion($question))) && $regionRow === null) {
                $regionMissing = true;

                continue;
            }

            $context = $this->formatDynamicContext($variable, $response, (string) $period['th'], $quarter['turth'] ?? null, $regionRow, $values);

            return $context;
        }

        if ($regionMissing && $region !== null) {
            return '[WebAPI BPS][DATA_BELUM_TERSEDIA][WILAYAH_TIDAK_TERSEDIA] Data wilayah '.$region['label'].' tidak tersedia pada indikator '.$variable['title'].' untuk tahun '.$period['th'].'.';
        }

        return '[WebAPI BPS][DATA_BELUM_TERSEDIA][NILAI_TIDAK_TERSEDIA] Indikator '.$variable['title'].' tersedia, tetapi WebAPI BPS belum mengembalikan nilai untuk tahun '.$period['th'].'.';
    }

    private function pressReleaseContextFor(string $question, string $domain, string $apiKey): ?string
    {
        $topic = $this->matchFallbackTopic($question);
        $keyword = $topic['matched_keyword'] ?? null;
        if ($keyword === null) {
            return null;
        }

        $dateFilters = $this->pressReleaseDateFilters($question);
        $cacheKey = 'bps-webapi:press-release:'.$domain.':'.$keyword.':'.sha1(json_encode($dateFilters) ?: '');
        $context = Cache::remember($cacheKey, now()->addHour(), function () use ($keyword, $domain, $apiKey, $dateFilters) {
            $catalog = ['model' => 'pressrelease', 'label' => 'Berita Resmi Statistik '.$keyword, 'limit' => 5, 'pages' => 1, 'dated' => true];
            $months = [];
            if (isset($dateFilters['year'])) {
                $months[] = $dateFilters;
            } else {
                $today = now('Asia/Jakarta');
                for ($monthsBack = 0; $monthsBack <= 2; $monthsBack++) {
                    $month = $today->copy()->subMonthsNoOverflow($monthsBack);
                    $months[] = ['year' => $month->year, 'month' => $month->month];
                }
            }

            foreach ($months as $filters) {
                try {
                    $result = $this->fetchCatalog($catalog, $domain, $apiKey, $filters + ['keyword' => $keyword]);
                } catch (ConnectionException) {
                    return '';
                }
                if (isset($result['error'])) {
                    return '';
                }
                $datedItems = array_values(array_filter($result['items'], fn (array $item) => isset($item['tanggal'])));
                if ($datedItems !== []) {
                    $lines = ['[Sumber: Berita Resmi Statistik BPS, kata kunci '.$keyword.']'];
                    foreach ($datedItems as $index => $item) {
                        $lines[] = ($index + 1).'. '.json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    }

                    return implode("\n", $lines);
                }
            }

            return '';
        });

        return $context !== '' ? $context : null;
    }

    private function formatFallbackPressReleases(
        array $items,
        string $notice,
        string $intro,
        string $closing,
        ?string $regionLabel = null
    ): string {
        $lines = [$notice, '', $intro];
        foreach ($items as $index => $item) {
            $lines[] = ($index + 1).'. '.$item['judul']
                .(isset($item['tanggal']) ? ' (rilis: '.$item['tanggal'].')' : '');
            if (isset($item['ringkasan'])) {
                $lines[] = '   Abstrak/isi yang dikembalikan API: '.$item['ringkasan'];
            } elseif ($item['abstrak_tidak_tersedia'] ?? false) {
                $lines[] = '   Abstrak/isi tidak tersedia pada respons detail API.';
            }
            $lines[] = isset($item['tautan'])
                ? '   Sumber/PDF: '.$item['tautan']
                : '   Tautan sumber/PDF tidak tersedia pada respons API.';
        }
        if ($regionLabel !== null) {
            $lines[] = '';
            $lines[] = str_replace('{region}', $regionLabel, config('chatbot_fallback.region_note'));
        }
        $lines[] = '';
        $lines[] = 'Ditampilkan pada '.now('Asia/Jakarta')->format('d-m-Y H:i').' WIB.';
        $lines[] = '';
        $lines[] = $closing;

        return implode("\n", $lines);
    }

    private function titlePatternForKeywords(array $keywords): string
    {
        $alternation = implode('|', array_map(fn (string $keyword) => preg_quote($keyword, '/'), $keywords));

        return '/\b(?:'.$alternation.')\b/iu';
    }

    private function regionalTopicTitlePattern(array $region, array $topic): string
    {
        $regionKeywords = array_merge($region['aliases'], [$region['label']]);
        $regionAlternation = implode('|', array_map(fn (string $keyword) => preg_quote($keyword, '/'), $regionKeywords));
        $topicAlternation = implode('|', array_map(fn (string $keyword) => preg_quote($keyword, '/'), $topic['title_keywords']));

        return '/(?=.*\b(?:'.$regionAlternation.')\b)(?=.*\b(?:'.$topicAlternation.')\b).*/iu';
    }

    private function latestPressReleases(
        string $apiKey,
        ?string $titlePattern,
        int $max,
        int $monthsBack = 6,
        string $domain = '1600'
    ): array {
        $catalog = ['model' => 'pressrelease', 'label' => 'Berita Resmi Statistik', 'limit' => 30, 'pages' => 3, 'dated' => true];
        $now = now('Asia/Jakarta');
        $found = [];

        for ($back = 0; $back <= $monthsBack && count($found) < $max; $back++) {
            $month = $now->copy()->subMonthsNoOverflow($back);
            $cacheKey = "bps-webapi:brs:{$domain}:{$month->year}-{$month->month}";
            $result = Cache::get($cacheKey);
            if ($result === null) {
                $result = $this->fetchCatalog($catalog, $domain, $apiKey, ['year' => $month->year, 'month' => $month->month]);
                if (isset($result['error'])) {
                    continue;
                }
                Cache::put($cacheKey, $result, now()->addMinutes(30));
            }

            foreach ($result['items'] as $item) {
                if (isset($item['judul'], $item['tanggal'])
                    && ($titlePattern === null || preg_match($titlePattern, $item['judul']))) {
                    $found[] = $item;
                }
            }
            if ($titlePattern === null && $found !== []) {
                break;
            }
        }

        usort($found, fn (array $left, array $right) => strcmp($right['tanggal'], $left['tanggal']));

        return array_slice($found, 0, $max);
    }

    private function matchFallbackTopic(string $question): ?array
    {
        return $this->matchFallbackTopics($question)[0] ?? null;
    }

    private function matchFallbackTopics(string $question): array
    {
        $matches = [];
        foreach (config('chatbot_fallback.topics', []) as $key => $topic) {
            foreach ($topic['keywords'] as $keyword) {
                if (preg_match('/\b'.preg_quote($keyword, '/').'/iu', $question)) {
                    $matches[] = $topic + ['key' => $key, 'matched_keyword' => $keyword];
                    break;
                }
            }
        }

        return $matches;
    }

    private function pressReleaseDateFilters(string $question): array
    {
        $filters = [];
        if (preg_match('/\b(20\d{2})\b/', $question, $match)) {
            $filters['year'] = (int) $match[1];
        } elseif (preg_match('/\b(tahun ini|tahun sekarang|sekarang|saat ini|terbaru|terkini|terakhir)\b/i', $question)) {
            $filters['year'] = now('Asia/Jakarta')->year;
        }

        if (preg_match('/\bbulan\s+(1[0-2]|[1-9])\b/i', $question, $match)) {
            $filters['month'] = (int) $match[1];
        } else {
            $months = [
                'januari' => 1, 'februari' => 2, 'maret' => 3, 'april' => 4,
                'mei' => 5, 'juni' => 6, 'juli' => 7, 'agustus' => 8,
                'september' => 9, 'oktober' => 10, 'november' => 11, 'desember' => 12,
            ];
            foreach ($months as $name => $number) {
                if (preg_match('/\b'.preg_quote($name, '/').'\b/i', $question)) {
                    $filters['month'] = $number;
                    break;
                }
            }
        }

        if (isset($filters['month']) && ! isset($filters['year'])) {
            $filters['year'] = now('Asia/Jakarta')->year;
        }

        return $filters;
    }

    private function monthlyReleaseUnavailableContext(string $question): string
    {
        $year = preg_match('/\b(20\d{2})\b/', $question, $match)
            ? $match[1]
            : (preg_match('/\b(tahun ini|tahun sekarang|sekarang|saat ini|terbaru|terkini|terakhir)\b/i', $question)
                ? (string) now('Asia/Jakarta')->year
                : 'periode yang diminta');

        return '[WebAPI BPS][DATA_BELUM_TERSEDIA] BRS bertanggal untuk indikator bulanan '.$year.' belum ditemukan atau belum dapat diverifikasi. Jangan gunakan nilai dari variabel lain atau periode yang lebih lama sebagai pengganti. Beri tahu pengguna data tersebut belum tersedia dan arahkan ke https://sumsel.bps.go.id.';
    }

    private function isCurrentPeriodRequest(string $question): bool
    {
        if (preg_match('/\b(tahun ini|tahun sekarang|bulan ini|sekarang|saat ini|terbaru|terkini|terakhir)\b/i', $question)) {
            return true;
        }

        return preg_match('/\b(20\d{2})\b/', $question, $match) === 1
            && (int) $match[1] === now('Asia/Jakarta')->year;
    }

    private function isMonthlyBpsTopic(string $question): bool
    {
        return preg_match('/\b(inflasi|ntp|ekspor|impor|wisata|pariwisata|hotel|penumpang|transportasi|ketenagakerjaan|tenaga\s+kerja|penganggur\w*|tpt|upah)\b/i', $question) === 1;
    }

    private function regionMentioned(string $question): ?array
    {
        foreach ($this->regionsMentioned($question) as $region) {
            if (($region['kind'] ?? null) === 'region') {
                return $region;
            }
        }

        return null;
    }

    private function regionScopeMentioned(string $question): ?array
    {
        $region = $this->regionMentioned($question);
        if ($region !== null) {
            return $region;
        }

        foreach ($this->regionsMentioned($question) as $candidate) {
            if (($candidate['kind'] ?? null) === 'province') {
                return $candidate;
            }
        }

        return null;
    }

    private function regionsMentioned(string $question): array
    {
        $index = [];
        foreach (config('sumsel_regions', []) as $region) {
            foreach ($region['aliases'] as $alias) {
                $index[] = ['alias' => $alias, 'region' => $region];
            }
        }
        usort($index, fn (array $left, array $right) => mb_strlen($right['alias']) <=> mb_strlen($left['alias']));
        $matches = [];
        $occupied = [];
        foreach ($index as $entry) {
            if (preg_match('/\b'.preg_quote($entry['alias'], '/').'\b/iu', $question, $match, PREG_OFFSET_CAPTURE)) {
                $start = $match[0][1];
                $end = $start + strlen($match[0][0]);
                $overlaps = false;
                foreach ($occupied as [$occupiedStart, $occupiedEnd]) {
                    if ($start < $occupiedEnd && $end > $occupiedStart) {
                        $overlaps = true;
                        break;
                    }
                }
                if (! $overlaps) {
                    $region = $entry['region'];
                    $region['kind'] = 'region';
                    $matches[(string) $region['domain']] = $region;
                    $occupied[] = [$start, $end];
                }
            }
        }

        if (preg_match('/\b(sumsel|sumatera\s+selatan|provinsi(?:\s+sumatera\s+selatan)?)\b/iu', $question)) {
            $matches['1600'] = $this->provinceFallbackRegion();
        }

        return array_values($matches);
    }

    private function provinceFallbackRegion(): array
    {
        return [
            'label' => 'Provinsi Sumatera Selatan',
            'aliases' => ['sumsel', 'sumatera selatan', 'provinsi'],
            'match' => ['sumatera selatan', 'sumsel'],
            'domain' => '1600',
            'kind' => 'province',
        ];
    }

    private function regionInVervar(array $region, mixed $vervar): bool
    {
        return $this->regionVervarRow($region, $vervar) !== null;
    }

    private function regionVervarRow(array $region, mixed $vervar): ?array
    {
        if (! is_array($vervar)) {
            return null;
        }

        foreach ($vervar as $row) {
            if (! is_array($row)) {
                continue;
            }
            $label = Str::lower(trim((string) ($row['label'] ?? '')));
            $label = trim(preg_replace('/^(kabupaten|kab\.?|kota)\s+/u', '', $label) ?? $label);
            if (in_array($label, $region['match'], true)) {
                return $row;
            }
        }

        return null;
    }

    private function valuesForRegion(array $values, array $regionRow): array
    {
        $regionValue = (string) ($regionRow['val'] ?? '');
        if ($regionValue === '') {
            return [];
        }

        return array_filter(
            $values,
            fn ($value, $key) => (string) $key === $regionValue || str_starts_with((string) $key, $regionValue),
            ARRAY_FILTER_USE_BOTH
        );
    }

    private function isPopulationTotalQuestion(string $question): bool
    {
        return preg_match('/\b(total|jumlah|penduduk|warga|masyarakat)\b/i', $question) === 1
            && preg_match('/\b(miskin\w*|kemiskinan|angkatan\s+kerja|ketenagakerjaan|penganggur\w*|tpt|'
                .'pria|lelaki|cowok|laki[- ]laki|wanita|perempuan|cewek|gender|jenis\s+kelamin)\b/i', $question) !== 1;
    }

    private function dynamicFallbackDetails(
        string $question,
        ?array $region,
        bool $populationQuestion,
        ?string $context = null
    ): ?string {
        $context ??= $this->contextFor($question);
        if ($context === null || $this->isUnavailableDataContext($context)) {
            return null;
        }
        $subregionalFallback = str_starts_with($context, '[WebAPI BPS][CAKUPAN_KAB_KOTA] ');
        if ($subregionalFallback) {
            $context = substr($context, strlen('[WebAPI BPS][CAKUPAN_KAB_KOTA] '));
        }
        $provinceFallback = str_starts_with($context, '[WebAPI BPS][CAKUPAN_PROVINSI] ');
        if ($provinceFallback) {
            $context = substr($context, strlen('[WebAPI BPS][CAKUPAN_PROVINSI] '));
        }
        if (! preg_match('/^\[Sumber: WebAPI BPS Sumatera Selatan, [^\]]+\]\s+(\{.*\})$/su', $context, $matches)) {
            return null;
        }

        $payload = json_decode($matches[1], true);
        if (! is_array($payload)
            || ! is_array($payload['nilai'] ?? null)
            || ! is_scalar($payload['var_id'] ?? null)
            || ! is_scalar($payload['indikator'] ?? null)
            || ! is_scalar($payload['tahun'] ?? null)) {
            return null;
        }

        $vervar = $payload['wilayah'] ?? null;
        if ($subregionalFallback) {
            $regionRows = [];
            foreach (config('sumsel_regions', []) as $subregion) {
                $subregionalRow = $this->regionVervarRow($subregion, $vervar);
                if ($subregionalRow !== null) {
                    $regionRows[] = $subregionalRow;
                }
            }
            if ($regionRows === []) {
                return null;
            }
            $regionRow = null;
            $regionLabel = 'kabupaten/kota di Sumatera Selatan';
        } elseif (filled($payload['vervar_label'] ?? null)) {
            $regionRow = $this->vervarRowByLabel($vervar, (string) $payload['vervar_label']);
            $regionLabel = (string) ($payload['wilayah_cakupan'] ?? $region['label'] ?? 'Provinsi Sumatera Selatan');
        } elseif ($region !== null && ! $provinceFallback) {
            $regionRow = $this->regionVervarRow($region, $vervar);
            $regionLabel = $region['label'];
        } else {
            $regionRow = $this->provinceVervarRow($vervar);
            $regionLabel = 'Provinsi Sumatera Selatan';
        }
        if (! $subregionalFallback
            && ($regionRow === null || ! is_scalar($regionRow['val'] ?? null))) {
            return null;
        }

        $categories = is_array($payload['kategori'] ?? null) ? $payload['kategori'] : [];
        if ($populationQuestion && ! $subregionalFallback) {
            $categories = array_values(array_filter($categories, fn ($category) => is_array($category)
                && preg_match('/^(jumlah|total|tidak ada|laki-laki\s*\+\s*perempuan)$/iu', trim((string) ($category['label'] ?? ''))) === 1));
            if ($categories === []) {
                return null;
            }
        } elseif ($categories === []) {
            $categories = [['label' => null, 'val' => null]];
        }

        $details = [];
        $regionRowsToFormat = $subregionalFallback ? $regionRows : [$regionRow];
        foreach ($regionRowsToFormat as $currentRegionRow) {
            foreach ($categories as $category) {
                $prefix = (string) $currentRegionRow['val'].(string) $payload['var_id'];
                if (is_scalar($category['val'] ?? null)) {
                    $prefix .= (string) $category['val'];
                }
                $matchesForCategory = array_filter(
                    $payload['nilai'],
                    fn ($value, $key) => is_numeric($value) && str_starts_with((string) $key, $prefix),
                    ARRAY_FILTER_USE_BOTH
                );
                if (count($matchesForCategory) !== 1) {
                    continue;
                }

                $categoryLabel = preg_match('/^laki-laki\s*\+\s*perempuan$/iu', (string) ($category['label'] ?? ''))
                    ? 'Total'
                    : (preg_match('/^tidak ada$/iu', (string) ($category['label'] ?? ''))
                        ? 'Total'
                        : ($this->provinceAggregateVervarRow([[
                            'label' => $payload['vervar_label'] ?? '',
                        ]]) !== null
                            ? 'Total'
                            : ($category['label'] ?? 'Nilai')));
                $rawValue = (string) reset($matchesForCategory);
                $decimalPart = explode('.', $rawValue, 2)[1] ?? '';
                $decimalPlaces = min(strlen(rtrim($decimalPart, '0')), 6);
                $value = number_format((float) $rawValue, $decimalPlaces, ',', '.');
                $unit = filled($payload['satuan'] ?? null) ? ' '.trim((string) $payload['satuan']) : '';
                $label = $subregionalFallback ? ($currentRegionRow['label'].': ') : '';
                if ($subregionalFallback && $categoryLabel !== 'Nilai') {
                    $label .= $categoryLabel.': ';
                } elseif (! $subregionalFallback) {
                    $label .= $categoryLabel.': ';
                }
                $details[] = $label.$value.$unit;
            }
        }
        if ($details === []) {
            return null;
        }

        $period = filled($payload['periode'] ?? null) && $payload['periode'] !== 'Tahunan'
            ? ' '.$payload['periode']
            : '';

        $answer = ($subregionalFallback
            ? 'Maaf, data agregat Provinsi Sumatera Selatan tidak tersedia untuk indikator ini. '
                .'Namun, data per kabupaten/kota berikut tersedia:'."\n"
            : '')
            .'Data WebAPI BPS untuk '.$payload['indikator'].' di '.$regionLabel.' tahun '.$payload['tahun'].$period.":\n".implode("\n", $details);
        if ($provinceFallback && $region !== null) {
            return 'Data khusus '.$region['label'].' tidak ditemukan; berikut data tingkat Provinsi Sumatera Selatan.'."\n".$answer;
        }

        return $answer;
    }

    private function questionWithoutRegionalScope(string $question): string
    {
        foreach ($this->regionsMentioned($question) as $region) {
            $names = array_merge($region['aliases'] ?? [], [$region['label'] ?? '']);
            usort($names, fn (string $left, string $right) => mb_strlen($right) <=> mb_strlen($left));
            foreach ($names as $name) {
                if ($name !== '') {
                    $question = preg_replace('/\b'.preg_quote($name, '/').'\b/iu', ' ', $question) ?? $question;
                }
            }
        }

        return trim(preg_replace('/\s+/u', ' ', $question) ?? $question);
    }

    private function provinceScopeFallbackContext(string $context, ?array $region): string
    {
        $label = $region['label'] ?? 'wilayah yang diminta';

        return '[WebAPI BPS][CAKUPAN_PROVINSI] Data khusus '.$label.' tidak ditemukan; hasil berikut hanya berlaku untuk tingkat Provinsi Sumatera Selatan. '
            .$context;
    }

    private function provinceOnlyDynamicContext(?string $context): ?string
    {
        if ($context === null
            || $this->isUnavailableDataContext($context)
            || ! preg_match('/^(\[Sumber: WebAPI BPS Sumatera Selatan, [^\]]+\]\s+)(\{.*\})$/su', $context, $matches)) {
            return null;
        }

        $payload = json_decode($matches[2], true);
        if (! is_array($payload) || ! is_array($payload['nilai'] ?? null)) {
            return null;
        }
        $provinceRow = $this->provinceVervarRow($payload['wilayah'] ?? null);
        if ($provinceRow === null || ! is_scalar($provinceRow['val'] ?? null)) {
            return null;
        }
        $values = $this->valuesForRegion($payload['nilai'], $provinceRow);
        if ($values === []) {
            return null;
        }

        $payload['wilayah'] = [$provinceRow];
        $payload['nilai'] = $values;
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($json) ? $matches[1].$json : null;
    }

    private function provinceVervarRow(mixed $vervar): ?array
    {
        if (! is_array($vervar)) {
            return null;
        }

        foreach ($vervar as $row) {
            if (! is_array($row)) {
                continue;
            }
            $label = Str::lower(trim((string) ($row['label'] ?? '')));
            $label = trim(preg_replace('/^provinsi\s+/u', '', $label) ?? $label);
            if (in_array($label, ['sumatera selatan', 'sumsel'], true)) {
                return $row;
            }
        }

        return null;
    }

    private function provinceAggregateVervarRow(mixed $vervar): ?array
    {
        if (! is_array($vervar)) {
            return null;
        }

        foreach ($vervar as $row) {
            if (! is_array($row)) {
                continue;
            }
            $label = Str::lower(trim((string) ($row['label'] ?? '')));
            $label = trim(preg_replace('/^(?:[a-z]\s*[\.\-\)]\s*|\d+\s*[\.\-\)]?\s*)/u', '', $label) ?? $label);
            if (in_array($label, [
                'jumlah',
                'total',
                'produk domestik regional bruto',
                'perkotaan+pedesaan',
            ], true)) {
                return $row;
            }
        }

        return null;
    }

    private function vervarRowByLabel(mixed $vervar, string $expectedLabel): ?array
    {
        if (! is_array($vervar)) {
            return null;
        }

        $expected = Str::lower(trim($expectedLabel));
        foreach ($vervar as $row) {
            if (! is_array($row)) {
                continue;
            }
            $label = Str::lower(trim((string) ($row['label'] ?? '')));
            $label = trim(preg_replace('/^\d+\s*[\.\-\)]?\s*/u', '', $label) ?? $label);
            if ($label === $expected) {
                return $row;
            }
        }

        return null;
    }

    private function matchingVariables(array $terms, string $question, string $domain, string $apiKey): array
    {
        $mainKeyword = $this->mainKeyword($terms);
        if ($mainKeyword === null) {
            return [];
        }
        $requiredTerms = $this->requiredVariableTerms($question);

        $variables = [];
        $hasApiMatch = false;
        $relevantTerms = array_values(array_filter($terms, fn (string $term) => ! in_array($term, self::GENERIC_TERMS, true)));
        $phrase = implode(' ', $relevantTerms);
        $searchTerms = [];
        foreach ($relevantTerms ?: [$mainKeyword] as $term) {
            $searchTerms = array_merge($searchTerms, $this->keywordVariants($term));
        }
        $indexedVariables = [];
        foreach (array_unique($searchTerms) as $searchTerm) {
            foreach ($this->variablesForKeyword($searchTerm, $domain, $apiKey) as $variable) {
                if (isset($variable['var_id'])) {
                    $hasApiMatch = $hasApiMatch || ! ($variable['_local_catalog_fallback'] ?? false);
                    $indexedVariables[(string) $variable['var_id']] = $variable;
                }
            }
        }

        foreach ($indexedVariables as $variable) {
            $id = $variable['var_id'] ?? null;
            $title = $variable['title'] ?? null;
            if (! is_scalar($id) || ! is_string($title)) {
                continue;
            }

            $normalizedTitle = Str::lower($title);
            $matchesAllTerms = true;
            foreach ($requiredTerms as $term) {
                if ($this->titleTermScore($this->variableSearchText($variable), $term) === 0.0) {
                    $matchesAllTerms = false;
                    break;
                }
            }
            if (! $matchesAllTerms) {
                continue;
            }
            if ($this->titleTermScore($normalizedTitle, $mainKeyword) === 0.0) {
                continue;
            }

            $score = 0;
            foreach ($terms as $searchTerm) {
                $termScore = $this->titleTermScore($normalizedTitle, $searchTerm);
                if ($termScore > 0) {
                    $score += in_array($searchTerm, self::GENERIC_TERMS, true)
                        ? 1
                        : min(Str::length($searchTerm), 10) * $termScore;
                }
            }
            if ($phrase !== '' && Str::contains($normalizedTitle, $phrase)) {
                $score += 20;
            }
            $score -= min(2, max(0, Str::length($title) - 80) / 20);
            if (preg_match('/\b(?:q|triwulan)\s*(?:[1-4]|i{1,3}|iv)\b/i', $question) && Str::contains($normalizedTitle, 'triwulan')) {
                $score += 20;
            } elseif (preg_match('/\bpdrb\b/i', $question) && ! preg_match('/\btahunan\b/i', $question) && Str::contains($normalizedTitle, 'triwulan')) {
                $score += 10;
            }
            if (preg_match('/\b(per\s*tahun|pertahun|tahunan)\b/i', $question) && ! Str::contains($normalizedTitle, 'triwulan')) {
                $score += 20;
            }
            if (preg_match('/\badhk\b/i', $question) && Str::contains($normalizedTitle, 'konstan')) {
                $score += 10;
            }
            if (preg_match('/\badhb\b/i', $question) && Str::contains($normalizedTitle, 'berlaku')) {
                $score += 10;
            }
            $requestsKabKota = preg_match('/\b(kabupaten|kab\.?|kota)\b/iu', $question) === 1;
            $requestsGender = preg_match('/\b(jenis\s+kelamin|gender|laki[- ]laki|perempuan|pria|wanita)\b/iu', $question) === 1;
            $hasGenderScope = preg_match('/\b(jenis\s+kelamin|gender|laki[- ]laki|perempuan)\b/iu', $title) === 1;
            if ($requestsKabKota) {
                $score += $this->explicitVariableLevel($title) === 'kab_kota' ? 15 : -10;
            }
            if ($hasGenderScope) {
                $score += $requestsGender ? 8 : -3;
            }

            $variable['level'] = $this->explicitVariableLevel($title) ?? 'unknown';
            $variable['_score'] = $score;
            if (! isset($variables[(string) $id]) || $score > ($variables[(string) $id]['_score'] ?? 0)) {
                $variables[(string) $id] = $variable;
            }
        }

        if ($hasApiMatch) {
            $variables = array_filter(
                $variables,
                fn (array $variable) => ! ($variable['_local_catalog_fallback'] ?? false)
            );
        }
        uasort($variables, fn (array $left, array $right) => ($right['_score'] ?? 0) <=> ($left['_score'] ?? 0));

        $minimumScore = count($requiredTerms) <= 1 ? 2 : 6;

        return array_slice(
            array_values(array_filter($variables, fn (array $variable) => ($variable['_score'] ?? 0) >= $minimumScore)),
            0,
            10
        );
    }

    private function regionalVariableScore(array $variable, array $region): int
    {
        $level = $variable['level'] ?? null;
        if (($region['kind'] ?? null) === 'province') {
            if ($level === 'province') {
                return 50;
            }
            if (in_array($level, ['kab_kota', 'subdistrict'], true)) {
                return -35;
            }

            return 15;
        }

        $score = $level === 'kab_kota' ? 50 : 0;
        $namedRegions = $this->regionsMentionedIn((string) ($variable['title'] ?? ''));
        if (count($namedRegions) === 1) {
            return $score + ((string) ($namedRegions[0]['domain'] ?? '') === (string) ($region['domain'] ?? '') ? 30 : -35);
        }
        if (count($namedRegions) > 1) {
            foreach ($namedRegions as $namedRegion) {
                if ((string) ($namedRegion['domain'] ?? '') === (string) ($region['domain'] ?? '')) {
                    return $score + 15;
                }
            }

            return $score - 35;
        }

        return $score;
    }

    private function regionsMentionedIn(string $text): array
    {
        $index = [];
        foreach (config('sumsel_regions', []) as $region) {
            foreach ($region['aliases'] as $alias) {
                $index[] = ['alias' => $alias, 'region' => $region];
            }
        }
        usort($index, fn (array $left, array $right) => mb_strlen($right['alias']) <=> mb_strlen($left['alias']));

        $matched = [];
        $remaining = $text;
        foreach ($index as $entry) {
            if (preg_match('/\b'.preg_quote($entry['alias'], '/').'\b/iu', $remaining, $match, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }
            $matched[(string) $entry['region']['domain']] = $entry['region'];
            $remaining = substr_replace($remaining, ' ', $match[0][1], strlen($match[0][0]));
        }

        return array_values($matched);
    }

    private function curatedVariables(array $terms): array
    {
        $catalogVariables = [];
        foreach ($this->localCatalogIndex()['variables'] as $catalogVariable) {
            $catalogVariables[(string) $catalogVariable['var_id']] = $catalogVariable;
        }
        $variables = [];
        foreach (config('bps_indicators.groups', []) as $group) {
            $matchedGroupTerms = array_values(array_intersect($terms, $group['terms'] ?? []));
            if ($matchedGroupTerms === []
                || (in_array('ipm', $group['terms'] ?? [], true)
                    && ! in_array('ipm', $matchedGroupTerms, true)
                    && count($matchedGroupTerms) < 2)) {
                continue;
            }

            foreach ($group['variables'] ?? [] as $variable) {
                $catalogVariable = $catalogVariables[(string) ($variable['var_id'] ?? '')] ?? null;
                if (array_intersect($terms, $variable['excludes'] ?? []) !== []) {
                    continue;
                }
                $variable['title'] = $catalogVariable['title'] ?? $variable['title'] ?? '';
                $variable['subject'] = $catalogVariable['subject'] ?? $variable['subject'] ?? '';
                $variable['category'] = $catalogVariable['category'] ?? $variable['category'] ?? '';
                $variable['aliases'] = $catalogVariable['aliases'] ?? $this->variableAliases((string) $variable['title']);
                $score = 0;
                foreach ($terms as $term) {
                    if (in_array($term, $variable['terms'] ?? [], true)) {
                        $score += in_array($term, self::GENERIC_TERMS, true) ? 1 : min(Str::length($term), 10);
                    }
                }
                $variable['_score'] = $score;
                $variables[(string) $variable['var_id']] = $variable;
            }
        }

        uasort($variables, fn (array $left, array $right) => ($right['_score'] ?? 0) <=> ($left['_score'] ?? 0));

        return array_values(array_slice($variables, 0, 5));
    }

    private function explicitVariableLevel(string $title): ?string
    {
        if (preg_match('/\bkab(?:upaten)?\s*\/\s*kota\b|\bkabupaten\s+dan\s+kota\b/iu', $title) === 1) {
            return 'kab_kota';
        }
        if (preg_match('/\b(kecamatan|kelurahan|desa)\b/iu', $title) === 1) {
            return 'subdistrict';
        }
        if (preg_match('/\bprovinsi\b/iu', $title) === 1) {
            return 'province';
        }

        return null;
    }

    private function variableSearchUrl(string $keyword, string $domain, string $apiKey, ?int $page = null): string
    {
        $segments = ['list', 'model', 'var', 'domain', $domain, 'keyword', rawurlencode($keyword)];
        if ($page !== null) {
            $segments = array_merge($segments, ['page', (string) $page]);
        }
        $segments = array_merge($segments, ['key', rawurlencode($apiKey)]);

        return 'https://webapi.bps.go.id/v1/api/'.implode('/', $segments).'/';
    }

    private function variablesForKeyword(string $keyword, string $domain, string $apiKey): array
    {
        $cacheKey = 'bps-webapi:variables:keyword:'.$domain.':'.sha1(Str::lower($keyword));
        $apiVariables = Cache::remember($cacheKey, now()->addHours(6), function () use ($keyword, $domain, $apiKey): array {
            $variables = [];
            $firstPageUrl = $this->variableSearchUrl($keyword, $domain, $apiKey);
            $page = 1;
            $pageCount = 1;
            do {
                $url = $page === 1
                    ? $firstPageUrl
                    : $this->variableSearchUrl($keyword, $domain, $apiKey, $page);
                $response = Http::timeout(8)->get($url);
                if ($response->status() === 401 || $response->status() === 403) {
                    throw new RuntimeException('API key WebAPI BPS tidak diterima saat mencari variabel.');
                }
                if ($response->status() === 404) {
                    return [];
                }
                if (! $response->successful()) {
                    throw new RuntimeException('WebAPI BPS gagal memuat indeks variabel.');
                }

                $payload = $response->json();
                $items = $payload['data'][1] ?? null;
                if (($payload['status'] ?? null) !== 'OK' || ! is_array($items)) {
                    throw new RuntimeException('Format indeks variabel WebAPI BPS tidak dikenali.');
                }
                $pageCount = max(1, (int) ($payload['data'][0]['pages'] ?? 1));
                if ($pageCount > 100) {
                    throw new RuntimeException('Jumlah halaman indeks variabel WebAPI BPS di luar batas yang diizinkan.');
                }

                foreach ($items as $item) {
                    if (! is_array($item)
                        || ! is_scalar($item['var_id'] ?? null)
                        || ! is_scalar($item['title'] ?? null)
                        || trim((string) $item['title']) === '') {
                        continue;
                    }
                    $title = trim((string) $item['title']);
                    $variables[(string) $item['var_id']] = array_merge($item, [
                        'var_id' => (string) $item['var_id'],
                        'title' => $title,
                        'subject_id' => $item['sub_id'] ?? null,
                        'subject' => $item['sub_name'] ?? '',
                        'category' => $item['subcsa_name'] ?? '',
                        'unit' => $item['unit'] ?? null,
                        'aliases' => $this->variableAliases($title),
                        'level' => $this->explicitVariableLevel($title) ?? 'unknown',
                    ]);
                }
                $page++;
            } while ($page <= $pageCount);

            return array_values($variables);
        });

        $matches = array_values(array_filter(
            $apiVariables,
            fn (array $variable) => $this->titleTermScore(
                $this->variableSearchText($variable),
                $keyword
            ) > 0
        ));
        if ($matches !== []) {
            return $matches;
        }

        $localMatches = array_values(array_filter(
            $this->localCatalogIndex()['variables'],
            fn (array $variable) => $this->titleTermScore(
                $this->variableSearchText($variable),
                $keyword
            ) > 0
        ));
        foreach ($localMatches as &$variable) {
            $variable['_local_catalog_fallback'] = true;
        }
        unset($variable);

        return $localMatches;
    }

    private function localCatalogIndex(): array
    {
        $path = (string) config('services.bps.catalog_csv', base_path('tabel_bps_sumsel.csv'));
        $signature = is_file($path)
            ? (string) filemtime($path).':'.(string) filesize($path)
            : 'missing';

        return Cache::remember(
            'bps-webapi:variables:local-catalog:'.sha1($path.':'.$signature),
            now()->addHours(26),
            function (): array {
                $catalogRows = $this->csvCatalogRows();
                $subjectsById = [];
                $variablesById = [];
                foreach ($catalogRows as $row) {
                    $subjectId = (string) ($row['id_subjek'] ?? '');
                    if ($subjectId !== '') {
                        $subjectsById[$subjectId] ??= [
                            'subcat_id' => '',
                            'kategori' => $row['kategori_subjek'] ?? '',
                            'sub_id' => $subjectId,
                            'subjek' => $row['subjek'] ?? '',
                            'jumlah_tabel' => null,
                        ];
                    }
                    if (($row['jenis_tabel'] ?? '') !== 'Tabel Dinamis') {
                        continue;
                    }
                    $variableId = trim((string) ($row['id_tabel'] ?? ''));
                    $title = trim((string) ($row['judul'] ?? ''));
                    if (! ctype_digit($variableId) || $title === '') {
                        continue;
                    }
                    $variablesById[$variableId] ??= [
                        'var_id' => $variableId,
                        'title' => $title,
                        'unit' => null,
                        'subject_id' => $subjectId,
                        'subject' => $row['subjek'] ?? '',
                        'category' => $row['kategori_subjek'] ?? '',
                        'category_id' => null,
                        'latest_year' => null,
                        'year_count' => 0,
                        'years' => [],
                        'aliases' => $this->variableAliases($title),
                    ];
                    $year = (string) ($row['tahun'] ?? '');
                    if (ctype_digit($year)) {
                        $variablesById[$variableId]['years'][] = (int) $year;
                    }
                }
                $variables = array_values($variablesById);
                foreach ($variables as &$variable) {
                    $variable['years'] = array_values(array_unique($variable['years']));
                    rsort($variable['years']);
                    $variable['latest_year'] = $variable['years'][0] ?? null;
                    $variable['year_count'] = count($variable['years']);
                }
                unset($variable);

                return ['subjects' => array_values($subjectsById), 'variables' => $variables];
            }
        );
    }

    private function readCatalogCsv(string $filename, array $requiredColumns): array
    {
        $path = is_file($filename) ? $filename : base_path($filename);
        if (! is_file($path)) {
            return [];
        }
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new RuntimeException("Katalog BPS lokal {$filename} tidak dapat dibuka.");
        }

        try {
            $headers = fgetcsv($handle);
            if (! is_array($headers)) {
                throw new RuntimeException("Katalog BPS lokal {$filename} tidak memiliki header.");
            }
            $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]) ?? (string) $headers[0];
            if (array_diff($requiredColumns, $headers) !== []) {
                throw new RuntimeException("Header katalog BPS lokal {$filename} tidak sesuai.");
            }

            $rows = [];
            while (($values = fgetcsv($handle)) !== false) {
                if ($values === [null]) {
                    continue;
                }
                if (count($values) !== count($headers)) {
                    throw new RuntimeException("Baris katalog BPS lokal {$filename} tidak lengkap.");
                }
                $row = array_combine($headers, $values);
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    private function variableAliases(string $title): array
    {
        preg_match_all('/\(([A-Z][A-Z0-9]{1,7})\)/u', $title, $matches);
        $aliases = $matches[1] ?? [];
        $words = preg_split('/[^a-z0-9]+/u', Str::lower(Str::ascii($title)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $stopWords = ['dan', 'di', 'dari', 'ke', 'yang', 'menurut', 'per', 'serta'];
        $acronymWords = [];
        foreach ($words as $word) {
            if (in_array($word, $stopWords, true)) {
                continue;
            }
            if ($acronymWords === [] || end($acronymWords) !== $word) {
                $acronymWords[] = $word;
            }
        }
        if (count($acronymWords) >= 2 && count($acronymWords) <= 8) {
            $aliases[] = implode('', array_map(fn (string $word) => $word[0], $acronymWords));
        }

        return array_values(array_unique(array_map(fn (string $alias) => Str::lower($alias), $aliases)));
    }

    private function variableSearchText(array $variable): string
    {
        return implode(' ', array_filter([
            (string) ($variable['title'] ?? ''),
            (string) ($variable['subject'] ?? ''),
            (string) ($variable['category'] ?? ''),
            implode(' ', $variable['aliases'] ?? []),
        ]));
    }

    private function catalogVariableMatches(string $question): bool
    {
        $terms = $this->requiredVariableTerms($question);
        if ($terms === []) {
            return false;
        }
        foreach ($this->localCatalogIndex()['variables'] as $variable) {
            $titleAndAliases = $this->variableSearchText($variable);
            $matchesAllTerms = true;
            foreach ($terms as $term) {
                if ($this->titleTermScore($titleAndAliases, $term) === 0.0) {
                    $matchesAllTerms = false;
                    break;
                }
            }
            if ($matchesAllTerms) {
                return true;
            }
        }

        return false;
    }

    private function titleHasExactTerm(string $title, string $term): bool
    {
        $normalizedTitle = Str::lower(Str::ascii($title));
        foreach ($this->keywordVariants($term) as $variant) {
            if ($variant !== '' && preg_match(
                '/(?<![a-z0-9])'.preg_quote($variant, '/').'(?![a-z0-9])/i',
                $normalizedTitle
            ) === 1) {
                return true;
            }
        }

        return false;
    }

    private function keywordVariants(string $keyword): array
    {
        $keyword = Str::lower(trim(Str::ascii($keyword)));
        $synonyms = [
            'kemiskinan' => ['miskin'],
            'miskin' => ['kemiskinan'],
            'rls' => ['lama', 'sekolah'],
            'hls' => ['harapan', 'lama', 'sekolah'],
            'ipm' => ['indeks', 'pembangunan', 'manusia'],
            'pengangguran' => ['penganggur'],
            'penganggur' => ['pengangguran'],
            'penduduk' => ['populasi', 'warga'],
            'populasi' => ['penduduk', 'warga'],
            'warga' => ['penduduk', 'populasi'],
            'upah' => ['gaji', 'pendapatan'],
            'gaji' => ['upah', 'pendapatan'],
            'pendapatan' => ['penghasilan', 'upah', 'gaji'],
            'penghasilan' => ['pendapatan', 'upah', 'gaji'],
            'pertumbuhan' => ['tumbuh', 'kenaikan'],
            'tumbuh' => ['pertumbuhan'],
            'presentase' => ['persentase'],
            'persentase' => ['presentase'],
        ];
        $variants = [$keyword, ...($synonyms[$keyword] ?? [])];
        foreach ([
            ['laki', 'pria', 'lelaki', 'cowok'],
            ['perempuan', 'wanita', 'cewek'],
        ] as $group) {
            if (in_array($keyword, $group, true)) {
                $variants = array_merge($variants, $group);
            }
        }
        foreach (['kan', 'nya', 'an', 'i'] as $suffix) {
            if (Str::endsWith($keyword, $suffix) && Str::length($keyword) - Str::length($suffix) >= 4) {
                $variants[] = Str::substr(
                    $keyword,
                    0,
                    Str::length($keyword) - Str::length($suffix)
                );
                break;
            }
        }

        return array_values(array_unique(array_filter($variants)));
    }

    private function titleTermScore(string $title, string $term, bool $allowFuzzy = true): float
    {
        $normalizedTitle = Str::lower(Str::ascii($title));
        $variants = $this->keywordVariants($term);
        foreach ($variants as $variant) {
            if ($variant !== '' && preg_match(
                '/(?<![a-z0-9])'.preg_quote($variant, '/').'(?![a-z0-9])/i',
                $normalizedTitle
            ) === 1) {
                return 1.0;
            }
        }
        if (! $allowFuzzy) {
            return 0.0;
        }

        $best = 0.0;
        $minimumScore = Str::length($term) >= 8 ? 0.78 : (Str::length($term) >= 5 ? 0.84 : 0.92);
        $titleWords = preg_split('/[^a-z0-9]+/u', $normalizedTitle, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($variants as $variant) {
            if (Str::length($variant) < 5) {
                continue;
            }
            foreach ($titleWords as $titleWord) {
                similar_text($variant, $titleWord, $percentage);
                $best = max($best, $percentage / 100);
            }
        }

        return $best >= $minimumScore ? $best : 0.0;
    }

    public function debugVariables(string $keyword): array
    {
        $apiKey = $this->apiKey();
        if ($apiKey === null) {
            return [];
        }

        return array_map(
            function (array $variable) use ($apiKey): array {
                $variableId = (int) ($variable['var_id'] ?? 0);
                $title = (string) ($variable['title'] ?? '');

                return [
                    'var_id' => $variable['var_id'] ?? null,
                    'title' => $title,
                    'level' => $this->explicitVariableLevel($title) ?? 'unknown',
                    'vertical' => $variable['vertical'] ?? null,
                    'years' => array_column($this->periodsForVariable($variableId, '1600', $apiKey), 'th'),
                ];
            },
            array_slice($this->variablesForKeyword($keyword, '1600', $apiKey), 0, 10)
        );
    }

    public function diagnoseQuestion(string $question): array
    {
        $apiKey = $this->apiKey();
        if ($apiKey === null) {
            return ['error' => 'API key WebAPI BPS belum dikonfigurasi.', 'topics' => [], 'regions' => [], 'combinations' => []];
        }

        $topics = array_slice($this->matchFallbackTopics($question), 0, 3);
        $regions = array_slice($this->regionsMentioned($question), 0, 3);
        if ($regions === []) {
            $regions = [$this->provinceFallbackRegion()];
        }

        $combinations = [];
        foreach ($topics as $topic) {
            foreach ($regions as $region) {
                $regional = ($region['kind'] ?? null) === 'region';
                $provinceOnly = $regional && $this->isProvinceOnlyFallbackTopic($topic, $question);
                $variables = $regional && ! $provinceOnly
                    ? ($topic['variables'] ?? [])
                    : ($topic['province_variables'] ?? []);
                $scored = array_map(fn (array $variable) => [
                    'variable' => $variable,
                    'score' => $this->fallbackVariableScore($variable, $question),
                    'level' => $regional && ! $provinceOnly ? 'kab_kota' : 'province',
                ], $variables);
                usort($scored, fn (array $left, array $right) => $right['score'] <=> $left['score']);
                $selected = $this->selectFallbackVariable($variables, $question);
                $diagnosticCandidates = $selected === null
                    ? array_slice($scored, 0, 3)
                    : array_values(array_filter($scored, fn (array $candidate) => $candidate['variable']['var_id'] === $selected['var_id']));
                $candidateDetails = [];
                foreach ($diagnosticCandidates as $candidate) {
                    $variable = $candidate['variable'];
                    $periods = $this->periodsForVariable((int) $variable['var_id'], '1600', $apiKey);
                    $periods = array_values(array_filter(
                        $periods,
                        fn (array $period) => (int) ($period['th'] ?? 0) <= now('Asia/Jakarta')->year
                    ));
                    $latestPeriod = $periods[0] ?? null;
                    $matchingRow = null;
                    if ($latestPeriod !== null) {
                        $data = $this->dynamicData(
                            (int) $variable['var_id'],
                            (int) ($latestPeriod['th_id'] ?? 0),
                            null,
                            '1600',
                            $apiKey,
                            (string) $variable['title'],
                            (string) $latestPeriod['th']
                        );
                        $matchingRow = $data === null
                            ? null
                            : (($candidate['level'] === 'province')
                                ? $this->provinceVervarRow($data->json('vervar'))
                                : $this->regionVervarRow($region, $data->json('vervar')));
                    }
                    $candidateDetails[] = [
                        'var_id' => $variable['var_id'],
                        'label' => $variable['label'],
                        'level' => $candidate['level'],
                        'score' => $candidate['score'],
                        'selected' => $selected !== null && $variable['var_id'] === $selected['var_id'],
                        'years' => array_column($periods, 'th'),
                        'matching_vervar' => $matchingRow,
                    ];
                }
                $combinations[] = [
                    'topic' => $topic['label'],
                    'region' => $region['label'],
                    'province_only' => $provinceOnly,
                    'variables' => $candidateDetails,
                ];
            }
        }

        return [
            'topics' => array_column($topics, 'label'),
            'regions' => array_column($regions, 'label'),
            'combinations' => $combinations,
        ];
    }

    private function fallbackVariableScore(array $variable, string $question): int
    {
        return count(array_filter(
            $variable['keywords'] ?? [],
            fn (string $keyword) => preg_match('/\b'.preg_quote($keyword, '/').'\b/iu', $question) === 1
        ));
    }

    private function periodsForVariable(int $variableId, string $domain, string $apiKey): array
    {
        $cacheKey = 'bps-webapi:variables:periods:'.$domain.':'.$variableId;
        $apiPeriods = Cache::remember($cacheKey, now()->addHours(1), function () use ($variableId, $domain, $apiKey): ?array {
            $periods = [];
            $page = 1;
            $pageCount = 1;
            do {
                $segments = ['list', 'model', 'th', 'domain', $domain, 'var', (string) $variableId];
                if ($page > 1) {
                    $segments = array_merge($segments, ['page', (string) $page]);
                }
                $segments = array_merge($segments, ['key', rawurlencode($apiKey)]);
                $url = 'https://webapi.bps.go.id/v1/api/'.implode('/', $segments).'/';
                $response = Http::timeout(8)->get($url);
                if ($response->status() === 404) {
                    return null;
                }
                if ($response->status() === 401 || $response->status() === 403) {
                    throw new RuntimeException('API key WebAPI BPS tidak diterima saat mencari periode.');
                }
                if (! $response->successful()) {
                    throw new RuntimeException('WebAPI BPS gagal memuat daftar periode.');
                }

                $payload = $response->json();
                $items = $payload['data'][1] ?? null;
                if (($payload['status'] ?? null) !== 'OK' || ! is_array($items)) {
                    throw new RuntimeException('Format daftar periode WebAPI BPS tidak dikenali.');
                }
                $pageCount = max(1, (int) ($payload['data'][0]['pages'] ?? 1));
                if ($pageCount > 100) {
                    throw new RuntimeException('Jumlah halaman periode WebAPI BPS di luar batas yang diizinkan.');
                }
                foreach ($items as $period) {
                    if (! is_array($period)
                        || ! ctype_digit((string) ($period['th'] ?? ''))
                        || ! ctype_digit((string) ($period['th_id'] ?? ''))) {
                        continue;
                    }
                    $periods[(string) $period['th_id']] = [
                        'th_id' => (string) $period['th_id'],
                        'th' => (string) $period['th'],
                    ];
                }
                $page++;
            } while ($page <= $pageCount);

            $periods = array_values($periods);
            usort($periods, fn (array $left, array $right) => (int) $right['th'] <=> (int) $left['th']);

            return $periods;
        });
        if (is_array($apiPeriods)) {
            return $apiPeriods;
        }

        $periods = [];
        foreach ($this->csvCatalogRows() as $row) {
            if (($row['jenis_tabel'] ?? '') !== 'Tabel Dinamis'
                || (string) ($row['id_tabel'] ?? '') !== (string) $variableId
                || ! str_contains((string) ($row['link_json'] ?? ''), '/domain/'.$domain.'/')
                || ! preg_match('~/th/(\d+)/~', (string) ($row['link_json'] ?? ''), $match)
                || ! ctype_digit((string) ($row['tahun'] ?? ''))) {
                continue;
            }
            $periods[] = ['th_id' => $match[1], 'th' => $row['tahun']];
        }
        usort($periods, fn (array $left, array $right) => (int) $right['th'] <=> (int) $left['th']);

        return $periods;
    }

    private function canonicalPeriods(int $variableId, string $apiKey): array
    {
        return $this->periodsForVariable($variableId, '1600', $apiKey);
    }

    private function canonicalDataForPeriod(
        int $variableId,
        int $periodId,
        string $apiKey,
        ?string $title = null,
        ?string $year = null
    ): ?\Illuminate\Http\Client\Response
    {
        return $this->dynamicData($variableId, $periodId, null, '1600', $apiKey, $title, $year);
    }

    private function domainFor(string $question): string
    {
        return '1600';
    }

    private function derivedPeriodsForVariable(int $variableId, string $domain, string $apiKey): array
    {
        unset($variableId, $domain, $apiKey);

        return [];
    }

    private function dynamicData(
        int $variableId,
        int $periodId,
        mixed $derivedPeriodId,
        string $domain,
        string $apiKey,
        ?string $title = null,
        ?string $year = null
    ): ?\Illuminate\Http\Client\Response
    {
        unset($derivedPeriodId);
        $row = $this->catalogRowForPeriod($variableId, $periodId, $domain);
        if ($row === null) {
            $period = collect($this->periodsForVariable($variableId, $domain, $apiKey))
                ->first(fn (array $item) => (int) ($item['th_id'] ?? 0) === $periodId);
            $year ??= is_array($period) ? (string) ($period['th'] ?? '') : null;
            if ($year === null || $year === '') {
                return null;
            }
            $row = [
                'id_tabel' => (string) $variableId,
                'jenis_tabel' => 'Tabel Dinamis',
                'tahun' => $year,
                'judul' => $title ?? 'Variabel BPS '.$variableId,
                'link_json' => 'https://webapi.bps.go.id/v1/api/list/model/data/domain/'
                    .$domain.'/var/'.$variableId.'/th/'.$periodId.'/key/',
            ];
        }

        return $this->catalogResponse($row, $apiKey);
    }

    private function formatDynamicContext(
        array $variable,
        \Illuminate\Http\Client\Response $response,
        string $year,
        ?string $quarter,
        ?array $regionRow,
        array $values,
        bool $subregionalFallback = false
    ): string {
        $payload = [
            'indikator' => $response->json('var.0.label', $variable['title']),
            'definisi' => $response->json('var.0.def'),
            'satuan' => $response->json('var.0.unit'),
            'sifat_data' => $variable['sifat_data']
                ?? (preg_match('/\bproyeksi\b/iu', (string) ($variable['title'] ?? '')) === 1 ? 'proyeksi' : null),
            'catatan_sumber' => $variable['catatan_sumber'] ?? null,
            'subjek' => $variable['subject'] ?? null,
            'level' => $this->explicitVariableLevel((string) ($variable['title'] ?? '')) ?? 'unknown',
            'wilayah' => $regionRow === null ? $response->json('vervar') : [$regionRow],
            'kategori' => $response->json('turvar'),
            'vervar_label' => $variable['vervar_label'] ?? null,
            'wilayah_cakupan' => $variable['wilayah_cakupan'] ?? null,
            'catatan_data' => $response->json('_catatan'),
            'tahun' => $year,
            'periode' => $quarter ?? 'Tahunan',
            'var_id' => $response->json('var.0.val', $variable['var_id']),
            'nilai' => $values,
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $context = '[Sumber: WebAPI BPS Sumatera Selatan, '.$variable['title'].'] '
            .Str::limit($json ?: '', $subregionalFallback ? 50000 : 7000, '...');

        return $subregionalFallback
            ? '[WebAPI BPS][CAKUPAN_KAB_KOTA] '.$context
            : $context;
    }

    private function requestedQuarter(string $question): ?int
    {
        if (! preg_match('/\b(?:q|triwulan)\s*(1|2|3|4|i|ii|iii|iv)\b/i', $question, $match)) {
            return null;
        }

        return $this->quarterNumber($match[1]);
    }

    private function quarterNumber(string $label): ?int
    {
        if (! preg_match('/(?:triwulan\s*)?(IV|III|II|I|[1-4])\b/i', $label, $match)) {
            return null;
        }

        $value = Str::lower($match[1]);

        return match ($value) {
            '1', 'i' => 1,
            '2', 'ii' => 2,
            '3', 'iii' => 3,
            '4', 'iv' => 4,
            default => null,
        };
    }

    private function apiKey(): ?string
    {
        $configuredKey = config('services.bps.api_key');

        return is_string($configuredKey) && trim($configuredKey) !== ''
            ? trim($configuredKey)
            : null;
    }

    private function csvCatalogRows(): array
    {
        $path = (string) config('services.bps.catalog_csv', base_path('tabel_bps_sumsel.csv'));
        if (! is_file($path)) {
            throw new RuntimeException('File katalog CSV BPS tidak ditemukan.');
        }
        $signature = (string) filemtime($path).':'.(string) filesize($path);
        $cacheKey = 'bps-csv-catalog:'.sha1($path.':'.$signature);

        return Cache::remember($cacheKey, now()->addMinutes(10), function () use ($path): array {
            $rows = $this->readCatalogCsv($path, [
                'kategori_subjek', 'subjek', 'id_subjek', 'judul', 'id_tabel',
                'jenis_tabel', 'tahun', 'link_json',
            ]);

            $rows = array_map(
                static fn (array $row) => array_map(
                    static fn ($value) => trim((string) ($value ?? '')),
                    $row
                ),
                $rows
            );

            return array_values(array_filter(
                $rows,
                static fn (array $row) => ($row['judul'] ?? '') !== ''
                    && ($row['link_json'] ?? '') !== ''
                    && ($row['link_json'] ?? '') !== 'TAHUN_TIDAK_TERSEDIA'
            ));
        });
    }

    private function catalogRowForTable(
        int|string $tableId,
        ?string $year = null,
        string $type = 'Tabel Dinamis',
        string $domain = '1600'
    ): ?array {
        foreach ($this->csvCatalogRows() as $row) {
            if ((string) ($row['id_tabel'] ?? '') !== (string) $tableId
                || ($row['jenis_tabel'] ?? '') !== $type) {
                continue;
            }
            if ($year !== null && (string) ($row['tahun'] ?? '') !== $year) {
                continue;
            }
            if ($type !== 'SIMDASI'
                && ! str_contains((string) ($row['link_json'] ?? ''), '/domain/'.$domain.'/')) {
                continue;
            }

            return $row;
        }

        return null;
    }

    private function catalogRowForPeriod(int $variableId, int $periodId, string $domain): ?array
    {
        foreach ($this->csvCatalogRows() as $row) {
            if (($row['jenis_tabel'] ?? '') !== 'Tabel Dinamis'
                || (string) ($row['id_tabel'] ?? '') !== (string) $variableId
                || ! str_contains((string) ($row['link_json'] ?? ''), '/domain/'.$domain.'/')
                || ! preg_match('~/th/(\d+)/~', (string) ($row['link_json'] ?? ''), $match)
                || (int) $match[1] !== $periodId) {
                continue;
            }

            return $row;
        }

        return null;
    }

    private function catalogResponse(array $row, string $apiKey): \Illuminate\Http\Client\Response
    {
        $link = trim((string) ($row['link_json'] ?? ''));
        $parts = parse_url($link);
        $path = is_array($parts) ? (string) ($parts['path'] ?? '') : '';
        if (! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || ($parts['host'] ?? null) !== 'webapi.bps.go.id'
            || ! str_starts_with($path, '/v1/api/')
            || ! str_ends_with(rtrim($path, '/'), '/key')
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            throw new RuntimeException('Format tautan pada katalog CSV BPS tidak valid.');
        }

        $tableId = trim((string) ($row['id_tabel'] ?? ''));
        $year = trim((string) ($row['tahun'] ?? ''));
        if ($tableId === '') {
            throw new RuntimeException('ID tabel pada katalog CSV BPS tidak valid.');
        }
        $database = $this->bpsDatabase();
        $cached = $database->table('data_tabel')
            ->where('id_tabel', $tableId)
            ->where('tahun', $year)
            ->first();
        $cachedPayload = null;
        if ($cached !== null) {
            $decoded = json_decode((string) $cached->isi_json, true);
            if (is_array($decoded)) {
                $cachedPayload = $decoded;
                if (time() - (float) $cached->diambil_pada < $this->tableFreshnessSeconds($row)) {
                    return $this->jsonResponseFromPayload($cachedPayload);
                }
            }
        }

        try {
            if (trim($apiKey) === '') {
                throw new RuntimeException('API key BPS belum dikonfigurasi.');
            }
            $url = rtrim($link, '/').'/'.rawurlencode($apiKey).'/';
            try {
                $response = Http::timeout(8)->get($url);
            } catch (ConnectionException) {
                throw new RuntimeException('WebAPI BPS tidak dapat diakses saat ini.');
            }
            $json = $response->json();
            if (strtolower((string) $response->header('data-availability')) === 'list-not-available') {
                $payload = ['status' => 'OK', 'data' => [['pages' => 1], []]];
            } elseif ($response->status() === 401 || $response->status() === 403) {
                throw new RuntimeException('API key BPS tidak diterima.');
            } elseif (! $response->successful() || ! is_array($json) || ($json['status'] ?? null) !== 'OK') {
                throw new RuntimeException('WebAPI BPS tidak dapat mengambil data pada tautan katalog.');
            } else {
                $payload = $json;
            }
        } catch (ConnectionException|RuntimeException $exception) {
            if ($cachedPayload === null) {
                throw $exception;
            }
            error_log('BPS MySQL stale fallback for table '.$tableId.' year '.$year.': '.get_class($exception));
            $cachedPayload['_catatan'] = (
                'Data berasal dari penyimpanan sebelumnya karena WebAPI BPS belum dapat '
                .'memperbaruinya; data mungkin bukan yang terbaru.'
            );

            return $this->jsonResponseFromPayload($cachedPayload);
        }

        $database->transaction(function () use ($database, $row, $tableId, $year, $link, $payload): void {
            $database->table('data_tabel')->upsert([[
                'id_tabel' => $tableId,
                'jenis_tabel' => (string) ($row['jenis_tabel'] ?? ''),
                'tahun' => $year,
                'judul' => (string) ($row['judul'] ?? ''),
                'link_json' => $link,
                'isi_json' => json_encode(
                    $payload,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                ),
                'diambil_pada' => time(),
            ]], ['id_tabel', 'tahun'], [
                'jenis_tabel', 'judul', 'link_json', 'isi_json', 'diambil_pada',
            ]);
            if (($row['jenis_tabel'] ?? '') === 'Tabel Dinamis') {
                $this->storeDynamicTableValues($database, $payload, $tableId);
            }
        });

        return $this->jsonResponseFromPayload($payload);
    }

    private function jsonResponseFromPayload(array $payload): \Illuminate\Http\Client\Response
    {
        return new \Illuminate\Http\Client\Response(
            new \GuzzleHttp\Psr7\Response(
                200,
                ['Content-Type' => 'application/json'],
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            )
        );
    }

    private function bpsDatabase(): \Illuminate\Database\Connection
    {
        return DB::connection();
    }

    private function tableFreshnessSeconds(array $row): int
    {
        if (($row['jenis_tabel'] ?? '') === 'Tabel Statis') {
            return 30 * 86400;
        }
        $year = (int) substr((string) ($row['tahun'] ?? ''), 0, 4);
        $currentYear = now('Asia/Jakarta')->year;

        return $year >= $currentYear - 1 ? 7 * 86400 : 90 * 86400;
    }

    private function storeDynamicTableValues(
        \Illuminate\Database\Connection $database,
        array $payload,
        string $tableId
    ): void
    {
        $variable = $payload['var'][0] ?? null;
        $content = $payload['datacontent'] ?? null;
        if (! is_array($variable) || ! isset($variable['val']) || ! is_array($content)) {
            error_log('Dynamic BPS table '.$tableId.' has an unsupported response structure.');

            return;
        }

        $dimensions = static function ($items, string $defaultLabel = ''): array {
            if (! is_array($items) || $items === []) {
                return [['val' => '0', 'label' => $defaultLabel]];
            }
            $rows = [];
            foreach ($items as $item) {
                if (is_array($item) && array_key_exists('val', $item)) {
                    $rows[] = [
                        'val' => (string) $item['val'],
                        'label' => trim((string) ($item['label'] ?? '')),
                    ];
                }
            }

            return $rows !== [] ? $rows : [['val' => '0', 'label' => $defaultLabel]];
        };
        $regions = $dimensions($payload['vervar'] ?? null);
        $categories = $dimensions($payload['turvar'] ?? null);
        $years = $dimensions($payload['tahun'] ?? null);
        $subyears = $dimensions($payload['turtahun'] ?? $payload['turth'] ?? null);
        $keyMatches = [];
        foreach ($regions as $region) {
            foreach ($categories as $category) {
                foreach ($years as $year) {
                    foreach ($subyears as $subyear) {
                        $key = $region['val'].$tableId.$category['val'].$year['val'].$subyear['val'];
                        $keyMatches[$key][] = [$region, $category, $year, $subyear];
                    }
                }
            }
        }

        $note = html_entity_decode(strip_tags((string) ($variable['note'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $database->table('tabel_meta')->upsert([[
            'id_tabel' => $tableId,
            'judul' => (string) ($variable['label'] ?? $variable['title'] ?? ''),
            'satuan' => (string) ($variable['unit'] ?? ''),
            'subjek' => (string) ($variable['subj'] ?? ''),
            'label_wilayah' => (string) ($payload['labelvervar'] ?? ''),
            'catatan' => trim(preg_replace('/\s+/', ' ', $note) ?? ''),
            'last_update_bps' => (string) ($payload['last_update'] ?? ''),
            'diambil_pada' => time(),
        ]], ['id_tabel'], [
            'judul', 'satuan', 'subjek', 'label_wilayah', 'catatan', 'last_update_bps', 'diambil_pada',
        ]);
        $values = [];
        $decodedKeys = [];
        foreach ($content as $key => $value) {
            $matches = $keyMatches[(string) $key] ?? [];
            if (count($matches) !== 1) {
                continue;
            }
            [$region, $category, $year, $subyear] = $matches[0];
            $values[] = [
                'id_tabel' => $tableId,
                'wilayah_id' => $region['val'],
                'wilayah' => $region['label'],
                'turvar_id' => $category['val'],
                'turvar' => $category['label'],
                'tahun' => $year['label'] !== '' ? $year['label'] : $year['val'],
                'turtahun' => $subyear['label'] !== '' ? $subyear['label'] : $subyear['val'],
                'nilai' => is_numeric($value) ? (float) $value : null,
            ];
            $decodedKeys[(string) $key] = true;
        }
        if ($values !== []) {
            $database->table('nilai_data')->upsert(
                $values,
                ['id_tabel', 'wilayah_id', 'turvar_id', 'tahun', 'turtahun'],
                ['wilayah', 'turvar', 'nilai']
            );
        }
        $unreadable = count($content) - count($decodedKeys);
        if ($unreadable > 0) {
            error_log('Dynamic BPS table '.$tableId.' has '.$unreadable.' unreadable datacontent key(s).');
        }
    }

    private function searchTables(string $keyword, string $domain, string $apiKey): array
    {
        unset($apiKey);
        $rows = array_filter(
            $this->csvCatalogRows(),
            function (array $row) use ($keyword, $domain): bool {
                $type = (string) ($row['jenis_tabel'] ?? '');
                $link = (string) ($row['link_json'] ?? '');
                $inDomain = $type === 'SIMDASI'
                    ? $domain === '1600' && str_contains($link, '/wilayah/1600000/')
                    : $type === 'Tabel Statis' && str_contains($link, '/domain/'.$domain.'/');

                return $inDomain
                    && $this->titleTermScore(
                        (string) ($row['judul'] ?? ''),
                        $keyword,
                        false
                    ) > 0;
            }
        );

        $tables = [];
        foreach ($rows as $row) {
            $tableId = (string) ($row['id_tabel'] ?? '');
            $year = (string) ($row['tahun'] ?? '');
            if ($tableId === '') {
                continue;
            }
            $table = [
                'table_id' => $row['id_tabel'],
                'title' => $row['judul'],
                'subject' => $row['subjek'],
                'link_json' => $row['link_json'],
                'type' => $row['jenis_tabel'],
                'year' => $year,
            ];
            $existingYear = (string) ($tables[$tableId]['year'] ?? '');
            if (! isset($tables[$tableId]) || (int) $year > (int) $existingYear) {
                $tables[$tableId] = $table;
            }
        }

        return array_values($tables);
    }

    private function matchingTables(
        array $keywords,
        string $domain,
        string $apiKey,
        string $question = ''
    ): array
    {
        $coreKeywords = $this->coreTableKeywords($keywords);
        $mainKeyword = $this->mainKeyword($coreKeywords);
        if ($mainKeyword === null) {
            return [];
        }

        $matches = [];
        foreach (array_slice($coreKeywords, 0, 4) as $keyword) {
            foreach ($this->searchTables($keyword, $domain, $apiKey) as $table) {
                $tableId = $table['table_id'] ?? null;
                $title = $table['title'] ?? null;
                if (! is_scalar($tableId) || ! is_string($title)) {
                    continue;
                }

                $searchText = $title;
                if (in_array('gaji', $coreKeywords, true)
                    && preg_match('/\b(?:upah|gaji|balas jasa|pekerja|buruh|pegawai|karyawan)\b/i', strip_tags($title)) !== 1) {
                    continue;
                }
                $score = 0.0;
                $matchedCoreTerms = 0;
                foreach ($coreKeywords as $term) {
                    $termScore = $this->titleTermScore($searchText, $term, false);
                    if ($termScore === 0.0) {
                        $termScore = $this->titleTermScore($searchText, $term) * 0.45;
                    }
                    if ($termScore > 0.0) {
                        $matchedCoreTerms++;
                        $score += min(Str::length($term), 10) * $termScore;
                    }
                }
                if ($score === 0.0 || $matchedCoreTerms === 0) {
                    continue;
                }
                if (preg_match(
                    '/(?<![\pL\pN])'.preg_quote($mainKeyword, '/').'(?![\pL\pN])/iu',
                    $title
                ) === 1) {
                    $score += 4;
                }
                $matches[(string) $tableId] = [
                    'table' => $table,
                    'score' => $score,
                    'matched_core_terms' => $matchedCoreTerms,
                ];
            }
        }

        uasort($matches, fn (array $left, array $right) => $right['score'] <=> $left['score']);
        $ranked = array_values($matches);
        $best = $ranked[0] ?? null;
        $runnerUp = $ranked[1] ?? null;
        $requiredScore = count($coreKeywords) > 1
            ? 6
            : max(2.5, Str::length($mainKeyword) * 0.65);
        if ($best === null || $best['score'] < $requiredScore) {
            return [];
        }
        $selected = [$best];
        if ($runnerUp !== null
            && $best['score'] - $runnerUp['score'] <= 5
            && $runnerUp['matched_core_terms'] === $best['matched_core_terms']) {
            $selected = array_values(array_filter(
                array_slice($ranked, 0, 3),
                fn (array $match) => $best['score'] - $match['score'] <= 5
                    && $match['matched_core_terms'] === $best['matched_core_terms']
            ));
        }
        if (! preg_match('/\b(?:19|20)\d{2}\b/', $question)) {
            $years = array_values(array_filter(
                array_map(static fn (array $match) => (int) ($match['table']['year'] ?? 0), $selected),
                static fn (int $year) => $year > 0
            ));
            if ($years !== []) {
                $latestYear = max($years);
                $selected = array_values(array_filter(
                    $selected,
                    static fn (array $match) => (int) ($match['table']['year'] ?? 0) === 0
                        || (int) $match['table']['year'] >= $latestYear - 1
                ));
            }
        }

        $allCoreTermsMatched = $best['matched_core_terms'] >= count($coreKeywords);
        foreach ($selected as &$match) {
            $match['table']['related_match'] = ! $allCoreTermsMatched
                || $this->hasUnrequestedTableScope($match['table']['title'], $question);
        }
        unset($match);

        return array_column($selected, 'table');
    }

    private function coreTableKeywords(array $keywords): array
    {
        $synonymGroups = [
            'gaji' => ['gaji', 'upah', 'penghasilan', 'pendapatan'],
            'pekerja' => ['pekerja', 'buruh', 'karyawan', 'pegawai'],
            'miskin' => ['miskin', 'kemiskinan'],
            'penduduk' => ['penduduk', 'populasi', 'warga', 'masyarakat', 'rakyat'],
        ];
        $canonical = [];
        foreach ($synonymGroups as $term => $members) {
            foreach ($members as $member) {
                $canonical[$member] = $term;
            }
        }

        $core = [];
        foreach ($keywords as $keyword) {
            $keyword = Str::lower(trim((string) $keyword));
            if ($keyword === '' || in_array($keyword, self::GENERIC_TERMS, true)) {
                continue;
            }
            $term = $canonical[$keyword] ?? $keyword;
            if (! in_array($term, $core, true)) {
                $core[] = $term;
            }
        }

        return $core;
    }

    private function hasUnrequestedTableScope(string $title, string $question): bool
    {
        $normalizedTitle = Str::lower(Str::ascii(strip_tags($title)));
        $normalizedQuestion = Str::lower(Str::ascii($question));
        foreach ([
            'pekerja informal',
            'pekerja formal',
            'informal',
            'formal',
            'pendidikan tertinggi',
            'lapangan pekerjaan',
            'lapangan usaha',
            'usaha konstruksi',
        ] as $scope) {
            if (str_contains($normalizedTitle, $scope)
                && ! str_contains($normalizedQuestion, $scope)) {
                return true;
            }
        }

        return false;
    }

    private function tablesNeedConfirmation(array $tables, string $question): bool
    {
        if (count($tables) > 1) {
            return true;
        }

        return ! empty($tables[0]['related_match'])
            || ($tables !== [] && $this->hasUnrequestedTableScope(
                (string) ($tables[0]['title'] ?? ''),
                $question
            ));
    }

    private function relatedTableSummary(array $table): string
    {
        $summary = [];
        if (! empty($table['year'])) {
            $summary[] = 'Tahun tersedia: '.$table['year'].'.';
        }
        if (! empty($table['subject'])) {
            $summary[] = 'Subjek: '.$table['subject'].'.';
        }

        return implode(' ', $summary);
    }

    private function formatRelatedTableAnswer(array $tables): string
    {
        $lines = [
            'Saya belum menemukan kecocokan persis untuk pertanyaan tersebut. Ada beberapa tabel BPS Sumatera Selatan yang topiknya berkaitan:',
        ];
        foreach ($tables as $index => $table) {
            $title = html_entity_decode(
                strip_tags((string) ($table['title'] ?? 'Tabel BPS')),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            );
            $year = trim((string) ($table['year'] ?? ''));
            $lines[] = ($index + 1).'. '.$title.($year !== '' ? ' (tahun '.$year.')' : '');
        }
        $lines[] = 'Apakah salah satu tabel tersebut yang Anda cari? Jika ya, sebutkan judul atau rinciannya.';

        return implode("\n", $lines);
    }

    private function staticTableContext(
        array $keywords,
        string $domain,
        string $apiKey,
        string $question = ''
    ): ?string
    {
        $tables = $this->matchingTables($keywords, $domain, $apiKey, $question);
        $tableDomain = $domain;
        if ($tables === [] && $domain !== '0000') {
            $tableDomain = '0000';
            $tables = $this->matchingTables($keywords, $tableDomain, $apiKey, $question);
        }

        $context = [];
        foreach ($tables as $table) {
            $tableId = $table['table_id'] ?? null;
            if (! is_scalar($tableId) || ! isset($table['title'])) {
                continue;
            }
            $detail = ! empty($table['related_match'])
                ? $this->relatedTableSummary($table)
                : $this->tableDetail(
                    (string) $tableId,
                    $tableDomain,
                    $apiKey,
                    (string) ($table['type'] ?? 'Tabel Statis'),
                    (string) ($table['year'] ?? '')
                );
            if ($detail !== null) {
                $scope = $tableDomain === '0000' ? 'domain nasional' : 'domain Sumatera Selatan';
                $context[] = '[Sumber: BPS WebAPI, '.$scope.', '
                    .html_entity_decode(strip_tags((string) $table['title']), ENT_QUOTES | ENT_HTML5, 'UTF-8')
                    .'] '.$detail;
            }
        }

        if ($context === []) {
            return null;
        }
        if ($this->tablesNeedConfirmation($tables, $question)) {
            return "[WebAPI BPS][KANDIDAT_TOPIK_TERKAIT]\n"
                ."Topik yang diminta belum memiliki kecocokan yang persis. Tabel berikut "
                ."hanya kandidat terkait; jangan menyajikan nilainya sebagai jawaban final. "
                ."Tanyakan dengan sopan apakah salah satu tabel berikut yang dimaksud:\n\n"
                .implode("\n\n", $context);
        }

        return implode("\n\n", $context);
    }

    private function isStaticWageQuestion(string $question): bool
    {
        return preg_match('/\b(upah|gaji|ump|umk|umr)\b/i', $question) === 1;
    }

    private function mainKeyword(array $keywords): ?string
    {
        foreach ($keywords as $keyword) {
            if (! in_array($keyword, self::GENERIC_TERMS, true)) {
                return $keyword;
            }
        }

        return null;
    }

    private function tableDetail(
        string $tableId,
        string $domain,
        string $apiKey,
        string $type = 'Tabel Statis',
        string $year = ''
    ): ?string
    {
        $row = $this->catalogRowForTable(
            $tableId,
            $year !== '' ? $year : null,
            $type,
            $domain
        );
        if ($row === null) {
            return null;
        }
        $response = $this->catalogResponse($row, $apiKey);

        if ($type === 'SIMDASI') {
            return $this->formatSimdasiTable($response->json('data.1'));
        }

        $table = $response->json('data.table');
        if (! is_string($table) || trim($table) === '') {
            return null;
        }

        $text = $this->formatStaticTableHtml($table);

        $stalenessNote = $response->json('_catatan');
        if (is_string($stalenessNote) && trim($stalenessNote) !== '') {
            $text .= ' '.$stalenessNote;
        }

        return Str::limit($text, 5000, '...');
    }

    private function formatStaticTableHtml(string $table): string
    {
        $previousErrorMode = libxml_use_internal_errors(true);
        $document = new \DOMDocument('1.0', 'UTF-8');
        try {
            $loaded = $document->loadHTML(
                '<?xml encoding="UTF-8">'.$table,
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorMode);
        }

        if (! $loaded) {
            $text = html_entity_decode($table, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = trim(preg_replace('/\s+/', ' ', strip_tags($text)) ?? '');

            return Str::limit($text, 2500, '...');
        }

        $rows = [];
        $truncated = false;
        foreach ($document->getElementsByTagName('tr') as $row) {
            $cells = [];
            foreach ($row->childNodes as $cell) {
                if (! $cell instanceof \DOMElement
                    || ! in_array(strtolower($cell->tagName), ['td', 'th'], true)) {
                    continue;
                }

                $cells[] = trim(preg_replace('/\s+/', ' ', $cell->textContent) ?? $cell->textContent);
            }
            $cells = array_values(array_filter($cells, static fn (string $value) => $value !== ''));
            if ($cells === []) {
                continue;
            }
            if (count($rows) >= 25) {
                $truncated = true;
                break;
            }

            $rows[] = '| '.implode(' | ', $cells).' |';
        }

        if ($rows === []) {
            $text = html_entity_decode($table, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = trim(preg_replace('/\s+/', ' ', strip_tags($text)) ?? '');

            return Str::limit($text, 2500, '...');
        }

        $text = Str::limit(implode("\n", $rows), 2300, '...');
        if ($truncated) {
            $text .= "\n[Data tabel dipotong setelah 25 baris.]";
        }

        return $text;
    }

    private function formatSimdasiTable(mixed $table): ?string
    {
        if (! is_array($table) || ! is_array($table['kolom'] ?? null) || ! is_array($table['data'] ?? null)) {
            return null;
        }

        $columns = [];
        foreach ($table['kolom'] as $key => $column) {
            if (! is_array($column)) {
                continue;
            }
            $label = html_entity_decode(
                strip_tags((string) ($column['nama_variabel'] ?? $column['label'] ?? $key)),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            );
            if (preg_match('/\s+-\s*(.+)$/u', $label, $match) === 1) {
                $label = trim($match[1]);
            } else {
                $label = trim($label);
            }
            $columns[(string) $key] = $label;
        }
        if ($columns === []) {
            return null;
        }

        $headers = ['Wilayah', ...array_values($columns)];
        $lines = ['| '.implode(' | ', $headers).' |'];
        $lines[] = '| '.implode(' | ', array_fill(0, count($headers), '---')).' |';
        $truncated = false;
        foreach ($table['data'] as $row) {
            if (! is_array($row) || ! is_array($row['variables'] ?? null)) {
                continue;
            }
            if (count($lines) >= 27) {
                $truncated = true;
                break;
            }
            $cells = [trim((string) ($row['label'] ?? ''))];
            foreach ($columns as $key => $_label) {
                $value = $row['variables'][$key]['value'] ?? '';
                $cells[] = is_scalar($value) ? trim((string) $value) : '';
            }
            $cells = array_map(
                static fn (string $cell) => str_replace('|', '\\|', $cell),
                $cells
            );
            $lines[] = '| '.implode(' | ', $cells).' |';
        }
        if (count($lines) <= 2) {
            return null;
        }
        if ($truncated) {
            $lines[] = '[Data tabel dipotong setelah 25 baris.]';
        }

        return Str::limit(implode("\n", $lines), 4500, '...');
    }

    private function keywords(string $question): array
    {
        $normalizedQuestion = Str::lower(Str::ascii($question));
        $phraseSynonyms = [
            'rumah sakit umum' => 'fasilitas kesehatan',
            'rumah sakit' => 'fasilitas kesehatan',
            'rsud' => 'fasilitas kesehatan',
            'faskes' => 'fasilitas kesehatan',
            'gender' => 'jenis kelamin',
            'sex' => 'jenis kelamin',
        ];
        uksort($phraseSynonyms, fn (string $left, string $right) => Str::length($right) <=> Str::length($left));
        foreach ($phraseSynonyms as $alias => $canonical) {
            $normalizedQuestion = preg_replace(
                '/(?<![a-z0-9])'.preg_quote($alias, '/').'(?![a-z0-9])/u',
                $canonical,
                $normalizedQuestion
            ) ?? $normalizedQuestion;
        }
        preg_match_all('/[\pL\pN]{3,}/u', $normalizedQuestion, $matches);
        $stopWords = ['yang', 'dan', 'atau', 'untuk', 'dari', 'dengan', 'pada', 'dalam', 'adalah', 'berapa', 'bagaimana', 'apa', 'data', 'saya', 'kami', 'bisa', 'tolong', 'menurut', 'per', 'tahun', 'sumatera', 'selatan', 'sumsel', 'provinsi', 'indonesia', 'terbaru', 'terkini', 'terakhir', 'sekarang', 'baru', 'ada', 'ini', 'itu', 'bulan', 'lalu', 'sih', 'dong', 'nih'];
        $region = $this->regionMentioned($question);
        if ($region !== null) {
            $regionWords = ['kabupaten', 'kab', 'kota'];
            foreach ($region['aliases'] as $alias) {
                $regionWords = array_merge($regionWords, preg_split('/\s+/u', Str::lower($alias)) ?: []);
            }
            $stopWords = array_merge($stopWords, $regionWords);
        }
        $synonyms = [
            'total' => 'jumlah',
            'masyarakat' => 'penduduk',
            'rakyat' => 'penduduk',
            'laju' => 'pertumbuhan',
        ];
        $keywords = array_diff($matches[0] ?? [], $stopWords);
        $expanded = [];
        foreach ($keywords as $keyword) {
            if (in_array($keyword, ['ump', 'umk', 'umr'], true)) {
                $expanded = array_merge($expanded, [$keyword, 'upah', 'minimum']);
                continue;
            }
            $normalized = $synonyms[$keyword] ?? $keyword;
            $expanded = array_merge($expanded, $this->keywordVariants($normalized));
        }
        $maleTerms = ['laki', 'pria', 'lelaki', 'cowok'];
        $femaleTerms = ['perempuan', 'wanita', 'cewek'];
        $genderPair = array_intersect($keywords, $maleTerms) !== []
            && array_intersect($keywords, $femaleTerms) !== [];
        if ($genderPair) {
            $otherTerms = array_values(array_diff($keywords, [...$maleTerms, ...$femaleTerms]));
            if ($otherTerms === []) {
                $expanded = array_merge($expanded, ['jumlah', 'penduduk']);
            } elseif (in_array('penduduk', $otherTerms, true)) {
                $expanded[] = 'jumlah';
            }
            $expanded[] = 'jenis kelamin';
        }

        return array_values(array_unique($expanded));
    }

    private function requiredVariableTerms(string $question): array
    {
        $normalizedQuestion = Str::lower(Str::ascii($question));
        foreach ([
            'rumah sakit umum' => 'fasilitas kesehatan',
            'rumah sakit' => 'fasilitas kesehatan',
            'rsud' => 'fasilitas kesehatan',
            'faskes' => 'fasilitas kesehatan',
            'gender' => 'jenis kelamin',
            'sex' => 'jenis kelamin',
        ] as $alias => $canonical) {
            $normalizedQuestion = preg_replace(
                '/(?<![a-z0-9])'.preg_quote($alias, '/').'(?![a-z0-9])/u',
                $canonical,
                $normalizedQuestion
            ) ?? $normalizedQuestion;
        }
        foreach ([
            'total' => 'jumlah',
            'masyarakat' => 'penduduk',
            'rakyat' => 'penduduk',
            'laju' => 'pertumbuhan',
            'kemiskinan' => 'miskin',
            'ekonomi' => 'pdrb',
            'ipm' => 'indeks pembangunan manusia',
            'rls' => 'rata-rata lama sekolah',
            'hls' => 'harapan lama sekolah',
            'laki' => 'kelamin',
            'pria' => 'kelamin',
            'lelaki' => 'kelamin',
            'cowok' => 'kelamin',
            'perempuan' => 'kelamin',
            'wanita' => 'kelamin',
            'cewek' => 'kelamin',
        ] as $alias => $canonical) {
            $normalizedQuestion = preg_replace(
                '/(?<![a-z0-9])'.preg_quote($alias, '/').'(?![a-z0-9])/u',
                $canonical,
                $normalizedQuestion
            ) ?? $normalizedQuestion;
        }
        foreach ($this->regionsMentionedIn($question) as $region) {
            foreach ($region['aliases'] as $alias) {
                $normalizedQuestion = preg_replace(
                    '/(?<![a-z0-9])'.preg_quote(Str::lower(Str::ascii($alias)), '/').'(?![a-z0-9])/u',
                    ' ',
                    $normalizedQuestion
                ) ?? $normalizedQuestion;
            }
        }

        preg_match_all('/[\pL\pN]{3,}/u', $normalizedQuestion, $matches);
        $stopWords = [
            'yang', 'dan', 'atau', 'untuk', 'dari', 'dengan', 'pada', 'dalam', 'adalah',
            'berapa', 'bagaimana', 'apa', 'data', 'saya', 'kami', 'bisa', 'tolong',
            'menurut', 'per', 'tahun', 'sumatera', 'selatan', 'sumsel', 'provinsi',
            'indonesia', 'terbaru', 'terkini', 'terakhir', 'sekarang', 'baru', 'ada',
            'ini', 'itu', 'bulan', 'lalu', 'sih', 'dong', 'nih', 'kabupaten', 'kab',
            'kota',
        ];

        return array_values(array_unique(array_filter(
            $matches[0] ?? [],
            fn (string $term) => ! in_array($term, $stopWords, true) && ! ctype_digit($term)
        )));
    }
}
