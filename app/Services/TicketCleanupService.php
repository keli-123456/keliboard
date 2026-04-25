<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\TicketMessageAttachment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class TicketCleanupService
{
    /**
     * @param array<int, int|string> $ticketIds
     * @return Collection<int, TicketMessageAttachment>
     */
    public function collectAttachmentsByTicketIds(array $ticketIds): Collection
    {
        $ticketIds = $this->normalizeIds($ticketIds);
        if ($ticketIds === []) {
            return collect();
        }

        return TicketMessageAttachment::query()
            ->whereIn('ticket_id', $ticketIds)
            ->get(['id', 'disk', 'path', 'mime']);
    }

    /**
     * Delete ticket-related database rows. Call this inside the caller's transaction.
     *
     * @param array<int, int|string> $ticketIds
     * @return array{tickets:int,messages:int,attachments:int}
     */
    public function deleteRowsByTicketIds(array $ticketIds): array
    {
        $ticketIds = $this->normalizeIds($ticketIds);
        if ($ticketIds === []) {
            return ['tickets' => 0, 'messages' => 0, 'attachments' => 0];
        }

        $deletedAttachments = TicketMessageAttachment::query()
            ->whereIn('ticket_id', $ticketIds)
            ->delete();
        $deletedMessages = TicketMessage::query()
            ->whereIn('ticket_id', $ticketIds)
            ->delete();
        $deletedTickets = Ticket::query()
            ->whereIn('id', $ticketIds)
            ->delete();

        return [
            'tickets' => (int) $deletedTickets,
            'messages' => (int) $deletedMessages,
            'attachments' => (int) $deletedAttachments,
        ];
    }

    /**
     * Delete physical attachment files after database changes have committed.
     *
     * @param iterable<TicketMessageAttachment> $attachments
     */
    public function deleteAttachmentFiles(iterable $attachments): int
    {
        $deletedFiles = 0;
        $attachmentService = app(TicketAttachmentService::class);
        $defaultDisk = (string) config('tickets.attachments.disk', 'local');

        foreach ($attachments as $attachment) {
            $disk = (string) ($attachment->disk ?: $defaultDisk);
            $path = (string) ($attachment->path ?? '');
            if ($path === '') {
                continue;
            }

            try {
                $deletedFiles += $attachmentService->deleteDerivedFiles($disk, $path, $attachment->mime ?? null);
                if (Storage::disk($disk)->exists($path) && Storage::disk($disk)->delete($path)) {
                    $deletedFiles++;
                }
            } catch (Throwable $e) {
                Log::warning('Failed to delete ticket attachment file', [
                    'attachment_id' => (int) ($attachment->id ?? 0),
                    'disk' => $disk,
                    'path' => $path,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $deletedFiles;
    }

    /**
     * @param array<int, int|string> $ids
     * @return array<int, int>
     */
    private function normalizeIds(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn ($id) => (int) $id,
            $ids
        ), static fn (int $id) => $id > 0)));
    }
}
