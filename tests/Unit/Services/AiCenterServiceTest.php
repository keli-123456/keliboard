<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\AiCenterService;
use App\Services\AiDiagnosticAnalyzer;
use App\Services\AiDiagnosticDispositionService;
use App\Services\AiDiagnosticIncidentService;
use App\Services\AiDiagnosticMetricsService;
use App\Services\AiDiagnosticService;
use App\Services\TicketAiAssistantService;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class AiCenterServiceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInMemoryDatabase();
        $this->bindTestSettings();
    }
    public function test_overview_aggregates_shared_provider_and_isolates_missing_modules(): void
    {
        $ticketAi = $this->createMock(TicketAiAssistantService::class);
        $ticketAi->method('publicSettings')->willReturn([
            'ticket_ai_enable' => true,
            'ticket_ai_auto_reply_enable' => false,
            'ticket_ai_knowledge_enable' => true,
            'ticket_ai_model' => 'test-model',
            'ticket_ai_base_url' => 'https://api.example.test/v1',
        ]);
        $ticketAi->method('capabilities')->willReturn([
            'enabled' => true,
            'configured' => true,
            'available' => true,
            'reason' => null,
            'circuit_open_until' => null,
            'consecutive_failures' => 0,
        ]);
        $ticketAi->method('stats')->with(7)->willReturn([
            'days' => 7,
            'requests' => 20,
            'success_rate' => 0.95,
            'estimated_cost' => 0.25,
            'estimated_cost_currency' => 'CNY',
        ]);

        $diagnostics = new AiDiagnosticService(
            new AiDiagnosticMetricsService(),
            new AiDiagnosticAnalyzer(),
            new AiDiagnosticDispositionService(),
            new AiDiagnosticIncidentService(),
        );

        $overview = (new AiCenterService($ticketAi, $diagnostics))->overview(7);

        $this->assertSame('api.example.test', $overview['provider']['provider_host']);
        $this->assertSame(20, $overview['summary']['requests']);
        $this->assertGreaterThanOrEqual(1, $overview['summary']['ready_modules']);
        $this->assertSame('ready', $overview['modules']['ticket_assistant']['state']);
        $this->assertArrayHasKey('system_diagnostics', $overview['modules']);
        $this->assertSame('migration_required', $overview['modules']['subscription_risk']['state']);
        $this->assertFalse($overview['integration']['direct_enforcement']);
    }
}
