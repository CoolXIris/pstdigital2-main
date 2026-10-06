<?php

namespace App\Http\Controllers;

use App\Models\Meeting;
use App\Models\TelegramBot;
use App\Services\ConsultationDocumentationService;
use App\Services\ConsultationMeetingNotificationService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class KonsultasiAdminController extends Controller
{
    private const ROOM_PROTOCOL_VERSION = 'sdp-stream-v2';

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
            $meeting->setAttribute('room_open', $this->isRoomOpen($meeting));

            return $meeting;
        });
        $history = $baseQuery()->whereIn('status', [1, 9])->orderByDesc('tanggal')->orderByDesc('start_time')->paginate(12, ['*'], 'history_page')->withQueryString();
        $staff = config('pst.consultation_staff', []);
        $upcoming->getCollection()->transform(function (Meeting $meeting) {
            $meeting->setAttribute('room_open', $this->isRoomOpen($meeting));

            return $meeting;
        });
        $availableStaff = [];
        foreach ($pending as $meeting) {
            $availableStaff[$meeting->id] = $this->availableStaffFor($meeting, $staff);
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

    public function updateStatus(Request $request, Meeting $meeting, ConsultationDocumentationService $documentation, ConsultationMeetingNotificationService $notifications): RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['confirm', 'complete', 'cancel'])],
            'assigned_staff' => ['required_if:action,confirm', 'nullable', Rule::in(config('pst.consultation_staff', []))],
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
                $storedDocumentation = $documentation->store($file, $meeting->id);
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

                    $staffName = $validated['assigned_staff'];
                    $conflict = Meeting::query()
                        ->where('assigned_staff', $staffName)
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
                Storage::disk('local')->delete($storedDocumentation);
            }

            return back()->with('error', 'Tidak dapat memperbarui konsultasi. Silakan coba kembali.');
        } catch (\Throwable $exception) {
            if ($storedDocumentation) {
                Storage::disk('local')->delete($storedDocumentation);
            }
            throw $exception;
        }

        if ($validated['action'] === 'confirm') {
            $confirmedMeeting = $meeting->fresh();
            $this->notifyConfirmed($confirmedMeeting);
            $notifications->sendApprovalNotifications($confirmedMeeting);
        }

        $message = match ($validated['action']) {
            'confirm' => 'Konsultasi dikonfirmasi dan petugas berhasil ditugaskan.',
            'complete' => 'Konsultasi ditandai selesai dan dipindahkan ke riwayat.',
            default => 'Permintaan konsultasi berhasil dibatalkan.',
        };

        return back()->with('message', $message);
    }

    private function isRoomOpen(Meeting $meeting): bool
    {
        $start = Carbon::parse($meeting->tanggal, 'Asia/Jakarta')->setTimeFromTimeString($meeting->start_time);
        $end = Carbon::parse($meeting->tanggal, 'Asia/Jakarta')->setTimeFromTimeString($meeting->end_time);

        return (int) $meeting->status === 2 && now('Asia/Jakarta')->betweenIncluded($start, $end);
    }

    public function room(Meeting $meeting): View
    {
        $this->authorizeParticipant($meeting);
        $this->ensureRoomOpen($meeting);

        $sessionId = DB::transaction(function () use ($meeting) {
            $lockedMeeting = Meeting::query()->whereKey($meeting->id)->lockForUpdate()->firstOrFail();
            $protocolChanged = $lockedMeeting->room_protocol_version !== self::ROOM_PROTOCOL_VERSION;
            $leaseExpired = !$lockedMeeting->room_last_seen_at
                || Carbon::parse($lockedMeeting->room_last_seen_at)->lt(now()->subSeconds(90));
            $existingParticipant = DB::table('meeting_room_participants')
                ->where('meeting_id', $meeting->id)
                ->where('user_id', Auth::id())
                ->lockForUpdate()
                ->first();
            if (!$lockedMeeting->room_session_id || $leaseExpired || $protocolChanged) {
                $lockedMeeting->room_session_id = (string) Str::uuid();
                DB::table('meeting_signals')->where('meeting_id', $meeting->id)->delete();
            }

            $lockedMeeting->room_protocol_version = self::ROOM_PROTOCOL_VERSION;
            $lockedMeeting->room_last_seen_at = now();
            $lockedMeeting->save();

            DB::table('meeting_room_participants')->updateOrInsert(
                ['meeting_id' => $meeting->id, 'user_id' => Auth::id()],
                ['joined_at' => now(), 'last_seen_at' => now(), 'left_at' => null, 'updated_at' => now(), 'created_at' => $existingParticipant?->created_at ?? now()]
            );

            return $lockedMeeting->room_session_id;
        });
        $peerPresent = $this->peerIsPresent($meeting);

        return view('konsultasi.room', [
            'meeting' => $meeting->load('user:id,name'),
            'isRequester' => (int) $meeting->user_id === (int) Auth::id(),
            'returnUrl' => Auth::user()->hasRole(['admin', 'super_admin']) ? route('admin.konsultasi') : url('konsultasi'),
            'roomSessionId' => $sessionId,
            'peerPresent' => $peerPresent,
            'iceServers' => config('pst.ice_servers', []),
        ]);
    }

    public function signals(Request $request, Meeting $meeting): JsonResponse
    {
        $this->authorizeParticipant($meeting);
        $this->ensureRoomOpen($meeting);

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
            $peerPresent = $this->peerIsPresent($meeting);
            Meeting::whereKey($meeting->id)
                ->where(function ($query) {
                    $query->whereNull('room_last_seen_at')
                        ->orWhere('room_last_seen_at', '<=', now()->subSeconds(30));
                })
                ->update(['room_last_seen_at' => now()]);
            $signals = DB::table('meeting_signals')->where('meeting_id', $meeting->id)
                ->where('session_id', $validated['session_id'])
                ->where('sender_user_id', '!=', Auth::id())
                ->where('id', '>', $validated['after'] ?? 0)
                ->orderBy('id')->limit(100)->get(['id', 'signal_type', 'payload']);

            return response()->json(['peer_present' => $peerPresent, 'signals' => $signals->map(fn ($signal) => [
                'id' => $signal->id,
                'type' => $signal->signal_type,
                'payload' => json_decode($signal->payload, true),
            ])]);
        }

        $validated = $request->validate([
            'type' => ['required', Rule::in(['description', 'candidate', 'media-state', 'leave'])],
            'payload' => ['required_unless:type,leave', 'nullable', 'array'],
            'session_id' => ['required', 'uuid'],
        ]);
        abort_unless(hash_equals((string) $meeting->room_session_id, $validated['session_id']), 409, 'Sesi ruang telah diperbarui. Muat ulang halaman meeting.');
        if ($validated['type'] === 'leave') {
            DB::transaction(function () use ($meeting) {
                DB::table('meeting_room_participants')->where('meeting_id', $meeting->id)->where('user_id', Auth::id())
                    ->update(['left_at' => now(), 'last_seen_at' => now(), 'updated_at' => now()]);
                $meeting->room_session_id = (string) Str::uuid();
                $meeting->room_last_seen_at = now();
                $meeting->save();
                DB::table('meeting_signals')->where('meeting_id', $meeting->id)->delete();
            });

            return response()->json(['left' => true]);
        }
        Meeting::whereKey($meeting->id)->update(['room_last_seen_at' => now()]);
        if (strlen(json_encode($validated['payload']) ?: '') > 100000) {
            return response()->json(['message' => 'Data signaling terlalu besar.'], 413);
        }

        DB::table('meeting_signals')->insert([
            'meeting_id' => $meeting->id,
            'session_id' => $validated['session_id'],
            'sender_user_id' => Auth::id(),
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
        $this->authorizeParticipant($meeting);
        $this->ensureRoomOpen($meeting);

        return response()->json(['peer_present' => $this->peerIsPresent($meeting)]);
    }

    public function documentation(Meeting $meeting)
    {
        $this->authorizeParticipant($meeting);
        abort_unless($meeting->documentation_path && Storage::disk('local')->exists($meeting->documentation_path), 404);

        return Storage::disk('local')->download(
            $meeting->documentation_path,
            $meeting->documentation_original_name ?: basename($meeting->documentation_path)
        );
    }

    private function authorizeMeetingChatParticipant(Meeting $meeting): void
    {
        $isRequester = (int) $meeting->user_id === (int) Auth::id();
        $isApprover = $meeting->approved_by_user_id
            && (int) $meeting->approved_by_user_id === (int) Auth::id();
        $isLegacyAdministrator = !$meeting->approved_by_user_id
            && Auth::user()->hasRole(['admin', 'super_admin']);
        abort_unless($isRequester || $isApprover || $isLegacyAdministrator, 403);
    }

    private function authorizeParticipant(Meeting $meeting): void
    {
        $isRequester = (int) $meeting->user_id === (int) Auth::id();
        $isAdministrator = Auth::user()->hasRole(['admin', 'super_admin']);
        abort_unless($isRequester || $isAdministrator, 403);
    }

    private function ensureRoomOpen(Meeting $meeting): void
    {
        abort_unless((int) $meeting->status === 2, 404);
        abort_unless($this->isRoomOpen($meeting), 403, 'Ruang konsultasi hanya tersedia pada tanggal dan jam yang telah dikonfirmasi.');
    }

    private function touchRoomParticipant(Meeting $meeting): void
    {
        DB::table('meeting_room_participants')->where('meeting_id', $meeting->id)->where('user_id', Auth::id())
            ->whereNull('left_at')->where('last_seen_at', '<=', now()->subSeconds(8))
            ->update(['last_seen_at' => now(), 'updated_at' => now()]);
    }

    private function peerIsPresent(Meeting $meeting): bool
    {
        return DB::table('meeting_room_participants')
            ->where('meeting_id', $meeting->id)
            ->where('user_id', '!=', Auth::id())
            ->whereNull('left_at')
            ->where('last_seen_at', '>=', now()->subSeconds(15))
            ->exists();
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

    private function availableStaffFor(Meeting $meeting, array $staff): array
    {
        $busyStaff = Meeting::query()
            ->whereIn('assigned_staff', $staff)
            ->where('status', 2)
            ->whereDate('tanggal', $meeting->tanggal)
            ->where('start_time', '<', $meeting->end_time)
            ->where('end_time', '>', $meeting->start_time)
            ->pluck('assigned_staff')
            ->all();

        return array_values(array_diff($staff, $busyStaff));
    }
}
