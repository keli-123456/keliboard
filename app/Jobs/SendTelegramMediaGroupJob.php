<?php

namespace App\Jobs;

use App\Services\TelegramService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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

    public $tries = 3;
    public $timeout = 30;

    /**
     * @param array<int, array{path:string,filename:string}> $files
     */
    public function __construct(int $telegramId, array $files, ?string $caption = null)
    {
        $this->onQueue('send_telegram');
        $this->telegramId = $telegramId;
        $this->files = $files;
        $this->caption = $caption;
    }

    public function handle(): void
    {
        $files = array_values(array_filter($this->files, fn($it) => is_array($it) && !empty($it['path'])));
        if (empty($files)) {
            return;
        }

        $telegramService = new TelegramService();

        try {
            $telegramService->sendMediaGroupPhotos($this->telegramId, $files, $this->caption);
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
                $telegramService->sendPhoto(
                    $this->telegramId,
                    (string) $file['path'],
                    (string) ($file['filename'] ?? basename((string) $file['path'])),
                    $this->caption,
                    ''
                );
            } catch (\Exception $e) {
                Log::warning('Telegram sendPhoto fallback failed, fallback to sendDocument', [
                    'telegram_id' => $this->telegramId,
                    'filename' => $file['filename'] ?? null,
                    'error' => $e->getMessage(),
                ]);
                $telegramService->sendDocument(
                    $this->telegramId,
                    (string) $file['path'],
                    (string) ($file['filename'] ?? basename((string) $file['path'])),
                    $this->caption,
                    ''
                );
            }
        }
    }
}

