<?php

namespace App\Services\SubscriptionProxy;

use App\Models\Site;
use App\Models\SiteDomain;

class WebsiteProxyRoutingService
{
    private const FIRST_BRANCH_PORT = 8443;

    public function build(string $mainBaseUrl, string $mainSiteId, string $mainHttpsListen): array
    {
        $mainProfiles = [[
            'site_id' => $mainSiteId,
            'upstream_base_url' => $mainBaseUrl,
            'path_prefix' => '/',
        ]];
        $listeners = [];

        foreach ($this->branchRoutes($this->listenPort($mainHttpsListen, 443)) as $route) {
            $listeners[] = [
                'https_listen' => $this->listenAddress($route['port']),
                'website_profiles' => [$route['profile']],
            ];
        }

        return ['main_profiles' => $mainProfiles, 'listeners' => $listeners];
    }

    public function branchPortForSite(int $siteId, int $mainHttpsPort): ?int
    {
        foreach ($this->branchRoutes($mainHttpsPort) as $route) {
            if ($route['site_id'] === $siteId) {
                return $route['port'];
            }
        }

        return null;
    }

    private function branchRoutes(int $mainHttpsPort): array
    {
        $usedPorts = [$this->validPort($mainHttpsPort, 443) => true];
        $routes = [];

        $sites = Site::query()
            ->with(['domains' => function ($query): void {
                $query->where('status', SiteDomain::STATUS_ACTIVE)
                    ->orderByDesc('is_primary')
                    ->orderBy('id');
            }])
            ->where('is_default', false)
            ->where('status', Site::STATUS_ACTIVE)
            ->orderBy('id')
            ->get();

        foreach ($sites as $site) {
            $domain = $site->domains->first();
            if (!$domain instanceof SiteDomain) {
                continue;
            }

            $profile = $this->siteProfile($site, $domain);
            if ($profile === null) {
                continue;
            }

            $port = $this->allocatePort((int) $site->id, $usedPorts);
            if ($port === null) {
                break;
            }

            $routes[] = [
                'site_id' => (int) $site->id,
                'port' => $port,
                'profile' => $profile,
            ];
        }

        return $routes;
    }

    private function siteProfile(Site $site, SiteDomain $domain): ?array
    {
        $host = strtolower(trim((string) $domain->domain, " \n\r\t\v\0."));
        if ($host === '' || parse_url('https://' . $host, PHP_URL_HOST) !== $host) {
            return null;
        }

        $siteId = $this->sanitizeSiteId((string) $site->code);
        if ($siteId === '') {
            $siteId = 'site-' . (int) $site->id;
        }

        return [
            'site_id' => $siteId,
            'upstream_base_url' => 'https://' . $host,
            'path_prefix' => '/',
        ];
    }

    private function allocatePort(int $siteId, array &$usedPorts): ?int
    {
        $span = 65535 - self::FIRST_BRANCH_PORT + 1;
        $candidate = self::FIRST_BRANCH_PORT + ((max(1, $siteId) - 1) % $span);

        for ($attempt = 0; $attempt < $span; $attempt++) {
            if (!isset($usedPorts[$candidate])) {
                $usedPorts[$candidate] = true;
                return $candidate;
            }
            $candidate = $candidate >= 65535 ? self::FIRST_BRANCH_PORT : $candidate + 1;
        }

        return null;
    }

    private function listenPort(string $listen, int $fallback): int
    {
        $separator = strrpos($listen, ':');
        if ($separator === false) {
            return $fallback;
        }

        return $this->validPort((int) substr($listen, $separator + 1), $fallback);
    }

    private function listenAddress(int $port): string
    {
        return '0.0.0.0:' . $this->validPort($port, self::FIRST_BRANCH_PORT);
    }

    private function validPort(int $port, int $fallback): int
    {
        return $port > 0 && $port <= 65535 ? $port : $fallback;
    }

    private function sanitizeSiteId(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9._-]+/', '-', $value) ?: '';
        $value = trim($value, '.-_');

        return substr($value, 0, 100);
    }
}
