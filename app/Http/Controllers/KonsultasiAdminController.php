<?php

namespace App\Http\Controllers;

use App\Exports\ConsultationHistoryExport;
use App\Models\ConsultationStaff;
use App\Models\Meeting;
use App\Models\TelegramBot;
use App\Models\User;
use App\Services\ConsultationDocumentationService;
use App\Services\ConsultationGoogleCalendarService;
use App\Services\ConsultationMeetingNotificationService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;

class KonsultasiAdminController extends Controller
{
    private const ROOM_PROTOCOL_VERSION = 'sdp-mesh-v6';

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));

        $baseQuery = fn () => Meeting::with('user:id,name,email')->when($search !== '', function ($query) use ($search) {
            $query->where(function ($builder) use ($search) {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($userQuery) => $userQuery->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%"));
            });
        });

        $pending = $baseQuery()->where('status', 0)->orderBy('tanggal')->orderBy('start_time')->paginate(8, ['*'], 'pending_page')->withQueryString();
        $upcoming = $baseQuery()->where('status', 2)->orderBy('tanggal')->orderBy('start_time')->paginate(9, ['*'], 'upcoming_page')->withQueryString();
        $upcoming->getCollection()->transform(function (Meeting $meeting) {
            $meeting->setAttribute('room_open', $meeting->isRoomOpen());
            $meeting->setAttribute('can_open_room_now', $meeting->canBeOpenedEarly());

            return $meeting;
        });
        $history = $baseQuery()->whereIn('status', [1, 9])->orderByDesc('tanggal')->orderByDesc('start_time')->paginate(12, ['*'], 'history_page')->withQueryString();
        $staffRecords = ConsultationStaff::query()->orderBy('sort_order')->orderBy('id')->get();
        $staff = $staffRecords->pluck('name')->all();
        $availableStaff = [];
        foreach ($pending as $meeting) {
            $availableStaff[$meeting->id] = $this->availableStaffFor($meeting, $staffRecords);
        }

        return view('admin.konsultasi.index', [
            'pending' => $pending,
            'upcoming' => $upcoming,
            'history' => $history,
            'search' => $search,
            'staff' => $staff,
            'availableStaff' => $availableStaff,
            'counts' => [
                'all' => Meeting::count(),
                'pending' => Meeting::where('status', 0)->count(),
                'confirmed' => Meeting::where('status', 2)->count(),
                'completed' => Meeting::where('status', 1)->count(),
                'cancelled' => Meeting::where('status', 9)->count(),
            ],
        ]);
    }

    public function exportHistory(Request $request)
    {
        return Excel::download(
            new ConsultationHistoryExport(trim((string) $request->query('q', ''))),
            'riwayat-konsultasi.xlsx'
        );
    }

    public function updateStatus(Request $request, Meeting $meeting, ConsultationDocumentationService $documentation, ConsultationMeetingNotificationService $notifications, ConsultationGoogleCalendarService $calendar): RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['confirm', 'complete', 'cancel'])],
            'assigned_staff' => ['required_if:action,confirm', 'nullable', Rule::in(ConsultationStaff::query()->pluck('name')->all())],
            'cancellation_reason' => ['required_if:action,cancel', 'nullable', 'string', 'min:10', 'max:1000'],
            'ringkasan' => ['required_if:action,complete', 'nullable', 'string', 'min:10', 'max:5000'],
            'documentation' => [Rule::requiredIf(fn () => $request->input('action') === 'complete' && !$request->filled('documentation_url')), 'nullable', 'file', 'mimes:jpg,jpeg,png,webp,mp4,webm,mov', 'max:25600'],
            'documentation_url' => [Rule::requiredIf(fn () => $request->input('action') === 'complete' && !$request->hasFile('documentation')), 'nullable', 'url', 'max:2048'],
        ]);

        $storedDocumentation = null;
        if ($validated['action'] === 'complete' && $request->hasFile('documentation')) {
            $file = $request->file('documentation');
            if (str_starts_with((string) $file->getMimeType(), 'video/') && $file->getSize() > 10 * 1024 * 1024) {
                throw ValidationException::withMessages(['documentation' => 'Video maksimal 10 MB. Unggah versi yang sudah diperkecil atau gunakan tautan dokumentasi.']);
            }
            try {
                $storedDocumentation = $documentation->store($file, $meeting->id, Auth::user());
            } catch (\RuntimeException $exception) {
                throw ValidationException::withMessages(['documentation' => $exception->getMessage()]);
            }
        }

        try {
            $approver = Auth::user();
            DB::transaction(function () use ($meeting, $validated, $storedDocumentation, $request, $approver) {
                $lockedMeeting = Meeting::query()->whereKey($meeting->id)->lockForUpdate()->firstOrFail();

                if ($validated['action'] === 'confirm') {
                    if ((int) $lockedMeeting->status !== 0) {
                        throw ValidationException::withMessages(['action' => 'Permintaan konsultasi ini sudah diproses.']);
                    }

                    $staffMember = ConsultationStaff::query()
                        ->where('name', $validated['assigned_staff'])
                        ->lockForUpdate()
                        ->first();
                    if (! $staffMember) {
                        throw ValidationException::withMessages(['assigned_staff' => 'Petugas sudah diubah. Muat ulang halaman lalu pilih petugas yang tersedia.']);
                    }

                    $staffName = $staffMember->name;
                    $conflict = Meeting::query()
                        ->where('assigned_staff_id', $staffMember->id)
                        ->where('status', 2)
                        ->whereDate('tanggal', $lockedMeeting->tanggal)
                        ->where('start_time', '<', $lockedMeeting->end_time)
                        ->where('end_time', '>', $lockedMeeting->start_time)
                        ->lockForUpdate()
                        ->exists();

                    if ($conflict) {
                        throw ValidationException::withMessages(['assigned_staff' => 'Petugas tersebut baru saja ditugaskan pada jadwal yang bertabrakan. Pilih petugas lain.']);
                    }

                    $lockedMeeting->assigned_staff = $staffName;
                    $lockedMeeting->assigned_staff_id = $staffMember->id;
                    $lockedMeeting->approved_by_user_id = $approver->id;
                    $lockedMeeting->approved_by_name = $approver->name;
                    $lockedMeeting->approved_by_email = $approver->email;
                    $lockedMeeting->status = 2;
                    $lockedMeeting->save();

                    return;
                }

                if ($validated['action'] === 'complete') {
                    if ((int) $lockedMeeting->status !== 2) {
                        throw ValidationException::withMessages(['action' => 'Hanya konsultasi terkonfirmasi yang dapat diselesaikan.']);
                    }
                    $lockedMeeting->update([
                        'status' => 1,
                        'ringkasan' => $validated['ringkasan'],
                        'documentation_path' => $storedDocumentation,
                        'documentation_original_name' => $request->file('documentation')?->getClientOriginalName(),
                        'link_dokumentasi' => $validated['documentation_url'] ?? null,
                    ]);
                    DB::table('meeting_signals')->where('meeting_id', $lockedMeeting->id)->delete();

                    return;
                }

                if (!in_array((int) $lockedMeeting->status, [0, 2], true)) {
                    throw ValidationException::withMessages(['action' => 'Konsultasi ini sudah ditutup.']);
                }
                $lockedMeeting->update([
                    'status' => 9,
                    'cancellation_reason' => $validated['cancellation_reason'],
                ]);
            });
        } catch (QueryException $exception) {
            report($exception);
            if ($storedDocumentation) {
                $documentation->delete($storedDocumentation, Auth::user());
            }

            return back()->with('error', 'Tidak dapat memperbarui konsultasi. Silakan coba kembali.');
        } catch (\Throwable $exception) {
            if ($storedDocumentation) {
                $documentation->delete($storedDocumentation, Auth::user());
            }
            throw $exception;
        }

        if ($validated['action'] === 'confirm') {
            $confirmedMeeting = $meeting->fresh();
            $this->notifyConfirmed($confirmedMeeting);
            $notifications->sendApprovalNotifications($confirmedMeeting);
            $calendarStatus = $calendar->createForApprover($confirmedMeeting);
        }

        $message = match ($validated['action']) {
            'confirm' => 'Konsultasi dikonfirmasi dan petugas berhasil ditugaskan.',
            'complete' => 'Konsultasi ditandai selesai dan dipindahkan ke riwayat.',
            default => 'Permintaan konsultasi berhasil dibatalkan.',
        };

        if ($validated['action'] === 'confirm') {
            $message .= match ($calendarStatus) {
                'created' => ' Pengingat Google Calendar berhasil dibuat untuk petugas yang menyetujui.',
                'already_created' => ' Pengingat Google Calendar untuk sesi ini sudah tersedia.',
                default => ' Pengingat Google Calendar belum dapat dibuat. Pastikan akun Google petugas yang menyetujui sudah terhubung dan memiliki akses kalender.',
            };
        }

        return back()->with('message', $message);
    }

    public function openRoomNow(Meeting $meeting): RedirectResponse
    {
        $result = DB::transaction(function () use ($meeting): string {
            $lockedMeeting = Meeting::query()->whereKey($meeting->id)->lockForUpdate()->firstOrFail();
            if ((int) $lockedMeeting->status !== 2) {
                throw ValidationException::withMessages(['meeting' => 'Hanya konsultasi terkonfirmasi yang ruangnya dapat dibuka.']);
            }

            $now = now('Asia/Jakarta');
            $end = Carbon::parse($lockedMeeting->tanggal, 'Asia/Jakarta')->setTimeFromTimeString((string) $lockedMeeting->end_time);
            if ($now->gt($end)) {
                throw ValidationException::withMessages(['meeting' => 'Jadwal konsultasi ini sudah berakhir.']);
            }

            if ($lockedMeeting->room_opened_at) {
                return 'already_open';
            }

            $start = Carbon::parse($lockedMeeting->tanggal, 'Asia/Jakarta')->setTimeFromTimeString((string) $lockedMeeting->start_time);
            if (! $now->lt($start)) {
                return 'scheduled_open';
            }

            $lockedMeeting->room_opened_at = $now->copy()->utc();
            $lockedMeeting->save();

            return 'opened';
        });

        $message = match ($result) {
            'opened' => 'Ruang konsultasi berhasil dibuka sekarang. Jam selesai tetap mengikuti jadwal yang telah dikonfirmasi.',
            'already_open' => 'Ruang konsultasi ini sudah dibuka lebih awal dan dapat diakses sampai jam selesai yang dijadwalkan.',
            default => 'Waktu mulai sesi telah tiba. Ruang konsultasi dapat diakses sesuai jadwal.',
        };

        return back()->with('message', $message);
    }

    public function updateHistory(Request $request, Meeting $meeting): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:3000'],
            'tanggal' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'assigned_staff' => ['nullable', 'string', 'max:255'],
            'ringkasan' => ['nullable', 'string', 'max:5000'],
            'cancellation_reason' => ['nullable', 'string', 'max:1000'],
            'documentation_url' => ['nullable', 'url', 'max:2048'],
        ]);

        DB::transaction(function () use ($meeting, $validated) {
            $lockedMeeting = Meeting::query()->whereKey($meeting->id)->lockForUpdate()->firstOrFail();
            if (! in_array((int) $lockedMeeting->status, [1, 9], true)) {
                throw ValidationException::withMessages(['meeting' => 'Hanya konsultasi yang sudah selesai atau dibatalkan yang dapat diedit di riwayat.']);
            }

            $staffName = $validated['assigned_staff'] ?: null;
            $changes = [
                'name' => $validated['name'],
                'description' => $validated['description'],
                'tanggal' => $validated['tanggal'],
                'start_time' => $validated['start_time'],
                'end_time' => $validated['end_time'],
                'assigned_staff' => $staffName,
                'assigned_staff_id' => $staffName
                    ? ConsultationStaff::query()->where('name', $staffName)->value('id')
                    : null,
                'link_dokumentasi' => $validated['documentation_url'] ?? null,
            ];

            if ((int) $lockedMeeting->status === 1) {
                $changes['ringkasan'] = $validated['ringkasan'] ?? null;
            } else {
                $changes['cancellation_reason'] = $validated['cancellation_reason'] ?? null;
            }

            $lockedMeeting->update($changes);
        });

        return back()->with('message', 'Riwayat konsultasi berhasil diperbarui.');
    }

    public function deleteHistory(Meeting $meeting, ConsultationDocumentationService $documentation): RedirectResponse
    {
        $documentationPath = DB::transaction(function () use ($meeting): ?string {
            $lockedMeeting = Meeting::query()->whereKey($meeting->id)->lockForUpdate()->firstOrFail();
            if (! in_array((int) $lockedMeeting->status, [1, 9], true)) {
                throw ValidationException::withMessages(['meeting' => 'Hanya konsultasi yang sudah selesai atau dibatalkan yang dapat dihapus dari riwayat.']);
            }

            $path = $lockedMeeting->documentation_path;
            $lockedMeeting->delete();

            return $path;
        });

        if ($documentationPath) {
            if (Str::startsWith($documentationPath, 'gdrive:')) {
                $documentation->delete($documentationPath, Auth::user());
            } else {
                Storage::disk('local')->delete($documentationPath);
            }
        }

        return back()->with('message', 'Riwayat konsultasi beserta data dan dokumentasi terkait berhasil dihapus.');
    }

    private function isRoomOpen(Meeting $meeting): bool
    {
        return $meeting->isRoomOpen();
    }

    public function inviteRoomStaff(Request $request, Meeting $meeting): RedirectResponse|JsonResponse
    {
        abort_unless((int) $meeting->approved_by_user_id === (int) Auth::id(), 403);
        abort_unless((int) $meeting->status === 2 && $meeting->isRoomOpen(), 403, 'Undangan hanya dapat diatur saat ruang konsultasi aktif.');

        $validated = $request->validate([
            'staff_user_ids' => ['required', 'array', 'min:1', 'max:2'],
            'staff_user_ids.*' => ['required', 'integer', 'distinct', 'exists:users,id'],
        ]);
        $selectedIds = collect($validated['staff_user_ids'])->map(fn ($id) => (int) $id)->unique();

        $inviteSummary = DB::transaction(function () use ($meeting, $selectedIds) {
            $lockedMeeting = Meeting::query()->whereKey($meeting->id)->lockForUpdate()->firstOrFail();
            abort_unless(
                (int) $lockedMeeting->approved_by_user_id === (int) Auth::id()
                    && (int) $lockedMeeting->status === 2
                    && $lockedMeeting->isRoomOpen(),
                403,
                'Undangan hanya dapat diatur oleh petugas penyetuju saat ruang aktif.'
            );

            $eligibleUsers = User::query()->role(['admin', 'super_admin'])
                ->whereIn('id', $selectedIds)
                ->whereNotIn('id', [(int) $lockedMeeting->approved_by_user_id, (int) $lockedMeeting->user_id])
                ->pluck('id');
            if ($eligibleUsers->count() !== $selectedIds->count()) {
                throw ValidationException::withMessages([
                    'staff_user_ids' => 'Pilih akun petugas admin yang berbeda dari pemohon dan petugas utama.',
                ]);
            }

            $existingIds = DB::table('meeting_room_invites')
                ->where('meeting_id', $lockedMeeting->id)
                ->lockForUpdate()
                ->pluck('user_id')
                ->map(fn ($id) => (int) $id);
            $newIds = $selectedIds->diff($existingIds);
            if ($existingIds->count() + $newIds->count() > 2) {
                throw ValidationException::withMessages([
                    'staff_user_ids' => 'Maksimal dua petugas tambahan dapat diundang ke ruang ini.',
                ]);
            }

            foreach ($newIds as $userId) {
                DB::table('meeting_room_invites')->insert([
                    'meeting_id' => $lockedMeeting->id,
                    'user_id' => $userId,
                    'invited_by_user_id' => Auth::id(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $invitedUsers = User::query()->whereIn('id', $existingIds->merge($newIds))->orderBy('name')->get(['id', 'name']);

            return [
                'invited_users' => $invitedUsers->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])->all(),
                'capacity' => min(4, 2 + $existingIds->count() + $newIds->count()),
            ];
        });

        if ($request->expectsJson()) {
            return response()->json($inviteSummary);
        }

        return back()->with('message', 'Petugas berhasil diundang ke ruang konsultasi.');
    }

    private function roomIceServers(Meeting $meeting): array
    {
        $iceServers = config('pst.ice_servers', []);
        $sharedSecret = trim((string) config('pst.turn_shared_secret', ''));
        if ($sharedSecret === '') {
            return $iceServers;
        }

        // Coturn REST credentials are time-limited and generated per room page load.
        $expiresAt = now()->addSeconds((int) config('pst.turn_credential_ttl', 21600))->timestamp;
        $username = $expiresAt . ':pst-' . $meeting->id . '-' . Auth::id() . '-' . Str::random(8);
        $credential = base64_encode(hash_hmac('sha1', $username, $sharedSecret, true));

        return array_map(static function (array $server) use ($username, $credential): array {
            $urls = is_array($server['urls'] ?? null) ? $server['urls'] : [$server['urls'] ?? ''];
            $hasTurnUrl = collect($urls)->contains(static fn ($url): bool =>
                is_string($url) && preg_match('/^turns?:/i', $url) === 1
            );

            if ($hasTurnUrl) {
                $server['username'] = $username;
                $server['credential'] = $credential;
                $server['credentialType'] = 'password';
            }

            return $server;
        }, $iceServers);
    }

    public function room(Meeting $meeting): View
    {
        $this->authorizeParticipant($meeting);
        $this->ensureRoomOpen($meeting);
        abort_if(
            DB::table('meeting_room_kicks')->where('meeting_id', $meeting->id)->where('user_id', Auth::id())->exists(),
            403,
            'Anda telah dikeluarkan dari ruang konsultasi ini.'
        );
        $clientSessionHash = hash('sha256', session()->getId());
        $lastSignalBeforeJoin = (int) DB::table('meeting_signals')->where('meeting_id', $meeting->id)->max('id');

        $sessionId = DB::transaction(function () use ($meeting, $clientSessionHash) {
            $lockedMeeting = Meeting::query()->whereKey($meeting->id)->lockForUpdate()->firstOrFail();
            abort_if(
                DB::table('meeting_room_kicks')->where('meeting_id', $meeting->id)->where('user_id', Auth::id())->exists(),
                403,
                'Anda telah dikeluarkan dari ruang konsultasi ini.'
            );
            $protocolChanged = $lockedMeeting->room_protocol_version !== self::ROOM_PROTOCOL_VERSION;
            if ($protocolChanged) {
                DB::table('meeting_room_participants')->where('meeting_id', $meeting->id)
                    ->whereNull('left_at')
                    ->update(['left_at' => now(), 'updated_at' => now()]);
                DB::table('meeting_signals')->where('meeting_id', $meeting->id)->delete();
            }
            $leaseExpired = !$lockedMeeting->room_last_seen_at
                || Carbon::parse($lockedMeeting->room_last_seen_at)->lt(now()->subSeconds(90));
            $existingParticipant = DB::table('meeting_room_participants')
                ->where('meeting_id', $meeting->id)
                ->where('user_id', Auth::id())
                ->lockForUpdate()
                ->first();
            $participantIsActive = $existingParticipant
                && !$existingParticipant->left_at
                && $existingParticipant->last_seen_at
                && Carbon::parse($existingParticipant->last_seen_at)->gte(now()->subSeconds(30));
            if ($participantIsActive && $existingParticipant->client_session_hash !== $clientSessionHash) {
                abort(409, 'Akun ini sudah menggunakan ruang konsultasi dari perangkat lain.');
            }
            $activeCount = DB::table('meeting_room_participants')
                ->where('meeting_id', $meeting->id)
                ->whereNull('left_at')
                ->where('last_seen_at', '>=', now()->subSeconds(30))
                ->when($participantIsActive, fn ($query) => $query->where('user_id', '!=', Auth::id()))
                ->count();
            $roomCapacity = min(
                4,
                2 + DB::table('meeting_room_invites')->where('meeting_id', $meeting->id)->count()
            );
            abort_if($activeCount >= $roomCapacity, 409, 'Ruang konsultasi sudah penuh. Coba bergabung kembali saat ada peserta keluar.');

            if (!$lockedMeeting->room_session_id || $leaseExpired || $protocolChanged) {
                $lockedMeeting->room_session_id = (string) Str::uuid();
            }

            $lockedMeeting->room_protocol_version = self::ROOM_PROTOCOL_VERSION;
            $lockedMeeting->room_last_seen_at = now();
            $lockedMeeting->save();

            DB::table('meeting_room_participants')->updateOrInsert(
                ['meeting_id' => $meeting->id, 'user_id' => Auth::id()],
                [
                    'client_session_hash' => $clientSessionHash,
                    'joined_at' => now(),
                    'last_seen_at' => now(),
                    'left_at' => null,
                    'updated_at' => now(),
                    'created_at' => $existingParticipant?->created_at ?? now(),
                ]
            );

            return $lockedMeeting->room_session_id;
        });
        $inviteIds = DB::table('meeting_room_invites')->where('meeting_id', $meeting->id)->pluck('user_id');
        $invitedUsers = User::query()->whereIn('id', $inviteIds)->orderBy('name')->get(['id', 'name']);
        $availableStaff = User::query()->role(['admin', 'super_admin'])
            ->whereNotIn('id', collect([(int) $meeting->approved_by_user_id, (int) $meeting->user_id])->merge($inviteIds))
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('konsultasi.room', [
            'meeting' => $meeting->load('user:id,name'),
            'isRequester' => (int) $meeting->user_id === (int) Auth::id(),
            'currentUserId' => (int) Auth::id(),
            'canManageRoomInvites' => (int) $meeting->approved_by_user_id === (int) Auth::id(),
            'canModerateRoom' => $this->isRoomModerator($meeting, (int) Auth::id()),
            'roomEndsAt' => Carbon::parse($meeting->tanggal, 'Asia/Jakarta')
                ->setTimeFromTimeString((string) $meeting->end_time)->toIso8601String(),
            'roomServerNow' => now('Asia/Jakarta')->toIso8601String(),
            'returnUrl' => Auth::user()->hasRole(['admin', 'super_admin']) ? route('admin.konsultasi') : url('konsultasi'),
            'roomSessionId' => $sessionId,
            'roomLastSignalId' => $lastSignalBeforeJoin,
            'roomCapacity' => min(4, 2 + $inviteIds->count()),
            'invitedUsers' => $invitedUsers,
            'availableStaff' => $availableStaff,
            'iceServers' => $this->roomIceServers($meeting),
        ]);
    }

    public function signals(Request $request, Meeting $meeting): JsonResponse
    {
        $this->authorizeParticipant($meeting);
        if (!$meeting->isRoomOpen()) {
            if ($request->isMethod('get')) {
                DB::table('meeting_room_participants')->where('meeting_id', $meeting->id)
                    ->whereNull('left_at')
                    ->update(['left_at' => now(), 'last_seen_at' => now(), 'updated_at' => now()]);

                return response()->json(['session_ended' => true], 410);
            }
            abort_unless($request->input('type') === 'leave', 403, 'Sesi konsultasi telah berakhir.');
        }
        $this->ensureJoinedRoom($meeting);

        if ($request->isMethod('get')) {
            $validated = $request->validate([
                'after' => ['nullable', 'integer', 'min:0'],
                'session_id' => ['required', 'uuid'],
            ]);
            if (!hash_equals((string) $meeting->room_session_id, $validated['session_id'])) {
                return response()->json([
                    'session_changed' => true,
                    'session_id' => $meeting->room_session_id,
                ], 409);
            }
            $this->touchRoomParticipant($meeting);
            Meeting::whereKey($meeting->id)
                ->where(function ($query) {
                    $query->whereNull('room_last_seen_at')
                        ->orWhere('room_last_seen_at', '<=', now()->subSeconds(30));
                })
                ->update(['room_last_seen_at' => now()]);
            $signals = DB::table('meeting_signals')->where('meeting_id', $meeting->id)
                ->where('session_id', $validated['session_id'])
                ->where('sender_user_id', '!=', Auth::id())
                ->where(function ($query) {
                    $query->whereNull('recipient_user_id')->orWhere('recipient_user_id', Auth::id());
                })
                ->where('id', '>', $validated['after'] ?? 0)
                ->orderBy('id')->limit(100)->get(['id', 'sender_user_id', 'recipient_user_id', 'signal_type', 'payload']);
            $participants = $this->activeRoomParticipants($meeting);
            $capacity = min(4, 2 + DB::table('meeting_room_invites')->where('meeting_id', $meeting->id)->count());

            return response()->json(['participants' => $participants, 'capacity' => $capacity, 'server_now' => now('Asia/Jakarta')->toIso8601String(), 'signals' => $signals->map(fn ($signal) => [
                'id' => $signal->id,
                'sender_user_id' => (int) $signal->sender_user_id,
                'recipient_user_id' => $signal->recipient_user_id ? (int) $signal->recipient_user_id : null,
                'type' => $signal->signal_type,
                'payload' => json_decode($signal->payload, true),
            ])]);
        }

        $signalRules = [
            'type' => ['required', Rule::in(['description', 'candidate', 'media-state', 'moderation', 'leave'])],
            'payload' => ['required_unless:type,leave', 'nullable', 'array'],
            'session_id' => ['required', 'uuid'],
            'recipient_user_id' => ['required_unless:type,leave', 'nullable', 'integer', 'exists:users,id'],
        ];
        if ($request->input('type') === 'moderation') {
            $signalRules['payload.action'] = ['required', Rule::in(['request-camera', 'request-mic', 'mute-mic', 'kick'])];
        }
        $validated = $request->validate($signalRules);
        abort_unless(hash_equals((string) $meeting->room_session_id, $validated['session_id']), 409, 'Sesi ruang telah diperbarui. Muat ulang halaman meeting.');
        if ($validated['type'] === 'leave') {
            $notifiedParticipants = DB::transaction(function () use ($meeting, $validated) {
                $now = now();
                $activeParticipants = DB::table('meeting_room_participants')
                    ->where('meeting_id', $meeting->id)
                    ->where('user_id', '!=', Auth::id())
                    ->whereNull('left_at')
                    ->where('last_seen_at', '>=', $now->copy()->subSeconds(30))
                    ->pluck('user_id');

                DB::table('meeting_room_participants')->where('meeting_id', $meeting->id)->where('user_id', Auth::id())
                    ->where('client_session_hash', hash('sha256', session()->getId()))
                    ->whereNull('left_at')
                    ->update(['left_at' => $now, 'last_seen_at' => $now, 'updated_at' => $now]);

                foreach ($activeParticipants as $recipientUserId) {
                    DB::table('meeting_signals')->insert([
                        'meeting_id' => $meeting->id,
                        'session_id' => $validated['session_id'],
                        'sender_user_id' => Auth::id(),
                        'recipient_user_id' => $recipientUserId,
                        'signal_type' => 'leave',
                        'payload' => json_encode([]),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                return $activeParticipants->count();
            });

            return response()->json(['left' => true, 'notified_participants' => $notifiedParticipants]);
        }
        if ($validated['type'] === 'moderation') {
            abort_unless($this->isRoomModerator($meeting, (int) Auth::id()), 403, 'Hanya petugas ruang yang dapat mengelola peserta.');
            abort_unless((int) $validated['recipient_user_id'] !== (int) Auth::id(), 422, 'Petugas tidak dapat menerapkan tindakan ini kepada dirinya sendiri.');

            $action = $validated['payload']['action'];
            $now = now();
            $activeParticipant = DB::table('meeting_room_participants')->where('meeting_id', $meeting->id)
                ->where('user_id', $validated['recipient_user_id'])->whereNull('left_at')
                ->where('last_seen_at', '>=', $now->copy()->subSeconds(30))->exists();
            abort_unless($activeParticipant, 409, 'Peserta tujuan sudah keluar dari ruang.');

            if ($action === 'kick') {
                DB::transaction(function () use ($meeting, $validated, $now) {
                    Meeting::query()->whereKey($meeting->id)->lockForUpdate()->firstOrFail();
                    DB::table('meeting_room_kicks')->insertOrIgnore([
                        'meeting_id' => $meeting->id,
                        'user_id' => $validated['recipient_user_id'],
                        'kicked_by_user_id' => Auth::id(),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    DB::table('meeting_room_participants')->where('meeting_id', $meeting->id)
                        ->where('user_id', $validated['recipient_user_id'])->whereNull('left_at')
                        ->update(['left_at' => $now, 'last_seen_at' => $now, 'updated_at' => $now]);

                    $remainingParticipantIds = DB::table('meeting_room_participants')->where('meeting_id', $meeting->id)
                        ->where('user_id', '!=', $validated['recipient_user_id'])->whereNull('left_at')
                        ->where('last_seen_at', '>=', $now->copy()->subSeconds(30))->pluck('user_id');
                    foreach ($remainingParticipantIds as $recipientUserId) {
                        DB::table('meeting_signals')->insert([
                            'meeting_id' => $meeting->id,
                            'session_id' => $validated['session_id'],
                            'sender_user_id' => $validated['recipient_user_id'],
                            'recipient_user_id' => $recipientUserId,
                            'signal_type' => 'leave',
                            'payload' => json_encode([]),
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                });

                return response()->json(['kicked' => true]);
            }

            DB::table('meeting_signals')->insert([
                'meeting_id' => $meeting->id,
                'session_id' => $validated['session_id'],
                'sender_user_id' => Auth::id(),
                'recipient_user_id' => $validated['recipient_user_id'],
                'signal_type' => 'moderation',
                'payload' => json_encode($validated['payload']),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return response()->json(['accepted' => true], 201);
        }
        abort_unless(
            DB::table('meeting_room_participants')->where('meeting_id', $meeting->id)
                ->where('user_id', $validated['recipient_user_id'])->whereNull('left_at')
                ->where('last_seen_at', '>=', now()->subSeconds(30))->exists(),
            409,
            'Peserta tujuan sudah keluar dari ruang.'
        );
        Meeting::whereKey($meeting->id)->update(['room_last_seen_at' => now()]);
        if (strlen(json_encode($validated['payload']) ?: '') > 100000) {
            return response()->json(['message' => 'Data signaling terlalu besar.'], 413);
        }

        DB::table('meeting_signals')->insert([
            'meeting_id' => $meeting->id,
            'session_id' => $validated['session_id'],
            'sender_user_id' => Auth::id(),
            'recipient_user_id' => $validated['recipient_user_id'],
            'signal_type' => $validated['type'],
            'payload' => json_encode($validated['payload']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->touchRoomParticipant($meeting);

        return response()->json(['accepted' => true], 201);
    }

    public function messages(Request $request, Meeting $meeting): JsonResponse
    {
        $this->authorizeMeetingChatParticipant($meeting);
        abort_unless((int) $meeting->status === 2, 404, 'Chat hanya tersedia untuk konsultasi yang masih terkonfirmasi.');

        $validated = $request->validate([
            'after' => ['nullable', 'integer', 'min:0'],
        ]);
        DB::table('consultation_messages')->where('meeting_id', $meeting->id)->where('sender_user_id', '!=', Auth::id())->whereNull('read_at')->update(['read_at' => now(), 'updated_at' => now()]);

        $after = (int) ($validated['after'] ?? 0);
        $query = DB::table('consultation_messages')
            ->join('users', 'users.id', '=', 'consultation_messages.sender_user_id')
            ->where('consultation_messages.meeting_id', $meeting->id)
            ->select([
                'consultation_messages.id',
                'consultation_messages.sender_user_id',
                'consultation_messages.body',
                'consultation_messages.created_at',
                'consultation_messages.read_at',
                'users.name as sender_name',
            ]);

        if ($after > 0) {
            $messages = $query->where('consultation_messages.id', '>', $after)
                ->orderBy('consultation_messages.id')
                ->limit(100)
                ->get();
        } else {
            $messages = $query->orderByDesc('consultation_messages.id')
                ->limit(50)
                ->get()
                ->reverse()
                ->values();
        }

        return response()->json([
            'unread_count' => 0,
            'read_message_ids' => DB::table('consultation_messages')->where('meeting_id', $meeting->id)->where('sender_user_id', Auth::id())->whereNotNull('read_at')->orderByDesc('id')->limit(100)->pluck('id')->map(fn ($id) => (int) $id),
            'messages' => $messages->map(fn ($message) => [
                'id' => (int) $message->id,
                'sender_id' => (int) $message->sender_user_id,
                'sender_name' => $message->sender_name,
                'body' => $message->body,
                'is_mine' => (int) $message->sender_user_id === (int) Auth::id(),
                'sent_at' => Carbon::parse($message->created_at)->timezone('Asia/Jakarta')->format('d/m/Y H:i'),
                'is_read' => $message->read_at !== null,
            ]),
        ]);
    }

    public function messageHistory(Request $request, Meeting $meeting): JsonResponse
    {
        $this->authorizeMeetingChatParticipant($meeting);
        abort_unless((int) $meeting->status === 1, 404, 'Riwayat chat hanya tersedia setelah konsultasi selesai.');

        $validated = $request->validate([
            'before' => ['nullable', 'integer', 'min:1'],
        ]);
        $query = DB::table('consultation_messages')
            ->join('users', 'users.id', '=', 'consultation_messages.sender_user_id')
            ->where('consultation_messages.meeting_id', $meeting->id)
            ->when(isset($validated['before']), fn ($builder) => $builder->where('consultation_messages.id', '<', $validated['before']))
            ->select([
                'consultation_messages.id',
                'consultation_messages.sender_user_id',
                'consultation_messages.body',
                'consultation_messages.created_at',
                'users.name as sender_name',
            ]);
        $messages = $query->orderByDesc('consultation_messages.id')->limit(101)->get();
        $hasOlder = $messages->count() > 100;
        $messages = $messages->take(100)->reverse()->values();

        return response()->json([
            'has_older' => $hasOlder,
            'messages' => $messages->map(fn ($message) => [
                'id' => (int) $message->id,
                'sender_id' => (int) $message->sender_user_id,
                'sender_name' => $message->sender_name,
                'body' => $message->body,
                'is_mine' => (int) $message->sender_user_id === (int) Auth::id(),
                'sent_at' => Carbon::parse($message->created_at)->timezone('Asia/Jakarta')->format('d/m/Y H:i'),
            ]),
        ]);
    }

    public function messageStatus(Meeting $meeting): JsonResponse
    {
        $this->authorizeMeetingChatParticipant($meeting);
        abort_unless((int) $meeting->status === 2, 404, 'Chat hanya tersedia untuk konsultasi yang masih terkonfirmasi.');

        return response()->json([
            'unread_count' => DB::table('consultation_messages')
                ->where('meeting_id', $meeting->id)
                ->where('sender_user_id', '!=', Auth::id())
                ->whereNull('read_at')
                ->count(),
        ]);
    }

    public function sendMessage(Request $request, Meeting $meeting): JsonResponse
    {
        $this->authorizeMeetingChatParticipant($meeting);
        abort_unless((int) $meeting->status === 2, 404, 'Chat hanya tersedia untuk konsultasi yang masih terkonfirmasi.');

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $messageId = DB::table('consultation_messages')->insertGetId([
            'meeting_id' => $meeting->id,
            'sender_user_id' => Auth::id(),
            'body' => trim($validated['body']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => [
                'id' => $messageId,
                'sender_id' => (int) Auth::id(),
                'sender_name' => Auth::user()->name,
                'body' => trim($validated['body']),
                'is_mine' => true,
                'sent_at' => now('Asia/Jakarta')->format('d/m/Y H:i'),
                'is_read' => false,
            ],
        ], 201);
    }

    public function presence(Meeting $meeting): JsonResponse
    {
        abort_unless(
            (int) $meeting->user_id === (int) Auth::id() || Auth::user()->hasRole(['admin', 'super_admin']),
            403
        );
        $this->ensureRoomOpen($meeting);
        $participant = DB::table('meeting_room_participants')
            ->where('meeting_id', $meeting->id)
            ->where('user_id', Auth::id())
            ->first();
        $participantActive = $participant && !$participant->left_at && $participant->last_seen_at
            && Carbon::parse($participant->last_seen_at)->gte(now()->subSeconds(30));
        $sameClient = $participantActive
            && $participant->client_session_hash === hash('sha256', session()->getId());
        $activeCount = DB::table('meeting_room_participants')
            ->where('meeting_id', $meeting->id)
            ->whereNull('left_at')
            ->where('last_seen_at', '>=', now()->subSeconds(30))
            ->count();
        $capacity = min(4, 2 + DB::table('meeting_room_invites')->where('meeting_id', $meeting->id)->count());
        $isAuthorized = $this->isAuthorizedRoomMember($meeting, (int) Auth::id());
        $wasKicked = DB::table('meeting_room_kicks')->where('meeting_id', $meeting->id)
            ->where('user_id', Auth::id())->exists();

        return response()->json([
            'peer_present' => DB::table('meeting_room_participants')->where('meeting_id', $meeting->id)
                ->where('user_id', '!=', Auth::id())->whereNull('left_at')
                ->where('last_seen_at', '>=', now()->subSeconds(30))->exists(),
            'participant_count' => $activeCount,
            'capacity' => $capacity,
            'can_join' => $isAuthorized && !$wasKicked && ($sameClient || (!$participantActive && $activeCount < $capacity)),
        ]);
    }

    public function documentation(Meeting $meeting)
    {
        abort_unless(Auth::user()->hasRole(['admin', 'super_admin']), 403);

        if ($meeting->documentation_path && Str::startsWith($meeting->documentation_path, 'gdrive:')) {
            $fileId = substr($meeting->documentation_path, strlen('gdrive:'));
            abort_if($fileId === '', 404);

            return redirect()->away('https://drive.google.com/file/d/'.rawurlencode($fileId).'/view');
        }

        abort_unless($meeting->documentation_path && Storage::disk('local')->exists($meeting->documentation_path), 404);

        return Storage::disk('local')->download(
            $meeting->documentation_path,
            $meeting->documentation_original_name ?: basename($meeting->documentation_path)
        );
    }

    private function authorizeMeetingChatParticipant(Meeting $meeting): void
    {
        $isLegacyAdministrator = !$meeting->approved_by_user_id
            && Auth::user()->hasRole(['admin', 'super_admin']);
        abort_unless($this->isAuthorizedRoomMember($meeting, (int) Auth::id()) || $isLegacyAdministrator, 403);
    }

    private function authorizeParticipant(Meeting $meeting): void
    {
        abort_unless($this->isAuthorizedRoomMember($meeting, (int) Auth::id()), 403);
    }

    private function isRoomModerator(Meeting $meeting, int $userId): bool
    {
        $user = User::find($userId);
        if (!$user?->hasRole(['admin', 'super_admin'])) {
            return false;
        }

        return (int) $meeting->approved_by_user_id === $userId
            || DB::table('meeting_room_invites')->where('meeting_id', $meeting->id)->where('user_id', $userId)->exists();
    }

    private function isAuthorizedRoomMember(Meeting $meeting, int $userId): bool
    {
        if ((int) $meeting->user_id === $userId) {
            return true;
        }

        if (!Auth::user()->hasRole(['admin', 'super_admin'])) {
            return false;
        }

        return ((int) $meeting->approved_by_user_id === $userId)
            || DB::table('meeting_room_invites')
                ->where('meeting_id', $meeting->id)
                ->where('user_id', $userId)
                ->exists();
    }

    private function ensureJoinedRoom(Meeting $meeting): void
    {
        abort_unless(
            DB::table('meeting_room_participants')
                ->where('meeting_id', $meeting->id)
                ->where('user_id', Auth::id())
                ->where('client_session_hash', hash('sha256', session()->getId()))
                ->whereNull('left_at')
                ->where('last_seen_at', '>=', now()->subSeconds(30))
                ->exists(),
            403,
            'Perangkat ini tidak sedang bergabung ke ruang konsultasi.'
        );
    }

    private function activeRoomParticipants(Meeting $meeting): array
    {
        return DB::table('meeting_room_participants')
            ->join('users', 'users.id', '=', 'meeting_room_participants.user_id')
            ->where('meeting_room_participants.meeting_id', $meeting->id)
            ->whereNull('meeting_room_participants.left_at')
            ->where('meeting_room_participants.last_seen_at', '>=', now()->subSeconds(30))
            ->orderBy('meeting_room_participants.joined_at')
            ->get(['users.id', 'users.name', 'users.picture'])
            ->map(fn ($participant) => [
                'id' => (int) $participant->id,
                'name' => $participant->name,
                'picture' => $participant->picture,
                'is_requester' => (int) $participant->id === (int) $meeting->user_id,
            ])
            ->all();
    }

    private function ensureRoomOpen(Meeting $meeting): void
    {
        abort_unless((int) $meeting->status === 2, 404);
        abort_unless($meeting->isRoomOpen(), 403, 'Ruang konsultasi hanya tersedia pada tanggal dan jam yang telah dikonfirmasi.');
    }

    private function touchRoomParticipant(Meeting $meeting): void
    {
        DB::table('meeting_room_participants')->where('meeting_id', $meeting->id)->where('user_id', Auth::id())
            ->whereNull('left_at')->where('last_seen_at', '<=', now()->subSeconds(8))
            ->update(['last_seen_at' => now(), 'updated_at' => now()]);
    }

    private function notifyConfirmed(Meeting $meeting): void
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('telegram_bot')) {
            return;
        }
        $telegram = TelegramBot::first();
        if (!$telegram?->token || !$telegram?->group_id) {
            return;
        }

        try {
            Http::timeout(8)->post("https://api.telegram.org/bot{$telegram->token}/sendMessage", [
                'chat_id' => $telegram->group_id,
                'text' => "Konsultasi dikonfirmasi.\nPemohon: ".($meeting->user?->name ?? 'Pengguna')."\nTopik: {$meeting->name}\nPetugas: {$meeting->assigned_staff}\nJadwal: {$meeting->tanggal} {$meeting->start_time}-{$meeting->end_time} WIB\nRuang: ".route('konsultasi.room', $meeting),
            ]);
        } catch (\Illuminate\Http\Client\ConnectionException $exception) {
            report($exception);
        }
    }

    private function availableStaffFor(Meeting $meeting, Collection $staff): array
    {
        $busyStaff = Meeting::query()
            ->whereIn('assigned_staff_id', $staff->pluck('id'))
            ->where('status', 2)
            ->whereDate('tanggal', $meeting->tanggal)
            ->where('start_time', '<', $meeting->end_time)
            ->where('end_time', '>', $meeting->start_time)
            ->pluck('assigned_staff_id')
            ->all();

        return $staff->reject(fn (ConsultationStaff $staffMember) => in_array($staffMember->id, $busyStaff))
            ->pluck('name')
            ->all();
    }
}
