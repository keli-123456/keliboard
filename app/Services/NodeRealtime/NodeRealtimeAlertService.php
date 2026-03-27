<?php

namespace App\Services\NodeRealtime;

use App\Services\TelegramService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class NodeRealtimeAlertService
{
    private const DEFAULT_WINDOW_MINUTES = 10;
    private const DEFAULT_COOLDOWN_MINUTES = 30;
    private const MAX_MESSAGE_NODES = 10;

    private NodeRealtimeStatusService $statusService;

    public function __construct(NodeRealtimeStatusService $statusService)
    {
        $this->statusService = $statusService;
    }

    public function settings(): array
    {
        return [
            'enable' => $this->toBool(admin_setting('node_realtime_alert_enable', false), false),
            'notify_telegram' => $this->toBool(admin_setting('node_realtime_alert_notify_telegram', false), false),
            'window_minutes' => $this->toInt(admin_setting('node_realtime_alert_window_minutes', self::DEFAULT_WINDOW_MINUTES), self::DEFAULT_WINDOW_MINUTES, 5, 120),
            'cooldown_minutes' => $this->toInt(admin_setting('node_realtime_alert_cooldown_minutes', self::DEFAULT_COOLDOWN_MINUTES), self::DEFAULT_COOLDOWN_MINUTES, 1, 1440),
        ];
    }

    public function evaluate(): array
    {
        $settings = $this->settings();
        $status = $this->statusService->getStatus();

        if (!$settings['enable'] || !$status['enabled']) {
            return [
                'settings' => $settings,
                'status' => $status,
                'alerts' => [],
            ];
        }

        $alerts = [];

        if (!$status['running']) {
            $alerts[] = [
                'key' => 'ws_server_stopped',
                'severity' => 'error',
                'title' => 'ws-server 未运行',
                'message' => sprintf(
                    "实时同步已启用，但 ws-server 当前没有在 %s 正常监听。",
                    $status['listen'] ?: '-'
                ),
            ];
        }

        if (blank($status['public_url'] ?? '')) {
            $alerts[] = [
                'key' => 'public_url_missing',
                'severity' => 'warning',
                'title' => '节点公网连接地址未配置',
                'message' => '当前无法生成可用的 realtime 公网地址，请检查 app_url、实时同步路径和公网端口配置。',
            ];
        }

        $missingNodes = array_values((array) ($status['missing_nodes'] ?? []));
        if ($missingNodes !== []) {
            $nodeIds = array_values(array_unique(array_map(
                fn (array $row) => (int) ($row['cache_server_id'] ?? $row['server_id'] ?? 0),
                $missingNodes
            )));
            sort($nodeIds);

            $topNodes = array_slice($missingNodes, 0, self::MAX_MESSAGE_NODES);
            $nodeLines = array_map(function (array $row): string {
                $nodeId = (string) ($row['node_id'] ?? '-');
                $name = trim((string) ($row['name'] ?? ''));
                $serverId = (int) ($row['server_id'] ?? 0);
                $lastCheckAt = trim((string) ($row['last_check_at'] ?? ''));

                return sprintf(
                    "- [%s] %s (server=%d%s%s)",
                    $nodeId !== '' ? $nodeId : '-',
                    $name !== '' ? $name : '-',
                    $serverId,
                    $lastCheckAt !== '' ? ', last_check_at=' : '',
                    $lastCheckAt !== '' ? $lastCheckAt : ''
                );
            }, $topNodes);

            $extraCount = max(0, count($missingNodes) - count($topNodes));
            if ($extraCount > 0) {
                $nodeLines[] = sprintf('- 还有 %d 个节点未展示', $extraCount);
            }

            $alerts[] = [
                'key' => 'missing_nodes:' . md5(json_encode($nodeIds)),
                'severity' => $status['running'] ? 'warning' : 'error',
                'title' => sprintf('%d 个活跃节点未完成 realtime 认证', count($missingNodes)),
                'message' => sprintf(
                    "这些节点最近 %d 分钟内仍有面板活动，但没有出现在已认证 realtime 连接里：\n%s",
                    max(1, (int) round(((int) ($status['recent_active_window_seconds'] ?? 0)) / 60)),
                    implode("\n", $nodeLines)
                ),
            ];
        }

        return [
            'settings' => $settings,
            'status' => $status,
            'alerts' => $alerts,
        ];
    }

    public function dispatch(bool $force = false, bool $notifyOverride = false): array
    {
        $evaluation = $this->evaluate();
        $settings = $evaluation['settings'];
        $alerts = (array) ($evaluation['alerts'] ?? []);

        if ($alerts === []) {
            return [
                ...$evaluation,
                'pending_alerts' => [],
                'notified' => false,
                'notify_error' => null,
            ];
        }

        $pendingAlerts = [];
        foreach ($alerts as $alert) {
            $cacheKey = $this->cooldownCacheKey((string) ($alert['key'] ?? ''));
            $cooldownHit = !$force && Cache::has($cacheKey);
            $alert['cooldown_hit'] = $cooldownHit;
            $pendingAlerts[] = $alert;
        }

        $alertsToNotify = array_values(array_filter($pendingAlerts, fn (array $alert) => !($alert['cooldown_hit'] ?? false)));
        if ($alertsToNotify === []) {
            return [
                ...$evaluation,
                'pending_alerts' => $pendingAlerts,
                'notified' => false,
                'notify_error' => null,
            ];
        }

        $notifyEnabled = $notifyOverride || (bool) $settings['notify_telegram'];
        $notifyError = null;
        $notified = false;

        foreach ($alertsToNotify as $alert) {
            Log::warning('Node realtime alert triggered', [
                'key' => $alert['key'] ?? null,
                'severity' => $alert['severity'] ?? null,
                'title' => $alert['title'] ?? null,
            ]);
        }

        if ($notifyEnabled) {
            $telegramBotToken = trim((string) admin_setting('telegram_bot_token', ''));
            if ($telegramBotToken === '') {
                $notifyError = 'telegram_not_configured';
            } else {
                try {
                    app(TelegramService::class)->sendMessageWithAdmin($this->buildTelegramMessage($alertsToNotify));
                    $notified = true;
                } catch (\Throwable $e) {
                    $notifyError = 'telegram_send_failed';
                    Log::warning('Node realtime alert telegram send failed', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $cooldownSeconds = max(60, ((int) $settings['cooldown_minutes']) * 60);
        foreach ($alertsToNotify as $alert) {
            Cache::put($this->cooldownCacheKey((string) ($alert['key'] ?? '')), time(), $cooldownSeconds);
        }

        return [
            ...$evaluation,
            'pending_alerts' => $pendingAlerts,
            'notified' => $notified,
            'notify_error' => $notifyError,
        ];
    }

    private function cooldownCacheKey(string $key): string
    {
        return 'node_realtime:alert:cooldown:' . $key;
    }

    private function buildTelegramMessage(array $alerts): string
    {
        $lines = [
            '实时同步告警',
            '----',
        ];

        foreach ($alerts as $index => $alert) {
            if ($index > 0) {
                $lines[] = '----';
            }
            $lines[] = (string) ($alert['title'] ?? '未知告警');
            $lines[] = (string) ($alert['message'] ?? '');
        }

        return implode("\n", array_filter($lines, fn ($line) => $line !== ''));
    }

    private function toBool(mixed $value, bool $default = false): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value !== 0;
        }

        $normalized = strtolower(trim((string) $value));
        if ($normalized === '') {
            return $default;
        }

        return in_array($normalized, ['1', 'true', 'on', 'yes'], true);
    }

    private function toInt(mixed $value, int $default, int $min, int $max): int
    {
        if (!is_numeric($value)) {
            return $default;
        }

        $normalized = (int) $value;
        if ($normalized < $min) {
            return $min;
        }
        if ($normalized > $max) {
            return $max;
        }

        return $normalized;
    }
}
