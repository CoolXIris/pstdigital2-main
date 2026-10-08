<?php

namespace App\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class BpsWebApiService
{
    private const GENERIC_TERMS = ['jumlah', 'persentase', 'angka', 'nilai', 'tingkat', 'banyak', 'indeks', 'pembangunan', 'manusia'];

    private const VARIABLE_INDEX_CACHE_PREFIX = 'bps-webapi:variables:index:';

    private const SETTING_KEY = 'bps_webapi_key';

    public function hasApiKey(): bool
    {
        return DB::table('chatbot_settings')->where('key', self::SETTING_KEY)->exists();
    }

    public function saveApiKey(string $apiKey): void
    {
        DB::table('chatbot_settings')->updateOrInsert(
            ['key' => self::SETTING_KEY],
            ['value' => Crypt::encryptString(trim($apiKey)), 'updated_at' => now(), 'created_at' => now()]
        );
    }

    public function clearApiKey(): void
    {
        DB::table('chatbot_settings')->where('key', self::SETTING_KEY)->delete();
    }

    public function refreshVariableIndex(): array
    {
        $apiKey = $this->apiKey();
        if ($apiKey === null) {
            throw new RuntimeException('API key WebAPI BPS belum dikonfigurasi.');
        }

        $subjects = $this->listModelItems('subject', '1600', $apiKey);
        if ($subjects === []) {
            throw new RuntimeException('WebAPI BPS tidak mengembalikan daftar subjek untuk domain 1600.');
        }

        $variables = [];
        foreach ($subjects as $subject) {
            if (! is_array($subject)) {
                continue;
            }
            $subjectId = $subject['sub_id'] ?? $subject['subject_id'] ?? null;
            if (! is_scalar($subjectId)) {
                continue;
            }

            $subjectName = $subject['sub_name'] ?? $subject['subject'] ?? $subject['name'] ?? '';
            foreach ($this->listModelItems('var', '1600', $apiKey, (string) $subjectId) as $variable) {
                if (! is_array($variable)) {
                    continue;
                }
                $variableId = $variable['var_id'] ?? null;
                $title = $variable['title'] ?? $variable['var_name'] ?? null;
                if (! is_scalar($variableId) || ! is_string($title) || trim($title) === '') {
                    continue;
                }

                $vertical = $variable['vertical'] ?? null;
                $unit = $variable['unit'] ?? $variable['unit_name'] ?? $variable['satuan'] ?? null;
                $variables[] = [
                    'var_id' => (string) $variableId,
                    'title' => trim($title),
                    'subject_id' => (string) $subjectId,
                    'subject' => is_scalar($subjectName) ? trim((string) $subjectName) : '',
                    'unit' => is_scalar($unit) ? trim((string) $unit) : null,
                    'vertical' => is_scalar($vertical) ? trim((string) $vertical) : null,
                    'level' => $this->explicitVariableLevel($title),
                ];
            }
        }

        if ($variables === []) {
            throw new RuntimeException('WebAPI BPS tidak mengembalikan variabel untuk subjek domain 1600.');
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
        $verticalLevels = [];
        foreach ($verticalCounts as $vertical => $count) {
            $scopes = $verticalEvidence[$vertical] ?? [];
            if (count($scopes) === 1) {
                $verticalLevels[$vertical] = array_key_first($scopes);
            }
        }
        $verticalEvidenceReport = [];
        foreach ($verticalCounts as $vertical => $count) {
            $verticalEvidenceReport[$vertical] = array_map('array_keys', $verticalEvidence[$vertical] ?? []);
        }
        foreach ($variables as &$variable) {
            $variable['level'] ??= $verticalLevels[$variable['vertical'] ?? ''] ?? 'unknown';
        }
        unset($variable);

        Cache::put(self::VARIABLE_INDEX_CACHE_PREFIX.'1600', [
            'variables' => $variables,
            'vertical_evidence' => $verticalEvidenceReport,
            'vertical_counts' => $verticalCounts,
            'vertical_levels' => $verticalLevels,
            'refreshed_at' => now()->toIso8601String(),
        ], now()->addHours(26));

        return [
            'count' => count($variables),
            'vertical_evidence' => $verticalEvidenceReport,
            'vertical_counts' => $verticalCounts,
            'vertical_levels' => $verticalLevels,
        ];
    }

    public function isDataQuestion(string $question): bool
    {
        return preg_match('/\b(berapa|persen\w*|persentase|jumlah|total|warga|masyarakat|angka|nilai|laju|tingkat|indeks|pertumbuhan|inflasi|miskin\w*|kemiskinan|penduduk|pdrb|bruto|ekspor|impor|upah|penganggur\w*|ekonomi|produksi|harga|gini|ntp|tpt|ipm|indikator|data|statistik)\b/i', $question) === 1;
    }

    private function hasDynamicDataIntent(string $question): bool
    {
        return $this->isDataQuestion($question)
            || $this->matchFallbackTopic($question) !== null
            || preg_match('/\b(perikan\w*|ikan|tenaga|angkatan|pendidikan|kesehatan|pengeluaran|pendapatan|konsumsi|wisata\w*|hotel|transportasi|terbaru)\b/i', $question) === 1;
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

            if ($this->isMonthlyBpsTopic($question) && ! $regionalFallbackQuestion) {
                $pressReleaseContext = $this->pressReleaseContextFor($question, $domain, $apiKey);

                return $pressReleaseContext ?? $this->monthlyReleaseUnavailableContext($question);
            }

            $dynamicContext = $this->dynamicContextFor($question, $keywords, $domain, $apiKey);
            if ($dynamicContext !== null) {
                return $dynamicContext;
            }

            if ($regionalFallbackQuestion) {
                return '[WebAPI BPS][DATA_BELUM_TERSEDIA] Data untuk wilayah yang diminta belum ditemukan pada variabel BPS yang cocok. Jangan menggunakan nilai tingkat provinsi sebagai pengganti.';
            }

            if ($this->isDataQuestion($question) && $this->isCurrentPeriodRequest($question)) {
                return '[WebAPI BPS][DATA_BELUM_TERSEDIA] Data untuk periode terbaru/tahun berjalan belum ditemukan. Jangan gunakan periode lama atau variabel yang kurang relevan sebagai pengganti.';
            }

            $tables = $this->matchingTables($keywords, $domain, $apiKey);
            if ($tables === []) {
                $domain = '0000';
                $tables = $this->matchingTables($keywords, $domain, $apiKey);
            }

            $context = [];
            foreach ($tables as $table) {
                $tableId = $table['table_id'] ?? null;
                if (! is_scalar($tableId) || ! isset($table['title'])) {
                    continue;
                }

                $detail = $this->tableDetail((string) $tableId, $domain, $apiKey);
                if ($detail !== null) {
                    $context[] = '[Sumber: BPS WebAPI, '.$table['title'].'] '.$detail;
                }
            }

            if ($context !== []) {
                return implode("\n\n", $context);
            }

            return null;
        } catch (ConnectionException) {
            if ($catalog !== null) {
                return '[WebAPI BPS] API tidak dapat dihubungi untuk mengambil '.$catalog['label'].'. Beri tahu pengguna bahwa data belum dapat diverifikasi dan jangan mengarang daftar atau jumlah.';
            }

            return $this->isMonthlyBpsTopic($question)
                ? $this->monthlyReleaseUnavailableContext($question)
                : null;
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
            return 'Maaf, '.$catalog['label'].' belum dapat ditampilkan karena API key WebAPI BPS belum dikonfigurasi.';
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
            return 'WebAPI BPS tidak mengembalikan entri untuk '.$catalog['label'].'.';
        }

        $label = ucfirst($catalog['label']);
        $shown = count($result['items']);
        $answer = "Berikut {$label} BPS Sumatera Selatan yang tercatat di WebAPI BPS (menampilkan {$shown} dari {$result['total']} entri):";
        foreach ($result['items'] as $index => $item) {
            $title = $item['judul'] ?? 'Judul tidak tersedia pada respons API';
            $details = [];
            foreach (['tanggal' => 'rilis', 'subjek' => 'subjek', 'jumlah_tabel' => 'jumlah tabel', 'tautan' => 'tautan'] as $key => $detailLabel) {
                if (isset($item[$key])) {
                    $details[] = $detailLabel.': '.$item[$key];
                }
            }

            $answer .= "\n".($index + 1).'. '.$title.($details === [] ? '' : ' ('.implode('; ', $details).')');
        }
        if ($shown < $result['total']) {
            $answer .= "\nDaftar di atas merupakan entri terbaru yang ditampilkan API, bukan seluruh katalog.";
        }

        return $answer;
    }

