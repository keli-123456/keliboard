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
            admin_setting('risk_center_enable', false),
            false
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

        if ($updates) {
            admin_setting($updates);
        }
        Cache::forget('risk:exclude_server_ips');

        return $this->success(true);
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

    /**
     * 疑似共享/转卖：同一账号短期出现大量不同 IP（可能来自公开群分享订阅）
     */
    public function userShareSummary(Request $request)
    {
        if ($resp = $this->ensureTableReady()) {
            return response(['data' => [], 'total' => 0]);
        }
        if (!$this->isRiskCenterEnabled()) {
            return response(['data' => [], 'total' => 0]);
        }

        $since = $this->getSinceTs($request);
        $excludedIps = $this->getExcludedIpsForSummary($request);

        $minIps = (int) $request->input('min_ips', 20);
        $minIps = max(1, min(1000000, $minIps));

        $minSubscribe = (int) $request->input('min_subscribe', 20);
        $minSubscribe = max(1, min(1000000, $minSubscribe));

        $minActiveDays = (int) $request->input('min_active_days', 1);
        $minActiveDays = max(1, min(self::MAX_DAYS, $minActiveDays));

        $minTrafficMbRaw = $request->input('min_traffic_mb', null);
        $minTrafficBytes = null;
        if ($minTrafficMbRaw !== null && !(is_string($minTrafficMbRaw) && trim($minTrafficMbRaw) === '')) {
            $minTrafficMb = (int) $minTrafficMbRaw;
            $minTrafficMb = max(0, min(1000000, $minTrafficMb));
            $minTrafficBytes = $minTrafficMb * 1024 * 1024;
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
            ->having('ip_count', '>=', $minIps)
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
            ->selectRaw('t.user_id, u.email, u.is_admin, u.banned, u.plan_id, t.subscribe_count, t.active_days, t.ip_count, t.ua_count, t.last_seen, COALESCE(s.traffic_total, 0) as traffic_total, ROUND(t.ip_count * ((COALESCE(s.traffic_total, 0) / 1048576) + 1), 4) as score');

        if ($minTrafficBytes !== null) {
            $builder->whereRaw('COALESCE(s.traffic_total, 0) >= ?', [$minTrafficBytes]);
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
            ->orderByDesc('ip_count')
            ->orderByDesc('traffic_total')
            ->orderByDesc('last_seen')
            ->forPage($current, $pageSize)
            ->get();

        // Attach online ip count (best-effort, from cache)
        try {
            $userIds = collect($rows)->pluck('user_id')->filter()->values()->all();
            $cachePrefix = 'ALIVE_IP_USER_';
            $aliveData = cache()->many(array_map(fn(int $id): string => $cachePrefix . $id, $userIds));
            foreach ($rows as $row) {
                $uid = (int) ($row->user_id ?? 0);
                $cached = $aliveData[$cachePrefix . $uid] ?? null;
                $row->online_ip_count = is_array($cached) ? (int) ($cached['alive_ip'] ?? 0) : 0;
            }
        } catch (\Throwable) {
        }

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
