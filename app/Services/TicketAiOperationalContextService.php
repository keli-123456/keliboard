<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AiDiagnosticIncident;
use App\Models\DomainHealth;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

final class TicketAiOperationalContextService
{
    private const MACHINE_ONLINE_WINDOW_SECONDS = 300;
    private const PROBE_FRESH_SECONDS = 180;
    private const INCIDENT_FRESH_SECONDS = 7200;

    /**
     * Build a privacy-safe snapshot from backend-owned, read-only data sources.
     * No domain, IP, credential, token, UUID, node name, or machine name leaves
     * this service.
     *
     * @param array<string, mixed> $scope
     * @param array<int, array<string, mixed>> $conversation
     * @return array<string, mixed>
     */
    public function build(?User $user, array $scope, array $conversation, string $subject = ''): array
    {
        $checkedAt = time();
        $message = $this->supportQuestion($conversation, $subject);
        $intents = $this->detectIntents($message);
        if ($intents === []) {
            return [
                'mode' => 'backend_verified_read_only',
                'checked_at' => $checkedAt,
                'intents' => [],
                'status' => 'not_requested',
                'requires_human' => false,
                'customer_safe_summary' => '',
                'tools' => [],
                'active_incidents' => [],
            ];
        }

        $tools = [];
        if (in_array('subscription_delivery', $intents, true)) {
            $tools['subscription_proxy'] = $this->subscriptionProxyStatus($checkedAt);
        }
        if (in_array('node_connectivity', $intents, true)) {
            $tools['eligible_nodes'] = $this->eligibleNodeStatus($user);
        }
        if (in_array('domain_access', $intents, true)) {
            $tools['tenant_domain'] = $this->tenantDomainStatus((string) ($scope['domain'] ?? ''));
        }

        $incidents = $this->activeIncidents($scope, $intents, $checkedAt);
        [$status, $requiresHuman, $summary] = $this->summarize($tools, $incidents);

        return [
            'mode' => 'backend_verified_read_only',
            'checked_at' => $checkedAt,
            'intents' => $intents,
            'status' => $status,
            'requires_human' => $requiresHuman,
            'customer_safe_summary' => $summary,
            'tools' => $tools,
            'active_incidents' => $incidents,
        ];
    }

    /** @param array<int, array<string, mixed>> $conversation */
    private function supportQuestion(array $conversation, string $subject): string
    {
        $parts = [mb_strtolower(trim($subject))];
        foreach ($conversation as $message) {
            if (!is_array($message) || ($message['role'] ?? null) !== 'user') {
                continue;
            }
            $parts[] = mb_strtolower(trim((string) ($message['content'] ?? '')));
        }

        return trim(implode("\n", array_filter($parts)));
    }

    /** @return array<int, string> */
    private function detectIntents(string $message): array
    {
        $intents = [];
        if ($this->matches($message, [
            '订阅', '导入', '更新配置', '配置链接', 'no server available', 'failed to fetch',
            'network error', 'unexpected eof', 'eof',
        ])) {
            $intents[] = 'subscription_delivery';
        }
        if ($this->matches($message, [
            '节点', '连接不上', '无法连接', '不能连接', '不能用', '无法使用', '延迟', '测速',
            'vless', 'vmess', 'trojan', 'hysteria', 'hy2', 'tuic', 'anytls', 'naive',
            'shadowrocket', '小火箭', 'karing', 'clash', 'sing-box',
        ])) {
            $intents[] = 'node_connectivity';
        }
        if ($this->matches($message, [
            '网站打不开', '网站也打不开', '打不开网站', '网页打不开', '网页也打不开', '打不开网页',
            '域名打不开', '访问不了', '访问失败', '证书错误',
            'ssl', 'http 404', 'http 502', 'http 521', 'http 522', 'error 521', 'error 522',
        ])) {
            $intents[] = 'domain_access';
        }
        if ($this->matches($message, [
            '支付失败', '付款失败', '支付超时', '访问被禁止', '当面付', '订单未到账',
        ])) {
            $intents[] = 'payment_health';
        }

        return array_values(array_unique($intents));
    }

