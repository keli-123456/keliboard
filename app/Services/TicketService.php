<?php
namespace App\Services;


use App\Exceptions\ApiException;
use App\Jobs\SendEmailJob;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\TicketMessageAttachment;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Services\Plugin\HookManager;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class TicketService
{
    private function assertStaffReplyAllowed(Ticket $ticket, int $userId): void
    {
        $limit = (int) config('tickets.staff_reply_limit', 2);
        if ($limit <= 0) {
            return;
        }

        $actor = User::find($userId);
        if (!$actor || !$actor->is_staff || $actor->is_admin) {
            return;
        }

        $recentUserIds = TicketMessage::query()
            ->where('ticket_id', $ticket->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->pluck('user_id')
            ->all();

        if (count($recentUserIds) < $limit) {
            return;
        }

        $ticketOwnerId = (int) $ticket->user_id;
        foreach ($recentUserIds as $uid) {
            if ((int) $uid === $ticketOwnerId) {
                return;
            }
        }

        throw new ApiException("用户未回复前，员工最多连续回复{$limit}条消息", 400);
    }

    public function reply($ticket, $message, $userId, array $images = [])
    {
        $stored = [];
        try {
            DB::beginTransaction();
            $ticketMessage = TicketMessage::create([
                'user_id' => $userId,
                'ticket_id' => $ticket->id,
                'message' => $message
            ]);

            if (!empty($images)) {
                $attachmentService = new TicketAttachmentService();
                $stored = $attachmentService->storeUploadedImages($images, (int) $ticket->id, (int) $ticketMessage->id);
                foreach ($stored as $meta) {
                    $ok = TicketMessageAttachment::create([
                        'ticket_id' => $ticket->id,
                        'ticket_message_id' => $ticketMessage->id,
                        'user_id' => $userId,
                        'disk' => $meta['disk'],
                        'path' => $meta['path'],
                        'mime' => $meta['mime'],
                        'size' => $meta['size'],
                        'width' => $meta['width'],
                        'height' => $meta['height'],
                    ]);
                    if (!$ok) {
                        throw new \Exception();
                    }
                }
            }

            $autoReplied = null;
            if ((int) $userId === (int) $ticket->user_id) {
                $autoReplied = $this->tryAutoReplyToUserMessage(
                    $ticket,
                    (string) ($ticket->subject ?? ''),
                    (string) $message,
                    false
                );
            }

            if ((int) $userId !== (int) $ticket->user_id) {
                $ticket->reply_status = Ticket::REPLY_STATUS_WAITING_USER;
            } else {
                $ticket->reply_status = $autoReplied
                    ? Ticket::REPLY_STATUS_AUTO_REPLIED
                    : Ticket::REPLY_STATUS_WAITING_ADMIN;
            }
            if (!$ticketMessage || !$ticket->save()) {
                throw new \Exception();
            }
            DB::commit();
            return $ticketMessage;
        } catch (\Exception $e) {
            DB::rollback();
            foreach ($stored as $meta) {
                try {
                    Storage::disk($meta['disk'])->delete($meta['path']);
                } catch (\Exception) {
                }
            }
            return false;
        }
    }

    public function replyByAdmin($ticketId, $message, $userId, array $images = []): void
    {
        $stored = [];
        try {
            DB::beginTransaction();
            /** @var Ticket|null $ticket */
            $ticket = Ticket::where('id', $ticketId)->lockForUpdate()->first();
            if (!$ticket) {
                throw new ApiException('工单不存在');
            }

            $this->assertStaffReplyAllowed($ticket, (int) $userId);

            $ticket->status = Ticket::STATUS_OPENING;
            $ticketMessage = TicketMessage::create([
                'user_id' => $userId,
                'ticket_id' => $ticket->id,
                'message' => $message
            ]);

            if (!empty($images)) {
                $attachmentService = new TicketAttachmentService();
                $stored = $attachmentService->storeUploadedImages($images, (int) $ticket->id, (int) $ticketMessage->id);
                foreach ($stored as $meta) {
                    $ok = TicketMessageAttachment::create([
                        'ticket_id' => $ticket->id,
                        'ticket_message_id' => $ticketMessage->id,
                        'user_id' => $userId,
                        'disk' => $meta['disk'],
                        'path' => $meta['path'],
                        'mime' => $meta['mime'],
                        'size' => $meta['size'],
                        'width' => $meta['width'],
                        'height' => $meta['height'],
                    ]);
                    if (!$ok) {
                        throw new ApiException('工单附件创建失败');
                    }
                }
            }

            if ($userId !== $ticket->user_id) {
                $ticket->reply_status = Ticket::REPLY_STATUS_WAITING_USER;
            } else {
                $ticket->reply_status = Ticket::REPLY_STATUS_WAITING_ADMIN;
            }
            if (!$ticketMessage || !$ticket->save()) {
                throw new ApiException('工单回复失败');
            }
            DB::commit();
            HookManager::call('ticket.reply.admin.after', [$ticket, $ticketMessage]);
        } catch (\Exception $e) {
            DB::rollBack();
            foreach ($stored as $meta) {
                try {
                    Storage::disk($meta['disk'])->delete($meta['path']);
                } catch (\Exception) {
                }
            }
            throw $e;
        }
        $this->sendEmailNotify($ticket, $ticketMessage);
    }

    public function createTicket($userId, $subject, $level, $message, array $images = [])
    {
        $stored = [];
        try {
            DB::beginTransaction();
            if (Ticket::where('status', 0)->where('user_id', $userId)->lockForUpdate()->first()) {
                DB::rollBack();
                throw new ApiException('存在未关闭的工单');
            }
            $ticket = Ticket::create([
                'user_id' => $userId,
                'subject' => $subject,
                'level' => $level,
                'status' => Ticket::STATUS_OPENING,
                'reply_status' => Ticket::REPLY_STATUS_WAITING_ADMIN,
            ]);
            if (!$ticket) {
                throw new ApiException('工单创建失败');
            }
            $ticketMessage = TicketMessage::create([
                'user_id' => $userId,
                'ticket_id' => $ticket->id,
                'message' => $message
            ]);
            if (!$ticketMessage) {
                DB::rollBack();
                throw new ApiException('工单消息创建失败');
            }

            if (!empty($images)) {
                $attachmentService = new TicketAttachmentService();
                $stored = $attachmentService->storeUploadedImages($images, (int) $ticket->id, (int) $ticketMessage->id);
                foreach ($stored as $meta) {
                    $ok = TicketMessageAttachment::create([
                        'ticket_id' => $ticket->id,
                        'ticket_message_id' => $ticketMessage->id,
                        'user_id' => $userId,
                        'disk' => $meta['disk'],
                        'path' => $meta['path'],
                        'mime' => $meta['mime'],
                        'size' => $meta['size'],
                        'width' => $meta['width'],
                        'height' => $meta['height'],
                    ]);
                    if (!$ok) {
                        throw new ApiException('工单附件创建失败');
                    }
                }
            }

            $autoReplied = $this->tryAutoReplyToUserMessage(
                $ticket,
                (string) $subject,
                (string) $message,
                true
            );
            $ticket->reply_status = $autoReplied
                ? Ticket::REPLY_STATUS_AUTO_REPLIED
                : Ticket::REPLY_STATUS_WAITING_ADMIN;
            if (!$ticket->save()) {
                throw new ApiException('工单状态更新失败');
            }

            DB::commit();
            return $ticket;
        } catch (\Exception $e) {
            DB::rollBack();
            foreach ($stored as $meta) {
                try {
                    Storage::disk($meta['disk'])->delete($meta['path']);
                } catch (\Exception) {
                }
            }
            throw $e;
        }
    }

    /**
     * @return array{message_id:int, rule_label:?string}|null
     */
    private function tryAutoReplyToUserMessage(Ticket $ticket, string $subject, string $message, bool $isNewTicket): ?array
    {
        try {
            return $this->autoReplyToUserMessage($ticket, $subject, $message, $isNewTicket);
        } catch (\Throwable $e) {
            Log::warning('ticket auto-reply failed', [
                'ticket_id' => (int) $ticket->id,
                'is_new_ticket' => $isNewTicket,
                'message' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * @return array{message_id:int, rule_label:?string}|null
     */
    private function autoReplyToUserMessage(Ticket $ticket, string $subject, string $message, bool $isNewTicket): ?array
    {
        if (!(bool) admin_setting('ticket_auto_reply_enable', 0)) {
            return null;
        }
        if (!$isNewTicket && !(bool) admin_setting('ticket_auto_reply_on_user_reply', 1)) {
            return null;
        }
        if ((bool) admin_setting('ticket_auto_reply_reply_once_per_ticket', 1) && $this->hasAutoReplyAlready($ticket)) {
            return null;
        }

        $maxPerTicket = max(0, (int) admin_setting('ticket_auto_reply_max_per_ticket', 3));
        if ($maxPerTicket > 0 && (int) ($ticket->auto_reply_count ?? 0) >= $maxPerTicket) {
            return null;
        }

        $cooldownSeconds = max(0, (int) admin_setting('ticket_auto_reply_cooldown_seconds', 0));
        $lastAutoReplyAt = (int) ($ticket->auto_reply_last_at ?? 0);
        if ($cooldownSeconds > 0 && $lastAutoReplyAt > 0 && (time() - $lastAutoReplyAt) < $cooldownSeconds) {
            return null;
        }

        $decision = $this->resolveAutoReplyDecision($subject, $message, $isNewTicket);
        if ($decision === null) {
            return null;
        }

        $senderId = $this->resolveAutoReplySenderId((int) $ticket->user_id);
        if ($senderId === null) {
            return null;
        }

        $ticketMessage = TicketMessage::create([
            'user_id' => $senderId,
            'ticket_id' => $ticket->id,
            'message' => $decision['reply'],
            'is_auto_reply' => 1,
            'auto_reply_rule' => $decision['rule_label'],
        ]);

        if (!$ticketMessage) {
            throw new ApiException('工单自动回复失败');
        }

        $ticket->auto_reply_count = max(0, (int) ($ticket->auto_reply_count ?? 0)) + 1;
        $ticket->auto_reply_last_at = time();
        $ticket->last_auto_reply_rule = $decision['rule_label'];
        if (!$ticket->save()) {
            throw new ApiException('工单自动回复状态更新失败');
        }

        return [
            'message_id' => (int) $ticketMessage->id,
            'rule_label' => $decision['rule_label'],
        ];
    }

    private function hasAutoReplyAlready(Ticket $ticket): bool
    {
        return TicketMessage::query()
            ->where('ticket_id', $ticket->id)
            ->where('is_auto_reply', 1)
            ->exists();
    }

    /**
     * @return array{reply: string, rule_label: ?string}|null
     */
    private function resolveAutoReplyDecision(string $subject, string $message, bool $isNewTicket): ?array
    {
        $rules = $this->normalizeAutoReplyRules(admin_setting('ticket_auto_reply_rules', []));
        foreach ($rules as $rule) {
            if (!$rule['enabled']) {
                continue;
            }
            if ($rule['keyword'] === '' || $rule['reply'] === '') {
                continue;
            }
            if ($this->matchesAutoReplyRule($rule, $subject, $message)) {
                return [
                    'reply' => $rule['reply'],
                    'rule_label' => $this->buildAutoReplyRuleLabel($rule),
                ];
            }
        }

        // 默认回复仅在“新建工单”且“未配置任何关键词规则”时生效
        if (!$isNewTicket) {
            return null;
        }
        if (count($rules) > 0) {
            return null;
        }

        $defaultMessage = trim((string) admin_setting('ticket_auto_reply_default_message', ''));
        if ($defaultMessage === '') {
            return null;
        }

        return [
            'reply' => $defaultMessage,
            'rule_label' => 'default',
        ];
    }

    /**
     * @return array<int, array{
     *   enabled: bool,
     *   name: string,
     *   keyword: string,
     *   exclude_keyword: string,
     *   scope: string,
     *   match_mode: string,
     *   priority: int,
     *   reply: string
     * }>
     */
    private function normalizeAutoReplyRules(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $rules = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $scope = strtolower(trim((string) ($item['scope'] ?? 'both')));
            if (!in_array($scope, ['subject', 'message', 'both'], true)) {
                $scope = 'both';
            }
            $matchMode = strtolower(trim((string) ($item['match_mode'] ?? 'contains')));
            if (!in_array($matchMode, ['contains', 'exact', 'regex'], true)) {
                $matchMode = 'contains';
            }
            $rules[] = [
                'enabled' => isset($item['enabled']) ? (bool) $item['enabled'] : true,
                'name' => trim((string) ($item['name'] ?? '')),
                'keyword' => trim((string) ($item['keyword'] ?? '')),
                'exclude_keyword' => trim((string) ($item['exclude_keyword'] ?? '')),
                'scope' => $scope,
                'match_mode' => $matchMode,
                'priority' => max(0, (int) ($item['priority'] ?? 0)),
                'reply' => trim((string) ($item['reply'] ?? '')),
                '__index' => count($rules),
            ];
        }

        usort($rules, function (array $a, array $b) {
            $priorityCmp = ((int) $b['priority']) <=> ((int) $a['priority']);
            if ($priorityCmp !== 0) {
                return $priorityCmp;
            }
            return ((int) $a['__index']) <=> ((int) $b['__index']);
        });

        return array_map(function (array $item) {
            unset($item['__index']);
            return $item;
        }, $rules);
    }

    private function matchesAutoReplyRule(array $rule, string $subject, string $message): bool
    {
        $haystack = match ($rule['scope']) {
            'subject' => $subject,
            'message' => $message,
            default => trim($subject . "\n" . $message),
        };
        if ($haystack === '') {
            return false;
        }

        $keywords = $this->splitAutoReplyKeywords((string) ($rule['keyword'] ?? ''));
        if (count($keywords) === 0) {
            return false;
        }

        $excludeKeywords = $this->splitAutoReplyKeywords((string) ($rule['exclude_keyword'] ?? ''));
        if ($this->containsAnyKeyword($haystack, $excludeKeywords)) {
            return false;
        }

        $matchMode = (string) ($rule['match_mode'] ?? 'contains');
        foreach ($keywords as $keyword) {
            if ($this->matchAutoReplyKeyword($haystack, $keyword, $matchMode)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array<int, string>
     */
    private function splitAutoReplyKeywords(string $keywordRaw): array
    {
        $parts = preg_split('/[\r\n,，]+/u', $keywordRaw) ?: [];
        $keywords = [];
        foreach ($parts as $part) {
            $item = trim((string) $part);
            if ($item !== '') {
                $keywords[] = $item;
            }
        }
        return $keywords;
    }

    /**
     * @param array<int, string> $keywords
     */
    private function containsAnyKeyword(string $haystack, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (mb_stripos($haystack, $keyword, 0, 'UTF-8') !== false) {
                return true;
            }
        }
        return false;
    }

    private function matchAutoReplyKeyword(string $haystack, string $keyword, string $matchMode): bool
    {
        $mode = strtolower(trim($matchMode));
        if ($mode === 'exact') {
            return mb_strtolower(trim($haystack), 'UTF-8') === mb_strtolower(trim($keyword), 'UTF-8');
        }

        if ($mode === 'regex') {
            $pattern = $this->normalizeRegexPattern($keyword);
            if ($pattern === null) {
                return false;
            }
            $matched = @preg_match($pattern, $haystack);
            return $matched === 1;
        }

        return mb_stripos($haystack, $keyword, 0, 'UTF-8') !== false;
    }

    private function normalizeRegexPattern(string $pattern): ?string
    {
        $candidate = trim($pattern);
        if ($candidate === '') {
            return null;
        }

        $first = $candidate[0];
        $lastPos = strrpos($candidate, $first);
        if (in_array($first, ['/', '#', '~', '%'], true) && $lastPos !== false && $lastPos > 0) {
            return $candidate;
        }

        return '/' . $candidate . '/u';
    }

    /**
     * @param array{
     *   name: string,
     *   keyword: string
     * } $rule
     */
    private function buildAutoReplyRuleLabel(array $rule): ?string
    {
        $name = trim((string) ($rule['name'] ?? ''));
        if ($name !== '') {
            return mb_substr($name, 0, 120, 'UTF-8');
        }

        $keywords = $this->splitAutoReplyKeywords((string) ($rule['keyword'] ?? ''));
        if (count($keywords) === 0) {
            return null;
        }

        return mb_substr((string) $keywords[0], 0, 120, 'UTF-8');
    }

    private function resolveAutoReplySenderId(int $ticketOwnerId): ?int
    {
        $sender = User::query()
            ->where('is_admin', 1)
            ->where('id', '!=', $ticketOwnerId)
            ->orderBy('id')
            ->first(['id']);

        if ($sender) {
            return (int) $sender->id;
        }

        $staff = User::query()
            ->where('is_staff', 1)
            ->where('id', '!=', $ticketOwnerId)
            ->orderBy('id')
            ->first(['id']);

        if ($staff) {
            return (int) $staff->id;
        }

        // 兜底使用系统发送人，避免与用户 ID 冲突导致用户无法继续回复
        return 0;
    }

    // 半小时内不再重复通知
    private function sendEmailNotify(Ticket $ticket, TicketMessage $ticketMessage)
    {
        $user = User::find($ticket->user_id);
        $cacheKey = 'ticket_sendEmailNotify_' . $ticket->user_id;
        if (!Cache::get($cacheKey)) {
            Cache::put($cacheKey, 1, 1800);
            SendEmailJob::dispatch([
                'email' => $user->email,
                'subject' => '您在' . admin_setting('app_name', 'XBoard') . '的工单得到了回复',
                'template_name' => 'notify',
                'template_value' => [
                    'name' => admin_setting('app_name', 'XBoard'),
                    'url' => admin_setting('app_url'),
                    'content' => "主题：{$ticket->subject}\r\n回复内容：{$ticketMessage->message}"
                ]
            ]);
        }
    }
}
