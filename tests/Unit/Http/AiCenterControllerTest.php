<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controllers\V2\Admin\AiCenterController;
use App\Services\AiCenterService;
use Illuminate\Http\Request;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class AiCenterControllerTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindJsonResponseFactory();
    }

    public function test_overview_returns_unified_ai_state(): void
    {
        $service = $this->createMock(AiCenterService::class);
        $service->expects($this->once())
            ->method('overview')
            ->with(14)
            ->willReturn([
                'window_days' => 14,
                'summary' => ['ready_modules' => 3, 'total_modules' => 4],
            ]);

        $request = Request::create('/api/v2/admin/ai-center/overview', 'GET', ['days' => 14]);
        $response = (new AiCenterController())->overview($request, $service);
        $payload = json_decode($response->getContent(), true);

        $this->assertSame('success', $payload['status']);
        $this->assertSame(14, $payload['data']['window_days']);
        $this->assertSame(3, $payload['data']['summary']['ready_modules']);
    }
}
