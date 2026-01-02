<?php

namespace App\Jobs;

use App\Services\TelegramService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendTelegramDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected int $telegramId;
    protected string $absolutePath;
    protected string $filename;
    protected ?string $caption;
    protected ?string $parseMode;

    public $tries = 3;
    public $timeout = 30;

    public function __construct(int $telegramId, string $absolutePath, string $filename, ?string $caption = null, ?string $parseMode = null)
    {
        $this->onQueue('send_telegram');
        $this->telegramId = $telegramId;
        $this->absolutePath = $absolutePath;
        $this->filename = $filename;
        $this->caption = $caption;
        $this->parseMode = $parseMode;
    }

    public function handle(): void
    {
        if (!is_file($this->absolutePath)) {
            Log::warning('Telegram document send skipped: file not found', [
                'telegram_id' => $this->telegramId,
                'path' => $this->absolutePath,
            ]);
            return;
        }

        $telegramService = new TelegramService();
        $telegramService->sendDocument(
            $this->telegramId,
            $this->absolutePath,
            $this->filename,
            $this->caption,
            $this->parseMode ?: ''
        );
    }
}

