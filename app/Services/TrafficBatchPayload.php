<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;
use OverflowException;

final class TrafficBatchPayload
{
    public const MAX_USERS = 1000;
    public const MAX_BODY_BYTES = 262144;

    public static function reportId(mixed $id): string
    {
        if (!is_string($id) || !preg_match('/\A[0-9a-f]{32}\z/', $id)) {
            throw new InvalidArgumentException('Invalid traffic report ID.');
        }
        return $id;
    }

    public static function normalize(mixed $traffic): array
    {
        if (!is_array($traffic) || !$traffic || count($traffic) > self::MAX_USERS) {
            throw new InvalidArgumentException('A traffic batch must contain 1 to 1000 users.');
        }
        $rows = [];
        $upload = $download = 0;
        foreach ($traffic as $id => $bytes) {
            if (!preg_match('/\A[1-9][0-9]{0,9}\z/', (string) $id) || (int) $id > 4294967295
                || !is_array($bytes) || array_keys($bytes) !== [0, 1]
                || !is_int($bytes[0]) || !is_int($bytes[1])
                || $bytes[0] < 0 || $bytes[1] < 0 || ($bytes[0] === 0 && $bytes[1] === 0)) {
                throw new InvalidArgumentException('Traffic requires positive user IDs and nonnegative integer byte pairs.');
            }
            $upload = self::add($upload, $bytes[0]);
            $download = self::add($download, $bytes[1]);
            $rows[(int) $id] = $bytes;
        }
        ksort($rows, SORT_NUMERIC);
        return $rows;
    }

    public static function contentHash(array $traffic): string
    {
        $body = "keli-traffic-v1\n";
        foreach ($traffic as $id => [$upload, $download]) {
            $body .= "$id:$upload:$download\n";
        }
        return hash('sha256', $body);
    }

    public static function rate(mixed $rate): int
    {
        if (!is_numeric($rate) || !is_finite((float) $rate) || (float) $rate < 0
            || (float) $rate > 999999.99) {
            throw new InvalidArgumentException('Traffic rate is outside the supported decimal range.');
        }
        $cents = (int) round((float) $rate * 100);
        if ((float) ($cents / 100) !== (float) $rate) {
            throw new InvalidArgumentException('Traffic rate must fit the statistics two-decimal schema.');
        }
        return $cents;
    }

    public static function rateString(int $cents): string
    {
        return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function scale(int $bytes, int $cents): int
    {
        if ($bytes < 0 || $cents < 0 || $cents > 99999999) {
            throw new InvalidArgumentException('Invalid scaled traffic.');
        }
        $whole = intdiv($bytes, 100);
        if ($cents !== 0 && $whole > intdiv(PHP_INT_MAX, $cents)) {
            throw new OverflowException('Scaled traffic exceeds signed 64-bit range.');
        }
        // Positive half-up rounding matches integral MySQL byte columns, without float loss.
        return self::add($whole * $cents, intdiv(($bytes % 100) * $cents + 50, 100));
    }

    public static function add(int $left, int $right): int
    {
        if ($left < 0 || $right < 0 || $left > PHP_INT_MAX - $right) {
            throw new OverflowException('Traffic exceeds signed 64-bit range.');
        }
        return $left + $right;
    }

    public static function storedInteger(mixed $value): int
    {
        $value ??= 0;
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/\A(?:0|[1-9][0-9]*)\z/', $value)
            && (strlen($value) < strlen((string) PHP_INT_MAX)
                || (strlen($value) === strlen((string) PHP_INT_MAX) && strcmp($value, (string) PHP_INT_MAX) <= 0))) {
            return (int) $value;
        }
        throw new OverflowException('Stored traffic is not a nonnegative signed 64-bit integer.');
    }
}
