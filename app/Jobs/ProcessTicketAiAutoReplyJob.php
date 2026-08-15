<?php

namespace App\Jobs;

use App\Services\TicketAiAutoReplyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessTicketAiAutoReplyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 150;

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
}
