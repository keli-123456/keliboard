<?php

namespace App\Services\SubscriptionProxy;

use App\Models\ServerMachine;
use App\Models\User;
use App\Models\WebsiteProxyHealth;
use App\Services\NotificationSiteContextService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class WebsiteProxyEndpointService
{
    private const MACHINE_ONLINE_WINDOW_SECONDS = 300;

    public function urlForSubscription(mixed $user, Request $request): ?string
    {
        $resolvedUser = $user instanceof User ? $user : null;
        $context = $resolvedUser
            ? app(NotificationSiteContextService::class)->forUser($resolvedUser, $request)
            : app(NotificationSiteContextService::class)->forRequest($request);
        if (!empty($context['agent_user_id'])) {
            return null;
        }
        $siteId = (int) ($context['site_id'] ?? 0);

        return $this->urlForSiteId($siteId > 0 ? $siteId : null);
    }

    public function urlForSiteId(?int $siteId): ?string
    {
        return $this->urlsForSiteId($siteId)[0] ?? null;
    }

    public function urlsForSiteId(?int $siteId): array
    {
        if (!(bool) admin_setting('website_proxy_enable', false) || !$this->healthTableExists()) {
            return [];
        }

        return $this->resolveUrlsForSiteId(max(0, (int) $siteId));
    }

    private function resolveUrlsForSiteId(int $siteId): array
    {
        $targets = array_values(array_filter($this->configuredTargets(),
            fn (array $target): bool => $target['site_id'] === $siteId && $target['runtime_error'] === null));
        $health = WebsiteProxyHealth::query()->where('site_id', $siteId)
            ->whereIn('machine_id', array_column($targets, 'machine_id'))->get()->keyBy('machine_id');
        $urls = [];
        foreach ($targets as $target) {
            $probe = $health->get($target['machine_id']);
            if ($probe instanceof WebsiteProxyHealth && $probe->eligibleFor($target)) {
                $urls[] = $target['url'];
            }
        }

        return array_values(array_unique($urls));
    }

    public function configuredTargets(?int $machineId = null): array
    {
        if (!$this->canUseMachineTable()) {
            return [];
        }
        $machines = ServerMachine::query()
            ->where('is_active', true)
            ->where('webproxy_enabled', true)
            ->when($machineId !== null, fn ($query) => $query->where('id', $machineId))
            ->orderBy('sort')
            ->orderBy('id')
            ->get();
        $routesByPort = [];
        $targets = [];
        foreach ($machines as $machine) {
            $host = $this->resolveProxyHost($machine);
            if ($host === null) {
                continue;
            }
            $mainPort = $this->httpsPort($machine);
            $routesByPort[$mainPort] ??= app(WebsiteProxyRoutingService::class)->targetsForPort($mainPort);
            foreach ($routesByPort[$mainPort] as $route) {
                $target = [
                    'machine_id' => (int) $machine->id,
                    'site_id' => (int) $route['site_id'],
                    'site_code' => $route['site_code'],
                    'upstream_url' => $route['upstream_url'],
                    'url' => 'https://' . $host . ($route['port'] === 443 ? '' : ':' . $route['port']),
                ];
                $target['route_fingerprint'] = hash('sha256', json_encode($target, JSON_THROW_ON_ERROR));
                $target['runtime_error'] = $this->runtimeServesPort($machine, $route['port'], $route['site_id'] === 0)
                    ? null : 'runtime_unavailable';
                $targets[] = $target;
            }
        }

        return $targets;
    }

    public function adminHealthPayloads(array $machineIds): array
    {
        if (!$this->healthTableExists()) {
            return [];
        }
        $health = WebsiteProxyHealth::query()->whereIn('machine_id', $machineIds)->get()
            ->keyBy(fn ($row): string => $row->machine_id . ':' . $row->site_id);
        $payloads = [];
        foreach ($this->configuredTargets() as $target) {
            if (!in_array($target['machine_id'], $machineIds, true)) {
                continue;
            }
            $probe = $health->get($target['machine_id'] . ':' . $target['site_id']);
            $matching = $probe && hash_equals((string) $probe->route_fingerprint, $target['route_fingerprint']);
            $eligible = $matching && $target['runtime_error'] === null && $probe->eligibleFor($target);
            $status = !$matching ? 'unverified' : ($target['runtime_error'] !== null ? 'error'
                : (!$probe->last_checked_at || $probe->last_checked_at <= time() - WebsiteProxyHealth::FRESH_SECONDS
                    || $probe->last_checked_at > time() + 5 ? 'stale' : $probe->status));
            $payloads[$target['machine_id']][] = [
                'site_id' => $target['site_id'], 'site_code' => $target['site_code'], 'url' => $target['url'],
                'status' => $status, 'reason' => $matching ? ($target['runtime_error'] ?? $probe->reason) : 'unverified',
                'eligible' => $eligible, 'published' => $eligible && (bool) admin_setting('website_proxy_enable', false),
                'last_checked_at' => $matching ? $probe->last_checked_at : null,
                'http_status' => $matching ? $probe->http_status : null,
                'context_http_status' => $matching ? $probe->context_http_status : null,
            ];
        }

        return $payloads;
    }

    public function healthTableExists(): bool
    {
        try {
            return Schema::hasTable('v2_website_proxy_health');
        } catch (\Throwable) {
            return false;
        }
    }

    private function runtimeServesPort(ServerMachine $machine, int $port, bool $main): bool
    {
        $lastSeenAt = (int) ($machine->last_seen_at ?? 0);
        if ($lastSeenAt <= 0 || $lastSeenAt < time() - self::MACHINE_ONLINE_WINDOW_SECONDS || $lastSeenAt > time() + 5) {
            return false;
        }

        $proxy = data_get($machine->load_status, 'agent.subscription_proxy');
        if (!is_array($proxy) || !($proxy['running'] ?? false)
            || !is_numeric($proxy['website_profiles'] ?? null) || (int) $proxy['website_profiles'] <= 0) {
            return false;
        }
        if (strtolower(trim((string) ($proxy['mode'] ?? ''))) !== 'https') {
            return false;
        }

        if ($main) {
            return $this->listenPort((string) ($proxy['https_listen'] ?? '')) === $port;
        }

        foreach ((array) ($proxy['website_listens'] ?? []) as $listen) {
            if ($this->listenPort((string) $listen) === $port) {
                return true;
            }
        }

        return false;
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

    private function httpsPort(ServerMachine $machine): int
    {
        $port = (int) ($machine->subproxy_https_port ?: admin_setting('subscription_proxy_https_port', 443));
        return $port > 0 && $port <= 65535 ? $port : 443;
    }

    private function listenPort(string $listen): ?int
    {
        $separator = strrpos($listen, ':');
        if ($separator === false) {
            return null;
        }

        $port = (int) substr($listen, $separator + 1);
        return $port > 0 && $port <= 65535 ? $port : null;
    }

    private function canUseMachineTable(): bool
    {
        try {
            return Schema::hasTable('v2_server_machine')
                && Schema::hasColumn('v2_server_machine', 'webproxy_enabled')
                && Schema::hasColumn('v2_server_machine', 'load_status');
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
