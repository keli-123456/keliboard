<?php

namespace Tests\Unit\Http;

use App\Http\Controllers\V2\Admin\PaymentController;
use App\Models\Payment;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class PaymentDeletionTest extends TestCase
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

    public function test_deleted_id_disappears_from_fresh_list_but_same_name_payment_remains(): void
    {
        $first = $this->payment('first');
        $second = $this->payment('second');
        $controller = app(PaymentController::class);
        $this->assertCount(2, $controller->fetch()->getData(true)['data']);
        $result = $controller->drop(Request::create('/payment/drop', 'POST', ['id' => $first->id]));
        $this->assertSame(true, $result->getData(true)['data']);
        $this->assertNull($first->fresh());
        $this->assertSame([$second->id], array_column($controller->fetch()->getData(true)['data'], 'id'));
    }

    public function test_vetoed_delete_returns_failure_instead_of_success_false(): void
    {
        $payment = $this->payment('veto');
        $previous = Payment::getEventDispatcher();
        Payment::setEventDispatcher(new Dispatcher(app()));
        Payment::deleting(fn () => false);
        try {
            $response = app(PaymentController::class)->drop(Request::create('/payment/drop', 'POST', ['id' => $payment->id]));
            $this->assertSame('fail', $response->getData(true)['status']);
            $this->assertSame(500, $response->getStatusCode());
            $this->assertNotNull($payment->fresh());
        } finally {
            $previous ? Payment::setEventDispatcher($previous) : Payment::unsetEventDispatcher();
        }
    }

    public function test_missing_id_is_rejected_without_deleting_other_payments(): void
    {
        $this->payment('keep');
        $response = app(PaymentController::class)->drop(Request::create('/payment/drop', 'POST', ['id' => 999]));
        $this->assertSame('fail', $response->getData(true)['status']);
        $this->assertSame(1, Payment::count());
    }

    public function test_array_id_is_rejected_as_invalid_input(): void
    {
        $payment = $this->payment('keep');
        $this->expectException(ValidationException::class);
        app(PaymentController::class)->drop(Request::create('/payment/drop', 'POST', ['id' => [$payment->id]]));
    }

    public function test_payment_list_is_never_cacheable(): void
    {
        $response = app(PaymentController::class)->fetch();
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertTrue($response->headers->hasCacheControlDirective('private'));
    }

    private function payment(string $uuid): Payment
    {
        return Payment::create(['uuid' => $uuid, 'name' => 'Same name', 'payment' => 'EPay', 'enable' => true]);
    }
}
