<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Contracts\Encryption\DecryptException;
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
        $keywords = $this->keywords($question);
        if ($apiKey === null || count($keywords) < 2) {
            return null;
        }

        try {
            $searchTerm = implode(' ', array_slice($keywords, 0, 6));
            $domain = '1600';
            $tables = $this->searchTables($searchTerm, $domain, $apiKey);
            if ($tables === []) {
                $domain = '0000';
                $tables = $this->searchTables($searchTerm, $domain, $apiKey);
            }

            $context = [];
            foreach (array_slice($tables, 0, 2) as $table) {
                $tableId = $table['table_id'] ?? null;
                if (!is_scalar($tableId) || !isset($table['title'])) {
                    continue;
                }

                $detail = $this->tableDetail((string) $tableId, $domain, $apiKey);
                if ($detail !== null) {
                    $context[] = '[Sumber: BPS WebAPI, '.$table['title'].'] '.$detail;
                }
            }

            return $context === [] ? null : implode("\n\n", $context);
        } catch (ConnectionException) {
            return null;
        }
    }

    private function apiKey(): ?string
    {
        $encrypted = DB::table('chatbot_settings')->where('key', self::SETTING_KEY)->value('value');
        if (!is_string($encrypted) || $encrypted === '') {
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
        if (!$response->successful() || $response->json('status') !== 'OK') {
            return [];
        }

        $tables = $response->json('data.1');

        return is_array($tables) ? $tables : [];
    }

    private function tableDetail(string $tableId, string $domain, string $apiKey): ?string
    {
        $url = "https://webapi.bps.go.id/v1/view/model/statictable/domain/{$domain}/id/".rawurlencode($tableId)."/key/{$apiKey}/";
        $response = Http::timeout(8)->get($url);
        if (!$response->successful() || $response->json('status') !== 'OK') {
            return null;
        }

        $table = $response->json('data.table');
        if (!is_string($table) || trim($table) === '') {
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
        $stopWords = ['yang', 'dan', 'atau', 'untuk', 'dari', 'dengan', 'pada', 'dalam', 'adalah', 'berapa', 'bagaimana', 'apa', 'data', 'saya', 'kami', 'bisa', 'tolong', 'menurut', 'tahun'];

        return array_values(array_unique(array_diff($matches[0] ?? [], $stopWords)));
    }
}