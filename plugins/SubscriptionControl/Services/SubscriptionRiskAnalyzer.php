<?php

declare(strict_types=1);

namespace Plugin\SubscriptionControl\Services;

use Illuminate\Support\Facades\Cache;

final class SubscriptionRiskAnalyzer
{
    private const DEFAULT_CLIENT_WHITELIST = <<<'TEXT'
mihomo
sing-box
shadowrocket
quantumult-x
surge
v2rayn
nekobox
sparkle
hiddify
streisand
loon
TEXT;

    public function __construct(private readonly array $config = [])
    {
    }

    public function classifyUserAgent(string $userAgent): array
    {
        $raw = trim($userAgent);
        $ua = strtolower($raw);

        if ($ua === '') {
            return $this->clientInfo('empty', true);
        }

        if (str_contains($ua, 'sparkle')) {
            return $this->clientInfo('sparkle');
        }

        if (
            str_contains($ua, 'mihomo')
            || str_contains($ua, 'clash.meta')
            || str_contains($ua, 'clash-meta')
            || str_contains($ua, 'clashmeta')
            || preg_match('/\bclash\b/', $ua)
        ) {
            return $this->clientInfo('mihomo');
        }

        if (str_contains($ua, 'sing-box') || str_contains($ua, 'singbox')) {
            return $this->clientInfo('sing-box');
        }

        if (str_contains($ua, 'shadowrocket')) {
            return $this->clientInfo('shadowrocket');
        }

        if (str_contains($ua, 'quantumult')) {
            return $this->clientInfo('quantumult-x');
        }

        if (str_contains($ua, 'surge')) {
            return $this->clientInfo('surge');
        }

        if (str_contains($ua, 'v2rayn')) {
            return $this->clientInfo('v2rayn');
        }

        if (str_contains($ua, 'nekobox') || str_contains($ua, 'nekoray')) {
            return $this->clientInfo('nekobox');
        }

        if (str_contains($ua, 'hiddify')) {
            return $this->clientInfo('hiddify');
        }

        if (str_contains($ua, 'streisand')) {
            return $this->clientInfo('streisand');
        }

        if (str_contains($ua, 'loon')) {
            return $this->clientInfo('loon');
        }

        if ($this->looksLikeScriptClient($ua)) {
            return $this->clientInfo('script', true);
        }

        if ($this->looksLikeBrowser($ua)) {
            return $this->clientInfo('browser', true);
        }

        return $this->clientInfo('unknown', true);
    }

