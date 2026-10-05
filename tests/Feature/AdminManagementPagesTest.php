<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Meeting;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use App\Services\ChatbotKnowledgeService;
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

    public function test_admin_profile_keeps_the_admin_layout_and_returns_to_dashboard(): void
    {
        /** @var User $admin */
        $admin = User::findOrFail($this->admin->getAuthIdentifier());

        $this->actingAs($admin)
            ->get(route('profile'))
            ->assertOk()
            ->assertSee('RUANG KERJA')
            ->assertSee('user-portal.css')
            ->assertDontSee('Katalog Publikasi');

        $this->actingAs($admin)
            ->put(route('profile.update', $admin), [
                'name' => $admin->name,
                'pekerjaan' => 'ASN/TNI/Polri',
                'jenis_kelamin' => '1',
                'tanggal_lahir' => '1980-01-01',
                'asal_prov' => 'Sumatera Selatan',
                'asal_kab' => 'Palembang',
                'no_hp' => '08123456789',
                'pendidikan' => 'S1 Statistik',
            ])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('message');
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
        $this->actingAs($authenticatedUser)->post(route('admin.chatbot.bps-api-key'), ['api_key' => 'should-not-be-saved'])->assertForbidden();
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

        Http::assertSent(fn($request) => str_contains($request['text'], 'Garis kemiskinan') && str_contains($request['text'], 'PERTANYAAN:'));
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
            'webapi.bps.go.id/v1/view/*' => Http::response([
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

        Http::assertSent(fn($request) => str_contains($request->url(), 'key/bps-test-secret/'));
        Http::assertSent(fn($request) => str_contains($request->url(), 'pst-chat.bpssumsel.com')
            && str_contains($request['text'], 'Persentase Penduduk Miskin')
            && str_contains($request['text'], '2025')
            && str_contains($request['text'], 'PERTANYAAN:'));

        $this->actingAs($this->admin)
            ->post(route('admin.chatbot.bps-api-key'), ['clear_api_key' => '1'])
            ->assertRedirect(route('admin.chatbot'));
        $this->assertDatabaseMissing('chatbot_settings', ['key' => 'bps_webapi_key']);
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

        $this->actingAs($authenticatedUser)->get('/chatbot')->assertOk()->assertSee('Konsultasi Chatbot')->assertSee('Katalog Publikasi');
    }

    public function test_user_chatbot_saves_and_reopens_conversation_turns(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $user->assignRole('user');
        /** @var Authenticatable $authenticatedUser */
        $authenticatedUser = $user;
        Http::fake(['pst-chat.bpssumsel.com/*' => Http::response(['data' => 'Jawaban chatbot.'], 200)]);

        $firstResponse = $this->actingAs($authenticatedUser)
            ->postJson(route('chatbot.message'), ['message' => 'Bagaimana membaca inflasi?'])
            ->assertOk()
            ->assertJsonPath('reply', 'Jawaban chatbot.')
            ->assertJsonPath('title', 'Bagaimana membaca inflasi?');
        $conversationId = $firstResponse->json('conversation_id');

        $this->postJson(route('chatbot.message'), [
            'message' => 'Bisa beri contoh?',
            'conversation_id' => $conversationId,
        ])->assertOk();

        $this->assertDatabaseCount('chatbot_messages', 2);
        $this->getJson(route('chatbot.conversation', $conversationId))
            ->assertOk()
            ->assertJsonCount(2, 'messages')
            ->assertJsonPath('messages.0.prompt', 'Bagaimana membaca inflasi?')
            ->assertJsonPath('messages.1.response', 'Jawaban chatbot.');
        $this->get('/chatbot')->assertOk()->assertSee('Bagaimana membaca inflasi?')->assertSee('tanya jawab');

        $conversation = DB::table('chatbot_conversations')->where('id', $conversationId)->first();
        $this->assertNotSame((string) $user->id, $conversation->session_key);
        Http::assertSent(fn($request) => $request['session_id'] === $conversation->session_key);
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
            ->assertStatus(409);
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

        $this->actingAs($authenticatedRequester)->post('/konsultasi_rating/' . $meeting->id, ['rating' => 5, 'kritik' => 'Layanan sangat membantu.'])
            ->assertRedirect()->assertSessionHas('message');
        $this->assertDatabaseHas('meeting', ['id' => $meeting->id, 'rating' => 5]);

        /** @var User $otherUser */
        $otherUser = User::factory()->create();
        $otherUser->assignRole('user');
        /** @var Authenticatable $authenticatedOther */
        $authenticatedOther = $otherUser;
        $this->actingAs($authenticatedOther)->post('/konsultasi_rating/' . $meeting->id, ['rating' => 1])->assertNotFound();
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