    /** @param array<int, string> $needles */
    private function matches(string $message, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function subscriptionProxyStatus(int $now): array
    {
        $enabled = (bool) admin_setting('subscription_proxy_enable', false);
        if (!$this->hasTable('v2_server_machine')) {
            return ['available' => false, 'enabled' => $enabled, 'status' => 'unknown'];
        }
        if (!$enabled) {
            return ['available' => true, 'enabled' => false, 'status' => 'disabled'];
        }

        try {
            $machines = ServerMachine::query()
                ->where('is_active', true)
                ->where('subproxy_enabled', true)
                ->get(['id', 'last_seen_at', 'load_status', 'subproxy_cert_state']);
            $healthy = 0;
            $lastCheckedAt = null;
            $lastSuccessAt = null;
            foreach ($machines as $machine) {
                $probe = (array) data_get($machine->subproxy_cert_state, 'probe', []);
                $checkedAt = (int) ($probe['last_checked_at'] ?? 0);
                $successAt = (int) ($probe['last_success_at'] ?? 0);
                $lastCheckedAt = max((int) ($lastCheckedAt ?? 0), $checkedAt) ?: null;
                $lastSuccessAt = max((int) ($lastSuccessAt ?? 0), $successAt) ?: null;
                $runtime = data_get($machine->load_status, 'agent.subscription_proxy');
                $runtimeHealthy = (int) ($machine->last_seen_at ?? 0) >= $now - self::MACHINE_ONLINE_WINDOW_SECONDS
                    && is_array($runtime)
                    && (bool) ($runtime['running'] ?? false)
                    && strtolower(trim((string) ($runtime['mode'] ?? ''))) === 'https';
                $probeHealthy = ($probe['status'] ?? null) === 'ok'
                    && $checkedAt >= $now - self::PROBE_FRESH_SECONDS
                    && $successAt >= $now - self::PROBE_FRESH_SECONDS;
                if ($runtimeHealthy && $probeHealthy) {
                    $healthy++;
                }
            }

            $configured = $machines->count();
            return [
                'available' => true,
                'enabled' => true,
                'status' => $configured === 0 ? 'unconfigured' : ($healthy > 0 ? 'healthy' : 'unavailable'),
                'configured_count' => $configured,
                'healthy_count' => $healthy,
                'last_checked_at' => $lastCheckedAt,
                'last_success_at' => $lastSuccessAt,
            ];
        } catch (\Throwable) {
            return ['available' => false, 'enabled' => true, 'status' => 'unknown'];
        }
    }

    /** @return array<string, mixed> */
    private function eligibleNodeStatus(?User $user): array
    {
        $groupId = $user?->group_id;
        if (!$this->hasTable('v2_server') || !is_numeric($groupId) || (int) $groupId <= 0) {
            return ['available' => false, 'status' => 'unknown'];
        }

        try {
            $servers = Server::query()
                ->where('enabled', true)
                ->where('show', true)
                ->get(['id', 'type', 'parent_id', 'group_ids', 'enabled', 'show']);
            $eligible = $servers->filter(function (Server $server) use ($groupId): bool {
                $groups = array_map('intval', (array) $server->group_ids);
                return in_array((int) $groupId, $groups, true);
            });
            $online = 0;
            $stale = 0;
            $offline = 0;
            foreach ($eligible as $server) {
                $status = (int) $server->available_status;
                if ($status === Server::STATUS_ONLINE) {
                    $online++;
                } elseif ($status === Server::STATUS_ONLINE_NO_PUSH) {
                    $stale++;
                } else {
                    $offline++;
                }
            }
            $total = $eligible->count();
            $status = match (true) {
                $total === 0 => 'unconfigured',
                $online === 0 => 'unavailable',
                $stale > 0 || $offline > 0 => 'degraded',
                default => 'healthy',
            };

            return [
                'available' => true,
                'status' => $status,
                'total_count' => $total,
                'online_count' => $online,
                'stale_count' => $stale,
                'offline_count' => $offline,
            ];
        } catch (\Throwable) {
            return ['available' => false, 'status' => 'unknown'];
        }
    }

    /** @return array<string, mixed> */
    private function tenantDomainStatus(string $domain): array
    {
        $domain = strtolower(trim($domain));
        if ($domain === '' || !$this->hasTable('v2_domain_health')) {
            return ['available' => false, 'status' => 'unknown'];
        }

        try {
            $health = DomainHealth::query()->where('domain', $domain)->first();
            if (!$health) {
                return ['available' => false, 'status' => 'unknown'];
            }

            return [
                'available' => true,
                'monitored' => (bool) $health->monitored,
                'status' => (string) $health->status,
                'reason' => $this->safeDomainReason((string) ($health->reason ?? '')),
                'http_status' => $health->http_status !== null ? (int) $health->http_status : null,
                'last_checked_at' => $health->last_checked_at !== null ? (int) $health->last_checked_at : null,
                'last_success_at' => $health->last_success_at !== null ? (int) $health->last_success_at : null,
            ];
        } catch (\Throwable) {
            return ['available' => false, 'status' => 'unknown'];
        }
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<int, string> $intents
     * @return array<int, array<string, mixed>>
     */
    private function activeIncidents(array $scope, array $intents, int $now): array
    {
        if (!$this->hasTable('v2_ai_diagnostic_incident')) {
            return [];
        }

        $modules = [];
        if (array_intersect($intents, ['subscription_delivery', 'node_connectivity', 'domain_access']) !== []) {
            $modules[] = 'infrastructure';
        }
        if (in_array('payment_health', $intents, true)) {
            $modules[] = 'payment';
        }
        if ($modules === []) {
            return [];
        }

        try {
            $scopeType = (string) ($scope['type'] ?? 'platform');
            $siteId = isset($scope['site_id']) && is_numeric($scope['site_id']) ? (int) $scope['site_id'] : null;
            $siteScopeKey = $scopeType === 'site' && $siteId !== null ? 'site:' . $siteId : 'site:0';
            $query = AiDiagnosticIncident::query()
                ->whereIn('status', AiDiagnosticIncident::ACTIVE_STATUSES)
                ->where('last_seen_at', '>=', $now - self::INCIDENT_FRESH_SECONDS)
                ->whereIn('module', $modules);

            if ($scopeType === 'agent') {
                $query->where('scope_key', 'platform')->where('module', 'infrastructure');
            } else {
                $query->where(function (Builder $query) use ($siteScopeKey): void {
                    $query->where('scope_key', $siteScopeKey)
                        ->orWhere(function (Builder $query): void {
                            $query->where('scope_key', 'platform')->where('module', 'infrastructure');
                        });
                });
            }

            return $query->orderByRaw("CASE WHEN severity = 'critical' THEN 0 ELSE 1 END")
                ->orderByDesc('last_seen_at')
                ->limit(5)
                ->get(['finding_key', 'module', 'severity', 'status', 'last_seen_at'])
                ->map(fn (AiDiagnosticIncident $incident): array => [
                    'type' => (string) $incident->finding_key,
                    'label' => $this->incidentLabel((string) $incident->finding_key),
                    'module' => (string) $incident->module,
                    'severity' => (string) $incident->severity,
                    'status' => (string) $incident->status,
                    'last_seen_at' => (int) $incident->last_seen_at,
                ])->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param array<string, array<string, mixed>> $tools
     * @param array<int, array<string, mixed>> $incidents
     * @return array{0:string,1:bool,2:string}
     */
    private function summarize(array $tools, array $incidents): array
    {
        $proxy = (array) ($tools['subscription_proxy'] ?? []);
        if (($proxy['enabled'] ?? false) && ($proxy['status'] ?? '') === 'unavailable') {
            return ['unavailable', true, '订阅加速通道当前未通过健康检查，需要人工排查；请勿让用户反复重置订阅凭证。'];
        }

        $nodes = (array) ($tools['eligible_nodes'] ?? []);
        if (($nodes['available'] ?? false) && in_array(($nodes['status'] ?? ''), ['unavailable', 'unconfigured'], true)) {
            return ['unavailable', true, '后端当前未检测到该账号可用的在线节点，需要人工核查账号权限组和节点状态。'];
        }

        $domain = (array) ($tools['tenant_domain'] ?? []);
        if (($domain['available'] ?? false) && ($domain['status'] ?? '') === DomainHealth::STATUS_DOWN) {
            return ['unavailable', true, '当前访问域名未通过可用性检测，需要人工排查域名、反代或证书状态。'];
        }

        $critical = array_values(array_filter(
            $incidents,
            static fn (array $incident): bool => ($incident['severity'] ?? '') === 'critical'
        ));
        if ($critical !== []) {
            return ['degraded', true, '后台诊断检测到仍在处理的服务异常，需要人工跟进；不要把问题直接归因于用户客户端。'];
        }

        $statuses = array_values(array_filter(array_map(
            static fn (array $tool): string => (string) ($tool['status'] ?? ''),
            $tools
        )));
        if (in_array('degraded', $statuses, true) || $incidents !== []) {
            return ['degraded', false, '当前部分服务指标存在异常，但仍有可用通道；答复时应说明已核验的状态和下一步。'];
        }
        if ($statuses !== [] && count(array_diff($statuses, ['healthy', 'disabled'])) === 0) {
            return ['healthy', false, '当前后端健康记录未发现持续性服务异常；这只能证明当前状态，不能否定用户此前遇到的问题。'];
        }

        return ['unknown', false, '当前运行状态证据不足，不能据此判断是客户端还是服务端问题。'];
    }

    private function safeDomainReason(string $reason): string
    {
        return match ($reason) {
            'dns_failed' => 'dns_failed',
            'connect_failed' => 'connect_failed',
            'tls_failed' => 'tls_failed',
            'http_error' => 'http_error',
            'timeout' => 'timeout',
            default => '',
        };
    }

    private function incidentLabel(string $key): string
    {
        return match ($key) {
            'infrastructure_nodes_offline' => '节点离线',
            'infrastructure_domain_unhealthy' => '域名健康异常',
            'infrastructure_failed_tasks' => '后台任务失败',
            'payment_success_low' => '支付成功率异常',
            'payment_pending_surge' => '待支付订单异常增加',
            default => '运行状态异常',
        };
    }

    private function hasTable(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (\Throwable) {
            return false;
        }
    }
}
