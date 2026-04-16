<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Exceptions\ApiException;
use App\Http\Controllers\V1\User\OrderController;
use App\Models\Order;
use App\Models\User;
use App\Services\UserService;
use Tests\TestCase;

final class OrderControllerInternalTest extends TestCase
{
    public function test_handle_user_balance_consumes_order_total_when_balance_is_sufficient(): void
    {
        $order = new Order([
            'user_id' => 9,
            'total_amount' => 500,
        ]);
        $order->balance_amount = 0;

        $user = new User();
        $user->id = 9;
        $user->balance = 900;

        $userService = new class extends UserService {
            public array $calls = [];

            public function addBalance(int $userId, int $balance): bool
            {
                $this->calls[] = [$userId, $balance];
                return true;
            }
        };

        $this->invokeHandleUserBalance($order, $user, $userService);

        $this->assertSame([[9, -500]], $userService->calls);
        $this->assertSame(500, (int) $order->balance_amount);
        $this->assertSame(0, (int) $order->total_amount);
    }

    public function test_handle_user_balance_consumes_full_user_balance_when_insufficient(): void
    {
        $order = new Order([
            'user_id' => 7,
            'total_amount' => 1200,
        ]);
        $order->balance_amount = 0;

        $user = new User();
        $user->id = 7;
        $user->balance = 300;

        $userService = new class extends UserService {
            public array $calls = [];

            public function addBalance(int $userId, int $balance): bool
            {
                $this->calls[] = [$userId, $balance];
                return true;
            }
        };

        $this->invokeHandleUserBalance($order, $user, $userService);

        $this->assertSame([[7, -300]], $userService->calls);
        $this->assertSame(300, (int) $order->balance_amount);
        $this->assertSame(900, (int) $order->total_amount);
    }

    public function test_handle_user_balance_throws_when_balance_deduction_fails(): void
    {
        $order = new Order([
            'user_id' => 3,
            'total_amount' => 200,
        ]);

        $user = new User();
        $user->id = 3;
        $user->balance = 1000;

        $userService = new class extends UserService {
            public function addBalance(int $userId, int $balance): bool
            {
                return false;
            }
        };

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Insufficient balance');

        $this->invokeHandleUserBalance($order, $user, $userService);
    }

    private function invokeHandleUserBalance(Order $order, User $user, UserService $userService): void
    {
        $controller = new OrderController();
        $method = new \ReflectionMethod(OrderController::class, 'handleUserBalance');
        $method->setAccessible(true);
        $method->invoke($controller, $order, $user, $userService);
    }
}

