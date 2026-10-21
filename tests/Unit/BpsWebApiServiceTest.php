<?php

namespace Tests\Unit;

use App\Services\BpsWebApiService;
use Tests\TestCase;

class BpsWebApiServiceTest extends TestCase
{
    public function test_average_wage_question_does_not_match_average_commodity_price_table(): void
    {
        $service = new BpsWebApiService;
        $keywords = $this->invokePrivate($service, 'keywords', 'rata-rata gaji di sumsel berapa');
        $tables = $this->invokePrivate(
            $service,
            'matchingTables',
            $keywords,
            '1600',
            'test-api-key'
        );

        $titles = array_column($tables, 'title');
        $this->assertNotContains(
            'Rata Rata Harga Beberapa Komoditas di Kabupaten/Kota se-Sumatera Selatan Agustus - September 2022',
            $titles
        );
        $upahKeywords = $this->invokePrivate($service, 'keywords', 'Berapa upah terbaru?');
        $upahTables = $this->invokePrivate(
            $service,
            'matchingTables',
            $upahKeywords,
            '1600',
            'test-api-key'
        );
        $upahTitles = implode(' ', array_column($upahTables, 'title'));
        $this->assertStringNotContainsString('Pengamatan', $upahTitles);
        $this->assertStringNotContainsString('Ternak', $upahTitles);
    }

    public function test_wage_question_suggests_related_sumsel_informal_income_tables(): void
    {
        $service = new BpsWebApiService;
        $question = 'rata-rata gaji di Sumsel berapa';
        $keywords = $this->invokePrivate($service, 'keywords', $question);
        $tables = $this->invokePrivate(
            $service,
            'matchingTables',
            $keywords,
            '1600',
            'test-api-key',
            $question
        );
        $tablesById = [];
        foreach ($tables as $table) {
            $tablesById[$table['table_id']] = $table;
        }

        $this->assertSame([
            'UXBNZmtLbnJrWnV3cE5aWkw3T1ovdz09',
            'aFZiZWkvUllycnd0UGkzV1gwQ1A2QT09',
        ], array_keys($tablesById));
        $this->assertArrayHasKey('aFZiZWkvUllycnd0UGkzV1gwQ1A2QT09', $tablesById);
        $this->assertArrayHasKey('UXBNZmtLbnJrWnV3cE5aWkw3T1ovdz09', $tablesById);
        $this->assertSame('2025', $tablesById['aFZiZWkvUllycnd0UGkzV1gwQ1A2QT09']['year']);
        $this->assertSame('2025', $tablesById['UXBNZmtLbnJrWnV3cE5aWkw3T1ovdz09']['year']);
        $this->assertTrue($tablesById['aFZiZWkvUllycnd0UGkzV1gwQ1A2QT09']['related_match']);
        $this->assertTrue($tablesById['UXBNZmtLbnJrWnV3cE5aWkw3T1ovdz09']['related_match']);
    }

    public function test_related_wage_results_are_presented_as_confirmation_candidates(): void
    {
        config(['services.bps.api_key' => 'test-api-key']);
        $context = (new BpsWebApiService)->contextFor('rata-rata gaji di Sumsel berapa');

        $this->assertStringContainsString('[KANDIDAT_TOPIK_TERKAIT]', $context);
        $this->assertStringContainsString('Tanyakan dengan sopan apakah salah satu tabel berikut', $context);
        $this->assertStringContainsString('Lapangan Pekerjaan Utama', $context);
        $this->assertStringContainsString('Pendidikan Tertinggi', $context);
        $this->assertStringNotContainsString('Realisasi Pendapatan dan Belanja Pemerintah Desa', $context);
    }

    public function test_user_fallback_asks_before_claiming_related_tables_answer_the_question(): void
    {
        config(['services.bps.api_key' => 'test-api-key']);
        $answer = (new BpsWebApiService)->fallbackAnswerFor('rata-rata gaji di Sumsel berapa');

        $this->assertStringContainsString('Ada beberapa tabel BPS Sumatera Selatan', $answer);
        $this->assertStringContainsString('Lapangan Pekerjaan Utama', $answer);
        $this->assertStringContainsString('Pendidikan Tertinggi', $answer);
        $this->assertStringContainsString('Apakah salah satu tabel tersebut yang Anda cari?', $answer);
        $this->assertStringNotContainsString('[KANDIDAT_TOPIK_TERKAIT]', $answer);
    }

    public function test_static_table_html_is_rendered_as_bounded_rows(): void
    {
        $service = new BpsWebApiService;
        $rows = '<tr><th>Wilayah</th><th>Gaji</th></tr>';
        for ($index = 1; $index <= 30; $index++) {
            $rows .= "<tr><td>Lahat {$index}</td><td>Rp4.000.000</td></tr>";
        }

        $text = $this->invokePrivate(
            $service,
            'formatStaticTableHtml',
            "<table>{$rows}</table>"
        );

        $this->assertStringContainsString('| Wilayah | Gaji |', $text);
        $this->assertStringContainsString('| Lahat 1 | Rp4.000.000 |', $text);
        $this->assertStringNotContainsString('<table>', $text);
        $this->assertStringNotContainsString('| Lahat 26 |', $text);
        $this->assertStringContainsString('dipotong setelah 25 baris', $text);
    }

    public function test_simdasi_rows_are_rendered_as_bounded_markdown(): void
    {
        $service = new BpsWebApiService;
        $table = [
            'kolom' => [
                'income' => ['nama_variabel' => 'Rata-rata pendapatan berdasarkan Lapangan Pekerjaan Utama - Pertanian'],
                'total' => ['nama_variabel' => 'Rata-rata pendapatan berdasarkan Lapangan Pekerjaan Utama - Jumlah'],
            ],
            'data' => [[
                'label' => 'Lahat',
                'variables' => [
                    'income' => ['value' => '2.500.000'],
                    'total' => ['value' => '2.800.000'],
                ],
            ]],
        ];

        $text = $this->invokePrivate($service, 'formatSimdasiTable', $table);

        $this->assertStringContainsString('| Wilayah | Pertanian | Jumlah |', $text);
        $this->assertStringContainsString('| Lahat | 2.500.000 | 2.800.000 |', $text);
    }

    private function invokePrivate(BpsWebApiService $service, string $method, mixed ...$arguments): mixed
    {
        $reflection = new \ReflectionMethod($service, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($service, $arguments);
    }
}
