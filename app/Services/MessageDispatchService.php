<?php

namespace App\Services;

use App\Jobs\ExecuteMessageDispatchTaskJob;
use App\Models\MarketingRule;
use App\Models\MessageDispatchLog;
use App\Models\MessageDispatchTask;
use App\Models\MessageSuppression;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MessageDispatchService
{
    public const EMAIL_PROVIDER_HOURLY_LIMIT = 3000;
    public const EMAIL_SOFT_HOURLY_LIMIT = 1800;
    public const EMAIL_LIFECYCLE_HOURLY_LIMIT = 600;
    public const EMAIL_MARKETING_HOURLY_LIMIT = 300;
    public const HEALTH_WINDOW_HOURS = 6;
    public const HEALTH_MIN_SAMPLE = 20;
    public const SENDING_TIMEOUT_SECONDS = 900;

    public function enqueueTask(array $attributes): ?MessageDispatchTask
    {
        $now = time();
        $dedupeKey = trim((string) ($attributes['dedupe_key'] ?? ''));
        if ($dedupeKey !== '') {
            $exists = MessageDispatchTask::query()
                ->where('dedupe_key', $dedupeKey)
                ->exists();
            if ($exists) {
                return null;
            }
        }

        return MessageDispatchTask::create([
            'user_id' => $attributes['user_id'] ?? null,
            'rule_id' => $attributes['rule_id'] ?? null,
            'template_id' => $attributes['template_id'] ?? null,
            'channel' => $attributes['channel'],
            'message_type' => $attributes['message_type'] ?? MarketingRule::TYPE_MARKETING,
            'priority' => (int) ($attributes['priority'] ?? 100),
            'state' => MessageDispatchTask::STATE_PENDING,
            'dedupe_key' => $dedupeKey !== '' ? $dedupeKey : null,
            'to_address' => $attributes['to_address'] ?? null,
            'subject' => $attributes['subject'] ?? null,
            'payload' => $attributes['payload'] ?? null,
            'context' => $attributes['context'] ?? null,
            'scheduled_at' => (int) ($attributes['scheduled_at'] ?? $now),
            'available_at' => (int) ($attributes['available_at'] ?? $now),
            'max_attempts' => max(1, (int) ($attributes['max_attempts'] ?? 3)),
        ]);
    }

    public function releaseDueTasks(int $limit = 200): array
    {
        $now = time();
        $released = 0;
        $blockedByQuota = 0;
        $checked = 0;

        $tasks = MessageDispatchTask::query()
            ->where('state', MessageDispatchTask::STATE_PENDING)
            ->where(function ($query) use ($now): void {
                $query->whereNull('available_at')
                    ->orWhere('available_at', '<=', $now);
            })
            ->where(function ($query) use ($now): void {
                $query->whereNull('scheduled_at')
                    ->orWhere('scheduled_at', '<=', $now);
            })
            ->orderBy('priority')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($tasks as $task) {
            $checked++;
            $quota = $this->checkImmediateQuota($task->message_type, $task->channel);
            if (!$quota['allowed']) {
                $blockedByQuota++;
                continue;
            }

            $updated = MessageDispatchTask::query()
                ->whereKey($task->id)
                ->where('state', MessageDispatchTask::STATE_PENDING)
                ->update([
                    'state' => MessageDispatchTask::STATE_SENDING,
                    'reserved_at' => $now,
                    'updated_at' => $now,
                ]);

            if (!$updated) {
                continue;
            }

            ExecuteMessageDispatchTaskJob::dispatch($task->id)->onQueue('message_dispatch');
            $released++;
        }

        return [
            'checked' => $checked,
            'released' => $released,
            'blocked_by_quota' => $blockedByQuota,
        ];
    }

    public function recoverStuckSendingTasks(int $limit = 200): array
    {
        $now = time();
        $cutoff = $now - self::SENDING_TIMEOUT_SECONDS;
        $summary = [
            'checked' => 0,
            'requeued' => 0,
            'failed' => 0,
        ];

        $tasks = MessageDispatchTask::query()
            ->where('state', MessageDispatchTask::STATE_SENDING)
            ->where(function ($query) use ($cutoff): void {
                $query->where('reserved_at', '<=', $cutoff)
                    ->orWhere(function ($nested) use ($cutoff): void {
                        $nested->whereNull('reserved_at')
                            ->where('updated_at', '<=', $cutoff);
                    });
            })
            ->orderBy('reserved_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($tasks as $task) {
            $summary['checked']++;
            $nextAttempt = min((int) $task->max_attempts, (int) $task->attempt_count + 1);
            $common = [
                'attempt_count' => $nextAttempt,
                'failure_classification' => MessageDispatchLog::FAILURE_TIMEOUT,
                'last_error' => 'sending timeout recovered by scheduler',
                'provider_response' => 'stuck_sending_timeout',
                'recovery_count' => (int) $task->recovery_count + 1,
                'last_recovered_at' => $now,
                'updated_at' => $now,
                'reserved_at' => null,
            ];

            if ($nextAttempt >= (int) $task->max_attempts) {
                $updated = MessageDispatchTask::query()
                    ->whereKey($task->id)
                    ->where('state', MessageDispatchTask::STATE_SENDING)
                    ->update(array_merge($common, [
                        'state' => MessageDispatchTask::STATE_FAILED,
                    ]));

                if ($updated) {
                    $summary['failed']++;
                }
                continue;
            }

            $updated = MessageDispatchTask::query()
                ->whereKey($task->id)
                ->where('state', MessageDispatchTask::STATE_SENDING)
                ->update(array_merge($common, [
                    'state' => MessageDispatchTask::STATE_PENDING,
                    'available_at' => $now + $this->retryDelaySeconds(MessageDispatchLog::FAILURE_TIMEOUT, $nextAttempt),
                ]));

            if ($updated) {
                $summary['requeued']++;
            }
        }

        return $summary;
    }

    public function processTask(int $taskId): void
    {
        /** @var MessageDispatchTask|null $task */
        $task = MessageDispatchTask::query()->with(['user', 'rule', 'template'])->find($taskId);
        if (!$task || $task->state !== MessageDispatchTask::STATE_SENDING) {
            return;
        }

        $attempt = (int) $task->attempt_count + 1;
        $user = $task->user;

        if ($task->channel === 'email') {
            $suppression = $this->matchSuppression(
                'email',
                $task->to_address,
                $task->message_type,
                $task->user_id
            );

            if ($suppression) {
                $this->logDispatchAttempt([
                    'task_id' => $task->id,
                    'user_id' => $task->user_id,
                    'rule_id' => $task->rule_id,
                    'template_id' => $task->template_id,
                    'channel' => 'email',
                    'message_type' => $task->message_type,
                    'status' => MessageDispatchLog::STATUS_SUPPRESSED,
                    'attempt' => $attempt,
                    'to_address' => $task->to_address,
                    'subject' => $task->subject,
                    'failure_classification' => null,
                    'provider_health_status' => $this->getProviderHealth()['status'],
                    'error_message' => 'suppressed:' . $suppression->reason_type,
                    'provider_response' => null,
                    'context' => array_merge($task->context ?? [], [
                        'suppression_id' => $suppression->id,
                        'suppression_scope' => $suppression->scope,
                        'suppression_reason' => $suppression->reason_type,
                    ]),
                ]);

                $task->update([
                    'state' => MessageDispatchTask::STATE_CANCELLED,
                    'attempt_count' => $attempt,
                    'last_error' => 'suppressed:' . $suppression->reason_type,
                    'updated_at' => time(),
                ]);
                return;
            }

            $payload = $task->payload ?? [];
            $result = MailService::sendEmail([
                'email' => $task->to_address,
                'subject' => $task->subject ?? ($payload['subject'] ?? ''),
                'template_name' => $payload['template_name'] ?? 'notify',
                'template_value' => $payload['template_value'] ?? [],
                'message_type' => $task->message_type,
            ], [
                'task_id' => $task->id,
                'user_id' => $task->user_id,
                'rule_id' => $task->rule_id,
                'template_id' => $task->template_id,
                'attempt' => $attempt,
                'context' => $task->context ?? [],
                'respect_suppression' => true,
            ]);

            $this->finishTaskAfterSend($task, $attempt, $result, $user);
            return;
        }

        if ($task->channel === 'telegram') {
            $result = $this->sendTelegramTask($task, $attempt);
            $this->finishTaskAfterSend($task, $attempt, $result, $user);
        }
    }

    public function finishTaskAfterSend(MessageDispatchTask $task, int $attempt, array $result, ?User $user = null): void
    {
        $now = time();
        $error = trim((string) ($result['error'] ?? ''));
        $classification = $result['failure_classification'] ?? null;

        if ($error === '') {
            $task->update([
                'state' => MessageDispatchTask::STATE_SENT,
                'sent_at' => $now,
                'attempt_count' => $attempt,
                'failure_classification' => null,
                'last_error' => null,
                'provider_response' => $result['provider_response'] ?? null,
                'updated_at' => $now,
            ]);
            return;
        }

        if ($classification === MessageDispatchLog::FAILURE_PERMANENT && $task->channel === 'email') {
            $this->addSuppression(
                'email',
                $task->to_address,
                MessageSuppression::SCOPE_ALL,
                MessageSuppression::REASON_PERMANENT_FAILURE,
                $error,
                $task->user_id
            );
        }

        if ($attempt < $task->max_attempts && $this->shouldRetry($classification)) {
            $task->update([
                'state' => MessageDispatchTask::STATE_PENDING,
                'available_at' => $now + $this->retryDelaySeconds($classification, $attempt),
                'attempt_count' => $attempt,
                'failure_classification' => $classification,
                'last_error' => $error,
                'provider_response' => $result['provider_response'] ?? null,
                'updated_at' => $now,
            ]);
            return;
        }

        $task->update([
            'state' => MessageDispatchTask::STATE_FAILED,
            'attempt_count' => $attempt,
            'failure_classification' => $classification,
            'last_error' => $error,
            'provider_response' => $result['provider_response'] ?? null,
            'updated_at' => $now,
        ]);
    }

    public function logDispatchAttempt(array $attributes): MessageDispatchLog
    {
        return MessageDispatchLog::create([
            'task_id' => $attributes['task_id'] ?? null,
            'user_id' => $attributes['user_id'] ?? null,
            'rule_id' => $attributes['rule_id'] ?? null,
            'template_id' => $attributes['template_id'] ?? null,
            'mail_log_id' => $attributes['mail_log_id'] ?? null,
            'channel' => $attributes['channel'],
            'message_type' => $attributes['message_type'] ?? MarketingRule::TYPE_TRANSACTIONAL,
            'status' => $attributes['status'],
            'attempt' => max(1, (int) ($attributes['attempt'] ?? 1)),
            'to_address' => $attributes['to_address'] ?? null,
            'subject' => $attributes['subject'] ?? null,
            'failure_classification' => $attributes['failure_classification'] ?? null,
            'provider_health_status' => $attributes['provider_health_status'] ?? null,
            'error_message' => $attributes['error_message'] ?? null,
            'provider_response' => $attributes['provider_response'] ?? null,
            'context' => $attributes['context'] ?? null,
            'manual_note' => $attributes['manual_note'] ?? null,
            'noted_by_admin_id' => $attributes['noted_by_admin_id'] ?? null,
            'noted_at' => $attributes['noted_at'] ?? null,
        ]);
    }

    public function saveLogNote(MessageDispatchLog $log, ?string $note, ?int $adminId = null): MessageDispatchLog
    {
        $trimmed = trim((string) $note);
        $log->update([
            'manual_note' => $trimmed !== '' ? $trimmed : null,
            'noted_by_admin_id' => $trimmed !== '' ? $adminId : null,
            'noted_at' => $trimmed !== '' ? time() : null,
        ]);

        return $log->refresh();
    }

    public function checkImmediateQuota(string $messageType, string $channel = 'email'): array
    {
        if ($channel !== 'email') {
            return [
                'allowed' => true,
                'reason' => null,
            ];
        }

        $stats = $this->getQuotaOverview();
        $providerUsed = (int) $stats['current_hour']['attempts'];
        $reservedTotal = (int) $stats['current_hour']['sending'];
        $softUsed = $providerUsed + $reservedTotal;

        if ($providerUsed >= self::EMAIL_PROVIDER_HOURLY_LIMIT) {
            return ['allowed' => false, 'reason' => 'provider_hourly_limit'];
        }

        if ($softUsed >= self::EMAIL_SOFT_HOURLY_LIMIT) {
            return ['allowed' => false, 'reason' => 'system_soft_limit'];
        }

        if ($messageType === MarketingRule::TYPE_MARKETING) {
            $used = (int) $stats['current_hour']['marketing_attempts'] + (int) $stats['current_hour']['marketing_sending'];
            if ($used >= self::EMAIL_MARKETING_HOURLY_LIMIT) {
                return ['allowed' => false, 'reason' => 'marketing_bucket_limit'];
            }
        }

        if ($messageType === MarketingRule::TYPE_LIFECYCLE) {
            $used = (int) $stats['current_hour']['lifecycle_attempts'] + (int) $stats['current_hour']['lifecycle_sending'];
            if ($used >= self::EMAIL_LIFECYCLE_HOURLY_LIMIT) {
                return ['allowed' => false, 'reason' => 'lifecycle_bucket_limit'];
            }
        }

        return ['allowed' => true, 'reason' => null];
    }

    public function getQuotaOverview(): array
    {
        $hourStart = CarbonImmutable::now()->startOfHour()->timestamp;

        $attemptBase = MessageDispatchLog::query()
            ->where('channel', 'email')
            ->where('created_at', '>=', $hourStart)
            ->whereIn('status', [MessageDispatchLog::STATUS_SUCCESS, MessageDispatchLog::STATUS_FAILED]);

        $sendingBase = MessageDispatchTask::query()
            ->where('channel', 'email')
            ->where('state', MessageDispatchTask::STATE_SENDING)
            ->where('reserved_at', '>=', $hourStart);

        $pendingBase = MessageDispatchTask::query()
            ->where('channel', 'email')
            ->where('state', MessageDispatchTask::STATE_PENDING);

        return [
            'provider_hourly_limit' => self::EMAIL_PROVIDER_HOURLY_LIMIT,
            'system_soft_hourly_limit' => self::EMAIL_SOFT_HOURLY_LIMIT,
            'reserved_capacity' => [
                'transactional_headroom' => self::EMAIL_SOFT_HOURLY_LIMIT - self::EMAIL_LIFECYCLE_HOURLY_LIMIT - self::EMAIL_MARKETING_HOURLY_LIMIT,
                'lifecycle_cap' => self::EMAIL_LIFECYCLE_HOURLY_LIMIT,
                'marketing_cap' => self::EMAIL_MARKETING_HOURLY_LIMIT,
            ],
            'current_hour' => [
                'attempts' => (clone $attemptBase)->count(),
                'success' => (clone $attemptBase)->where('status', MessageDispatchLog::STATUS_SUCCESS)->count(),
                'failed' => (clone $attemptBase)->where('status', MessageDispatchLog::STATUS_FAILED)->count(),
                'sending' => (clone $sendingBase)->count(),
                'pending' => (clone $pendingBase)->count(),
                'transactional_attempts' => (clone $attemptBase)->where('message_type', MarketingRule::TYPE_TRANSACTIONAL)->count(),
                'lifecycle_attempts' => (clone $attemptBase)->where('message_type', MarketingRule::TYPE_LIFECYCLE)->count(),
                'marketing_attempts' => (clone $attemptBase)->where('message_type', MarketingRule::TYPE_MARKETING)->count(),
                'transactional_sending' => (clone $sendingBase)->where('message_type', MarketingRule::TYPE_TRANSACTIONAL)->count(),
                'lifecycle_sending' => (clone $sendingBase)->where('message_type', MarketingRule::TYPE_LIFECYCLE)->count(),
                'marketing_sending' => (clone $sendingBase)->where('message_type', MarketingRule::TYPE_MARKETING)->count(),
            ],
        ];
    }

    public function getProviderHealth(): array
    {
        $windowStart = CarbonImmutable::now()->subHours(self::HEALTH_WINDOW_HOURS)->timestamp;

        $base = MessageDispatchLog::query()
            ->where('channel', 'email')
            ->where('created_at', '>=', $windowStart)
            ->whereIn('status', [MessageDispatchLog::STATUS_SUCCESS, MessageDispatchLog::STATUS_FAILED]);

        $total = (clone $base)->count();
        if ($total < self::HEALTH_MIN_SAMPLE) {
            return [
                'status' => MessageDispatchLog::HEALTH_UNKNOWN,
                'window_hours' => self::HEALTH_WINDOW_HOURS,
                'sample_size' => $total,
                'success_rate' => null,
                'provider_issue_rate' => null,
            ];
        }

        $success = (clone $base)->where('status', MessageDispatchLog::STATUS_SUCCESS)->count();
        $providerIssues = (clone $base)
            ->where('status', MessageDispatchLog::STATUS_FAILED)
            ->whereIn('failure_classification', [
                MessageDispatchLog::FAILURE_PROVIDER,
                MessageDispatchLog::FAILURE_RATE_LIMIT,
                MessageDispatchLog::FAILURE_TIMEOUT,
            ])
            ->count();

        $successRate = $total > 0 ? $success / $total : 0;
        $providerIssueRate = $total > 0 ? $providerIssues / $total : 0;

        $status = MessageDispatchLog::HEALTH_HEALTHY;
        if ($successRate < 0.50 || $providerIssueRate > 0.40) {
            $status = MessageDispatchLog::HEALTH_UNHEALTHY;
        } elseif ($successRate < 0.85 || $providerIssueRate > 0.10) {
            $status = MessageDispatchLog::HEALTH_DEGRADED;
        }

        return [
            'status' => $status,
            'window_hours' => self::HEALTH_WINDOW_HOURS,
            'sample_size' => $total,
            'success_rate' => round($successRate, 4),
            'provider_issue_rate' => round($providerIssueRate, 4),
        ];
    }

    public function classifyEmailFailure(?string $message): string
    {
        $normalized = Str::lower(trim((string) $message));

        if ($normalized === '') {
            return MessageDispatchLog::FAILURE_TEMPORARY;
        }

        if ($this->containsAny($normalized, [
            'rate limit',
            'too many requests',
            'throttle',
            'quota exceeded',
            'message quota',
            'daily user sending limit exceeded',
        ])) {
            return MessageDispatchLog::FAILURE_RATE_LIMIT;
        }

        if ($this->containsAny($normalized, [
            'timeout',
            'timed out',
            'operation timed out',
            'connection timed out',
        ])) {
            return MessageDispatchLog::FAILURE_TIMEOUT;
        }

        if ($this->containsAny($normalized, [
            '550',
            '551',
            '552',
            '553',
            '554',
            '5.1.1',
            '5.1.0',
            'user unknown',
            'unknown user',
            'no such user',
            'invalid recipient',
            'bad destination mailbox',
            'mailbox unavailable',
            'recipient address rejected',
            'relay access denied',
            'domain does not exist',
        ])) {
            return MessageDispatchLog::FAILURE_PERMANENT;
        }

        if ($this->containsAny($normalized, [
            'connection refused',
            'could not authenticate',
            'authentication failed',
            'mail server unavailable',
            'server unavailable',
            'connection could not be established',
            'network is unreachable',
            'dns',
            'smtp connect() failed',
        ])) {
            return MessageDispatchLog::FAILURE_PROVIDER;
        }

        if ($this->containsAny($normalized, [
            '421',
            '450',
            '451',
            '452',
            'temporary',
            'temporarily',
            'try again later',
            'greylist',
        ])) {
            return MessageDispatchLog::FAILURE_TEMPORARY;
        }

        return MessageDispatchLog::FAILURE_TEMPORARY;
    }

    public function matchSuppression(string $channel, ?string $address, string $messageType, ?int $userId = null): ?MessageSuppression
    {
        $query = MessageSuppression::query()
            ->where('channel', $channel)
            ->where('active', true)
            ->where(function ($builder) use ($address, $userId): void {
                if ($address !== null && $address !== '') {
                    $builder->orWhere('address', $address);
                }
                if ($userId) {
                    $builder->orWhere('user_id', $userId);
                }
            })
            ->where(function ($builder): void {
                $builder->whereNull('expires_at')
                    ->orWhere('expires_at', '>', time());
            })
            ->orderByDesc('id');

        foreach ($query->get() as $suppression) {
            if ($this->matchesSuppressionScope($suppression->scope, $messageType)) {
                return $suppression;
            }
        }

        return null;
    }

    public function addSuppression(
        string $channel,
        ?string $address,
        string $scope,
        string $reasonType,
        ?string $reasonDetail = null,
        ?int $userId = null,
        ?int $createdByAdminId = null
    ): MessageSuppression {
        $existing = MessageSuppression::query()
            ->where('channel', $channel)
            ->where('scope', $scope)
            ->where('reason_type', $reasonType)
            ->where('address', $address)
            ->where('user_id', $userId)
            ->where('active', true)
            ->first();

        if ($existing) {
            return $existing;
        }

        return MessageSuppression::create([
            'user_id' => $userId,
            'channel' => $channel,
            'address' => $address,
            'scope' => $scope,
            'reason_type' => $reasonType,
            'reason_detail' => $reasonDetail,
            'created_by_admin_id' => $createdByAdminId,
            'active' => true,
        ]);
    }

    private function sendTelegramTask(MessageDispatchTask $task, int $attempt): array
    {
        $payload = $task->payload ?? [];
        $health = $this->getProviderHealth()['status'];

        try {
            if (!(bool) admin_setting('telegram_bot_enable', 0)) {
                throw new \RuntimeException('telegram bot disabled');
            }

            $telegramId = (int) ($payload['telegram_id'] ?? 0);
            $text = trim((string) ($payload['text'] ?? ''));
            if ($telegramId <= 0 || $text === '') {
                throw new \RuntimeException('telegram target missing');
            }

            (new TelegramService())->sendMessage($telegramId, $text, 'markdown');
            $this->logDispatchAttempt([
                'task_id' => $task->id,
                'user_id' => $task->user_id,
                'rule_id' => $task->rule_id,
                'template_id' => $task->template_id,
                'channel' => 'telegram',
                'message_type' => $task->message_type,
                'status' => MessageDispatchLog::STATUS_SUCCESS,
                'attempt' => $attempt,
                'to_address' => (string) $telegramId,
                'subject' => $task->subject,
                'provider_health_status' => $health,
                'context' => $task->context ?? null,
            ]);

            return [
                'error' => null,
                'failure_classification' => null,
                'provider_response' => 'ok',
            ];
        } catch (\Throwable $e) {
            $classification = $this->classifyEmailFailure($e->getMessage());
            $this->logDispatchAttempt([
                'task_id' => $task->id,
                'user_id' => $task->user_id,
                'rule_id' => $task->rule_id,
                'template_id' => $task->template_id,
                'channel' => 'telegram',
                'message_type' => $task->message_type,
                'status' => MessageDispatchLog::STATUS_FAILED,
                'attempt' => $attempt,
                'to_address' => (string) ($payload['telegram_id'] ?? ''),
                'subject' => $task->subject,
                'failure_classification' => $classification,
                'provider_health_status' => $health,
                'error_message' => $e->getMessage(),
                'provider_response' => null,
                'context' => $task->context ?? null,
            ]);

            return [
                'error' => $e->getMessage(),
                'failure_classification' => $classification,
                'provider_response' => null,
            ];
        }
    }

    private function matchesSuppressionScope(string $scope, string $messageType): bool
    {
        return match ($scope) {
            MessageSuppression::SCOPE_MARKETING_ONLY => $messageType === MarketingRule::TYPE_MARKETING,
            MessageSuppression::SCOPE_NON_TRANSACTIONAL => $messageType !== MarketingRule::TYPE_TRANSACTIONAL,
            default => true,
        };
    }

    private function retryDelaySeconds(?string $classification, int $attempt): int
    {
        return match ($classification) {
            MessageDispatchLog::FAILURE_TIMEOUT => 600 * $attempt,
            MessageDispatchLog::FAILURE_RATE_LIMIT => 900 * $attempt,
            MessageDispatchLog::FAILURE_PROVIDER => 1800 * $attempt,
            default => 900 * $attempt,
        };
    }

    public function shouldRetry(?string $classification): bool
    {
        return in_array($classification, [
            MessageDispatchLog::FAILURE_TEMPORARY,
            MessageDispatchLog::FAILURE_PROVIDER,
            MessageDispatchLog::FAILURE_RATE_LIMIT,
            MessageDispatchLog::FAILURE_TIMEOUT,
        ], true);
    }

    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
