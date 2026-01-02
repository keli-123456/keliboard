<?php

namespace App\Http\Controllers\V1\Guest;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Plugin\HookManager;
use App\Services\TelegramService;
use App\Services\UserService;
use Illuminate\Http\Request;

class TelegramController extends Controller
{
    protected ?object $msg = null;
    protected TelegramService $telegramService;
    protected UserService $userService;

    public function __construct(TelegramService $telegramService, UserService $userService)
    {
        $this->telegramService = $telegramService;
        $this->userService = $userService;
    }

    public function webhook(Request $request): void
    {
        $expectedToken = md5(admin_setting('telegram_bot_token'));
        if ($request->input('access_token') !== $expectedToken) {
            throw new ApiException('access_token is error', 401);
        }

        $data = $request->json()->all();

        $this->formatMessage($data);
        $this->formatChatJoinRequest($data);
        $this->handle();
    }

    private function handle(): void
    {
        if (!$this->msg)
            return;
        $msg = $this->msg;
        $this->processBotName($msg);
        try {
            HookManager::call('telegram.message.before', [$msg]);
            $handled = HookManager::filter('telegram.message.handle', false, [$msg]);
            if (!$handled) {
                HookManager::call('telegram.message.unhandled', [$msg]);
            }
            HookManager::call('telegram.message.after', [$msg]);
        } catch (\Exception $e) {
            HookManager::call('telegram.message.error', [$msg, $e]);
            $this->telegramService->sendMessage($msg->chat_id, $e->getMessage());
        }
    }

    private function processBotName(object $msg): void
    {
        $commandParts = explode('@', $msg->command);

        if (count($commandParts) === 2) {
            $botName = $this->getBotName();
            if ($commandParts[1] === $botName) {
                $msg->command = $commandParts[0];
            }
        }
    }

    private function getBotName(): string
    {
        $response = $this->telegramService->getMe();
        return $response->result->username;
    }

    private function formatMessage(array $data): void
    {
        $message = $data['message'] ?? null;
        if (!$message || !is_array($message)) {
            return;
        }

        $isReply = isset($message['reply_to_message']) && is_array($message['reply_to_message']);
        $hasText = isset($message['text']) && is_string($message['text']);
        $hasCaption = isset($message['caption']) && is_string($message['caption']);
        $hasPhoto = isset($message['photo']) && is_array($message['photo']) && !empty($message['photo']);
        $hasDoc = isset($message['document']) && is_array($message['document']) && isset($message['document']['file_id']);

        // Only handle:
        // - normal text messages (commands)
        // - reply messages that include text/caption/images (ticket replies)
        if (!$isReply && !$hasText) {
            return;
        }
        if ($isReply && !$hasText && !$hasCaption && !$hasPhoto && !$hasDoc) {
            return;
        }

        $textContent = '';
        if ($hasText) {
            $textContent = $message['text'];
        } elseif ($hasCaption) {
            $textContent = $message['caption'];
        }

        $parts = $textContent !== '' ? explode(' ', $textContent) : [''];
        $this->msg = (object) [
            'command' => $parts[0] ?? '',
            'args' => array_slice($parts, 1),
            'chat_id' => $message['chat']['id'],
            'message_id' => $message['message_id'],
            'message_type' => $isReply ? 'reply_message' : 'message',
            'text' => $textContent,
            'is_private' => ($message['chat']['type'] ?? '') === 'private',
        ];

        if (isset($message['media_group_id']) && (is_string($message['media_group_id']) || is_numeric($message['media_group_id']))) {
            $this->msg->media_group_id = (string) $message['media_group_id'];
        }

        if ($isReply) {
            $reply = $message['reply_to_message'];
            $replyText = '';
            if (isset($reply['text']) && is_string($reply['text'])) {
                $replyText = $reply['text'];
            } elseif (isset($reply['caption']) && is_string($reply['caption'])) {
                $replyText = $reply['caption'];
            }

            $this->msg->reply_text = $replyText;
            if (isset($reply['message_id']) && is_numeric($reply['message_id'])) {
                $this->msg->reply_message_id = (int) $reply['message_id'];
            }
        }

        $images = [];
        if ($hasPhoto) {
            $largest = end($message['photo']);
            if (is_array($largest) && isset($largest['file_id'])) {
                $images[] = [
                    'type' => 'photo',
                    'file_id' => $largest['file_id'],
                    'file_unique_id' => $largest['file_unique_id'] ?? null,
                    'file_size' => $largest['file_size'] ?? null,
                    'width' => $largest['width'] ?? null,
                    'height' => $largest['height'] ?? null,
                ];
            }
        }

        if ($hasDoc) {
            $mime = $message['document']['mime_type'] ?? null;
            if (is_string($mime) && str_starts_with($mime, 'image/')) {
                $images[] = [
                    'type' => 'document',
                    'file_id' => $message['document']['file_id'],
                    'file_unique_id' => $message['document']['file_unique_id'] ?? null,
                    'file_size' => $message['document']['file_size'] ?? null,
                    'file_name' => $message['document']['file_name'] ?? null,
                    'mime_type' => $mime,
                ];
            }
        }

        if (!empty($images)) {
            $this->msg->images = $images;
        }
    }

    private function formatChatJoinRequest(array $data): void
    {
        $joinRequest = $data['chat_join_request'] ?? null;
        if (!$joinRequest)
            return;

        $chatId = $joinRequest['chat']['id'] ?? null;
        $userId = $joinRequest['from']['id'] ?? null;

        if (!$chatId || !$userId)
            return;

        $user = User::where('telegram_id', $userId)->first();

        if (!$user) {
            $this->telegramService->declineChatJoinRequest($chatId, $userId);
            return;
        }

        if (!$this->userService->isAvailable($user)) {
            $this->telegramService->declineChatJoinRequest($chatId, $userId);
            return;
        }

        $this->telegramService->approveChatJoinRequest($chatId, $userId);
    }
}
