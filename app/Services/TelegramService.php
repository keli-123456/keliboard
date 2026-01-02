<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Jobs\SendTelegramDocumentJob;
use App\Jobs\SendTelegramJob;
use App\Models\User;
use App\Services\Plugin\HookManager;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TelegramService
{
    protected PendingRequest $http;
    protected string $apiUrl;
    protected string $botToken;

    public function __construct(?string $token = null)
    {
        $this->botToken = (string) admin_setting('telegram_bot_token', $token);
        $this->apiUrl = "https://api.telegram.org/bot{$this->botToken}/";

        $this->http = Http::timeout(30)
            ->retry(3, 1000)
            ->withHeaders([
                'Accept' => 'application/json',
            ]);
    }

    public function sendMessage(int $chatId, string $text, string $parseMode = ''): void
    {
        $text = $parseMode === 'markdown' ? str_replace('_', '\_', $text) : $text;

        $this->request('sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => $parseMode ?: null,
        ]);
    }

    public function sendDocument(int $chatId, string $absoluteFilePath, string $filename, ?string $caption = null, string $parseMode = ''): void
    {
        if (!is_file($absoluteFilePath) || !is_readable($absoluteFilePath)) {
            throw new ApiException('Telegram 文件不存在或不可读');
        }

        $params = [
            'chat_id' => $chatId,
            'caption' => $caption ?: null,
            'parse_mode' => $parseMode ?: null,
        ];

        $resource = fopen($absoluteFilePath, 'r');
        if ($resource === false) {
            throw new ApiException('Telegram 文件打开失败');
        }

        try {
            $response = $this->http
                ->attach('document', $resource, $filename)
                ->post($this->apiUrl . 'sendDocument', array_filter($params, fn($v) => $v !== null));

            if (!$response->successful()) {
                throw new ApiException("HTTP 请求失败: {$response->status()}");
            }

            $data = $response->object();
            if (!isset($data->ok)) {
                throw new ApiException('无效的 Telegram API 响应');
            }
            if (!$data->ok) {
                $description = $data->description ?? '未知错误';
                throw new ApiException("Telegram API 错误: {$description}");
            }
        } catch (\Exception $e) {
            Log::error('Telegram API sendDocument 失败', [
                'chat_id' => $chatId,
                'filename' => $filename,
                'error' => $e->getMessage(),
            ]);
            throw new ApiException("Telegram 服务错误: {$e->getMessage()}");
        } finally {
            try {
                fclose($resource);
            } catch (\Exception) {
            }
        }
    }

    public function approveChatJoinRequest(int $chatId, int $userId): void
    {
        $this->request('approveChatJoinRequest', [
            'chat_id' => $chatId,
            'user_id' => $userId,
        ]);
    }

    public function declineChatJoinRequest(int $chatId, int $userId): void
    {
        $this->request('declineChatJoinRequest', [
            'chat_id' => $chatId,
            'user_id' => $userId,
        ]);
    }

    public function getMe(): object
    {
        return $this->request('getMe');
    }

    public function setWebhook(string $url): object
    {
        $result = $this->request('setWebhook', ['url' => $url]);
        return $result;
    }

    /**
     * 注册 Bot 命令列表
     */
    public function registerBotCommands(): void
    {
        try {
            $commands = HookManager::filter('telegram.bot.commands', []);

            if (empty($commands)) {
                Log::warning('没有找到任何 Telegram Bot 命令');
                return;
            }

            $this->request('setMyCommands', [
                'commands' => json_encode($commands),
                'scope' => json_encode(['type' => 'default'])
            ]);

            Log::info('Telegram Bot 命令注册成功', [
                'commands_count' => count($commands),
                'commands' => $commands
            ]);

        } catch (\Exception $e) {
            Log::error('Telegram Bot 命令注册失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * 获取当前注册的命令列表
     */
    public function getMyCommands(): object
    {
        return $this->request('getMyCommands');
    }

    /**
     * 删除所有命令
     */
    public function deleteMyCommands(): object
    {
        return $this->request('deleteMyCommands');
    }

    public function sendMessageWithAdmin(string $message, bool $isStaff = false): void
    {
        $query = User::where('telegram_id', '!=', null);
        $query->where(
            fn($q) => $q->where('is_admin', 1)
                ->when($isStaff, fn($q) => $q->orWhere('is_staff', 1))
        );
        $users = $query->get();
        foreach ($users as $user) {
            SendTelegramJob::dispatch($user->telegram_id, $message);
        }
    }

    public function sendDocumentWithAdmin(string $absoluteFilePath, string $filename, ?string $caption = null, bool $isStaff = false): void
    {
        $query = User::where('telegram_id', '!=', null);
        $query->where(
            fn($q) => $q->where('is_admin', 1)
                ->when($isStaff, fn($q) => $q->orWhere('is_staff', 1))
        );
        $users = $query->get();
        foreach ($users as $user) {
            SendTelegramDocumentJob::dispatch($user->telegram_id, $absoluteFilePath, $filename, $caption, 'markdown');
        }
    }

    public function getFile(string $fileId): object
    {
        return $this->request('getFile', [
            'file_id' => $fileId,
        ]);
    }

    /**
     * @return array{path:string,filename:string}
     */
    public function downloadFileToTemp(string $fileId, ?string $preferredFilename = null): array
    {
        $resp = $this->getFile($fileId);
        $filePath = $resp->result->file_path ?? null;
        if (!is_string($filePath) || $filePath === '') {
            throw new ApiException('无法获取 Telegram 文件路径');
        }

        $filename = $preferredFilename ?: basename($filePath);
        $ext = pathinfo($filename, PATHINFO_EXTENSION);

        $dir = storage_path('app/tmp/telegram');
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            throw new ApiException('临时目录不可写');
        }

        $localName = (string) Str::uuid();
        if (is_string($ext) && $ext !== '') {
            $localName .= '.' . $ext;
        }
        $absolute = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $localName;

        $downloadUrl = $this->getFileDownloadUrl($filePath);
        $response = $this->http->get($downloadUrl);
        if (!$response->successful()) {
            throw new ApiException("下载失败: {$response->status()}");
        }
        $written = @file_put_contents($absolute, (string) $response->body());
        if ($written === false) {
            throw new ApiException('保存临时文件失败');
        }

        return [
            'path' => $absolute,
            'filename' => $filename,
        ];
    }

    protected function getFileDownloadUrl(string $filePath): string
    {
        $clean = ltrim($filePath, '/');
        return "https://api.telegram.org/file/bot{$this->botToken}/{$clean}";
    }

    protected function request(string $method, array $params = []): object
    {
        try {
            $response = $this->http->get($this->apiUrl . $method, $params);

            if (!$response->successful()) {
                throw new ApiException("HTTP 请求失败: {$response->status()}");
            }

            $data = $response->object();

            if (!isset($data->ok)) {
                throw new ApiException('无效的 Telegram API 响应');
            }

            if (!$data->ok) {
                $description = $data->description ?? '未知错误';
                throw new ApiException("Telegram API 错误: {$description}");
            }

            return $data;

        } catch (\Exception $e) {
            Log::error('Telegram API 请求失败', [
                'method' => $method,
                'params' => $params,
                'error' => $e->getMessage(),
            ]);

            throw new ApiException("Telegram 服务错误: {$e->getMessage()}");
        }
    }
}
