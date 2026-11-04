<?php

namespace Tests\Feature;

use App\Models\Meeting;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ConsultationRoomAccessTest extends TestCase
{
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
    }

    public function test_room_is_restricted_to_requester_approver_and_invited_admins(): void
    {
        $approver = $this->admin();
        $invited = $this->admin();
        $otherAdmin = $this->admin();
        $meeting = $this->meeting($approver);

        $this->actingAs($otherAdmin)->get(route('konsultasi.room', $meeting))->assertForbidden();

        DB::table('meeting_room_invites')->insert([
            'meeting_id' => $meeting->id,
            'user_id' => $invited->id,
            'invited_by_user_id' => $approver->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($approver)->get(route('konsultasi.room', $meeting))
            ->assertOk()->assertSee('data-can-moderate="true"', false);
        $this->actingAs($invited)->get(route('konsultasi.room', $meeting))
            ->assertOk()->assertSee('data-can-moderate="true"', false);
        $this->actingAs(User::findOrFail($meeting->user_id))->get(route('konsultasi.room', $meeting))
            ->assertOk()->assertSee('data-can-moderate="false"', false);
        $this->actingAs($otherAdmin)->get(route('konsultasi.room', $meeting))->assertForbidden();
    }

    public function test_kicked_participant_cannot_rejoin_the_room(): void
    {
        $approver = $this->admin();
        $meeting = $this->meeting($approver);
        $requester = User::findOrFail($meeting->user_id);
        DB::table('meeting_room_kicks')->insert([
            'meeting_id' => $meeting->id,
            'user_id' => $requester->id,
            'kicked_by_user_id' => $approver->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($requester)->get(route('konsultasi.room', $meeting))->assertForbidden();
        $this->getJson(route('konsultasi.presence', $meeting))->assertOk()->assertJsonPath('can_join', false);
    }

    public function test_expired_room_rejects_polling_and_releases_all_participant_seats(): void
    {
        $approver = $this->admin();
        $meeting = $this->meeting($approver);
        $meeting->update(['tanggal' => now('Asia/Jakarta')->subDay()->toDateString()]);
        $requester = User::findOrFail($meeting->user_id);

        foreach ([$approver, $requester] as $participant) {
            DB::table('meeting_room_participants')->insert([
                'meeting_id' => $meeting->id,
                'user_id' => $participant->id,
                'client_session_hash' => hash('sha256', 'expired-room-'.$participant->id),
                'joined_at' => now(),
                'last_seen_at' => now(),
                'left_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->actingAs($approver)->getJson(route('konsultasi.signals.poll', $meeting).'?after=0&session_id='.str()->uuid())
            ->assertStatus(410)
            ->assertJsonPath('session_ended', true);
        $this->assertDatabaseMissing('meeting_room_participants', [
            'meeting_id' => $meeting->id,
            'left_at' => null,
        ]);
    }

    public function test_only_approver_can_invite_up_to_two_distinct_admin_accounts(): void
    {
        $approver = $this->admin();
        $invitedOne = $this->admin();
        $invitedTwo = $this->admin();
        $otherAdmin = $this->admin();
        $meeting = $this->meeting($approver);
        $inviteUrl = route('admin.konsultasi.room-invites.store', $meeting);

        $this->actingAs($otherAdmin)->post($inviteUrl, ['staff_user_ids' => [$invitedOne->id]])->assertForbidden();
        $this->actingAs($approver)->get(route('konsultasi.room', $meeting))->assertOk();
        $roomSessionId = $meeting->fresh()->room_session_id;
        $this->postJson($inviteUrl, [
            'staff_user_ids' => [$invitedOne->id, $invitedTwo->id],
        ])->assertOk()
            ->assertJsonPath('capacity', 4)
            ->assertJsonCount(2, 'invited_users');
        $this->assertSame($roomSessionId, $meeting->fresh()->room_session_id);
        $this->assertDatabaseHas('meeting_room_participants', [
            'meeting_id' => $meeting->id,
            'user_id' => $approver->id,
            'left_at' => null,
        ]);
        $this->assertDatabaseCount('meeting_room_invites', 2);

        $thirdInvite = $this->admin();
        $this->actingAs($approver)->post($inviteUrl, [
            'staff_user_ids' => [$thirdInvite->id],
        ])->assertSessionHasErrors('staff_user_ids');
        $this->assertDatabaseCount('meeting_room_invites', 2);
    }

    public function test_room_rejects_join_when_four_active_seats_are_occupied(): void
    {
        $approver = $this->admin();
        $inviteOne = $this->admin();
        $inviteTwo = $this->admin();
        $extraParticipant = $this->admin();
        $meeting = $this->meeting($approver);
        $meeting->update(['room_protocol_version' => 'sdp-mesh-v6']);

        foreach ([$inviteOne, $inviteTwo] as $invite) {
            DB::table('meeting_room_invites')->insert([
                'meeting_id' => $meeting->id,
                'user_id' => $invite->id,
                'invited_by_user_id' => $approver->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        foreach ([$inviteOne, $inviteTwo, $extraParticipant, User::findOrFail($meeting->user_id)] as $participant) {
            DB::table('meeting_room_participants')->insert([
                'meeting_id' => $meeting->id,
                'user_id' => $participant->id,
                'client_session_hash' => hash('sha256', 'another-device-'.$participant->id),
                'joined_at' => now(),
                'last_seen_at' => now(),
                'left_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $this->assertSame(4, DB::table('meeting_room_participants')->where('meeting_id', $meeting->id)->count());

        $this->actingAs($approver)->get(route('konsultasi.room', $meeting))
            ->assertStatus(409);
    }

    public function test_room_capacity_tracks_the_number_of_invited_staff(): void
    {
        $approver = $this->admin();
        $meeting = $this->meeting($approver);
        $presenceUrl = route('konsultasi.presence', $meeting);

        $this->actingAs($approver)->getJson($presenceUrl)->assertOk()
            ->assertJsonPath('capacity', 2);
        $this->get(route('konsultasi.room', $meeting))->assertOk()
            ->assertSee('data-room-capacity="2"', false);

        $invitedOne = $this->admin();
        DB::table('meeting_room_invites')->insert([
            'meeting_id' => $meeting->id,
            'user_id' => $invitedOne->id,
            'invited_by_user_id' => $approver->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->getJson($presenceUrl)->assertOk()->assertJsonPath('capacity', 3);
        $this->actingAs($invitedOne)->get(route('konsultasi.room', $meeting))->assertOk()
            ->assertSee('data-room-capacity="3"', false);

        $invitedTwo = $this->admin();
        DB::table('meeting_room_invites')->insert([
            'meeting_id' => $meeting->id,
            'user_id' => $invitedTwo->id,
            'invited_by_user_id' => $approver->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->getJson($presenceUrl)->assertOk()->assertJsonPath('capacity', 4);
    }

    public function test_signals_are_targeted_and_same_session_can_rejoin_a_full_room(): void
    {
        $approver = $this->admin();
        $invited = $this->admin();
        $meeting = $this->meeting($approver);
        $requester = User::findOrFail($meeting->user_id);
        DB::table('meeting_room_invites')->insert([
            'meeting_id' => $meeting->id,
            'user_id' => $invited->id,
            'invited_by_user_id' => $approver->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $cookie = config('session.cookie');
        $approverSession = Str::random(40);
        $requesterSession = Str::random(40);
        $invitedSession = Str::random(40);
        $this->withCredentials();

        $room = $this->actingAs($approver)->withCookie($cookie, $approverSession)
            ->get(route('konsultasi.room', $meeting))->assertOk();
        preg_match('/data-room-session="([^"]+)"/', $room->getContent(), $matches);
        $roomSession = $matches[1];
        $this->actingAs($requester)->withCookie($cookie, $requesterSession)
            ->get(route('konsultasi.room', $meeting))->assertOk();
        $this->actingAs($invited)->withCookie($cookie, $invitedSession)
            ->get(route('konsultasi.room', $meeting))->assertOk();

        $this->actingAs($approver)->withCookie($cookie, $approverSession)
            ->getJson(route('konsultasi.presence', $meeting))
            ->assertJsonPath('participant_count', 3)
            ->assertJsonPath('can_join', true);
        $this->get(route('konsultasi.room', $meeting))->assertOk();
        $this->withCookie($cookie, Str::random(40))->get(route('konsultasi.room', $meeting))->assertStatus(409);

        $this->withCookie($cookie, $approverSession)->postJson(route('konsultasi.signals.send', $meeting), [
            'session_id' => $roomSession,
            'recipient_user_id' => $requester->id,
            'type' => 'description',
            'payload' => ['type' => 'offer', 'sdp' => 'test'],
        ])->assertCreated();
        $pollUrl = route('konsultasi.signals.poll', [$meeting, 'after' => 0, 'session_id' => $roomSession]);
        $this->actingAs($invited)->withCookie($cookie, $invitedSession)->getJson($pollUrl)
            ->assertOk()->assertJsonCount(0, 'signals');
        $this->actingAs($requester)->withCookie($cookie, $requesterSession)->getJson($pollUrl)
            ->assertOk()->assertJsonPath('signals.0.sender_user_id', $approver->id)
            ->assertJsonPath('signals.0.recipient_user_id', $requester->id);
        $this->postJson(route('konsultasi.signals.send', $meeting), [
            'session_id' => $roomSession,
            'type' => 'leave',
        ])->assertOk()->assertJsonPath('notified_participants', 2);
        $this->getJson($pollUrl)->assertForbidden();
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function meeting(User $approver): Meeting
    {
        return Meeting::query()->create([
            'name' => 'Konsultasi pengujian',
            'description' => 'Permintaan konsultasi untuk menguji akses ruang multi-peserta.',
            'status' => 2,
            'user_id' => $this->admin()->id,
            'assigned_staff' => $approver->name,
            'approved_by_user_id' => $approver->id,
            'tanggal' => now('Asia/Jakarta')->toDateString(),
            'start_time' => '00:00:00',
            'end_time' => '23:59:00',
            'room_opened_at' => now(),
        ]);
    }
}
