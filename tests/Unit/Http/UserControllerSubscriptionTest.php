<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controllers\V1\User\UserController;
use App\Services\Auth\LoginService;
use Illuminate\Http\Request;
use Tests\TestCase;

final class UserControllerSubscriptionTest extends TestCase
{
    public function test_is_normalized_subscribe_plan_detects_response_extension_keys(): void
    {
        $controller = new UserController(new LoginService());
        $method = new \ReflectionMethod(UserController::class, 'isNormalizedSubscribePlan');
        $method->setAccessible(true);

        $normalized = $method->invoke($controller, [
            'id' => 1,
            'name' => 'Pro',
            'available_periods' => ['month_price'],
        ]);
        $plain = $method->invoke($controller, [
            'id' => 1,
            'name' => 'Pro',
        ]);

        $this->assertTrue($normalized);
        $this->assertFalse($plain);
    }

    public function test_normalize_subscribe_plan_keeps_already_normalized_array(): void
    {
        $controller = new UserController(new LoginService());
        $method = new \ReflectionMethod(UserController::class, 'normalizeSubscribePlan');
        $method->setAccessible(true);

        $plan = [
            'id' => 1,
            'name' => 'Pro',
            'available_periods' => ['month_price', 'year_price'],
            'recurring_periods' => ['month_price', 'year_price'],
            'has_recurring_price' => true,
            'has_onetime_price' => false,
        ];

        $normalized = $method->invoke($controller, Request::create('/', 'GET'), $plan);

        $this->assertSame($plan, $normalized);
    }

    public function test_normalize_subscribe_plan_returns_original_payload_when_required_fields_missing(): void
    {
        $controller = new UserController(new LoginService());
        $method = new \ReflectionMethod(UserController::class, 'normalizeSubscribePlan');
        $method->setAccessible(true);

        $planWithoutId = ['name' => 'Starter'];
        $nonArrayPayload = 'starter';

        $resultWithoutId = $method->invoke($controller, Request::create('/', 'GET'), $planWithoutId);
        $resultScalar = $method->invoke($controller, Request::create('/', 'GET'), $nonArrayPayload);

        $this->assertSame($planWithoutId, $resultWithoutId);
        $this->assertSame($nonArrayPayload, $resultScalar);
    }
}

