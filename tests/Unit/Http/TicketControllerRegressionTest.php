<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controllers\V1\User\TicketController;
use App\Models\User;
use Illuminate\Http\Request;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class TicketControllerRegressionTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->bindJsonResponseFactory();
        $this->createUserTable();
        $this->createTicketTables();
    }

    public function test_fetch_missing_ticket_returns_business_error_instead_of_throwing(): void
    {
        $user = User::create([
            'email' => 'customer@example.com',
            'password' => 'secret',
            'token' => 'customer-token',
            'uuid' => 'customer-uuid',
        ]);

        $request = Request::create('/api/v1/user/ticket/fetch', 'GET', ['id' => 999]);
        $request->setUserResolver(fn () => $user);

        $response = (new TicketController())->fetch($request);
        $payload = $response->getData(true);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('fail', $payload['status']);
        $this->assertSame('Ticket does not exist', $payload['message']);
    }
}
