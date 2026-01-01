<?php

namespace App\Console\Commands;

use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\TicketMessageAttachment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CleanupTicket extends Command
{
    protected $signature = 'cleanup:ticket {--days= : Retention days (default from config)} {--dry-run : Show what would be deleted}';
    protected $description = '删除超过保留期的已关闭工单（含消息与附件）';

    public function handle(): int
    {
        ini_set('memory_limit', -1);

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

        $baseQuery->chunkById(100, function ($tickets) use ($dryRun, &$deletedTickets, &$deletedMessages, &$deletedAttachments, &$deletedFiles) {
            foreach ($tickets as $ticket) {
                $ticketId = (int) $ticket->id;

                $attachments = TicketMessageAttachment::where('ticket_id', $ticketId)->get(['id', 'disk', 'path']);
                $messagesCount = TicketMessage::where('ticket_id', $ticketId)->count();

                if ($dryRun) {
                    $this->line(sprintf('Would delete ticket #%d (messages=%d, attachments=%d)', $ticketId, $messagesCount, $attachments->count()));
                    continue;
                }

                foreach ($attachments as $attachment) {
                    $disk = $attachment->disk ?: 'local';
                    $path = $attachment->path;
                    if ($path && Storage::disk($disk)->exists($path)) {
                        if (Storage::disk($disk)->delete($path)) {
                            $deletedFiles++;
                        }
                    }
                }

                DB::beginTransaction();
                try {
                    $deletedAttachments += TicketMessageAttachment::where('ticket_id', $ticketId)->delete();
                    $deletedMessages += TicketMessage::where('ticket_id', $ticketId)->delete();
                    $deletedTickets += Ticket::where('id', $ticketId)->delete();
                    DB::commit();
                } catch (\Exception $e) {
                    DB::rollBack();
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

