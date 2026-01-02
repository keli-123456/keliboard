<?php

namespace App\Jobs;

use App\Services\TelegramService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SendTelegramMediaGroupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected int $telegramId;
    /**
     * @var array<int, array{path:string,filename:string}>
     */
    protected array $files;
    protected ?string $caption;
    protected ?int $ticketId;

    public $tries = 3;
    public $timeout = 30;

    /**
     * @param array<int, array{path:string,filename:string}> $files
     */
    public function __construct(int $telegramId, array $files, ?string $caption = null, ?int $ticketId = null)
    {
        $this->onQueue('send_telegram');
        $this->telegramId = $telegramId;
        $this->files = $files;
        $this->caption = $caption;
        $this->ticketId = $ticketId;
    }

    public function handle(): void
    {
        $files = array_values(array_filter($this->files, fn($it) => is_array($it) && !empty($it['path'])));
        if (empty($files)) {
            return;
        }

        $telegramService = new TelegramService();

        try {
            $messageIds = $telegramService->sendMediaGroupPhotos($this->telegramId, $files, $this->caption);
            $this->rememberTicketMappings($messageIds);
            return;
        } catch (\Exception $e) {
            Log::warning('Telegram sendMediaGroup failed, fallback to sendPhoto', [
                'telegram_id' => $this->telegramId,
                'count' => count($files),
                'error' => $e->getMessage(),
            ]);
        }

        foreach ($files as $file) {
            try {
                $messageId = $telegramService->sendPhoto(
                    $this->telegramId,
                    (string) $file['path'],
                    (string) ($file['filename'] ?? basename((string) $file['path'])),
                    $this->caption,
                    ''
                );
                $this->rememberTicketMappings([$messageId]);
            } catch (\Exception $e) {
                Log::warning('Telegram sendPhoto fallback failed, fallback to sendDocument', [
                    'telegram_id' => $this->telegramId,
                    'filename' => $file['filename'] ?? null,
                    'error' => $e->getMessage(),
                ]);
                $messageId = $telegramService->sendDocument(
                    $this->telegramId,
                    (string) $file['path'],
                    (string) ($file['filename'] ?? basename((string) $file['path'])),
                    $this->caption,
                    ''
                );
                $this->rememberTicketMappings([$messageId]);
            }
        }
    }

    /**
     * @param array<int, int|null> $messageIds
     */
    protected function rememberTicketMappings(array $messageIds): void
    {
        if (!$this->ticketId) {
            return;
        }
        $ticketId = (int) $this->ticketId;
        if ($ticketId <= 0) {
            return;
        }

        $days = (int) config('tickets.retention_days', 90);
        $ttl = now()->addDays(max(1, $days));

        foreach ($messageIds as $messageId) {
            $mid = is_numeric($messageId) ? (int) $messageId : 0;
            if ($mid <= 0) {
                continue;
            }
            Cache::put($this->cacheKey($this->telegramId, $mid), $ticketId, $ttl);
        }
    }

    protected function cacheKey(int $telegramId, int $messageId): string
    {
        return "tg_ticket_reply:{$telegramId}:{$messageId}";
    }
}
