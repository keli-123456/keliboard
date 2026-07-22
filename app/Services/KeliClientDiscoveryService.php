<?php

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Request;
use RuntimeException;

class KeliClientDiscoveryService
{
    public function enabled(): bool
    {
        return (bool) config('keli_client.discovery.enabled', true);
    }

    public function payload(Request $request): array
    {
        $payload = [
            'api_base' => $this->normalizeBaseUrl(
                config('keli_client.discovery.api_base') ?: $this->requestBaseUrl($request)
            ),
            'api_prefix' => $this->normalizeApiPrefix(
                (string) config('keli_client.discovery.api_prefix', '/api/v1')
            ),
            'backup_api_bases' => array_map(
                fn (string $url): string => $this->normalizeBaseUrl($url),
                $this->stringList(config('keli_client.discovery.backup_api_bases', ''))
            ),
            'bootstrap_urls' => $this->stringList(config('keli_client.discovery.bootstrap_urls', '')),
            'panel_host' => strtolower($request->getHost()),
            'source' => 'well-known',
            'ttl' => max(60, (int) config('keli_client.discovery.ttl', 3600)),
            'updated_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
        ];

        $privateKey = trim((string) config('keli_client.discovery.ed25519_private_key', ''));
        if ($privateKey !== '') {
            $payload['signature'] = $this->signature($payload, $privateKey);
        }

        return $payload;
    }

    public function signature(array $payload, string $privateKey): string
    {
        if (!function_exists('sodium_crypto_sign_detached')) {
            throw new RuntimeException('PHP sodium extension is required for Ed25519 discovery signatures.');
        }

        $signature = sodium_crypto_sign_detached(
            $this->signingPayload($payload),
            $this->ed25519SecretKey($privateKey)
        );

        return 'ed25519:' . $this->base64UrlEncode($signature);
    }

    public function publicKey(string $privateKey): string
    {
        if (!function_exists('sodium_crypto_sign_publickey_from_secretkey')) {
            throw new RuntimeException('PHP sodium extension is required for Ed25519 discovery keys.');
        }

        return 'ed25519:' . $this->base64UrlEncode(
            sodium_crypto_sign_publickey_from_secretkey($this->ed25519SecretKey($privateKey))
        );
    }

    public function signingPayload(array $payload): string
    {
        $canonical = [
            'api_base' => $this->normalizeBaseUrl((string) ($payload['api_base'] ?? '')),
            'api_prefix' => $this->normalizeApiPrefix((string) ($payload['api_prefix'] ?? '/api/v1')),
            'backup_api_bases' => array_map(
                fn (string $url): string => $this->normalizeBaseUrl($url),
                $this->stringList($payload['backup_api_bases'] ?? [])
            ),
            'bootstrap_urls' => $this->stringList($payload['bootstrap_urls'] ?? []),
            'panel_host' => strtolower((string) ($payload['panel_host'] ?? '')),
        ];

        return json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function normalizeBaseUrl(string $value): string
    {
        $url = trim($value);
        if ($url === '') {
            return '';
        }
        if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
            $url = 'https://' . $url;
        }
        return rtrim($url, '/');
    }

    private function requestBaseUrl(Request $request): string
    {
        $forwardedProto = strtolower(trim(explode(',', (string) $request->header('X-Forwarded-Proto'))[0]));
        $cfVisitor = json_decode((string) $request->header('CF-Visitor'), true);
        $cfScheme = is_array($cfVisitor) ? strtolower((string) ($cfVisitor['scheme'] ?? '')) : '';
        $secure = $request->isSecure() || $forwardedProto === 'https' || $cfScheme === 'https';

        return ($secure ? 'https' : 'http') . '://' . $request->getHttpHost();
    }

    private function normalizeApiPrefix(string $value): string
    {
        $prefix = trim($value) ?: '/api/v1';
        if (!str_starts_with($prefix, '/')) {
            $prefix = '/' . $prefix;
        }
        return rtrim($prefix, '/') ?: '/api/v1';
    }

    private function ed25519SecretKey(string $privateKey): string
    {
        if (!function_exists('sodium_crypto_sign_seed_keypair')) {
            throw new RuntimeException('PHP sodium extension is required for Ed25519 discovery signatures.');
        }

        $raw = $this->decodeKeyMaterial($privateKey);
        $length = strlen($raw);
        if ($length === SODIUM_CRYPTO_SIGN_SEEDBYTES) {
            return sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair($raw));
        }
        if ($length === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            return $raw;
        }

        throw new RuntimeException('KELI_CLIENT_DISCOVERY_ED25519_PRIVATE_KEY must decode to a 32-byte seed or 64-byte secret key.');
    }

    private function decodeKeyMaterial(string $value): string
    {
        $key = trim($value);
        if (str_starts_with($key, 'ed25519:')) {
            $key = substr($key, strlen('ed25519:'));
        }

        if (ctype_xdigit($key) && strlen($key) % 2 === 0) {
            return hex2bin($key) ?: '';
        }

        $base64 = strtr($key, '-_', '+/');
        $padding = strlen($base64) % 4;
        if ($padding > 0) {
            $base64 .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($base64, true);
        if ($decoded === false) {
            throw new RuntimeException('KELI_CLIENT_DISCOVERY_ED25519_PRIVATE_KEY is not valid base64url or hex.');
        }

        return $decoded;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * @return array<int, string>
     */
    private function stringList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map(
                fn ($item): string => trim((string) $item),
                $value
            ), fn (string $item): bool => $item !== ''));
        }

        return array_values(array_filter(preg_split('/[\s,]+/', trim((string) $value)) ?: [], fn (string $item): bool => $item !== ''));
    }
}
