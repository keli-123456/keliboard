<?php

declare(strict_types=1);

namespace App\Support;

final class Hysteria2Ech
{
    public static function enabled(array $settings): bool
    {
        return data_get($settings, 'ech.enabled') === true;
    }

    public static function publicConfig(array $settings): ?string
    {
        return self::enabled($settings) ? data_get($settings, 'ech.config') : null;
    }

    public static function pem(string $config): string
    {
        return "-----BEGIN ECH CONFIGS-----\n" . chunk_split($config, 64, "\n") . "-----END ECH CONFIGS-----\n";
    }

    // Validate the public ECHConfigList envelope, never accept private PEM material.
    // Crypto/key-pair validation remains the node TLS backend's responsibility.
    public static function validConfig(mixed $value): bool
    {
        if (!is_string($value) || strlen($value) > 16384 || $value === '') {
            return false;
        }
        $bytes = base64_decode($value, true);
        if ($bytes === false || base64_encode($bytes) !== $value || strlen($bytes) < 2) {
            return false;
        }
        try {
            $list = self::vector($bytes);
            if ($bytes !== '' || $list === '') return false;
            $ids = [];
            while ($list !== '') {
                if (self::take($list, 2) !== "\xfe\x0d") return false;
                $config = self::vector($list);
                $id = ord(self::take($config, 1));
                if (isset($ids[$id]) || count($ids) >= 16) return false;
                $ids[$id] = true;
                if (self::take($config, 2) !== "\x00\x20" || strlen(self::vector($config)) !== 32) return false;
                $suites = self::vector($config);
                if ($suites === '' || strlen($suites) % 4 !== 0) return false;
                while ($suites !== '') {
                    if (self::take($suites, 2) !== "\x00\x01" || !in_array(self::take($suites, 2), ["\x00\x01", "\x00\x02", "\x00\x03"], true)) return false;
                }
                self::take($config, 1); // maximum_name_length
                $name = self::take($config, ord(self::take($config, 1)));
                if (!self::validName($name)) return false;
                // This initial integration accepts the generator's extension-free format.
                if (self::vector($config) !== '' || $config !== '') return false;
            }
            return true;
        } catch (\UnderflowException) {
            return false;
        }
    }

    public static function validName(mixed $name): bool
    {
        return is_string($name) && strlen($name) <= 253 && str_contains($name, '.')
            && !filter_var($name, FILTER_VALIDATE_IP)
            && (bool) filter_var($name, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME);
    }

    private static function vector(string &$bytes): string
    {
        $size = unpack('n', self::take($bytes, 2))[1];
        return self::take($bytes, $size);
    }

    private static function take(string &$bytes, int $size): string
    {
        if (strlen($bytes) < $size) throw new \UnderflowException();
        $part = substr($bytes, 0, $size);
        $bytes = substr($bytes, $size);
        return $part;
    }
}
