<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TelegramService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RiskController extends Controller
{
    private const MAX_DAYS = 90;
    private const DEFAULT_DAYS = 30;
    private const DEFAULT_MIN_USERS = 3;
    // Node host -> IP cache TTL (includes DNS resolution for domains).
    private const EXCLUDE_SERVER_IPS_CACHE_TTL = 86400; // 24h
    // Alerts defaults
    private const DEFAULT_ALERT_WINDOW_MINUTES = 10;
    private const DEFAULT_ALERT_COOLDOWN_MINUTES = 30;
    private const DEFAULT_ALERT_MAX_ITEMS = 10;
    private const DEFAULT_ALERT_SUBSCRIBE_IP_THRESHOLD = 200;
    private const DEFAULT_ALERT_SUBSCRIBE_TOKEN_THRESHOLD = 50;
    private const DEFAULT_ALERT_SUBSCRIBE_UA_THRESHOLD = 300;
    private const DEFAULT_ALERT_LOGIN_FAILED_IP_THRESHOLD = 30;
    private const DEFAULT_ALERT_LOGIN_FAILED_UA_THRESHOLD = 50;

    private function isRiskCenterEnabled(): bool
    {
        return $this->parseBool(
            admin_setting('risk_center_enable', true),
            true
        );
    }

    private function getSinceTs(Request $request): int
    {
        $days = (int) $request->input('days', self::DEFAULT_DAYS);
        $days = max(1, min(self::MAX_DAYS, $days));
        return time() - ($days * 86400);
    }

    private function parseBool(mixed $value, bool $default): bool
    {
        if ($value === null) {
            return $default;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return ((int) $value) !== 0;
        }
        if (is_string($value)) {
            $v = strtolower(trim($value));
            if ($v === '') {
                return $default;
            }
            if (in_array($v, ['1', 'true', 'yes', 'y', 'on'], true)) {
                return true;
            }
            if (in_array($v, ['0', 'false', 'no', 'n', 'off'], true)) {
                return false;
            }
        }
        if (is_array($value) || is_object($value)) {
            return $default;
        }
        $filtered = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        return $filtered ?? $default;
    }

    private function parseInt(mixed $value, int $default, int $min, int $max): int
    {
        if ($value === null) {
            return $default;
        }
        if (is_string($value) && trim($value) === '') {
            return $default;
        }
        if (!is_numeric($value)) {
            return $default;
        }
        $n = (int) $value;
        return max($min, min($max, $n));
    }

    private function normalizeIpHost(mixed $host): ?string
    {
        if ($host === null) {
            return null;
        }
        $host = trim((string) $host);
        if ($host === '') {
            return null;
        }

        // Handle "host:port" for IPv4.
        if (str_contains($host, ':') && !filter_var($host, FILTER_VALIDATE_IP)) {
            $parsed = parse_url('tcp://' . $host);
            if (is_array($parsed) && isset($parsed['host'])) {
                $host = (string) $parsed['host'];
            }
        }

        if (!filter_var($host, FILTER_VALIDATE_IP)) {
            return null;
        }
        return $host;
    }

    private function normalizeHost(mixed $host): ?string
    {
        if ($host === null) {
            return null;
        }

        $raw = trim((string) $host);
        if ($raw === '') {
            return null;
        }

        $parsed = null;
        if (str_contains($raw, '://')) {
            $parsed = parse_url($raw);
        } else {
            $parsed = parse_url('tcp://' . $raw);
        }

        if (is_array($parsed) && isset($parsed['host']) && is_string($parsed['host']) && trim($parsed['host']) !== '') {
            $raw = (string) $parsed['host'];
        }

        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        // [IPv6] -> IPv6
        if (str_starts_with($raw, '[') && str_ends_with($raw, ']')) {
            $raw = substr($raw, 1, -1);
        }

        $raw = rtrim($raw, '.');
        return $raw !== '' ? $raw : null;
    }

    private function resolveDomainIps(string $domain): array
    {
        $domain = trim($domain);
        if ($domain === '') {
            return [];
        }
        if (filter_var($domain, FILTER_VALIDATE_IP)) {
            return [$domain];
        }

        // IDN to ASCII (best-effort)
        if (function_exists('idn_to_ascii') && preg_match('/[^\x20-\x7E]/', $domain)) {
            try {
                $ascii = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
                if (is_string($ascii) && trim($ascii) !== '') {
                    $domain = trim($ascii);
                }
            } catch (\Throwable) {
            }
        }

        $ips = [];

        if (function_exists('dns_get_record')) {
            $a = @dns_get_record($domain, DNS_A);
            if (is_array($a)) {
                foreach ($a as $rec) {
                    $ip = $rec['ip'] ?? null;
                    if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                        $ips[] = $ip;
                    }
                }
            }

            $aaaa = @dns_get_record($domain, DNS_AAAA);
            if (is_array($aaaa)) {
                foreach ($aaaa as $rec) {
                    $ip = $rec['ipv6'] ?? null;
                    if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                        $ips[] = $ip;
                    }
                }
            }
        }

        if (!$ips && function_exists('gethostbynamel')) {
            $v4s = @gethostbynamel($domain);
            if (is_array($v4s)) {
                foreach ($v4s as $ip) {
                    if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                        $ips[] = $ip;
                    }
                }
            }
        }

        return array_values(array_unique($ips));
    }

    private function parseExcludeIpList(mixed $raw): array
    {
        if (is_array($raw)) {
            $values = $raw;
        } elseif (is_string($raw)) {
            $values = preg_split('/[,\s]+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        } else {
            return [];
        }

        $ips = [];
        foreach ($values as $v) {
            $ip = $this->normalizeIpHost($v);
            if ($ip) {
                $ips[] = $ip;
            }
        }
        return array_values(array_unique($ips));
    }

    private function getServerHostIps(): array
    {
        return Cache::remember('risk:exclude_server_ips', self::EXCLUDE_SERVER_IPS_CACHE_TTL, function (): array {
            try {
                $hosts = DB::table('v2_server')->pluck('host');
            } catch (\Throwable) {
                return [];
            }

            $ips = [];
            $domains = [];
            foreach ($hosts as $host) {
                $normalizedHost = $this->normalizeHost($host);
                if (!$normalizedHost) {
                    continue;
                }

                $ip = $this->normalizeIpHost($normalizedHost);
                if ($ip) {
                    $ips[] = $ip;
                    continue;
                }
                // Keep domains for DNS resolving (once per 24h)
                $domains[] = $normalizedHost;
            }

            foreach (array_values(array_unique($domains)) as $domain) {
                $ips = array_merge($ips, $this->resolveDomainIps($domain));
            }
            return array_values(array_unique($ips));
        });
    }

    private function getExcludedIpsForSummary(Request $request): array
    {
        $excluded = [];

        // Manual exclude list (env/admin_setting), only supports exact IPs.
        $excluded = array_merge(
            $excluded,
            $this->parseExcludeIpList(env('XBOARD_RISK_EXCLUDE_IPS', '')),
            $this->parseExcludeIpList(admin_setting('risk_exclude_ips'))
        );

        $defaultExcludeNodes = $this->parseBool(
            admin_setting('risk_exclude_node_ips', env('XBOARD_RISK_EXCLUDE_NODE_IPS', true)),
            true
        );
        $excludeNodeIps = $this->parseBool($request->input('exclude_node_ips', null), $defaultExcludeNodes);
        if ($excludeNodeIps) {
            $excluded = array_merge($excluded, $this->getServerHostIps());
        }

        $excluded = array_values(array_unique(array_filter($excluded, fn($v) => is_string($v) && $v !== '')));
        return $excluded;
    }

    private function getEventTypes(Request $request): array
    {
        $raw = $request->input('event_types');
        $types = [];

        if (is_string($raw) && trim($raw) !== '') {
            $types = preg_split('/[|,\\s]+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        } elseif (is_array($raw)) {
            $types = $raw;
        }

        $types = array_values(array_filter(array_map(function ($t) {
            $t = is_string($t) || is_numeric($t) ? trim((string) $t) : '';
            return $t !== '' ? $t : null;
        }, $types)));

        if (!$types) {
            return ['subscribe'];
        }

        // Whitelist known event types (future types can be added here).
        $allowed = ['subscribe', 'login_success', 'login_failed'];
        $types = array_values(array_intersect($types, $allowed));
        return $types ?: ['subscribe'];
    }

    private function getEventTypesOrNull(Request $request): ?array
    {
        $raw = $request->input('event_types');
        if ($raw === null) {
            return null;
        }
        if (is_string($raw) && trim($raw) === '') {
            return null;
        }
        if (is_array($raw) && count($raw) === 0) {
            return null;
        }
        return $this->getEventTypes($request);
    }

    private function ensureTableReady()
    {
        if (Schema::hasTable('v2_risk_event')) {
            return null;
        }
        return $this->fail([500000, '风控事件表不存在，请先执行数据库迁移（php artisan migrate）']);
    }

    private function parseUaWhitelist(?string $raw): array
    {
        $raw = is_string($raw) ? trim($raw) : '';
        if ($raw === '') {
            return [];
        }
        $items = preg_split('/[,\r\n]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $items = array_values(array_filter(array_map(fn($v) => trim((string) $v), $items), fn($v) => $v !== ''));
        return array_slice($items, 0, 200);
    }

    private function isUaWhitelisted(?string $ua, array $patterns): bool
    {
        if (!$patterns) {
            return false;
        }
        $ua = is_string($ua) ? strtolower(trim($ua)) : '';
        if ($ua === '') {
            return false;
        }
        foreach ($patterns as $p) {
            $p = strtolower(trim((string) $p));
            if ($p === '') {
                continue;
            }
            if (str_contains($ua, $p)) {
                return true;
            }
        }
        return false;
    }

    public function getConfig(Request $request)
    {
        $enabled = $this->isRiskCenterEnabled();

        $excludeNodeIps = $this->parseBool(
            admin_setting('risk_exclude_node_ips', env('XBOARD_RISK_EXCLUDE_NODE_IPS', true)),
            true
        );

        $trustedProxies = admin_setting('trusted_proxies', config('app.trusted_proxies', ''));

        $proxySecretHeader = trim((string) admin_setting('proxy_trust_secret_header', config('app.proxy_trust_secret_header', 'X-Xboard-Proxy-Secret')));
        if ($proxySecretHeader === '') {
            $proxySecretHeader = 'X-Xboard-Proxy-Secret';
        }

        $proxySecretFromServerToken = $this->parseBool(
            admin_setting('proxy_trust_secret_from_server_token', config('app.proxy_trust_secret_from_server_token', false)),
            false
        );

        $proxySecret = admin_setting('proxy_trust_secret', config('app.proxy_trust_secret'));
        $proxySecretSet = is_string($proxySecret) && trim($proxySecret) !== '';

        $serverToken = admin_setting('server_token');
        $serverTokenSet = is_string($serverToken) && strlen(trim($serverToken)) >= 16;

        $alertEnable = $this->parseBool(admin_setting('risk_alert_enable', false), false);
        $alertNotifyTelegram = $this->parseBool(admin_setting('risk_alert_notify_telegram', false), false);
        $alertWindowMinutes = $this->parseInt(admin_setting('risk_alert_window_minutes', self::DEFAULT_ALERT_WINDOW_MINUTES), self::DEFAULT_ALERT_WINDOW_MINUTES, 1, 120);
        $alertCooldownMinutes = $this->parseInt(admin_setting('risk_alert_cooldown_minutes', self::DEFAULT_ALERT_COOLDOWN_MINUTES), self::DEFAULT_ALERT_COOLDOWN_MINUTES, 1, 1440);
        $alertMaxItems = $this->parseInt(admin_setting('risk_alert_max_items', self::DEFAULT_ALERT_MAX_ITEMS), self::DEFAULT_ALERT_MAX_ITEMS, 1, 50);

        $alertSubscribeIpThreshold = $this->parseInt(admin_setting('risk_alert_subscribe_ip_threshold', self::DEFAULT_ALERT_SUBSCRIBE_IP_THRESHOLD), self::DEFAULT_ALERT_SUBSCRIBE_IP_THRESHOLD, 1, 1000000);
        $alertSubscribeTokenThreshold = $this->parseInt(admin_setting('risk_alert_subscribe_token_threshold', self::DEFAULT_ALERT_SUBSCRIBE_TOKEN_THRESHOLD), self::DEFAULT_ALERT_SUBSCRIBE_TOKEN_THRESHOLD, 1, 1000000);
        $alertSubscribeUaThreshold = $this->parseInt(admin_setting('risk_alert_subscribe_ua_threshold', self::DEFAULT_ALERT_SUBSCRIBE_UA_THRESHOLD), self::DEFAULT_ALERT_SUBSCRIBE_UA_THRESHOLD, 1, 1000000);

        $alertLoginFailedIpThreshold = $this->parseInt(admin_setting('risk_alert_login_failed_ip_threshold', self::DEFAULT_ALERT_LOGIN_FAILED_IP_THRESHOLD), self::DEFAULT_ALERT_LOGIN_FAILED_IP_THRESHOLD, 1, 1000000);
        $alertLoginFailedUaThreshold = $this->parseInt(admin_setting('risk_alert_login_failed_ua_threshold', self::DEFAULT_ALERT_LOGIN_FAILED_UA_THRESHOLD), self::DEFAULT_ALERT_LOGIN_FAILED_UA_THRESHOLD, 1, 1000000);

        return $this->success([
            'risk_center_enable' => $enabled,
            'risk_exclude_ips' => (string) admin_setting('risk_exclude_ips', ''),
            'risk_exclude_node_ips' => $excludeNodeIps,
            'risk_ua_whitelist' => (string) admin_setting('risk_ua_whitelist', ''),
            'trusted_proxies' => is_string($trustedProxies) ? $trustedProxies : '',
            'proxy_trust_secret_set' => $proxySecretSet,
            'proxy_trust_secret_header' => $proxySecretHeader,
            'proxy_trust_secret_from_server_token' => $proxySecretFromServerToken,
            'server_token_set' => $serverTokenSet,
            'risk_alert_enable' => $alertEnable,
            'risk_alert_notify_telegram' => $alertNotifyTelegram,
            'risk_alert_window_minutes' => $alertWindowMinutes,
            'risk_alert_cooldown_minutes' => $alertCooldownMinutes,
            'risk_alert_max_items' => $alertMaxItems,
            'risk_alert_subscribe_ip_threshold' => $alertSubscribeIpThreshold,
            'risk_alert_subscribe_token_threshold' => $alertSubscribeTokenThreshold,
            'risk_alert_subscribe_ua_threshold' => $alertSubscribeUaThreshold,
            'risk_alert_login_failed_ip_threshold' => $alertLoginFailedIpThreshold,
            'risk_alert_login_failed_ua_threshold' => $alertLoginFailedUaThreshold,
        ]);
    }

    public function saveConfig(Request $request)
    {
        $data = $request->validate([
            'risk_center_enable' => 'nullable|boolean',
            'risk_exclude_ips' => 'nullable|string|max:4096',
            'risk_exclude_node_ips' => 'nullable|boolean',
            'risk_ua_whitelist' => 'nullable|string|max:4096',
            'trusted_proxies' => 'nullable|string|max:2048',
            'proxy_trust_secret' => 'nullable|string|max:256',
            'proxy_trust_secret_header' => 'nullable|string|max:64',
            'proxy_trust_secret_from_server_token' => 'nullable|boolean',
            'risk_alert_enable' => 'nullable|boolean',
            'risk_alert_notify_telegram' => 'nullable|boolean',
            'risk_alert_window_minutes' => 'nullable|integer|min:1|max:120',
            'risk_alert_cooldown_minutes' => 'nullable|integer|min:1|max:1440',
            'risk_alert_max_items' => 'nullable|integer|min:1|max:50',
            'risk_alert_subscribe_ip_threshold' => 'nullable|integer|min:1|max:1000000',
            'risk_alert_subscribe_token_threshold' => 'nullable|integer|min:1|max:1000000',
            'risk_alert_subscribe_ua_threshold' => 'nullable|integer|min:1|max:1000000',
            'risk_alert_login_failed_ip_threshold' => 'nullable|integer|min:1|max:1000000',
            'risk_alert_login_failed_ua_threshold' => 'nullable|integer|min:1|max:1000000',
        ]);

        $updates = [];

        if (array_key_exists('risk_center_enable', $data)) {
            $updates['risk_center_enable'] = $this->parseBool($data['risk_center_enable'], true) ? 1 : 0;
        }
        if (array_key_exists('risk_exclude_ips', $data)) {
            $raw = is_string($data['risk_exclude_ips']) ? trim($data['risk_exclude_ips']) : '';
            $updates['risk_exclude_ips'] = $raw !== '' ? $raw : null;
        }
        if (array_key_exists('risk_exclude_node_ips', $data)) {
            $updates['risk_exclude_node_ips'] = $this->parseBool($data['risk_exclude_node_ips'], true) ? 1 : 0;
        }
        if (array_key_exists('risk_ua_whitelist', $data)) {
            $raw = is_string($data['risk_ua_whitelist']) ? trim($data['risk_ua_whitelist']) : '';
            $updates['risk_ua_whitelist'] = $raw !== '' ? $raw : null;
        }

        if (array_key_exists('trusted_proxies', $data)) {
            $raw = is_string($data['trusted_proxies']) ? trim($data['trusted_proxies']) : '';
            $updates['trusted_proxies'] = $raw !== '' ? $raw : null;
        }

        if (array_key_exists('proxy_trust_secret_from_server_token', $data)) {
            $updates['proxy_trust_secret_from_server_token'] = $this->parseBool($data['proxy_trust_secret_from_server_token'], false) ? 1 : 0;
        }
        if (array_key_exists('proxy_trust_secret_header', $data)) {
            $raw = is_string($data['proxy_trust_secret_header']) ? trim($data['proxy_trust_secret_header']) : '';
            $updates['proxy_trust_secret_header'] = $raw !== '' ? $raw : null;
        }
        if (array_key_exists('proxy_trust_secret', $data)) {
            $raw = is_string($data['proxy_trust_secret']) ? trim($data['proxy_trust_secret']) : '';
            $updates['proxy_trust_secret'] = $raw !== '' ? $raw : null;
        }

        if (array_key_exists('risk_alert_enable', $data)) {
            $updates['risk_alert_enable'] = $this->parseBool($data['risk_alert_enable'], false) ? 1 : 0;
        }
        if (array_key_exists('risk_alert_notify_telegram', $data)) {
            $updates['risk_alert_notify_telegram'] = $this->parseBool($data['risk_alert_notify_telegram'], false) ? 1 : 0;
        }
        if (array_key_exists('risk_alert_window_minutes', $data)) {
            $updates['risk_alert_window_minutes'] = $this->parseInt($data['risk_alert_window_minutes'], self::DEFAULT_ALERT_WINDOW_MINUTES, 1, 120);
        }
        if (array_key_exists('risk_alert_cooldown_minutes', $data)) {
            $updates['risk_alert_cooldown_minutes'] = $this->parseInt($data['risk_alert_cooldown_minutes'], self::DEFAULT_ALERT_COOLDOWN_MINUTES, 1, 1440);
        }
        if (array_key_exists('risk_alert_max_items', $data)) {
            $updates['risk_alert_max_items'] = $this->parseInt($data['risk_alert_max_items'], self::DEFAULT_ALERT_MAX_ITEMS, 1, 50);
        }
        if (array_key_exists('risk_alert_subscribe_ip_threshold', $data)) {
            $updates['risk_alert_subscribe_ip_threshold'] = $this->parseInt($data['risk_alert_subscribe_ip_threshold'], self::DEFAULT_ALERT_SUBSCRIBE_IP_THRESHOLD, 1, 1000000);
        }
        if (array_key_exists('risk_alert_subscribe_token_threshold', $data)) {
            $updates['risk_alert_subscribe_token_threshold'] = $this->parseInt($data['risk_alert_subscribe_token_threshold'], self::DEFAULT_ALERT_SUBSCRIBE_TOKEN_THRESHOLD, 1, 1000000);
        }
        if (array_key_exists('risk_alert_subscribe_ua_threshold', $data)) {
            $updates['risk_alert_subscribe_ua_threshold'] = $this->parseInt($data['risk_alert_subscribe_ua_threshold'], self::DEFAULT_ALERT_SUBSCRIBE_UA_THRESHOLD, 1, 1000000);
        }
        if (array_key_exists('risk_alert_login_failed_ip_threshold', $data)) {
            $updates['risk_alert_login_failed_ip_threshold'] = $this->parseInt($data['risk_alert_login_failed_ip_threshold'], self::DEFAULT_ALERT_LOGIN_FAILED_IP_THRESHOLD, 1, 1000000);
        }
        if (array_key_exists('risk_alert_login_failed_ua_threshold', $data)) {
            $updates['risk_alert_login_failed_ua_threshold'] = $this->parseInt($data['risk_alert_login_failed_ua_threshold'], self::DEFAULT_ALERT_LOGIN_FAILED_UA_THRESHOLD, 1, 1000000);
        }

        if ($updates) {
            admin_setting($updates);
        }
        Cache::forget('risk:exclude_server_ips');

        return $this->success(true);
    }

    public function alertsCheck(Request $request)
    {
        if ($resp = $this->ensureTableReady()) {
            return $resp;
        }
        if (!$this->isRiskCenterEnabled()) {
            return $this->success([
                'enabled' => false,
                'alert_enabled' => false,
                'window_minutes' => self::DEFAULT_ALERT_WINDOW_MINUTES,
                'notified' => false,
                'items' => [],
            ]);
        }

        $alertEnabled = $this->parseBool(admin_setting('risk_alert_enable', false), false);
        if (!$alertEnabled) {
            return $this->success([
                'enabled' => true,
                'alert_enabled' => false,
                'window_minutes' => self::DEFAULT_ALERT_WINDOW_MINUTES,
                'notified' => false,
                'items' => [],
            ]);
        }

        $windowMinutes = $this->parseInt(
            $request->input('window_minutes', admin_setting('risk_alert_window_minutes', self::DEFAULT_ALERT_WINDOW_MINUTES)),
            self::DEFAULT_ALERT_WINDOW_MINUTES,
            1,
            120
        );
        $since = time() - ($windowMinutes * 60);

        $maxItems = $this->parseInt(
            $request->input('max_items', admin_setting('risk_alert_max_items', self::DEFAULT_ALERT_MAX_ITEMS)),
            self::DEFAULT_ALERT_MAX_ITEMS,
            1,
            50
        );

        $notify = $this->parseBool(
            $request->input('notify', admin_setting('risk_alert_notify_telegram', false)),
            false
        );
        $cooldownMinutes = $this->parseInt(
            $request->input('cooldown_minutes', admin_setting('risk_alert_cooldown_minutes', self::DEFAULT_ALERT_COOLDOWN_MINUTES)),
            self::DEFAULT_ALERT_COOLDOWN_MINUTES,
            1,
            1440
        );
        $cooldownSeconds = $cooldownMinutes * 60;

        $thresholdSubscribeIp = $this->parseInt(admin_setting('risk_alert_subscribe_ip_threshold', self::DEFAULT_ALERT_SUBSCRIBE_IP_THRESHOLD), self::DEFAULT_ALERT_SUBSCRIBE_IP_THRESHOLD, 1, 1000000);
        $thresholdSubscribeToken = $this->parseInt(admin_setting('risk_alert_subscribe_token_threshold', self::DEFAULT_ALERT_SUBSCRIBE_TOKEN_THRESHOLD), self::DEFAULT_ALERT_SUBSCRIBE_TOKEN_THRESHOLD, 1, 1000000);
        $thresholdSubscribeUa = $this->parseInt(admin_setting('risk_alert_subscribe_ua_threshold', self::DEFAULT_ALERT_SUBSCRIBE_UA_THRESHOLD), self::DEFAULT_ALERT_SUBSCRIBE_UA_THRESHOLD, 1, 1000000);
        $thresholdLoginFailedIp = $this->parseInt(admin_setting('risk_alert_login_failed_ip_threshold', self::DEFAULT_ALERT_LOGIN_FAILED_IP_THRESHOLD), self::DEFAULT_ALERT_LOGIN_FAILED_IP_THRESHOLD, 1, 1000000);
        $thresholdLoginFailedUa = $this->parseInt(admin_setting('risk_alert_login_failed_ua_threshold', self::DEFAULT_ALERT_LOGIN_FAILED_UA_THRESHOLD), self::DEFAULT_ALERT_LOGIN_FAILED_UA_THRESHOLD, 1, 1000000);

        $excludedIps = $this->getExcludedIpsForSummary($request);
        $uaWhitelist = $this->parseUaWhitelist((string) admin_setting('risk_ua_whitelist', ''));

        $items = [];

        $subscribeIpRows = DB::table('v2_risk_event')
            ->where('created_at', '>=', $since)
            ->where('event_type', '=', 'subscribe')
            ->whereNotNull('ip')
            ->where('ip', '<>', '')
            ->selectRaw('ip, COUNT(*) AS event_count, COUNT(DISTINCT token_hash) AS token_count, COUNT(DISTINCT ua_hash) AS ua_count, MAX(created_at) AS last_seen')
            ->groupBy('ip')
            ->having('event_count', '>=', $thresholdSubscribeIp)
            ->orderByDesc('event_count')
            ->limit($maxItems);
        if ($excludedIps) {
            $subscribeIpRows->whereNotIn('ip', $excludedIps);
        }
        foreach ($subscribeIpRows->get() as $row) {
            $items[] = [
                'type' => 'subscribe_ip',
                'key' => (string) $row->ip,
                'event_count' => (int) $row->event_count,
                'token_count' => (int) $row->token_count,
                'ua_count' => (int) $row->ua_count,
                'last_seen' => (int) $row->last_seen,
            ];
        }

        $subscribeTokenRows = DB::table('v2_risk_event')
            ->where('created_at', '>=', $since)
            ->where('event_type', '=', 'subscribe')
            ->whereNotNull('token_hash')
            ->where('token_hash', '<>', '')
            ->whereNotNull('ip')
            ->where('ip', '<>', '')
            ->selectRaw('token_hash, MAX(user_id) AS user_id, COUNT(*) AS event_count, COUNT(DISTINCT ip) AS ip_count, COUNT(DISTINCT ua_hash) AS ua_count, MAX(created_at) AS last_seen')
            ->groupBy('token_hash')
            ->having('event_count', '>=', $thresholdSubscribeToken)
            ->orderByDesc('event_count')
            ->limit($maxItems);
        if ($excludedIps) {
            $subscribeTokenRows->whereNotIn('ip', $excludedIps);
        }
        foreach ($subscribeTokenRows->get() as $row) {
            $items[] = [
                'type' => 'subscribe_token',
                'key' => (string) $row->token_hash,
                'user_id' => $row->user_id !== null ? (int) $row->user_id : null,
                'event_count' => (int) $row->event_count,
                'ip_count' => (int) $row->ip_count,
                'ua_count' => (int) $row->ua_count,
                'last_seen' => (int) $row->last_seen,
            ];
        }

        $subscribeUaRows = DB::table('v2_risk_event')
            ->where('created_at', '>=', $since)
            ->where('event_type', '=', 'subscribe')
            ->whereNotNull('ua_hash')
            ->where('ua_hash', '<>', '')
            ->whereNotNull('ip')
            ->where('ip', '<>', '')
            ->selectRaw('ua_hash, MAX(ua) AS ua, COUNT(*) AS event_count, COUNT(DISTINCT ip) AS ip_count, COUNT(DISTINCT user_id) AS user_count, MAX(created_at) AS last_seen')
            ->groupBy('ua_hash')
            ->having('event_count', '>=', $thresholdSubscribeUa)
            ->orderByDesc('event_count')
            ->limit($maxItems);
        if ($excludedIps) {
            $subscribeUaRows->whereNotIn('ip', $excludedIps);
        }
        foreach ($subscribeUaRows->get() as $row) {
            $ua = $row->ua !== null ? (string) $row->ua : null;
            if ($this->isUaWhitelisted($ua, $uaWhitelist)) {
                continue;
            }
            $items[] = [
                'type' => 'subscribe_ua',
                'key' => (string) $row->ua_hash,
                'ua' => $ua,
                'event_count' => (int) $row->event_count,
                'ip_count' => (int) $row->ip_count,
                'user_count' => (int) $row->user_count,
                'last_seen' => (int) $row->last_seen,
            ];
        }

        $loginFailedIpRows = DB::table('v2_risk_event')
            ->where('created_at', '>=', $since)
            ->where('event_type', '=', 'login_failed')
            ->whereNotNull('ip')
            ->where('ip', '<>', '')
            ->selectRaw('ip, COUNT(*) AS event_count, COUNT(DISTINCT ua_hash) AS ua_count, MAX(created_at) AS last_seen')
            ->groupBy('ip')
            ->having('event_count', '>=', $thresholdLoginFailedIp)
            ->orderByDesc('event_count')
            ->limit($maxItems);
        if ($excludedIps) {
            $loginFailedIpRows->whereNotIn('ip', $excludedIps);
        }
        foreach ($loginFailedIpRows->get() as $row) {
            $items[] = [
                'type' => 'login_failed_ip',
                'key' => (string) $row->ip,
                'event_count' => (int) $row->event_count,
                'ua_count' => (int) $row->ua_count,
                'last_seen' => (int) $row->last_seen,
            ];
        }

        $loginFailedUaRows = DB::table('v2_risk_event')
            ->where('created_at', '>=', $since)
            ->where('event_type', '=', 'login_failed')
            ->whereNotNull('ua_hash')
            ->where('ua_hash', '<>', '')
            ->whereNotNull('ip')
            ->where('ip', '<>', '')
            ->selectRaw('ua_hash, MAX(ua) AS ua, COUNT(*) AS event_count, COUNT(DISTINCT ip) AS ip_count, MAX(created_at) AS last_seen')
            ->groupBy('ua_hash')
            ->having('event_count', '>=', $thresholdLoginFailedUa)
            ->orderByDesc('event_count')
            ->limit($maxItems);
        if ($excludedIps) {
            $loginFailedUaRows->whereNotIn('ip', $excludedIps);
        }
        foreach ($loginFailedUaRows->get() as $row) {
            $ua = $row->ua !== null ? (string) $row->ua : null;
            if ($this->isUaWhitelisted($ua, $uaWhitelist)) {
                continue;
            }
            $items[] = [
                'type' => 'login_failed_ua',
                'key' => (string) $row->ua_hash,
                'ua' => $ua,
                'event_count' => (int) $row->event_count,
                'ip_count' => (int) $row->ip_count,
                'last_seen' => (int) $row->last_seen,
            ];
        }

        if (!$items) {
            return $this->success([
                'enabled' => true,
                'alert_enabled' => true,
                'window_minutes' => $windowMinutes,
                'notified' => false,
                'items' => [],
            ]);
        }

        $telegramBotToken = trim((string) admin_setting('telegram_bot_token', ''));
        $telegramNotifyOk = $notify && $telegramBotToken !== '';

        $notifiedAny = false;
        foreach ($items as &$item) {
            $type = (string) ($item['type'] ?? '');
            $key = (string) ($item['key'] ?? '');
            $cacheKey = 'risk:alert:cooldown:' . $type . ':' . $key;

            $item['cooldown_hit'] = Cache::has($cacheKey);
            $item['notified'] = false;

            if (!$notify || $item['cooldown_hit']) {
                continue;
            }
            if (!$telegramNotifyOk) {
                $item['notify_error'] = 'telegram_not_configured';
                continue;
            }

            $message = $this->formatAlertMessage($type, $item, $windowMinutes);
            try {
                app(TelegramService::class)->sendMessageWithAdmin($message, true);
                Cache::put($cacheKey, 1, $cooldownSeconds);
                $item['notified'] = true;
                $notifiedAny = true;
            } catch (\Throwable) {
                $item['notify_error'] = 'telegram_send_failed';
            }
        }
        unset($item);

        return $this->success([
            'enabled' => true,
            'alert_enabled' => true,
            'window_minutes' => $windowMinutes,
            'notified' => $notifiedAny,
            'items' => $items,
        ]);
    }

    private function formatAlertMessage(string $type, array $item, int $windowMinutes): string
    {
        $title = match ($type) {
            'subscribe_ip' => '订阅 IP 异常',
            'subscribe_token' => '订阅 Token 异常',
            'subscribe_ua' => '订阅 UA 异常',
            'login_failed_ip' => '登录失败 IP 异常',
            'login_failed_ua' => '登录失败 UA 异常',
            default => '风控异常',
        };

        $lines = [];
        $lines[] = "【风控告警】{$title}（{$windowMinutes} 分钟）";

        if (isset($item['key'])) {
            $k = (string) $item['key'];
            $label = str_contains($type, 'ip') ? 'IP' : (str_contains($type, 'token') ? 'TokenHash' : 'UAHash');
            $lines[] = "{$label}: {$k}";
        }
        if (isset($item['event_count'])) {
            $lines[] = '事件: ' . (int) $item['event_count'];
        }

        foreach (['token_count' => 'Token数', 'ua_count' => 'UA数', 'ip_count' => 'IP数', 'user_count' => '用户数'] as $k => $label) {
            if (array_key_exists($k, $item)) {
                $lines[] = "{$label}: " . (int) $item[$k];
            }
        }

        if (isset($item['user_id']) && $item['user_id'] !== null) {
            $lines[] = '用户ID: ' . (int) $item['user_id'];
        }
        if (isset($item['ua']) && is_string($item['ua']) && trim($item['ua']) !== '') {
            $ua = trim($item['ua']);
            if (function_exists('mb_strlen') && mb_strlen($ua, 'UTF-8') > 180) {
                $ua = mb_substr($ua, 0, 180, 'UTF-8') . '…';
            } elseif (strlen($ua) > 180) {
                $ua = substr($ua, 0, 180) . '…';
            }
            $lines[] = "UA: {$ua}";
        }
        if (isset($item['last_seen'])) {
            $lines[] = '最后: ' . date('Y-m-d H:i:s', (int) $item['last_seen']);
        }
        return implode("\n", $lines);
    }

    public function purge(Request $request)
    {
        if ($resp = $this->ensureTableReady()) {
            return $resp;
        }

        $data = $request->validate([
            'mode' => 'nullable|in:older_than,all',
            'keep_days' => 'nullable|integer|min:1|max:365',
            'dry_run' => 'nullable|boolean',
            'batch_size' => 'nullable|integer|min:500|max:20000',
            'max_seconds' => 'nullable|integer|min:1|max:120',
            'confirm' => 'nullable|string|max:32',
        ]);

        $mode = $data['mode'] ?? 'older_than';
        $dryRun = $this->parseBool($data['dry_run'] ?? null, false);

        $builder = DB::table('v2_risk_event');
        $beforeTs = null;

        if ($mode === 'older_than') {
            $keepDays = (int) ($data['keep_days'] ?? self::DEFAULT_DAYS);
            $keepDays = max(1, min(365, $keepDays));
            $beforeTs = time() - ($keepDays * 86400);
            $builder->where('created_at', '<', $beforeTs);
        } else {
            $confirm = (string) ($data['confirm'] ?? '');
            if ($confirm !== 'DELETE') {
                return $this->fail([400, '清理全部数据需要 confirm=DELETE']);
            }
        }

        $eventTypes = $this->getEventTypesOrNull($request);
        if ($eventTypes) {
            $builder->whereIn('event_type', $eventTypes);
        }

        $wouldDelete = (clone $builder)->count();

        if ($dryRun) {
            return $this->success([
                'mode' => $mode,
                'before_ts' => $beforeTs,
                'event_types' => $eventTypes,
                'would_delete' => $wouldDelete,
            ]);
        }

        $batchSize = (int) ($data['batch_size'] ?? 5000);
        $maxSeconds = (int) ($data['max_seconds'] ?? 20);
        $batchSize = max(500, min(20000, $batchSize));
        $maxSeconds = max(1, min(120, $maxSeconds));

        $deleted = 0;
        $startedAt = microtime(true);

        while (true) {
            if ((microtime(true) - $startedAt) > $maxSeconds) {
                break;
            }

            $ids = (clone $builder)
                ->orderBy('id')
                ->limit($batchSize)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $deleted += DB::table('v2_risk_event')->whereIn('id', $ids)->delete();
        }

        return $this->success([
            'mode' => $mode,
            'before_ts' => $beforeTs,
            'event_types' => $eventTypes,
            'would_delete' => $wouldDelete,
            'deleted' => $deleted,
            'done' => $deleted >= $wouldDelete,
        ]);
    }

    public function ipSummary(Request $request)
    {
        if ($resp = $this->ensureTableReady()) {
            return response(['data' => [], 'total' => 0]);
        }
        if (!$this->isRiskCenterEnabled()) {
            return response(['data' => [], 'total' => 0]);
        }

        $since = $this->getSinceTs($request);
        $eventTypes = $this->getEventTypes($request);

        $minUsers = (int) $request->input('min_users', self::DEFAULT_MIN_USERS);
        $minUsers = max(1, min(1000, $minUsers));

        $q = trim((string) $request->input('q', ''));

        $current = (int) $request->input('current', 1);
        $pageSize = (int) $request->input('pageSize', 20);
        $current = max(1, $current);
        $pageSize = max(1, min(200, $pageSize));

        $excludedIps = $this->getExcludedIpsForSummary($request);

        $builder = DB::table('v2_risk_event')
            ->where('created_at', '>=', $since)
            ->whereIn('event_type', $eventTypes)
            ->whereNotNull('ip')
            ->where('ip', '<>', '')
            ->selectRaw('ip, COUNT(*) AS event_count, COUNT(DISTINCT user_id) AS user_count, COUNT(DISTINCT token_hash) AS token_count, COUNT(DISTINCT ua_hash) AS ua_count, MAX(created_at) AS last_seen')
            ->groupBy('ip')
            ->having('user_count', '>=', $minUsers);

        if ($excludedIps) {
            $builder->whereNotIn('ip', $excludedIps);
        }

        if ($q !== '') {
            $builder->where('ip', 'like', '%' . $q . '%');
        }

        $total = DB::query()->fromSub(clone $builder, 't')->count();
        $rows = $builder
            ->orderByDesc('user_count')
            ->orderByDesc('last_seen')
            ->forPage($current, $pageSize)
            ->get();

        return response([
            'data' => $rows,
            'total' => $total,
        ]);
    }

    public function uaSummary(Request $request)
    {
        if ($resp = $this->ensureTableReady()) {
            return response(['data' => [], 'total' => 0]);
        }
        if (!$this->isRiskCenterEnabled()) {
            return response(['data' => [], 'total' => 0]);
        }

        $since = $this->getSinceTs($request);
        $eventTypes = $this->getEventTypes($request);

        $minUsers = (int) $request->input('min_users', self::DEFAULT_MIN_USERS);
        $minUsers = max(1, min(1000, $minUsers));
        $minIps = (int) $request->input('min_ips', 3);
        $minIps = max(1, min(1000, $minIps));

        $q = trim((string) $request->input('q', ''));

        $current = (int) $request->input('current', 1);
        $pageSize = (int) $request->input('pageSize', 20);
        $current = max(1, $current);
        $pageSize = max(1, min(200, $pageSize));

        $excludedIps = $this->getExcludedIpsForSummary($request);

        $builder = DB::table('v2_risk_event')
            ->where('created_at', '>=', $since)
            ->whereIn('event_type', $eventTypes)
            ->whereNotNull('ua_hash')
            ->where('ua_hash', '<>', '')
            ->whereNotNull('ip')
            ->where('ip', '<>', '')
            ->selectRaw('ua_hash, MAX(ua) as ua, COUNT(*) AS event_count, COUNT(DISTINCT user_id) AS user_count, COUNT(DISTINCT ip) AS ip_count, MAX(created_at) AS last_seen')
            ->groupBy('ua_hash')
            ->having('user_count', '>=', $minUsers)
            ->having('ip_count', '>=', $minIps);

        if ($excludedIps) {
            $builder->whereNotIn('ip', $excludedIps);
        }

        if ($q !== '') {
            if (preg_match('/^[0-9a-fA-F]{6,64}$/', $q)) {
                $builder->where('ua_hash', 'like', strtolower($q) . '%');
            } else {
                $builder->where('ua', 'like', '%' . $q . '%');
            }
        }

        $total = DB::query()->fromSub(clone $builder, 't')->count();
        $rows = $builder
            ->orderByDesc('user_count')
            ->orderByDesc('ip_count')
            ->orderByDesc('last_seen')
            ->forPage($current, $pageSize)
            ->get();

        return response([
            'data' => $rows,
            'total' => $total,
        ]);
    }

    public function ipDetail(Request $request)
    {
        if ($resp = $this->ensureTableReady()) {
            return $resp;
        }
        if (!$this->isRiskCenterEnabled()) {
            return $this->fail([403, '风控中心未启用']);
        }

        $request->validate([
            'ip' => 'required|string|max:64',
        ], [
            'ip.required' => 'IP不能为空',
        ]);

        $ip = trim((string) $request->input('ip'));
        $since = $this->getSinceTs($request);
        $eventTypes = $this->getEventTypes($request);

        $users = DB::table('v2_risk_event as e')
            ->join('v2_user as u', 'u.id', '=', 'e.user_id')
            ->where('e.created_at', '>=', $since)
            ->whereIn('e.event_type', $eventTypes)
            ->where('e.ip', '=', $ip)
            ->selectRaw('u.id as user_id, u.email, u.is_admin, u.banned, u.plan_id, COUNT(*) as event_count, COUNT(DISTINCT e.ua_hash) as ua_count, MAX(e.created_at) as last_seen')
            ->groupBy('u.id', 'u.email', 'u.is_admin', 'u.banned', 'u.plan_id')
            ->orderByDesc('event_count')
            ->orderByDesc('last_seen')
            ->get();

        $uas = DB::table('v2_risk_event as e')
            ->where('e.created_at', '>=', $since)
            ->whereIn('e.event_type', $eventTypes)
            ->where('e.ip', '=', $ip)
            ->whereNotNull('e.ua_hash')
            ->where('e.ua_hash', '<>', '')
            ->selectRaw('e.ua_hash, MAX(e.ua) as ua, COUNT(*) as event_count, MAX(e.created_at) as last_seen')
            ->groupBy('e.ua_hash')
            ->orderByDesc('event_count')
            ->orderByDesc('last_seen')
            ->limit(30)
            ->get();

        $clients = DB::table('v2_risk_event as e')
            ->where('e.created_at', '>=', $since)
            ->whereIn('e.event_type', $eventTypes)
            ->where('e.ip', '=', $ip)
            ->whereNotNull('e.client_name')
            ->where('e.client_name', '<>', '')
            ->selectRaw('e.client_name, e.client_version, COUNT(*) as event_count, MAX(e.created_at) as last_seen')
            ->groupBy('e.client_name', 'e.client_version')
            ->orderByDesc('event_count')
            ->orderByDesc('last_seen')
            ->limit(30)
            ->get();

        $tokens = DB::table('v2_risk_event as e')
            ->where('e.created_at', '>=', $since)
            ->whereIn('e.event_type', $eventTypes)
            ->where('e.ip', '=', $ip)
            ->whereNotNull('e.token_hash')
            ->where('e.token_hash', '<>', '')
            ->selectRaw('e.token_hash, COUNT(*) as event_count, COUNT(DISTINCT e.user_id) as user_count, MAX(e.created_at) as last_seen')
            ->groupBy('e.token_hash')
            ->orderByDesc('event_count')
            ->orderByDesc('last_seen')
            ->limit(60)
            ->get();

        return $this->success([
            'ip' => $ip,
            'since' => $since,
            'event_types' => $eventTypes,
            'users' => $users,
            'uas' => $uas,
            'clients' => $clients,
            'tokens' => $tokens,
        ]);
    }

    public function tokenSummary(Request $request)
    {
        if ($resp = $this->ensureTableReady()) {
            return response(['data' => [], 'total' => 0]);
        }
        if (!$this->isRiskCenterEnabled()) {
            return response(['data' => [], 'total' => 0]);
        }

        $since = $this->getSinceTs($request);

        $minIps = (int) $request->input('min_ips', 3);
        $minIps = max(1, min(1000, $minIps));

        $q = trim((string) $request->input('q', ''));

        $current = (int) $request->input('current', 1);
        $pageSize = (int) $request->input('pageSize', 20);
        $current = max(1, $current);
        $pageSize = max(1, min(200, $pageSize));

        $excludedIps = $this->getExcludedIpsForSummary($request);

        $agg = DB::table('v2_risk_event as e')
            ->where('e.created_at', '>=', $since)
            ->where('e.event_type', '=', 'subscribe')
            ->whereNotNull('e.token_hash')
            ->where('e.token_hash', '<>', '')
            ->whereNotNull('e.ip')
            ->where('e.ip', '<>', '')
            ->selectRaw('e.token_hash, MAX(e.user_id) as user_id, COUNT(*) AS event_count, COUNT(DISTINCT e.user_id) AS user_count, COUNT(DISTINCT e.ip) AS ip_count, COUNT(DISTINCT e.ua_hash) AS ua_count, MAX(e.created_at) AS last_seen')
            ->groupBy('e.token_hash')
            ->having('ip_count', '>=', $minIps);

        if ($excludedIps) {
            $agg->whereNotIn('e.ip', $excludedIps);
        }

        if ($q !== '') {
            if (preg_match('/^[0-9a-fA-F]{6,64}$/', $q)) {
                $agg->where('e.token_hash', 'like', strtolower($q) . '%');
            } elseif (is_numeric($q)) {
                $agg->where('e.user_id', '=', (int) $q);
            }
        }

        $builder = DB::query()
            ->fromSub(clone $agg, 't')
            ->leftJoin('v2_user as u', 'u.id', '=', 't.user_id')
            ->select([
                't.token_hash',
                't.user_id',
                'u.email',
                'u.is_admin',
                'u.banned',
                'u.plan_id',
                't.event_count',
                't.user_count',
                't.ip_count',
                't.ua_count',
                't.last_seen',
            ]);

        if ($q !== '' && !preg_match('/^[0-9a-fA-F]{6,64}$/', $q) && !is_numeric($q)) {
            $builder->where('u.email', 'like', '%' . $q . '%');
        }

        $total = DB::query()->fromSub(clone $builder, 'x')->count();
        $rows = $builder
            ->orderByDesc('t.ip_count')
            ->orderByDesc('t.ua_count')
            ->orderByDesc('t.last_seen')
            ->forPage($current, $pageSize)
            ->get();

        return response([
            'data' => $rows,
            'total' => $total,
        ]);
    }

    public function tokenDetail(Request $request)
    {
        if ($resp = $this->ensureTableReady()) {
            return $resp;
        }
        if (!$this->isRiskCenterEnabled()) {
            return $this->fail([403, '风控中心未启用']);
        }

        $request->validate([
            'token_hash' => 'required|string|max:64',
        ], [
            'token_hash.required' => 'Token Hash不能为空',
        ]);

        $tokenHash = strtolower(trim((string) $request->input('token_hash')));
        if (!preg_match('/^[0-9a-f]{64}$/', $tokenHash)) {
            return $this->fail([422, 'Token Hash格式不正确']);
        }

        $since = $this->getSinceTs($request);
        $excludedIps = $this->getExcludedIpsForSummary($request);

        $base = DB::table('v2_risk_event as e')
            ->where('e.created_at', '>=', $since)
            ->where('e.event_type', '=', 'subscribe')
            ->where('e.token_hash', '=', $tokenHash)
            ->whereNotNull('e.ip')
            ->where('e.ip', '<>', '');

        if ($excludedIps) {
            $base->whereNotIn('e.ip', $excludedIps);
        }

        $summary = (clone $base)
            ->selectRaw('e.token_hash, MAX(e.user_id) as user_id, COUNT(*) AS event_count, COUNT(DISTINCT e.user_id) AS user_count, COUNT(DISTINCT e.ip) AS ip_count, COUNT(DISTINCT e.ua_hash) AS ua_count, MAX(e.created_at) AS last_seen')
            ->groupBy('e.token_hash')
            ->first();

        $users = (clone $base)
            ->whereNotNull('e.user_id')
            ->join('v2_user as u', 'u.id', '=', 'e.user_id')
            ->selectRaw('u.id as user_id, u.email, u.is_admin, u.banned, u.plan_id, COUNT(*) as event_count, COUNT(DISTINCT e.ip) as ip_count, COUNT(DISTINCT e.ua_hash) as ua_count, MAX(e.created_at) as last_seen')
            ->groupBy('u.id', 'u.email', 'u.is_admin', 'u.banned', 'u.plan_id')
            ->orderByDesc('event_count')
            ->orderByDesc('last_seen')
            ->limit(200)
            ->get();

        $ips = (clone $base)
            ->selectRaw('e.ip, COUNT(*) as event_count, COUNT(DISTINCT e.user_id) as user_count, COUNT(DISTINCT e.ua_hash) as ua_count, MAX(e.created_at) as last_seen')
            ->groupBy('e.ip')
            ->orderByDesc('event_count')
            ->orderByDesc('last_seen')
            ->limit(200)
            ->get();

        $uas = (clone $base)
            ->whereNotNull('e.ua_hash')
            ->where('e.ua_hash', '<>', '')
            ->selectRaw('e.ua_hash, MAX(e.ua) as ua, COUNT(*) as event_count, COUNT(DISTINCT e.ip) as ip_count, COUNT(DISTINCT e.user_id) as user_count, MAX(e.created_at) as last_seen')
            ->groupBy('e.ua_hash')
            ->orderByDesc('event_count')
            ->orderByDesc('last_seen')
            ->limit(200)
            ->get();

        $topIps = $ips->pluck('ip')->filter(fn($v) => is_string($v) && $v !== '')->values()->all();
        $ipTokenCounts = [];
        if ($topIps) {
            $tokenCounts = DB::table('v2_risk_event')
                ->where('created_at', '>=', $since)
                ->where('event_type', '=', 'subscribe')
                ->whereIn('ip', $topIps)
                ->whereNotNull('token_hash')
                ->where('token_hash', '<>', '')
                ->selectRaw('ip, COUNT(DISTINCT token_hash) as token_count')
                ->groupBy('ip')
                ->get();
            foreach ($tokenCounts as $row) {
                $ipTokenCounts[(string) $row->ip] = (int) $row->token_count;
            }
        }

        $ipsWithTokenCount = $ips->map(function ($row) use ($ipTokenCounts) {
            $ip = (string) ($row->ip ?? '');
            $row->token_count = $ip !== '' ? ($ipTokenCounts[$ip] ?? 0) : 0;
            return $row;
        });

        return $this->success([
            'token_hash' => $tokenHash,
            'since' => $since,
            'summary' => $summary,
            'users' => $users,
            'ips' => $ipsWithTokenCount,
            'uas' => $uas,
        ]);
    }

    public function loginFailedIpSummary(Request $request)
    {
        if ($resp = $this->ensureTableReady()) {
            return response(['data' => [], 'total' => 0]);
        }
        if (!$this->isRiskCenterEnabled()) {
            return response(['data' => [], 'total' => 0]);
        }

        $since = $this->getSinceTs($request);

        $minEvents = (int) $request->input('min_events', 20);
        $minEvents = max(1, min(1000000, $minEvents));

        $q = trim((string) $request->input('q', ''));

        $current = (int) $request->input('current', 1);
        $pageSize = (int) $request->input('pageSize', 20);
        $current = max(1, $current);
        $pageSize = max(1, min(200, $pageSize));

        $excludedIps = $this->getExcludedIpsForSummary($request);

        $select = 'ip, COUNT(*) AS event_count, COUNT(DISTINCT ua_hash) AS ua_count, MAX(created_at) AS last_seen';

        $driver = null;
        try {
            $driver = DB::connection()->getDriverName();
        } catch (\Throwable) {
        }
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $select .= ", COUNT(DISTINCT (CASE WHEN meta IS NOT NULL AND JSON_VALID(meta) THEN JSON_UNQUOTE(JSON_EXTRACT(meta, '$.email')) ELSE NULL END)) AS email_count";
        } else {
            $select .= ', 0 AS email_count';
        }

        $builder = DB::table('v2_risk_event')
            ->where('created_at', '>=', $since)
            ->where('event_type', '=', 'login_failed')
            ->whereNotNull('ip')
            ->where('ip', '<>', '')
            ->selectRaw($select)
            ->groupBy('ip')
            ->having('event_count', '>=', $minEvents);

        if ($excludedIps) {
            $builder->whereNotIn('ip', $excludedIps);
        }
        if ($q !== '') {
            $builder->where('ip', 'like', '%' . $q . '%');
        }

        $total = DB::query()->fromSub(clone $builder, 't')->count();
        $rows = $builder
            ->orderByDesc('event_count')
            ->orderByDesc('last_seen')
            ->forPage($current, $pageSize)
            ->get();

        return response([
            'data' => $rows,
            'total' => $total,
        ]);
    }

    public function loginFailedIpDetail(Request $request)
    {
        if ($resp = $this->ensureTableReady()) {
            return $resp;
        }
        if (!$this->isRiskCenterEnabled()) {
            return $this->fail([403, '风控中心未启用']);
        }

        $request->validate([
            'ip' => 'required|string|max:64',
        ], [
            'ip.required' => 'IP不能为空',
        ]);

        $ip = trim((string) $request->input('ip'));
        $since = $this->getSinceTs($request);

        $events = DB::table('v2_risk_event')
            ->where('created_at', '>=', $since)
            ->where('event_type', '=', 'login_failed')
            ->where('ip', '=', $ip)
            ->orderByDesc('created_at')
            ->limit(2000)
            ->get([
                'id',
                'ip',
                'ua',
                'ua_hash',
                'status_code',
                'meta',
                'created_at',
            ]);

        $emailCounts = [];
        $uaCounts = [];
        $lastSeen = 0;

        foreach ($events as $ev) {
            $ts = (int) ($ev->created_at ?? 0);
            if ($ts > $lastSeen) {
                $lastSeen = $ts;
            }

            $email = null;
            $meta = $ev->meta ?? null;
            if (is_string($meta) && $meta !== '' && str_starts_with(ltrim($meta), '{')) {
                try {
                    $decoded = json_decode($meta, true, 512, JSON_THROW_ON_ERROR);
                    $email = is_array($decoded) && isset($decoded['email']) ? (string) $decoded['email'] : null;
                } catch (\Throwable) {
                }
            }
            if (is_string($email) && trim($email) !== '') {
                $email = strtolower(trim($email));
                $emailCounts[$email] = ($emailCounts[$email] ?? 0) + 1;
            }

            $uaHash = is_string($ev->ua_hash ?? null) ? (string) $ev->ua_hash : '';
            if ($uaHash !== '') {
                $uaCounts[$uaHash] = ($uaCounts[$uaHash] ?? 0) + 1;
            }
        }

        arsort($emailCounts);
        arsort($uaCounts);

        $topEmails = [];
        foreach (array_slice($emailCounts, 0, 50, true) as $email => $count) {
            $topEmails[] = [
                'email' => $email,
                'count' => $count,
            ];
        }

        $uas = DB::table('v2_risk_event as e')
            ->where('e.created_at', '>=', $since)
            ->where('e.event_type', '=', 'login_failed')
            ->where('e.ip', '=', $ip)
            ->whereNotNull('e.ua_hash')
            ->where('e.ua_hash', '<>', '')
            ->selectRaw('e.ua_hash, MAX(e.ua) as ua, COUNT(*) as event_count, MAX(e.created_at) as last_seen')
            ->groupBy('e.ua_hash')
            ->orderByDesc('event_count')
            ->orderByDesc('last_seen')
            ->limit(200)
            ->get();

        return $this->success([
            'ip' => $ip,
            'since' => $since,
            'summary' => [
                'event_count' => $events->count(),
                'email_count' => count($emailCounts),
                'ua_count' => count($uaCounts),
                'last_seen' => $lastSeen ?: null,
            ],
            'top_emails' => $topEmails,
            'uas' => $uas,
            'events' => $events->take(200),
        ]);
    }

    public function loginFailedUaSummary(Request $request)
    {
        if ($resp = $this->ensureTableReady()) {
            return response(['data' => [], 'total' => 0]);
        }
        if (!$this->isRiskCenterEnabled()) {
            return response(['data' => [], 'total' => 0]);
        }

        $since = $this->getSinceTs($request);

        $minEvents = (int) $request->input('min_events', 20);
        $minEvents = max(1, min(1000000, $minEvents));
        $minIps = (int) $request->input('min_ips', 3);
        $minIps = max(1, min(1000, $minIps));

        $q = trim((string) $request->input('q', ''));

        $current = (int) $request->input('current', 1);
        $pageSize = (int) $request->input('pageSize', 20);
        $current = max(1, $current);
        $pageSize = max(1, min(200, $pageSize));

        $excludedIps = $this->getExcludedIpsForSummary($request);

        $select = 'ua_hash, MAX(ua) as ua, COUNT(*) AS event_count, COUNT(DISTINCT ip) AS ip_count, MAX(created_at) AS last_seen';

        $driver = null;
        try {
            $driver = DB::connection()->getDriverName();
        } catch (\Throwable) {
        }
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $select .= ", COUNT(DISTINCT (CASE WHEN meta IS NOT NULL AND JSON_VALID(meta) THEN JSON_UNQUOTE(JSON_EXTRACT(meta, '$.email')) ELSE NULL END)) AS email_count";
        } else {
            $select .= ', 0 AS email_count';
        }

        $builder = DB::table('v2_risk_event')
            ->where('created_at', '>=', $since)
            ->where('event_type', '=', 'login_failed')
            ->whereNotNull('ua_hash')
            ->where('ua_hash', '<>', '')
            ->whereNotNull('ip')
            ->where('ip', '<>', '')
            ->selectRaw($select)
            ->groupBy('ua_hash')
            ->having('event_count', '>=', $minEvents)
            ->having('ip_count', '>=', $minIps);

        if ($excludedIps) {
            $builder->whereNotIn('ip', $excludedIps);
        }

        if ($q !== '') {
            if (preg_match('/^[0-9a-fA-F]{6,64}$/', $q)) {
                $builder->where('ua_hash', 'like', strtolower($q) . '%');
            } else {
                $builder->where('ua', 'like', '%' . $q . '%');
            }
        }

        $total = DB::query()->fromSub(clone $builder, 't')->count();
        $rows = $builder
            ->orderByDesc('event_count')
            ->orderByDesc('ip_count')
            ->orderByDesc('last_seen')
            ->forPage($current, $pageSize)
            ->get();

        return response([
            'data' => $rows,
            'total' => $total,
        ]);
    }

    public function loginFailedUaDetail(Request $request)
    {
        if ($resp = $this->ensureTableReady()) {
            return $resp;
        }
        if (!$this->isRiskCenterEnabled()) {
            return $this->fail([403, '风控中心未启用']);
        }

        $request->validate([
            'ua_hash' => 'required|string|max:64',
        ], [
            'ua_hash.required' => 'UA Hash不能为空',
        ]);

        $uaHash = strtolower(trim((string) $request->input('ua_hash')));
        if (!preg_match('/^[0-9a-f]{64}$/', $uaHash)) {
            return $this->fail([422, 'UA Hash格式不正确']);
        }

        $since = $this->getSinceTs($request);

        $events = DB::table('v2_risk_event')
            ->where('created_at', '>=', $since)
            ->where('event_type', '=', 'login_failed')
            ->where('ua_hash', '=', $uaHash)
            ->orderByDesc('created_at')
            ->limit(2000)
            ->get([
                'id',
                'ip',
                'ua',
                'ua_hash',
                'status_code',
                'meta',
                'created_at',
            ]);

        $emailCounts = [];
        $ipCounts = [];
        $lastSeen = 0;

        foreach ($events as $ev) {
            $ts = (int) ($ev->created_at ?? 0);
            if ($ts > $lastSeen) {
                $lastSeen = $ts;
            }

            $ip = is_string($ev->ip ?? null) ? (string) $ev->ip : '';
            if ($ip !== '') {
                $ipCounts[$ip] = ($ipCounts[$ip] ?? 0) + 1;
            }

            $email = null;
            $meta = $ev->meta ?? null;
            if (is_string($meta) && $meta !== '' && str_starts_with(ltrim($meta), '{')) {
                try {
                    $decoded = json_decode($meta, true, 512, JSON_THROW_ON_ERROR);
                    $email = is_array($decoded) && isset($decoded['email']) ? (string) $decoded['email'] : null;
                } catch (\Throwable) {
                }
            }
            if (is_string($email) && trim($email) !== '') {
                $email = strtolower(trim($email));
                $emailCounts[$email] = ($emailCounts[$email] ?? 0) + 1;
            }
        }

        arsort($emailCounts);
        arsort($ipCounts);

        $topEmails = [];
        foreach (array_slice($emailCounts, 0, 50, true) as $email => $count) {
            $topEmails[] = [
                'email' => $email,
                'count' => $count,
            ];
        }

        $topIps = [];
        foreach (array_slice($ipCounts, 0, 200, true) as $ip => $count) {
            $topIps[] = [
                'ip' => $ip,
                'count' => $count,
            ];
        }

        $ua = $events->first()?->ua ?? null;
        $ua = is_string($ua) ? $ua : null;

        return $this->success([
            'ua_hash' => $uaHash,
            'ua' => $ua,
            'since' => $since,
            'summary' => [
                'event_count' => $events->count(),
                'email_count' => count($emailCounts),
                'ip_count' => count($ipCounts),
                'last_seen' => $lastSeen ?: null,
            ],
            'top_emails' => $topEmails,
            'top_ips' => $topIps,
            'events' => $events->take(200),
        ]);
    }

    public function uaDetail(Request $request)
    {
        if ($resp = $this->ensureTableReady()) {
            return $resp;
        }
        if (!$this->isRiskCenterEnabled()) {
            return $this->fail([403, '风控中心未启用']);
        }

        $request->validate([
            'ua_hash' => 'required|string|max:64',
        ], [
            'ua_hash.required' => 'UA Hash不能为空',
        ]);

        $uaHash = strtolower(trim((string) $request->input('ua_hash')));
        if (!preg_match('/^[0-9a-f]{64}$/', $uaHash)) {
            return $this->fail([422, 'UA Hash格式不正确']);
        }

        $since = $this->getSinceTs($request);
        $eventTypes = $this->getEventTypes($request);

        $excludedIps = $this->getExcludedIpsForSummary($request);

        $base = DB::table('v2_risk_event as e')
            ->where('e.created_at', '>=', $since)
            ->whereIn('e.event_type', $eventTypes)
            ->where('e.ua_hash', '=', $uaHash)
            ->whereNotNull('e.ip')
            ->where('e.ip', '<>', '');

        if ($excludedIps) {
            $base->whereNotIn('e.ip', $excludedIps);
        }

        $summary = (clone $base)
            ->selectRaw('e.ua_hash, MAX(e.ua) as ua, COUNT(*) AS event_count, COUNT(DISTINCT e.user_id) AS user_count, COUNT(DISTINCT e.ip) AS ip_count, MAX(e.created_at) AS last_seen')
            ->groupBy('e.ua_hash')
            ->first();

        $users = (clone $base)
            ->join('v2_user as u', 'u.id', '=', 'e.user_id')
            ->selectRaw('u.id as user_id, u.email, u.is_admin, u.banned, u.plan_id, COUNT(*) as event_count, COUNT(DISTINCT e.ip) as ip_count, MAX(e.created_at) as last_seen')
            ->groupBy('u.id', 'u.email', 'u.is_admin', 'u.banned', 'u.plan_id')
            ->orderByDesc('event_count')
            ->orderByDesc('last_seen')
            ->limit(200)
            ->get();

        $ips = (clone $base)
            ->selectRaw('e.ip, COUNT(*) as event_count, COUNT(DISTINCT e.user_id) as user_count, MAX(e.created_at) as last_seen')
            ->groupBy('e.ip')
            ->orderByDesc('user_count')
            ->orderByDesc('last_seen')
            ->limit(200)
            ->get();

        return $this->success([
            'ua_hash' => $uaHash,
            'since' => $since,
            'event_types' => $eventTypes,
            'summary' => $summary,
            'users' => $users,
            'ips' => $ips,
        ]);
    }

    /**
     * 危险用户榜单：订阅拉取频繁但流量很少（疑似采集节点）
     */
    public function userBehaviorSummary(Request $request)
    {
        if ($resp = $this->ensureTableReady()) {
            return response(['data' => [], 'total' => 0]);
        }
        if (!$this->isRiskCenterEnabled()) {
            return response(['data' => [], 'total' => 0]);
        }

        $since = $this->getSinceTs($request);
        $excludedIps = $this->getExcludedIpsForSummary($request);

        $minSubscribe = (int) $request->input('min_subscribe', 50);
        $minSubscribe = max(1, min(1000000, $minSubscribe));

        $minActiveDays = (int) $request->input('min_active_days', 3);
        $minActiveDays = max(1, min(self::MAX_DAYS, $minActiveDays));

        $maxTrafficMbRaw = $request->input('max_traffic_mb', null);
        $maxTrafficBytes = null;
        if ($maxTrafficMbRaw !== null && !(is_string($maxTrafficMbRaw) && trim($maxTrafficMbRaw) === '')) {
            $maxTrafficMb = (int) $maxTrafficMbRaw;
            $maxTrafficMb = max(0, min(1000000, $maxTrafficMb));
            $maxTrafficBytes = $maxTrafficMb * 1024 * 1024;
        }

        $q = trim((string) $request->input('q', ''));

        $current = (int) $request->input('current', 1);
        $pageSize = (int) $request->input('pageSize', 20);
        $current = max(1, $current);
        $pageSize = max(1, min(200, $pageSize));

        $subscribeAgg = DB::table('v2_risk_event as e')
            ->where('e.created_at', '>=', $since)
            ->where('e.event_type', '=', 'subscribe')
            ->whereNotNull('e.user_id')
            ->where('e.user_id', '>', 0)
            ->whereNotNull('e.ip')
            ->where('e.ip', '<>', '')
            ->selectRaw('e.user_id, COUNT(*) as subscribe_count, COUNT(DISTINCT e.ip) as ip_count, COUNT(DISTINCT e.ua_hash) as ua_count, COUNT(DISTINCT FLOOR(e.created_at / 86400)) as active_days, MAX(e.created_at) as last_seen')
            ->groupBy('e.user_id')
            ->having('subscribe_count', '>=', $minSubscribe)
            ->having('active_days', '>=', $minActiveDays);

        if ($excludedIps) {
            $subscribeAgg->whereNotIn('e.ip', $excludedIps);
        }

        $trafficAgg = DB::table('v2_stat_user as s')
            ->where('s.record_at', '>=', $since)
            ->selectRaw('s.user_id, SUM(s.u + s.d) as traffic_total')
            ->groupBy('s.user_id');

        $builder = DB::query()
            ->fromSub($subscribeAgg, 't')
            ->join('v2_user as u', 'u.id', '=', 't.user_id')
            ->leftJoinSub($trafficAgg, 's', 's.user_id', '=', 't.user_id')
            ->selectRaw('t.user_id, u.email, u.is_admin, u.banned, u.plan_id, t.subscribe_count, t.active_days, t.ip_count, t.ua_count, t.last_seen, COALESCE(s.traffic_total, 0) as traffic_total, ROUND(t.subscribe_count / ((COALESCE(s.traffic_total, 0) / 1048576) + 1), 4) as score');

        if ($maxTrafficBytes !== null) {
            $builder->whereRaw('COALESCE(s.traffic_total, 0) <= ?', [$maxTrafficBytes]);
        }

        if ($q !== '') {
            if (is_numeric($q)) {
                $builder->where('u.id', '=', (int) $q);
            } else {
                $builder->where('u.email', 'like', '%' . $q . '%');
            }
        }

        $total = DB::query()->fromSub(clone $builder, 'x')->count();
        $rows = $builder
            ->orderByDesc('score')
            ->orderByDesc('subscribe_count')
            ->orderByDesc('active_days')
            ->orderByDesc('last_seen')
            ->forPage($current, $pageSize)
            ->get();

        return response([
            'data' => $rows,
            'total' => $total,
        ]);
    }

    public function userDetail(Request $request)
    {
        if ($resp = $this->ensureTableReady()) {
            return $resp;
        }
        if (!$this->isRiskCenterEnabled()) {
            return $this->fail([403, '风控中心未启用']);
        }

        $request->validate([
            'user_id' => 'required|integer|min:1',
        ], [
            'user_id.required' => '用户ID不能为空',
        ]);

        $userId = (int) $request->input('user_id');
        $user = User::query()
            ->select(['id', 'email', 'is_admin', 'banned', 'plan_id', 'expired_at', 'last_login_at', 'created_at'])
            ->find($userId);
        if (!$user) {
            return $this->fail([400202, '用户不存在']);
        }

        $since = $this->getSinceTs($request);
        $eventTypes = $this->getEventTypes($request);

        $ips = DB::table('v2_risk_event')
            ->where('created_at', '>=', $since)
            ->whereIn('event_type', $eventTypes)
            ->where('user_id', '=', $userId)
            ->whereNotNull('ip')
            ->where('ip', '<>', '')
            ->selectRaw('ip, COUNT(*) as event_count, COUNT(DISTINCT ua_hash) as ua_count, MAX(created_at) as last_seen')
            ->groupBy('ip')
            ->orderByDesc('last_seen')
            ->limit(200)
            ->get();

        $uas = DB::table('v2_risk_event')
            ->where('created_at', '>=', $since)
            ->whereIn('event_type', $eventTypes)
            ->where('user_id', '=', $userId)
            ->whereNotNull('ua_hash')
            ->where('ua_hash', '<>', '')
            ->selectRaw('ua_hash, MAX(ua) as ua, COUNT(*) as event_count, MAX(created_at) as last_seen')
            ->groupBy('ua_hash')
            ->orderByDesc('event_count')
            ->orderByDesc('last_seen')
            ->limit(60)
            ->get();

        $events = DB::table('v2_risk_event')
            ->where('created_at', '>=', $since)
            ->whereIn('event_type', $eventTypes)
            ->where('user_id', '=', $userId)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get([
                'id',
                'event_type',
                'ip',
                'ua',
                'client_name',
                'client_version',
                'route',
                'status_code',
                'meta',
                'created_at',
            ]);

        return $this->success([
            'user' => $user,
            'since' => $since,
            'event_types' => $eventTypes,
            'ips' => $ips,
            'uas' => $uas,
            'events' => $events,
        ]);
    }
}
