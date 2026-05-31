<?php

declare(strict_types=1);

namespace Plugin\SubscriptionControl\Services;

use Illuminate\Support\Facades\Cache;

final class SubscriptionRiskAnalyzer
{
    private const DEFAULT_CLIENT_WHITELIST = <<<'TEXT'
mihomo
sing-box
singbox
sfa
shadowrocket
clashmeta
clash-meta
clashx.meta
clashxmeta
clashmetaforandroid
clash-verge-rev
clashvergerev
clash-nyanpasu
clashnyanpasu
gui.for.clash
guiforclash
flclash
flclashx
clashmi
flyclash
yumebox
bettbox
monadbox
clashfest
pandora-box
pandorabox
mihomosh
mihomo-tui
mihomotui
koala
stelliberty
clash-xiaoy
clashxiaoy
goclashz
zephyr
slothclash
clashmac
clashbar
catbar
mihoro
shellcrash
openclash
openwrt-nikki
nikki
ssclash
vclash
merlinclash
clashbox
deckyclash
tomoon
v2rayn
v2rayng
nekobox
nekoray
sparkle
hiddify
karing
gui.for.singbox
gui.for.sing-box
guiforsingbox
onebox
qsing-box
qsingbox
sing-box-windows
singbox-windows
singbox-launcher
sing-box-launcher
stash
streisand
throne
quantumult-x
quantumult
surge
loon
TEXT;

    public function __construct(
        private readonly array $config = [],
        private readonly ?SubscriptionIpIntelligenceService $ipIntelligence = null
    )
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

        if (str_contains($ua, 'clashforwindows') || str_contains($ua, 'clash for windows')) {
            return $this->clientInfo('legacy_clash', true);
        }

        if (str_contains($ua, 'clashforandroid')) {
            return $this->clientInfo('legacy_clash', true);
        }

        if ($this->containsAny($ua, [
            'mihomo',
            'clash.meta',
            'clashx.meta',
            'clash-meta',
            'clashmeta',
            'clash-verge-rev',
            'clashvergerev',
            'clash-nyanpasu',
            'clashnyanpasu',
            'gui.for.clash',
            'guiforclash',
            'flclash',
            'flclashx',
            'clashmi',
            'flyclash',
            'yumebox',
            'bettbox',
            'monadbox',
            'clashfest',
            'pandora-box',
            'pandorabox',
            'mihomosh',
            'mihomo-tui',
            'mihomotui',
            'koala',
            'stelliberty',
            'clash-xiaoy',
            'clashxiaoy',
            'goclashz',
            'zephyr',
            'slothclash',
            'clashmac',
            'clashbar',
            'catbar',
            'mihoro',
            'shellcrash',
            'openclash',
            'openwrt-nikki',
            'nikki',
            'ssclash',
            'vclash',
            'merlinclash',
            'clashbox',
            'deckyclash',
            'tomoon',
        ])) {
            return $this->clientInfo('mihomo');
        }

        if (str_contains($ua, 'throne')) {
            return $this->clientInfo('throne');
        }

