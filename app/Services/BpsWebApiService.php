<?php

namespace App\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class BpsWebApiService
{
    private const GENERIC_TERMS = ['jumlah', 'persentase', 'angka', 'nilai', 'tingkat', 'banyak', 'indeks', 'pembangunan', 'manusia'];

    private const SETTING_KEY = 'bps_webapi_key';

    private const BRS_TOPICS = [
        ['question' => '/\b(inflasi|ihk)\b/i', 'title' => '/inflasi/i', 'keyword' => 'inflasi'],
        ['question' => '/\bntp\b|nilai tukar petani/i', 'title' => '/\bNTP\b|nilai tukar petani/i', 'keyword' => 'ntp'],
        ['question' => '/\bekspor\b/i', 'title' => '/ekspor|neraca perdagangan/i', 'keyword' => 'ekspor'],
        ['question' => '/\bimpor\b/i', 'title' => '/impor|neraca perdagangan/i', 'keyword' => 'impor'],
        ['question' => '/neraca perdagangan/i', 'title' => '/neraca perdagangan|ekspor|impor/i', 'keyword' => 'perdagangan'],
        ['question' => '/\b(wisman|wisatawan|wisata|pariwisata|hotel|tpk)\b/i', 'title' => '/wisman|wisnus|hotel|TPK/i', 'keyword' => 'pariwisata'],
        ['question' => '/\b(penumpang|transportasi)\b/i', 'title' => '/penumpang|transportasi/i', 'keyword' => 'penumpang'],
        ['question' => '/\b(ketenagakerjaan|tenaga\s+kerja|penganggur\w*|tpt|upah)\b/i', 'title' => '/ketenagakerjaan|penganggur|TPT|upah/i', 'keyword' => 'ketenagakerjaan'],
        ['question' => '/miskin|kemiskinan/i', 'title' => '/kemiskinan|penduduk miskin/i', 'keyword' => 'kemiskinan'],
        ['question' => '/pertumbuhan ekonomi|laju ekonomi|pdrb/i', 'title' => '/pertumbuhan ekonomi|PDRB/i', 'keyword' => 'pdrb'],
    ];

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

    public function isDataQuestion(string $question): bool
    {
        return preg_match('/\b(berapa|persen\w*|persentase|jumlah|angka|nilai|laju|tingkat|indeks|pertumbuhan|inflasi|miskin\w*|kemiskinan|penduduk|pdrb|bruto|ekspor|impor|upah|penganggur\w*|ekonomi|produksi|harga|gini|ntp|tpt|ipm|indikator|data|statistik)\b/i', $question) === 1;
    }

    public function isUnavailableDataContext(?string $context): bool
    {
        return is_string($context) && str_starts_with($context, '[WebAPI BPS][DATA_BELUM_TERSEDIA]');
    }

