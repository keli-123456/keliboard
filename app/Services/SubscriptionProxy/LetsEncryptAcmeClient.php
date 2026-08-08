<?php

namespace App\Services\SubscriptionProxy;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class LetsEncryptAcmeClient
{
    private const PRODUCTION_DIRECTORY = 'https://acme-v02.api.letsencrypt.org/directory';
    private const STAGING_DIRECTORY = 'https://acme-staging-v02.api.letsencrypt.org/directory';
    private const PROFILE = 'shortlived';

    public function __construct(private ?string $accountDirectory = null)
    {
    }

    public function createOrder(string $identifier): array
    {
        $directory = $this->directory();
        $profiles = (array) data_get($directory, 'meta.profiles', []);
        if (!array_key_exists(self::PROFILE, $profiles)) {
            throw new \RuntimeException('Let\'s Encrypt ACME directory does not advertise the shortlived profile.');
        }

        $response = $this->accountRequest((string) $directory['newOrder'], [
            'identifiers' => [[
                'type' => filter_var($identifier, FILTER_VALIDATE_IP) !== false ? 'ip' : 'dns',
                'value' => $identifier,
            ]],
            'profile' => self::PROFILE,
        ]);
        $payload = $this->jsonResponse($response, 'create order');
        $orderURL = trim((string) $response->header('Location'));
        if ($orderURL === '') {
            throw new \RuntimeException('Let\'s Encrypt create order response did not include an order URL.');
        }

        $payload['order_url'] = $orderURL;
        return $payload;
    }

    public function fetch(string $url): array
    {
        return $this->jsonResponse($this->accountRequest($url, null), 'fetch resource');
    }

    public function triggerChallenge(string $url): array
    {
        return $this->jsonResponse($this->accountRequest($url, new \stdClass()), 'trigger HTTP-01 challenge');
    }

    public function finalize(string $url, string $csrPEM): array
    {
        $der = $this->pemToDer($csrPEM, 'CERTIFICATE REQUEST');
        return $this->jsonResponse($this->accountRequest($url, [
            'csr' => $this->base64URL($der),
        ]), 'finalize order');
    }

    public function downloadCertificate(string $url): string
    {
        $response = $this->accountRequest($url, null);
        if (!$response->successful()) {
            throw new \RuntimeException('Let\'s Encrypt download certificate failed: ' . trim($response->body()));
        }
        return trim($response->body());
    }

    public function accountThumbprint(): string
    {
        [, $jwk] = $this->accountKey();
        return $this->base64URL(hash('sha256', $this->canonicalJwk($jwk), true));
    }

    private function accountRequest(string $url, array|\stdClass|null $payload): Response
    {
        $directory = $this->directory();
        [$privateKey, $jwk] = $this->accountKey();
        $meta = $this->accountMeta();
        $kid = trim((string) ($meta['kid'] ?? ''));
        $directoryURL = $this->directoryURL();

        if ($kid === '' || ($meta['directory'] ?? '') !== $directoryURL) {
            $accountPayload = ['termsOfServiceAgreed' => true];
            $email = trim((string) admin_setting('letsencrypt_email', ''));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $accountPayload['contact'] = ['mailto:' . $email];
            }
            $accountResponse = $this->signedRequest(
                (string) $directory['newAccount'],
                $accountPayload,
                $privateKey,
                $jwk,
                null,
                (string) $directory['newNonce']
            );
            $this->jsonResponse($accountResponse, 'register account');
            $kid = trim((string) $accountResponse->header('Location'));
            if ($kid === '') {
                throw new \RuntimeException('Let\'s Encrypt account response did not include an account URL.');
            }
            $this->saveAccountMeta(['directory' => $directoryURL, 'kid' => $kid]);
        }

