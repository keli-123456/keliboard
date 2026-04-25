<?php

namespace App\Console\Commands;

use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Services\TicketCleanupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupTicket extends Command
{
    protected $signature = 'cleanup:ticket {--days= : Retention days (default from config)} {--dry-run : Show what would be deleted}';
    protected $description = '删除超过保留期的已关闭工单（含消息与附件）';

    public function handle(TicketCleanupService $ticketCleanupService): int
    {
        $days = $this->option('days');
        $days = is_numeric($days) ? (int) $days : (int) config('tickets.retention_days', 90);
        if ($days <= 0) {
            $this->error('Invalid days option');
            return self::FAILURE;
        }

        $cutoff = time() - ($days * 86400);
        $dryRun = (bool) $this->option('dry-run');

        $baseQuery = Ticket::query()
            ->where('status', Ticket::STATUS_CLOSED)
            ->where('updated_at', '<=', $cutoff)
            ->orderBy('id', 'asc');

        $total = (clone $baseQuery)->count();
        $this->info(sprintf('Matched %d tickets (<= %d days)', $total, $days));

        if ($total === 0) {
            return self::SUCCESS;
        }

        $deletedTickets = 0;
        $deletedMessages = 0;
        $deletedAttachments = 0;
        $deletedFiles = 0;

        $baseQuery->chunkById(100, function ($tickets) use ($ticketCleanupService, $dryRun, &$deletedTickets, &$deletedMessages, &$deletedAttachments, &$deletedFiles) {
            foreach ($tickets as $ticket) {
                $ticketId = (int) $ticket->id;

                $attachments = $ticketCleanupService->collectAttachmentsByTicketIds([$ticketId]);
                $messagesCount = TicketMessage::where('ticket_id', $ticketId)->count();

                if ($dryRun) {
                    $this->line(sprintf('Would delete ticket #%d (messages=%d, attachments=%d)', $ticketId, $messagesCount, $attachments->count()));
                    continue;
                }

                DB::beginTransaction();
                try {
                    $deleted = $ticketCleanupService->deleteRowsByTicketIds([$ticketId]);
                    $deletedAttachments += $deleted['attachments'];
                    $deletedMessages += $deleted['messages'];
                    $deletedTickets += $deleted['tickets'];
                    DB::commit();
                    $deletedFiles += $ticketCleanupService->deleteAttachmentFiles($attachments);
                } catch (\Throwable $e) {
                    if (DB::transactionLevel() > 0) {
                        DB::rollBack();
                    }
                    $this->error(sprintf('Failed deleting ticket #%d: %s', $ticketId, $e->getMessage()));
                }
            }
        });

        if ($dryRun) {
            $this->info('Dry run completed.');
            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Deleted tickets=%d, messages=%d, attachments=%d, files=%d',
            $deletedTickets,
            $deletedMessages,
            $deletedAttachments,
            $deletedFiles
        ));

        return self::SUCCESS;
    }
}
