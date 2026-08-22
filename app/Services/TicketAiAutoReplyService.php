<?php

namespace App\Services;

use App\Exceptions\TicketAiProviderException;
use App\Models\Ticket;
use App\Models\TicketMessage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class TicketAiAutoReplyService
{
    private const DEFAULT_ALLOWED_CATEGORIES = ['客户端连接', '订阅与节点', '套餐订单'];
    private const MODE_BROAD = 'broad';
    private const MODE_STRICT = 'strict';
    private const WITHDRAWAL_PATTERNS = [
        '提现', '提取佣金', '佣金提取', 'withdraw', 'payout', 'cash out',
    ];

    public function __construct(
        private ?TicketAiAssistantService $assistant = null,
        private ?TicketService $ticketService = null,
        private ?TicketAiConversationService $conversation = null
    ) {
        $this->assistant ??= app(TicketAiAssistantService::class);
        $this->ticketService ??= app(TicketService::class);
        $this->conversation ??= app(TicketAiConversationService::class);
    }

    public function process(int $ticketId, int $sourceMessageId, bool $isNewTicket): void
    {
        if (!$this->isEnabled($isNewTicket)) {
            return;
        }

        try {
            $lock = Cache::lock('ticket_ai_auto_reply:' . $ticketId, 180);
            if (!$lock->get()) {
                return;
            }
            try {
                $this->processLocked($ticketId, $sourceMessageId);
            } finally {
                $lock->release();
            }
        } catch (\Throwable $e) {
            Log::warning('ticket AI auto-reply failed', [
                'ticket_id' => $ticketId,
                'source_message_id' => $sourceMessageId,
                'message' => $e->getMessage(),
            ]);

            if ($this->shouldRetry($e)) {
                throw $e;
            } else {
                try {
                    $this->conversation->recordFailure($ticketId, $sourceMessageId, 'automation_exception');
                } catch (\Throwable $trackingError) {
                    Log::warning('ticket AI automation failure tracking failed', [
                        'ticket_id' => $ticketId,
                        'message' => $trackingError->getMessage(),
                    ]);
                }
            }
        }
    }

    public function rejectionReason(array $result): ?string
    {
        if (!(bool) ($result['structured_output'] ?? false)) {
            return 'unstructured_output';
        }
        if (trim((string) ($result['draft'] ?? '')) === '') {
            return 'empty_draft';
        }
        if ($this->automationMode() === self::MODE_BROAD) {
            return null;
        }
        if (strtolower((string) ($result['risk'] ?? '')) !== 'low') {
            return 'risk_not_low';
        }
        if ((bool) ($result['needs_human'] ?? true)) {
            return 'needs_human';
        }
        if ((float) ($result['confidence'] ?? 0) < $this->minimumConfidence()) {
            return 'low_confidence';
        }
        if (!in_array((string) ($result['category'] ?? ''), $this->allowedCategories(), true)) {
            return 'category_not_allowed';
        }
        if (
            (bool) admin_setting('ticket_ai_auto_reply_require_knowledge', true)
            && count((array) ($result['matched_knowledge'] ?? [])) === 0
            && !(bool) ($result['system_grounded'] ?? false)
        ) {
            return 'knowledge_not_matched';
        }

        return null;
    }

    public function exclusionReason(Ticket $ticket, TicketMessage $sourceMessage): ?string
    {
        $content = mb_strtolower(trim(
            (string) ($ticket->subject ?? '') . "\n" . (string) ($sourceMessage->message ?? '')
        ));
        foreach (self::WITHDRAWAL_PATTERNS as $pattern) {
            if (str_contains($content, $pattern)) {
                return 'withdrawal_excluded';
            }
        }

        return null;
    }

    private function processLocked(int $ticketId, int $sourceMessageId): void
    {
        $ticket = Ticket::query()->find($ticketId);
        $sourceMessage = TicketMessage::query()
            ->where('id', $sourceMessageId)
            ->where('ticket_id', $ticketId)
            ->first();

        if (!$ticket || !$sourceMessage || !$this->canGenerate($ticket, $sourceMessage)) {
            return;
        }

        $exclusion = $this->exclusionReason($ticket, $sourceMessage);
        if ($exclusion !== null) {
            $this->conversation->recordRejected($ticket, $sourceMessage, null, $exclusion);
            Log::info('ticket AI auto-reply excluded by policy', [
                'ticket_id' => $ticketId,
                'source_message_id' => $sourceMessageId,
                'reason' => $exclusion,
            ]);
            return;
        }
        if ($this->automationMode() === self::MODE_BROAD) {
            $this->conversation->resumeBroadAutomation($ticket);
        }

        $preflight = $this->conversation->preflight($ticket, $sourceMessage);
        if (!$preflight['allow']) {
            return;
        }

        $clarification = $this->conversation->clarification($ticket, $sourceMessage);
        $isClarification = $clarification !== null;
        $result = $isClarification
            ? $this->assistant->suggestClarification($ticket, $clarification)
            : $this->assistant->suggest($ticket);
        $suggestionId = isset($result['suggestion_id']) ? (int) $result['suggestion_id'] : null;
        if ($this->conversation->isDuplicateDraft($ticket, (string) ($result['draft'] ?? ''))) {
            $this->assistant->discardAutomationSuggestion($suggestionId, $ticketId);
            $this->conversation->recordRejected($ticket, $sourceMessage, $suggestionId, 'duplicate_reply');
            return;
        }
        $rejection = $this->rejectionReason($result);
        if ($rejection !== null) {
            $this->assistant->discardAutomationSuggestion($suggestionId, $ticketId);
            $this->conversation->recordRejected($ticket, $sourceMessage, $suggestionId, $rejection);
            Log::info('ticket AI auto-reply held for review', [
                'ticket_id' => $ticketId,
                'source_message_id' => $sourceMessageId,
                'reason' => $rejection,
                'category' => $result['category'] ?? null,
                'risk' => $result['risk'] ?? null,
                'confidence' => $result['confidence'] ?? null,
            ]);
            return;
        }

        $sent = $this->ticketService->replyByAiAutomation(
            $ticketId,
            $sourceMessageId,
            (int) $suggestionId,
            (string) $result['category'],
            (string) $result['draft']
        );
        if (!$sent) {
            $this->assistant->discardAutomationSuggestion($suggestionId, $ticketId);
            $this->conversation->recordRejected($ticket, $sourceMessage, $suggestionId, 'send_conflict');
            return;
        }
        try {
            $this->conversation->recordSent($ticket, $sourceMessage, $sent, $result, $isClarification);
        } catch (\Throwable $trackingError) {
            Log::warning('ticket AI sent-state tracking failed', [
                'ticket_id' => $ticketId,
                'message' => $trackingError->getMessage(),
            ]);
        }
    }

    private function isEnabled(bool $isNewTicket): bool
    {
        if (!(bool) admin_setting('ticket_ai_enable', false)) {
            return false;
        }
        if (!(bool) admin_setting('ticket_ai_auto_reply_enable', false)) {
            return false;
        }

        return $isNewTicket || (bool) admin_setting('ticket_ai_auto_reply_on_user_reply', true);
    }

    private function canGenerate(Ticket $ticket, TicketMessage $sourceMessage): bool
    {
        if ((int) $ticket->status !== Ticket::STATUS_OPENING) {
            return false;
        }
        if ((int) $ticket->reply_status !== Ticket::REPLY_STATUS_WAITING_ADMIN) {
            return false;
        }
        if ((int) $sourceMessage->user_id !== (int) $ticket->user_id) {
            return false;
        }

        $latestMessageId = (int) TicketMessage::query()
            ->where('ticket_id', $ticket->id)
            ->max('id');

        return $latestMessageId === (int) $sourceMessage->id;
    }

    private function minimumConfidence(): float
    {
        return max(0.5, min(1.0, (float) admin_setting('ticket_ai_auto_reply_min_confidence', 0.9)));
    }

    private function automationMode(): string
    {
        $mode = strtolower(trim((string) admin_setting('ticket_ai_auto_reply_mode', self::MODE_BROAD)));

        return $mode === self::MODE_STRICT ? self::MODE_STRICT : self::MODE_BROAD;
    }

    /** @return array<int, string> */
    private function allowedCategories(): array
    {
        $value = admin_setting('ticket_ai_auto_reply_allowed_categories', self::DEFAULT_ALLOWED_CATEGORIES);
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : preg_split('/[,，\r\n]+/u', $value);
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $category): string => trim((string) $category),
            is_array($value) ? $value : []
        ))));
    }

    private function shouldRetry(\Throwable $exception): bool
    {
        if (!$exception instanceof TicketAiProviderException) {
            return false;
        }

        return in_array($exception->errorCode(), [
            'connection', 'timeout', 'rate_limited', 'upstream',
        ], true);
    }
}
