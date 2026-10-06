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

    public function contextFor(string $question): ?string
    {
        $apiKey = $this->apiKey();
        $catalog = $this->catalogIntent($question);
        if ($catalog !== null && $apiKey === null) {
            return '[WebAPI BPS] API key belum dikonfigurasi. Beri tahu pengguna bahwa data '.$catalog['label'].' belum dapat diambil dan jangan mengarang daftar atau jumlah.';
        }

        $keywords = $this->keywords($question);
        if ($apiKey === null || ($keywords === [] && $catalog === null)) {
            return null;
        }

        try {
            $domain = '1600';
            if ($catalog !== null) {
                return $this->catalogContext($catalog, $domain, $apiKey);
            }

            $dynamicContext = $this->dynamicContextFor($question, $keywords, $domain, $apiKey);
            if ($dynamicContext !== null) {
                return $dynamicContext;
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

            return $context === [] ? null : implode("\n\n", $context);
        } catch (ConnectionException) {
            return $catalog === null
                ? null
                : '[WebAPI BPS] API tidak dapat dihubungi untuk mengambil '.$catalog['label'].'. Beri tahu pengguna bahwa data belum dapat diverifikasi dan jangan mengarang daftar atau jumlah.';
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
            $result = $this->fetchCatalogForQuestion($catalog, $question, '1600', $apiKey);
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

    private function catalogIntent(string $question): ?array
    {
        if (preg_match('/\b(berita\s+resmi\s+statistik|brs|rilis\s+resmi)\b/i', $question)) {
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
        if (! preg_match('/\b(pdrb|bruto|penganggur\w*|tpt|perikan\w*|ikan|produksi|inflasi|tenaga|angkatan|ekspor|impor|indeks|ipm|kemiskinan|pertumbuhan|penduduk|pendidikan|kesehatan|pengeluaran|upah|pendapatan|konsumsi|wisata\w*|hotel|transportasi|persentase|jumlah|nilai|angka|berapa|terbaru)\b/i', $question)) {
            return null;
        }

        $terms = array_values(array_filter($keywords, fn (string $term) => ! preg_match('/^\d{4}$/', $term)));
        if (preg_match('/\b(?:q|triwulan)\s*(?:[1-4]|i{1,3}|iv)\b/i', $question) && ! in_array('triwulanan', $terms, true)) {
            $terms[] = 'triwulanan';
        }
        $terms = array_slice($terms, 0, 3);
        if ($terms === []) {
            return null;
        }

        $variables = $this->matchingVariables($terms, $question, $domain, $apiKey);
        if ($variables === []) {
            return null;
        }

        $requestedYear = preg_match('/\b(20\d{2})\b/', $question, $yearMatch) ? $yearMatch[1] : null;
        $requestedQuarter = $this->requestedQuarter($question);
        $contexts = [];

        foreach (array_slice($variables, 0, 2) as $variable) {
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
                $contexts[] = 'Data WebAPI BPS untuk '.$variable['title'].' tahun '.$requestedYear.' belum tersedia. Periode terbaru pada API: '.($periods[0]['th'] ?? 'tidak diketahui').'. Jangan gunakan tahun lain sebagai pengganti.';

                continue;
            }

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

                    $contexts[] = $this->formatDynamicContext($variable, $response, (string) $period['th'], $quarter['turth'] ?? null);
                    break 2;
                }
            }

            if (count($contexts) > 0 && $requestedYear !== null) {
                break;
            }
        }

        if ($contexts !== []) {
            return implode("\n\n", $contexts);
        }

        if ($requestedYear !== null && $variables !== []) {
            return 'WebAPI BPS belum mengembalikan nilai untuk indikator yang cocok pada tahun '.$requestedYear.'. Jangan menyajikan data tahun sebelumnya sebagai data '.$requestedYear.'.';
        }

        return null;
    }

    private function matchingVariables(array $terms, string $question, string $domain, string $apiKey): array
    {
        $variables = [];
        foreach (array_slice($terms, 0, 3) as $term) {
            foreach ($this->variablesForKeyword($term, $domain, $apiKey) as $variable) {
                $id = $variable['var_id'] ?? null;
                $title = $variable['title'] ?? null;
                if (! is_scalar($id) || ! is_string($title)) {
                    continue;
                }

                $score = 0;
                $normalizedTitle = Str::lower($title);
                foreach ($terms as $searchTerm) {
                    if (Str::contains($normalizedTitle, $searchTerm)) {
                        $score += min(Str::length($searchTerm), 10);
                    }
                }
                if (preg_match('/\b(?:q|triwulan)\s*(?:[1-4]|i{1,3}|iv)\b/i', $question) && Str::contains($normalizedTitle, 'triwulan')) {
                    $score += 20;
                } elseif (preg_match('/\bpdrb\b/i', $question) && ! preg_match('/\btahunan\b/i', $question) && Str::contains($normalizedTitle, 'triwulan')) {
                    $score += 10;
                }
                if (preg_match('/\badhk\b/i', $question) && Str::contains($normalizedTitle, 'konstan')) {
                    $score += 10;
                }
                if (preg_match('/\badhb\b/i', $question) && Str::contains($normalizedTitle, 'berlaku')) {
                    $score += 10;
                }

                $variables[(string) $id] = ['variable' => $variable, 'score' => $score];
            }
        }

        uasort($variables, fn (array $left, array $right) => $right['score'] <=> $left['score']);

        return array_column(array_slice($variables, 0, 5, true), 'variable');
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

    private function periodsForVariable(int $variableId, string $domain, string $apiKey): array
    {
        $url = "https://webapi.bps.go.id/v1/api/list/model/th/domain/{$domain}/var/{$variableId}/key/{$apiKey}/";
        $response = Http::timeout(8)->get($url);

        return $response->successful() && $response->json('status') === 'OK'
            ? ($response->json('data.1') ?? [])
            : [];
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
        $matches = [];
        foreach (array_slice($keywords, 0, 4) as $keyword) {
            foreach ($this->searchTables($keyword, $domain, $apiKey) as $table) {
                $tableId = $table['table_id'] ?? null;
                $title = $table['title'] ?? null;
                if (! is_scalar($tableId) || ! is_string($title)) {
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

        return array_slice(array_column(array_slice($matches, 0, 2, true), 'table'), 0, 2);
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
        $stopWords = ['yang', 'dan', 'atau', 'untuk', 'dari', 'dengan', 'pada', 'dalam', 'adalah', 'berapa', 'bagaimana', 'apa', 'data', 'saya', 'kami', 'bisa', 'tolong', 'menurut', 'tahun', 'sumatera', 'selatan', 'sumsel', 'provinsi', 'indonesia'];

        return array_values(array_unique(array_diff($matches[0] ?? [], $stopWords)));
    }
}
