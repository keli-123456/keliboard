<?php

namespace Tests\Unit\Http;

use App\Http\Controllers\V1\User\NotificationController;
use App\Http\Controllers\V1\User\TicketController;
use App\Models\Ticket;
use App\Models\User;
use App\Services\UserNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

class UserNotificationTest extends TestCase
{
    use InteractsWithInMemoryDatabase;
    private User $user;
    private Ticket $ticket;
    private UserNotificationService $notifications;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInMemoryDatabase();
        $this->bindJsonResponseFactory();
        $this->bindTestRouter();
        $this->createUserTable();
        $this->createTicketTables();
        $this->createOrderTable();
        $this->user = User::create(['email' => 'local@example.test', 'password' => 'test', 'token' => 'test', 'uuid' => 'test', 'transfer_enable' => 100, 'u' => 5, 'd' => 90]);
        $this->ticket = Ticket::create(['user_id' => $this->user->id, 'subject' => 'Support', 'status' => 0]);
        $this->notifications = new UserNotificationService();
    }

    private function migrate(): void
    {
        (require base_path('database/migrations/2026_09_12_000001_add_ticket_message_user_read_at.php'))->up();
    }

    private function message(?int $sender = 0, ?int $ticket = null): int
    {
        return DB::table('v2_ticket_message')->insertGetId(['ticket_id' => $ticket ?? $this->ticket->id, 'user_id' => $sender, 'message' => 'Reply', 'created_at' => time(), 'updated_at' => time()]);
    }

    private function request(array $data = []): Request
    {
        $request = Request::create('/api/v1/user/ticket/read', 'POST', $data);
        $request->setUserResolver(fn () => $this->user);
        return $request;
    }

    public function test_migration_preserves_legacy_read_baseline_and_new_replies_are_unread(): void
    {
        $old = $this->message();
        $this->migrate();
        $new = $this->message();
        $this->assertSame(0, DB::table('v2_ticket_message')->where('id', $old)->value('user_read_at'));
        $this->assertNull(DB::table('v2_ticket_message')->where('id', $new)->value('user_read_at'));
        $this->migrate();
        $this->assertSame([$new], $this->notifications->unreadIds($this->ticket));
    }

    public function test_counts_support_replies_including_closed_tickets_not_customer_posts_or_tasks(): void
    {
        $this->migrate();
        $this->message($this->user->id);
        $this->message();
        $this->message(null);
        $this->ticket->update(['status' => 1]);
        DB::table('v2_order')->insert(['user_id' => $this->user->id, 'trade_no' => 'LOCAL-ORDER', 'period' => 'month_price', 'status' => 0, 'total_amount' => 100]);
        $summary = $this->notifications->summary($this->user);
        $this->assertSame(2, $summary['unread_count']);
        $this->assertSame(1, $summary['pending_orders_count']);
        $this->assertSame(95, $summary['traffic_percent']);
    }

    public function test_summary_does_not_leak_other_users_and_is_bounded_without_losing_total(): void
    {
        $this->migrate();
        $other = Ticket::create(['user_id' => 999, 'subject' => 'Private']);
        $this->message(0, $other->id);
        for ($i = 0; $i < 25; $i++) {
            $ticket = Ticket::create(['user_id' => $this->user->id, 'subject' => 'Ticket ' . $i]);
            $this->message(0, $ticket->id);
        }
        $summary = $this->notifications->summary($this->user);
        $this->assertSame(25, $summary['unread_count']);
        $this->assertCount(20, $summary['tickets']);
        $this->assertNotContains('Private', array_column($summary['tickets'], 'subject'));
    }

    public function test_exact_read_ids_are_idempotent_and_do_not_clear_unseen_or_new_messages(): void
    {
        $this->migrate();
        $unseen = $this->message();
        $seen = $this->message();
        $own = $this->message($this->user->id);
        $other = Ticket::create(['user_id' => 999, 'subject' => 'Private']);
        $foreign = $this->message(0, $other->id);
        $controller = new NotificationController();
        $request = $this->request(['id' => $this->ticket->id, 'message_ids' => [$seen, $own, $foreign]]);
        $result = $controller->read($request, $this->notifications)->getData(true);
        $this->assertSame([$seen], $result['data']['read_ids']);
        $at = DB::table('v2_ticket_message')->where('id', $seen)->value('user_read_at');
        $controller->read($request, $this->notifications);
        $this->assertSame($at, DB::table('v2_ticket_message')->where('id', $seen)->value('user_read_at'));
        $new = $this->message();
        $this->assertSame([$unseen, $new], $this->notifications->unreadIds($this->ticket));
        $this->assertNull(DB::table('v2_ticket_message')->where('id', $foreign)->value('user_read_at'));
    }

    public function test_foreign_ticket_cannot_be_marked_read(): void
    {
        $this->migrate();
        $other = Ticket::create(['user_id' => 999, 'subject' => 'Private']);
        $message = $this->message(0, $other->id);
        $response = (new NotificationController())->read($this->request(['id' => $other->id, 'message_ids' => [$message]]), $this->notifications);
        $this->assertSame(404, $response->getStatusCode());
        $this->assertNull(DB::table('v2_ticket_message')->where('id', $message)->value('user_read_at'));
    }

    public function test_fetching_details_and_summary_never_marks_messages_read(): void
    {
        $this->migrate();
        $message = $this->message();
        $detail = (new TicketController())->fetch($this->request(['id' => $this->ticket->id]))->getData(true);
        $this->assertSame([$message], $detail['data']['unread_message_ids']);
        $summary = (new NotificationController())->fetch($this->request(), $this->notifications);
        $this->assertSame(1, $summary->getData(true)['data']['unread_count']);
        $this->assertStringContainsString('no-store', $summary->headers->get('Cache-Control'));
        $this->assertNull(DB::table('v2_ticket_message')->where('id', $message)->value('user_read_at'));
    }

    public function test_old_schema_is_unavailable_not_a_false_zero(): void
    {
        $response = (new NotificationController())->fetch($this->request(), $this->notifications);
        $this->assertSame(503, $response->getStatusCode());
    }

    public function test_detail_unread_ids_are_limited_to_the_loaded_message_snapshot(): void
    {
        $this->migrate();
        $loaded = $this->message();
        $this->message();
        $this->assertSame([$loaded], $this->notifications->unreadIds($this->ticket, [$loaded]));
        $this->assertSame([], $this->notifications->unreadIds($this->ticket, []));
    }

    public function test_read_request_rejects_empty_duplicate_and_oversized_batches(): void
    {
        $this->migrate();
        foreach ([[], [1, 1], range(1, 101), [-1], ['not-an-id']] as $ids) {
            try {
                (new NotificationController())->read($this->request(['id' => $this->ticket->id, 'message_ids' => $ids]), $this->notifications);
                $this->fail('Invalid read batch was accepted');
            } catch (\Illuminate\Validation\ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
    }
}
