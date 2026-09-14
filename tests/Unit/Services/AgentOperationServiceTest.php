<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\AgentOperationService;
use Illuminate\Support\Facades\DB;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class AgentOperationServiceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInMemoryDatabase();
        $this->createUserTable();
        (require base_path('database/migrations/2026_09_14_180100_create_agent_operations_table.php'))->up();
    }

    public function test_same_key_replays_original_result_and_separates_accounts(): void
    {
        $service = new AgentOperationService();
        foreach ([1, 2] as $id) {
            $user = $this->user($id);
            $calls = 0;
            $charge = function () use ($user, &$calls): array {
                $calls++;
                $user->decrement('balance', 100);
                return ['balance' => 900, 'receipt' => 'original'];
            };
            $first = $service->execute($user, 'retry-request-key', 'assign:3', ['plan' => 1, 'period' => 'monthly'], $charge);
            $again = $service->execute($user, 'retry-request-key', 'assign:3', ['period' => 'monthly', 'plan' => 1], $charge);
            $this->assertSame($first, $again);
            $this->assertSame(1, $calls);
            $this->assertSame(900, (int) $user->fresh()->balance);
        }
        $this->assertSame(2, DB::table('v2_agent_operation')->count());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('conflicts')]
    public function test_key_cannot_be_reused_with_changed_action_or_data(string $action, array $data): void
    {
        $user = $this->user(1);
        $service = new AgentOperationService();
        $service->execute($user, 'retry-request-key', 'assign:3', ['plan' => 1], fn () => []);
        $this->expectException(ApiException::class);
        $service->execute($user, 'retry-request-key', $action, $data, function (): array {
            $this->fail('Conflicting request must not execute.');
        });
    }

    public static function conflicts(): array
    {
        return [['assign:4', ['plan' => 1]], ['assign:3', ['plan' => 2]]];
    }

    public function test_failure_rolls_back_charge_and_can_be_retried(): void
    {
        $user = $this->user(1);
        $service = new AgentOperationService();
        try {
            $service->execute($user, 'retry-request-key', 'assign', [], function () use ($user): array {
                $user->decrement('balance', 100);
                throw new \RuntimeException('simulated failure');
            });
            $this->fail('Expected failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('simulated failure', $e->getMessage());
        }
        $this->assertSame(1000, (int) $user->fresh()->balance);
        $this->assertSame(0, DB::table('v2_agent_operation')->count());
        $this->assertSame(['ok' => true], $service->execute($user, 'retry-request-key', 'assign', [],
            fn () => ['ok' => true]));
    }

    public function test_request_payload_and_password_are_not_persisted(): void
    {
        $service = new AgentOperationService();
        $service->execute($this->user(1), 'retry-request-key', 'create', ['password' => 'private-password'], fn () => ['id' => 9]);
        $this->assertStringNotContainsString('private-password', json_encode(DB::table('v2_agent_operation')->first()));
    }

    public function test_invalid_key_is_rejected_and_legacy_requests_still_work(): void
    {
        $service = new AgentOperationService();
        $user = $this->user(1);
        $this->assertSame(['ok' => true], $service->execute($user, null, 'create', [], fn () => ['ok' => true]));
        $this->expectException(ApiException::class);
        $service->execute($user, 'bad key', 'create', [], fn () => []);
    }

    private function user(int $id): User
    {
        return User::create(['email' => "agent$id@example.test", 'password' => 'hash',
            'uuid' => "uuid$id", 'token' => "token$id", 'balance' => 1000]);
    }
}
