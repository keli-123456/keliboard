<?php

namespace App\Jobs;

use App\Services\TelegramService;
use App\Services\TicketService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProcessTelegramTicketMediaGroupReplyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected int $chatId;
    protected string $mediaGroupId;

    public $tries = 5;
    public $timeout = 60;

    public function __construct(int $chatId, string $mediaGroupId)
    {
        $this->onQueue('send_telegram');
        $this->chatId = $chatId;
        $this->mediaGroupId = $mediaGroupId;
    }

    public function handle(): void
    {
        $cacheKey = $this->cacheKey();
        $data = Cache::get($cacheKey);
        if (!is_array($data)) {
            return;
        }

        $waitSeconds = 2;
        $updatedAt = isset($data['updated_at']) && is_numeric($data['updated_at']) ? (int) $data['updated_at'] : 0;
        if ($updatedAt > 0 && (time() - $updatedAt) < $waitSeconds) {
            $this->release($waitSeconds);
            return;
        }

        $lockKey = "{$cacheKey}:lock";
        $acquired = $this->acquireLock($lockKey, 30);
        if (!$acquired) {
            $this->release(1);
            return;
        }

        $tempPaths = [];
        try {
            $data = Cache::get($cacheKey);
            if (!is_array($data)) {
                return;
            }

            $updatedAt = isset($data['updated_at']) && is_numeric($data['updated_at']) ? (int) $data['updated_at'] : 0;
            if ($updatedAt > 0 && (time() - $updatedAt) < $waitSeconds) {
                $this->release($waitSeconds);
                return;
            }

            $ticketId = isset($data['ticket_id']) && is_numeric($data['ticket_id']) ? (int) $data['ticket_id'] : 0;
            $userId = isset($data['user_id']) && is_numeric($data['user_id']) ? (int) $data['user_id'] : 0;
            $text = is_string($data['text'] ?? null) ? (string) $data['text'] : '';

            $maxImages = (int) config('tickets.attachments.max_images', 3);
            $maxKb = (int) config('tickets.attachments.max_kb', 5120);

            $images = $data['images'] ?? [];
            $images = is_array($images) ? array_values($images) : [];
            if ($maxImages > 0) {
                $images = array_slice($images, 0, $maxImages);
            }

            $telegram = new TelegramService();
            $files = [];
            foreach ($images as $meta) {
                if (!is_array($meta)) {
                    continue;
                }
                $fileId = $meta['file_id'] ?? null;
                if (!is_string($fileId) || $fileId === '') {
                    continue;
                }
                $fileSize = $meta['file_size'] ?? null;
                if (is_numeric($fileSize) && (int) $fileSize > ($maxKb * 1024)) {
                    continue;
                }
                $preferredName = isset($meta['file_name']) && is_string($meta['file_name']) ? $meta['file_name'] : null;

                $downloaded = $telegram->downloadFileToTemp($fileId, $preferredName);
                $tempPaths[] = $downloaded['path'];

                $downloadedSize = @filesize($downloaded['path']);
                if (is_int($downloadedSize) && $downloadedSize > ($maxKb * 1024)) {
                    continue;
                }

                $files[] = new UploadedFile($downloaded['path'], $downloaded['filename'], null, null, true);
            }

            if (trim($text) === '' && empty($files)) {
                Cache::forget($cacheKey);
                return;
            }

            if ($ticketId <= 0 || $userId <= 0) {
                Cache::forget($cacheKey);
                $telegram->sendMessage($this->chatId, '工单回复失败：参数错误');
                return;
            }

            $ticketService = new TicketService();
            $ticketService->replyByAdmin($ticketId, $text, $userId, $files);

            Cache::forget($cacheKey);
            $telegram->sendMessage($this->chatId, "工单 #{$ticketId} 回复成功");
        } catch (\Exception $e) {
            Log::error('Telegram media-group ticket reply failed', [
                'chat_id' => $this->chatId,
                'media_group_id' => $this->mediaGroupId,
                'error' => $e->getMessage(),
            ]);

            try {
                $telegram = new TelegramService();
                $telegram->sendMessage($this->chatId, '工单回复失败，请重试');
            } catch (\Exception) {
            }
        } finally {
            foreach ($tempPaths as $p) {
                try {
                    if (is_string($p) && $p !== '' && is_file($p)) {
                        @unlink($p);
                    }
                } catch (\Exception) {
                }
            }
            $this->releaseLock($lockKey, $acquired);
        }
    }

    protected function cacheKey(): string
    {
        $group = trim($this->mediaGroupId);
        return "tg_ticket_media_group_reply:{$this->chatId}:{$group}";
    }

    protected function acquireLock(string $key, int $seconds): bool
    {
        try {
            return (bool) Cache::add($key, 1, $seconds);
        } catch (\Exception) {
            if (Cache::has($key)) {
                return false;
            }
            Cache::put($key, 1, $seconds);
            return true;
        }
    }

    protected function releaseLock(string $key, bool $acquired): void
    {
        if (!$acquired) {
            return;
        }
        try {
            Cache::forget($key);
        } catch (\Exception) {
        }
    }
}

