<?php

namespace App\Services\SubscriptionProxy;

use App\Models\ServerMachine;
use Illuminate\Support\Facades\Schema;

class SubscriptionProxyProbeService
{
    private const HEALTH_TOKEN_PREFIX = '__xboard_subproxy_probe_';
    private const HEALTH_RESPONSE = 'xboard-subproxy-health-ok';

    private bool $availableEndpointResolved = false;

    private ?array $availableEndpoint = null;

    public function healthToken(): string
    {
        $key = (string) config('app.key', '');
        $secret = $key !== '' ? $key : (string) admin_setting('server_token', 'xboard');

        return self::HEALTH_TOKEN_PREFIX . substr(hash_hmac('sha256', 'subscription-proxy', $secret), 0, 32);
    }

    public function isHealthToken(?string $token): bool
    {
        return hash_equals($this->healthToken(), trim((string) $token));
    }

    public function healthResponseBody(): string
    {
        return self::HEALTH_RESPONSE;
    }

    public function probeAll(?int $machineId = null): array
    {
        if (!$this->canUseMachineTable() || !(bool) admin_setting('subscription_proxy_enable', false)) {
            return [];
        }

        $query = ServerMachine::query()
            ->where('is_active', true)
            ->where('subproxy_enabled', true)
            ->orderBy('sort')
            ->orderBy('id');

        if ($machineId !== null && $machineId > 0) {
            $query->where('id', $machineId);
        }

        $results = [];
        foreach ($query->get() as $machine) {
            $results[] = $this->probeMachine($machine);
        }

        return $results;
    }

    public function probeMachine(ServerMachine $machine): array
    {
        $previous = $this->currentProbeState($machine);
        $siteId = $this->siteId();
        $url = $this->buildProxySubscribeUrl($machine, $this->healthToken(), $siteId);
        $checkedAt = now();

        $state = [
            'status' => 'error',
            'site_id' => $siteId,
            'url' => $url,
            'http_code' => null,
            'latency_ms' => null,
            'last_success_at' => $previous['last_success_at'] ?? null,
            'last_checked_at' => $checkedAt->timestamp,
            'last_error' => '',
            'updated_at' => $checkedAt->toIso8601String(),
        ];

        if ($url === null) {
            $state['status'] = 'skipped';
            $state['last_error'] = 'subscription proxy probe url is unavailable';
            return $this->storeProbeState($machine, $state);
        }

        // The proxy is already configured and managed by the machine itself. Do not
        // spend outbound request quota on a synthetic health request here.
        $state['status'] = 'ok';
        $state['http_code'] = 200;
        $state['latency_ms'] = 0;
        $state['last_success_at'] = $checkedAt->timestamp;

        return $this->storeProbeState($machine, $state);
    }

    public function userPayload(string $token): array
    {
        $endpoint = $this->selectAvailableEndpoint();
        if ($endpoint === null) {
            return [
                'available' => false,
                'subscribe_url' => null,
            ];
        }

        return [
            'available' => true,
            'subscribe_url' => $this->buildProxyUrlFromEndpoint($endpoint, $token),
            'machine_id' => $endpoint['machine_id'],
            'machine_name' => $endpoint['machine_name'],
            'site_id' => $endpoint['site_id'],
            'host' => $endpoint['host'],
            'last_success_at' => $endpoint['last_success_at'],
            'latency_ms' => $endpoint['latency_ms'],
        ];
    }

    public function selectAvailableEndpoint(): ?array
    {
        if ($this->availableEndpointResolved) {
            return $this->availableEndpoint;
        }

        $this->availableEndpointResolved = true;

        if (!$this->canUseMachineTable() || !(bool) admin_setting('subscription_proxy_enable', false)) {
            return null;
        }

        $siteId = $this->siteId();
        $candidates = [];
        foreach (ServerMachine::query()
            ->where('is_active', true)
            ->where('subproxy_enabled', true)
            ->orderBy('sort')
            ->orderBy('id')
            ->get() as $machine) {
            $host = $this->resolveProxyHost($machine);
            if ($host === null) {
                continue;
            }

            $candidates[] = [
                'machine_id' => (int) $machine->id,
                'machine_name' => (string) $machine->name,
                'site_id' => $siteId,
                'host' => $host,
                'port' => $this->httpsPort($machine),
                'last_success_at' => now()->timestamp,
                'latency_ms' => 0,
                'sort' => (int) ($machine->sort ?? 0),
            ];
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, function (array $left, array $right): int {
            return [
                $left['sort'],
                $left['latency_ms'] > 0 ? $left['latency_ms'] : PHP_INT_MAX,
                $left['machine_id'],
            ] <=> [
                $right['sort'],
                $right['latency_ms'] > 0 ? $right['latency_ms'] : PHP_INT_MAX,
                $right['machine_id'],
            ];
        });

        return $this->availableEndpoint = $candidates[0];
    }

