<?php

namespace Tests\Feature;

use App\Models\Meeting;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
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

        $this->actingAs($approver)->get(route('konsultasi.room', $meeting))->assertOk();
        $this->actingAs($invited)->get(route('konsultasi.room', $meeting))->assertOk();
        $this->actingAs($otherAdmin)->get(route('konsultasi.room', $meeting))->assertForbidden();
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
        $this->actingAs($approver)->post($inviteUrl, [
            'staff_user_ids' => [$invitedOne->id, $invitedTwo->id],
        ])->assertRedirect();
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
        $meeting->update(['room_protocol_version' => 'sdp-mesh-v5']);

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
