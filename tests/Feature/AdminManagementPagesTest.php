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

        Http::fake(['pst-chat.bpssumsel.com/*' => Http::response(['data' => 'Nilai garis kemiskinan tercatat 12.5.'], 200)]);

        $this->actingAs($this->admin)
            ->postJson(route('chatbot.message'), ['message' => 'berapa garis kemiskinan'])
            ->assertOk()
            ->assertJsonPath('knowledge_used', true);

        Http::assertSent(fn ($request) => str_contains($request['text'], 'Garis kemiskinan') && str_contains($request['text'], 'PERTANYAAN:'));
    }

    public function test_admin_can_configure_encrypted_bps_api_key_and_use_bps_data_in_chat(): void
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
            'webapi.bps.go.id/v1/api/list/*' => Http::response([
                'status' => 'OK',
                'data' => [['total' => 1], [['table_id' => 77, 'title' => 'Persentase Penduduk Miskin']]],
            ]),
            'webapi.bps.go.id/v1/api/view/*' => Http::response([
                'status' => 'OK',
                'data' => ['table' => '<table><tr><th>Tahun</th><th>Persentase</th></tr><tr><td>2025</td><td>10,5</td></tr></table>'],
            ]),
            'pst-chat.bpssumsel.com/*' => Http::response(['data' => 'Persentase kemiskinan tahun 2025 sebesar 10,5.'], 200),
        ]);

        /** @var User $user */
        $user = User::factory()->create();
        $user->assignRole('user');

        $this->actingAs($user)
            ->postJson(route('chatbot.message'), ['message' => 'berapa persentase kemiskinan'])
            ->assertOk()
            ->assertJsonPath('knowledge_used', true);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'key/bps-test-secret/'));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'pst-chat.bpssumsel.com')
            && str_contains($request['text'], 'Persentase Penduduk Miskin')
            && str_contains($request['text'], '2025')
            && str_contains($request['text'], 'PERTANYAAN:'));

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

    public function test_dynamic_bps_context_rejects_ambiguous_variables_instead_of_guessing(): void
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

        $this->assertNull($context);
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

    public function test_curated_ipm_variable_uses_verified_var_id(): void
    {
        app(BpsWebApiService::class)->saveApiKey('bps-test-secret');
        Cache::flush();
        Carbon::setTestNow(Carbon::parse('2026-10-07 09:00:00', 'Asia/Jakarta'));
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, '/model/th/domain/1600/var/980/')) {
                return Http::response(['status' => 'OK', 'data' => [[], [['th' => '2026', 'th_id' => 2026]]]]);
            }
            if (str_contains($url, '/model/data/domain/1600/var/980/')) {
                return Http::response(['status' => 'OK', 'datacontent' => ['1600' => 75.5]]);
            }

            return Http::response(['status' => 'ERROR'], 404);
        });

        $context = app(BpsWebApiService::class)->contextFor('IPM terbaru');

        $this->assertStringContainsString('Indeks Pembangunan Manusia', $context);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/model/data/domain/1600/var/980/th/2026/'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/model/var/'));
        Carbon::setTestNow();
    }

    public function test_latest_bps_indicator_does_not_fall_back_to_an_older_period(): void
    {
        app(BpsWebApiService::class)->saveApiKey('bps-test-secret');
        Cache::flush();
        Carbon::setTestNow(Carbon::parse('2026-10-07 09:00:00', 'Asia/Jakarta'));
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/model/th/domain/1600/var/980/')) {
                return Http::response(['status' => 'OK', 'data' => [[], [['th' => '2025', 'th_id' => 2025]]]]);
            }

            return Http::response(['status' => 'ERROR'], 404);
        });

        $context = app(BpsWebApiService::class)->contextFor('IPM terbaru');

        $this->assertStringContainsString('tahun 2026 belum tersedia', $context);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/model/data/'));
        Carbon::setTestNow();
    }

    public function test_curated_population_variable_uses_verified_projection_var_id(): void
    {
        app(BpsWebApiService::class)->saveApiKey('bps-test-secret');
        Cache::flush();
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, '/model/th/domain/1600/var/51/')) {
                return Http::response(['status' => 'OK', 'data' => [[], [['th' => '2025', 'th_id' => 2025]]]]);
            }
            if (str_contains($url, '/model/data/domain/1600/var/51/')) {
                return Http::response(['status' => 'OK', 'datacontent' => ['1600' => 9000000]]);
            }

            return Http::response(['status' => 'ERROR'], 404);
        });

        $context = app(BpsWebApiService::class)->contextFor('berapa jumlah penduduk?');

        $this->assertStringContainsString('Proyeksi Jumlah Penduduk', $context);
        $this->assertStringContainsString('"tahun":"2025"', $context);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/model/data/domain/1600/var/51/th/2025/'));
        Http::assertNotSent(fn ($request) => preg_match('/\/var\/(317|320|322)\//', $request->url()) === 1);
    }

    public function test_population_projection_is_not_used_for_disaggregated_population_questions(): void
    {
        app(BpsWebApiService::class)->saveApiKey('bps-test-secret');
        Http::fake();

        $context = app(BpsWebApiService::class)->contextFor('jumlah penduduk menurut kabupaten kota');

        $this->assertStringContainsString('Variabel terverifikasi', $context);
        Http::assertNothingSent();
    }

    public function test_poverty_count_does_not_use_the_verified_poverty_percentage_variable(): void
    {
        app(BpsWebApiService::class)->saveApiKey('bps-test-secret');
        Http::fake();

        $context = app(BpsWebApiService::class)->contextFor('berapa jumlah penduduk miskin?');

        $this->assertStringContainsString('Variabel terverifikasi', $context);
        Http::assertNothingSent();
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

    public function test_chatbot_reports_unavailable_latest_data_without_calling_ai_or_using_older_periods(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $user->assignRole('user');
        app(BpsWebApiService::class)->saveApiKey('bps-test-secret');
        Cache::flush();
        Carbon::setTestNow(Carbon::parse('2026-10-07 09:00:00', 'Asia/Jakarta'));
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/model/th/domain/1600/var/980/')) {
                return Http::response(['status' => 'OK', 'data' => [[], [['th' => '2025', 'th_id' => 2025]]]]);
            }
            if (str_contains($request->url(), '/model/pressrelease/')) {
                return Http::response(['status' => 'OK', 'data' => [['total' => 0, 'pages' => 1], []]]);
            }

            return Http::response(['status' => 'ERROR'], 404);
        });

        $this->actingAs($user)
            ->postJson(route('chatbot.message'), ['message' => 'IPM terbaru'])
            ->assertOk()
            ->assertJsonPath('reply', 'Maaf, data tersebut belum ditemukan di WebAPI BPS. Silakan cek https://sumsel.bps.go.id.');

        $this->actingAs($user)
            ->postJson(route('chatbot.message'), ['message' => 'pengangguran terbaru'])
            ->assertOk()
            ->assertJsonPath('reply', 'Maaf, data tersebut belum ditemukan di WebAPI BPS. Silakan cek https://sumsel.bps.go.id.');

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'pst-chat.bpssumsel.com'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/model/data/'));
        Carbon::setTestNow();
    }

    public function test_explicit_historical_ipm_year_remains_available(): void
    {
        app(BpsWebApiService::class)->saveApiKey('bps-test-secret');
        Cache::flush();
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, '/model/th/domain/1600/var/980/')) {
                return Http::response(['status' => 'OK', 'data' => [[], [['th' => '2025', 'th_id' => 2025]]]]);
            }
            if (str_contains($url, '/model/data/domain/1600/var/980/')) {
                return Http::response(['status' => 'OK', 'datacontent' => ['1600' => 75.5]]);
            }

            return Http::response(['status' => 'ERROR'], 404);
        });

        $context = app(BpsWebApiService::class)->contextFor('IPM tahun 2025');

        $this->assertStringContainsString('"tahun":"2025"', $context);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/model/data/domain/1600/var/980/th/2025/'));
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

        $this->assertStringContainsString('Pertumbuhan PDRB Tahunan', $context);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/model/data/domain/1600/var/32/th/2025/'));
    }

    public function test_dynamic_bps_context_rejects_data_without_palembang_region_label(): void
    {
        app(BpsWebApiService::class)->saveApiKey('bps-test-secret');
        Cache::flush();
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, '/model/var/')) {
                return Http::response([
                    'status' => 'OK',
                    'data' => [['pages' => 1], [['var_id' => 41, 'title' => 'Jumlah Penduduk']]],
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

        $this->assertNull($context);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/domain/1600/'));
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
        Http::fake(['pst-chat.bpssumsel.com/*' => Http::sequence()
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
    }

    public function test_chatbot_does_not_guess_data_when_bps_api_has_no_context(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $user->assignRole('user');
        Http::fake();

        $this->actingAs($user)
            ->postJson(route('chatbot.message'), ['message' => 'Berapa laju pertumbuhan ekonomi?'])
            ->assertOk()
            ->assertJsonPath('reply', 'Maaf, data tersebut belum ditemukan di WebAPI BPS. Silakan cek https://sumsel.bps.go.id.');

        Http::assertNothingSent();
    }

    public function test_chatbot_says_wage_data_not_found_without_bps_api_key(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $user->assignRole('user');
        Http::fake();

        $this->actingAs($user)
            ->postJson(route('chatbot.message'), ['message' => 'Berapa upah terbaru?'])
            ->assertOk()
            ->assertJsonPath('reply', 'Maaf, data tersebut belum ditemukan di WebAPI BPS. Silakan cek https://sumsel.bps.go.id.');

        Http::assertNothingSent();
    }

    public function test_chatbot_replaces_busy_ai_reply_with_latest_topic_brs_template(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $user->assignRole('user');
        app(BpsWebApiService::class)->saveApiKey('bps-test-secret');
        Cache::flush();
        Carbon::setTestNow(Carbon::parse('2026-10-07 09:00:00', 'Asia/Jakarta'));
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'pst-chat.bpssumsel.com')) {
                return Http::response(['data' => 'Saat ini chatbot BPS Sumsel sedang menerima banyak permintaan. Coba lagi nanti.'], 200);
            }
            if (str_contains($request->url(), '/model/pressrelease/')) {
                return Http::response([
                    'status' => 'OK',
                    'data' => [['total' => 1, 'pages' => 1], [[
                        'title' => 'Inflasi Sumatera Selatan sebesar 3,2 persen',
                        'rl_date' => '2026-10-01',
                    ]]],
                ]);
            }

            return Http::response(['status' => 'ERROR'], 404);
        });

        $response = $this->actingAs($user)
            ->postJson(route('chatbot.message'), ['message' => 'inflasi bulan ini'])
            ->assertOk();
        $this->assertStringContainsString('Inflasi Sumatera Selatan sebesar 3,2 persen', $response->json('reply'));
        $this->assertStringContainsString('rilis: 2026-10-01', $response->json('reply'));
        $this->assertStringNotContainsString('Coba lagi nanti.', $response->json('reply'));

        Http::assertSent(fn ($request) => str_contains($request->url(), 'pst-chat.bpssumsel.com'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/model/pressrelease/')
            && str_contains($request->url(), '/year/2026/month/10/'));
        Carbon::setTestNow();
    }

    public function test_chatbot_replaces_ai_http_error_with_fallback_instead_of_returning_502(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $user->assignRole('user');
        Http::fake(['pst-chat.bpssumsel.com/*' => Http::response(['error' => 'busy'], 503)]);

        $this->actingAs($user)
            ->postJson(route('chatbot.message'), ['message' => 'Apa manfaat layanan konsultasi?'])
            ->assertOk()
            ->assertJsonPath('reply', 'Maaf, chatbot BPS Sumsel sedang menerima banyak permintaan sehingga belum dapat memproses pesan Anda.'."\n\nSilakan kirim pertanyaan Anda lagi beberapa saat lagi, atau kunjungi https://sumsel.bps.go.id.");
    }

    public function test_chatbot_replaces_ai_connection_failure_with_fallback(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $user->assignRole('user');
        Http::fake(['pst-chat.bpssumsel.com/*' => Http::failedConnection()]);

        $this->actingAs($user)
            ->postJson(route('chatbot.message'), ['message' => 'Apa manfaat layanan konsultasi?'])
            ->assertOk()
            ->assertJsonPath('reply', 'Maaf, chatbot BPS Sumsel sedang menerima banyak permintaan sehingga belum dapat memproses pesan Anda.'."\n\nSilakan kirim pertanyaan Anda lagi beberapa saat lagi, atau kunjungi https://sumsel.bps.go.id.");
    }

    public function test_admin_chatbot_test_uses_fallback_when_ai_is_unavailable(): void
    {
        Http::fake(['pst-chat.bpssumsel.com/*' => Http::response(['error' => 'busy'], 503)]);

        $this->actingAs($this->admin)
            ->postJson(route('admin.chatbot.test'), ['message' => 'Apa manfaat layanan konsultasi?'])
            ->assertOk()
            ->assertJsonPath('reply', 'Maaf, chatbot BPS Sumsel sedang menerima banyak permintaan sehingga belum dapat memproses pesan Anda.'."\n\nSilakan kirim pertanyaan Anda lagi beberapa saat lagi, atau kunjungi https://sumsel.bps.go.id.");
    }

    public function test_successful_ai_reply_is_cached_for_twenty_minutes(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $user->assignRole('user');
        Cache::flush();
        $aiRequests = 0;
        Http::fake(function ($request) use (&$aiRequests) {
            if (str_contains($request->url(), 'pst-chat.bpssumsel.com')) {
                $aiRequests++;

                return Http::response(['data' => 'Jawaban AI yang dapat digunakan kembali.'], 200);
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

    public function test_admin_chatbot_test_does_not_send_data_questions_without_bps_context_to_ai(): void
    {
        Http::fake();

        $this->actingAs($this->admin)
            ->postJson(route('admin.chatbot.test'), ['message' => 'Berapa tingkat kemiskinan?'])
            ->assertOk()
            ->assertJsonPath('reply', 'Maaf, data tersebut belum ditemukan di WebAPI BPS. Silakan cek https://sumsel.bps.go.id.');

        Http::assertNothingSent();
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
        Http::fake(['pst-chat.bpssumsel.com/*' => Http::response(['data' => 'Jawaban.'], 200)]);

        $this->actingAs($this->admin);
        foreach (['Apa fungsi layanan ini?', 'Bagaimana memakai layanan ini?'] as $message) {
            $this->postJson(route('admin.chatbot.test'), ['message' => $message])->assertOk();
        }

        $sessionIds = [];
        Http::assertSent(function ($request) use (&$sessionIds) {
            $sessionIds[] = $request['session_id'];

            return str_contains($request->url(), 'pst-chat.bpssumsel.com/send_message/');
        });
        $this->assertCount(2, $sessionIds);
        $this->assertNotSame($sessionIds[0], $sessionIds[1]);
    }

    public function test_chatbot_returns_template_for_unsafe_messages_without_calling_external_services(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $user->assignRole('user');
        /** @var Authenticatable $authenticatedUser */
        $authenticatedUser = $user;

        Http::fake();

        $response = $this->actingAs($authenticatedUser)
            ->postJson(route('chatbot.message'), ['message' => 'kamu goblok'])
            ->assertOk()
            ->assertJsonPath('knowledge_used', false)
            ->assertJsonPath('safety_blocked', true);

        $this->assertStringContainsString('bahasa kasar', $response->json('reply'));
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

        Http::assertNothingSent();
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
