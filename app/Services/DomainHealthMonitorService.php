<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AgentDomain;
use App\Models\DomainHealth;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\SiteNavigationDomain;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class DomainHealthMonitorService
{
    public function __construct(
        private DomainHealthProbeService $probe,
        private ?Closure $notifier = null,
    ) {}

    /**
     * @return array{enabled: bool, failure_threshold: int, timeout_seconds: int, certificate_warning_days: int, telegram_notify: bool, interval_minutes: int}
     */
    public function settings(): array
    {
        return [
            'enabled' => (bool) admin_setting('domain_monitor_enabled', true),
            'failure_threshold' => max(1, min(10, (int) admin_setting('domain_monitor_failure_threshold', 3))),
            'timeout_seconds' => max(2, min(20, (int) admin_setting('domain_monitor_timeout_seconds', 8))),
            'certificate_warning_days' => max(1, min(60, (int) admin_setting('domain_monitor_certificate_warning_days', 14))),
            'telegram_notify' => (bool) admin_setting('domain_monitor_telegram_notify', true),
            'interval_minutes' => 5,
        ];
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public function saveSettings(array $settings): array
    {
        admin_setting([
            'domain_monitor_enabled' => !empty($settings['enabled']) ? 1 : 0,
            'domain_monitor_failure_threshold' => max(1, min(10, (int) ($settings['failure_threshold'] ?? 3))),
            'domain_monitor_timeout_seconds' => max(2, min(20, (int) ($settings['timeout_seconds'] ?? 8))),
            'domain_monitor_certificate_warning_days' => max(1, min(60, (int) ($settings['certificate_warning_days'] ?? 14))),
            'domain_monitor_telegram_notify' => !empty($settings['telegram_notify']) ? 1 : 0,
        ]);

        return $this->settings();
    }

    public function synchronizeTargets(): int
    {
        if (!Schema::hasTable('v2_domain_health')) {
            return 0;
        }

        $targets = $this->discoverTargets();
        $seen = [];
        foreach ($targets as $target) {
            $domain = $this->normalizeHost((string) ($target['domain'] ?? ''));
            if ($domain === '' || isset($seen[$domain])) {
                continue;
            }
            $seen[$domain] = true;
            DomainHealth::query()->updateOrCreate(
                ['domain' => $domain],
                [
                    'source_type' => (string) $target['source_type'],
                    'source_id' => $target['source_id'],
                    'owner_id' => $target['owner_id'],
                    'source_name' => $target['source_name'],
                    'configured_status' => $target['configured_status'],
                    'monitored' => (bool) $target['monitored'],
                ],
            );
        }

        $stale = DomainHealth::query();
        if ($seen === []) {
            $stale->update(['monitored' => false, 'configured_status' => 'removed']);
        } else {
            $stale->whereNotIn('domain', array_keys($seen))
                ->update(['monitored' => false, 'configured_status' => 'removed']);
        }

        return count($seen);
    }

    /**
     * @return array<string, int|bool>
     */
    public function scanAll(bool $notify = true, bool $force = false): array
    {
        $settings = $this->settings();
        if (!$force && !$settings['enabled']) {
            return ['disabled' => true, 'skipped' => 0, 'checked' => 0, 'healthy' => 0, 'warning' => 0, 'down' => 0];
        }

        $lock = null;
        try {
            $lock = Cache::lock('domain-health:scan', 300);
            if (!$lock->get()) {
                return ['disabled' => false, 'skipped' => 1, 'checked' => 0, 'healthy' => 0, 'warning' => 0, 'down' => 0];
            }
        } catch (Throwable) {
            $lock = null;
        }

        try {
            $this->synchronizeTargets();
            $summary = ['disabled' => false, 'skipped' => 0, 'checked' => 0, 'healthy' => 0, 'warning' => 0, 'down' => 0];
            DomainHealth::query()
                ->where('monitored', true)
                ->orderBy('id')
                ->chunkById(100, function ($domains) use (&$summary, $notify, $settings): void {
                    foreach ($domains as $domain) {
                        try {
                            $checked = $this->scanOne($domain, $notify, $settings);
                            $summary['checked']++;
                            $status = (string) $checked->status;
                            if (isset($summary[$status])) {
                                $summary[$status]++;
                            }
                        } catch (Throwable $exception) {
                            $summary['skipped']++;
                            Log::warning('Domain health scan failed', [
                                'domain_id' => (int) $domain->id,
                                'domain' => (string) $domain->domain,
                                'error' => $exception->getMessage(),
                            ]);
                        }
                    }
                });

            return $summary;
        } finally {
            if ($lock) {
                try {
                    $lock->release();
                } catch (Throwable) {
                }
            }
        }
    }

    /**
     * @param array<string, mixed>|null $settings
     */
    public function scanOne(DomainHealth $domain, bool $notify = true, ?array $settings = null): DomainHealth
    {
        $settings ??= $this->settings();
        $result = $this->probe->check(
            (string) $domain->domain,
            $settings['timeout_seconds'],
            $settings['certificate_warning_days'],
        );
        $event = null;

        $domain = DB::transaction(function () use ($domain, $result, $settings, &$event): DomainHealth {
            $current = DomainHealth::query()->lockForUpdate()->findOrFail($domain->id);
            $isDown = ($result['status'] ?? DomainHealth::STATUS_DOWN) === DomainHealth::STATUS_DOWN;
            $wasAlertActive = (bool) $current->alert_active;
            $failures = $isDown ? ((int) $current->consecutive_failures + 1) : 0;
            $now = (int) ($result['checked_at'] ?? time());
            $shouldAlert = $isDown
                && $failures >= $settings['failure_threshold']
                && !$wasAlertActive;
            $shouldRecover = !$isDown && $wasAlertActive;

            $current->fill([
                'status' => (string) ($result['status'] ?? DomainHealth::STATUS_DOWN),
                'reason' => (string) ($result['reason'] ?? 'unknown'),
                'http_status' => $result['http_status'] ?? null,
                'response_ms' => $result['response_ms'] ?? null,
                'dns_addresses' => $result['dns_addresses'] ?? [],
                'certificate_expires_at' => $result['certificate_expires_at'] ?? null,
                'certificate_issuer' => $result['certificate_issuer'] ?? null,
                'certificate_sha256' => $result['certificate_sha256'] ?? null,
                'last_error' => $result['last_error'] ?? null,
                'consecutive_failures' => $failures,
                'alert_active' => $isDown ? ($wasAlertActive || $shouldAlert) : false,
                'last_checked_at' => $now,
                'last_success_at' => $isDown ? $current->last_success_at : $now,
                'last_failure_at' => $isDown ? $now : $current->last_failure_at,
                'alerted_at' => $shouldAlert ? $now : $current->alerted_at,
                'recovered_at' => $shouldRecover ? $now : $current->recovered_at,
            ]);
            $current->save();
            $event = $shouldAlert ? 'down' : ($shouldRecover ? 'recovered' : null);

            return $current->fresh();
        });

        if ($event && $notify && $settings['telegram_notify']) {
            $this->notify($event, $domain);
        }

        return $domain;
    }

    /**
     * @return array<string, int|null>
     */
    public function summary(): array
    {
        $query = DomainHealth::query()->where('monitored', true);

        return [
            'total' => (clone $query)->count(),
            'healthy' => (clone $query)->where('status', DomainHealth::STATUS_HEALTHY)->count(),
            'warning' => (clone $query)->where('status', DomainHealth::STATUS_WARNING)->count(),
            'down' => (clone $query)->where('status', DomainHealth::STATUS_DOWN)->count(),
            'unknown' => (clone $query)->where('status', DomainHealth::STATUS_UNKNOWN)->count(),
            'alerting' => (clone $query)->where('alert_active', true)->count(),
            'last_checked_at' => (clone $query)->max('last_checked_at'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(DomainHealth $domain): array
    {
        $expiresAt = $domain->certificate_expires_at !== null ? (int) $domain->certificate_expires_at : null;

        return [
            'id' => (int) $domain->id,
            'domain' => (string) $domain->domain,
            'source_type' => (string) $domain->source_type,
            'source_id' => $domain->source_id !== null ? (int) $domain->source_id : null,
            'owner_id' => $domain->owner_id !== null ? (int) $domain->owner_id : null,
            'source_name' => (string) ($domain->source_name ?? ''),
            'configured_status' => (string) ($domain->configured_status ?? ''),
            'monitored' => (bool) $domain->monitored,
            'status' => (string) $domain->status,
            'reason' => (string) ($domain->reason ?? ''),
            'http_status' => $domain->http_status !== null ? (int) $domain->http_status : null,
            'response_ms' => $domain->response_ms !== null ? (int) $domain->response_ms : null,
            'dns_addresses' => array_values($domain->dns_addresses ?? []),
            'certificate_expires_at' => $expiresAt,
            'certificate_days_remaining' => $expiresAt ? (int) floor(($expiresAt - time()) / 86400) : null,
            'certificate_issuer' => (string) ($domain->certificate_issuer ?? ''),
            'certificate_sha256' => (string) ($domain->certificate_sha256 ?? ''),
            'last_error' => (string) ($domain->last_error ?? ''),
            'consecutive_failures' => (int) $domain->consecutive_failures,
            'alert_active' => (bool) $domain->alert_active,
            'last_checked_at' => $domain->last_checked_at !== null ? (int) $domain->last_checked_at : null,
            'last_success_at' => $domain->last_success_at !== null ? (int) $domain->last_success_at : null,
            'last_failure_at' => $domain->last_failure_at !== null ? (int) $domain->last_failure_at : null,
            'alerted_at' => $domain->alerted_at !== null ? (int) $domain->alerted_at : null,
            'recovered_at' => $domain->recovered_at !== null ? (int) $domain->recovered_at : null,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function discoverTargets(): array
    {
        $targets = [];
        if (Schema::hasTable('v2_site_domain') && Schema::hasTable('v2_site')) {
            foreach (SiteDomain::query()->with('site:id,name,status')->orderBy('id')->get() as $domain) {
                $targets[] = [
                    'domain' => $domain->domain,
                    'source_type' => DomainHealth::SOURCE_SITE,
                    'source_id' => (int) $domain->id,
                    'owner_id' => (int) $domain->site_id,
                    'source_name' => (string) ($domain->site?->name ?? ''),
                    'configured_status' => (string) $domain->status,
                    'monitored' => $domain->status === SiteDomain::STATUS_ACTIVE
                        && $domain->site?->status === Site::STATUS_ACTIVE,
                ];
            }
        }
        if (Schema::hasTable('v2_agent_domain') && Schema::hasTable('v2_user')) {
            foreach (AgentDomain::query()->with('agent:id,email')->orderBy('id')->get() as $domain) {
                $targets[] = [
                    'domain' => $domain->domain,
                    'source_type' => DomainHealth::SOURCE_AGENT,
                    'source_id' => (int) $domain->id,
                    'owner_id' => (int) $domain->agent_user_id,
                    'source_name' => (string) ($domain->agent?->email ?? ''),
                    'configured_status' => (string) $domain->status,
                    'monitored' => $domain->status === AgentDomain::STATUS_ACTIVE,
                ];
            }
        }
        if (Schema::hasTable('v2_site_navigation_domain') && Schema::hasTable('v2_site_navigation')) {
            foreach (SiteNavigationDomain::query()->with('navigation:id,title,scope_key,enabled')->orderBy('id')->get() as $domain) {
                $targets[] = [
                    'domain' => $domain->domain,
                    'source_type' => DomainHealth::SOURCE_NAVIGATION,
                    'source_id' => (int) $domain->id,
                    'owner_id' => (int) $domain->navigation_id,
                    'source_name' => (string) ($domain->navigation?->title ?: $domain->navigation?->scope_key ?: ''),
                    'configured_status' => (string) $domain->status,
                    'monitored' => $domain->status === SiteNavigationDomain::STATUS_ACTIVE
                        && (bool) $domain->navigation?->enabled,
                ];
            }
        }

        $systemTargets = [
            [(string) admin_setting('app_url', ''), (string) admin_setting('app_name', 'KeliBoard')],
            [(string) admin_setting('subscribe_url', ''), '订阅入口'],
        ];
        foreach ($systemTargets as [$url, $name]) {
            $host = $this->normalizeHost($url);
            if ($host !== '') {
                $targets[] = [
                    'domain' => $host,
                    'source_type' => DomainHealth::SOURCE_SYSTEM,
                    'source_id' => null,
                    'owner_id' => null,
                    'source_name' => $name,
                    'configured_status' => 'active',
                    'monitored' => true,
                ];
            }
        }

        return $targets;
    }

    private function notify(string $event, DomainHealth $domain): void
    {
        try {
            if ($this->notifier) {
                ($this->notifier)($event, $domain);
                return;
            }

            app(TelegramService::class)->sendMessageWithAdmin($this->notificationMessage($event, $domain));
        } catch (Throwable $exception) {
            Log::warning('Domain health notification failed', [
                'domain' => (string) $domain->domain,
                'event' => $event,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function notificationMessage(string $event, DomainHealth $domain): string
    {
        $recovered = $event === 'recovered';
        $lines = [
            $recovered ? '域名访问恢复' : '域名访问异常',
            '----',
            '域名：' . $this->escapeMarkdown((string) $domain->domain),
            '来源：' . $this->escapeMarkdown($this->sourceLabel((string) $domain->source_type) . ' ' . (string) $domain->source_name),
            '状态：' . ($recovered ? '已恢复' : $this->reasonLabel((string) $domain->reason)),
            'HTTP：' . ($domain->http_status ?: '-'),
            '响应：' . ($domain->response_ms ? $domain->response_ms . ' ms' : '-'),
        ];
        if (!$recovered) {
            $lines[] = '连续失败：' . (int) $domain->consecutive_failures . ' 次';
            $lines[] = '错误：' . $this->escapeMarkdown((string) ($domain->last_error ?: $this->reasonLabel((string) $domain->reason)));
        }
        $lines[] = '时间：' . date('Y-m-d H:i:s');

        return implode("\n", $lines);
    }

    private function sourceLabel(string $source): string
    {
        return match ($source) {
            DomainHealth::SOURCE_SITE => '站点',
            DomainHealth::SOURCE_AGENT => '代理',
            DomainHealth::SOURCE_NAVIGATION => '导航页',
            default => '主站',
        };
    }

    private function reasonLabel(string $reason): string
    {
        return match ($reason) {
            'dns_unresolved' => 'DNS 无法解析',
            'unsafe_address' => '解析到非公网地址',
            'tls_failed' => 'HTTPS 证书或连接失败',
            'http_unreachable' => '无法获得 HTTP 响应',
            'http_server_error' => '网站返回服务端错误',
            'http_client_error' => '网站拒绝访问',
            'certificate_expiring' => '证书即将到期',
            'ok' => '正常',
            default => '检测失败',
        };
    }

    private function normalizeHost(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $host = parse_url(str_contains($value, '://') ? $value : 'https://' . $value, PHP_URL_HOST);
        $host = strtolower(rtrim(trim((string) $host), '.'));
        if ($host !== '' && function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (is_string($ascii) && $ascii !== '') {
                $host = strtolower($ascii);
            }
        }

        return filter_var($host, FILTER_VALIDATE_IP) || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            ? $host
            : '';
    }

    private function escapeMarkdown(string $value): string
    {
        return str_replace(['\\', '*', '_', '[', ']', '`'], ['\\\\', '\\*', '\\_', '\\[', '\\]', '\\`'], trim($value));
    }
}
