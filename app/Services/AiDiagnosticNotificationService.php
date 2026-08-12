<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\SendEmailJob;
use App\Models\AiDiagnosticIncident;
use App\Models\AiDiagnosticIncidentLog;
use App\Models\Site;
use Illuminate\Support\Facades\Log;
use Throwable;

final class AiDiagnosticNotificationService
{
    public function dispatch(AiDiagnosticIncident $incident, string $event): array
    {
        $channels = ['panel'];
        $errors = [];
        $now = time();
        $cooldown = max(1, min(168, (int) admin_setting('ai_diagnostics_alert_cooldown_hours', 6))) * 3600;

        if (!$this->meetsSeverityThreshold((string) $incident->severity)) {
            return $this->record($incident, $event, $channels, $errors, false);
        }
        if ($incident->scope_key === 'platform' && $incident->module !== 'infrastructure') {
            return $this->record($incident, $event, $channels, $errors, false);
        }
        if ($event === 'recovered' && $incident->last_notified_at === null) {
            return $this->record($incident, $event, $channels, $errors, false);
        }
        if (
            in_array($event, ['recurrence', 'recovered'], true)
            && $incident->last_notified_at !== null
            && (int) $incident->last_notified_at + $cooldown > $now
        ) {
            return $this->record($incident, $event, $channels, $errors, false);
        }

        $message = $this->message($incident, $event);
        if ((bool) admin_setting('ai_diagnostics_notify_telegram', false)) {
            try {
                app(TelegramService::class)->sendMessageWithAdmin($message);
                $channels[] = 'telegram';
            } catch (Throwable $exception) {
                $errors['telegram'] = $exception->getMessage();
            }
        }

        $email = trim((string) admin_setting('ai_diagnostics_notification_email', ''));
        if ((bool) admin_setting('ai_diagnostics_notify_email', false) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            try {
                $context = app(NotificationSiteContextService::class)->forSite(
                    $incident->site_id !== null ? (int) $incident->site_id : null
                );
                SendEmailJob::dispatch([
                    'email' => $email,
                    'subject' => sprintf('[%s] %s', $this->eventLabel($event), $this->findingLabel((string) $incident->finding_key)),
                    'template_name' => 'notify',
                    'template_value' => app(NotificationSiteContextService::class)->templateValues($context, [
                        'content' => $message,
                    ]),
                    'dispatch_context' => app(NotificationSiteContextService::class)->dispatchContext($context),
                ]);
                $channels[] = 'email';
            } catch (Throwable $exception) {
                $errors['email'] = $exception->getMessage();
            }
        }

        return $this->record($incident, $event, array_values(array_unique($channels)), $errors, true);
    }

    private function record(AiDiagnosticIncident $incident, string $event, array $channels, array $errors, bool $notified): array
    {
        if ($notified) {
            $incident->forceFill([
                'last_notified_at' => time(),
                'last_notification_channels' => $channels,
                'last_notification_error' => $errors !== [] ? json_encode($errors, JSON_UNESCAPED_UNICODE) : null,
            ])->save();
        }

        try {
            AiDiagnosticIncidentLog::query()->create([
                'incident_id' => (int) $incident->id,
                'action' => $notified ? 'notification_sent' : 'notification_suppressed',
                'metadata' => [
                    'event' => $event,
                    'channels' => $channels,
                    'errors' => $errors,
                ],
            ]);
        } catch (Throwable $exception) {
            Log::warning('AI diagnostic notification log failed', ['error' => $exception->getMessage()]);
        }

        return ['notified' => $notified, 'channels' => $channels, 'errors' => $errors];
    }

    private function meetsSeverityThreshold(string $severity): bool
    {
        $minimum = (string) admin_setting('ai_diagnostics_minimum_alert_severity', 'critical');
        if ($minimum === 'warning') {
            return in_array($severity, ['warning', 'critical'], true);
        }

        return $severity === 'critical';
    }

    private function message(AiDiagnosticIncident $incident, string $event): string
    {
        $scope = '全部非代理业务';
        if ($incident->scope_key !== 'platform') {
            $site = $incident->site_id !== null ? Site::query()->find((int) $incident->site_id) : null;
            $scope = $incident->site_id === null
                ? '主站'
                : (string) ($site?->name ?: $site?->code ?: ('分站 #' . (int) $incident->site_id));
        }

        return implode("\n", [
            'AI 诊断' . $this->eventLabel($event),
            '----',
            '范围：' . $scope,
            '异常：' . $this->findingLabel((string) $incident->finding_key),
            '级别：' . ((string) $incident->severity === 'critical' ? '严重' : '需关注'),
            '发生：' . (int) $incident->occurrence_count . ' 次',
            '复发：' . (int) $incident->recurrence_count . ' 次',
            '最后发现：' . date('Y-m-d H:i:s', (int) $incident->last_seen_at),
            '说明：仅生成告警和证据，不会自动修改业务数据。',
        ]);
    }

    private function eventLabel(string $event): string
    {
        return match ($event) {
            'escalated' => '升级',
            'recurrence' => '复发',
            'recovered' => '恢复',
            default => '新异常',
        };
    }

    private function findingLabel(string $key): string
    {
        return match ($key) {
            'business_income_drop' => '收入明显下降',
            'business_registration_drop' => '注册量明显下降',
            'business_traffic_drop' => '用户流量明显下降',
            'business_ticket_surge' => '工单数量激增',
            'payment_success_low' => '支付成功率偏低',
            'payment_pending_surge' => '待支付订单堆积',
            'referral_volume_surge' => '邀请人数异常增长',
            'referral_concentration' => '邀请集中于单一用户',
            'referral_low_conversion' => '邀请转化率异常偏低',
            'referral_commission_exposure' => '待确认佣金敞口偏高',
            'infrastructure_nodes_offline' => '节点离线',
            'infrastructure_domain_unhealthy' => '域名健康异常',
            'infrastructure_failed_tasks' => '后台任务失败',
            default => $key,
        };
    }
}

