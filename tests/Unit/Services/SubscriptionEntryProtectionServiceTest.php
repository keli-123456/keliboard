<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\User;
use App\Services\SubscriptionEntryProtectionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class SubscriptionEntryProtectionServiceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp(); $this->setUpInMemoryDatabase(); config(['app.key' => 'synthetic-entry-key']);
        $this->bindTestSettings(['server_entry_probe_enable' => true, 'server_entry_probe_targets' => ['223.5.5.5']]);
        Schema::create('v2_server', function (Blueprint $table): void {
            $table->id(); $table->integer('machine_id'); $table->text('group_ids');
            $table->boolean('enabled'); $table->boolean('show'); $table->string('runtime')->default('generic');
            $table->integer('created_at')->nullable(); $table->integer('updated_at')->nullable();
        });
        Schema::create('v2_server_machine', function (Blueprint $table): void {
            $table->id(); $table->boolean('is_active'); $table->text('load_status');
            $table->integer('created_at')->nullable(); $table->integer('updated_at')->nullable();
        });
    }

    private function user(int $group = 1): User
    {
        return (new User())->forceFill(['id' => 10, 'group_id' => $group, 'site_id' => 2,
            'token' => 'private-subscription-token', 'email' => 'private@example.test']);
    }

    private function node(int $group = 1, bool $enabled = true): int
    {
        $machine = ServerMachine::query()->create(['is_active' => true, 'load_status' => [
            'updated_at' => time(), 'metrics' => ['keli_core_rs' => ['keli_core_tcp_auth_failure_total' => 3]],
            'ip' => ['public_ipv4' => '192.0.2.1'], 'private_secret' => 'private-machine-secret',
        ]]);
        return Server::query()->create(['machine_id' => $machine->id, 'group_ids' => [(string) $group],
            'enabled' => $enabled, 'show' => true])->id;
    }

    public function test_only_response_candidates_still_authorized_for_the_account_are_linked_without_addresses_or_secrets(): void
    {
        $own = $this->node(); $other = $this->node(2); $disabled = $this->node(1, false);
        $service = new SubscriptionEntryProtectionService();
        $result = $service->forUser($this->user(), [$own, $other, $disabled], 2);
        $this->assertSame('available', $result['status']); $this->assertCount(1, $result['machines']);
        $this->assertSame('observe_only', $result['mode']);
        $this->assertSame(3, $result['machines'][0]['context']['totals']['tcp_auth_failure_total']);
        foreach (['private-subscription-token', 'private@example.test', '192.0.2.1', 'private-machine-secret', '223.5.5.5'] as $private) {
            $this->assertStringNotContainsString($private, json_encode($result));
        }
        $this->assertSame(1, $result['machines'][0]['context']['reachability']['configured_targets']);
        $this->assertArrayNotHasKey('results', $result['machines'][0]['context']['reachability']);
        $this->assertSame([], $service->forUser($this->user(3), [$own], 1)['machines']);
        $this->assertSame('not_observed', $service->forUser($this->user(), [], 0)['status']);
    }

    public function test_machine_context_is_bounded_and_unsupported_storage_is_unknown(): void
    {
        $ids = []; for ($i = 0; $i < 20; $i++) $ids[] = $this->node();
        $service = new SubscriptionEntryProtectionService(); $result = $service->forUser($this->user(), $ids, 1);
        $this->assertCount(8, $result['machines']); $this->assertSame(16, $result['candidate_limit']);
        Schema::drop('v2_server_machine');
        $this->assertSame('unavailable', $service->forUser($this->user(), $ids, 1)['status']);
    }
}
