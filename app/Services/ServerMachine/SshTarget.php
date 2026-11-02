<?php

namespace App\Services\ServerMachine;

class SshTarget
{
    public function normalize(array $input): array
    {
        $host = trim((string) ($input['host'] ?? ''), ' []');
        $port = filter_var($input['port'] ?? 22, FILTER_VALIDATE_INT);
        $username = (string) ($input['username'] ?? 'root');
        if (strlen($host) > 253 || $host === '' || (!filter_var($host, FILTER_VALIDATE_IP)
            && !preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/iD', $host))
            || $port === false || $port < 1 || $port > 65535
            || !preg_match('/^[a-zA-Z0-9_][a-zA-Z0-9_.-]{0,63}$/D', $username)) {
            throw new SshTerminalException('invalid_target');
        }
        return ['host' => strtolower($host), 'port' => $port, 'username' => $username];
    }

    public function resolve(string $host): string
    {
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->lookup($host);
        if ($addresses === [] || count($addresses) > 32) throw new SshTerminalException('invalid_target');
        // Reject the entire DNS answer if any address is forbidden. The chosen IP
        // is carried into the SSH connection; phpseclib never resolves it again.
        foreach ($addresses as $ip) {
            if (!$this->allowed($ip)) throw new SshTerminalException('target_not_allowed');
        }
        return $addresses[0];
    }

    protected function lookup(string $host): array
    {
        $rows = @dns_get_record($host, DNS_A | DNS_AAAA);
        return array_values(array_unique(array_filter(array_map(fn ($row) => $row['ip'] ?? $row['ipv6'] ?? null, $rows ?: []))));
    }

    public function allowed(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) return false;
        if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
            $ip = inet_ntop(substr($packed, 12));
        }
        // Cloud metadata, unspecified and multicast destinations are never valid.
        if (\Symfony\Component\HttpFoundation\IpUtils::checkIp($ip, [
            '0.0.0.0/8', '169.254.0.0/16', '100.100.100.200', '168.63.129.16', '224.0.0.0/4', '240.0.0.0/4',
            '::/128', 'fe80::/10', 'ff00::/8', 'fd00:ec2::254',
        ])) return false;
        if (\Symfony\Component\HttpFoundation\IpUtils::checkIp($ip, ['100.64.0.0/10', '198.18.0.0/15'])) {
            return in_array($ip, (array) config('ssh_terminal.allowed_private_hosts', []), true);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return true;
        return in_array($ip, (array) config('ssh_terminal.allowed_private_hosts', []), true);
    }

    public static function hash(array $profile): string
    {
        return hash('sha256', json_encode($profile, JSON_THROW_ON_ERROR));
    }
}
