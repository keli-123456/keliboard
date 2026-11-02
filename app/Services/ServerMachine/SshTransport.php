<?php

namespace App\Services\ServerMachine;

use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;

class SshTransport
{
    private ?InteractiveSshConnection $connection = null;

    protected function connection(array $target): InteractiveSshConnection
    {
        $ssh = new InteractiveSshConnection($target['address'], $target['port'], 8);
        if (!empty($target['key_type'])) {
            $types = $target['key_type'] === 'ssh-rsa' ? ['rsa-sha2-512', 'rsa-sha2-256', 'ssh-rsa'] : [$target['key_type']];
            $ssh->setPreferredAlgorithms(['hostkey' => $types]);
        }
        return $ssh;
    }

    public static function identity(string $publicKey): array
    {
        $parts = explode(' ', trim($publicKey));
        $blob = isset($parts[1]) ? base64_decode($parts[1], true) : false;
        $length = $blob !== false && strlen($blob) >= 4 ? unpack('N', substr($blob, 0, 4))[1] : 0;
        $type = $length > 0 && $length <= 64 ? substr($blob, 4, $length) : '';
        // phpseclib labels RSA keys with the negotiated rsa-sha2 signature
        // algorithm; OpenSSH fingerprints still hash the canonical ssh-rsa blob.
        $labelMatches = ($parts[0] ?? '') === $type || ($type === 'ssh-rsa' && in_array($parts[0] ?? '', ['rsa-sha2-256', 'rsa-sha2-512'], true));
        if ($blob === false || strlen($blob) < 16 || !$labelMatches || !preg_match('/^(ssh-(ed25519|rsa)|ecdsa-sha2-nistp(256|384|521))$/D', $type)) {
            throw new SshTerminalException('host_key_unavailable');
        }
        return ['key_type' => $type, 'fingerprint' => 'SHA256:' . rtrim(base64_encode(hash('sha256', $blob, true)), '=')];
    }

    public function probe(array $target): array
    {
        $ssh = $this->connection($target);
        try {
            return self::identity((string) $ssh->getServerPublicHostKey());
        } catch (SshTerminalException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new SshTerminalException('connection_failed', 502);
        } finally {
            $ssh->disconnect();
        }
    }

    public function open(array $target, array $credentials, int $columns, int $rows): void
    {
        $this->connection = $ssh = $this->connection($target);
        try {
            $identity = self::identity((string) $ssh->getServerPublicHostKey());
            if (!hash_equals($target['fingerprint'], $identity['fingerprint'])) throw new SshTerminalException('host_key_changed', 409);
            // No password or private-key signature is sent before host-key validation.
            $auth = $credentials['password'] ?? null;
            if (($credentials['auth_type'] ?? '') === 'key') {
                try {
                    $auth = PublicKeyLoader::loadPrivateKey($credentials['private_key'], $credentials['key_password'] ?? false);
                } catch (\Throwable) {
                    throw new SshTerminalException('invalid_private_key');
                }
            }
            if (!$ssh->login($target['username'], $auth)) throw new SshTerminalException('authentication_failed', 401);
            $ssh->setTerminal('xterm-256color');
            $ssh->setWindowSize($columns, $rows);
            $ssh->openShell();
            $ssh->setTimeout(0.02);
        } catch (SshTerminalException $e) {
            $this->close();
            throw $e;
        } catch (\Throwable) {
            $this->close();
            throw new SshTerminalException('connection_failed', 502);
        }
    }

    public function read(): string|false
    {
        $value = $this->connection->read('', SSH2::READ_NEXT);
        return $value === false && $this->connection->isTimeout() ? '' : $value;
    }

    public function write(string $data): void { $this->connection->write($data); }
    public function resize(int $columns, int $rows): void { $this->connection->resizePty($columns, $rows); }
    public function close(): void { $this->connection?->disconnect(); $this->connection = null; }
}