        if ($this->containsAny($ua, [
            'sing-box',
            'singbox',
            'sfa',
            'karing',
            'gui.for.singbox',
            'gui.for.sing-box',
            'guiforsingbox',
            'onebox',
            'qsing-box',
            'qsingbox',
            'sing-box-windows',
            'singbox-windows',
            'singbox-launcher',
            'sing-box-launcher',
        ])) {
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
        $trustedEgress = $this->isTrustedEgressIp($clientIp);
        $ipIntelligence = null;

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

        if (!$trustedEgress && $this->configBool('enable_source_batch_detection', false)) {
            $decision = $this->inspectSourceBatchPull($userId, $clientIp, $client);
            if ($decision !== null) {
                $decisions[] = $decision;
            }
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

        if ($this->configBool('enable_leak_guard', false)) {
            $ipIntelligence = $this->resolveIpIntelligence($clientIp, $trustedEgress);
            $decision = $this->inspectLeakGuard(
                $userId,
                $token,
                $clientIp,
                $userAgent,
                $client,
                $context,
                $trustedEgress,
                $ipIntelligence
            );
            if ($decision !== null) {
                $decisions[] = $decision;
            }
        }

        if (!empty($decisions) && $ipIntelligence === null) {
            $ipIntelligence = $this->resolveIpIntelligence($clientIp, $trustedEgress);
        }

        return array_map(
            fn(array $decision): array => $this->withIpIntelligenceMeta($decision, $ipIntelligence),
            $decisions
        );
    }

    private function clientInfo(string $category, bool $risky = false): array
    {
        return [
            'category' => $category,
            'risky' => $risky,
        ];
    }

    private function extractVersionAfter(string $ua, string $name): ?string
    {
        if (preg_match('/' . preg_quote($name, '/') . '[^0-9]*([0-9]+(?:\.[0-9]+)*)/i', $ua, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function containsAny(string $value, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($value, $needle)) {
                return true;
            }
        }

        return false;
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
        return $this->rememberWindowValueWithState($cacheKey, $value, $window)['values'];
    }

    private function rememberWindowValueWithState(string $cacheKey, string $value, int $window): array
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
        $hadValues = !empty($items);
        $wasNew = !array_key_exists($value, $items);
        $items[$value] = $now;

        Cache::put($cacheKey, $items, $window);

        $values = array_keys($items);
        sort($values, SORT_STRING);

        return [
            'values' => $values,
            'had_values' => $hadValues,
            'was_new' => $wasNew,
        ];
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

    private function sourceBatchCacheKey(string $clientIp): string
    {
        return sprintf(
            'subscription_control:risk:source_batch:%s',
            hash('sha256', trim($clientIp))
        );
    }

    public function isTrustedEgressIp(string $ip): bool
    {
        $ip = trim($ip);
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        foreach ($this->parseKeywordList((string) ($this->config['trusted_egress_ips'] ?? '')) as $entry) {
            if ($this->ipMatchesCidr($ip, $entry)) {
                return true;
            }
        }

        return false;
    }

    private function ipMatchesCidr(string $ip, string $cidr): bool
    {
        $cidr = trim($cidr);
        if ($cidr === '') {
            return false;
        }

        if (!str_contains($cidr, '/')) {
            return hash_equals(strtolower($cidr), strtolower($ip));
        }

        [$network, $prefix] = array_map('trim', explode('/', $cidr, 2));
        if ($network === '' || $prefix === '' || !is_numeric($prefix)) {
            return false;
        }

        $ipBytes = @inet_pton($ip);
        $networkBytes = @inet_pton($network);
        if ($ipBytes === false || $networkBytes === false || strlen($ipBytes) !== strlen($networkBytes)) {
            return false;
        }

        $prefixBits = (int) $prefix;
        $maxBits = strlen($ipBytes) * 8;
        if ($prefixBits < 0 || $prefixBits > $maxBits) {
            return false;
        }

        $fullBytes = intdiv($prefixBits, 8);
        $remainingBits = $prefixBits % 8;

        if ($fullBytes > 0 && substr($ipBytes, 0, $fullBytes) !== substr($networkBytes, 0, $fullBytes)) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xff << (8 - $remainingBits)) & 0xff;
        return (ord($ipBytes[$fullBytes]) & $mask) === (ord($networkBytes[$fullBytes]) & $mask);
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

    private function inspectSourceBatchPull(int $userId, string $clientIp, array $client): ?array
    {
        $window = $this->configInt('source_batch_window_seconds', 600, 60);
        $threshold = $this->configInt('source_batch_user_threshold', 3, 2);
        $category = (string) ($client['category'] ?? 'unknown');
        $state = $this->rememberSourceBatchState(
            $this->sourceBatchCacheKey($clientIp),
            (string) $userId,
            $category,
            $window
        );
        $users = $state['users'];

        if (count($users) < $threshold) {
            return null;
        }

        return $this->decision(
            'source_batch_pull',
            '同一来源短时间内拉取多个用户订阅',
            $this->configAction('source_batch_action', 'empty'),
            [
                'source_user_count' => count($users),
                'source_user_threshold' => $threshold,
                'ua_category' => $category,
                'source_ua_categories' => $state['ua_categories'],
                'threshold' => $threshold,
            ]
        );
    }

    private function rememberSourceBatchState(string $cacheKey, string $userId, string $uaCategory, int $window): array
    {
        $now = time();
        $state = Cache::get($cacheKey, []);
        if (!is_array($state) || (!isset($state['users']) && !isset($state['ua_categories']))) {
            $state = [
                'users' => [],
                'ua_categories' => [],
            ];
        }

        $users = is_array($state['users'] ?? null) ? $state['users'] : [];
        $uaCategories = is_array($state['ua_categories'] ?? null) ? $state['ua_categories'] : [];

        $users = array_filter(
            $users,
            static fn($timestamp): bool => is_numeric($timestamp) && ($now - (int) $timestamp) < $window
        );
        $uaCategories = array_filter(
            $uaCategories,
            static fn($timestamp): bool => is_numeric($timestamp) && ($now - (int) $timestamp) < $window
        );

        $users[$userId] = $now;
        $uaCategories[$uaCategory] = $now;

        Cache::put($cacheKey, [
            'users' => $users,
            'ua_categories' => $uaCategories,
        ], $window);

        $userValues = array_keys($users);
        $uaValues = array_keys($uaCategories);
        sort($userValues, SORT_STRING);
        sort($uaValues, SORT_STRING);

        return [
            'users' => $userValues,
            'ua_categories' => $uaValues,
        ];
    }

    private function inspectLeakGuard(
        int $userId,
        string $token,
        string $clientIp,
        string $userAgent,
        array $client,
        array $context,
        bool $trustedEgress,
        ?array $ipIntelligence = null
    ): ?array {
        $window = $this->configInt('leak_guard_window_seconds', 600, 60);
        $threshold = $this->configInt('leak_guard_score_threshold', 80, 1);
        $allowedIpCount = $this->configInt('leak_guard_allowed_ip_count', 2, 1);
        $allowedUaCount = $this->configInt('leak_guard_allowed_ua_count', 1, 1);
        $allowedRegionCount = $this->configInt('leak_guard_allowed_region_count', 1, 1);
        $strictMode = $this->configBool('enable_leak_guard_strict_mode', false);

        $score = 0;
        $signals = [];
        $category = (string) ($client['category'] ?? 'unknown');
        $region = $this->resolveRegionKey($clientIp);
        $onlineRegions = $this->resolveOnlineRegions((array) ($context['online_ips'] ?? []));
        $activePlanUser = $this->isActivePlanUser($context);
        $usedTraffic = $this->contextInt($context, 'used_traffic', 0);
        $transferEnable = $this->contextInt($context, 'transfer_enable', 0);

        if ((bool) ($client['risky'] ?? false)) {
            $score += 45;
            $signals[] = 'risky_ua';
        }

        if (!$this->isWhitelistedClient($client, $userAgent)) {
            $score += 35;
            $signals[] = 'non_whitelisted_ua';
        }

        $ipFingerprint = filter_var($clientIp, FILTER_VALIDATE_IP)
            ? hash('sha256', trim($clientIp))
            : 'invalid';
        $ipFingerprints = [];
        if (!$trustedEgress) {
            $ipState = $this->rememberWindowValueWithState(
                $this->cacheKey('leak_ip', $userId, $token),
                $ipFingerprint,
                $window
            );
            $ipFingerprints = $ipState['values'];
            if ($strictMode && $ipState['had_values'] && $ipState['was_new']) {
                $score += 35;
                $signals[] = 'new_pull_ip';
            }
            if (count($ipFingerprints) > $allowedIpCount) {
                $score += 25;
                $signals[] = 'many_pull_ips';
            }
        }

        $uaState = $this->rememberWindowValueWithState(
            $this->cacheKey('leak_ua', $userId, $token),
            $category,
            $window
        );
        $uaCategories = $uaState['values'];
        if ($strictMode && $uaState['had_values'] && $uaState['was_new']) {
            $score += 35;
            $signals[] = 'new_pull_ua_category';
        }
        if (count($uaCategories) > $allowedUaCount) {
            $score += 35;
            $signals[] = 'many_pull_ua_categories';
        }

        $regions = [];
        if (!$trustedEgress && $this->isActionableRegion($region)) {
            $regionState = $this->rememberWindowValueWithState(
                $this->cacheKey('leak_region', $userId, $token),
                $region,
                $window
            );
            $regions = $regionState['values'];
            if ($strictMode && $regionState['had_values'] && $regionState['was_new']) {
                $score += 35;
                $signals[] = 'new_pull_region';
            }
            if (count($regions) > $allowedRegionCount) {
                $score += 35;
                $signals[] = 'many_pull_regions';
            }
        }

        if (!$trustedEgress && $strictMode && empty($onlineRegions)) {
            $score += 25;
            $signals[] = 'no_online_region_evidence';
        }

        if (!$trustedEgress && $this->isActionableRegion($region) && !empty($onlineRegions) && !in_array($region, $onlineRegions, true)) {
            $score += 45;
            $signals[] = 'online_region_mismatch';
        }

        if ($activePlanUser) {
            $lowUsageBytes = $this->configInt('leak_guard_active_plan_low_usage_bytes', 100 * 1024 * 1024, 0);
            if ($lowUsageBytes > 0 && $usedTraffic < $lowUsageBytes) {
                $score += 15;
                $signals[] = 'active_plan_low_usage';

                $veryLowUsageBytes = $this->configInt('leak_guard_active_plan_very_low_usage_bytes', 10 * 1024 * 1024, 0);
                if ($veryLowUsageBytes > 0 && $usedTraffic < $veryLowUsageBytes) {
                    $score += 15;
                    $signals[] = 'active_plan_very_low_usage';
                }

                if (count($uaCategories) > $allowedUaCount) {
                    $score += 25;
                    $signals[] = 'active_plan_low_usage_with_many_ua';
                }

                if (count($ipFingerprints) > $allowedIpCount) {
                    $score += 20;
                    $signals[] = 'active_plan_low_usage_with_many_ips';
                }

                if (!$trustedEgress && $this->isActionableRegion($region) && !empty($onlineRegions) && !in_array($region, $onlineRegions, true)) {
                    $score += 20;
                    $signals[] = 'active_plan_low_usage_with_online_mismatch';
                }
            }
        }

        if (!$trustedEgress && $ipIntelligence !== null) {
            $ipType = (string) ($ipIntelligence['ip_type'] ?? 'unknown');
            $weight = $this->configInt('ip_intelligence_score_weight', 20, 0);
            if ($ipType === 'hosting' && $weight > 0) {
                $score += $weight;
                $signals[] = 'ip_intelligence_hosting';
            } elseif ($ipType === 'proxy' && $weight > 0) {
                $score += $weight + 10;
                $signals[] = 'ip_intelligence_proxy';
            }
        }

        if ($score < $threshold) {
            return null;
        }

        $hitCount = $this->rememberLeakGuardHit($userId, $token, $window);
        $action = $this->configAction('leak_guard_action', 'empty');
        if (
            $this->configBool('enable_leak_guard_escalation', true)
            && $hitCount >= $this->configInt('leak_guard_escalate_hits', 3, 1)
        ) {
            $action = $this->configAction('leak_guard_escalate_action', 'reset_token_uuid');
        }

        return $this->decision(
            'subscription_leak_guard',
            '订阅疑似被探测拉取，已进入保护模式',
            $action,
            [
                'risk_score' => $score,
                'score_threshold' => $threshold,
                'signals' => $signals,
                'hit_count' => $hitCount,
                'ua_category' => $category,
                'trusted_egress' => $trustedEgress,
                'ip_count' => count($ipFingerprints),
                'ua_categories' => $uaCategories,
                'region' => $region,
                'regions' => $regions,
                'online_regions' => $onlineRegions,
                'active_plan_user' => $activePlanUser,
                'used_traffic' => $usedTraffic,
                'transfer_enable' => $transferEnable,
                'threshold' => $threshold,
            ]
        );
    }

    private function resolveIpIntelligence(string $clientIp, bool $trustedEgress): ?array
    {
        if ($trustedEgress || !$this->configBool('enable_ip_intelligence', true)) {
            return null;
        }

        $service = $this->ipIntelligence ?? new SubscriptionIpIntelligenceService($this->config);
        return $service->lookup($clientIp);
    }

    private function withIpIntelligenceMeta(array $decision, ?array $ipIntelligence): array
    {
        if ($ipIntelligence === null) {
            return $decision;
        }

        $decision['meta'] = array_merge((array) ($decision['meta'] ?? []), [
            'ip_asn' => $ipIntelligence['ip_asn'] ?? null,
            'ip_prefix' => $ipIntelligence['ip_prefix'] ?? null,
            'ip_country' => $ipIntelligence['ip_country'] ?? null,
            'ip_registry' => $ipIntelligence['ip_registry'] ?? null,
            'ip_org' => $ipIntelligence['ip_org'] ?? null,
            'ip_type' => $ipIntelligence['ip_type'] ?? 'unknown',
            'ip_risk_tags' => $ipIntelligence['ip_risk_tags'] ?? [],
        ]);

        return $decision;
    }

    private function isActivePlanUser(array $context): bool
    {
        $planId = $this->contextInt($context, 'plan_id', 0);
        $transferEnable = $this->contextInt($context, 'transfer_enable', 0);
        $expiredAt = $context['expired_at'] ?? null;

        if ($planId <= 0 || $transferEnable <= 0) {
            return false;
        }

        if ($expiredAt === null || $expiredAt === '') {
            return true;
        }

        if (!is_numeric($expiredAt)) {
            return false;
        }

        return (int) $expiredAt > time();
    }

    private function contextInt(array $context, string $key, int $default): int
    {
        $value = $context[$key] ?? $default;
        if (!is_numeric($value)) {
            return $default;
        }

        return max(0, (int) $value);
    }

    private function rememberLeakGuardHit(int $userId, string $token, int $window): int
    {
        $cacheKey = $this->cacheKey('leak_hit', $userId, $token);
        $now = time();
        $items = Cache::get($cacheKey, []);
        if (!is_array($items)) {
            $items = [];
        }

        $items = array_values(array_filter(
            $items,
            static fn($timestamp): bool => is_numeric($timestamp) && ($now - (int) $timestamp) < $window
        ));
        $items[] = $now;

        Cache::put($cacheKey, $items, $window);

        return count($items);
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
