<?php

namespace App\Services;

use App\Mail\ConsultationMeetingNotice;
use App\Models\Meeting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

class ConsultationMeetingNotificationService
{
    public function sendApprovalNotifications(Meeting $meeting): void
    {
        $meeting->loadMissing('user');
        $this->sendForRecipient(
            $meeting,
            'approval_user_notified_at',
            'user',
            $meeting->user?->email,
            $meeting->user?->name ?: 'Pengguna',
            'approved',
        );
        $this->sendForRecipient(
            $meeting,
            'approval_admin_notified_at',
            'admin',
            $meeting->approved_by_email,
            $meeting->approved_by_name ?: 'Admin',
            'approved',
        );
    }

    public function sendReminderNotifications(Meeting $meeting): void
    {
        $meeting->loadMissing('user');
        $this->sendForRecipient(
            $meeting,
            'reminder_user_notified_at',
            'user',
            $meeting->user?->email,
            $meeting->user?->name ?: 'Pengguna',
            'reminder',
        );
        $this->sendForRecipient(
            $meeting,
            'reminder_admin_notified_at',
            'admin',
            $meeting->approved_by_email,
            $meeting->approved_by_name ?: 'Admin',
            'reminder',
        );
    }

    private function sendForRecipient(
        Meeting $meeting,
        string $sentColumn,
        string $recipientRole,
        ?string $email,
        string $recipientName,
        string $noticeType,
    ): void {
        if ($meeting->{$sentColumn} !== null) {
            return;
        }

        if (!$email) {
            DB::table('meeting')->where('id', $meeting->id)->whereNull($sentColumn)->update([$sentColumn => now()]);
            return;
        }

        try {
            $isAdmin = $recipientRole === 'admin';
            $scheduleDate = Carbon::parse($meeting->tanggal, 'Asia/Jakarta')->locale('id')->translatedFormat('l, d F Y');
            Mail::to($email)->send(new ConsultationMeetingNotice(
                noticeType: $noticeType,
                recipientRole: $recipientRole,
                recipientName: $recipientName,
                topic: $meeting->name,
                scheduleDate: $scheduleDate,
                scheduleTime: substr((string) $meeting->start_time, 0, 5).' - '.substr((string) $meeting->end_time, 0, 5).' WIB',
                staffName: $meeting->assigned_staff ?: 'Belum ditentukan',
                actionUrl: $isAdmin ? route('admin.konsultasi') : url('/konsultasi'),
            ));

            DB::table('meeting')->where('id', $meeting->id)->whereNull($sentColumn)->update([$sentColumn => now()]);
            $meeting->setAttribute($sentColumn, now());
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
