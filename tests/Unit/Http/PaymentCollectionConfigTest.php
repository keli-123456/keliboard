<?php

namespace Tests\Unit\Http;

use App\Http\Controllers\V2\Admin\PaymentController;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class PaymentCollectionConfigTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInMemoryDatabase();
        $this->createPaymentTable();
        $this->createOrderTable();
        $this->bindJsonResponseFactory();
        $this->bindTestUrlGenerator('https://panel.example.test');
        $this->bindTestSettings(['app_url' => 'https://panel.example.test']);
    }

    public function test_admin_can_save_policy_and_legacy_edit_does_not_clear_it(): void
    {
        $p = $this->payment();
        $policy = ['daily_target' => 300001, 'reached_action' => 'pause', 'windows' => [['start' => '22:00', 'end' => '06:00']]];
        $controller = app(PaymentController::class);
        $result = $controller->save($this->request($p, ['collection_policy' => $policy]));
        $policy['reached_action'] = 'demote';
        $this->assertSame('success', $result->getData(true)['status']);
        $this->assertSame($policy, $p->fresh()->collection_policy);
        $result = $controller->save($this->request($p, ['name' => 'Legacy edit']));
        $this->assertSame('success', $result->getData(true)['status']);
        $this->assertSame($policy, $p->fresh()->collection_policy);
        $this->assertSame(['merchant' => 'keep'], $p->fresh()->config);
        $this->assertTrue($p->fresh()->enable);
        $this->assertSame(7, $p->fresh()->sort);
        $rows = $controller->fetch()->getData(true)['data'];
        $this->assertSame($policy, $rows[0]['collection_policy']);
        $this->assertSame(0, $rows[0]['collection_state']['today_amount']);
    }

    public function test_invalid_policy_never_updates_payment(): void
    {
        $p = $this->payment();
        try {
            app(PaymentController::class)->save($this->request($p, ['name' => 'Invalid update', 'collection_policy' => ['windows' => [['start' => '12:00', 'end' => '12:00']]]]));
            $this->fail('Expected validation failure');
        } catch (ValidationException) {
            $this->assertSame('Test Pay', $p->fresh()->name);
            $this->assertNull($p->fresh()->collection_policy);
        }
    }

    public function test_explicit_reset_restores_unlimited_all_day_policy(): void
    {
        $p = $this->payment();
        $p->collection_policy = ['daily_target' => 1, 'reached_action' => 'pause', 'windows' => [['start' => '09:00', 'end' => '12:00']]];
        $p->save();
        app(PaymentController::class)->save($this->request($p, ['collection_policy' => null]));
        $this->assertSame(['daily_target' => 0, 'reached_action' => 'demote', 'windows' => []], $p->fresh()->collection_policy);
    }

    private function payment(): Payment
    {
        return Payment::create(['uuid' => 'test-pay', 'name' => 'Test Pay', 'payment' => 'TEST', 'config' => ['merchant' => 'keep'], 'enable' => true, 'sort' => 7]);
    }

    private function request(Payment $p, array $overrides): Request
    {
        return Request::create('/payment/save', 'POST', array_replace(['id' => $p->id, 'name' => $p->name, 'payment' => $p->payment, 'config' => $p->config], $overrides));
    }
}
