<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Exceptions\TicketAiProviderException;
use App\Http\Controllers\V2\Admin\TicketController;
use App\Models\User;
use App\Services\TicketAiAssistantService;
use Illuminate\Http\Request;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class TicketAiControllerTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindJsonResponseFactory();
    }

    public function test_capabilities_endpoint_returns_service_state(): void
    {
        $service = new class extends TicketAiAssistantService {
            public function capabilities(): array
            {
                return ['enabled' => true, 'configured' => true, 'available' => true, 'reason' => null];
            }
        };

        $response = (new TicketController())->aiCapabilities(new Request(), $service);
        $payload = json_decode($response->getContent(), true);

        $this->assertSame('success', $payload['status']);
        $this->assertTrue($payload['data']['available']);
    }

    public function test_connection_endpoint_passes_current_admin_to_service(): void
    {
        $service = new class extends TicketAiAssistantService {
            public ?int $adminId = null;

            public function testConnection(?int $adminId = null): array
            {
                $this->adminId = $adminId;
                return ['ok' => true, 'model' => 'test-model', 'latency_ms' => 15];
            }
        };
        $request = $this->requestForAdmin(42);

        $response = (new TicketController())->aiTestConnection($request, $service);
        $payload = json_decode($response->getContent(), true);

        $this->assertSame(42, $service->adminId);
        $this->assertTrue($payload['data']['ok']);
        $this->assertSame(15, $payload['data']['latency_ms']);
    }

    public function test_connection_endpoint_returns_normalized_provider_error_code(): void
    {
        $service = new class extends TicketAiAssistantService {
            public function testConnection(?int $adminId = null): array
            {
                throw new TicketAiProviderException('authentication');
            }
        };

        $response = (new TicketController())->aiTestConnection($this->requestForAdmin(8), $service);
        $payload = json_decode($response->getContent(), true);

        $this->assertSame('fail', $payload['status']);
        $this->assertSame('authentication', $payload['message']);
        $this->assertSame(422, $response->getStatusCode());
    }

    private function requestForAdmin(int $id): Request
    {
        $admin = new User();
        $admin->id = $id;
        $request = Request::create('/api/v2/admin/ticket/aiTestConnection', 'POST');
        $request->setUserResolver(fn () => $admin);

        return $request;
    }
}
