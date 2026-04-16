<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use Tests\TestCase;

final class OrderPricingHelpersTest extends TestCase
{
    public function test_amount_to_cents_handles_rounding_and_negative_values(): void
    {
        $this->assertSame(0, OrderService::amountToCents(null));
        $this->assertSame(0, OrderService::amountToCents(''));
        $this->assertSame(1235, OrderService::amountToCents('12.345'));
        $this->assertSame(0, OrderService::amountToCents(-1));
    }

    public function test_percentage_of_amount_handles_empty_and_precision_inputs(): void
    {
        $this->assertSame(0, OrderService::percentageOfAmount(0, 10));
        $this->assertSame(0, OrderService::percentageOfAmount(1000, ''));
        $this->assertSame(125, OrderService::percentageOfAmount(1000, 12.5));
        $this->assertSame(0, OrderService::percentageOfAmount(1000, -20));
    }

    public function test_set_vip_discount_combines_existing_discount_and_user_discount_rate(): void
    {
        $order = new Order();
        $order->total_amount = 1000;
        $order->discount_amount = 50;
        $user = new User();
        $user->discount = 10;

        $service = new OrderService($order);
        $service->setVipDiscount($user);

        $this->assertSame(150, (int) $order->discount_amount);
        $this->assertSame(850, (int) $order->total_amount);
    }

    public function test_set_vip_discount_caps_discount_at_order_total(): void
    {
        $order = new Order();
        $order->total_amount = 100;
        $order->discount_amount = 200;
        $user = new User();
        $user->discount = 50;

        $service = new OrderService($order);
        $service->setVipDiscount($user);

        $this->assertSame(100, (int) $order->discount_amount);
        $this->assertSame(0, (int) $order->total_amount);
    }
}
