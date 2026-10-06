<?php

namespace App\Http\Controllers;

use App\Models\Meeting;
use App\Models\TelegramBot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class KonsultasiController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        if ($user->hasRole(['admin', 'super_admin'])) {
            return redirect()->route('admin.konsultasi');
        }

        $data_mendatang = Meeting::where('user_id', $user->id)
            ->whereIn('status', [0, 2])
            ->orderBy('tanggal')
            ->orderBy('start_time')
            ->paginate(10, ['*'], 'jadwal_page');

        $data_riwayat = Meeting::where('user_id', $user->id)
            ->whereIn('status', [1, 9])
            ->orderByDesc('tanggal')
            ->orderByDesc('start_time')
            ->paginate(10, ['*'], 'riwayat_page');
        $data_mendatang->getCollection()->transform(function (Meeting $meeting) {
            $start = Carbon::parse($meeting->tanggal, 'Asia/Jakarta')->setTimeFromTimeString($meeting->start_time);
            $end = Carbon::parse($meeting->tanggal, 'Asia/Jakarta')->setTimeFromTimeString($meeting->end_time);
            $meeting->setAttribute('room_open', (int) $meeting->status === 2 && now('Asia/Jakarta')->betweenIncluded($start, $end));

            return $meeting;
        });

        return view('konsultasi.index', compact('data_mendatang','data_riwayat', 'user'));
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'deskripsi' => ['required', 'string', 'min:15', 'max:3000'],
                'tanggal' => ['required', 'date', 'after_or_equal:today'],
                'waktu_mulai' => ['required', 'date_format:H:i'],
                'waktu_selesai' => ['required', 'date_format:H:i', 'after:waktu_mulai'],
            ]);

            if ($validated['tanggal'] === now()->toDateString() && $validated['waktu_mulai'] <= now()->format('H:i')) {
                throw ValidationException::withMessages(['waktu_mulai' => 'Pilih waktu konsultasi yang masih akan datang.']);
            }

            Meeting::create([
                'user_id' => Auth::id(),
                'name' => $validated['name'],
                'description' => $validated['deskripsi'],
                'tanggal' => $validated['tanggal'],
                'start_time' => $validated['waktu_mulai'],
                'end_time' => $validated['waktu_selesai'],
                'google_event_id' => null,
                'google_meet_link' => null,
                'status' => 0
            ]);

            $this->sendTelegramNotification($request);

            return redirect()->back()->with(
                'message',
                'Event berhasil dibuat!'
            );
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {

            return redirect()->back()->with(
                'error',
                $e->getMessage()
            );
        }
    }

    public function rating_post(Request $request, int $id)
    {
        $meeting = Meeting::whereKey($id)->where('user_id', Auth::id())->firstOrFail();
        abort_unless((int) $meeting->status === 1, 403, 'Rating hanya tersedia setelah konsultasi selesai.');
        $validated = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'kritik' => ['nullable', 'string', 'max:2000'],
        ]);

        $meeting->update([
            'rating' => $validated['rating'],
            'kritik_saran' => $validated['kritik'] ?? null,
        ]);

        return redirect()->back()->with('message', 'Terima kasih. Penilaian konsultasi Anda berhasil disimpan.');
    }

    private function sendTelegramNotification(Request $request,  ?string $meetLink = null)
    {
        if (!Schema::hasTable('telegram_bot')) {
            return;
        }

        $telegram = TelegramBot::first();
        if (!$telegram || !$telegram->token || !$telegram->group_id) {
            return;
        }

        $message = "Halo Admin permintaan konsultasi baru." .
            "\nUser : " . Auth::user()->name .
            "\nKonsultasi : " . $request->name .
            "\nDeskripsi : " . $request->deskripsi .
            "\nTanggal : " . $request->tanggal .
            "\nJam : " . $request->waktu_mulai .
            "\nLink : " . $meetLink;

        try {
            Http::timeout(8)->post(
                "https://api.telegram.org/bot{$telegram->token}/sendMessage",
                [
                'chat_id' => $telegram->group_id,
                'text' => $message
                ]
            );
        } catch (\Illuminate\Http\Client\ConnectionException $exception) {
            report($exception);
        }
    }
}
