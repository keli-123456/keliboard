<?php

declare(strict_types=1);

namespace App\Services\ServerMachine\Cloud;

final readonly class MachineUsdo1PrivateCallback
{
    public function __construct(public string $ip, public string $interface, public ?string $ca = null)
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            || !preg_match('/^(?:10\.|172\.(?:1[6-9]|2[0-9]|3[01])\.|192\.168\.)/', $ip)
            || !preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]{0,14}\z/', $interface) || $interface === 'lo') {
            throw new CloudException('usdo1_private_callback_invalid');
        }
        if ($ca !== null) {
            $pem = '/-----BEGIN CERTIFICATE-----\s+[A-Za-z0-9+\/=\r\n]+-----END CERTIFICATE-----/';
            preg_match_all($pem, $ca, $certs);
            if (strlen($ca) > 16384 || !$certs[0] || trim(preg_replace($pem, '', $ca)) !== '') {
                throw new CloudException('usdo1_callback_ca_invalid');
            }
            foreach ($certs[0] as $cert) {
                $data = openssl_x509_parse($cert);
                if (!is_array($data) || !str_contains($data['extensions']['basicConstraints'] ?? '', 'CA:TRUE')) {
                    throw new CloudException('usdo1_callback_ca_invalid');
                }
            }
        }
    }

    public static function fromEnvironment(): self
    {
        $ip = getenv('KELI_USDO1_CALLBACK_PRIVATE_IP');
        $interface = getenv('KELI_USDO1_CALLBACK_INTERFACE');
        $trust = getenv('KELI_USDO1_CALLBACK_TRUST');
        $ca = null;
        if (!is_string($ip) || !is_string($interface) || !in_array($trust, ['system', 'pinned_ca'], true)) {
            throw new CloudException('usdo1_private_callback_unconfigured');
        }
        if ($trust === 'pinned_ca') {
            $path = getenv('KELI_USDO1_CALLBACK_CA_FILE');
            $sha = getenv('KELI_USDO1_CALLBACK_CA_SHA256');
            if (!is_string($path) || !str_starts_with($path, '/') || !is_file($path) || is_link($path)
                || !is_string($sha) || !preg_match('/\A[a-f0-9]{64}\z/', $sha)
                || !is_string($ca = file_get_contents($path, false, null, 0, 16385))
                || !hash_equals($sha, hash('sha256', $ca))) {
                throw new CloudException('usdo1_callback_ca_invalid');
            }
        }
        return new self($ip, $interface, $ca);
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode([$this->ip, $this->interface, $this->ca], JSON_THROW_ON_ERROR));
    }
}
