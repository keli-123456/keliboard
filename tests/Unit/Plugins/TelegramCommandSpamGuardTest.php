<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins;

use App\Http\Controllers\V1\Guest\TelegramController;
use App\Services\TelegramService;
use App\Services\UserService;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Plugin\Telegram\Plugin as TelegramPlugin;
use ReflectionMethod;
use Tests\TestCase;

final class TelegramCommandSpamGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array']);
        Cache::flush();
    }

    public function test_registered_command_is_silently_ignored_in_group_chat(): void
    {
        $telegram = new SpamGuardTelegramService();
        $plugin = new SpamGuardTelegramPlugin('Telegram');
        $plugin->prepare($telegram);

        $handled = $plugin->dispatchCommand((object) [
            'command' => '/traffic',
            'chat_id' => -100123456,
            'is_private' => false,
        ]);

        $this->assertTrue($handled);
        $this->assertFalse($plugin->handlerCalled);
        $this->assertSame([], $telegram->messages);
    }

    public function test_same_telegram_update_is_only_claimed_once(): void
    {
        $controller = new TelegramController(
            new SpamGuardTelegramService(),
            Mockery::mock(UserService::class)
        );
        $method = new ReflectionMethod($controller, 'claimUpdate');
        $method->setAccessible(true);
        $payload = ['update_id' => 987654321];

        $this->assertTrue($method->invoke($controller, $payload));
        $this->assertFalse($method->invoke($controller, $payload));
    }
}

final class SpamGuardTelegramPlugin extends TelegramPlugin
{
    public bool $handlerCalled = false;

    public function prepare(TelegramService $telegram): void
    {
        $this->telegramService = $telegram;
        $this->registerTelegramCommand('/traffic', function (): void {
            $this->handlerCalled = true;
        });
    }

    public function dispatchCommand(object $message): bool
    {
        return $this->handleCommandMessage($message);
    }
}

final class SpamGuardTelegramService extends TelegramService
{
    /** @var list<string> */
    public array $messages = [];

    public function __construct()
    {
    }

    public function sendMessage(int $chatId, string $text, string $parseMode = '', array $options = []): void
    {
        $this->messages[] = $text;
    }
}
