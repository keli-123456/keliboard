<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\TicketAiConversation;
use App\Models\TicketAiConversationEvent;
use App\Models\TicketMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TicketAiConversationService
{
    private const HUMAN_REQUEST_PATTERNS = [
        '人工客服', '转人工', '找人工', '真人客服', '人工处理', '人工回复',
        '找客服', '转接客服', '客服人员', '人工接管', '不要ai', '别用ai',
        '不要机器人', '别用机器人', 'human agent', 'live agent',
    ];

    private const PROBLEM_PATTERNS = [
        '不能用', '用不了', '连不上', '连接不上', '连接失败', '导入失败', '导入不了',
        '没有节点', '没节点', '节点不可用', '打不开', '报错', '失败', '断流', '很慢', '太慢',
        'not working', 'failed', 'error', 'timeout',
    ];

    private const DETAIL_PATTERNS = [
        'windows', 'android', 'ios', 'iphone', 'ipad', 'macos', 'mac ',
        '小火箭', 'shadowrocket', 'clash', 'karing', 'v2ray', 'sing-box', 'singbox',
        'loon', 'stash', 'hiddify', 'nekobox', '版本', '完整错误', '错误码', '错误代码',
        '提示为', '提示：', '截图', '日志', '订阅导入', '某个节点',
    ];

    /** @return array{allow:bool,reason:?string,handoff:bool} */
    public function preflight(Ticket $ticket, TicketMessage $sourceMessage): array
    {
        if (!$this->available()) {
            return ['allow' => true, 'reason' => null, 'handoff' => false];
        }

        $state = $this->stateFor($ticket);
        if (!$state) {
            return ['allow' => true, 'reason' => null, 'handoff' => false];
        }
        if ($state->status === TicketAiConversation::STATUS_HUMAN_REQUIRED) {
            return ['allow' => false, 'reason' => $state->handoff_reason ?: 'human_required', 'handoff' => true];
        }
        if ((int) ($state->last_source_message_id ?? 0) >= (int) $sourceMessage->id) {
            return ['allow' => false, 'reason' => 'duplicate_source_message', 'handoff' => false];
        }
        if ($sourceMessage->attachments()->exists()) {
            $this->handoff($state, $sourceMessage, 'attachment');
            return ['allow' => false, 'reason' => 'attachment', 'handoff' => true];
        }
        if ($this->containsAny($this->normalizedMessage($sourceMessage), self::HUMAN_REQUEST_PATTERNS)) {
            $this->handoff($state, $sourceMessage, 'user_requested_human');
            return ['allow' => false, 'reason' => 'user_requested_human', 'handoff' => true];
        }

        $sentCount = TicketMessage::query()
            ->where('ticket_id', $ticket->id)
            ->where('is_auto_reply', 1)
            ->where('auto_reply_rule', 'like', 'ai:%')
            ->count();
        $maxPerTicket = max(1, min(10, (int) admin_setting('ticket_ai_auto_reply_max_per_ticket', 3)));
        if ($sentCount >= $maxPerTicket) {
            $this->handoff($state, $sourceMessage, 'reply_limit_reached');
            return ['allow' => false, 'reason' => 'reply_limit_reached', 'handoff' => true];
        }

        if ($state->status === TicketAiConversation::STATUS_WAITING_USER) {
            $state->status = TicketAiConversation::STATUS_ACTIVE;
            $state->last_activity_at = time();
            $state->save();
        }

        return ['allow' => true, 'reason' => null, 'handoff' => false];
    }

    public function clarification(Ticket $ticket, TicketMessage $sourceMessage): ?string
    {
        if (!$this->available()) {
            return null;
        }
        $state = $this->stateFor($ticket);
        if (!$state || (int) $state->follow_up_count >= 1) {
            return null;
        }

        $message = $this->normalizedMessage($sourceMessage);
        $subject = mb_strtolower(trim((string) $ticket->subject));
        $combined = trim($subject . ' ' . $message);
        if (!$this->containsAny($combined, self::PROBLEM_PATTERNS)) {
            return null;
        }
        if ($this->containsAny($combined, self::DETAIL_PATTERNS)) {
            return null;
        }

        return "我先帮您定位问题，请补充以下信息：\n"
            . "1. 使用的客户端名称和版本；\n"
            . "2. 是订阅无法导入，还是某个节点无法连接；\n"
            . "3. 页面显示的完整错误文字（也可以附截图）。\n"
            . '请不要发送订阅链接、Token、UUID、密码或验证码。';
    }

    public function isDuplicateDraft(Ticket $ticket, string $draft): bool
    {
        if (!$this->available()) {
            return false;
        }
        $state = $this->stateFor($ticket);
        $hash = hash('sha256', trim($draft));

        return $state && $state->last_draft_hash !== null && hash_equals((string) $state->last_draft_hash, $hash);
    }

    /** @param array<string, mixed> $result */
    public function recordSent(
        Ticket $ticket,
        TicketMessage $sourceMessage,
        TicketMessage $replyMessage,
        array $result,
        bool $followUp
    ): void {
        if (!$this->available()) {
            return;
        }
        $state = $this->stateFor($ticket);
        if (!$state) {
            return;
        }

        $state->status = $followUp
            ? TicketAiConversation::STATUS_WAITING_USER
            : TicketAiConversation::STATUS_ACTIVE;
        $state->auto_reply_count = max(0, (int) $state->auto_reply_count) + 1;
        if ($followUp) {
            $state->follow_up_count = max(0, (int) $state->follow_up_count) + 1;
        }
        $state->low_confidence_count = 0;
        $state->last_source_message_id = (int) $sourceMessage->id;
        $state->last_reply_message_id = (int) $replyMessage->id;
        $state->last_draft_hash = hash('sha256', trim((string) ($result['draft'] ?? $replyMessage->message)));
        $state->last_reason = $followUp ? 'clarification_sent' : 'auto_reply_sent';
        $state->last_activity_at = time();
        $state->save();

        $this->recordEvent($state, $followUp ? 'follow_up_sent' : 'sent', $state->last_reason, [
            'source_message_id' => (int) $sourceMessage->id,
            'suggestion_id' => isset($result['suggestion_id']) ? (int) $result['suggestion_id'] : null,
            'reply_message_id' => (int) $replyMessage->id,
        ]);
    }

    public function recordRejected(
        Ticket $ticket,
        TicketMessage $sourceMessage,
        ?int $suggestionId,
        string $reason
    ): void {
        if (!$this->available()) {
            return;
        }
        $state = $this->stateFor($ticket);
        if (!$state) {
            return;
        }

        $state->last_source_message_id = (int) $sourceMessage->id;
        $state->last_reason = $reason;
        $state->last_activity_at = time();
        $handoff = $reason !== 'low_confidence';
        if ($reason === 'low_confidence') {
            $state->low_confidence_count = max(0, (int) $state->low_confidence_count) + 1;
            $handoff = (int) $state->low_confidence_count >= 2;
        }
        if ($handoff) {
            $state->status = TicketAiConversation::STATUS_HUMAN_REQUIRED;
            $state->handoff_reason = $reason;
            $state->handoff_at = $state->handoff_at ?: time();
        }
        $state->save();

        $this->recordEvent($state, $handoff ? 'handoff' : 'held', $reason, [
            'source_message_id' => (int) $sourceMessage->id,
            'suggestion_id' => $suggestionId,
        ]);
    }

    public function recordFailure(int $ticketId, int $sourceMessageId, string $reason): void
    {
        if (!$this->available()) {
            return;
        }
        $ticket = Ticket::query()->find($ticketId);
        if (!$ticket) {
            return;
        }
        $state = $this->stateFor($ticket);
        if (!$state) {
            return;
        }

        $state->status = TicketAiConversation::STATUS_HUMAN_REQUIRED;
        $state->failure_count = max(0, (int) $state->failure_count) + 1;
        $state->last_source_message_id = $sourceMessageId > 0 ? $sourceMessageId : $state->last_source_message_id;
        $state->last_reason = $reason;
        $state->handoff_reason = $reason;
        $state->handoff_at = $state->handoff_at ?: time();
        $state->last_activity_at = time();
        $state->save();

        $this->recordEvent($state, 'failed', $reason, [
            'source_message_id' => $sourceMessageId > 0 ? $sourceMessageId : null,
        ]);
    }

    public function recordHumanReply(Ticket $ticket, TicketMessage $message): void
    {
        if (!$this->available()) {
            return;
        }
        $state = TicketAiConversation::query()->where('ticket_id', $ticket->id)->first();
        if (!$state || (int) $state->auto_reply_count < 1 || $state->handoff_at !== null) {
            return;
        }
        $state->status = TicketAiConversation::STATUS_HUMAN_REQUIRED;
        $state->handoff_reason = 'human_replied';
        $state->handoff_at = time();
        $state->last_reason = 'human_replied';
        $state->last_activity_at = time();
        $state->save();
        $this->recordEvent($state, 'handoff', 'human_replied', [
            'reply_message_id' => (int) $message->id,
        ]);
    }

    public function markClosed(Ticket $ticket): void
    {
        if (!$this->available()) {
            return;
        }
        $state = TicketAiConversation::query()->where('ticket_id', $ticket->id)->first();
        if (!$state || $state->status === TicketAiConversation::STATUS_RESOLVED) {
            return;
        }
        $state->status = TicketAiConversation::STATUS_RESOLVED;
        $state->last_reason = 'ticket_closed';
        $state->last_activity_at = time();
        $state->save();
        $this->recordEvent($state, 'resolved', 'ticket_closed');
    }

    /** @return array<string, mixed> */
    public function stats(int $days): array
    {
        $days = max(1, min(90, $days));
        $empty = [
            'conversations' => 0,
            'auto_replies' => 0,
            'follow_ups' => 0,
            'handoffs' => 0,
            'failures' => 0,
            'resolved_without_handoff' => 0,
            'automatic_resolution_rate' => 0.0,
            'handoff_rate' => 0.0,
            'top_handoff_reasons' => [],
            'top_failure_reasons' => [],
        ];
        if (!$this->available()) {
            return $empty;
        }

        $since = time() - ($days * 86400);
        $base = TicketAiConversation::query()->where('last_activity_at', '>=', $since);
        $conversations = (clone $base)->count();
        $autoReplies = (int) ((clone $base)->sum('auto_reply_count') ?? 0);
        $followUps = (int) ((clone $base)->sum('follow_up_count') ?? 0);
        $handoffs = (clone $base)->whereNotNull('handoff_at')->count();
        $failures = (int) ((clone $base)->sum('failure_count') ?? 0);
        $resolved = (clone $base)
            ->where('status', TicketAiConversation::STATUS_RESOLVED)
            ->whereNull('handoff_at')
            ->where('auto_reply_count', '>', 0)
            ->count();
        $autoRepliedConversations = (clone $base)->where('auto_reply_count', '>', 0)->count();
        $events = TicketAiConversationEvent::query()->where('created_at', '>=', $since);

        return array_merge($empty, [
            'conversations' => (int) $conversations,
            'auto_replies' => $autoReplies,
            'follow_ups' => $followUps,
            'handoffs' => (int) $handoffs,
            'failures' => $failures,
            'resolved_without_handoff' => (int) $resolved,
            'automatic_resolution_rate' => $autoRepliedConversations > 0
                ? round($resolved / $autoRepliedConversations, 4)
                : 0.0,
            'handoff_rate' => $conversations > 0 ? round($handoffs / $conversations, 4) : 0.0,
            'top_handoff_reasons' => $this->groupReasons((clone $events)->where('event', 'handoff')),
            'top_failure_reasons' => $this->groupReasons((clone $events)->where('event', 'failed')),
        ]);
    }

    private function handoff(TicketAiConversation $state, TicketMessage $sourceMessage, string $reason): void
    {
        $state->status = TicketAiConversation::STATUS_HUMAN_REQUIRED;
        $state->last_source_message_id = (int) $sourceMessage->id;
        $state->last_reason = $reason;
        $state->handoff_reason = $reason;
        $state->handoff_at = $state->handoff_at ?: time();
        $state->last_activity_at = time();
        $state->save();
        $this->recordEvent($state, 'handoff', $reason, [
            'source_message_id' => (int) $sourceMessage->id,
        ]);
    }

    private function stateFor(Ticket $ticket): ?TicketAiConversation
    {
        try {
            $state = TicketAiConversation::query()->firstOrCreate(
                ['ticket_id' => (int) $ticket->id],
                array_merge($this->scopeFields($ticket), [
                    'status' => TicketAiConversation::STATUS_ACTIVE,
                    'last_activity_at' => time(),
                ])
            );
            $state->fill($this->scopeFields($ticket));
            if ($state->isDirty(['scope_type', 'site_id', 'agent_user_id', 'agent_domain_id'])) {
                $state->save();
            }

            return $state;
        } catch (\Throwable $e) {
            Log::warning('ticket AI conversation state unavailable', [
                'ticket_id' => (int) $ticket->id,
                'message' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /** @param array<string, mixed> $extra */
    private function recordEvent(
        TicketAiConversation $state,
        string $event,
        ?string $reason,
        array $extra = []
    ): void {
        TicketAiConversationEvent::query()->create(array_merge(
            $this->scopeFieldsFromState($state),
            [
                'conversation_id' => (int) $state->id,
                'ticket_id' => (int) $state->ticket_id,
                'event' => $event,
                'reason' => $reason,
            ],
            $extra
        ));
    }

    /** @return array<string, mixed> */
    private function scopeFields(Ticket $ticket): array
    {
        $agentUserId = (int) ($ticket->agent_user_id ?? 0) ?: null;
        $siteId = (int) ($ticket->site_id ?? 0) ?: null;

        return [
            'scope_type' => $agentUserId !== null ? 'agent' : ($siteId !== null ? 'site' : 'platform'),
            'site_id' => $siteId,
            'agent_user_id' => $agentUserId,
            'agent_domain_id' => (int) ($ticket->agent_domain_id ?? 0) ?: null,
        ];
    }

    /** @return array<string, mixed> */
    private function scopeFieldsFromState(TicketAiConversation $state): array
    {
        return [
            'scope_type' => (string) $state->scope_type,
            'site_id' => $state->site_id,
            'agent_user_id' => $state->agent_user_id,
            'agent_domain_id' => $state->agent_domain_id,
        ];
    }

    private function normalizedMessage(TicketMessage $message): string
    {
        return mb_strtolower(trim(strip_tags((string) $message->message)));
    }

    /** @param array<int, string> $patterns */
    private function containsAny(string $value, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (str_contains($value, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, array{reason:string,total:int}> */
    private function groupReasons($query): array
    {
        return $query
            ->whereNotNull('reason')
            ->select('reason', DB::raw('COUNT(*) as total'))
            ->groupBy('reason')
            ->orderByDesc('total')
            ->limit(8)
            ->get()
            ->map(fn ($row): array => [
                'reason' => (string) $row->reason,
                'total' => (int) $row->total,
            ])
            ->all();
    }

    private function available(): bool
    {
        try {
            $schema = app('db')->connection()->getSchemaBuilder();
            return $schema->hasTable('v2_ticket_ai_conversation')
                && $schema->hasTable('v2_ticket_ai_conversation_event');
        } catch (\Throwable) {
            return false;
        }
    }
}
