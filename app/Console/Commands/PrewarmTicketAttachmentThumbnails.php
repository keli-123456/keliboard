<?php

namespace App\Console\Commands;

use App\Models\TicketMessageAttachment;
use App\Services\TicketAttachmentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class PrewarmTicketAttachmentThumbnails extends Command
{
    protected $signature = 'ticket:prewarm-thumbnails
        {--chunk=100 : Chunk size for each batch}
        {--limit=0 : Maximum attachment rows to process, 0 means no limit}
        {--force : Rebuild thumbnails even if they already exist}
        {--dry-run : Only count/process candidates without writing files}
        {--report= : Optional JSON report path, relative paths are resolved from storage/}';

    protected $description = '为历史工单附件预热或重建缩略图';

    public function handle(TicketAttachmentService $attachmentService): int
    {
        $chunk = max(10, (int) $this->option('chunk'));
        $limit = max(0, (int) $this->option('limit'));
        $force = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run');
        $reportPath = $this->resolveReportPath((string) $this->option('report'));

        $baseQuery = TicketMessageAttachment::query()
            ->orderBy('id', 'asc');

        $matched = (clone $baseQuery)->count();
        $targetTotal = $limit > 0 ? min($matched, $limit) : $matched;
        $this->info(sprintf(
            'Matched %d attachments, target=%d, chunk=%d, force=%s, dry-run=%s',
            $matched,
            $targetTotal,
            $chunk,
            $force ? 'yes' : 'no',
            $dryRun ? 'yes' : 'no'
        ));

        if ($targetTotal === 0) {
            return self::SUCCESS;
        }

        $processed = 0;
        $warmed = 0;
        $skipped = 0;
        $failed = 0;
        $deletedDerived = 0;
        $failedItems = [];

        $baseQuery->chunkById($chunk, function ($attachments) use (
            $attachmentService,
            $dryRun,
            $force,
            $limit,
            &$processed,
            &$warmed,
            &$skipped,
            &$failed,
            &$deletedDerived,
            &$failedItems
        ) {
            foreach ($attachments as $attachment) {
                if ($limit > 0 && $processed >= $limit) {
                    return false;
                }

                $processed++;

                $mime = $attachment->mime ?: null;
                $looksLikeImage = !$mime || str_starts_with(strtolower((string) $mime), 'image/');
                if (!$looksLikeImage) {
                    $skipped++;
                    continue;
                }

                if ($dryRun) {
                    $warmed++;
                    continue;
                }

                try {
                    if ($force) {
                        $deletedDerived += $attachmentService->deleteDerivedFiles($attachment->disk, $attachment->path, $mime);
                    }

                    $thumbnail = $attachmentService->ensureThumbnail($attachment->disk, $attachment->path, $mime);
                    if ($thumbnail) {
                        $warmed++;
                    } else {
                        $skipped++;
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    if (count($failedItems) < 50) {
                        $failedItems[] = [
                            'id' => (int) $attachment->id,
                            'disk' => (string) $attachment->disk,
                            'path' => (string) $attachment->path,
                            'message' => $e->getMessage(),
                        ];
                    }
                    $this->warn(sprintf('Attachment #%d failed: %s', $attachment->id, $e->getMessage()));
                }
            }

            if ($limit > 0 && $processed >= $limit) {
                return false;
            }

            $this->line(sprintf('Progress: processed=%d warmed=%d skipped=%d failed=%d', $processed, $warmed, $skipped, $failed));
            return null;
        });

        $this->info(sprintf(
            'Finished: processed=%d warmed=%d skipped=%d failed=%d deleted_derived=%d',
            $processed,
            $warmed,
            $skipped,
            $failed,
            $deletedDerived
        ));

        if ($failedItems) {
            $sample = implode(', ', array_map(
                static fn (array $item): string => (string) $item['id'],
                array_slice($failedItems, 0, 10)
            ));
            $this->warn(sprintf('Failed attachment ids (sample): %s', $sample));
        }

        if ($reportPath) {
            $payload = [
                'processed' => $processed,
                'warmed' => $warmed,
                'skipped' => $skipped,
                'failed' => $failed,
                'deleted_derived' => $deletedDerived,
                'dry_run' => $dryRun,
                'force' => $force,
                'chunk' => $chunk,
                'limit' => $limit,
                'generated_at' => now()->toIso8601String(),
                'failed_items' => $failedItems,
            ];
            File::ensureDirectoryExists(dirname($reportPath));
            File::put($reportPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->info(sprintf('Report written to %s', $reportPath));
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function resolveReportPath(string $report): ?string
    {
        $trimmed = trim($report);
        if ($trimmed === '') {
            return null;
        }

        if (str_starts_with($trimmed, DIRECTORY_SEPARATOR)) {
            return $trimmed;
        }

        return storage_path($trimmed);
    }
}
