<?php

return [
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