    public function contextFor(string $question): ?string
    {
        $apiKey = $this->apiKey();
        $catalog = $this->catalogIntent($question);
        if ($catalog !== null && $apiKey === null) {
            return '[WebAPI BPS] API key belum dikonfigurasi. Beri tahu pengguna bahwa data '.$catalog['label'].' belum dapat diambil dan jangan mengarang daftar atau jumlah.';
        }
        if ($this->isMonthlyBpsTopic($question) && $apiKey === null) {
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

            if ($this->isMonthlyBpsTopic($question)) {
                $pressReleaseContext = $this->pressReleaseContextFor($question, $domain, $apiKey);

                return $pressReleaseContext ?? $this->monthlyReleaseUnavailableContext($question);
            }

            $dynamicContext = $this->dynamicContextFor($question, $keywords, $domain, $apiKey);
            if ($dynamicContext !== null) {
                return $dynamicContext;
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
        $busy = 'Maaf, chatbot BPS Sumsel sedang menerima banyak permintaan sehingga belum dapat memproses pesan Anda.';
        $outro = "\n\nSilakan kirim pertanyaan Anda lagi beberapa saat lagi, atau kunjungi https://sumsel.bps.go.id.";

        $apiKey = $this->apiKey();
        if ($apiKey === null) {
            return $busy.$outro;
        }

        try {
            $topic = $this->topicPatternFor($question);
            $items = $this->latestPressReleases($apiKey, $topic, 5);
            $heading = 'Berita Resmi Statistik yang terkait dengan pertanyaan Anda';
            if ($items === []) {
                $items = $this->latestPressReleases($apiKey, null, 5);
                $heading = 'Berita Resmi Statistik terbaru';
            }
        } catch (ConnectionException) {
            return $busy.$outro;
        }

        if ($items === []) {
            return $busy.$outro;
        }

        $lines = [$busy.' Berikut data terbaru langsung dari WebAPI BPS:', '', $heading.':'];
        foreach ($items as $index => $item) {
            $lines[] = ($index + 1).'. '.$item['judul'].(isset($item['tanggal']) ? ' (rilis: '.$item['tanggal'].')' : '');
        }
        $lines[] = '';
        $lines[] = 'Data diambil dari WebAPI BPS pada '.now('Asia/Jakarta')->format('d-m-Y H:i').' WIB.';

        return implode("\n", $lines).$outro;
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
        if (! $this->isDataQuestion($question) && ! preg_match('/\b(perikan\w*|ikan|tenaga|angkatan|pendidikan|kesehatan|pengeluaran|pendapatan|konsumsi|wisata\w*|hotel|transportasi|terbaru)\b/i', $question)) {
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
            return null;
        }

        $hasCuratedIntent = $this->hasCuratedIndicatorIntent($terms);
        $variables = $this->curatedVariables($terms);
        if ($variables === []) {
            if ($hasCuratedIntent) {
                return '[WebAPI BPS][DATA_BELUM_TERSEDIA] Variabel terverifikasi untuk rincian indikator yang diminta belum tersedia. Jangan menggantinya dengan var_id hasil tebakan.';
            }
            $variables = $this->matchingVariables($terms, $question, $domain, $apiKey);
        }
        if ($variables === []) {
            return null;
        }

        $requestedYear = preg_match('/\b(20\d{2})\b/', $question, $yearMatch)
            ? $yearMatch[1]
            : ($this->isCurrentPeriodRequest($question) ? (string) now('Asia/Jakarta')->year : null);
        $requestedQuarter = $this->requestedQuarter($question);
        $notes = [];
        $candidates = [];

        foreach ($variables as $variable) {
            $variableId = (int) ($variable['var_id'] ?? 0);
            if ($variableId === 0) {
                continue;
            }

            $periods = $this->periodsForVariable($variableId, $domain, $apiKey);
            if ($periods === []) {
                continue;
            }

            $periodsToCheck = $requestedYear === null
                ? $periods
                : array_values(array_filter($periods, fn (array $period) => (string) ($period['th'] ?? '') === $requestedYear));

            if ($requestedYear !== null && $periodsToCheck === []) {
                $notes[] = 'Data WebAPI BPS untuk '.$variable['title'].' tahun '.$requestedYear.' belum tersedia. Jangan gunakan periode lain sebagai pengganti.';

                continue;
            }

            $variable['_periods'] = $periodsToCheck;
            $variable['_latest_year'] = (int) ($periods[0]['th'] ?? 0);
            $candidates[] = $variable;
        }

        usort($candidates, fn (array $left, array $right) => ($right['_score'] ?? 0) <=> ($left['_score'] ?? 0)
            ?: ($right['_latest_year'] ?? 0) <=> ($left['_latest_year'] ?? 0));
        $contexts = [];

        foreach ($candidates as $variable) {
            $variableId = (int) ($variable['var_id'] ?? 0);
            $periodsToCheck = $variable['_periods'];

            $isQuarterly = Str::contains(Str::lower($variable['title']), 'triwulan');
            $derivedPeriods = $isQuarterly ? $this->derivedPeriodsForVariable($variableId, $domain, $apiKey) : [];
            $quarters = array_values(array_filter($derivedPeriods, fn (array $period) => preg_match('/triwulan\s*([1-4]|i{1,3}|iv)/i', $period['turth'] ?? '') === 1));
            if ($requestedQuarter !== null) {
                $quarters = array_values(array_filter($quarters, fn (array $period) => $this->quarterNumber((string) ($period['turth'] ?? '')) === $requestedQuarter));
            } else {
                $quarters = array_reverse($quarters);
            }

            foreach ($periodsToCheck as $period) {
                $dataOptions = $isQuarterly && $quarters !== [] ? $quarters : [null];
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

                    if (preg_match('/\bpalembang\b/i', $question)) {
                        $labels = Str::lower(json_encode($response->json('vervar'), JSON_UNESCAPED_UNICODE) ?: '');
                        if (! Str::contains($labels, 'palembang')) {
                            continue;
                        }
                    }

                    $contexts[] = $this->formatDynamicContext($variable, $response, (string) $period['th'], $quarter['turth'] ?? null);
                    break 2;
                }
            }

            if ($contexts !== []) {
                break;
            }
        }

        if ($contexts !== []) {
            return implode("\n\n", $contexts);
        }

        if ($notes !== []) {
            return '[WebAPI BPS][DATA_BELUM_TERSEDIA] '.$notes[0];
        }

        if ($requestedYear !== null && $variables !== []) {
            return '[WebAPI BPS][DATA_BELUM_TERSEDIA] WebAPI BPS belum mengembalikan nilai untuk indikator yang cocok pada tahun '.$requestedYear.'. Jangan menyajikan data tahun sebelumnya sebagai data '.$requestedYear.'.';
        }

        return null;
    }

    private function pressReleaseContextFor(string $question, string $domain, string $apiKey): ?string
    {
        $keyword = null;
        foreach (self::BRS_TOPICS as $topic) {
            if (preg_match($topic['question'], $question)) {
                $keyword = $topic['keyword'];
                break;
            }
        }
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

    private function latestPressReleases(string $apiKey, ?string $titlePattern, int $max, int $monthsBack = 6): array
    {
        $catalog = ['model' => 'pressrelease', 'label' => 'Berita Resmi Statistik', 'limit' => 30, 'pages' => 3, 'dated' => true];
        $now = now('Asia/Jakarta');
        $found = [];

        for ($back = 0; $back <= $monthsBack && count($found) < $max; $back++) {
            $month = $now->copy()->subMonthsNoOverflow($back);
            $cacheKey = "bps-webapi:brs:{$month->year}-{$month->month}";
            $result = Cache::get($cacheKey);
            if ($result === null) {
                $result = $this->fetchCatalog($catalog, '1600', $apiKey, ['year' => $month->year, 'month' => $month->month]);
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

    private function topicPatternFor(string $question): ?string
    {
        foreach (self::BRS_TOPICS as $topic) {
            if (preg_match($topic['question'], $question)) {
                return $topic['title'];
            }
        }

        return null;
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

            $variable['_score'] = $score;
            $variables[(string) $id] = ['variable' => $variable, 'score' => $score];
        }

        uasort($variables, fn (array $left, array $right) => $right['score'] <=> $left['score']);
        $ranked = array_values($variables);
        $best = $ranked[0] ?? null;
        $runnerUp = $ranked[1] ?? null;
        if ($best === null
            || $best['score'] < 6
            || ($runnerUp !== null && $best['score'] - $runnerUp['score'] < 4)) {
            return [];
        }

        return [$best['variable']];
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

    private function hasCuratedIndicatorIntent(array $terms): bool
    {
        foreach (config('bps_indicators.groups', []) as $group) {
            $matchedGroupTerms = array_values(array_intersect($terms, $group['terms'] ?? []));
            if ($matchedGroupTerms === []) {
                continue;
            }
            if (in_array('ipm', $group['terms'] ?? [], true)
                && ! in_array('ipm', $matchedGroupTerms, true)
                && count($matchedGroupTerms) < 2) {
                continue;
            }

            return true;
        }

        return false;
    }

    private function variablesForKeyword(string $keyword, string $domain, string $apiKey): array
    {
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

                return [
                    'var_id' => $variable['var_id'] ?? null,
                    'title' => $variable['title'] ?? null,
                    'years' => array_column($this->periodsForVariable($variableId, '1600', $apiKey), 'th'),
                ];
            },
            array_slice($this->variablesForKeyword($keyword, '1600', $apiKey), 0, 10)
        );
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

    private function formatDynamicContext(array $variable, \Illuminate\Http\Client\Response $response, string $year, ?string $quarter): string
    {
        $payload = [
            'indikator' => $response->json('var.0.label', $variable['title']),
            'definisi' => $response->json('var.0.def'),
            'satuan' => $response->json('var.0.unit'),
            'wilayah' => $response->json('vervar'),
            'kategori' => $response->json('turvar'),
            'tahun' => $year,
            'periode' => $quarter ?? 'Tahunan',
            'nilai' => $response->json('datacontent'),
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
        $synonyms = ['warga' => 'penduduk', 'presentase' => 'persentase', 'laju' => 'pertumbuhan', 'kemiskinan' => 'miskin'];
        $keywords = array_diff($matches[0] ?? [], $stopWords);

        return array_values(array_unique(array_map(fn (string $keyword) => $synonyms[$keyword] ?? $keyword, $keywords)));
    }
}
