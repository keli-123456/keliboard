<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DomainHealth;
use Closure;

class DomainHealthProbeService
{
    public function __construct(
        private ?Closure $dnsResolver = null,
        private ?Closure $httpsProbe = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function check(string $domain, int $timeoutSeconds = 8, int $certificateWarningDays = 14): array
    {
        $checkedAt = time();
        $host = $this->normalizeHost($domain);
        $base = [
            'status' => DomainHealth::STATUS_DOWN,
            'reason' => 'invalid_domain',
            'http_status' => null,
            'response_ms' => null,
            'dns_addresses' => [],
            'certificate_expires_at' => null,
            'certificate_issuer' => null,
            'certificate_sha256' => null,
            'last_error' => 'Invalid domain',
            'checked_at' => $checkedAt,
        ];

        if ($host === '') {
            return $base;
        }

        $addresses = $this->resolveAddresses($host);
        $base['dns_addresses'] = $addresses;
        if ($addresses === []) {
            return array_merge($base, [
                'reason' => 'dns_unresolved',
                'last_error' => 'DNS did not return an address',
            ]);
        }
        if (collect($addresses)->contains(fn (string $address): bool => !$this->isPublicAddress($address))) {
            return array_merge($base, [
                'reason' => 'unsafe_address',
                'last_error' => 'DNS returned a private or reserved address',
            ]);
        }

        $timeoutSeconds = max(2, min(20, $timeoutSeconds));
        $certificateWarningDays = max(1, min(60, $certificateWarningDays));
        $probe = $this->httpsProbe
            ? ($this->httpsProbe)($host, $addresses, $timeoutSeconds)
            : $this->probeHttps($host, $addresses, $timeoutSeconds);
        $httpStatus = (int) ($probe['http_status'] ?? 0);
        $tlsValid = array_key_exists('tls_valid', $probe)
            ? (bool) $probe['tls_valid']
            : $httpStatus > 0;
        $certificateExpiresAt = $this->positiveIntOrNull($probe['certificate_expires_at'] ?? null);
        $result = array_merge($base, [
            'http_status' => $httpStatus > 0 ? $httpStatus : null,
            'response_ms' => $this->positiveIntOrNull($probe['response_ms'] ?? null),
            'certificate_expires_at' => $certificateExpiresAt,
            'certificate_issuer' => $this->nullableString($probe['certificate_issuer'] ?? null, 255),
            'certificate_sha256' => $this->nullableString($probe['certificate_sha256'] ?? null, 64),
            'last_error' => $this->nullableString($probe['error'] ?? null, 1000),
        ]);

        if (!$tlsValid) {
            return array_merge($result, [
                'reason' => 'tls_failed',
                'last_error' => $result['last_error'] ?: 'TLS connection failed',
            ]);
        }
        if ($httpStatus <= 0) {
            return array_merge($result, [
                'reason' => 'http_unreachable',
                'last_error' => $result['last_error'] ?: 'No HTTP response',
            ]);
        }
        if ($httpStatus >= 500) {
            return array_merge($result, [
                'reason' => 'http_server_error',
                'last_error' => 'HTTP ' . $httpStatus,
            ]);
        }
        if ($httpStatus >= 400) {
            return array_merge($result, [
                'status' => DomainHealth::STATUS_WARNING,
                'reason' => 'http_client_error',
                'last_error' => 'HTTP ' . $httpStatus,
            ]);
        }
        if ($certificateExpiresAt && $certificateExpiresAt <= $checkedAt + ($certificateWarningDays * 86400)) {
            return array_merge($result, [
                'status' => DomainHealth::STATUS_WARNING,
                'reason' => 'certificate_expiring',
                'last_error' => null,
            ]);
        }

        return array_merge($result, [
            'status' => DomainHealth::STATUS_HEALTHY,
            'reason' => 'ok',
            'last_error' => null,
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function resolveAddresses(string $domain): array
    {
        if ($this->dnsResolver) {
            $addresses = ($this->dnsResolver)($domain);
        } elseif (filter_var($domain, FILTER_VALIDATE_IP)) {
            $addresses = [$domain];
        } else {
            $ipv4 = @gethostbynamel($domain) ?: [];
            $records = @dns_get_record($domain, DNS_AAAA) ?: [];
            $ipv6 = array_map(
                static fn (array $record): string => trim((string) ($record['ipv6'] ?? '')),
                $records,
            );
            $addresses = array_merge($ipv4, $ipv6);
        }

        return array_values(array_unique(array_filter(
            is_array($addresses) ? $addresses : [],
            static fn (mixed $address): bool => is_string($address)
                && filter_var(trim($address), FILTER_VALIDATE_IP) !== false,
        )));
    }

    private function isPublicAddress(string $address): bool
    {
        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }

    /**
     * @param array<int, string> $addresses
     * @return array<string, mixed>
     */
    private function probeHttps(string $domain, array $addresses, int $timeoutSeconds): array
    {
        $errors = [];
        foreach ($addresses as $address) {
            $target = str_contains($address, ':') ? '[' . $address . ']' : $address;
            $context = stream_context_create([
                'ssl' => [
                    'peer_name' => $domain,
                    'SNI_enabled' => true,
                    'SNI_server_name' => $domain,
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'allow_self_signed' => false,
                    'capture_peer_cert' => true,
                    'disable_compression' => true,
                ],
            ]);
            $errno = 0;
            $error = '';
            $startedAt = microtime(true);
            $socket = @stream_socket_client(
                'tls://' . $target . ':443',
                $errno,
                $error,
                $timeoutSeconds,
                STREAM_CLIENT_CONNECT,
                $context,
            );
            if (!is_resource($socket)) {
                $lastError = error_get_last();
                $errors[] = trim($error ?: (string) ($lastError['message'] ?? 'TLS connection failed'));
                continue;
            }

            stream_set_timeout($socket, $timeoutSeconds);
            $request = "GET / HTTP/1.1\r\n"
                . 'Host: ' . $domain . "\r\n"
                . "User-Agent: KeliBoard-Domain-Monitor/1.0\r\n"
                . "Accept: text/html,application/json;q=0.9,*/*;q=0.8\r\n"
                . "Connection: close\r\n\r\n";
            if (@fwrite($socket, $request) === false) {
                fclose($socket);
                $errors[] = 'Unable to write HTTP request';
                continue;
            }

            $statusLine = @fgets($socket, 4096);
            $metadata = stream_get_meta_data($socket);
            $params = stream_context_get_params($socket);
            $responseMs = max(1, (int) round((microtime(true) - $startedAt) * 1000));
            fclose($socket);
            if (!is_string($statusLine) || !preg_match('/^HTTP\/\S+\s+(\d{3})/i', trim($statusLine), $matches)) {
                $errors[] = !empty($metadata['timed_out']) ? 'HTTP response timed out' : 'Invalid HTTP status line';
                continue;
            }

            $certificate = $params['options']['ssl']['peer_certificate'] ?? null;
            $certificateData = $certificate ? (@openssl_x509_parse($certificate) ?: []) : [];
            $issuer = $certificateData['issuer']['CN']
                ?? $certificateData['issuer']['O']
                ?? null;

            return [
                'tls_valid' => true,
                'http_status' => (int) $matches[1],
                'response_ms' => $responseMs,
                'certificate_expires_at' => $this->positiveIntOrNull($certificateData['validTo_time_t'] ?? null),
                'certificate_issuer' => is_string($issuer) ? $issuer : null,
                'certificate_sha256' => $certificate && function_exists('openssl_x509_fingerprint')
                    ? strtolower((string) openssl_x509_fingerprint($certificate, 'sha256'))
                    : null,
                'error' => null,
            ];
        }

        return [
            'tls_valid' => false,
            'http_status' => null,
            'response_ms' => null,
            'certificate_expires_at' => null,
            'certificate_issuer' => null,
            'certificate_sha256' => null,
            'error' => implode('; ', array_values(array_unique(array_filter($errors)))) ?: 'TLS connection failed',
        ];
    }

    private function normalizeHost(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $host = parse_url(str_contains($value, '://') ? $value : 'https://' . $value, PHP_URL_HOST);
        $host = strtolower(rtrim(trim((string) $host), '.'));
        if ($host !== '' && function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (is_string($ascii) && $ascii !== '') {
                $host = strtolower($ascii);
            }
        }

        return filter_var($host, FILTER_VALIDATE_IP) || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            ? $host
            : '';
    }

    private function positiveIntOrNull(mixed $value): ?int
    {
        if (!is_numeric($value)) {
            return null;
        }

        $value = (int) $value;

        return $value > 0 ? $value : null;
    }

    private function nullableString(mixed $value, int $limit): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $limit);
    }
}
