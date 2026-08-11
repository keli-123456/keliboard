<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\TicketAiCircuitBreaker;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class TicketAiCircuitBreakerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_repeated_failures_open_the_circuit_and_success_resets_it(): void
    {
        $breaker = new TicketAiCircuitBreaker();
        $baseUrl = 'https://ai-' . uniqid() . '.example.test/v1';

        $breaker->failure($baseUrl, 'support-model', 2, 5);
        $first = $breaker->state($baseUrl, 'support-model');
        $this->assertFalse($first['open']);
        $this->assertSame(1, $first['failures']);

        $breaker->failure($baseUrl, 'support-model', 2, 5);
        $opened = $breaker->state($baseUrl, 'support-model');
        $this->assertTrue($opened['open']);
        $this->assertSame(2, $opened['failures']);
        $this->assertGreaterThan(time(), $opened['open_until']);

        $breaker->success($baseUrl, 'support-model');
        $reset = $breaker->state($baseUrl, 'support-model');
        $this->assertFalse($reset['open']);
        $this->assertSame(0, $reset['failures']);
        $this->assertNull($reset['open_until']);
    }

    public function test_circuit_state_is_isolated_by_provider_and_model(): void
    {
        $breaker = new TicketAiCircuitBreaker();
        $baseUrl = 'https://ai-' . uniqid() . '.example.test/v1';

        $breaker->failure($baseUrl, 'model-a', 1, 5);

        $this->assertTrue($breaker->state($baseUrl, 'model-a')['open']);
        $this->assertFalse($breaker->state($baseUrl, 'model-b')['open']);
        $this->assertFalse($breaker->state('https://other.example.test/v1', 'model-a')['open']);
    }
}
