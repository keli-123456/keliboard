<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controllers\V1\User\TicketController;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\TicketMessageAttachment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
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

    public function test_fetch_ticket_detail_includes_relative_attachment_preview_urls(): void
    {
        if (!Route::has('api.v2.ticket.attachment.preview')) {
            Route::get('/api/v2/ticket/attachment/{id}/preview', fn () => response('ok'))
                ->whereNumber('id')
                ->name('api.v2.ticket.attachment.preview');
        }

        $user = User::create([
            'email' => 'customer@example.com',
            'password' => 'secret',
            'token' => 'customer-token',
            'uuid' => 'customer-uuid',
        ]);

        $ticket = Ticket::create([
            'user_id' => $user->id,
            'subject' => 'Need help',
            'level' => 0,
            'status' => 0,
        ]);

        $message = TicketMessage::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'message' => 'see image',
        ]);

        TicketMessageAttachment::create([
            'ticket_id' => $ticket->id,
            'ticket_message_id' => $message->id,
            'user_id' => $user->id,
            'disk' => 'local',
            'path' => 'ticket_attachments/test.webp',
            'mime' => 'image/webp',
            'size' => 123,
            'width' => 80,
            'height' => 60,
        ]);

        $request = Request::create('/api/v1/user/ticket/fetch', 'GET', ['id' => $ticket->id]);
        $request->setUserResolver(fn () => $user);

        $response = (new TicketController())->fetch($request);
        $payload = $response->getData(true);
        $attachment = $payload['data']['message'][0]['attachments'][0] ?? [];

        $this->assertSame('success', $payload['status']);
        $this->assertStringStartsWith('/api/v2/ticket/attachment/1/preview', $attachment['preview_url']);
        $this->assertStringStartsWith('/api/v2/ticket/attachment/1/preview', $attachment['thumbnail_url']);
        $this->assertStringStartsWith('/api/v2/ticket/attachment/1/preview', $attachment['preview_path']);
        $this->assertStringStartsWith('/api/v2/ticket/attachment/1/preview', $attachment['thumbnail_path']);
        $this->assertStringContainsString('signature=', $attachment['preview_url']);
        $this->assertStringContainsString('variant=thumb', $attachment['thumbnail_path']);
    }
}
