<?php

declare(strict_types=1);

namespace App\Services;

final class PaytaroNetwork
{
    public static function label(mixed $value): ?string
    {
        if (!is_string($value) || strlen($value) > 240 || !mb_check_encoding($value, 'UTF-8')
            || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            return null;
        }
        $label = trim($value);
        if (str_contains($label, ':')) {
            // Asset identifiers are opaque metadata, never a payment recipient.
            $parts = explode(':', $label);
            if (count($parts) !== 3
                || preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,31}\z/', $parts[1]) !== 1
                || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/', $parts[2]) !== 1) {
                return null;
            }
            $chain = strtoupper($parts[0]);
            $network = strtoupper($parts[1]);
            if ($chain === 'EVM') {
                // EVM MAINNET alone is ambiguous: require an explicit decimal chain ID.
                return match ($network) {
                    '56' => 'BNB SMART CHAIN (BEP20) MAINNET',
                    '97' => 'BNB SMART CHAIN TESTNET (97)',
                    '137' => 'POLYGON MAINNET',
                    '80002' => 'POLYGON AMOY TESTNET (80002)',
                    default => null,
                };
            }
            $name = match ($chain) {
                'TRON' => 'TRON',
                'BSC' => 'BNB SMART CHAIN (BEP20)',
                'POLYGON' => 'POLYGON',
                'SOLANA' => 'SOLANA',
                default => null,
            };
            if ($name === null || preg_match('/\A[A-Za-z][A-Za-z0-9_-]{0,31}\z/', $parts[1]) !== 1) {
                return null;
            }
            return $name . ' ' . $network;
        }
        // Preserve legacy display labels, including localized network names.
        if ($label === '' || mb_strlen($label, 'UTF-8') > 80
            || preg_match('/\A[\p{L}\p{N} _().（）-]+\z/u', $label) !== 1
            || preg_match('/[\p{L}\p{N}]/u', $label) !== 1) {
            return null;
        }
        return strtoupper($label);
    }

    public static function fromMethod(array $method, string $currency): ?string
    {
        $type = $method['type'] ?? null;
        $network = self::label($type);
        if ($network !== null || ($type !== null && (!is_string($type) || trim($type) !== ''))) {
            return $network;
        }
        // Only qualified network names from the exact authenticated channel can fill a missing type.
        $name = $method['name'] ?? null;
        if (!in_array($currency, ['USDT', 'USDC'], true) || !is_string($name)
            || preg_match('/\A' . $currency . '[ _-]+(TRC[ _-]?20|BEP[ _-]?20|POLYGON|SOLANA)\z/i', trim($name), $matches) !== 1) {
            return null;
        }
        return match (strtoupper(str_replace([' ', '_', '-'], '', $matches[1]))) {
            'TRC20' => 'TRON',
            'BEP20' => 'BNB SMART CHAIN (BEP20)',
            'POLYGON' => 'POLYGON',
            'SOLANA' => 'SOLANA',
            default => null,
        };
    }
}
