<?php

namespace App\Services;

use App\Jobs\SendEmailJob;
use App\Models\MarketingRule;
use App\Models\MailLog;
use App\Models\MessageDispatchLog;
use App\Models\User;
use App\Utils\CacheKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class MailService
{
    /**
     * 获取需要发送提醒的用户总数
     */
    public function getTotalUsersNeedRemind(): int
    {
        return User::where(function ($query) {
            $query->where('remind_expire', true)
                ->orWhere('remind_traffic', true);
        })
            ->where('banned', false)
            ->whereNotNull('email')
            ->count();
    }

    /**
     * 分块处理用户提醒邮件
     */
    public function processUsersInChunks(int $chunkSize, ?callable $progressCallback = null): array
    {
        $statistics = [
            'processed_users' => 0,
            'expire_emails' => 0,
            'traffic_emails' => 0,
            'errors' => 0,
            'skipped' => 0,
        ];

        User::select('id', 'site_id', 'email', 'expired_at', 'transfer_enable', 'u', 'd', 'remind_expire', 'remind_traffic')
            ->where(function ($query) {
                $query->where('remind_expire', true)
                    ->orWhere('remind_traffic', true);
            })
            ->where('banned', false)
            ->whereNotNull('email')
            ->chunk($chunkSize, function ($users) use (&$statistics, $progressCallback) {
                $this->processUserChunk($users, $statistics);

                if ($progressCallback) {
                    $progressCallback();
                }

                // 定期清理内存
                if ($statistics['processed_users'] % 2500 === 0) {
                    gc_collect_cycles();
                }
            });

        return $statistics;
    }

    /**
     * 处理用户块
     */
    private function processUserChunk($users, array &$statistics): void
    {
        foreach ($users as $user) {
            try {
                $statistics['processed_users']++;
                $emailsSent = 0;

                // 检查并发送过期提醒
                if ($user->remind_expire && $this->shouldSendExpireRemind($user)) {
                    $this->remindExpire($user);
                    $statistics['expire_emails']++;
                    $emailsSent++;
                }

                // 检查并发送流量提醒
                if ($user->remind_traffic && $this->shouldSendTrafficRemind($user)) {
                    $this->remindTraffic($user);
                    $statistics['traffic_emails']++;
                    $emailsSent++;
                }

                if ($emailsSent === 0) {
                    $statistics['skipped']++;
                }

            } catch (\Exception $e) {
                $statistics['errors']++;

                Log::error('发送提醒邮件失败', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * 检查是否应该发送过期提醒
     */
    private function shouldSendExpireRemind(User $user): bool
    {
        if ($user->expired_at === NULL) {
            return false;
        }
        $expiredAt = $user->expired_at;
        $now = time();
        if (($expiredAt - 86400) < $now && $expiredAt > $now) {
            return true;
        }
        return false;
    }

    /**
     * 检查是否应该发送流量提醒
     */
    private function shouldSendTrafficRemind(User $user): bool
    {
        if ($user->transfer_enable <= 0) {
            return false;
        }

        $usedBytes = $user->u + $user->d;
        $usageRatio = $usedBytes / $user->transfer_enable;

        // 流量使用超过80%时发送提醒
        return $usageRatio >= 0.8;
    }

    public function remindTraffic(User $user)
    {
        if (!$user->remind_traffic)
            return;
        if (!$this->remindTrafficIsWarnValue($user->u, $user->d, $user->transfer_enable))
            return;
        $flag = CacheKey::get('LAST_SEND_EMAIL_REMIND_TRAFFIC', $user->id);
        if (Cache::get($flag))
            return;
        if (!Cache::put($flag, 1, 24 * 3600))
            return;

        $notificationContext = app(NotificationSiteContextService::class)->forUser($user);

        SendEmailJob::dispatch([
            'email' => $user->email,
            'subject' => __('The traffic usage in :app_name has reached 80%', [
                'app_name' => $notificationContext['app_name']
            ]),
            'message_type' => MarketingRule::TYPE_LIFECYCLE,
            'template_name' => 'remindTraffic',
            'template_value' => app(NotificationSiteContextService::class)->templateValues($notificationContext),
            'dispatch_context' => app(NotificationSiteContextService::class)->dispatchContext($notificationContext),
        ]);
    }

    public function remindExpire(User $user)
    {
        if (!$this->shouldSendExpireRemind($user)) {
            return;
        }

        $notificationContext = app(NotificationSiteContextService::class)->forUser($user);

        SendEmailJob::dispatch([
            'email' => $user->email,
            'subject' => __('The service in :app_name is about to expire', [
                'app_name' => $notificationContext['app_name']
            ]),
            'message_type' => MarketingRule::TYPE_LIFECYCLE,
            'template_name' => 'remindExpire',
            'template_value' => app(NotificationSiteContextService::class)->templateValues($notificationContext),
            'dispatch_context' => app(NotificationSiteContextService::class)->dispatchContext($notificationContext),
        ]);
    }

    private function remindTrafficIsWarnValue($u, $d, $transfer_enable)
    {
        $ud = $u + $d;
        if (!$ud)
            return false;
        if (!$transfer_enable)
            return false;
        $percentage = ($ud / $transfer_enable) * 100;
        if ($percentage < 80)
            return false;
        if ($percentage >= 100)
            return false;
        return true;
    }

    /**
     * 发送邮件
     *
     * @param array $params 包含邮件参数的数组，必须包含以下字段：
     *   - email: 收件人邮箱地址
     *   - subject: 邮件主题
     *   - template_name: 邮件模板名称，例如 "welcome" 或 "password_reset"
     *   - template_value: 邮件模板变量，一个关联数组，包含模板中需要替换的变量和对应的值
     * @return array 包含邮件发送结果的数组，包含以下字段：
     *   - email: 收件人邮箱地址
     *   - subject: 邮件主题
     *   - template_name: 邮件模板名称
     *   - error: 如果邮件发送失败，包含错误信息；否则为 null
     * @throws \InvalidArgumentException 如果 $params 参数缺少必要的字段，抛出此异常
     */
    public static function sendEmail(array $params, array $meta = []): array
    {
        /** @var MessageDispatchService $dispatchService */
        $dispatchService = app(MessageDispatchService::class);
        $messageType = (string) ($params['message_type'] ?? $meta['message_type'] ?? MarketingRule::TYPE_TRANSACTIONAL);
        self::applyRuntimeMailerConfig($messageType);

        $mailerProfile = self::resolveMailerProfile($messageType);
        $email = (string) $params['email'];
        $subject = (string) $params['subject'];
        $templateName = (string) ($params['template_name'] ?? 'notify');
        $baseDispatchContext = self::mergeDispatchContext(
            $meta['context'] ?? null,
            is_array($params['dispatch_context'] ?? null) ? $params['dispatch_context'] : []
        );
        $fromName = trim((string) ($params['from_name'] ?? $baseDispatchContext['app_name'] ?? admin_setting('app_name', 'XBoard')));
        if (!str_starts_with($templateName, 'mail.')) {
            $templateName = 'mail.' . admin_setting('email_template', 'default') . '.' . $templateName;
        }

        $providerHealth = $dispatchService->getProviderHealth()['status'];
        $failureClassification = null;
        $providerResponse = null;

        $suppression = null;
        if (($meta['respect_suppression'] ?? false) === true) {
            $suppression = $dispatchService->matchSuppression('email', $email, $messageType, $meta['user_id'] ?? null);
        }

        if ($suppression) {
            $error = 'suppressed:' . $suppression->reason_type;
            $failureClassification = null;
        } else {
            $quota = $dispatchService->checkImmediateQuota($messageType, 'email');
            if (!$quota['allowed']) {
                if (($meta['defer_on_quota_block'] ?? false) === true) {
                    return [
                        'email' => $email,
                        'subject' => $subject,
                        'template_name' => $templateName,
                        'error' => 'message quota blocked: ' . $quota['reason'],
                        'failure_classification' => MessageDispatchLog::FAILURE_RATE_LIMIT,
                        'provider_response' => $quota['reason'],
                        'mail_log_id' => null,
                        'dispatch_log_id' => null,
                        'deferred_by_quota' => true,
                        'quota_reason' => $quota['reason'],
                    ];
                }
                $error = 'message quota blocked: ' . $quota['reason'];
                $failureClassification = MessageDispatchLog::FAILURE_RATE_LIMIT;
                $providerResponse = $quota['reason'];
            } else {
                try {
                    $fromAddress = trim((string) config('mail.from.address', ''));
                    Mail::send(
                        $templateName,
                        $params['template_value'],
                        function ($message) use ($email, $subject, $fromName, $fromAddress) {
                            if ($fromName !== '' && $fromAddress !== '') {
                                $message->from($fromAddress, $fromName);
                            }
                            $message->to($email)->subject($subject);
                        }
                    );
                    $error = null;
                } catch (\Throwable $e) {
                    Log::error($e);
                    $error = $e->getMessage();
                    $failureClassification = $dispatchService->classifyEmailFailure($error);
                }
            }
        }

        $mailLog = MailLog::create([
            'email' => $email,
            'subject' => $subject,
            'template_name' => $templateName,
            'error' => $error,
        ]);

        $dispatchLog = $dispatchService->logDispatchAttempt([
            'task_id' => $meta['task_id'] ?? null,
            'user_id' => $meta['user_id'] ?? null,
            'rule_id' => $meta['rule_id'] ?? null,
            'template_id' => $meta['template_id'] ?? null,
            'mail_log_id' => $mailLog->id,
            'channel' => 'email',
            'message_type' => $messageType,
            'status' => $suppression
                ? MessageDispatchLog::STATUS_SUPPRESSED
                : ($error ? MessageDispatchLog::STATUS_FAILED : MessageDispatchLog::STATUS_SUCCESS),
            'attempt' => max(1, (int) ($meta['attempt'] ?? 1)),
            'to_address' => $email,
            'subject' => $subject,
            'failure_classification' => $failureClassification,
            'provider_health_status' => $providerHealth,
            'error_message' => $error,
            'provider_response' => $providerResponse,
            'context' => self::mergeDispatchContext(
                $baseDispatchContext,
                [
                'mailer_profile' => $mailerProfile,
                ]
            ),
        ]);

        return [
            'email' => $email,
            'subject' => $subject,
            'template_name' => $templateName,
            'error' => $error,
            'failure_classification' => $failureClassification,
            'provider_response' => $providerResponse,
            'mail_log_id' => $mailLog->id,
            'dispatch_log_id' => $dispatchLog->id,
            'mailer_profile' => $mailerProfile,
        ];
    }

    private static function resolveMailerProfile(string $messageType): string
    {
        return self::shouldUseMarketingMailer($messageType) ? 'marketing' : 'default';
    }

    private static function applyRuntimeMailerConfig(string $messageType): void
    {
        if (self::shouldUseMarketingMailer($messageType)) {
            self::applyMarketingMailerConfig();
            return;
        }

        self::applyDefaultMailerConfig();
    }

    private static function shouldUseMarketingMailer(string $messageType): bool
    {
        if ($messageType !== MarketingRule::TYPE_MARKETING) {
            return false;
        }

        if (!(bool) admin_setting('marketing_email_enabled', false)) {
            return false;
        }

        return (bool) admin_setting('marketing_email_host');
    }

    private static function applyDefaultMailerConfig(): void
    {
        if (!admin_setting('email_host')) {
            return;
        }

        Config::set('mail.host', admin_setting('email_host', config('mail.host')));
        Config::set('mail.port', admin_setting('email_port', config('mail.port')));
        Config::set('mail.encryption', admin_setting('email_encryption', config('mail.encryption')));
        Config::set('mail.username', admin_setting('email_username', config('mail.username')));
        Config::set('mail.password', admin_setting('email_password', config('mail.password')));
        Config::set('mail.from.address', admin_setting('email_from_address', config('mail.from.address')));
        Config::set('mail.from.name', admin_setting('app_name', 'XBoard'));
    }

    private static function applyMarketingMailerConfig(): void
    {
        Config::set('mail.host', admin_setting('marketing_email_host', admin_setting('email_host', config('mail.host'))));
        Config::set('mail.port', admin_setting('marketing_email_port', admin_setting('email_port', config('mail.port'))));
        Config::set('mail.encryption', admin_setting('marketing_email_encryption', admin_setting('email_encryption', config('mail.encryption'))));
        Config::set('mail.username', admin_setting('marketing_email_username', admin_setting('email_username', config('mail.username'))));
        Config::set('mail.password', admin_setting('marketing_email_password', admin_setting('email_password', config('mail.password'))));
        Config::set('mail.from.address', admin_setting('marketing_email_from_address', admin_setting('email_from_address', config('mail.from.address'))));
        Config::set('mail.from.name', admin_setting('app_name', 'XBoard'));
    }

    private static function mergeDispatchContext(mixed $context, array $extras): array
    {
        $base = is_array($context) ? $context : [];
        return array_merge($base, $extras);
    }
}
