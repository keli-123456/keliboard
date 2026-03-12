<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RiskEventService
{
    private const MAX_UA_CHARS = 2000;
    private const MAX_ROUTE_CHARS = 128;
    private const MAX_META_BYTES = 60000;

    public static function record(string $eventType, array $payload = []): void
    {
        $eventType = trim($eventType);
        if ($eventType === '') {
            return;
        }

        try {
            $enabledRaw = admin_setting('risk_center_enable', false);
            $enabled = filter_var($enabledRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($enabled === null) {
                $enabled = (bool) $enabledRaw;
            }
            if (!$enabled) {
                return;
            }
        } catch (\Throwable) {
            // If settings cannot be loaded, keep primary flow safe.
        }

        try {
            $now = time();

            $ua = self::normalizeString($payload['ua'] ?? null, self::MAX_UA_CHARS);
            $uaHash = $ua !== null && $ua !== '' ? hash('sha256', $ua) : null;

            $tokenHash = self::normalizeString($payload['token_hash'] ?? null, 64);
            if (!$tokenHash) {
                $token = self::normalizeString($payload['token'] ?? null, 128);
                $tokenHash = $token ? hash('sha256', $token) : null;
            }

            $meta = $payload['meta'] ?? null;
            if (is_array($meta)) {
                $meta = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            } elseif (!is_string($meta)) {
                $meta = null;
            }

            $meta = self::truncateBytes($meta, self::MAX_META_BYTES);

            DB::table('v2_risk_event')->insert([
                'event_type' => Str::limit($eventType, 32, ''),
                'user_id' => isset($payload['user_id']) && is_numeric($payload['user_id']) ? (int) $payload['user_id'] : null,
                'token_hash' => $tokenHash ?: null,
                'ip' => self::normalizeString($payload['ip'] ?? null, 45),
                'ua' => $ua ?: null,
                'ua_hash' => $uaHash ?: null,
                'client_name' => self::normalizeString($payload['client_name'] ?? null, 32),
                'client_version' => self::normalizeString($payload['client_version'] ?? null, 32),
                'route' => self::normalizeString($payload['route'] ?? null, self::MAX_ROUTE_CHARS),
                'status_code' => isset($payload['status_code']) && is_numeric($payload['status_code']) ? (int) $payload['status_code'] : null,
                'meta' => $meta,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (\Throwable $e) {
            // Never block the primary flow (login/subscribe).
        }
    }

    private static function normalizeString(mixed $value, int $maxChars): ?string
    {
        if ($value === null) {
            return null;
        }
        $str = trim((string) $value);
        if ($str === '') {
            return null;
        }
        if ($maxChars <= 0) {
            return '';
        }
        if (Str::length($str) <= $maxChars) {
            return $str;
        }
        return Str::substr($str, 0, $maxChars);
    }

    private static function truncateBytes(?string $value, int $maxBytes): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }
        if ($maxBytes <= 0) {
            return '';
        }
        if (strlen($value) <= $maxBytes) {
            return $value;
        }
        return substr($value, 0, $maxBytes);
    }
}
