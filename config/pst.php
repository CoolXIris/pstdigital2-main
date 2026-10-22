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
