<?php

namespace Tests\Feature;

use App\Models\Meeting;
use App\Models\User;
use App\Services\BpsWebApiService;
use App\Services\ChatbotKnowledgeService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase as BaseTestCase;

class AdminManagementPagesTest extends BaseTestCase
{
    protected Authenticatable $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        Artisan::call('migrate:fresh', ['--force' => true]);

        foreach (['super_admin', 'admin', 'user'] as $roleName) {
            Role::create(['name' => $roleName, 'guard_name' => 'web']);
        }

        /** @var User $admin */
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        /** @var Authenticatable $authenticatedAdmin */
        $authenticatedAdmin = $admin;
        $this->admin = $authenticatedAdmin;
    }

    public function test_admin_can_open_all_four_management_pages(): void
    {
        $this->actingAs($this->admin)->get(route('dashboard'))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.konsultasi'))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.chatbot'))->assertOk();
        $this->actingAs($this->admin)->get(route('users.index'))->assertOk();
    }

    public function test_regular_user_cannot_open_admin_management_pages(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $user->assignRole('user');

        /** @var Authenticatable $authenticatedUser */
        $authenticatedUser = $user;

        $this->actingAs($authenticatedUser)->get(route('dashboard'))->assertForbidden();
        $this->actingAs($authenticatedUser)->get(route('admin.konsultasi'))->assertForbidden();
        $this->actingAs($authenticatedUser)->get(route('admin.chatbot'))->assertForbidden();
        $this->actingAs($authenticatedUser)->get(route('users.index'))->assertForbidden();
    }

    public function test_chatbot_catalog_detection_expands_gender_and_healthcare_synonyms(): void
    {
        $service = app(BpsWebApiService::class);
        $populationQuestion = new \ReflectionMethod(BpsWebApiService::class, 'isPopulationTotalQuestion');

        $this->assertTrue($service->isRegionalDataQuestion('pria dan wanita di Palembang'));
        $this->assertTrue($service->isRegionalDataQuestion('RSUD di Palembang'));
        $this->assertFalse($populationQuestion->invoke(
            $service,
            'jumlah penduduk pria dan wanita di Sumsel'
        ));
    }

    public function test_uploaded_dataset_is_used_as_context_for_chatbot_answers(): void
    {
        Storage::fake('local');

        $this->actingAs($this->admin)
            ->from(route('admin.chatbot'))
            ->post(route('admin.chatbot.knowledge.store'), [
                'title' => 'Indikator Kemiskinan',
                'file' => UploadedFile::fake()->createWithContent('indikator.csv', "indikator,nilai\nGaris kemiskinan,12.5\n"),
            ])
            ->assertRedirect(route('admin.chatbot'));

        $this->assertDatabaseHas('chatbot_knowledge_sources', ['title' => 'Indikator Kemiskinan', 'chunks_count' => 1]);
        $this->assertStringContainsString('Garis kemiskinan', app(ChatbotKnowledgeService::class)->contextFor('berapa garis kemiskinan'));

        Http::fake(['*127.0.0.1:8001/api/chat*' => Http::response(['data' => 'Nilai garis kemiskinan tercatat 12.5.'], 200)]);

        $this->actingAs($this->admin)
            ->postJson(route('chatbot.message'), ['message' => 'berapa garis kemiskinan'])
            ->assertOk()
            ->assertJsonPath('knowledge_used', true);

        Http::assertSent(fn ($request) => $request['question'] === 'berapa garis kemiskinan'
            && str_contains($request['context'], 'Garis kemiskinan'));
    }

    public function test_chatbot_knowledge_service_calls_configured_gemini_api_with_private_token(): void
    {
        config([
            'services.gemini.chat_url' => 'https://gemini-internal.test/api/chat',
            'services.gemini.chat_token' => 'private-shared-token',
        ]);
        Http::fake([
            'https://gemini-internal.test/api/chat' => Http::response([
                'reply' => 'Gemini reply.',
                'data' => 'Gemini reply.',
            ]),
        ]);

        $response = app(ChatbotKnowledgeService::class)->askWithContext(
            'Apa angka terbaru?',
            'session-123',
            'Referensi BPS: tahun 2025.',
            [['prompt' => 'Sebelumnya', 'response' => 'Jawaban lama.']]
        );

        $this->assertSame('Gemini reply.', $response->json('data'));
        Http::assertSent(fn ($request) => $request->url() === 'https://gemini-internal.test/api/chat'
            && $request->hasHeader('Authorization', 'Bearer private-shared-token')
            && $request['session_id'] === 'session-123'
            && $request['question'] === 'Apa angka terbaru?'
            && $request['context'] === 'Referensi BPS: tahun 2025.'
            && $request['history'] === [['prompt' => 'Sebelumnya', 'response' => 'Jawaban lama.']]);
    }

    public function test_admin_can_configure_encrypted_bps_api_key_without_intercepting_ai_chat(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.chatbot'))
            ->assertOk()
            ->assertSee('WebAPI BPS')
            ->assertSee('Belum terhubung');

        $this->actingAs($this->admin)
            ->post(route('admin.chatbot.bps-api-key'), ['api_key' => 'bps-test-secret'])
            ->assertRedirect(route('admin.chatbot'))
            ->assertSessionHas('message');

        $encryptedKey = DB::table('chatbot_settings')->where('key', 'bps_webapi_key')->value('value');
        $this->assertNotSame('bps-test-secret', $encryptedKey);
        $this->assertSame('bps-test-secret', Crypt::decryptString($encryptedKey));
        $this->actingAs($this->admin)
            ->get(route('admin.chatbot'))
            ->assertOk()
            ->assertDontSee('bps-test-secret')
            ->assertSee('API key tersimpan');

        Http::fake([
            '*127.0.0.1:8001/api/chat*' => Http::response([
                'reply' => 'Persentase kemiskinan tahun 2025 sebesar 10,5.',
                'data' => 'Persentase kemiskinan tahun 2025 sebesar 10,5.',
            ], 200),
        ]);

        /** @var User $user */
        $user = User::factory()->create();
        $user->assignRole('user');

        $this->actingAs($user)
            ->postJson(route('chatbot.message'), ['message' => 'berapa persentase kemiskinan'])
            ->assertOk()
            ->assertJsonPath('knowledge_used', false)
            ->assertJsonPath('reply', 'Persentase kemiskinan tahun 2025 sebesar 10,5.');

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'webapi.bps.go.id'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '127.0.0.1:8001/api/chat')
            && $request['question'] === 'berapa persentase kemiskinan');

        $this->actingAs($this->admin)
            ->post(route('admin.chatbot.bps-api-key'), ['clear_api_key' => '1'])
            ->assertRedirect(route('admin.chatbot'));
        $this->assertDatabaseMissing('chatbot_settings', ['key' => 'bps_webapi_key']);
    }

    public function test_bps_catalog_filters_year_and_month_for_all_catalog_models(): void
    {
        app(BpsWebApiService::class)->saveApiKey('bps-test-secret');

        Http::fake([
            'webapi.bps.go.id/v1/api/list/*' => Http::response([
                'status' => 'OK',
                'data' => [['total' => 1, 'pages' => 1], [['title' => 'Katalog BPS Sumsel']]],
            ]),
        ]);

        $service = app(BpsWebApiService::class);

        $this->assertNotNull($service->catalogAnswerFor('publikasi kemiskinan tahun 2024 bulan maret'));
        $this->assertNotNull($service->catalogAnswerFor('daftar tabel statis kemiskinan tahun 2025 bulan oktober'));
        $this->assertNotNull($service->catalogAnswerFor('subjek data tahun 2026 bulan 2'));

        Http::assertSent(fn ($request) => str_contains($request->url(), '/model/publication/domain/1600/year/2024/month/3/'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/model/statictable/domain/1600/year/2025/month/10/'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/model/subject/domain/1600/year/2026/month/2/'));
    }

    public function test_bps_catalog_recognizes_berita_statistik_without_resmi(): void
    {
        app(BpsWebApiService::class)->saveApiKey('bps-test-secret');
        Http::fake(['webapi.bps.go.id/*' => Http::response([
            'status' => 'OK',
            'data' => [['total' => 1], [['title' => 'Berita Statistik Terbaru']]],
        ])]);

        $answer = app(BpsWebApiService::class)->catalogAnswerFor('berita statistik');

        $this->assertStringContainsString('Berita Statistik Terbaru', $answer);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/model/pressrelease/'));
    }

    public function test_dynamic_bps_context_prefers_the_candidate_with_the_requested_year(): void
    {
        app(BpsWebApiService::class)->saveApiKey('bps-test-secret');
        Cache::flush();
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, '/model/var/')) {
                return Http::response([
                    'status' => 'OK',
                    'data' => [['pages' => 1], [
                        ['var_id' => 11, 'title' => 'Produksi Ikan Tangkap'],
                        ['var_id' => 12, 'title' => 'Produksi Ikan Budidaya'],
                    ]],
                ]);
            }
            if (str_contains($url, '/model/th/')) {
                $periods = str_contains($url, '/var/11/')
                    ? [['th' => '2023', 'th_id' => 2023]]
                    : [['th' => '2024', 'th_id' => 2024]];

                return Http::response(['status' => 'OK', 'data' => [[], $periods]]);
            }
            if (str_contains($url, '/model/data/')) {
                return Http::response([
                    'status' => 'OK',
                    'datacontent' => ['1600' => 123],
                    'var' => [['label' => 'Produksi Ikan Budidaya']],
                ]);
            }

            return Http::response(['status' => 'ERROR'], 404);
        });

        $context = app(BpsWebApiService::class)->contextFor('berapa jumlah produksi ikan tahun 2024');

        $this->assertStringContainsString('Produksi Ikan Budidaya', $context);
        $this->assertStringContainsString('"tahun":"2024"', $context);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/model/data/domain/1600/var/12/th/2024/'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/model/data/domain/1600/var/11/'));
    }

    public function test_dynamic_bps_context_asks_for_clarification_when_candidates_remain_tied(): void
    {
        app(BpsWebApiService::class)->saveApiKey('bps-test-secret');
        Cache::flush();
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, '/model/var/')) {
                return Http::response([
                    'status' => 'OK',
                    'data' => [['pages' => 1], [
                        ['var_id' => 11, 'title' => 'Produksi Ikan Tangkap'],
                        ['var_id' => 12, 'title' => 'Produksi Ikan Budidaya'],
                    ]],
                ]);
            }
            if (str_contains($url, '/model/th/')) {
                return Http::response(['status' => 'OK', 'data' => [[], [['th' => '2026', 'th_id' => 2026]]]]);
            }

            return Http::response(['status' => 'ERROR'], 404);
        });

        $context = app(BpsWebApiService::class)->contextFor('berapa jumlah produksi ikan tahun 2026');

        $this->assertStringContainsString('[INDIKATOR_AMBIGU]', $context);
        $this->assertStringContainsString('Produksi Ikan Tangkap', $context);
        $this->assertStringContainsString('Produksi Ikan Budidaya', $context);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/model/data/'));
    }

    public function test_dynamic_bps_context_uses_latest_period_and_caches_period_list(): void
    {
        app(BpsWebApiService::class)->saveApiKey('bps-test-secret');
        Cache::flush();
        $periodRequests = 0;
        $periodFixture = json_decode(file_get_contents(base_path('tests/Fixtures/bps-periods-var-608.json')), true);
        Http::fake(function ($request) use (&$periodRequests, $periodFixture) {
            $url = $request->url();
            if (str_contains($url, '/model/th/domain/1600/var/608/')) {
                $periodRequests++;

                return Http::response($periodFixture);
            }
            if (str_contains($url, '/model/data/')) {
                return Http::response(['status' => 'OK', 'datacontent' => ['1600' => 123]]);
            }

            return Http::response(['status' => 'ERROR'], 404);
        });

        $service = app(BpsWebApiService::class);
        $firstContext = $service->contextFor('kemiskinan');
        $secondContext = $service->contextFor('kemiskinan');

        $this->assertStringContainsString('2026', $firstContext);
        $this->assertStringContainsString('2026', $secondContext);
        $this->assertSame(1, $periodRequests);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/model/data/domain/1600/var/608/th/126/'));
    }

    public function test_variable_index_uses_titles_and_prefers_regional_indicators(): void
    {
        app(BpsWebApiService::class)->saveApiKey('bps-test-secret');
        Cache::flush();
        $title = 'Persentase Penduduk Miskin Menurut Kabupaten/Kota';
        Http::fake(function ($request) use ($title) {
            $url = $request->url();
            if (str_contains($url, '/model/subject/domain/1600/')) {
                return Http::response([
                    'status' => 'OK',
                    'data' => [['pages' => 1], [
                        ['sub_id' => 1, 'sub_name' => 'Kemiskinan'],
                        ['sub_id' => 2, 'sub_name' => 'Pembangunan Manusia'],
                    ]],
                ]);
            }
            if (str_contains($url, '/model/var/domain/1600/subject/1/')) {
                return Http::response([
                    'status' => 'OK',
                    'data' => [['pages' => 1], [
                        ['var_id' => 11, 'title' => $title, 'unit' => 'Persen', 'vertical' => 8],
                        ['var_id' => 12, 'title' => 'Persentase Penduduk Miskin Menurut Kecamatan', 'unit' => 'Persen', 'vertical' => 9],
                        ['var_id' => 13, 'title' => 'Persentase Penduduk Miskin Provinsi', 'unit' => 'Persen', 'vertical' => 2],
                        ['var_id' => 15, 'title' => 'Persentase Penduduk Miskin Ogan Komering Ulu Selatan', 'unit' => 'Persen', 'vertical' => 8],
                    ]],
                ]);
            }
            if (str_contains($url, '/model/var/domain/1600/subject/2/')) {
                return Http::response([
                    'status' => 'OK',
                    'data' => [['pages' => 1], [
                        ['var_id' => 14, 'title' => 'Indeks Pembangunan Manusia Menurut Kabupaten/Kota', 'unit' => 'Indeks', 'vertical' => 8],
                    ]],
                ]);
            }
            if (str_contains($url, '/model/th/domain/1600/var/11/')
                || str_contains($url, '/model/th/domain/1600/var/15/')
                || str_contains($url, '/model/th/domain/1600/var/608/')) {
                return Http::response(['status' => 'OK', 'data' => [[], [['th' => '2026', 'th_id' => 126]]]]);
            }
            if (str_contains($url, '/model/data/domain/1600/var/11/')) {
                return Http::response([
                    'status' => 'OK',
                    'var' => [['val' => 11, 'label' => $title, 'unit' => 'Persen']],
                    'vervar' => [
                        ['val' => 1671, 'label' => 'Kota Palembang'],
                        ['val' => 1601, 'label' => 'Kabupaten Ogan Komering Ulu'],
                    ],
                    'datacontent' => ['167111126' => 3.2, '160111126' => 3.4],
                ]);
            }

            return Http::response(['status' => 'ERROR'], 404);
        });

        $service = app(BpsWebApiService::class);
        $summary = $service->refreshVariableIndex();
        $context = $service->contextFor('persentase penduduk miskin di Palembang tahun 2026');
        $typoContext = $service->contextFor('persentse penduduk miskin di Palembang tahun 2026');
        $synonymContext = $service->contextFor('kemiskinan penduduk di Palembang tahun 2026');
        $okuContext = $service->contextFor('persentase penduduk miskin Ogan Komering Ulu tahun 2026');

        $this->assertSame(5, $summary['count']);
        $this->assertArrayNotHasKey('vertical_levels', $summary);
        $indexedVariables = collect(Cache::get('bps-webapi:variables:index:1600')['variables'])->keyBy('var_id');
        $this->assertSame('kab_kota', $indexedVariables['11']['level']);
        $this->assertSame('subdistrict', $indexedVariables['12']['level']);
        $this->assertSame('province', $indexedVariables['13']['level']);
        $this->assertSame('unknown', $indexedVariables['15']['level']);
        $this->assertSame(['Kemiskinan', 'Pembangunan Manusia'], $summary['vertical_evidence'][8]['kab_kota']);
        $this->assertStringContainsString('"indikator":"'.$title.'"', $context);
        $this->assertStringContainsString('"indikator":"'.$title.'"', $typoContext);
        $this->assertStringContainsString('"indikator":"'.$title.'"', $synonymContext);
        $this->assertStringContainsString('"level":"kab_kota"', $context);
        $this->assertStringContainsString('"nilai":{"160111126":3.4}', $okuContext);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/model/data/domain/1600/var/11/th/126/'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/model/data/domain/1600/var/608/'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/model/data/domain/1600/var/15/'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/model/th/domain/1600/var/13/'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/model/th/domain/1600/var/12/'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/keyword/'));
    }

    public function test_curated_ipm_variable_uses_verified_var_id(): void
    {
        app(BpsWebApiService::class)->saveApiKey('bps-test-secret');
        Cache::flush();
        Carbon::setTestNow(Carbon::parse('2026-10-07 09:00:00', 'Asia/Jakarta'));
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, '/model/th/domain/1600/var/959/')) {
                return Http::response(['status' => 'OK', 'data' => [[], [['th' => '2025', 'th_id' => 2025]]]]);
            }
            if (str_contains($url, '/model/data/domain/1600/var/959/')) {
                return Http::response(['status' => 'OK', 'datacontent' => ['1600' => 75.5]]);
            }

            return Http::response(['status' => 'ERROR'], 404);
        });

        $context = app(BpsWebApiService::class)->contextFor('IPM terbaru');

        $this->assertStringContainsString('Indeks Pembangunan Manusia', $context);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/model/data/domain/1600/var/959/th/2025/'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/model/var/'));
        Carbon::setTestNow();
    }

    public function test_canonical_map_is_loaded_from_shared_json_and_lists_all_seventeen_regions(): void
    {
        $sharedMap = json_decode(
            file_get_contents(base_path('config/bps_indicators.json')),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertSame($sharedMap, config('bps_indicators'));
        $this->assertCount(17, config('sumsel_regions'));
        $this->assertSame(
            959,
            collect(config('bps_indicators.groups'))
                ->flatMap(fn (array $group) => $group['variables'])
                ->firstWhere('name', 'ipm')['var_id']
        );
    }

    public function test_canonical_coverage_checks_live_api_values_by_vervar_and_flags_missing_regions(): void
    {
        app(BpsWebApiService::class)->saveApiKey('bps-test-secret');
        Cache::flush();
        Carbon::setTestNow(Carbon::parse('2026-10-07 09:00:00', 'Asia/Jakarta'));
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, '/model/th/domain/1600/var/262/')) {
                return Http::response([
                    'status' => 'OK',
                    'data' => [[], [['th' => '2026', 'th_id' => 2026]]],
                ]);
            }
            if (str_contains($url, '/model/data/domain/1600/var/262/')) {
                return Http::response([
                    'status' => 'OK',
                    'vervar' => [
                        ['val' => 1671, 'label' => 'Kota Palembang'],
                        ['val' => 1600, 'label' => 'Sumatera Selatan'],
                    ],
                    'datacontent' => [
                        '16712622026' => 1900000,
                        '16002622026' => 9000000,
                    ],
                ]);
            }

            return Http::response(['status' => 'ERROR'], 404);
        });

        $service = app(BpsWebApiService::class);
        $observations = $service->checkCanonicalCoverage(['jumlah_penduduk']);
        $palembang = collect($observations)->firstWhere('region', 'Kota Palembang');
        $province = collect($observations)->firstWhere('region', 'Provinsi Sumatera Selatan');
        $missingRegion = collect($observations)->firstWhere('status', 'missing');
        $goldens = $service->verifyCanonicalGoldens([[
            'question' => 'Berapa jumlah penduduk Kota Palembang?',
            'indicator' => 'jumlah_penduduk',
            'expected_var_id' => 262,
            'region' => 'Kota Palembang',
            'expected_year' => null,
            'expected_value' => 1900000,
        ]]);

        $this->assertCount(18, $observations);
        $this->assertSame(2026, $palembang['latest_year']);
        $this->assertSame('current', $palembang['status']);
        $this->assertSame(9000000, $province['value']);
        $this->assertSame('missing', $missingRegion['status']);
        $this->assertTrue($goldens[0]['passed']);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/model/data/domain/1600/var/262/th/2026/'));
        Carbon::setTestNow();
    }

    public function test_latest_bps_indicator_uses_latest_available_period_when_current_period_is_missing(): void
    {
        app(BpsWebApiService::class)->saveApiKey('bps-test-secret');
        Cache::flush();
        Carbon::setTestNow(Carbon::parse('2026-10-07 09:00:00', 'Asia/Jakarta'));
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/model/th/domain/1600/var/959/')) {
                return Http::response(['status' => 'OK', 'data' => [[], [['th' => '2025', 'th_id' => 2025]]]]);
            }
            if (str_contains($request->url(), '/model/data/domain/1600/var/959/')) {
                return Http::response([
                    'status' => 'OK',
                    'var' => [['val' => 959, 'label' => 'Indeks Pembangunan Manusia', 'unit' => 'Indeks']],
                    'vervar' => [['val' => 1600, 'label' => 'Sumatera Selatan']],
                    'datacontent' => ['16009592025' => 75.5],
                ]);
            }

            return Http::response(['status' => 'ERROR'], 404);
        });

        $context = app(BpsWebApiService::class)->contextFor('IPM terbaru');

        $this->assertStringContainsString('[TAHUN_FALLBACK]', $context);
        $this->assertStringContainsString('"tahun":"2025"', $context);
        $this->assertStringContainsString('75.5', $context);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/model/data/domain/1600/var/959/th/2025/'));
        Carbon::setTestNow();
    }

    public function test_curated_population_variable_uses_verified_estimate_var_id(): void
    {
        app(BpsWebApiService::class)->saveApiKey('bps-test-secret');
        Cache::flush();
        Carbon::setTestNow(Carbon::parse('2026-10-07 09:00:00', 'Asia/Jakarta'));
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, '/model/th/domain/1600/var/262/')) {
                return Http::response(['status' => 'OK', 'data' => [[], [['th' => '2026', 'th_id' => 2026]]]]);
            }
            if (str_contains($url, '/model/data/domain/1600/var/262/')) {
                return Http::response([
                    'status' => 'OK',
                    'var' => [['val' => 262, 'label' => 'Jumlah Penduduk Menurut Kabupaten/Kota', 'unit' => 'Jiwa']],
                    'vervar' => [['val' => 1600, 'label' => 'Sumatera Selatan']],
                    'turvar' => [['val' => '0', 'label' => 'Tidak ada']],
                    'datacontent' => ['160026202026' => 9000000],
                ]);
            }

            return Http::response(['status' => 'ERROR'], 404);
        });

        $context = app(BpsWebApiService::class)->contextFor('berapa jumlah penduduk?');

        $this->assertStringContainsString('Jumlah Penduduk Menurut Kabupaten/Kota', $context);
        $this->assertStringContainsString('"sifat_data":"estimasi"', $context);
        $this->assertStringContainsString('"tahun":"2026"', $context);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/model/data/domain/1600/var/262/th/2026/'));
        Http::assertNotSent(fn ($request) => preg_match('/\/var\/(317|320|322)\//', $request->url()) === 1);
        Carbon::setTestNow();
    }

    public function test_explicit_population_projection_uses_the_projection_series(): void
    {
        app(BpsWebApiService::class)->saveApiKey('bps-test-secret');
        Cache::flush();
        Carbon::setTestNow(Carbon::parse('2026-10-07 09:00:00', 'Asia/Jakarta'));
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, '/model/th/domain/1600/var/51/')) {
                return Http::response(['status' => 'OK', 'data' => [[], [['th' => '2035', 'th_id' => 2035], ['th' => '2026', 'th_id' => 2026]]]]);
            }
            if (str_contains($url, '/model/data/domain/1600/var/51/')) {
                return Http::response([
                    'status' => 'OK',
                    'var' => [['val' => 51, 'label' => 'Proyeksi Jumlah Penduduk', 'unit' => 'Jiwa']],
                    'vervar' => [['val' => 1600, 'label' => 'Sumatera Selatan']],
                    'turvar' => [['val' => '38', 'label' => 'Laki-Laki + Perempuan']],
                    'datacontent' => ['160051382026' => 9707356],
                ]);
            }

            return Http::response(['status' => 'ERROR'], 404);
        });

        $context = app(BpsWebApiService::class)->contextFor('proyeksi jumlah penduduk');

        $this->assertStringContainsString('"sifat_data":"proyeksi"', $context);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/model/data/domain/1600/var/51/th/2026/'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/model/data/domain/1600/var/262/'));
        Carbon::setTestNow();
    }

    public function test_fallback_returns_population_total_for_a_requested_district_and_province(): void
    {
        app(BpsWebApiService::class)->saveApiKey('bps-test-secret');
        Cache::flush();
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, '/model/th/domain/1600/var/262/')) {
                return Http::response(['status' => 'OK', 'data' => [[], [['th' => '2026', 'th_id' => 126]]]]);
            }
            if (str_contains($url, '/model/data/domain/1600/var/262/')) {
                return Http::response([
                    'status' => 'OK',
                    'var' => [['val' => 262, 'label' => 'Jumlah Penduduk Menurut Kabupaten/Kota', 'unit' => 'Jiwa']],
                    'vervar' => [
                        ['val' => 1600, 'label' => 'Sumatera Selatan'],
                        ['val' => 1612, 'label' => 'Pali'],
                    ],
                    'turvar' => [['val' => '0', 'label' => 'Tidak ada']],
                    'datacontent' => [
                        '160026201260' => 9707356,
                        '161226201260' => 232044,
                    ],
                ]);
            }

            return Http::response(['status' => 'ERROR'], 404);
        });

        $regionalReply = app(BpsWebApiService::class)->fallbackAnswerFor('total warga PALI');
        $provinceReply = app(BpsWebApiService::class)->fallbackAnswerFor('total masyarakat');

        $this->assertStringContainsString('Kabupaten Penukal Abab Lematang Ilir tahun 2026', $regionalReply);
        $this->assertStringContainsString('Total: 232.044 Jiwa', $regionalReply);
        $this->assertStringContainsString('Provinsi Sumatera Selatan tahun 2026', $provinceReply);
        $this->assertStringContainsString('Total: 9.707.356 Jiwa', $provinceReply);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/model/data/domain/1600/var/262/'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/domain/1612/'));
    }

    public function test_fallback_returns_dynamic_indicator_value_for_a_requested_city(): void
    {
        app(BpsWebApiService::class)->saveApiKey('bps-test-secret');
        Cache::flush();
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, '/model/var/')) {
                return Http::response([
                    'status' => 'OK',
                    'data' => [['pages' => 1], [['var_id' => 42, 'title' => 'Produksi Ikan']]],
                ]);
            }
            if (str_contains($url, '/model/th/domain/1600/var/42/')) {
                return Http::response(['status' => 'OK', 'data' => [[], [['th' => '2025', 'th_id' => 2025]]]]);
            }
            if (str_contains($url, '/model/data/domain/1600/var/42/')) {
                return Http::response([
                    'status' => 'OK',
                    'var' => [['val' => 42, 'label' => 'Produksi Ikan', 'unit' => 'Ton']],
                    'vervar' => [['val' => 1671, 'label' => 'Palembang']],
                    'turvar' => [],
                    'datacontent' => ['16714220250' => 123],
                ]);
            }

            return Http::response(['status' => 'ERROR'], 404);
        });

        $reply = app(BpsWebApiService::class)->fallbackAnswerFor('berapa produksi ikan Palembang');

        $this->assertStringContainsString('Produksi Ikan di Kota Palembang tahun 2025', $reply);
        $this->assertStringContainsString('Nilai: 123 Ton', $reply);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/model/data/domain/1600/var/42/'));
    }

    public function test_fallback_searches_local_catalog_and_returns_latest_marital_status_series(): void
    {
        app(BpsWebApiService::class)->saveApiKey('bps-test-secret');
        Cache::flush();
        $titles = [
            794 => 'Penduduk Laki-Laki Berumur 10 Tahun Ke Atas Menurut Status Perkawinan',
            796 => 'Penduduk Perempuan Berumur 10 Tahun Ke Atas Menurut Status Perkawinan',
        ];
        Http::fake(function ($request) use ($titles) {
            $url = $request->url();
            foreach (array_keys($titles) as $variableId) {
                if (str_contains($url, "/model/th/domain/1600/var/{$variableId}/")) {
                    return Http::response([
                        'status' => 'OK',
                        'data' => [[], [['th' => '2025', 'th_id' => 126]]],
                    ]);
                }
                if (str_contains($url, "/model/data/domain/1600/var/{$variableId}/")) {
                    return Http::response([
                        'status' => 'OK',
                        'var' => [[
                            'val' => $variableId,
                            'label' => $titles[$variableId],
                            'unit' => 'Persen',
                        ]],
                        'vervar' => [['val' => 1600, 'label' => 'Sumatera Selatan']],
                        'turvar' => [['val' => 1, 'label' => 'Belum Kawin']],
                        'datacontent' => ['1600'.$variableId.'1'.'1260' => $variableId],
                    ]);
                }
            }

            return Http::response(['status' => 'ERROR'], 404);
        });

        $reply = app(BpsWebApiService::class)->fallbackAnswerFor(
            'status perkawinan di Sumatera Selatan tahun 2026'
        );

        $this->assertStringContainsString('Data 2026 belum tersedia', $reply);
        $this->assertStringContainsString('Data terbaru yang berhasil diverifikasi adalah tahun 2025', $reply);
        $this->assertStringContainsString('Pada penduduk laki-laki berumur 10 tahun ke atas tahun 2025', $reply);
        $this->assertStringContainsString('Pada penduduk perempuan berumur 10 tahun ke atas tahun 2025', $reply);
        $this->assertStringContainsString('belum kawin 794%', $reply);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/model/data/domain/1600/var/794/th/126/'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/model/data/domain/1600/var/796/th/126/'));
    }

    public function test_fallback_uses_a_configured_topic_for_a_requested_city(): void
    {
        app(BpsWebApiService::class)->saveApiKey('bps-test-secret');
        Cache::flush();
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, '/model/var/domain/1600/keyword/wisatawan/')) {
                return Http::response([
                    'status' => 'OK',
                    'data' => [['pages' => 1], [['var_id' => 88, 'title' => 'Jumlah Wisatawan Mancanegara']]],
                ]);
            }
            if (str_contains($url, '/model/th/domain/1600/var/88/')) {
                return Http::response(['status' => 'OK', 'data' => [[], [['th' => '2026', 'th_id' => 126]]]]);
            }
            if (str_contains($url, '/model/data/domain/1600/var/88/')) {
                return Http::response([
                    'status' => 'OK',
                    'var' => [['val' => 88, 'label' => 'Jumlah Wisatawan Mancanegara', 'unit' => 'Orang']],
                    'vervar' => [['val' => 1671, 'label' => 'Palembang']],
                    'turvar' => [],
                    'datacontent' => ['1671881260' => 12.5],
                ]);
            }

            return Http::response(['status' => 'ERROR'], 404);
        });

        $reply = app(BpsWebApiService::class)->fallbackAnswerFor('wisatawan Palembang');

        $this->assertStringContainsString('Jumlah Wisatawan Mancanegara di Kota Palembang tahun 2026', $reply);
        $this->assertStringContainsString('Nilai: 12,5 Orang', $reply);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/model/data/domain/1600/var/88/'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/model/pressrelease/'));
    }

    public function test_fallback_answers_multiple_topics_and_regions_with_latest_available_year(): void
    {
        app(BpsWebApiService::class)->saveApiKey('bps-test-secret');
        Cache::flush();
        Carbon::setTestNow(Carbon::parse('2026-10-07 09:00:00', 'Asia/Jakarta'));
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, '/model/th/domain/1600/var/604/')) {
                return Http::response(['status' => 'OK', 'data' => [[], [['th' => '2025', 'th_id' => 125]]]]);
            }
            if (str_contains($url, '/model/th/domain/1600/var/334/')) {
                return Http::response(['status' => 'OK', 'data' => [[], [['th' => '2025', 'th_id' => 125]]]]);
            }
            if (str_contains($url, '/model/data/domain/1600/var/604/')) {
                return Http::response([
                    'status' => 'OK',
                    'var' => [['val' => 604, 'label' => 'Persentase Penduduk Miskin', 'unit' => 'Persen']],
                    'vervar' => [
                        ['val' => 1612, 'label' => 'Pali'],
                        ['val' => 1671, 'label' => 'Palembang'],
                    ],
                    'turvar' => [],
                    'datacontent' => [
                        '1612604125' => 11.2,
                        '1671604125' => 8.4,
                    ],
                ]);
            }
            if (str_contains($url, '/model/data/domain/1600/var/334/')) {
                return Http::response([
                    'status' => 'OK',
                    'var' => [['val' => 334, 'label' => 'Tingkat Pengangguran', 'unit' => 'Persen']],
                    'vervar' => [['val' => 1600, 'label' => 'Sumatera Selatan']],
                    'turvar' => [],
                    'datacontent' => ['1600334125' => 4.2],
                ]);
            }
            if (str_contains($url, '/model/pressrelease/')) {
                return Http::response([
                    'status' => 'OK',
                    'data' => [['total' => 1, 'pages' => 1], [[
                        'title' => 'Kemiskinan dan TPT PALI Palembang',
                        'rl_date' => '2026-10-01',
                    ]]],
                ]);
            }

            return Http::response(['status' => 'ERROR'], 404);
        });

        $reply = app(BpsWebApiService::class)->fallbackAnswerFor(
            'persentase kemiskinan dan pengangguran PALI dan Palembang tahun 2026'
        );

        $this->assertStringContainsString('Kemiskinan dan ketimpangan Kabupaten Penukal Abab Lematang Ilir', $reply);
        $this->assertStringContainsString('Data 2026 belum dirilis. Data terbaru Kabupaten Penukal Abab Lematang Ilir (2025)', $reply);
        $this->assertStringContainsString('Data WebAPI BPS untuk Persentase Penduduk Miskin di Kabupaten Penukal Abab Lematang Ilir tahun 2025', $reply);
        $this->assertStringContainsString('Kemiskinan dan ketimpangan Kota Palembang', $reply);
        $this->assertStringContainsString('Ketenagakerjaan Kota Palembang', $reply);
        $this->assertStringContainsString('Indikator ini hanya tersedia pada tingkat Provinsi Sumatera Selatan', $reply);
        $this->assertStringContainsString('Data terbaru Provinsi Sumatera Selatan (2025)', $reply);
        $this->assertStringContainsString('Berita Resmi Statistik pelengkap:', $reply);
        Carbon::setTestNow();
    }

    public function test_fallback_diagnostic_command_reports_topics_variables_years_and_vervar(): void
    {
        app(BpsWebApiService::class)->saveApiKey('bps-test-secret');
        Cache::flush();
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/model/th/domain/1600/var/604/')) {
                return Http::response(['status' => 'OK', 'data' => [[], [['th' => '2025', 'th_id' => 125]]]]);
            }
            if (str_contains($request->url(), '/model/data/domain/1600/var/604/')) {
                return Http::response([
                    'status' => 'OK',
                    'vervar' => [['val' => 1612, 'label' => 'Pali']],
                    'datacontent' => ['1612604125' => 11.2],
                ]);
            }

            return Http::response(['status' => 'ERROR'], 404);
        });

        $this->artisan('bps:debug-fallback', ['question' => 'persentase kemiskinan PALI'])
            ->expectsOutputToContain('Kemiskinan dan ketimpangan / Kabupaten Penukal Abab Lematang Ilir')
            ->expectsOutputToContain('604')
            ->expectsOutputToContain('Score')
            ->assertExitCode(0);

        $diagnosis = app(BpsWebApiService::class)->diagnoseQuestion('persentase kemiskinan PALI');
        $this->assertSame('kab_kota', $diagnosis['combinations'][0]['variables'][0]['level']);
        $this->assertContains('2025', $diagnosis['combinations'][0]['variables'][0]['years']);
        $this->assertSame('Pali', $diagnosis['combinations'][0]['variables'][0]['matching_vervar']['label'] ?? null);
    }

    public function test_population_projection_is_not_used_for_disaggregated_population_questions(): void
    {
        app(BpsWebApiService::class)->saveApiKey('bps-test-secret');
        Cache::flush();
        Carbon::setTestNow(Carbon::parse('2026-10-07 09:00:00', 'Asia/Jakarta'));
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, '/model/th/domain/1600/var/262/')) {
                return Http::response(['status' => 'OK', 'data' => [[], [['th' => '2026', 'th_id' => 2026]]]]);
            }
            if (str_contains($url, '/model/data/domain/1600/var/262/')) {
                return Http::response([
                    'status' => 'OK',
                    'var' => [['val' => 262, 'label' => 'Jumlah Penduduk Menurut Kabupaten/Kota', 'unit' => 'Jiwa']],
                    'vervar' => [['val' => 1600, 'label' => 'Sumatera Selatan']],
                    'turvar' => [['val' => '0', 'label' => 'Tidak ada']],
                    'datacontent' => ['160026202026' => 9000000],
                ]);
            }

            return Http::response(['status' => 'ERROR'], 404);
        });

        $context = app(BpsWebApiService::class)->contextFor('jumlah penduduk menurut kabupaten kota');

        $this->assertStringContainsString('Jumlah Penduduk Menurut Kabupaten/Kota', $context);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/model/data/domain/1600/var/262/th/2026/'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/model/th/domain/1600/var/51/'));
        Carbon::setTestNow();
    }

    public function test_poverty_count_does_not_use_the_verified_poverty_percentage_variable(): void
    {
        app(BpsWebApiService::class)->saveApiKey('bps-test-secret');
        Cache::flush();
        Carbon::setTestNow(Carbon::parse('2026-10-07 09:00:00', 'Asia/Jakarta'));
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, '/model/th/domain/1600/var/157/')) {
                return Http::response(['status' => 'OK', 'data' => [[], [['th' => '2026', 'th_id' => 2026]]]]);
            }
            if (str_contains($url, '/model/data/domain/1600/var/157/')) {
                return Http::response([
                    'status' => 'OK',
                    'var' => [['val' => 157, 'label' => 'Jumlah Penduduk Miskin Maret', 'unit' => 'Ribu Jiwa']],
                    'vervar' => [['val' => 1600, 'label' => 'Sumatera Selatan']],
                    'datacontent' => ['16001572026' => 100],
                ]);
            }

            return Http::response(['status' => 'ERROR'], 404);
        });

        $context = app(BpsWebApiService::class)->contextFor('berapa jumlah penduduk miskin?');

        $this->assertStringContainsString('Jumlah Penduduk Miskin Maret', $context);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/model/data/domain/1600/var/157/th/2026/'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/model/th/domain/1600/var/608/'));
        Carbon::setTestNow();
    }

    public function test_historical_monthly_indicator_uses_dated_bps_press_release(): void
    {
        app(BpsWebApiService::class)->saveApiKey('bps-test-secret');
        Cache::flush();
        Http::fake(['webapi.bps.go.id/*' => Http::response([
            'status' => 'OK',
            'data' => [['total' => 1, 'pages' => 1], [[
                'title' => 'Inflasi Sumatera Selatan 2024',
                'rl_date' => '2024-03-01',
                'abstract' => 'Rilis inflasi periode 2024.',
            ]]],
        ])]);

        $context = app(BpsWebApiService::class)->contextFor('inflasi tahun 2024');

        $this->assertStringContainsString('"tanggal":"2024-03-01"', $context);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/model/pressrelease/')
            && str_contains($request->url(), '/year/2024/')
            && str_contains($request->url(), '/keyword/inflasi/'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/model/data/'));
    }

    public function test_monthly_latest_request_does_not_fall_back_when_brs_has_no_dated_release(): void
    {
        app(BpsWebApiService::class)->saveApiKey('bps-test-secret');
        Cache::flush();
        Carbon::setTestNow(Carbon::parse('2026-10-07 09:00:00', 'Asia/Jakarta'));
        Http::fake(['webapi.bps.go.id/*' => Http::response([
            'status' => 'OK',
            'data' => [['total' => 0, 'pages' => 1], []],
        ])]);

        $context = app(BpsWebApiService::class)->contextFor('pengangguran terbaru');

        $this->assertStringContainsString('BRS bertanggal', $context);
        $this->assertStringContainsString('2026', $context);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/model/pressrelease/')
            && str_contains($request->url(), '/year/2026/'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/model/data/'));
        Carbon::setTestNow();
    }

    public function test_latest_indicator_questions_are_forwarded_to_ai_without_laravel_bps_preflight(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $user->assignRole('user');
        Cache::flush();
        Http::fake(['*127.0.0.1:8001/api/chat*' => Http::sequence()
            ->push(['reply' => 'IPM Sumatera Selatan tahun 2025 sebesar 75,5.'], 200)
            ->push(['reply' => 'Data pengangguran terbaru sedang belum tersedia.'], 200)]);

        $this->actingAs($user)
            ->postJson(route('chatbot.message'), ['message' => 'IPM terbaru'])
            ->assertOk()
            ->assertJsonFragment(['reply' => 'IPM Sumatera Selatan tahun 2025 sebesar 75,5.']);

        $unemploymentResponse = $this->actingAs($user)
            ->postJson(route('chatbot.message'), ['message' => 'pengangguran terbaru'])
            ->assertOk();
        $this->assertSame('Data pengangguran terbaru sedang belum tersedia.', $unemploymentResponse->json('reply'));

        Http::assertSent(fn ($request) => str_contains($request->url(), '127.0.0.1:8001/api/chat')
            && in_array($request['question'], ['IPM terbaru', 'pengangguran terbaru'], true));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'webapi.bps.go.id'));
    }

    public function test_explicit_historical_ipm_year_remains_available(): void
    {
        app(BpsWebApiService::class)->saveApiKey('bps-test-secret');
        Cache::flush();
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, '/model/th/domain/1600/var/959/')) {
                return Http::response(['status' => 'OK', 'data' => [[], [['th' => '2025', 'th_id' => 2025]]]]);
            }
            if (str_contains($url, '/model/data/domain/1600/var/959/')) {
                return Http::response(['status' => 'OK', 'datacontent' => ['1600' => 75.5]]);
            }

            return Http::response(['status' => 'ERROR'], 404);
        });

        $context = app(BpsWebApiService::class)->contextFor('IPM tahun 2025');

        $this->assertStringContainsString('"tahun":"2025"', $context);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/model/data/domain/1600/var/959/th/2025/'));
    }

    public function test_static_tables_without_main_keyword_are_rejected(): void
    {
        app(BpsWebApiService::class)->saveApiKey('bps-test-secret');
        Cache::flush();
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, '/model/th/')) {
                return Http::response(['status' => 'OK', 'data' => [[], []]]);
            }
            if (str_contains($url, '/view/model/')) {
                return Http::response(['status' => 'OK', 'data' => ['table' => '<table><tr><td>Data penduduk valid</td></tr></table>']]);
            }
            if (str_contains($url, '/model/statictable/')) {
                return Http::response([
                    'status' => 'OK',
                    'data' => [[], [
                        ['table_id' => 1, 'title' => 'Jumlah Hotel'],
                        ['table_id' => 2, 'title' => 'Jumlah Penduduk'],
                    ]],
                ]);
            }

            return Http::response(['status' => 'ERROR'], 404);
        });

        $context = app(BpsWebApiService::class)->contextFor('jumlah penduduk');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/model/statictable/domain/1600/keyword/penduduk/'));
        $this->assertStringContainsString('Data penduduk valid', $context);
        $this->assertStringNotContainsString('Jumlah Hotel', $context);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/view/model/statictable/') && str_contains($request->url(), '/2/'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/view/model/statictable/') && str_contains($request->url(), '/1/'));
    }

    public function test_upah_uses_a_static_table_before_monthly_releases(): void
    {
        app(BpsWebApiService::class)->saveApiKey('bps-test-secret');
        Cache::flush();
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, '/view/model/statictable/')) {
                return Http::response([
                    'status' => 'OK',
                    'data' => ['table' => '<table><tr><td>Tahun</td><td>Upah</td></tr><tr><td>2025</td><td>3.500.000</td></tr></table>'],
                ]);
            }
            if (str_contains($url, '/model/statictable/')) {
                $tables = str_contains($url, '/keyword/upah/')
                    ? [['table_id' => 42, 'title' => 'Rata-rata Upah Buruh Sumatera Selatan']]
                    : [];

                return Http::response(['status' => 'OK', 'data' => [['pages' => 1], $tables]]);
            }

            return Http::response(['status' => 'ERROR'], 404);
        });

        $context = app(BpsWebApiService::class)->contextFor('Berapa upah terbaru?');

        $this->assertStringContainsString('Rata-rata Upah Buruh Sumatera Selatan', $context);
        $this->assertStringContainsString('3.500.000', $context);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/model/statictable/domain/1600/keyword/upah/'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/model/pressrelease/'));
    }

    public function test_dynamic_bps_context_prefers_annual_variable_for_per_tahun_question(): void
    {
        app(BpsWebApiService::class)->saveApiKey('bps-test-secret');
        Cache::flush();
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, '/model/var/')) {
                return Http::response([
                    'status' => 'OK',
                    'data' => [['pages' => 1], [
                        ['var_id' => 31, 'title' => 'Pertumbuhan PDRB Triwulan'],
                        ['var_id' => 32, 'title' => 'Pertumbuhan PDRB Tahunan'],
                    ]],
                ]);
            }
            if (str_contains($url, '/model/th/')) {
                return Http::response(['status' => 'OK', 'data' => [[], [['th' => '2025', 'th_id' => 2025]]]]);
            }
            if (str_contains($url, '/model/data/')) {
                return Http::response(['status' => 'OK', 'datacontent' => ['1600' => 123]]);
            }

            return Http::response(['status' => 'ERROR'], 404);
        });

        $context = app(BpsWebApiService::class)->contextFor('pertumbuhan pdrb per tahun');

        $this->assertStringContainsString('Laju Pertumbuhan Produk Domestik Regional Bruto (PDRB) Tahunan', $context);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/model/data/domain/1600/var/862/th/2025/'));
    }

    public function test_dynamic_bps_context_rejects_data_without_the_requested_region_label(): void
    {
        app(BpsWebApiService::class)->saveApiKey('bps-test-secret');
        Cache::flush();
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, '/model/var/')) {
                return Http::response([
                    'status' => 'OK',
                    'data' => [['pages' => 1], [['var_id' => 41, 'title' => 'Produksi Ikan']]],
                ]);
            }
            if (str_contains($url, '/model/th/')) {
                return Http::response(['status' => 'OK', 'data' => [[], [['th' => '2025', 'th_id' => 2025]]]]);
            }
            if (str_contains($url, '/model/data/')) {
                return Http::response([
                    'status' => 'OK',
                    'datacontent' => ['1671' => 123],
                    'vervar' => [['label' => 'Kabupaten Lahat']],
                ]);
            }

            return Http::response(['status' => 'ERROR'], 404);
        });

        $context = app(BpsWebApiService::class)->contextFor('berapa jumlah produksi ikan Palembang');

        $this->assertStringContainsString('[WILAYAH_TIDAK_TERSEDIA]', $context);
        $this->assertStringContainsString('tidak tersedia pada indikator Produksi Ikan', $context);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/domain/1600/'));
    }

    public function test_dynamic_bps_context_matches_multiword_region_alias_and_removes_it_from_search_terms(): void
    {
        app(BpsWebApiService::class)->saveApiKey('bps-test-secret');
        Cache::flush();
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, '/model/var/')) {
                return Http::response([
                    'status' => 'OK',
                    'data' => [['pages' => 1], [['var_id' => 42, 'title' => 'Produksi Ikan']]],
                ]);
            }
            if (str_contains($url, '/model/th/domain/1600/var/42/')) {
                return Http::response(['status' => 'OK', 'data' => [[], [['th' => '2025', 'th_id' => 2025]]]]);
            }
            if (str_contains($url, '/model/data/domain/1600/var/42/')) {
                return Http::response([
                    'status' => 'OK',
                    'datacontent' => ['16084220250' => 123],
                    'vervar' => [['val' => 1608, 'label' => 'Kabupaten Ogan Komering Ulu Selatan']],
                ]);
            }

            return Http::response(['status' => 'ERROR'], 404);
        });

        $context = app(BpsWebApiService::class)->contextFor('berapa produksi ikan OKU Selatan');

        $this->assertStringContainsString('Produksi Ikan', $context);
        $this->assertStringContainsString('"nilai":{"16084220250":123}', $context);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/model/var/')
            && str_contains($request->url(), '/keyword/produksi/')
            && ! str_contains($request->url(), 'oku')
            && ! str_contains($request->url(), 'selatan'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/model/data/domain/1600/var/42/'));
    }

    public function test_dynamic_bps_context_does_not_match_a_region_name_by_prefix(): void
    {
        app(BpsWebApiService::class)->saveApiKey('bps-test-secret');
        Cache::flush();
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, '/model/var/')) {
                return Http::response([
                    'status' => 'OK',
                    'data' => [['pages' => 1], [['var_id' => 43, 'title' => 'Produksi Ikan']]],
                ]);
            }
            if (str_contains($url, '/model/th/domain/1600/var/43/')) {
                return Http::response(['status' => 'OK', 'data' => [[], [['th' => '2025', 'th_id' => 2025]]]]);
            }
            if (str_contains($url, '/model/data/domain/1600/var/43/')) {
                return Http::response([
                    'status' => 'OK',
                    'datacontent' => ['1600' => 123],
                    'vervar' => [['label' => 'Kabupaten Ogan Komering Ulu Selatan']],
                ]);
            }

            return Http::response(['status' => 'ERROR'], 404);
        });

        $context = app(BpsWebApiService::class)->contextFor('berapa produksi ikan Ogan Komering Ulu');

        $this->assertStringContainsString('[WILAYAH_TIDAK_TERSEDIA]', $context);
        $this->assertStringContainsString('tidak tersedia pada indikator Produksi Ikan', $context);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/model/data/') && str_contains($request->url(), '/var/43/'));
    }

    public function test_admin_can_confirm_assign_and_complete_a_consultation(): void
    {
        $meeting = $this->createMeeting([
            'tanggal' => '2026-10-15',
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
        ]);

        $this->actingAs($this->admin)
            ->patch(route('admin.konsultasi.status', $meeting), [
                'action' => 'confirm',
                'assigned_staff' => 'Petugas 1',
            ])
            ->assertRedirect()
            ->assertSessionHas('message');

        $this->assertDatabaseHas('meeting', ['id' => $meeting->id, 'status' => 2, 'assigned_staff' => 'Petugas 1']);

        $this->actingAs($this->admin)
            ->patch(route('admin.konsultasi.status', $meeting), [
                'action' => 'complete',
                'ringkasan' => 'Penjelasan metodologi dan tindak lanjut sudah diberikan.',
                'documentation_url' => 'https://example.org/dokumentasi',
            ])
            ->assertRedirect()
            ->assertSessionHas('message');

        $this->assertDatabaseHas('meeting', ['id' => $meeting->id, 'status' => 1, 'assigned_staff' => 'Petugas 1']);
    }

    public function test_a_staff_member_cannot_be_assigned_to_overlapping_confirmed_meetings(): void
    {
        $this->createMeeting([
            'tanggal' => '2026-10-15',
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
            'status' => 2,
            'assigned_staff' => 'Petugas 1',
        ]);
        $pending = $this->createMeeting([
            'tanggal' => '2026-10-15',
            'start_time' => '09:30:00',
            'end_time' => '10:30:00',
        ]);

        $this->actingAs($this->admin)
            ->from(route('admin.konsultasi'))
            ->patch(route('admin.konsultasi.status', $pending), [
                'action' => 'confirm',
                'assigned_staff' => 'Petugas 1',
            ])
            ->assertRedirect(route('admin.konsultasi'))
            ->assertSessionHasErrors('assigned_staff');

        $this->assertDatabaseHas('meeting', ['id' => $pending->id, 'status' => 0, 'assigned_staff' => null]);
    }

    public function test_user_can_open_and_complete_their_profile(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $user->assignRole('user');
        /** @var Authenticatable $authenticatedUser */
        $authenticatedUser = $user;

        $this->actingAs($authenticatedUser)->get(route('profile'))->assertOk()->assertSee('Profil Saya')->assertSee('Katalog Publikasi');

        $this->actingAs($authenticatedUser)->put(route('profile.update', $user), [
            'name' => $user->name,
            'pekerjaan' => 'Pelajar/Mahasiswa',
            'jenis_kelamin' => '1',
            'tanggal_lahir' => '2000-01-01',
            'asal_prov' => 'Sumatera Selatan',
            'asal_kab' => 'Palembang',
            'no_hp' => '08123456789',
            'pendidikan' => 'S1 Statistik',
        ])->assertRedirect('/')->assertSessionHas('message');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'asal_kab' => 'Palembang', 'pendidikan' => 'S1 Statistik']);
    }

    public function test_user_can_submit_a_future_consultation_request(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $user->assignRole('user');
        /** @var Authenticatable $authenticatedUser */
        $authenticatedUser = $user;

        $this->actingAs($authenticatedUser)->get('/konsultasi')->assertOk()->assertSee('Jadwal Mendatang')->assertSee('Buat konsultasi');
        $this->actingAs($authenticatedUser)->post('/konsultasi', [
            'name' => 'Metodologi Statistik',
            'deskripsi' => 'Saya memerlukan penjelasan metodologi untuk penelitian.',
            'tanggal' => '2027-01-15',
            'waktu_mulai' => '09:00',
            'waktu_selesai' => '10:00',
        ])->assertRedirect()->assertSessionHas('message');
        $this->assertDatabaseHas('meeting', ['user_id' => $user->id, 'status' => 0, 'name' => 'Metodologi Statistik']);

        $this->assertDatabaseHas('meeting', ['user_id' => $user->id, 'status' => 0, 'name' => 'Metodologi Statistik']);
    }

    public function test_user_chatbot_screen_uses_the_user_portal(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $user->assignRole('user');
        /** @var Authenticatable $authenticatedUser */
        $authenticatedUser = $user;

        $this->actingAs($authenticatedUser)
            ->get('/chatbot')
            ->assertOk()
            ->assertSee('Konsultasi Chatbot')
            ->assertSee('Katalog Publikasi')
            ->assertSee('AI dapat keliru. Periksa kembali informasi penting melalui sumber resmi.');
    }

    public function test_user_chatbot_saves_and_reopens_conversation_turns(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $user->assignRole('user');
        /** @var Authenticatable $authenticatedUser */
        $authenticatedUser = $user;
        Http::fake(['*127.0.0.1:8001/api/chat*' => Http::sequence()
            ->push(['data' => 'Jawaban chatbot.'], 200)
            ->push(['data' => 'Contoh jawaban chatbot.'], 200)]);

        $firstResponse = $this->actingAs($authenticatedUser)
            ->postJson(route('chatbot.message'), ['message' => 'Apa kegunaan layanan konsultasi?'])
            ->assertOk()
            ->assertJsonPath('reply', 'Jawaban chatbot.')
            ->assertJsonPath('title', 'Apa kegunaan layanan konsultasi?');
        $conversationId = $firstResponse->json('conversation_id');

        $this->postJson(route('chatbot.message'), [
            'message' => 'Bisa beri contoh?',
            'conversation_id' => $conversationId,
        ])->assertOk();

        $this->assertDatabaseCount('chatbot_messages', 2);
        $this->getJson(route('chatbot.conversation', $conversationId))
            ->assertOk()
            ->assertJsonCount(2, 'messages')
            ->assertJsonPath('messages.0.prompt', 'Apa kegunaan layanan konsultasi?')
            ->assertJsonPath('messages.1.response', 'Contoh jawaban chatbot.');
        $this->get('/chatbot')->assertOk()->assertSee('Apa kegunaan layanan konsultasi?');

        $conversation = DB::table('chatbot_conversations')->where('id', $conversationId)->first();
        $this->assertNotSame((string) $user->id, $conversation->session_key);
        Http::assertSent(fn ($request) => $request['session_id'] === $conversation->session_key);
        Http::assertSent(fn ($request) => $request['question'] === 'Bisa beri contoh?'
            && $request['history'] === [[
                'prompt' => 'Apa kegunaan layanan konsultasi?',
                'response' => 'Jawaban chatbot.',
            ]]);
    }

    public function test_chatbot_sends_data_questions_to_ai_without_laravel_bps_context(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $user->assignRole('user');
        Http::fake(['*127.0.0.1:8001/api/chat*' => Http::response([
            'reply' => 'Jawaban AI untuk pertumbuhan ekonomi.',
        ], 200)]);

        $this->actingAs($user)
            ->postJson(route('chatbot.message'), ['message' => 'Berapa laju pertumbuhan ekonomi?'])
            ->assertOk()
            ->assertJsonPath('reply', 'Jawaban AI untuk pertumbuhan ekonomi.');

        Http::assertSent(fn ($request) => str_contains($request->url(), '127.0.0.1:8001/api/chat')
            && $request['question'] === 'Berapa laju pertumbuhan ekonomi?');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'webapi.bps.go.id'));
    }

    public function test_chatbot_allows_general_statistical_concepts_through_to_ai(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $user->assignRole('user');
        Http::fake(['*127.0.0.1:8001/api/chat*' => Http::response([
            'reply' => 'Korelasi menggambarkan hubungan antara dua variabel.',
        ], 200)]);

        $this->actingAs($user)
            ->postJson(route('chatbot.message'), ['message' => 'Apa arti korelasi dalam statistik?'])
            ->assertOk()
            ->assertJsonPath('reply', 'Korelasi menggambarkan hubungan antara dua variabel.')
            ->assertJsonPath('safety_blocked', false);

        Http::assertSent(fn ($request) => str_contains($request->url(), '127.0.0.1:8001/api/chat')
            && $request['question'] === 'Apa arti korelasi dalam statistik?');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'webapi.bps.go.id'));
    }

    public function test_concept_answer_uses_uploaded_knowledge_when_python_is_unavailable(): void
    {
        Cache::flush();
        $question = 'Apa perbedaan PDRB ADHB dan ADHK?';
        $glossary = '[Sumber: Glosarium PDRB] ADHB menggunakan harga yang berlaku; ADHK menggunakan harga konstan.';
        $knowledge = \Mockery::mock(ChatbotKnowledgeService::class);
        $knowledge->shouldReceive('contextFor')->once()->with($question)->andReturn($glossary);
        $knowledge->shouldReceive('askWithContext')
            ->once()
            ->andReturn(new \Illuminate\Http\Client\Response(new \GuzzleHttp\Psr7\Response(503, [], '{"error":"busy"}')));
        $this->app->instance(ChatbotKnowledgeService::class, $knowledge);

        /** @var User $user */
        $user = User::factory()->create();
        $user->assignRole('user');
        $response = $this->actingAs($user)
            ->postJson(route('chatbot.message'), ['message' => $question])
            ->assertOk()
            ->assertJsonPath('knowledge_used', true);

        $this->assertStringContainsString('ADHB menggunakan harga yang berlaku', $response->json('reply'));
        $this->assertStringContainsString('ADHK menggunakan harga konstan', $response->json('reply'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'webapi.bps.go.id'));
    }

    public function test_concept_answer_returns_honest_notice_when_no_glossary_or_python_is_available(): void
    {
        Cache::flush();
        $question = 'Apa perbedaan PDRB ADHB dan ADHK?';
        $knowledge = \Mockery::mock(ChatbotKnowledgeService::class);
        $knowledge->shouldReceive('contextFor')->once()->with($question)->andReturn(null);
        $knowledge->shouldReceive('askWithContext')
            ->once()
            ->andReturn(new \Illuminate\Http\Client\Response(new \GuzzleHttp\Psr7\Response(503, [], '{"error":"busy"}')));
        $this->app->instance(ChatbotKnowledgeService::class, $knowledge);

        /** @var User $user */
        $user = User::factory()->create();
        $user->assignRole('user');
        $this->actingAs($user)
            ->postJson(route('chatbot.message'), ['message' => $question])
            ->assertOk()
            ->assertJsonPath(
                'reply',
                'Maaf, penjelasan konsep tersebut belum tersedia pada basis pengetahuan saat ini. Silakan coba lagi setelah referensi yang sesuai tersedia.'
            )
            ->assertJsonPath('knowledge_used', false);
    }

    public function test_chatbot_sends_wage_questions_to_ai_without_bps_api_key(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $user->assignRole('user');
        Http::fake(['*127.0.0.1:8001/api/chat*' => Http::response([
            'reply' => 'Jawaban AI tentang upah terbaru.',
        ], 200)]);

        $response = $this->actingAs($user)
            ->postJson(route('chatbot.message'), ['message' => 'Berapa upah terbaru?'])
            ->assertOk();
        $this->assertSame('Jawaban AI tentang upah terbaru.', $response->json('reply'));

        Http::assertSent(fn ($request) => str_contains($request->url(), '127.0.0.1:8001/api/chat'));
    }

    public function test_chatbot_preserves_ai_reply_even_when_it_mentions_a_busy_service(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $user->assignRole('user');
        Cache::flush();
        Http::fake(function ($request) {
            if (str_contains($request->url(), '127.0.0.1:8001/api/chat')) {
                return Http::response(['reply' => 'Saat ini chatbot BPS Sumsel sedang menerima banyak permintaan. Coba lagi nanti.'], 200);
            }
            return Http::response(['status' => 'ERROR'], 404);
        });

        $response = $this->actingAs($user)
            ->postJson(route('chatbot.message'), ['message' => 'inflasi bulan ini'])
            ->assertOk()
            ->assertJsonPath('reply', 'Saat ini chatbot BPS Sumsel sedang menerima banyak permintaan. Coba lagi nanti.');

        Http::assertSent(fn ($request) => str_contains($request->url(), '127.0.0.1:8001/api/chat'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'webapi.bps.go.id'));
    }

    public function test_gini_fallback_returns_the_matching_brs_without_poverty_candidates(): void
    {
        app(BpsWebApiService::class)->saveApiKey('bps-test-secret');
        Cache::flush();
        Http::fake(['webapi.bps.go.id/*' => Http::response([
            'status' => 'OK',
            'data' => [['total' => 1, 'pages' => 1], [[
                'title' => 'Gini Ratio Maret 2026 tercatat sebesar 0,285',
                'rl_date' => '2026-08-05',
            ]]],
        ])]);

        $reply = app(BpsWebApiService::class)->fallbackAnswerFor('Gini Sumsel terbaru');

        $this->assertStringContainsString('Gini Ratio Maret 2026 tercatat sebesar 0,285', $reply);
        $this->assertStringNotContainsString('kemiskinan:', mb_strtolower($reply));
        $this->assertStringNotContainsString('Belum dapat dipastikan indikator yang dimaksud', $reply);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/model/pressrelease/'));
    }

    public function test_unverified_gini_ai_reply_is_replaced_with_only_the_gini_release(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $user->assignRole('user');
        app(BpsWebApiService::class)->saveApiKey('bps-test-secret');
        Cache::flush();
        $aiReply = 'Indikator Gini dan kemiskinan yang dimaksud belum dapat dipastikan.';
        Http::fake(function ($request) use ($aiReply) {
            $url = $request->url();
            if (str_contains($url, '127.0.0.1:8001/api/chat')) {
                return Http::response(['reply' => $aiReply], 200);
            }
            if (str_contains($url, '/model/pressrelease/')) {
                return Http::response([
                    'status' => 'OK',
                    'data' => [['total' => 1, 'pages' => 1], [[
                        'title' => 'Gini Ratio Maret 2026 tercatat sebesar 0,285',
                        'rl_date' => '2026-08-05',
                    ]]],
                ]);
            }

            return Http::response(['status' => 'ERROR'], 404);
        });

        $response = $this->actingAs($user)
            ->postJson(route('chatbot.message'), ['message' => 'Gini Sumsel terbaru'])
            ->assertOk();

        $reply = $response->json('reply');
        $this->assertStringContainsString('Saya periksa kembali menggunakan data resmi BPS:', $reply);
        $this->assertStringContainsString('Gini Ratio Maret 2026 tercatat sebesar 0,285', $reply);
        $this->assertStringNotContainsString('Jawaban AI tidak sesuai', $reply);
        $this->assertStringNotContainsString('Persentase Penduduk Miskin', $reply);
    }

    public function test_fallback_uses_general_brs_intro_when_no_topic_matches(): void
    {
        app(BpsWebApiService::class)->saveApiKey('bps-test-secret');
        Cache::flush();
        Carbon::setTestNow(Carbon::parse('2026-10-07 09:00:00', 'Asia/Jakarta'));
        Http::fake(['webapi.bps.go.id/*' => Http::response([
            'status' => 'OK',
            'data' => [['total' => 1, 'pages' => 1], [[
                'title' => 'Perkembangan Statistik Sumatera Selatan',
                'rl_date' => '2026-10-02',
            ]]],
        ])]);

        $reply = app(BpsWebApiService::class)->fallbackAnswerFor('Apa yang baru di Palembang?');

        $this->assertStringContainsString(config('chatbot_fallback.general_intro'), $reply);
        $this->assertStringContainsString('Perkembangan Statistik Sumatera Selatan (rilis: 2026-10-02)', $reply);
        $this->assertStringContainsString(str_replace('{region}', 'Kota Palembang', config('chatbot_fallback.region_note')), $reply);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/model/pressrelease/')
            && ! str_contains($request->url(), '/keyword/'));
        Carbon::setTestNow();
    }

    public function test_regional_fallback_uses_the_district_press_release_domain_after_dynamic_data_misses(): void
    {
        app(BpsWebApiService::class)->saveApiKey('bps-test-secret');
        Cache::flush();
        Carbon::setTestNow(Carbon::parse('2026-10-07 09:00:00', 'Asia/Jakarta'));
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, '/model/var/domain/1600/keyword/inflasi/')) {
                return Http::response(['status' => 'ERROR'], 404);
            }
            if (str_contains($url, '/model/pressrelease/domain/1671/')) {
                return Http::response([
                    'status' => 'OK',
                    'data' => [['total' => 1, 'pages' => 1], [[
                        'title' => 'Inflasi Kota Palembang',
                        'rl_date' => '2026-10-02',
                    ]]],
                ]);
            }

            return Http::response(['status' => 'ERROR'], 404);
        });

        $reply = app(BpsWebApiService::class)->fallbackAnswerFor('inflasi di Palembang');

        $intro = str_replace(
            ['{region}', '{topic}'],
            ['Kota Palembang', 'inflasi'],
            config('chatbot_fallback.regional_brs_intro')
        );
        $this->assertStringContainsString($intro, $reply);
        $this->assertStringContainsString('Inflasi Kota Palembang (rilis: 2026-10-02)', $reply);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/model/pressrelease/domain/1671/'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/model/pressrelease/domain/1600/'));
        Carbon::setTestNow();
    }

    public function test_regional_fallback_uses_a_provincial_release_that_mentions_the_requested_city(): void
    {
        app(BpsWebApiService::class)->saveApiKey('bps-test-secret');
        Cache::flush();
        Carbon::setTestNow(Carbon::parse('2026-10-07 09:00:00', 'Asia/Jakarta'));
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, '/model/var/domain/1600/keyword/inflasi/')) {
                return Http::response(['status' => 'ERROR'], 404);
            }
            if (str_contains($url, '/model/pressrelease/domain/1671/')) {
                return Http::response(['status' => 'OK', 'data' => [['total' => 0, 'pages' => 1], []]]);
            }
            if (str_contains($url, '/model/pressrelease/domain/1600/')) {
                return Http::response([
                    'status' => 'OK',
                    'data' => [['total' => 1, 'pages' => 1], [[
                        'title' => 'Inflasi Gabungan Palembang dan Lubuklinggau',
                        'rl_date' => '2026-10-02',
                    ]]],
                ]);
            }

            return Http::response(['status' => 'ERROR'], 404);
        });

        $reply = app(BpsWebApiService::class)->fallbackAnswerFor('inflasi di Palembang');

        $intro = str_replace(
            ['{region}', '{topic}'],
            ['Kota Palembang', 'inflasi'],
            config('chatbot_fallback.regional_province_brs_intro')
        );
        $this->assertStringContainsString($intro, $reply);
        $this->assertStringContainsString('Inflasi Gabungan Palembang dan Lubuklinggau (rilis: 2026-10-02)', $reply);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/model/pressrelease/domain/1671/'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/model/pressrelease/domain/1600/'));
        Carbon::setTestNow();
    }

    public function test_regional_fallback_uses_provincial_topic_releases_with_a_region_note_as_last_resort(): void
    {
        app(BpsWebApiService::class)->saveApiKey('bps-test-secret');
        Cache::flush();
        Carbon::setTestNow(Carbon::parse('2026-10-07 09:00:00', 'Asia/Jakarta'));
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, '/model/var/domain/1600/keyword/inflasi/')) {
                return Http::response(['status' => 'ERROR'], 404);
            }
            if (str_contains($url, '/model/pressrelease/domain/1671/')) {
                return Http::response(['status' => 'OK', 'data' => [['total' => 0, 'pages' => 1], []]]);
            }
            if (str_contains($url, '/model/pressrelease/domain/1600/')) {
                return Http::response([
                    'status' => 'OK',
                    'data' => [['total' => 1, 'pages' => 1], [[
                        'title' => 'Inflasi Sumatera Selatan',
                        'rl_date' => '2026-10-02',
                    ]]],
                ]);
            }

            return Http::response(['status' => 'ERROR'], 404);
        });

        $reply = app(BpsWebApiService::class)->fallbackAnswerFor('inflasi di Palembang');

        $this->assertStringContainsString('Berita Resmi Statistik terbaru tentang inflasi:', $reply);
        $this->assertStringContainsString('Inflasi Sumatera Selatan (rilis: 2026-10-02)', $reply);
        $this->assertStringContainsString(str_replace('{region}', 'Kota Palembang', config('chatbot_fallback.region_note')), $reply);
        Carbon::setTestNow();
    }

    public function test_chatbot_replaces_ai_http_error_with_fallback_instead_of_returning_502(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $user->assignRole('user');
        Http::fake(['*127.0.0.1:8001/api/chat*' => Http::response(['error' => 'busy'], 503)]);

        $this->actingAs($user)
            ->postJson(route('chatbot.message'), ['message' => 'Apa manfaat layanan konsultasi?'])
            ->assertOk()
            ->assertJsonPath('reply', config('chatbot_fallback.notice')."\n\nData terbaru belum dapat diambil karena API key WebAPI BPS belum dikonfigurasi.\n\n".config('chatbot_fallback.closing'));
    }

    public function test_regional_variable_lookup_failure_continues_to_ai(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $user->assignRole('user');
        app(BpsWebApiService::class)->saveApiKey('bps-test-secret');
        Cache::flush();
        Http::fake(function ($request) {
            if (str_contains($request->url(), '127.0.0.1:8001/api/chat')) {
                return Http::response(['data' => 'Jawaban AI untuk pertanyaan Palembang.'], 200);
            }

            return Http::response(['status' => 'ERROR'], 404);
        });

        $this->actingAs($user)
            ->postJson(route('chatbot.message'), ['message' => 'inflasi di Palembang'])
            ->assertOk()
            ->assertJsonPath('reply', 'Jawaban AI untuk pertanyaan Palembang.');

        Http::assertSent(fn ($request) => str_contains($request->url(), '127.0.0.1:8001/api/chat'));
    }

    public function test_chatbot_uses_existing_template_when_ai_is_rate_limited(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $user->assignRole('user');
        Cache::flush();
        Http::fake(['*127.0.0.1:8001/api/chat*' => Http::response(['error' => 'rate limited'], 429)]);

        $response = $this->actingAs($user)
            ->postJson(route('chatbot.message'), ['message' => 'berapa jumlah produksi ikan tahun 2026'])
            ->assertOk();

        $this->assertStringStartsWith(config('chatbot_fallback.notice'), $response->json('reply'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '127.0.0.1:8001/api/chat'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'webapi.bps.go.id'));
    }

    public function test_chatbot_replaces_ai_connection_failure_with_fallback(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $user->assignRole('user');
        Http::fake(['*127.0.0.1:8001/api/chat*' => Http::failedConnection()]);

        $this->actingAs($user)
            ->postJson(route('chatbot.message'), ['message' => 'Apa manfaat layanan konsultasi?'])
            ->assertOk()
            ->assertJsonPath('reply', config('chatbot_fallback.notice')."\n\nData terbaru belum dapat diambil karena API key WebAPI BPS belum dikonfigurasi.\n\n".config('chatbot_fallback.closing'));
    }

    public function test_admin_chatbot_test_uses_fallback_when_ai_is_unavailable(): void
    {
        Http::fake(['*127.0.0.1:8001/api/chat*' => Http::response(['error' => 'busy'], 503)]);

        $this->actingAs($this->admin)
            ->postJson(route('admin.chatbot.test'), ['message' => 'Apa manfaat layanan konsultasi?'])
            ->assertOk()
            ->assertJsonPath('reply', config('chatbot_fallback.notice')."\n\nData terbaru belum dapat diambil karena API key WebAPI BPS belum dikonfigurasi.\n\n".config('chatbot_fallback.closing'));
    }

    public function test_successful_ai_reply_is_cached_for_twenty_minutes(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $user->assignRole('user');
        Cache::flush();
        $aiRequests = 0;
        Http::fake(function ($request) use (&$aiRequests) {
            if (str_contains($request->url(), '127.0.0.1:8001/api/chat')) {
                $aiRequests++;

                return Http::response([
                    'reply' => 'Jawaban AI yang dapat digunakan kembali.',
                    'data' => 'Teks yang tidak boleh menggantikan reply.',
                ], 200);
            }

            return Http::response(['status' => 'ERROR'], 404);
        });

        foreach (range(1, 2) as $_) {
            $this->actingAs($user)
                ->postJson(route('chatbot.message'), ['message' => 'Apa manfaat layanan konsultasi?'])
                ->assertOk()
                ->assertJsonPath('reply', 'Jawaban AI yang dapat digunakan kembali.');
        }

        $this->assertSame(1, $aiRequests);
    }

    public function test_ai_claim_that_disease_data_is_unverified_uses_latest_catalog_values(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $user->assignRole('user');
        app(BpsWebApiService::class)->saveApiKey('bps-test-secret');
        Cache::flush();
        $aiReply = 'Maaf, data rinci belum dapatdiverifikasi dari indikator tersebut.';
        $categories = [
            1 => ['HIV/AIDS', 300],
            2 => ['IMS', 969],
            3 => ['DBD', 4416],
            4 => ['Diare', 71962],
            5 => ['TB', 24748],
            6 => ['Malaria', 42],
        ];
        Http::fake(function ($request) use ($aiReply, $categories) {
            $url = $request->url();
            if (str_contains($url, '127.0.0.1:8001/api/chat')) {
                return Http::response(['reply' => $aiReply], 200);
            }
            if (str_contains($url, '/model/th/domain/1600/var/375/')) {
                return Http::response([
                    'status' => 'OK',
                    'data' => [[], [['th' => '2025', 'th_id' => 125]]],
                ]);
            }
            if (str_contains($url, '/model/data/domain/1600/var/375/th/125/')) {
                $datacontent = [];
                foreach ($categories as $categoryId => [, $value]) {
                    $datacontent['1600'.'375'.$categoryId.'1250'] = $value;
                }

                return Http::response([
                    'status' => 'OK',
                    'var' => [['val' => 375, 'label' => 'Jumlah Kasus Penderita Penyakit', 'unit' => 'Kasus']],
                    'vervar' => [['val' => 1600, 'label' => 'Sumatera Selatan']],
                    'turvar' => array_map(
                        fn (array $category, int $id) => ['val' => $id, 'label' => $category[0]],
                        $categories,
                        array_keys($categories)
                    ),
                    'datacontent' => $datacontent,
                ]);
            }

            return Http::response(['status' => 'ERROR'], 404);
        });

        $response = $this->actingAs($user)
            ->postJson(route('chatbot.message'), ['message' => 'jumlah kasus penyakit di sumsel'])
            ->assertOk();

        $this->assertStringContainsString('Data WebAPI BPS untuk Jumlah Kasus Penderita Penyakit', $response->json('reply'));
        $this->assertStringContainsString('DBD: 4.416 Kasus', $response->json('reply'));
        $this->assertStringContainsString('Saya periksa kembali menggunakan data resmi BPS:', $response->json('reply'));
        $this->assertStringNotContainsString('layanan asisten AI sedang tidak tersedia', $response->json('reply'));
        $this->assertStringNotContainsString('belum dapatdiverifikasi', $response->json('reply'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/model/data/domain/1600/var/375/th/125/'));
    }

    public function test_unverified_ai_data_reply_uses_bps_fallback_and_is_not_cached(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $user->assignRole('user');
        app(BpsWebApiService::class)->saveApiKey('bps-test-secret');
        Cache::flush();
        $aiRequests = 0;
        $question = 'Tingkat pengangguran Sumatera Selatan terbaru?';
        $unverifiedReply = 'Maaf, data tersebut belum dapat diverifikasi dari WebAPI BPS saat ini.';
        $cacheKey = 'chatbot:ai:'.sha1(
            mb_strtolower(trim($question)).'|'.sha1(''). '|'.sha1(serialize([]))
        );
        Cache::put($cacheKey, $unverifiedReply, now()->addMinutes(20));

        Http::fake(function ($request) use (&$aiRequests, $unverifiedReply) {
            $url = $request->url();
            if (str_contains($url, '127.0.0.1:8001/api/chat')) {
                $aiRequests++;

                return Http::response(['reply' => $unverifiedReply], 200);
            }
            if (str_contains($url, '/model/th/domain/1600/var/334/')) {
                return Http::response([
                    'status' => 'OK',
                    'data' => [['pages' => 1], [['th' => '2025', 'th_id' => 125]]],
                ]);
            }
            if (str_contains($url, '/model/data/domain/1600/var/334/th/125/')) {
                return Http::response([
                    'status' => 'OK',
                    'var' => [['val' => 334, 'label' => 'Tingkat Pengangguran', 'unit' => 'Persen']],
                    'vervar' => [
                        ['val' => 1, 'label' => 'Laki-Laki'],
                        ['val' => 2, 'label' => 'Perempuan'],
                        ['val' => 3, 'label' => 'Jumlah'],
                    ],
                    'turvar' => [['val' => '0', 'label' => 'Tidak ada']],
                    'datacontent' => [
                        '133401250' => 3.56,
                        '233401250' => 3.92,
                        '333401250' => 3.69,
                    ],
                ]);
            }

            return Http::response(['status' => 'ERROR'], 404);
        });

        foreach (range(1, 2) as $_) {
            $response = $this->actingAs($user)
                ->postJson(route('chatbot.message'), ['message' => $question])
                ->assertOk();

            $this->assertStringContainsString('Total: 3,69 Persen', $response->json('reply'));
            $this->assertStringNotContainsString(
                'belum dapat diverifikasi',
                mb_strtolower($response->json('reply'))
            );
        }

        $this->assertSame(2, $aiRequests);
    }

    public function test_ai_reply_with_unavailable_requested_year_and_latest_data_is_preserved(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $user->assignRole('user');
        app(BpsWebApiService::class)->saveApiKey('bps-test-secret');
        Cache::flush();
        $aiReply = 'Data 2026 belum dirilis. Data terbaru Provinsi Sumatera Selatan (2025): '
            .'Tingkat Pengangguran sebesar 3,69 Persen.';

        Http::fake(function ($request) use ($aiReply) {
            if (str_contains($request->url(), '127.0.0.1:8001/api/chat')) {
                return Http::response(['reply' => $aiReply], 200);
            }

            return Http::response(['status' => 'ERROR'], 404);
        });

        $response = $this->actingAs($user)
            ->postJson(route('chatbot.message'), [
                'message' => 'Tingkat pengangguran Sumatera Selatan tahun 2026?',
            ])
            ->assertOk();

        $this->assertSame($aiReply, $response->json('reply'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'webapi.bps.go.id'));
    }

    public function test_inflation_for_current_month_uses_bps_press_release_context(): void
    {
        app(BpsWebApiService::class)->saveApiKey('bps-test-secret');
        Http::fake(['webapi.bps.go.id/*' => Http::response([
            'status' => 'OK',
            'data' => [['total' => 1, 'pages' => 1], [[
                'title' => 'Inflasi Sumatera Selatan Bulan Ini',
                'rl_date' => '2026-10-01',
                'abstract' => 'Inflasi Sumatera Selatan tercatat berdasarkan rilis terbaru.',
            ]]],
        ])]);

        $context = app(BpsWebApiService::class)->contextFor('inflasi bulan ini');

        $this->assertStringContainsString('Berita Resmi Statistik BPS', $context);
        $this->assertStringContainsString('Inflasi Sumatera Selatan Bulan Ini', $context);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/model/pressrelease/')
            && str_contains($request->url(), '/keyword/inflasi/'));
    }

    public function test_ump_fallback_rejects_substring_table_matches_and_decodes_html_table_text(): void
    {
        app(BpsWebApiService::class)->saveApiKey('bps-test-secret');
        Cache::flush();
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, '/view/model/statictable/domain/1600/')
                && str_contains($url, '/id/ump-good/')) {
                return Http::response([
                    'status' => 'OK',
                    'data' => [
                        'table' => '&lt;table class=&quot;excel&quot;&gt;&lt;tr&gt;'
                            .'&lt;td&gt;Upah Minimum Provinsi Sumatera Selatan&lt;/td&gt;'
                            .'&lt;td&gt;2026&lt;/td&gt;&lt;td&gt;Rp 3.942.000&lt;/td&gt;'
                            .'&lt;/tr&gt;&lt;/table&gt;',
                    ],
                ]);
            }
            if (str_contains($url, '/list/model/statictable/domain/1600/keyword/ump/')) {
                return Http::response([
                    'status' => 'OK',
                    'data' => [['total' => 1, 'pages' => 1], [[
                        'table_id' => 'ump-bad',
                        'title' => 'Banyaknya Desa Menurut Lokasi Berkumpul Anak Jalanan',
                    ]]],
                ]);
            }
            if (str_contains($url, '/list/model/statictable/domain/1600/keyword/upah/')
                || str_contains($url, '/list/model/statictable/domain/1600/keyword/minimum/')) {
                return Http::response([
                    'status' => 'OK',
                    'data' => [['total' => 1, 'pages' => 1], [[
                        'table_id' => 'ump-good',
                        'title' => 'Upah Minimum Provinsi Sumatera Selatan',
                    ]]],
                ]);
            }

            return Http::response(['status' => 'OK', 'data' => [['total' => 0, 'pages' => 1], []]]);
        });

        $answer = app(BpsWebApiService::class)->fallbackAnswerFor('UMP Sumsel');

        $this->assertStringContainsString('Upah Minimum Provinsi Sumatera Selatan', $answer);
        $this->assertStringContainsString('Rp 3.942.000', $answer);
        $this->assertStringNotContainsString('Banyaknya Desa', $answer);
        $this->assertStringNotContainsString('<table', $answer);
        $this->assertStringNotContainsString('&lt;table', $answer);
    }

    public function test_fallback_uses_regional_canonical_pdrb_series_for_the_requested_year(): void
    {
        app(BpsWebApiService::class)->saveApiKey('bps-test-secret');
        Cache::flush();
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, '/model/th/domain/1600/var/860/')) {
                return Http::response([
                    'status' => 'OK',
                    'data' => [['pages' => 1], [
                        ['th' => '2025', 'th_id' => 125],
                        ['th' => '2024', 'th_id' => 124],
                    ]],
                ]);
            }
            if (str_contains($url, '/model/data/domain/1600/var/860/th/124/')) {
                return Http::response([
                    'status' => 'OK',
                    'var' => [[
                        'val' => 860,
                        'label' => 'Produk Domestik Regional Bruto Atas Dasar Harga Berlaku',
                        'unit' => 'Miliar Rupiah',
                    ]],
                    'vervar' => [['val' => 14, 'label' => 'Palembang']],
                    'turvar' => [['val' => '0', 'label' => 'Tidak ada']],
                    'datacontent' => ['1486001240' => 208196.7],
                ]);
            }

            return Http::response(['status' => 'ERROR'], 404);
        });

        $answer = app(BpsWebApiService::class)->fallbackAnswerFor('Berapa PDRB Palembang 2024?');

        $this->assertStringContainsString('Kota Palembang', $answer);
        $this->assertStringContainsString('tahun 2024', $answer);
        $this->assertStringContainsString('208.196,7 Miliar Rupiah', $answer);
        Http::assertSent(fn ($request) => str_contains(
            $request->url(),
            '/model/data/domain/1600/var/860/th/124/'
        ));
    }

    public function test_fallback_uses_the_total_category_for_provincial_unemployment(): void
    {
        app(BpsWebApiService::class)->saveApiKey('bps-test-secret');
        Cache::flush();
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, '/model/th/domain/1600/var/334/')) {
                return Http::response([
                    'status' => 'OK',
                    'data' => [['pages' => 1], [['th' => '2025', 'th_id' => 125]]],
                ]);
            }
            if (str_contains($url, '/model/data/domain/1600/var/334/th/125/')) {
                return Http::response([
                    'status' => 'OK',
                    'var' => [['val' => 334, 'label' => 'Tingkat Pengangguran', 'unit' => 'Persen']],
                    'vervar' => [
                        ['val' => 1, 'label' => 'Laki-Laki'],
                        ['val' => 2, 'label' => 'Perempuan'],
                        ['val' => 3, 'label' => 'Jumlah'],
                    ],
                    'turvar' => [['val' => '0', 'label' => 'Tidak ada']],
                    'datacontent' => [
                        '133401250' => 3.56,
                        '233401250' => 3.92,
                        '333401250' => 3.69,
                    ],
                ]);
            }

            return Http::response(['status' => 'ERROR'], 404);
        });

        $answer = app(BpsWebApiService::class)->fallbackAnswerFor(
            'Berapa tingkat pengangguran Sumatera Selatan terbaru?'
        );

        $this->assertStringContainsString('Provinsi Sumatera Selatan tahun 2025', $answer);
        $this->assertStringContainsString('Total: 3,69 Persen', $answer);
        $this->assertStringNotContainsString('Data WebAPI BPS untuk Tingkat Pengangguran di Jumlah', $answer);
    }

    public function test_fallback_uses_adhk_pdrb_when_requested_for_a_city(): void
    {
        app(BpsWebApiService::class)->saveApiKey('bps-test-secret');
        Cache::flush();
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, '/model/th/domain/1600/var/859/')) {
                return Http::response([
                    'status' => 'OK',
                    'data' => [['pages' => 1], [['th' => '2025', 'th_id' => 125]]],
                ]);
            }
            if (str_contains($url, '/model/data/domain/1600/var/859/th/125/')) {
                return Http::response([
                    'status' => 'OK',
                    'var' => [[
                        'val' => 859,
                        'label' => 'Produk Domestik Regional Bruto Atas Dasar Harga Konstan 2010',
                        'unit' => 'Miliar Rupiah',
                    ]],
                    'vervar' => [['val' => 14, 'label' => 'Palembang']],
                    'turvar' => [['val' => '0', 'label' => 'Tidak ada']],
                    'datacontent' => ['1485901250' => 131646.95],
                ]);
            }

            return Http::response(['status' => 'ERROR'], 404);
        });

        $answer = app(BpsWebApiService::class)->fallbackAnswerFor(
            'Berapa PDRB ADHK Palembang 2025?'
        );

        $this->assertStringContainsString('Kota Palembang', $answer);
        $this->assertStringContainsString('Atas Dasar Harga Konstan 2010', $answer);
        $this->assertStringContainsString('131.646,95 Miliar Rupiah', $answer);
        Http::assertSent(fn ($request) => str_contains(
            $request->url(),
            '/model/data/domain/1600/var/859/th/125/'
        ));
    }

    public function test_regional_inflation_context_falls_back_to_province_with_scope_notice(): void
    {
        app(BpsWebApiService::class)->saveApiKey('bps-test-secret');
        Cache::flush();
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/model/pressrelease/domain/1612/')) {
                return Http::response(['status' => 'OK', 'data' => [['total' => 0, 'pages' => 1], []]]);
            }
            if (str_contains($request->url(), '/model/pressrelease/domain/1600/')) {
                return Http::response([
                    'status' => 'OK',
                    'data' => [['total' => 1, 'pages' => 1], [[
                        'title' => 'Agustus 2026 inflasi Year on Year Sumatera Selatan sebesar 2,60 persen',
                        'rl_date' => '2026-09-01',
                        'abstract' => 'Inflasi Sumatera Selatan tercatat sebesar 2,60 persen.',
                    ]]],
                ]);
            }

            return Http::response(['status' => 'ERROR'], 404);
        });

        $context = app(BpsWebApiService::class)->contextFor('inflasi PALI terbaru');

        $this->assertStringContainsString('[WebAPI BPS][CAKUPAN_PROVINSI]', $context);
        $this->assertStringContainsString('Data khusus Kabupaten Penukal Abab Lematang Ilir tidak ditemukan', $context);
        $this->assertStringContainsString('2,60 persen', $context);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/model/pressrelease/domain/1612/'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/model/pressrelease/domain/1600/'));
    }

    public function test_admin_chatbot_test_sends_data_questions_to_ai_without_laravel_bps_context(): void
    {
        Http::fake(['*127.0.0.1:8001/api/chat*' => Http::response([
            'reply' => 'Jawaban AI mengenai kemiskinan.',
        ], 200)]);

        $this->actingAs($this->admin)
            ->postJson(route('admin.chatbot.test'), ['message' => 'Berapa tingkat kemiskinan?'])
            ->assertOk()
            ->assertJsonPath('reply', 'Jawaban AI mengenai kemiskinan.');

        Http::assertSent(fn ($request) => str_contains($request->url(), '127.0.0.1:8001/api/chat')
            && $request['question'] === 'Berapa tingkat kemiskinan?');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'webapi.bps.go.id'));
    }

    public function test_admin_chatbot_test_uses_knowledge_context_when_bps_context_is_empty(): void
    {
        $knowledge = \Mockery::mock(ChatbotKnowledgeService::class);
        $knowledge->shouldReceive('contextFor')->once()->andReturn('Referensi dari basis pengetahuan.');
        $knowledge->shouldReceive('askWithContext')
            ->once()
            ->withArgs(fn ($question, $sessionId, $context) => $context === 'Referensi dari basis pengetahuan.')
            ->andReturn(new \Illuminate\Http\Client\Response(new \GuzzleHttp\Psr7\Response(
                200,
                ['Content-Type' => 'application/json'],
                json_encode(['data' => 'Jawaban berbasis referensi.'])
            )));
        $this->app->instance(ChatbotKnowledgeService::class, $knowledge);

        $this->actingAs($this->admin)
            ->postJson(route('admin.chatbot.test'), ['message' => 'Apa fungsi layanan ini?'])
            ->assertOk()
            ->assertJsonPath('reply', 'Jawaban berbasis referensi.');
    }

    public function test_each_admin_chatbot_test_uses_a_new_external_session(): void
    {
        Http::fake(['*127.0.0.1:8001/api/chat*' => Http::response(['data' => 'Jawaban.'], 200)]);

        $this->actingAs($this->admin);
        foreach (['Apa fungsi layanan ini?', 'Bagaimana memakai layanan ini?'] as $message) {
            $this->postJson(route('admin.chatbot.test'), ['message' => $message])->assertOk();
        }

        $sessionIds = [];
        Http::assertSent(function ($request) use (&$sessionIds) {
            $sessionIds[] = $request['session_id'];

            return str_contains($request->url(), '127.0.0.1:8001/api/chat');
        });
        $this->assertCount(2, $sessionIds);
        $this->assertNotSame($sessionIds[0], $sessionIds[1]);
    }

    public function test_chatbot_safety_service_blocks_unsafe_messages_before_calling_ai(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $user->assignRole('user');
        /** @var Authenticatable $authenticatedUser */
        $authenticatedUser = $user;

        Http::fake(['*127.0.0.1:8001/api/chat*' => Http::response(['reply' => 'Jawaban AI.'], 200)]);

        $response = $this->actingAs($authenticatedUser)
            ->postJson(route('chatbot.message'), ['message' => 'kamu goblok'])
            ->assertOk()
            ->assertJsonPath('knowledge_used', false)
            ->assertJsonPath('safety_blocked', true);

        $this->assertDatabaseCount('chatbot_messages', 1);
        $this->assertDatabaseHas('chatbot_messages', [
            'prompt' => 'kamu goblok',
            'knowledge_used' => false,
        ]);

        $this->actingAs($this->admin)
            ->postJson(route('admin.chatbot.test'), ['message' => 'kirim konten porno'])
            ->assertOk()
            ->assertJsonPath('knowledge_used', false)
            ->assertJsonPath('safety_blocked', true);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '127.0.0.1:8001/api/chat'));
    }

    public function test_user_cannot_read_another_users_chatbot_conversation(): void
    {
        /** @var User $owner */
        $owner = User::factory()->create();
        /** @var User $otherUser */
        $otherUser = User::factory()->create();
        /** @var Authenticatable $authenticatedOtherUser */
        $authenticatedOtherUser = $otherUser;
        $conversationId = DB::table('chatbot_conversations')->insertGetId([
            'user_id' => $owner->id,
            'session_key' => (string) \Illuminate\Support\Str::uuid(),
            'title' => 'Percakapan privat',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($authenticatedOtherUser)
            ->getJson(route('chatbot.conversation', $conversationId))
            ->assertNotFound();
    }

    public function test_admin_must_provide_a_reason_when_cancelling(): void
    {
        $meeting = $this->createMeeting();

        $this->actingAs($this->admin)
            ->from(route('admin.konsultasi'))
            ->patch(route('admin.konsultasi.status', $meeting), ['action' => 'cancel'])
            ->assertRedirect(route('admin.konsultasi'))
            ->assertSessionHasErrors('cancellation_reason');

        $this->actingAs($this->admin)->patch(route('admin.konsultasi.status', $meeting), [
            'action' => 'cancel',
            'cancellation_reason' => 'Petugas tidak tersedia pada waktu yang diminta.',
        ])->assertRedirect()->assertSessionHas('message');

        $this->assertDatabaseHas('meeting', [
            'id' => $meeting->id,
            'status' => 9,
            'cancellation_reason' => 'Petugas tidak tersedia pada waktu yang diminta.',
        ]);
    }

    public function test_room_is_only_open_to_participants_during_the_confirmed_time(): void
    {
        /** @var User $requester */
        $requester = User::factory()->create();
        $requester->assignRole('user');
        /** @var Authenticatable $authenticatedRequester */
        $authenticatedRequester = $requester;
        $meeting = $this->createMeeting([
            'user_id' => $requester->id,
            'status' => 2,
            'assigned_staff' => 'Petugas 1',
            'tanggal' => '2026-10-15',
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-10-15 09:30:00', 'Asia/Jakarta'));
        Carbon::setTestNow(Carbon::parse('2026-10-15 08:59:00', 'Asia/Jakarta'));
        $this->actingAs($authenticatedRequester)->get(route('konsultasi.room', $meeting))->assertForbidden();
        Carbon::setTestNow(Carbon::parse('2026-10-15 09:30:00', 'Asia/Jakarta'));
        $this->actingAs($authenticatedRequester)->get(route('konsultasi.room', $meeting))->assertOk()->assertSee('Ruang Konsultasi');
        $roomResponse = $this->actingAs($authenticatedRequester)->get(route('konsultasi.room', $meeting));
        preg_match('/data-room-session="([^"]+)"/', $roomResponse->getContent(), $sessionMatch);
        $roomSession = $sessionMatch[1];
        $sdp = "v=0\r\na=ssrc:1048995972 msid:- 43d84ad7-224e-45a2-8e40-6589d611248a\r\n";
        $this->post(route('konsultasi.signals.send', $meeting), ['session_id' => $roomSession, 'type' => 'description', 'payload' => ['type' => 'offer', 'sdp' => $sdp]])->assertCreated();

        $staffRoomResponse = $this->actingAs($this->admin)->get(route('konsultasi.room', $meeting));
        preg_match('/data-room-session="([^"]+)"/', $staffRoomResponse->getContent(), $staffSessionMatch);
        $this->assertSame($roomSession, $staffSessionMatch[1]);
        $this->actingAs($this->admin)->get(route('konsultasi.signals.poll', [$meeting, 'after' => 0, 'session_id' => $roomSession]))
            ->assertOk()
            ->assertJsonPath('signals.0.type', 'description')
            ->assertJsonPath('signals.0.payload.sdp', $sdp);
        $this->actingAs($this->admin)->get(route('konsultasi.signals.poll', [$meeting, 'after' => 0, 'session_id' => (string) \Illuminate\Support\Str::uuid()]))
            ->assertOk()->assertJsonPath('session_changed', true);
        $this->actingAs($this->admin)->get(route('konsultasi.room', $meeting))->assertOk()->assertSee(route('admin.konsultasi'), false);

        DB::table('meeting_signals')->insert([
            'meeting_id' => $meeting->id,
            'session_id' => $roomSession,
            'sender_user_id' => $requester->id,
            'signal_type' => 'description',
            'payload' => json_encode(['type' => 'offer', 'sdp' => 'obsolete']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Meeting::whereKey($meeting->id)->update(['room_last_seen_at' => now()->subMinutes(5)]);
        $newRoomResponse = $this->actingAs($authenticatedRequester)->get(route('konsultasi.room', $meeting));
        preg_match('/data-room-session="([^"]+)"/', $newRoomResponse->getContent(), $newSessionMatch);
        $this->assertNotSame($roomSession, $newSessionMatch[1]);
        $this->assertSame(0, DB::table('meeting_signals')->where('meeting_id', $meeting->id)->count());

        /** @var User $otherUser */
        $otherUser = User::factory()->create();
        $otherUser->assignRole('user');
        /** @var Authenticatable $authenticatedOther */
        $authenticatedOther = $otherUser;
        $this->actingAs($authenticatedOther)->get(route('konsultasi.room', $meeting))->assertForbidden();

        Carbon::setTestNow();
    }

    public function test_only_the_requester_can_rate_a_completed_consultation(): void
    {
        /** @var User $requester */
        $requester = User::factory()->create();
        $requester->assignRole('user');
        /** @var Authenticatable $authenticatedRequester */
        $authenticatedRequester = $requester;
        $meeting = $this->createMeeting(['user_id' => $requester->id, 'status' => 1]);

        $this->actingAs($authenticatedRequester)->post('/konsultasi_rating/'.$meeting->id, ['rating' => 5, 'kritik' => 'Layanan sangat membantu.'])
            ->assertRedirect()->assertSessionHas('message');
        $this->assertDatabaseHas('meeting', ['id' => $meeting->id, 'rating' => 5]);

        /** @var User $otherUser */
        $otherUser = User::factory()->create();
        $otherUser->assignRole('user');
        /** @var Authenticatable $authenticatedOther */
        $authenticatedOther = $otherUser;
        $this->actingAs($authenticatedOther)->post('/konsultasi_rating/'.$meeting->id, ['rating' => 1])->assertNotFound();
    }

    public function test_admin_can_complete_a_session_with_an_optimized_private_image(): void
    {
        Storage::fake('local');
        $meeting = $this->createMeeting(['status' => 2, 'assigned_staff' => 'Petugas 2']);
        $image = UploadedFile::fake()->image('catatan.png', 2400, 1200)->size(500);

        $this->actingAs($this->admin)->patch(route('admin.konsultasi.status', $meeting), [
            'action' => 'complete',
            'ringkasan' => 'Petugas menjelaskan metodologi dan sumber data yang sesuai.',
            'documentation' => $image,
        ])->assertRedirect()->assertSessionHas('message');

        $completed = $meeting->fresh();
        $this->assertSame(1, (int) $completed->status);
        $this->assertSame('catatan.png', $completed->documentation_original_name);
        $this->assertStringEndsWith('.jpg', $completed->documentation_path);
        Storage::disk('local')->assertExists($completed->documentation_path);
        $this->actingAs($this->admin)->get(route('konsultasi.documentation', $meeting))->assertOk();
    }

    private function createMeeting(array $attributes = []): Meeting
    {
        return Meeting::create(array_merge([
            'user_id' => $this->admin->id,
            'name' => 'Konsultasi statistik',
            'description' => 'Permintaan konsultasi data statistik.',
            'tanggal' => '2026-10-20',
            'start_time' => '13:00:00',
            'end_time' => '14:00:00',
            'status' => 0,
        ], $attributes));
    }
}
