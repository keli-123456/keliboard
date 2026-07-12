<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Exceptions\ApiException;
use App\Jobs\SendTelegramJob;
use Mockery;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Psr\Log\NullLogger;
use Tests\TestCase;

final class SendTelegramJobTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = Container::getInstance();
        $app->instance('log', new NullLogger());
        Facade::setFacadeApplication($app);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_it_does_not_fail_the_queue_for_a_missing_telegram_chat(): void
    {
        $job = new SendTelegramJob(621620518, '风控提醒');
        $telegram = Mockery::mock('overload:App\\Services\\TelegramService');
        $telegram->shouldReceive('sendMessage')
            ->once()
            ->andThrow(new ApiException('Telegram 服务错误: Telegram API 错误: Bad Request: chat not found'));

        $exception = null;
        try {
            $job->handle();
        } catch (ApiException $error) {
            $exception = $error;
        }

        $this->assertNull($exception);
    }

    public function test_it_still_rethrows_transient_telegram_failures(): void
    {
        $job = new SendTelegramJob(621620518, '风控提醒');
        $telegram = Mockery::mock('overload:App\\Services\\TelegramService');
        $telegram->shouldReceive('sendMessage')
            ->once()
            ->andThrow(new ApiException('Telegram 服务错误: HTTP 请求失败: 502'));

        $this->expectException(ApiException::class);
        $job->handle();
    }
}