        return $this->signedRequest(
            $url,
            $payload,
            $privateKey,
            $jwk,
            $kid,
            (string) $directory['newNonce']
        );
    }

    private function signedRequest(
        string $url,
        array|\stdClass|null $payload,
        string $privateKey,
        array $jwk,
        ?string $kid,
        string $newNonceURL
    ): Response {
        $nonce = $this->nonce($newNonceURL);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $protected = [
                'alg' => 'RS256',
                'nonce' => $nonce,
                'url' => $url,
            ];
            if ($kid !== null && $kid !== '') {
                $protected['kid'] = $kid;
            } else {
                $protected['jwk'] = $jwk;
            }

            $protected64 = $this->base64URL(json_encode($protected, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            $payload64 = $payload === null
                ? ''
                : $this->base64URL(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            $signature = '';
            if (!openssl_sign($protected64 . '.' . $payload64, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
                throw new \RuntimeException('Unable to sign Let\'s Encrypt ACME request.');
            }

            $response = Http::timeout(30)
                ->withHeaders(['Content-Type' => 'application/jose+json'])
                ->withBody(json_encode([
                    'protected' => $protected64,
                    'payload' => $payload64,
                    'signature' => $this->base64URL($signature),
                ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'application/jose+json')
                ->post($url);

            $type = (string) data_get($response->json(), 'type', '');
            if ($response->status() !== 400 || !str_ends_with($type, ':badNonce')) {
                return $response;
            }
            $nonce = trim((string) $response->header('Replay-Nonce')) ?: $this->nonce($newNonceURL);
        }

        return $response;
    }

    private function directory(): array
    {
        $response = Http::timeout(30)->get($this->directoryURL());
        $payload = $this->jsonResponse($response, 'load directory');
        foreach (['newNonce', 'newAccount', 'newOrder'] as $key) {
            if (empty($payload[$key])) {
                throw new \RuntimeException("Let's Encrypt ACME directory is missing {$key}.");
            }
        }
        return $payload;
    }

    private function directoryURL(): string
    {
        return admin_setting('letsencrypt_environment', 'production') === 'staging'
            ? self::STAGING_DIRECTORY
            : self::PRODUCTION_DIRECTORY;
    }

    private function nonce(string $url): string
    {
        $response = Http::timeout(30)->head($url);
        $nonce = trim((string) $response->header('Replay-Nonce'));
        if (!$response->successful() || $nonce === '') {
            throw new \RuntimeException('Unable to obtain a Let\'s Encrypt ACME nonce.');
        }
        return $nonce;
    }

    private function accountKey(): array
    {
        $path = $this->accountKeyPath();
        if (is_file($path)) {
            $privateKey = trim((string) file_get_contents($path));
        } else {
            $this->ensureAccountDirectory(dirname($path));
            $resource = openssl_pkey_new([
                'private_key_bits' => 2048,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
            ]);
            if ($resource === false || !openssl_pkey_export($resource, $privateKey)) {
                throw new \RuntimeException('Unable to generate Let\'s Encrypt ACME account key.');
            }
            if (file_put_contents($path, $privateKey, LOCK_EX) === false) {
                throw new \RuntimeException('Unable to persist Let\'s Encrypt ACME account key.');
            }
            @chmod($path, 0600);
        }

        $resource = openssl_pkey_get_private($privateKey);
        $details = $resource !== false ? openssl_pkey_get_details($resource) : false;
        if (!is_array($details) || !isset($details['rsa']['n'], $details['rsa']['e'])) {
            throw new \RuntimeException('Invalid Let\'s Encrypt ACME account key.');
        }

        return [$privateKey, [
            'e' => $this->base64URL($details['rsa']['e']),
            'kty' => 'RSA',
            'n' => $this->base64URL($details['rsa']['n']),
        ]];
    }

    private function accountKeyPath(): string
    {
        return rtrim($this->accountDirectory ?? storage_path('app/private/letsencrypt'), '/\\') . '/account.pem';
    }

    private function accountMeta(): array
    {
        $path = dirname($this->accountKeyPath()) . '/account.json';
        if (!is_file($path)) {
            return [];
        }
        $value = json_decode((string) file_get_contents($path), true);
        return is_array($value) ? $value : [];
    }

    private function saveAccountMeta(array $meta): void
    {
        $path = dirname($this->accountKeyPath()) . '/account.json';
        $this->ensureAccountDirectory(dirname($path));
        $content = json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        if (file_put_contents($path, $content, LOCK_EX) === false) {
            throw new \RuntimeException('Unable to persist Let\'s Encrypt ACME account metadata.');
        }
        @chmod($path, 0600);
    }

    private function ensureAccountDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }
        if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create Let\'s Encrypt ACME account directory.');
        }
    }

    private function canonicalJwk(array $jwk): string
    {
        return json_encode([
            'e' => (string) $jwk['e'],
            'kty' => 'RSA',
            'n' => (string) $jwk['n'],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function pemToDer(string $pem, string $label): string
    {
        $body = preg_replace('/-----BEGIN ' . preg_quote($label, '/') . '-----|-----END ' . preg_quote($label, '/') . '-----|\s+/', '', $pem);
        $der = base64_decode((string) $body, true);
        if ($der === false || $der === '') {
            throw new \RuntimeException('Invalid certificate signing request reported by node.');
        }
        return $der;
    }

    private function base64URL(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function jsonResponse(Response $response, string $action): array
    {
        $payload = $response->json();
        if (!$response->successful() || !is_array($payload)) {
            $message = is_array($payload)
                ? (string) ($payload['detail'] ?? json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
                : $response->body();
            throw new \RuntimeException("Let's Encrypt {$action} failed: " . trim($message));
        }
        return $payload;
    }
}
