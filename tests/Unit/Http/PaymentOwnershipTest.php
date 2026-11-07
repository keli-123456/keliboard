<?php

namespace Tests\Unit\Http;

use App\Http\Controllers\V2\Admin\PaymentController;
use App\Models\Payment;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class PaymentOwnershipTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInMemoryDatabase();
        $this->createUserTable();
        $this->createPaymentTable();
        $this->createOrderTable();
        $this->createAgentCommerceTables();
        $this->bindJsonResponseFactory();
        $this->bindTestUrlGenerator('https://panel.example.test');
        $this->bindTestSettings(['app_url' => 'https://panel.example.test']);
        $this->database->table('v2_user')->insert([
            ['id' => 41, 'email' => 'agent-a@example.test', 'password' => 'not-owner-metadata'],
            ['id' => 42, 'email' => 'agent-b@example.test', 'password' => 'not-owner-metadata'],
        ]);
        $this->database->table('v2_agent_domain')->insert([
            ['id' => 11, 'agent_user_id' => 41, 'domain' => 'a.example.test'],
            ['id' => 12, 'agent_user_id' => 42, 'domain' => 'b.example.test'],
        ]);
    }

    public function test_fetch_distinguishes_same_name_payments_without_changing_config_or_callbacks(): void
    {
        $platform = $this->payment('main');
        $agent = $this->payment('agent', ['owner_type' => 'agent', 'owner_id' => 41, 'owner_domain_id' => 11]);
        $rows = collect(app(PaymentController::class)->fetch()->getData(true)['data'])->keyBy('id');
        $this->assertSame('platform', $rows[$platform->id]['owner_type']);
        $this->assertNull($rows[$platform->id]['agent_email']);
        $this->assertNull($rows[$platform->id]['owner_domain']);
        $this->assertSame('agent', $rows[$agent->id]['owner_type']);
        $this->assertSame(41, $rows[$agent->id]['owner_id']);
        $this->assertSame('agent-a@example.test', $rows[$agent->id]['agent_email']);
        $this->assertSame('a.example.test', $rows[$agent->id]['owner_domain']);
        $this->assertSame($platform->name, $agent->name);
        $this->assertSame(['merchant' => 'keep-agent'], $rows[$agent->id]['config']);
        $this->assertStringEndsWith('/EPay/agent', $rows[$agent->id]['notify_url']);
        $this->assertArrayNotHasKey('password', $rows[$agent->id]);
    }

    public function test_missing_accounts_and_domains_keep_the_original_ownership_ids(): void
    {
        $missing = $this->payment('missing', ['owner_type' => 'agent', 'owner_id' => 99, 'owner_domain_id' => 999]);
        $mismatched = $this->payment('mismatch', ['owner_type' => 'agent', 'owner_id' => 41, 'owner_domain_id' => 12]);
        $allDomains = $this->payment('all', ['owner_type' => 'agent', 'owner_id' => 41]);
        $rows = collect(app(PaymentController::class)->fetch()->getData(true)['data'])->keyBy('id');
        $this->assertSame('agent', $rows[$missing->id]['owner_type']);
        $this->assertSame(99, $rows[$missing->id]['owner_id']);
        $this->assertSame(999, $rows[$missing->id]['owner_domain_id']);
        $this->assertNull($rows[$missing->id]['agent_email']);
        $this->assertNull($rows[$missing->id]['owner_domain']);
        $this->assertNull($rows[$mismatched->id]['owner_domain']);
        $this->assertNull($rows[$allDomains->id]['owner_domain_id']);
        $this->assertNull($rows[$allDomains->id]['owner_domain']);
    }

    public function test_owner_metadata_uses_two_batch_lookups_instead_of_one_per_channel(): void
    {
        foreach (range(1, 20) as $index) {
            $this->payment('agent-' . $index, ['owner_type' => 'agent', 'owner_id' => 41, 'owner_domain_id' => 11]);
        }
        $connection = $this->database->getConnection();
        $connection->enableQueryLog();
        app(PaymentController::class)->fetch();
        $lookups = array_filter($connection->getQueryLog(), fn ($query) =>
            str_contains($query['query'], 'from "v2_user"') || str_contains($query['query'], 'from "v2_agent_domain"'));
        $this->assertCount(2, $lookups);
        foreach ($lookups as $lookup) {
            $this->assertStringNotContainsString('password', $lookup['query']);
            $this->assertStringNotContainsString('select *', $lookup['query']);
        }
    }

    public function test_sorting_main_site_does_not_reorder_agent_payments(): void
    {
        $first = $this->payment('main-first');
        $agent = $this->payment('agent', ['owner_type' => 'agent', 'owner_id' => 41]);
        $second = $this->payment('main-second');
        $this->assertSame('success', $this->sort([$second->id, $first->id])['status']);
        $this->assertSame(1, $second->fresh()->sort);
        $this->assertSame(2, $first->fresh()->sort);
        $this->assertSame(20, $agent->fresh()->sort);
    }

    public function test_one_agent_can_sort_channels_across_its_domains(): void
    {
        $main = $this->payment('main');
        $first = $this->payment('agent-first', ['owner_type' => 'agent', 'owner_id' => 41, 'owner_domain_id' => 11]);
        $second = $this->payment('agent-second', ['owner_type' => 'agent', 'owner_id' => 41]);
        $this->assertSame('success', $this->sort([$second->id, $first->id])['status']);
        $this->assertSame(1, $second->fresh()->sort);
        $this->assertSame(2, $first->fresh()->sort);
        $this->assertSame(20, $main->fresh()->sort);
    }

    public function test_mixed_missing_and_unknown_owners_are_rejected_before_any_sort_writes(): void
    {
        $main = $this->payment('main');
        $a = $this->payment('a', ['owner_type' => 'agent', 'owner_id' => 41]);
        $b = $this->payment('b', ['owner_type' => 'agent', 'owner_id' => 42]);
        $unknown = $this->payment('unknown', ['owner_type' => 'other']);
        $missing = $this->payment('missing', ['owner_type' => 'agent']);
        $invalidMain = $this->payment('invalid-main', ['owner_id' => 41]);
        foreach ([[$main->id, $a->id], [$a->id, $b->id], [$a->id, 999], [$unknown->id], [$missing->id], [$invalidMain->id]] as $ids) {
            $this->assertSame('fail', $this->sort($ids)['status']);
            $this->assertSame([20], Payment::query()->distinct()->pluck('sort')->all());
        }
    }

    public function test_invalid_and_duplicate_ids_are_rejected(): void
    {
        $payment = $this->payment('main');
        foreach ([[], [$payment->id, $payment->id], [[1]], [0], ['invalid']] as $ids) {
            try {
                $this->sort($ids);
                $this->fail('Expected invalid sort IDs to be rejected');
            } catch (ValidationException) {
                $this->assertSame(20, $payment->fresh()->sort);
            }
        }
    }

    public function test_a_failed_update_rolls_back_the_whole_owner_sort(): void
    {
        $first = $this->payment('first');
        $second = $this->payment('second');
        $previous = Payment::getEventDispatcher();
        Payment::setEventDispatcher(new Dispatcher(app()));
        Payment::updating(fn (Payment $payment) => $payment->id !== $second->id);
        try {
            $this->assertSame('fail', $this->sort([$first->id, $second->id])['status']);
            $this->assertSame(20, $first->fresh()->sort);
            $this->assertSame(20, $second->fresh()->sort);
        } finally {
            $previous ? Payment::setEventDispatcher($previous) : Payment::unsetEventDispatcher();
        }
    }

    public function test_admin_edit_does_not_transfer_ownership_and_create_is_always_platform_owned(): void
    {
        $agent = $this->payment('agent', ['owner_type' => 'agent', 'owner_id' => 41, 'owner_domain_id' => 11]);
        $payload = ['id' => $agent->id, 'name' => 'Edited', 'payment' => 'EPay', 'config' => ['merchant' => 'keep'],
            'owner_type' => 'platform', 'owner_id' => 42, 'owner_domain_id' => 12];
        $controller = app(PaymentController::class);
        $this->assertSame('success', $controller->save(Request::create('/payment/save', 'POST', $payload))->getData(true)['status']);
        $this->assertSame('agent', $agent->fresh()->owner_type);
        $this->assertSame(41, $agent->fresh()->owner_id);
        $this->assertSame(11, $agent->fresh()->owner_domain_id);
        unset($payload['id']);
        $payload['owner_type'] = 'agent';
        $controller->save(Request::create('/payment/save', 'POST', $payload));
        $created = Payment::query()->latest('id')->firstOrFail();
        $this->assertSame('platform', $created->owner_type);
        $this->assertNull($created->owner_id);
        $this->assertNull($created->owner_domain_id);
    }

    public function test_same_name_agent_toggle_and_delete_never_touch_main_site_channel(): void
    {
        $main = $this->payment('main');
        $agent = $this->payment('agent', ['owner_type' => 'agent', 'owner_id' => 41]);
        $controller = app(PaymentController::class);
        $controller->show(Request::create('/payment/show', 'POST', ['id' => $agent->id]));
        $this->assertFalse($agent->fresh()->enable);
        $this->assertTrue($main->fresh()->enable);
        $controller->drop(Request::create('/payment/drop', 'POST', ['id' => $agent->id]));
        $this->assertNull(Payment::find($agent->id));
        $this->assertTrue($main->fresh()->enable);
    }

    private function payment(string $uuid, array $attributes = []): Payment
    {
        return Payment::create(array_replace(['uuid' => $uuid, 'name' => 'Same name', 'payment' => 'EPay',
            'config' => ['merchant' => 'keep-' . $uuid], 'enable' => true, 'sort' => 20], $attributes));
    }

    private function sort(array $ids): array
    {
        return app(PaymentController::class)->sort(Request::create('/payment/sort', 'POST', ['ids' => $ids]))->getData(true);
    }
}
