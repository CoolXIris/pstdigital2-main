<?php

namespace Tests\Unit;

use App\Services\BpsWebApiService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
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

    public function test_latest_student_tables_are_matched_after_removing_question_filler_words(): void
    {
        $service = new BpsWebApiService;
        $question = 'Apakah ada data terkait jumlah murid di Sumsel yang terbaru?';
        $keywords = $this->invokePrivate($service, 'keywords', $question);
        $tables = $this->invokePrivate(
            $service,
            'matchingTables',
            $keywords,
            '1600',
            'test-api-key',
            $question
        );

        $this->assertNotContains('apakah', $keywords);
        $this->assertNotContains('terkait', $keywords);
        $this->assertNotEmpty($tables);
        $this->assertGreaterThanOrEqual(2025, max(array_map(
            static fn (array $table) => (int) $table['year'],
            $tables
        )));
        $this->assertFalse($this->invokePrivate($service, 'tablesNeedConfirmation', $tables, $question));
    }

    public function test_casual_area_question_keeps_only_the_indicator_and_region_terms(): void
    {
        $service = new BpsWebApiService;
        $question = 'Pengen tau luas daerah PALI itu berapa yah?';

        $this->assertSame(
            ['luas', 'daerah'],
            $this->invokePrivate($service, 'keywords', $question)
        );
        $this->assertSame(
            ['luas', 'daerah'],
            $this->invokePrivate($service, 'requiredVariableTerms', $question)
        );
    }

    public function test_specific_topic_outweighs_facility_context_in_table_search(): void
    {
        $service = new BpsWebApiService;
        $schoolKeywords = $this->invokePrivate($service, 'keywords', 'fasilitas sekolah');
        $healthKeywords = $this->invokePrivate($service, 'keywords', 'fasilitas kesehatan');

        $this->assertSame(
            ['sekolah'],
            $this->invokePrivate($service, 'requiredVariableTerms', 'fasilitas sekolah')
        );
        $this->assertSame(
            'sekolah',
            $this->invokePrivate(
                $service,
                'mainKeyword',
                $this->invokePrivate($service, 'coreTableKeywords', $schoolKeywords)
            )
        );
        $this->assertSame(
            'kesehatan',
            $this->invokePrivate(
                $service,
                'mainKeyword',
                $this->invokePrivate($service, 'coreTableKeywords', $healthKeywords)
            )
        );
    }

    public function test_universitas_mahasiswa_and_dosen_match_the_latest_higher_education_simdasi_tables(): void
    {
        $service = new BpsWebApiService;
        $question = 'Apakah ada data terkait jumlah universitas di Sumsel dan total per kabupaten/kota?';
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
            'dnF4TTdwbEcwbTFHazAwZUtOMVRBQT09',
            'cmdTdG5vU0IwKzBFR20rQnpuZEYzdz09',
        ], array_keys($tablesById));
        $this->assertSame('2025', $tablesById['dnF4TTdwbEcwbTFHazAwZUtOMVRBQT09']['year']);
        $this->assertSame('2025', $tablesById['cmdTdG5vU0IwKzBFR20rQnpuZEYzdz09']['year']);
        $this->assertFalse($this->invokePrivate($service, 'tablesNeedConfirmation', $tables, $question));

        foreach (['mahasiswa', 'dosen'] as $topic) {
            $topicQuestion = 'Apakah ada data terkait jumlah '.$topic.' di Sumsel?';
            $topicTables = $this->invokePrivate(
                $service,
                'matchingTables',
                $this->invokePrivate($service, 'keywords', $topicQuestion),
                '1600',
                'test-api-key',
                $topicQuestion
            );
            $this->assertNotEmpty($topicTables, 'The catalog should match '.$topic.' in a higher-education table.');
            $this->assertSame('2025', max(array_column($topicTables, 'year')));
        }
    }

    public function test_requested_year_is_not_counted_as_a_topic_when_matching_simdasi_tables(): void
    {
        $service = new BpsWebApiService;
        $question = 'bencana alam di Sumsel 2025';
        $keywords = $this->invokePrivate($service, 'keywords', $question);
        $coreKeywords = $this->invokePrivate($service, 'coreTableKeywords', $keywords);
        $tables = $this->invokePrivate(
            $service,
            'matchingTables',
            $keywords,
            '1600',
            'test-api-key',
            $question
        );
        $disasterTable = collect($tables)->firstWhere(
            'table_id',
            'MFZ0emxUcHBxOTB2R2F4Sk5oT2hqQT09'
        );

        $this->assertSame(['bencana', 'alam'], $coreKeywords);
        $this->assertNotNull($disasterTable);
        $this->assertSame('2025', $disasterTable['year']);
        $this->assertFalse($disasterTable['related_match']);
        $this->assertFalse($this->invokePrivate($service, 'tablesNeedConfirmation', $tables, $question));
    }

    public function test_village_and_district_counts_are_not_routed_to_population_totals(): void
    {
        $service = new BpsWebApiService;

        $this->assertFalse($this->invokePrivate(
            $service,
            'isPopulationTotalQuestion',
            'jumlah desa dan kelurahan di Sumsel terbaru'
        ));
        $this->assertFalse($this->invokePrivate(
            $service,
            'isPopulationTotalQuestion',
            'jumlah kecamatan di Lahat'
        ));
        $this->assertTrue($this->invokePrivate(
            $service,
            'isPopulationTotalQuestion',
            'berapa total penduduk di PALI'
        ));
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
        $this->assertStringContainsString('| --- | --- |', $text);
        $this->assertStringContainsString('| Lahat 1 | Rp4.000.000 |', $text);
        $this->assertStringNotContainsString('<table>', $text);
        $this->assertStringNotContainsString('| Lahat 26 |', $text);
        $this->assertStringContainsString('dipotong setelah 25 baris', $text);
    }

    public function test_entity_encoded_static_table_is_decoded_before_rendering(): void
    {
        $service = new BpsWebApiService;
        $html = '&lt;table&gt;&lt;tr&gt;&lt;th&gt;Wilayah&lt;/th&gt;&lt;th&gt;Jumlah&lt;/th&gt;&lt;/tr&gt;'
            .'&lt;tr&gt;&lt;td&gt;Palembang&lt;/td&gt;&lt;td&gt;75&nbsp;orang&lt;/td&gt;&lt;/tr&gt;&lt;/table&gt;';
        $text = $this->invokePrivate($service, 'formatStaticTableHtml', $html);

        $this->assertStringContainsString('| Wilayah | Jumlah |', $text);
        $this->assertStringContainsString('| Palembang | 75 orang |', $text);
        $this->assertStringNotContainsString('&nbsp;', $text);
    }

    public function test_static_table_is_limited_to_the_requested_sumsel_aggregate(): void
    {
        $service = new BpsWebApiService;
        $html = '<table><tr><th>Wilayah</th><th>Jumlah</th></tr>'
            .'<tr><td>Lahat</td><td>12</td></tr>'
            .'<tr><td>Sumatera Selatan</td><td>99</td></tr></table>';
        $region = [
            'label' => 'Provinsi Sumatera Selatan',
            'aliases' => ['sumsel', 'sumatera selatan'],
            'match' => ['sumatera selatan', 'sumsel'],
            'kind' => 'province',
        ];

        $text = $this->invokePrivate($service, 'formatStaticTableHtml', $html, $region);

        $this->assertStringContainsString('| Sumatera Selatan | 99 |', $text);
        $this->assertStringNotContainsString('| Lahat |', $text);
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

    public function test_related_press_release_replaces_unavailable_indicator_fallback(): void
    {
        Cache::flush();
        \Illuminate\Support\Carbon::setTestNow(
            \Illuminate\Support\Carbon::parse('2026-10-22 21:03:00', 'Asia/Jakarta')
        );
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, '/model/th/domain/1600/var/645/')) {
                return Http::response([
                    'status' => 'OK',
                    'data' => [['pages' => 1], [['th' => '2026', 'th_id' => 126]]],
                ]);
            }
            if (str_contains($url, '/model/pressrelease/')) {
                return Http::response([
                    'status' => 'OK',
                    'data' => [['total' => 1, 'pages' => 1], [[
                        'title' => 'NTP September 2026 tercatat 156,67',
                        'rl_date' => '2026-10-01',
                    ]]],
                ]);
            }
            if (str_contains($url, '/var/645/')) {
                return Http::response([
                    'status' => 'OK',
                    'var' => [['val' => 645, 'label' => 'Perkembangan Nilai Tukar Petani', 'unit' => 'Indeks']],
                    'vervar' => [],
                    'turvar' => [],
                    'datacontent' => [],
                ]);
            }

            return Http::response(['status' => 'ERROR'], 404);
        });

        $service = new BpsWebApiService;
        $topic = config('chatbot_fallback.topics.ntp') + ['key' => 'ntp'];
        $region = [
            'label' => 'Provinsi Sumatera Selatan',
            'aliases' => ['sumsel', 'sumatera selatan'],
            'match' => ['sumsel', 'sumatera selatan'],
            'kind' => 'province',
            'domain' => '1600',
        ];
        $method = new \ReflectionMethod(BpsWebApiService::class, 'fallbackForTopicRegions');
        $reply = $method->invoke(
            $service,
            'NTP terbaru',
            [$topic],
            [$region],
            'test-api-key',
            'Layanan AI tidak tersedia.',
            'Silakan coba lagi.'
        );
        \Illuminate\Support\Carbon::setTestNow();

        $this->assertStringNotContainsString('belum ditemukan', mb_strtolower($reply));
        $this->assertStringContainsString('Berita Resmi Statistik yang ditemukan:', $reply);
        $this->assertStringContainsString('Berita Resmi Statistik terbaru tentang Nilai Tukar Petani (NTP):', $reply);
    }

    public function test_source_label_is_separated_from_markdown_table(): void
    {
        $service = new BpsWebApiService;
        $section = $this->invokePrivate(
            $service,
            'formatSourceSection',
            '[Sumber: BPS WebAPI, Tabel contoh]',
            "| Wilayah | Jumlah |\n| --- | --- |\n| Palembang | 75 |"
        );

        $this->assertSame(
            "[Sumber: BPS WebAPI, Tabel contoh]\n\n| Wilayah | Jumlah |\n| --- | --- |\n| Palembang | 75 |",
            $section
        );
    }

    public function test_fallback_table_sources_use_the_matching_verified_csv_url(): void
    {
        foreach ([
            '[Sumber: WebAPI BPS Sumatera Selatan, Jumlah Fasilitas Kesehatan. (Unit)]',
            '[Sumber: BPS WebAPI, Jumlah Fasilitas Kesehatan. (Unit)]',
        ] as $marker) {
            $context = $marker.' {"tahun":"2025"}';
            $sources = (new BpsWebApiService)->sourceLinesForContext(
                $context,
                'jumlah fasilitas kesehatan Sumsel'
            );

            $this->assertStringContainsString('Sumber:', $sources);
            $this->assertStringContainsString(
                'https://sumsel.bps.go.id/id/statistics-table/2/Nzc5IzI=/jumlah-fasilitas-kesehatan---unit-.html',
                $sources
            );
        }

        $populationSources = (new BpsWebApiService)->sourceLinesForContext(
            '[Sumber: BPS WebAPI, Jumlah Penduduk Menurut Kabupaten/Kota] {"var_id":262}',
            'jumlah orang di Sumsel terbaru'
        );
        $this->assertStringContainsString(
            'https://sumsel.bps.go.id/id/statistics-table/2/MjYyIzI=/jumlah-penduduk-menurut-kabupaten-kota--jiwa-.html',
            $populationSources
        );
    }

    public function test_fallback_editorial_source_rejects_api_key_urls_and_uses_listing_page(): void
    {
        $context = "[Sumber: WebAPI BPS; Berita Resmi Statistik]\n"
            .'1. {"judul":"BRS terbaru","tautan":"https://webapi.bps.go.id/v1/api/key/not-a-real-key/"}';
        $sources = (new BpsWebApiService)->sourceLinesForContext($context, 'BRS terbaru');

        $this->assertStringContainsString(
            '- [BRS terbaru](https://sumsel.bps.go.id/id/pressrelease)',
            $sources
        );
        $this->assertStringNotContainsString('not-a-real-key', $sources);
    }

    public function test_simdasi_table_is_limited_to_the_requested_sumsel_aggregate(): void
    {
        $service = new BpsWebApiService;
        $table = [
            'kolom' => [
                'count' => ['nama_variabel' => 'Jumlah bencana'],
            ],
            'data' => [
                ['label' => 'Lahat', 'variables' => ['count' => ['value' => '12']]],
                ['label' => 'Sumatera Selatan', 'variables' => ['count' => ['value' => '99']]],
            ],
        ];
        $region = [
            'label' => 'Provinsi Sumatera Selatan',
            'aliases' => ['sumsel', 'sumatera selatan'],
            'match' => ['sumatera selatan', 'sumsel'],
            'kind' => 'province',
        ];

        $text = $this->invokePrivate($service, 'formatSimdasiTable', $table, $region);

        $this->assertStringContainsString('| Sumatera Selatan | 99 |', $text);
        $this->assertStringNotContainsString('| Lahat |', $text);
    }

    public function test_simdasi_table_does_not_substitute_district_rows_when_province_total_is_missing(): void
    {
        $service = new BpsWebApiService;
        $table = [
            'kolom' => [
                'count' => ['nama_variabel' => 'Jumlah bencana'],
            ],
            'data' => [
                ['label' => 'Lahat', 'variables' => ['count' => ['value' => '12']]],
            ],
        ];
        $region = [
            'label' => 'Provinsi Sumatera Selatan',
            'aliases' => ['sumsel', 'sumatera selatan'],
            'match' => ['sumatera selatan', 'sumsel'],
            'kind' => 'province',
        ];

        $text = $this->invokePrivate($service, 'formatSimdasiTable', $table, $region);

        $this->assertStringContainsString('tidak tersedia pada tabel SIMDASI', $text);
        $this->assertStringNotContainsString('| Lahat |', $text);
    }

    public function test_explicit_district_breakdown_keeps_all_matching_regions(): void
    {
        $service = new BpsWebApiService;

        $this->assertNull($this->invokePrivate(
            $service,
            'tableRegionFilterForQuestion',
            'Jumlah fasilitas sekolah di Sumsel per kabupaten/kota'
        ));
        $this->assertSame(
            'province',
            $this->invokePrivate($service, 'tableRegionFilterForQuestion', 'Jumlah fasilitas sekolah di Sumsel')['kind']
        );
    }

    public function test_simdasi_duplicate_leaf_headers_keep_their_topic_labels(): void
    {
        $service = new BpsWebApiService;
        $table = [
            'kolom' => [
                'university' => ['nama_variabel' => 'Jumlah Perguruan Tinggi - Negeri'],
                'lecturer' => ['nama_variabel' => 'Jumlah Tenaga Pendidik - Negeri'],
            ],
            'data' => [[
                'label' => 'Palembang',
                'variables' => [
                    'university' => ['value' => '1'],
                    'lecturer' => ['value' => '616'],
                ],
            ]],
        ];

        $text = $this->invokePrivate($service, 'formatSimdasiTable', $table);

        $this->assertStringContainsString('| Wilayah | Jumlah Perguruan Tinggi - Negeri | Jumlah Tenaga Pendidik - Negeri |', $text);
    }

    public function test_empty_successful_variable_search_does_not_abort_simdasi_catalog_search(): void
    {
        $keyword = 'perguruan';
        Cache::forget('bps-webapi:variables:keyword:1600:'.sha1($keyword));
        Http::fake([
            '*keyword/perguruan/*' => Http::response(['status' => 'OK', 'data' => '']),
        ]);

        $variables = $this->invokePrivate(
            new BpsWebApiService,
            'variablesForKeyword',
            $keyword,
            '1600',
            'test-api-key'
        );

        $this->assertSame([], $variables);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/keyword/perguruan/'));
    }

    private function invokePrivate(BpsWebApiService $service, string $method, mixed ...$arguments): mixed
    {
        $reflection = new \ReflectionMethod($service, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($service, $arguments);
    }
}
