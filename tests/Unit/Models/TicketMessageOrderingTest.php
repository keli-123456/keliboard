<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Ticket;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class TicketMessageOrderingTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInMemoryDatabase();
        $this->createTicketTables();
        (require base_path('database/migrations/2026_09_12_000001_add_ticket_message_user_read_at.php'))->up();
    }

    public static function messageRelations(): array
    {
        return [
            'messages lazy' => ['messages', false],
            'messages eager' => ['messages', true],
            'legacy message lazy' => ['message', false],
            'legacy message eager' => ['message', true],
        ];
    }

    #[DataProvider('messageRelations')]
    public function test_message_order_is_independent_of_read_state_and_timestamp_ties(string $relation, bool $eager): void
    {
        $ticket = Ticket::create(['user_id' => 10, 'subject' => 'Support', 'status' => 0]);
        $ids = [];
        foreach ([1789350050, null, 0] as $index => $readAt) {
            $ids[] = DB::table('v2_ticket_message')->insertGetId([
                'ticket_id' => $ticket->id,
                'user_id' => $index === 0 ? 10 : 20,
                'message' => 'Message ' . $index,
                'created_at' => 1789350000,
                'updated_at' => 1789350000,
                'user_read_at' => $readAt,
            ]);
        }
        $other = Ticket::create(['user_id' => 99, 'subject' => 'Other ticket']);
        DB::table('v2_ticket_message')->insert([
            'ticket_id' => $other->id, 'user_id' => 99, 'message' => 'Private',
            'created_at' => 1789350000, 'updated_at' => 1789350000,
        ]);

        $load = static function () use ($ticket, $relation, $eager) {
            $query = Ticket::query();
            if ($eager) {
                $query->with([$relation . '.ticket', $relation . '.attachments']);
            }
            return $query->findOrFail($ticket->id)->{$relation};
        };
        $this->assertSame($ids, $load()->pluck('id')->all());

        DB::table('v2_ticket_message')->where('id', $ids[1])->update(['user_read_at' => 1789350100]);
        $messages = $load();
        $this->assertSame($ids, $messages->pluck('id')->all());
        $this->assertSame([true, false, false], $messages->pluck('is_from_user')->all());
    }
}
