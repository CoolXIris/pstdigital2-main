<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ConsultationMeetingNotice extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $noticeType,
        public string $recipientRole,
        public string $recipientName,
        public string $topic,
        public string $scheduleDate,
        public string $scheduleTime,
        public string $staffName,
        public string $actionUrl,
    ) {}

    public function envelope(): Envelope
    {
        $subject = $this->noticeType === 'reminder'
            ? 'Pengingat: konsultasi dimulai sekitar 10 menit lagi'
            : 'Sesi konsultasi Anda telah disetujui';
        $senderName = (string) config('mail.from.name', config('app.name', 'PST Digital'));
        if ($senderName === '' || str_contains($senderName, '${APP_NAME}') || str_contains($senderName, '$APP_NAME')) {
            $senderName = (string) config('app.name', 'PST Digital');
        }

        return new Envelope(
            from: new Address((string) config('mail.from.address'), $senderName),
            subject: $subject.' - '.$this->topic,
        );
    }

    public function content(): Content
    {
        $isReminder = $this->noticeType === 'reminder';

        return new Content(
            view: 'emails.consultation-meeting-notice',
            with: [
                'heading' => $isReminder ? 'Konsultasi segera dimulai' : 'Konsultasi telah disetujui',
                'greeting' => 'Halo '.$this->recipientName.',',
                'bodyText' => $this->recipientRole === 'admin'
                    ? ($isReminder
                        ? 'Sesi konsultasi yang Anda setujui akan segera dimulai. Silakan masuk ke halaman manajemen konsultasi untuk bergabung.'
                        : 'Anda telah menyetujui sesi konsultasi berikut. Informasi jadwal dan petugas sudah tersedia di halaman manajemen konsultasi.')
                    : ($isReminder
                        ? 'Sesi konsultasi Anda akan segera dimulai. Buka halaman konsultasi PST Digital untuk masuk ke ruang meeting.'
                        : 'Permintaan sesi konsultasi Anda telah disetujui. Simpan informasi jadwal berikut dan buka halaman konsultasi saat waktunya tiba.'),
                'buttonLabel' => $this->recipientRole === 'admin' ? 'Buka manajemen konsultasi' : 'Buka konsultasi',
            ],
        );
    }
}