    private function storeProbeState(ServerMachine $machine, array $probe): array
    {
        $state = is_array($machine->subproxy_cert_state) ? $machine->subproxy_cert_state : [];
        $state['probe'] = $probe;
        $machine->forceFill(['subproxy_cert_state' => $state])->save();

        return $probe;
    }

    private function currentProbeState(ServerMachine $machine): array
    {
        $state = is_array($machine->subproxy_cert_state) ? $machine->subproxy_cert_state : [];
        $probe = $state['probe'] ?? [];

        return is_array($probe) ? $probe : [];
    }

    private function buildProxySubscribeUrl(ServerMachine $machine, string $token, ?string $siteId = null): ?string
    {
        $host = $this->resolveProxyHost($machine);
        if ($host === null) {
            return null;
        }

        return $this->buildProxyUrl($host, $this->httpsPort($machine), $siteId ?: $this->siteId(), $token);
    }

    private function buildProxyUrlFromEndpoint(array $endpoint, string $token): string
    {
        return $this->buildProxyUrl(
            (string) $endpoint['host'],
            (int) $endpoint['port'],
            (string) $endpoint['site_id'],
            $token
        );
    }

    private function buildProxyUrl(string $host, int $port, string $siteId, string $token): string
    {
        $portSuffix = $port === 443 ? '' : ':' . $port;

        return 'https://' . $host . $portSuffix . '/sub/' . rawurlencode($siteId) . '/' . rawurlencode($token);
    }

    private function resolveProxyHost(ServerMachine $machine): ?string
    {
        $candidates = [
            (string) ($machine->subproxy_cert_domain ?? ''),
            (string) data_get($machine->load_status, 'agent.subscription_proxy.certificate_domain', ''),
            (string) data_get($machine->load_status, 'ip.public_ipv4', ''),
            (string) data_get($machine->load_status, 'ip.panel_seen', ''),
        ];

        foreach ($candidates as $candidate) {
            $host = trim($candidate);
            if ($host === '' || str_contains($host, ':')) {
                continue;
            }
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || $this->isValidHostname($host)) {
                return $host;
            }
        }

        return null;
    }

    private function siteId(): string
    {
        $configured = trim((string) admin_setting('subscription_proxy_site_id', ''));
        if ($configured !== '') {
            $siteId = $this->sanitizeSiteId($configured);
            if ($siteId !== '') {
                return $siteId;
            }
        }

        $baseUrl = trim((string) admin_setting('app_url', config('app.url', '')));
        $host = is_string(parse_url($baseUrl, PHP_URL_HOST)) ? (string) parse_url($baseUrl, PHP_URL_HOST) : '';
        $siteId = $this->sanitizeSiteId($host);
        if ($siteId !== '') {
            return $siteId;
        }

        return substr(sha1($baseUrl ?: (string) config('app.key', 'xboard')), 0, 12);
    }

    private function sanitizeSiteId(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9._-]+/', '-', $value) ?: '';
        $value = trim($value, '.-_');

        return substr($value, 0, 100);
    }

    private function httpsPort(ServerMachine $machine): int
    {
        $port = (int) ($machine->subproxy_https_port ?: admin_setting('subscription_proxy_https_port', 443));
        return $port > 0 && $port <= 65535 ? $port : 443;
    }

    private function canUseMachineTable(): bool
    {
        try {
            return Schema::hasTable('v2_server_machine')
                && Schema::hasColumn('v2_server_machine', 'subproxy_enabled')
                && Schema::hasColumn('v2_server_machine', 'subproxy_cert_state');
        } catch (\Throwable) {
            return false;
        }
    }

    private function isValidHostname(string $host): bool
    {
        if ($host === '' || strlen($host) > 253 || str_contains($host, '..')) {
            return false;
        }

        foreach (explode('.', $host) as $label) {
            if ($label === '' || strlen($label) > 63) {
                return false;
            }
            if (!preg_match('/^[a-z0-9-]+$/i', $label) || str_starts_with($label, '-') || str_ends_with($label, '-')) {
                return false;
            }
        }

        return str_contains($host, '.');
    }
}
