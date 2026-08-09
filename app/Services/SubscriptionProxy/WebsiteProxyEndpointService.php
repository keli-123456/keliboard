<?php

namespace App\Services\SubscriptionProxy;

use App\Models\ServerMachine;
use App\Models\User;
use App\Services\NotificationSiteContextService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
        if (!(bool) admin_setting('website_proxy_enable', false)) {
            return [];
        }

        $siteId = max(0, (int) $siteId);
        $cached = Cache::remember(
            'website_proxy_endpoint:v2:site:' . $siteId,
            30,
            fn (): array => ['urls' => $this->resolveUrlsForSiteId($siteId)]
        );

        if (!is_array($cached) || !is_array($cached['urls'] ?? null)) {
            return [];
        }

        return array_values(array_filter(
            $cached['urls'],
            fn (mixed $url): bool => is_string($url) && $url !== ''
        ));
    }

    private function resolveUrlsForSiteId(int $siteId): array
    {
        if (!$this->canUseMachineTable()) {
            return [];
        }

        $urls = [];
        foreach (ServerMachine::query()
            ->where('is_active', true)
            ->where('webproxy_enabled', true)
            ->orderBy('sort')
            ->orderBy('id')
            ->get() as $machine) {
            $mainPort = $this->httpsPort($machine);
            $port = $siteId > 0
                ? app(WebsiteProxyRoutingService::class)->branchPortForSite($siteId, $mainPort)
                : $mainPort;
            if ($port === null || !$this->runtimeServesPort($machine, $port, $siteId === 0)) {
                continue;
            }

            $host = $this->resolveProxyHost($machine);
            if ($host === null) {
                continue;
            }

            $urls[] = 'https://' . $host . ($port === 443 ? '' : ':' . $port);
        }

        return array_values(array_unique($urls));
    }

    private function runtimeServesPort(ServerMachine $machine, int $port, bool $main): bool
    {
        $lastSeenAt = (int) ($machine->last_seen_at ?? 0);
        if ($lastSeenAt <= 0 || $lastSeenAt < time() - self::MACHINE_ONLINE_WINDOW_SECONDS) {
            return false;
        }

        $proxy = data_get($machine->load_status, 'agent.subscription_proxy');
        if (!is_array($proxy) || !($proxy['running'] ?? false)) {
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
