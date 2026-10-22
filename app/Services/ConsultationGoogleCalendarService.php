<?php

namespace App\Services;

use App\Models\Meeting;
use App\Models\User;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class ConsultationGoogleCalendarService
{
    private const CALENDAR_EVENTS_URL = 'https://www.googleapis.com/calendar/v3/calendars/primary/events';

    /**
     * Create a private reminder in the calendar of the staff member who approved the meeting.
     * The deterministic event ID makes retries safe if Google created the event but saving its ID failed.
     */
    public function createForApprover(Meeting $meeting): string
    {
        if (filled($meeting->google_event_id)) {
            return 'already_created';
        }

        $approver = $meeting->approved_by_user_id
            ? User::find($meeting->approved_by_user_id)
            : null;

        if (!$approver || !filled($approver->google_access_token)) {
            return 'unavailable';
        }

        $eventId = substr(hash('sha256', 'pst-consultation-'.$meeting->id), 0, 40);

        try {
            $response = $this->calendarRequest($approver, 'POST', self::CALENDAR_EVENTS_URL, $this->eventPayload($meeting, $eventId));

            if ($response?->status() === 409) {
                $response = $this->calendarRequest($approver, 'GET', self::CALENDAR_EVENTS_URL.'/'.$eventId);
                if ($response?->successful()) {
                    $this->saveEventId($meeting, $eventId);

                    return 'already_created';
                }
            }

            if (!$response?->successful()) {
                Log::warning('Google Calendar event creation failed for a consultation.', [
                    'meeting_id' => $meeting->id,
                    'approver_user_id' => $approver->id,
                    'http_status' => $response?->status(),
                    'google_error_reason' => $response?->json('error.errors.0.reason'),
                    'google_error_message' => $response?->json('error.message'),
                ]);

                return 'failed';
            }

            $createdEventId = $response->json('id') ?: $eventId;
            $this->saveEventId($meeting, $createdEventId);

            return 'created';
        } catch (Throwable $exception) {
            report($exception);

            return 'failed';
        }
    }

    private function eventPayload(Meeting $meeting, string $eventId): array
    {
        $timezone = 'Asia/Jakarta';
        $start = Carbon::parse($meeting->tanggal, $timezone)->setTimeFromTimeString((string) $meeting->start_time);
        $end = Carbon::parse($meeting->tanggal, $timezone)->setTimeFromTimeString((string) $meeting->end_time);
        $requester = $meeting->user()->first();

        return [
            'id' => $eventId,
            'summary' => 'Konsultasi Statistik: '.$meeting->name,
            'description' => implode("\n", array_filter([
                'Sesi konsultasi yang telah dikonfirmasi melalui PST Digital.',
                'Pengguna: '.($requester?->name ?: 'Pengguna'),
                $requester?->email ? 'Email pengguna: '.$requester->email : null,
                'Petugas: '.($meeting->assigned_staff ?: 'Belum ditentukan'),
                '',
                'Deskripsi kebutuhan:',
                (string) $meeting->description,
            ], fn ($line) => $line !== null)),
            'start' => [
                'dateTime' => $start->toRfc3339String(),
                'timeZone' => $timezone,
            ],
            'end' => [
                'dateTime' => $end->toRfc3339String(),
                'timeZone' => $timezone,
            ],
            'status' => 'confirmed',
            'reminders' => [
                'useDefault' => false,
                'overrides' => [
                    ['method' => 'popup', 'minutes' => 30],
                    ['method' => 'email', 'minutes' => 30],
                ],
            ],
        ];
    }

    private function calendarRequest(User $user, string $method, string $url, array $payload = []): ?Response
    {
        $response = $this->sendCalendarRequest($user->google_access_token, $method, $url, $payload);
        if ($response->status() !== 401) {
            return $response;
        }

        $refreshedToken = $this->refreshAccessToken($user);
        if (!$refreshedToken) {
            return $response;
        }

        return $this->sendCalendarRequest($refreshedToken, $method, $url, $payload);
    }

    private function sendCalendarRequest(string $accessToken, string $method, string $url, array $payload = []): Response
    {
        $request = Http::acceptJson()->timeout(15)->withToken($accessToken);

        return strtoupper($method) === 'GET'
            ? $request->get($url)
            : $request->post($url, $payload);
    }

    private function refreshAccessToken(User $user): ?string
    {
        if (!filled($user->google_refresh_token)
            || !config('services.google.client_id')
            || !config('services.google.client_secret')) {
            return null;
        }

        $response = Http::asForm()->acceptJson()->timeout(15)->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'refresh_token' => $user->google_refresh_token,
            'grant_type' => 'refresh_token',
        ]);

        $accessToken = $response->successful() ? $response->json('access_token') : null;
        if (!is_string($accessToken) || $accessToken === '') {
            Log::warning('Google OAuth token refresh failed for a consultation calendar.', [
                'approver_user_id' => $user->id,
                'http_status' => $response->status(),
            ]);

            return null;
        }

        $user->google_access_token = $accessToken;
        $user->save();

        return $accessToken;
    }

    private function saveEventId(Meeting $meeting, string $eventId): void
    {
        DB::table('meeting')
            ->where('id', $meeting->id)
            ->whereNull('google_event_id')
            ->update(['google_event_id' => $eventId]);

        $meeting->refresh();
    }
}
