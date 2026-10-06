<?php

namespace App\Console\Commands;

use App\Models\Meeting;
use App\Services\ConsultationMeetingNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SendConsultationReminders extends Command
{
    protected $signature = 'consultations:send-notifications';

    protected $description = 'Send pending consultation approval notices and reminders before meetings';

    public function handle(ConsultationMeetingNotificationService $notifications): int
    {
        $now = Carbon::now('Asia/Jakarta');
        $lastRelevantDate = $now->copy()->addMinutes(10)->toDateString();

        $meetings = Meeting::query()
            ->with('user')
            ->where('status', 2)
            ->whereNotNull('approved_by_user_id')
            ->whereDate('tanggal', '>=', $now->toDateString())
            ->where(function ($query) use ($now, $lastRelevantDate) {
                $query->where(function ($approval) {
                    $approval->whereNull('approval_user_notified_at')
                        ->orWhereNull('approval_admin_notified_at');
                })->orWhere(function ($reminder) use ($now, $lastRelevantDate) {
                    $reminder->whereDate('tanggal', '<=', $lastRelevantDate)
                        ->where(function ($pending) {
                            $pending->whereNull('reminder_user_notified_at')
                                ->orWhereNull('reminder_admin_notified_at');
                        });
                });
            })
            ->get();

        foreach ($meetings as $meeting) {
            $notifications->sendApprovalNotifications($meeting);

            $startsAt = Carbon::parse($meeting->tanggal, 'Asia/Jakarta')
                ->setTimeFromTimeString((string) $meeting->start_time);
            if ($startsAt->greaterThan($now) && $startsAt->lessThanOrEqualTo($now->copy()->addMinutes(10))) {
                $notifications->sendReminderNotifications($meeting);
            }
        }

        return self::SUCCESS;
    }
}