    public function inspectSubscriptionPull(
        int $userId,
        string $token,
        string $clientIp,
        string $userAgent,
        array $context = []
    ): array {
        $client = $this->classifyUserAgent($userAgent);
        $decisions = [];

        if ($this->configBool('enable_client_ua_whitelist', false) && !$this->isWhitelistedClient($client, $userAgent)) {
            $decisions[] = $this->decision(
                'client_ua_not_allowed',
                '客户端不在订阅白名单内',
                $this->configAction('client_ua_unknown_action', 'observe'),
                [
                    'ua_category' => $client['category'],
                ]
            );
        }

        if ($this->configBool('enable_multi_ua_detection', false)) {
            $window = $this->configInt('multi_ua_window_seconds', 600, 60);
            $allowed = $this->configInt('multi_ua_allowed_count', 2, 1);
            $categories = $this->rememberWindowValue(
                $this->cacheKey('ua', $userId, $token),
                $client['category'],
                $window
            );

            if (count($categories) > $allowed) {
                $decisions[] = $this->decision(
                    'multi_ua_pull',
                    '同一订阅短时间内被多个客户端拉取',
                    $this->configAction('multi_ua_action', 'observe'),
                    [
                        'ua_category' => $client['category'],
                        'ua_categories' => $categories,
                        'threshold' => $allowed,
                    ]
                );
            }
        }

        if ($this->configBool('enable_multi_region_pull_detection', false)) {
            $region = $this->resolveRegionKey($clientIp);
            if ($this->isActionableRegion($region)) {
                $window = $this->configInt('multi_region_pull_window_seconds', 600, 60);
                $allowed = $this->configInt('multi_region_pull_allowed_count', 2, 1);
                $regions = $this->rememberWindowValue(
                    $this->cacheKey('region', $userId, $token),
                    $region,
                    $window
                );

                if (count($regions) > $allowed) {
                    $decisions[] = $this->decision(
                        'multi_region_pull',
                        '同一订阅短时间内从多个地区拉取',
                        $this->configAction('multi_region_pull_action', 'observe'),
                        [
                            'region' => $region,
                            'regions' => $regions,
                            'threshold' => $allowed,
                        ]
                    );
                }
            }
        }

        if ($this->configBool('enable_multi_region_online_detection', false)) {
            $onlineRegions = $this->resolveOnlineRegions((array) ($context['online_ips'] ?? []));
            $allowed = $this->configInt('multi_region_online_allowed_count', 2, 1);
            if (count($onlineRegions) > $allowed) {
                $decisions[] = $this->decision(
                    'multi_region_online',
                    '同一用户当前在线 IP 分布在多个地区',
                    $this->configAction('multi_region_online_action', 'observe'),
                    [
                        'regions' => $onlineRegions,
                        'online_ip_count' => count((array) ($context['online_ips'] ?? [])),
                        'threshold' => $allowed,
                    ]
                );
            }
        }

        return $decisions;
    }

    private function clientInfo(string $category, bool $risky = false): array
    {
        return [
            'category' => $category,
            'risky' => $risky,
        ];
    }

