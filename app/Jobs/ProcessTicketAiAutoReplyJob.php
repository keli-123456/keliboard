<?php

namespace App\Jobs;

use App\Services\TicketAiAutoReplyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessTicketAiAutoReplyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 150;
    public array $backoff = [10, 30];

    public function __construct(
        private int $ticketId,
        private int $sourceMessageId,
        private bool $isNewTicket
    ) {
        $this->onQueue('ticket_ai');
    }

    public function handle(TicketAiAutoReplyService $service): void
    {
        $service->process($this->ticketId, $this->sourceMessageId, $this->isNewTicket);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('ticket AI auto-reply job exhausted retries', [
            'ticket_id' => $this->ticketId,
            'source_message_id' => $this->sourceMessageId,
            'is_new_ticket' => $this->isNewTicket,
            'exception' => $exception ? $exception::class : null,
            'message' => $exception?->getMessage(),
        ]);
    }
}