    public function fallbackAnswerFor(string $question): string
    {
        $config = config('chatbot_fallback');
        $notice = $config['notice'];
        $closing = $config['closing'];

        $apiKey = $this->apiKey();
        $topics = array_slice($this->matchFallbackTopics($question), 0, 3);
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
        if ($regionalDataQuestion || $populationQuestion) {
            $label = $region['label'] ?? 'Provinsi Sumatera Selatan';
            if ($apiKey === null) {
                return $notice."\n\nData BPS untuk {$label} belum dapat diambil karena API key WebAPI BPS belum dikonfigurasi.\n\n".$closing;
            }

            try {
                $details = $this->dynamicFallbackDetails($question, $region, $populationQuestion);
            } catch (ConnectionException) {
                $details = null;
            }
            if ($details !== null) {
                return $notice."\n\n".$details."\n\nDitampilkan pada ".now('Asia/Jakarta')->format('d-m-Y H:i').' WIB.'."\n\n".$closing;
            }

            if ($region === null) {
                return $notice."\n\nData WebAPI BPS yang cocok untuk {$label} belum ditemukan atau belum dapat diverifikasi.\n\n".$closing;
            }
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

    private function fallbackForTopicRegions(
        string $question,
        array $topics,
        array $regions,
        string $apiKey,
        string $notice,
        string $closing
    ): string {
        $sections = [];
        $releaseLines = [];
        $regionUnavailable = [];
        foreach ($topics as $topic) {
            foreach ($regions as $region) {
                $sectionTitle = Str::ucfirst($topic['label']).' '.$region['label'];
                $sections[] = $sectionTitle.':';
                $regional = ($region['kind'] ?? null) === 'region';
                $regionalVariables = $regional ? ($topic['variables'] ?? []) : [];
                $provinceOnly = $regional && $this->isProvinceOnlyFallbackTopic($topic, $question);
                $useProvinceVariables = ! $regional || $provinceOnly || $regionalVariables === [];
                $variables = $useProvinceVariables
                    ? ($topic['province_variables'] ?? [])
                    : $regionalVariables;
                $selected = $this->selectFallbackVariable($variables, $question);
                $details = null;

                if ($selected !== null) {
                    $dataRegion = $useProvinceVariables ? $this->provinceFallbackRegion() : $region;
                    $lookup = $this->fallbackVariableLookup($selected, $question, $dataRegion, $apiKey);
                    if ($lookup['context'] !== null) {
                        $details = $this->dynamicFallbackDetails($question, $dataRegion, false, $lookup['context']);
                        if ($details !== null && $lookup['year_notice'] !== null) {
                            $details = $lookup['year_notice']."\n".$details;
                        }
                        if ($details !== null && $regional && $provinceOnly) {
                            $details = 'Indikator ini hanya tersedia pada tingkat Provinsi Sumatera Selatan; bukan data khusus '.$region['label'].".\n".$details;
                        } elseif ($details !== null && $regional && $dataRegion !== $region) {
                            $details = 'Data tingkat kabupaten/kota untuk indikator ini tidak tersedia; berikut data tingkat Provinsi Sumatera Selatan.'."\n".$details;
                        }
                    } elseif ($lookup['reason'] !== null) {
                        if ($regional && ! $provinceOnly && ! $useProvinceVariables) {
                            $provinceVariable = $this->selectFallbackVariable($topic['province_variables'] ?? [], $question);
                            if ($provinceVariable !== null) {
                                $provinceLookup = $this->fallbackVariableLookup(
                                    $provinceVariable,
                                    $question,
                                    $this->provinceFallbackRegion(),
                                    $apiKey
                                );
                                if ($provinceLookup['context'] !== null) {
                                    $details = $this->dynamicFallbackDetails(
                                        $question,
                                        $this->provinceFallbackRegion(),
                                        false,
                                        $provinceLookup['context']
                                    );
                                    if ($details !== null) {
                                        $details = 'Data kabupaten/kota untuk indikator ini belum tersedia; berikut data tingkat Provinsi Sumatera Selatan.'."\n".$details;
                                        if ($provinceLookup['year_notice'] !== null) {
                                            $details = $provinceLookup['year_notice']."\n".$details;
                                        }
                                    }
                                }
                            }
                        }
                        if ($details === null) {
                            $sections[] = 'Data variabel belum ditemukan: '.$lookup['reason'];
                        }
                    }
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
                    if ($details !== null) {
                        $sections[] = $details;
                    } else {
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

                $brs = $this->fallbackPressReleaseForPair($apiKey, $topic, $region);
                if ($brs !== null && count($releaseLines) < 5) {
                    $releaseLines[] = $brs;
                }
            }
        }

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

    private function selectFallbackVariable(array $variables, string $question): ?array
    {
        if ($variables === []) {
            return null;
        }
        if (count($variables) === 1) {
            return $variables[0];
        }

        $scores = [];
        foreach ($variables as $variable) {
            $scores[] = [
                'variable' => $variable,
                'score' => count(array_filter($variable['keywords'] ?? [], fn (string $keyword) => preg_match('/\b'.preg_quote($keyword, '/').'\b/iu', $question) === 1)),
            ];
        }
        usort($scores, fn (array $left, array $right) => $right['score'] <=> $left['score']);
        if (($scores[0]['score'] ?? 0) === 0 || ($scores[0]['score'] ?? 0) === ($scores[1]['score'] ?? 0)) {
            return null;
        }

        return $scores[0]['variable'];
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
            $response = $this->dynamicData($variableId, (int) ($period['th_id'] ?? 0), null, '1600', $apiKey);
        } catch (ConnectionException) {
            return ['context' => null, 'reason' => 'WebAPI BPS tidak dapat dihubungi untuk mengambil nilai indikator.', 'year_notice' => $yearNotice];
        }
        if ($response === null || ! is_array($response->json('datacontent'))) {
            return ['context' => null, 'reason' => 'nilai '.$variable['label'].' tahun '.$year.' tidak dikembalikan WebAPI BPS.', 'year_notice' => $yearNotice];
        }

        $regionRow = ($region['kind'] ?? null) === 'province'
            ? $this->provinceVervarRow($response->json('vervar'))
            : $this->regionVervarRow($region, $response->json('vervar'));
        if ($regionRow === null) {
            return ['context' => null, 'reason' => 'baris '.$region['label'].' tidak tersedia pada indikator '.$variable['label'].'.', 'year_notice' => $yearNotice];
        }
        $values = $this->valuesForRegion($response->json('datacontent'), $regionRow);
        if ($values === []) {
            return ['context' => null, 'reason' => 'nilai untuk '.$region['label'].' pada indikator '.$variable['label'].' tidak ditemukan.', 'year_notice' => $yearNotice];
        }

        $metadata = [
            'var_id' => $variableId,
            'title' => $variable['label'],
            'level' => ($region['kind'] ?? null) === 'province' ? 'province' : 'kab_kota',
            'sifat_data' => $variable['sifat_data'] ?? null,
            'catatan_sumber' => $variable['catatan_sumber'] ?? null,
        ];

        return [
            'context' => $this->formatDynamicContext($metadata, $response, $year, null, $regionRow, $values),
            'reason' => null,
            'year_notice' => $yearNotice,
        ];
    }

    private function fallbackPressReleaseForPair(string $apiKey, array $topic, array $region): ?string
    {
        $pattern = $this->titlePatternForKeywords($topic['title_keywords']);
        $local = ($region['kind'] ?? null) === 'region';
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
                $intro = str_replace('{topic}', $topic['label'], config('chatbot_fallback.topic_intro'))
                    .' Catatan: data berikut berlaku untuk tingkat Provinsi Sumatera Selatan, bukan untuk '.$region['label'].'.';
            }
        } catch (ConnectionException) {
            return null;
        }

        return $items === [] ? null : $intro."\n".$items[0]['judul']
            .(isset($items[0]['tanggal']) ? ' (rilis: '.$items[0]['tanggal'].')' : '');
    }

    private function catalogIntent(string $question): ?array
    {
        if (preg_match('/\b(berita\s+(?:resmi\s+)?statistik|brs|rilis\s+resmi)\b/i', $question)) {
            return [
                'model' => 'pressrelease',
                'label' => 'Berita Resmi Statistik terbaru',
                'limit' => 5,
                'pages' => 1,
                'dated' => true,
                'latest' => preg_match('/\b(terbaru|terkini|terakhir)\b/i', $question) === 1,
            ];
        }
        if (preg_match('/\b(publikasi|terbitan|buku\s+statistik)\b/i', $question)) {
            return ['model' => 'publication', 'label' => 'publikasi terbaru', 'limit' => 5, 'pages' => 1, 'dated' => true];
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
        if (isset($result['error'])) {
            return '[WebAPI BPS] Data '.$catalog['label'].' tidak berhasil diperoleh dari API. Beri tahu pengguna bahwa data belum dapat diverifikasi dan jangan mengarang daftar atau jumlah.';
        }

        $prefix = '[Sumber: WebAPI BPS Sumatera Selatan; katalog '.$catalog['label'].'; total menurut API: '.$result['total'];
        if (count($result['items']) < $result['total']) {
            $prefix .= '; ditampilkan: '.count($result['items']);
        }
        $prefix .= ']';
        if ($result['items'] === []) {
            return $prefix.' Tidak ada entri yang dikembalikan API. Jangan menyimpulkan jumlah atau membuat daftar sendiri.';
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
        if ($catalog['model'] !== 'pressrelease' || ! ($catalog['latest'] ?? false) || isset($filters['year']) || isset($filters['month'])) {
            return $this->fetchCatalog($catalog, $domain, $apiKey, $filters);
        }

        $date = now('Asia/Jakarta');
        for ($monthsBack = 0; $monthsBack <= 2; $monthsBack++) {
            $monthDate = $date->copy()->subMonthsNoOverflow($monthsBack);
            $monthFilters = $filters;
            $monthFilters['year'] = $monthDate->year;
            $monthFilters['month'] = $monthDate->month;
            $result = $this->fetchCatalog($catalog, $domain, $apiKey, $monthFilters);
            if (isset($result['error']) || $result['items'] !== []) {
                return $result;
            }
        }

        return $result;
    }

    private function catalogFilters(array $catalog, string $question): array
    {
        $filters = [];
        if (preg_match('/\byear\/(20\d{2})\b/i', $question, $match) || preg_match('/\btahun\s+(20\d{2})\b/i', $question, $match)) {
            $filters['year'] = (int) $match[1];
        }

        if (preg_match('/\bmonth\/(1[0-2]|[1-9])\b/i', $question, $match)) {
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

        if (preg_match('/\bpage\/([1-9]\d*)\b/i', $question, $match) || preg_match('/\bhalaman\s+([1-9]\d*)\b/i', $question, $match)) {
            $filters['page'] = (int) $match[1];
        }

        if (preg_match('/\bkeyword\/([\pL\pN_-]+(?:%20[\pL\pN_-]+)*)/iu', $question, $match)) {
            $filters['keyword'] = str_replace('%20', ' ', $match[1]);
        } else {
            $keywordQuestion = preg_replace('/\b(berita|resmi|statistik|brs|rilis|publikasi|terbitan|buku|tabel|statis|daftar|subjek|subject|topik|data|terbaru|terkini|terakhir|tahun|bulan|halaman|year|month|page|keyword|bps|sumsel|sumatera|selatan|apa|saja|dari|untuk|pada|yang|dan|tentang|mengenai|ini|januari|februari|maret|april|mei|juni|juli|agustus|september|oktober|november|desember)\b/iu', ' ', $question) ?? '';
            $keywordQuestion = preg_replace('/\b(20\d{2}|1[0-2]|[1-9])\b/u', ' ', $keywordQuestion) ?? '';
            $keywordQuestion = trim(preg_replace('/\s+/u', ' ', $keywordQuestion) ?? '');
            if (mb_strlen($keywordQuestion) >= 3) {
                $filters['keyword'] = $keywordQuestion;
            }
        }
        if (isset($filters['month']) && ! isset($filters['year'])) {
            $filters['year'] = now('Asia/Jakarta')->year;
        }

        return $filters;
    }

    private function fetchCatalog(array $catalog, string $domain, string $apiKey, array $filters = []): array
    {
        $results = [];
        $metadata = [];
        $firstPage = (int) ($filters['page'] ?? 1);
        $pagesToFetch = isset($filters['page']) ? $firstPage : (int) $catalog['pages'];

        for ($page = $firstPage; $page <= $pagesToFetch && count($results) < (int) $catalog['limit']; $page++) {
            $url = "https://webapi.bps.go.id/v1/api/list/model/{$catalog['model']}/domain/{$domain}/";
            foreach (['year', 'month'] as $filter) {
                if (isset($filters[$filter])) {
                    $url .= $filter.'/'.rawurlencode((string) $filters[$filter]).'/';
                }
            }
            if ($catalog['model'] !== 'pressrelease' || isset($filters['page']) || $page > 1) {
                $url .= 'page/'.$page.'/';
            }
            if (isset($filters['keyword'])) {
                $url .= 'keyword/'.str_replace('%20', '+', rawurlencode($filters['keyword'])).'/';
            }
            $url .= "key/{$apiKey}/";
            $response = Http::timeout(8)->get($url);
            $status = $response->json('status');
            $pageMetadata = $response->json('data.0');
            $pageResults = $response->json('data.1');

            if (! $response->successful() || $status !== 'OK' || ! is_array($pageResults)) {
                return ['error' => true];
            }

            if (is_array($pageMetadata)) {
                $metadata = $pageMetadata;
            }
            $results = array_merge($results, $pageResults);
            $pagesToFetch = min($pagesToFetch, max(1, (int) ($pageMetadata['pages'] ?? 1)));
        }

        $total = is_numeric($metadata['total'] ?? null) ? (int) $metadata['total'] : count($results);
        $visibleResults = array_slice($results, 0, (int) $catalog['limit']);
        $items = [];
        foreach ($visibleResults as $item) {
            if (! is_array($item)) {
                continue;
            }
            $formatted = $this->formatCatalogItem($item, (bool) $catalog['dated']);
            if ($formatted !== []) {
                $items[] = $formatted;
            }
        }

        return ['total' => $total, 'items' => $items];
    }

    private function formatCatalogItem(array $item, bool $dated): array
    {
        $formatted = [];
        foreach ([
            'judul' => ['title', 'judul', 'name'],
            'tanggal' => $dated ? ['rl_date', 'release_date', 'pub_date', 'date', 'updt_date'] : [],
            'subjek' => ['sub_name', 'subject', 'subcat'],
            'jumlah_tabel' => ['ntable', 'ntabel'],
            'tautan' => ['url', 'link'],
            'ringkasan' => ['abstract', 'description'],
        ] as $label => $fields) {
            foreach ($fields as $field) {
                if (isset($item[$field]) && is_scalar($item[$field]) && trim((string) $item[$field]) !== '') {
                    $formatted[$label] = $label === 'jumlah_tabel' && is_numeric($item[$field])
                        ? (int) $item[$field]
                        : Str::limit(trim((string) $item[$field]), $label === 'ringkasan' ? 500 : 300);
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
        $terms = array_slice($terms, 0, 3);
        if ($terms === []) {
            return $this->isRegionalDataQuestion($question)
                ? '[WebAPI BPS][DATA_BELUM_TERSEDIA][INDIKATOR_TIDAK_DITEMUKAN] Indikator yang diminta belum ditemukan di indeks WebAPI BPS.'
                : null;
        }

        $curatedVariables = $this->curatedVariables($terms);
        $cachedIndex = Cache::get(self::VARIABLE_INDEX_CACHE_PREFIX.$domain);
        $searchVariables = (is_array($cachedIndex) && is_array($cachedIndex['variables'] ?? null)) || $curatedVariables === []
            ? $this->matchingVariables($terms, $question, $domain, $apiKey)
            : [];
        $variablesById = [];
        foreach (array_merge($curatedVariables, $searchVariables) as $variable) {
            $variableId = $variable['var_id'] ?? null;
            $title = $variable['title'] ?? null;
            if (! is_scalar($variableId) || ! is_string($title)) {
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
            : ($this->isCurrentPeriodRequest($question) ? (string) now('Asia/Jakarta')->year : null);
        $requestedQuarter = $this->requestedQuarter($question);
        $region = $this->regionMentioned($question);
        $compatibleVariables = [];
        foreach ($variables as $variable) {
            $variable['level'] = $this->explicitVariableLevel((string) ($variable['title'] ?? ''))
                ?? ($variable['level'] ?? 'unknown');
            if ($region !== null) {
                if (in_array($variable['level'], ['province', 'subdistrict'], true)) {
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
        $yearFallbackNotice = null;
        if ($requestedYear !== null) {
            if ($candidatesWithRequestedPeriod === []) {
                $withLatestYear = array_values(array_filter($candidates, fn (array $candidate) => $candidate['_all_periods'] !== []));
                if ($withLatestYear === []) {
                    return '[WebAPI BPS][DATA_BELUM_TERSEDIA][TAHUN_TIDAK_TERSEDIA] Variabel ditemukan, tetapi WebAPI BPS belum menyediakan daftar tahun untuk indikator tersebut.';
                }
                foreach ($withLatestYear as &$candidate) {
                    $ceilingYear = (int) $requestedYear < now('Asia/Jakarta')->year
                        ? (int) $requestedYear
                        : now('Asia/Jakarta')->year;
                    $eligiblePeriods = array_values(array_filter(
                        $candidate['_all_periods'],
                        fn (array $period) => (int) ($period['th'] ?? 0) <= $ceilingYear
                    ));
                    $candidate['_periods'] = array_slice($eligiblePeriods, 0, 1);
                    $candidate['_selected_year'] = (int) ($candidate['_periods'][0]['th'] ?? 0);
                    $candidate['_latest_year'] = $candidate['_selected_year'];
                }
                unset($candidate);
                $withLatestYear = array_values(array_filter($withLatestYear, fn (array $candidate) => $candidate['_periods'] !== []));
                if ($withLatestYear === []) {
                    return '[WebAPI BPS][DATA_BELUM_TERSEDIA][TAHUN_TIDAK_TERSEDIA] Data untuk tahun '.$requestedYear.' belum tersedia dan tidak ada tahun sebelumnya yang dapat digunakan.';
                }
                usort($withLatestYear, fn (array $left, array $right) => ($right['_score'] ?? 0) <=> ($left['_score'] ?? 0)
                    ?: ($right['_latest_year'] ?? 0) <=> ($left['_latest_year'] ?? 0));
                $candidatesWithRequestedPeriod = $withLatestYear;
                $yearFallbackNotice = 'Data untuk tahun '.$requestedYear.' belum dirilis; berikut nilai dari tahun terakhir yang tersedia.';
            }
            $candidates = $candidatesWithRequestedPeriod;
        } else {
            $candidates = $candidatesWithRequestedPeriod;
        }
        if ($candidates === []) {
            if ($requestedYear === null && $region === null) {
                return null;
            }

            return '[WebAPI BPS][DATA_BELUM_TERSEDIA][TAHUN_TIDAK_TERSEDIA] Indikator ditemukan, tetapi WebAPI BPS belum mengembalikan periode data yang dapat digunakan.';
        }

        usort($candidates, fn (array $left, array $right) => ($right['_score'] ?? 0) <=> ($left['_score'] ?? 0)
            ?: ($right['_selected_year'] ?? 0) <=> ($left['_selected_year'] ?? 0));
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
                $apiKey
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

            return $yearFallbackNotice === null
                ? $context
                : '[WebAPI BPS][TAHUN_FALLBACK] '.$yearFallbackNotice.' Tahun data: '.$period['th'].'. '.$context;
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
            $lines[] = ($index + 1).'. '.$item['judul'].(isset($item['tanggal']) ? ' (rilis: '.$item['tanggal'].')' : '');
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
            && preg_match('/\b(miskin\w*|kemiskinan|angkatan\s+kerja|ketenagakerjaan|penganggur\w*|tpt)\b/i', $question) !== 1;
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
        if ($region !== null) {
            $regionRow = $this->regionVervarRow($region, $vervar);
            $regionLabel = $region['label'];
        } else {
            $regionRow = $this->provinceVervarRow($vervar);
            $regionLabel = 'Provinsi Sumatera Selatan';
        }
        if ($regionRow === null || ! is_scalar($regionRow['val'] ?? null)) {
            return null;
        }

        $categories = is_array($payload['kategori'] ?? null) ? $payload['kategori'] : [];
        if ($populationQuestion) {
            $categories = array_values(array_filter($categories, fn ($category) => is_array($category)
                && preg_match('/^(jumlah|total|tidak ada|laki-laki\s*\+\s*perempuan)$/iu', trim((string) ($category['label'] ?? ''))) === 1));
            if ($categories === []) {
                return null;
            }
        } elseif ($categories === []) {
            $categories = [['label' => null, 'val' => null]];
        }

        $details = [];
        foreach ($categories as $category) {
            $prefix = (string) $regionRow['val'].(string) $payload['var_id'];
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
                    : ($category['label'] ?? 'Nilai'));
            $rawValue = (string) reset($matchesForCategory);
            $decimalPart = explode('.', $rawValue, 2)[1] ?? '';
            $decimalPlaces = min(strlen(rtrim($decimalPart, '0')), 6);
            $value = number_format((float) $rawValue, $decimalPlaces, ',', '.');
            $unit = filled($payload['satuan'] ?? null) ? ' '.trim((string) $payload['satuan']) : '';
            $details[] = $categoryLabel.': '.$value.$unit;
        }
        if ($details === []) {
            return null;
        }

        $period = filled($payload['periode'] ?? null) && $payload['periode'] !== 'Tahunan'
            ? ' '.$payload['periode']
            : '';

        return 'Data WebAPI BPS untuk '.$payload['indikator'].' di '.$regionLabel.' tahun '.$payload['tahun'].$period.":\n".implode("\n", $details);
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

    private function matchingVariables(array $terms, string $question, string $domain, string $apiKey): array
    {
        $mainKeyword = $this->mainKeyword($terms);
        if ($mainKeyword === null) {
            return [];
        }

        $variables = [];
        $relevantTerms = array_values(array_filter($terms, fn (string $term) => ! in_array($term, self::GENERIC_TERMS, true)));
        $phrase = implode(' ', $relevantTerms);
        foreach ($this->variablesForKeyword($mainKeyword, $domain, $apiKey) as $variable) {
            $id = $variable['var_id'] ?? null;
            $title = $variable['title'] ?? null;
            if (! is_scalar($id) || ! is_string($title)) {
                continue;
            }

            $normalizedTitle = Str::lower($title);
            if (! Str::contains($normalizedTitle, $mainKeyword)) {
                continue;
            }

            $score = 0;
            foreach ($terms as $searchTerm) {
                if (Str::contains($normalizedTitle, $searchTerm)) {
                    $score += in_array($searchTerm, self::GENERIC_TERMS, true) ? 1 : min(Str::length($searchTerm), 10);
                }
            }
            if ($phrase !== '' && Str::contains($normalizedTitle, $phrase)) {
                $score += 20;
            }
            $score -= max(0, Str::length($title) - 50) / 10;
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

            $variable['level'] = $this->explicitVariableLevel($title) ?? ($variable['level'] ?? 'unknown');
            $variable['_score'] = $score;
            if (! isset($variables[(string) $id]) || $score > ($variables[(string) $id]['_score'] ?? 0)) {
                $variables[(string) $id] = $variable;
            }
        }

        uasort($variables, fn (array $left, array $right) => ($right['_score'] ?? 0) <=> ($left['_score'] ?? 0));

        return array_slice(array_values(array_filter($variables, fn (array $variable) => ($variable['_score'] ?? 0) >= 6)), 0, 10);
    }

    private function regionalVariableScore(array $variable, array $region): int
    {
        $score = ($variable['level'] ?? null) === 'kab_kota' ? 50 : 0;
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
                if (array_intersect($terms, $variable['excludes'] ?? []) !== []) {
                    continue;
                }
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

    private function listModelItems(string $model, string $domain, string $apiKey, ?string $subjectId = null): array
    {
        $items = [];
        $pages = 1;
        for ($page = 1; $page <= $pages; $page++) {
            $url = "https://webapi.bps.go.id/v1/api/list/model/{$model}/domain/{$domain}/";
            if ($subjectId !== null) {
                $url .= 'subject/'.rawurlencode($subjectId).'/';
            }
            $url .= "page/{$page}/key/{$apiKey}/";
            $response = Http::timeout(8)->get($url);
            if (! $response->successful() || $response->json('status') !== 'OK') {
                throw new RuntimeException("WebAPI BPS gagal mengambil model {$model} pada domain {$domain} (HTTP {$response->status()}).");
            }

            $pageItems = $response->json('data.1');
            if (! is_array($pageItems)) {
                throw new RuntimeException("WebAPI BPS mengembalikan daftar model {$model} yang tidak valid pada domain {$domain}.");
            }
            $items = array_merge($items, $pageItems);
            $pages = max(1, (int) $response->json('data.0.pages', 1));
            if ($pages > 200) {
                throw new RuntimeException("WebAPI BPS melaporkan jumlah halaman model {$model} yang tidak wajar.");
            }
        }

        return $items;
    }

    private function variablesForKeyword(string $keyword, string $domain, string $apiKey): array
    {
        $index = Cache::get(self::VARIABLE_INDEX_CACHE_PREFIX.$domain);
        if (is_array($index) && is_array($index['variables'] ?? null)) {
            return array_values(array_filter(
                $index['variables'],
                fn (array $variable) => Str::contains(Str::lower((string) ($variable['title'] ?? '')), Str::lower($keyword))
            ));
        }

        return Cache::remember('bps-webapi:variables:'.$domain.':'.sha1($keyword), now()->addHours(6), function () use ($keyword, $domain, $apiKey) {
            $variables = [];
            for ($page = 1; $page <= 8; $page++) {
                $keywordPath = str_replace('%20', '+', rawurlencode($keyword));
                $url = "https://webapi.bps.go.id/v1/api/list/model/var/domain/{$domain}/keyword/{$keywordPath}/page/{$page}/key/{$apiKey}/";
                $response = Http::timeout(8)->get($url);
                if (! $response->successful() || $response->json('status') !== 'OK') {
                    break;
                }

                $variables = array_merge($variables, $response->json('data.1') ?? []);
                if ($page >= (int) $response->json('data.0.pages', 1)) {
                    break;
                }
            }

            return $variables;
        });
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
                        $data = $this->dynamicData((int) $variable['var_id'], (int) ($latestPeriod['th_id'] ?? 0), null, '1600', $apiKey);
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
        return Cache::remember("bps-webapi:periods:{$domain}:{$variableId}", now()->addHour(), function () use ($variableId, $domain, $apiKey) {
            $url = "https://webapi.bps.go.id/v1/api/list/model/th/domain/{$domain}/var/{$variableId}/key/{$apiKey}/";
            $response = Http::timeout(8)->get($url);

            if (! $response->successful() || $response->json('status') !== 'OK') {
                return [];
            }

            $periods = $response->json('data.1') ?? [];
            usort($periods, fn (array $left, array $right) => (int) ($right['th'] ?? 0) <=> (int) ($left['th'] ?? 0));

            return $periods;
        });
    }

    private function domainFor(string $question): string
    {
        return '1600';
    }

    private function derivedPeriodsForVariable(int $variableId, string $domain, string $apiKey): array
    {
        $url = "https://webapi.bps.go.id/v1/api/list/model/turth/domain/{$domain}/var/{$variableId}/key/{$apiKey}/";
        $response = Http::timeout(8)->get($url);

        return $response->successful() && $response->json('status') === 'OK'
            ? ($response->json('data.1') ?? [])
            : [];
    }

    private function dynamicData(int $variableId, int $periodId, mixed $derivedPeriodId, string $domain, string $apiKey): ?\Illuminate\Http\Client\Response
    {
        $url = "https://webapi.bps.go.id/v1/api/list/model/data/domain/{$domain}/var/{$variableId}/th/{$periodId}/";
        if ($derivedPeriodId !== null) {
            $url .= 'turth/'.rawurlencode((string) $derivedPeriodId).'/';
        }
        $url .= "key/{$apiKey}/";
        $response = Http::timeout(8)->get($url);

        return $response->successful() && $response->json('status') === 'OK' ? $response : null;
    }

    private function formatDynamicContext(
        array $variable,
        \Illuminate\Http\Client\Response $response,
        string $year,
        ?string $quarter,
        ?array $regionRow,
        array $values
    ): string {
        $payload = [
            'indikator' => $response->json('var.0.label', $variable['title']),
            'definisi' => $response->json('var.0.def'),
            'satuan' => $response->json('var.0.unit'),
            'sifat_data' => $variable['sifat_data']
                ?? (preg_match('/\bproyeksi\b/iu', (string) ($variable['title'] ?? '')) === 1 ? 'proyeksi' : null),
            'catatan_sumber' => $variable['catatan_sumber'] ?? null,
            'subjek' => $variable['subject'] ?? null,
            'level' => $variable['level'] ?? $this->explicitVariableLevel((string) ($variable['title'] ?? '')),
            'vertical' => $variable['vertical'] ?? null,
            'wilayah' => $regionRow === null ? $response->json('vervar') : [$regionRow],
            'kategori' => $response->json('turvar'),
            'tahun' => $year,
            'periode' => $quarter ?? 'Tahunan',
            'var_id' => $response->json('var.0.val', $variable['var_id']),
            'nilai' => $values,
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return '[Sumber: WebAPI BPS Sumatera Selatan, '.$variable['title'].'] '.Str::limit($json ?: '', 7000, '...');
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
        $encrypted = DB::table('chatbot_settings')->where('key', self::SETTING_KEY)->value('value');
        if (! is_string($encrypted) || $encrypted === '') {
            return null;
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (DecryptException) {
            return null;
        }
    }

    private function searchTables(string $keyword, string $domain, string $apiKey): array
    {
        $keywordPath = str_replace('%20', '+', rawurlencode($keyword));
        $url = "https://webapi.bps.go.id/v1/api/list/model/statictable/domain/{$domain}/keyword/{$keywordPath}/key/{$apiKey}/";
        $response = Http::timeout(8)->get($url);
        if (! $response->successful() || $response->json('status') !== 'OK') {
            return [];
        }

        $tables = $response->json('data.1');

        return is_array($tables) ? $tables : [];
    }

    private function matchingTables(array $keywords, string $domain, string $apiKey): array
    {
        $mainKeyword = $this->mainKeyword($keywords);
        if ($mainKeyword === null) {
            return [];
        }

        $matches = [];
        foreach (array_slice(array_values(array_filter($keywords, fn (string $term) => ! in_array($term, self::GENERIC_TERMS, true))), 0, 4) as $keyword) {
            foreach ($this->searchTables($keyword, $domain, $apiKey) as $table) {
                $tableId = $table['table_id'] ?? null;
                $title = $table['title'] ?? null;
                if (! is_scalar($tableId) || ! is_string($title)) {
                    continue;
                }
                if (! Str::contains(Str::lower($title), $mainKeyword)) {
                    continue;
                }

                $score = 0;
                foreach ($keywords as $term) {
                    if (Str::contains(Str::lower($title), $term)) {
                        $score += min(Str::length($term), 10);
                    }
                }
                $matches[(string) $tableId] = ['table' => $table, 'score' => $score];
            }
        }

        uasort($matches, fn (array $left, array $right) => $right['score'] <=> $left['score']);
        $ranked = array_values($matches);
        $best = $ranked[0] ?? null;
        $runnerUp = $ranked[1] ?? null;
        if ($best === null
            || $best['score'] < 6
            || ($runnerUp !== null && $best['score'] - $runnerUp['score'] < 4)) {
            return [];
        }

        return [$best['table']];
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

    private function tableDetail(string $tableId, string $domain, string $apiKey): ?string
    {
        $url = "https://webapi.bps.go.id/v1/api/view/model/statictable/domain/{$domain}/lang/ind/id/".rawurlencode($tableId)."/key/{$apiKey}/";
        $response = Http::timeout(8)->get($url);
        if (! $response->successful() || $response->json('status') !== 'OK') {
            return null;
        }

        $table = $response->json('data.table');
        if (! is_string($table) || trim($table) === '') {
            return null;
        }

        $text = preg_replace('/<\/(?:td|th|tr|p|div)>/i', "\n", $table) ?? $table;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace('/[ \t]+|\R{2,}/u', ' ', $text) ?? '');

        return Str::limit($text, 5000, '...');
    }

    private function keywords(string $question): array
    {
        preg_match_all('/[\pL\pN]{3,}/u', Str::lower($question), $matches);
        $stopWords = ['yang', 'dan', 'atau', 'untuk', 'dari', 'dengan', 'pada', 'dalam', 'adalah', 'berapa', 'bagaimana', 'apa', 'data', 'saya', 'kami', 'bisa', 'tolong', 'menurut', 'tahun', 'sumatera', 'selatan', 'sumsel', 'provinsi', 'indonesia', 'terbaru', 'terkini', 'terakhir', 'sekarang', 'ada', 'ini', 'itu', 'bulan', 'lalu', 'sih', 'dong', 'nih'];
        $region = $this->regionMentioned($question);
        if ($region !== null) {
            $regionWords = ['kabupaten', 'kab', 'kota'];
            foreach ($region['aliases'] as $alias) {
                $regionWords = array_merge($regionWords, preg_split('/\s+/u', Str::lower($alias)) ?: []);
            }
            $stopWords = array_merge($stopWords, $regionWords);
        }
        $synonyms = ['total' => 'jumlah', 'warga' => 'penduduk', 'masyarakat' => 'penduduk', 'presentase' => 'persentase', 'laju' => 'pertumbuhan', 'kemiskinan' => 'miskin'];
        $keywords = array_diff($matches[0] ?? [], $stopWords);

        return array_values(array_unique(array_map(fn (string $keyword) => $synonyms[$keyword] ?? $keyword, $keywords)));
    }
}
