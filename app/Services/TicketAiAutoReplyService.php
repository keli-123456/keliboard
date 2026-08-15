<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\TicketMessage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class TicketAiAutoReplyService
{
    private const DEFAULT_ALLOWED_CATEGORIES = ['客户端连接', '订阅与节点'];

    public function __construct(
        private ?TicketAiAssistantService $assistant = null,
        private ?TicketService $ticketService = null
    ) {
        $this->assistant ??= app(TicketAiAssistantService::class);
        $this->ticketService ??= app(TicketService::class);
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
        }
    }

    public function rejectionReason(array $result): ?string
    {
        if (!(bool) ($result['structured_output'] ?? false)) {
            return 'unstructured_output';
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
        if (trim((string) ($result['draft'] ?? '')) === '') {
            return 'empty_draft';
        }
        if (
            (bool) admin_setting('ticket_ai_auto_reply_require_knowledge', true)
            && count((array) ($result['matched_knowledge'] ?? [])) === 0
        ) {
            return 'knowledge_not_matched';
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

        $result = $this->assistant->suggest($ticket);
        $suggestionId = isset($result['suggestion_id']) ? (int) $result['suggestion_id'] : null;
        $rejection = $this->rejectionReason($result);
        if ($rejection !== null) {
            $this->assistant->discardAutomationSuggestion($suggestionId, $ticketId);
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
        if ($sourceMessage->attachments()->exists()) {
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
}