    private function looksLikeScriptClient(string $ua): bool
    {
        foreach ([
            'curl',
            'wget',
            'python',
            'go-http-client',
            'postman',
            'insomnia',
            'httpclient',
            'libwww',
            'okhttp',
            'axios',
            'node-fetch',
            'java/',
            'ruby',
            'perl',
        ] as $needle) {
            if (str_contains($ua, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeBrowser(string $ua): bool
    {
        return str_contains($ua, 'mozilla/')
            || str_contains($ua, 'chrome/')
            || str_contains($ua, 'safari/')
            || str_contains($ua, 'firefox/')
            || str_contains($ua, 'edg/');
    }

    private function isWhitelistedClient(array $client, string $userAgent): bool
    {
        $keywords = $this->parseKeywordList(
            (string) ($this->config['client_ua_whitelist'] ?? self::DEFAULT_CLIENT_WHITELIST)
        );
        if (empty($keywords)) {
            $keywords = $this->parseKeywordList(self::DEFAULT_CLIENT_WHITELIST);
        }

        $category = strtolower((string) ($client['category'] ?? 'unknown'));
        $ua = strtolower($userAgent);

        foreach ($keywords as $keyword) {
            $normalized = strtolower($keyword);
            if ($normalized === $category || ($ua !== '' && str_contains($ua, $normalized))) {
                return true;
            }
        }

        return false;
    }

    private function rememberWindowValue(string $cacheKey, string $value, int $window): array
    {
        $now = time();
        $items = Cache::get($cacheKey, []);
        if (!is_array($items)) {
            $items = [];
        }

        $items = array_filter(
            $items,
            static fn($timestamp): bool => is_numeric($timestamp) && ($now - (int) $timestamp) < $window
        );
        $items[$value] = $now;

        Cache::put($cacheKey, $items, $window);

        $values = array_keys($items);
        sort($values, SORT_STRING);

        return $values;
    }

    private function cacheKey(string $kind, int $userId, string $token): string
    {
        return sprintf(
            'subscription_control:risk:%s:%d:%s',
            $kind,
            $userId,
            hash('sha256', $token)
        );
    }

    private function decision(string $code, string $reason, string $action, array $meta = []): array
    {
        return [
            'code' => $code,
            'reason' => $reason,
            'action' => $action,
            'meta' => $meta,
        ];
    }

    private function resolveOnlineRegions(array $ips): array
    {
        $regions = [];
        foreach ($ips as $ip) {
            $region = $this->resolveRegionKey((string) $ip);
            if ($this->isActionableRegion($region)) {
                $regions[$region] = true;
            }
        }

        $values = array_keys($regions);
        sort($values, SORT_STRING);

        return $values;
    }

    private function resolveRegionKey(string $ip): ?string
    {
        $ip = trim($ip);
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }

        $overrides = $this->parseIpRegionOverrides($this->config['ip_region_overrides'] ?? []);
        if (isset($overrides[$ip])) {
            return $overrides[$ip];
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return 'private';
        }

        $cacheTtl = $this->configInt('ip_region_cache_ttl_seconds', 604800, 60);
        $cacheKey = 'subscription_control:ip_region:' . hash('sha256', $ip);
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $region = $this->lookupRegionByIp2Region($ip);
        if ($region !== null) {
            Cache::put($cacheKey, $region, $cacheTtl);
        }

        return $region;
    }

    private function lookupRegionByIp2Region(string $ip): ?string
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || !class_exists('Ip2Region')) {
            return null;
        }

        try {
            $raw = (string) (new \Ip2Region())->simple($ip);
        } catch (\Throwable) {
            return null;
        }

        return $this->extractRegionKey($raw);
    }

    private function extractRegionKey(string $raw): ?string
    {
        $parts = array_values(array_filter(
            array_map('trim', preg_split('/\|+/', $raw) ?: []),
            static fn(string $part): bool => $part !== '' && $part !== '0'
        ));

        if (empty($parts)) {
            return null;
        }

        if (in_array($parts[0], ['内网IP', '本机地址'], true)) {
            return 'private';
        }

        $country = $parts[0];
        $province = $parts[1] ?? null;
        $city = $parts[2] ?? null;

        if ($country === '中国' && $province) {
            return $city && $city !== $province
                ? "{$country}/{$province}/{$city}"
                : "{$country}/{$province}";
        }

        return $country;
    }

    private function isActionableRegion(?string $region): bool
    {
        return is_string($region)
            && $region !== ''
            && !in_array($region, ['private', 'unknown'], true);
    }

    private function parseIpRegionOverrides(mixed $raw): array
    {
        if (is_array($raw)) {
            $map = [];
            foreach ($raw as $ip => $region) {
                $ip = trim((string) $ip);
                $region = trim((string) $region);
                if ($ip !== '' && $region !== '') {
                    $map[$ip] = $region;
                }
            }

            return $map;
        }

        $map = [];
        foreach (preg_split('/[\r\n]+/', (string) $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, '=')) {
                continue;
            }

            [$ip, $region] = array_map('trim', explode('=', $line, 2));
            if ($ip !== '' && $region !== '') {
                $map[$ip] = $region;
            }
        }

        return $map;
    }

    private function configBool(string $key, bool $default): bool
    {
        return filter_var($this->config[$key] ?? $default, FILTER_VALIDATE_BOOL);
    }

    private function configInt(string $key, int $default, int $min): int
    {
        $value = (int) ($this->config[$key] ?? $default);
        return max($min, $value);
    }

    private function configAction(string $key, string $default): string
    {
        $action = strtolower(trim((string) ($this->config[$key] ?? $default)));
        if ($action === 'reset_token') {
            return 'reset_token_uuid';
        }

        return in_array($action, ['observe', 'throttle', 'empty', 'block', 'reset_token_uuid'], true)
            ? $action
            : ($default === 'reset_token' ? 'reset_token_uuid' : $default);
    }

    private function parseKeywordList(string $input): array
    {
        if (trim($input) === '') {
            return [];
        }

        $parts = preg_split('/[\r\n，,]+/', $input);
        return array_values(array_filter(array_map('trim', $parts), static fn($item): bool => $item !== ''));
    }
}
