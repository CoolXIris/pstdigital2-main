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
        'Petugas 1',
        'Petugas 2',
        'Petugas 3',
        'Petugas 4',
        'Petugas 5',
        'Petugas 6',
    ],
    'ice_servers' => array_values(array_filter(array_map(
        static function (string $server): ?array {
            $decoded = json_decode($server, true);

            return is_array($decoded) ? $decoded : null;
        },
        explode('|', env('WEBRTC_ICE_SERVERS', ''))
    ))),
];
