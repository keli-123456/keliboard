<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
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

    private function ensureTableReady()
    {
        if (Schema::hasTable('v2_risk_event')) {
            return null;
        }
        return $this->fail([500000, '风控事件表不存在，请先执行数据库迁移（php artisan migrate）']);
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

        return $this->success([
            'risk_center_enable' => $enabled,
            'risk_exclude_ips' => (string) admin_setting('risk_exclude_ips', ''),
            'risk_exclude_node_ips' => $excludeNodeIps,
            'trusted_proxies' => is_string($trustedProxies) ? $trustedProxies : '',
            'proxy_trust_secret_set' => $proxySecretSet,
            'proxy_trust_secret_header' => $proxySecretHeader,
            'proxy_trust_secret_from_server_token' => $proxySecretFromServerToken,
            'server_token_set' => $serverTokenSet,
        ]);
    }

    public function saveConfig(Request $request)
    {
        $data = $request->validate([
            'risk_center_enable' => 'nullable|boolean',
            'risk_exclude_ips' => 'nullable|string|max:4096',
            'risk_exclude_node_ips' => 'nullable|boolean',
            'trusted_proxies' => 'nullable|string|max:2048',
            'proxy_trust_secret' => 'nullable|string|max:256',
            'proxy_trust_secret_header' => 'nullable|string|max:64',
            'proxy_trust_secret_from_server_token' => 'nullable|boolean',
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

        if ($updates) {
            admin_setting($updates);
        }
        Cache::forget('risk:exclude_server_ips');

        return $this->success(true);
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
            ->selectRaw('ip, COUNT(*) AS event_count, COUNT(DISTINCT user_id) AS user_count, COUNT(DISTINCT ua_hash) AS ua_count, MAX(created_at) AS last_seen')
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

        return $this->success([
            'ip' => $ip,
            'since' => $since,
            'event_types' => $eventTypes,
            'users' => $users,
            'uas' => $uas,
            'clients' => $clients,
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
