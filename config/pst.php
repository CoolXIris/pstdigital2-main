<?php

return [
    'consultation_start_times' => ['09:00', '10:00', '11:00', '13:00', '14:00', '15:00'],
    'consultation_duration_minutes' => 45,
    // Hari libur nasional dan cuti bersama Indonesia. Perbarui daftar ini saat SKB tahunan terbit.
    // Sumber 2026: https://www.setneg.go.id/baca/index/inilah_skb_3_menteri_libur_nasional_dan_cuti_bersama_2026
    // Sumber 2027: https://setneg.go.id/baca/index/inilah_skb_3_menteri_libur_nasional_dan_cuti_bersama_2027
    'consultation_holidays' => [
        '2026-01-01', '2026-01-16', '2026-02-16', '2026-02-17', '2026-03-18', '2026-03-19',
        '2026-03-20', '2026-03-21', '2026-03-22', '2026-03-23', '2026-03-24', '2026-04-03',
        '2026-04-05', '2026-05-01', '2026-05-14', '2026-05-15', '2026-05-27', '2026-05-28',
        '2026-05-31', '2026-06-01', '2026-06-16', '2026-08-17', '2026-08-25', '2026-12-24',
        '2026-12-25',
        '2027-01-01', '2027-01-05', '2027-02-05', '2027-02-06', '2027-03-08', '2027-03-09',
        '2027-03-10', '2027-03-11', '2027-03-12', '2027-03-15', '2027-03-25', '2027-03-26',
        '2027-05-01', '2027-05-06', '2027-05-17', '2027-05-18', '2027-05-19', '2027-05-20',
        '2027-06-01', '2027-06-06', '2027-08-15', '2027-08-17', '2027-12-24', '2027-12-25',
        '2027-12-26',
    ],
    'consultation_staff' => [
        'Petugas 1 (Aris Wijayanto)',
        'Petugas 2 (Nadia Qathrunnada)',
        'Petugas 3 (M. Irfan Zulfahmi)',
        'Petugas 4 (S. Abdurrahman)',
        'Petugas 5 (Telly Dwi Nataliza)',
        'Petugas 6 (Zahirah Husin)',
    ],
    'ice_servers' => (static function (): array {
        $raw = trim((string) env('WEBRTC_ICE_SERVERS', ''));
        if ($raw === '') {
            return [];
        }

        $normalise = static function ($decoded): array {
            if (!is_array($decoded)) {
                return [];
            }

            $servers = isset($decoded['urls']) ? [$decoded] : $decoded;

            return array_values(array_filter($servers, static fn ($server): bool =>
                is_array($server)
                && isset($server['urls'])
                && (is_string($server['urls']) || is_array($server['urls']))
            ));
        };

        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $normalise($decoded);
        }

        // Also accept individual JSON objects separated by "|" for easier .env editing.
        $servers = [];
        foreach (explode('|', $raw) as $entry) {
            $decoded = json_decode(trim($entry), true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $servers = array_merge($servers, $normalise($decoded));
            }
        }

        return $servers;
    })(),
    'turn_shared_secret' => env('TURN_SHARED_SECRET', ''),
    'turn_credential_ttl' => max(300, (int) env('TURN_CREDENTIAL_TTL', 21600)),
];
