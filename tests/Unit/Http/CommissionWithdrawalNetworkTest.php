<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controllers\V1\User\TicketController;
use App\Http\Requests\Admin\ConfigSave;
use App\Http\Requests\User\TicketWithdraw;
use App\Http\Resources\TicketResource;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\AgentCommerceContextResolver;
use App\Services\CommissionWithdrawalService;
use App\Services\SiteCommerceService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class CommissionWithdrawalNetworkTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInMemoryDatabase();
        $this->bindJsonResponseFactory();
        $this->bindTestSettings(['commission_withdraw_usdt_networks' => ['TRC20', 'ERC20'], 'commission_withdraw_limit' => 100]);
        $this->createUserTable();
        $this->createTicketTables();
        Schema::table('v2_ticket', fn (Blueprint $table) => $table->integer('reply_status')->nullable());
        $this->migration()->up();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function migration(): object
    {
        return require base_path('database/migrations/2026_10_15_210000_add_commission_withdrawal_details_to_tickets.php');
    }

    private function submit(array $data): object
    {
        $user = User::query()->create(['email' => 'withdrawal@example.test', 'password' => 'test-only', 'commission_balance' => 25000]);
        $agentContext = Mockery::mock(AgentCommerceContextResolver::class);
        $agentContext->shouldReceive('resolveRequest')->andReturn([]);
        app()->instance(AgentCommerceContextResolver::class, $agentContext);
        $siteContext = Mockery::mock(SiteCommerceService::class);
        $siteContext->shouldReceive('contextForRequest')->andReturn([]);
        app()->instance(SiteCommerceService::class, $siteContext);
        $request = TicketWithdraw::create('/api/v1/user/ticket/withdraw', 'POST', $data);
        $request->setUserResolver(fn () => $user);
        $request->setValidator(app('validator')->make($data, $request->rules()));
        return (new TicketController())->withdraw($request);
    }

    public function test_saves_the_explicit_network_and_raw_address_with_a_server_amount_snapshot(): void
    {
        $address = 'T' . str_repeat('A', 33);
        $response = $this->submit(['withdraw_method' => 'USDT', 'withdraw_network' => 'TRC20', 'withdraw_account' => $address, 'amount' => 999999999]);
        $this->assertSame('success', $response->getData(true)['status']);
        $ticket = Ticket::query()->firstOrFail();
        $this->assertSame('TRC20', $ticket->withdrawal['network']);
        $this->assertSame('TRON (TRC20)', $ticket->withdrawal['network_name']);
        $this->assertSame($address, $ticket->withdrawal['account']);
        $this->assertSame(25000, $ticket->withdrawal['amount']);
        $this->assertSame('CNY', $ticket->withdrawal['currency']);
        $this->assertStringContainsString('TRON (TRC20)', TicketMessage::query()->firstOrFail()->message);
        $this->assertStringContainsString('250.00 CNY', TicketMessage::query()->firstOrFail()->message);
        $this->assertSame(25000, (int) User::query()->firstOrFail()->commission_balance);
        $resource = (new TicketResource($ticket))->toArray(Request::create('/'));
        $this->assertSame('TRC20', $resource['withdrawal']['network']);
        $this->assertFalse($resource['withdrawal']['legacy']);
    }

    public static function invalidDestinations(): array
    {
        $tron = 'T' . str_repeat('A', 33);
        $evm = '0x' . str_repeat('a', 40);
        return [
            'missing network' => ['USDT', null, $tron, 'withdraw_network'],
            'disabled network' => ['USDT', 'BEP20', $evm, 'withdraw_network'],
            'disabled polygon' => ['USDT', 'POLYGON', $evm, 'withdraw_network'],
            'unknown network' => ['USDT', 'FAKE', $evm, 'withdraw_network'],
            'tron with evm address' => ['USDT', 'TRC20', $evm, 'withdraw_account'],
            'evm with tron address' => ['USDT', 'ERC20', $tron, 'withdraw_account'],
            'legacy name is not an explicit network' => ['USDT TRC20', null, $tron, 'withdraw_network'],
            'invalid base58' => ['USDT', 'TRC20', 'T' . str_repeat('0', 33), 'withdraw_account'],
            'network on another method' => ['Paypal', 'ERC20', 'payee@example.test', 'withdraw_network'],
        ];
    }

    #[DataProvider('invalidDestinations')]
    public function test_rejects_missing_disabled_and_mismatched_destinations(string $method, ?string $network, string $account, string $field): void
    {
        try {
            app(CommissionWithdrawalService::class)->destination(['withdraw_method' => $method, 'withdraw_network' => $network, 'withdraw_account' => $account]);
            $this->fail('Unsafe destination accepted');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
        $this->assertSame(0, Ticket::query()->count());
    }

    public function test_network_configuration_is_opt_in_and_whitelisted(): void
    {
        $service = app(CommissionWithdrawalService::class);
        $this->assertSame(['TRC20', 'ERC20'], array_column($service->networks(true), 'id'));
        app(\App\Support\Setting::class)->save(['commission_withdraw_usdt_networks' => []]);
        $this->assertSame([], $service->networks(true));
        $this->assertSame(['POLYGON', 'TRC20', 'ERC20', 'BEP20'], array_column($service->networks(), 'id'));
        foreach ([['FAKE'], ['TRC20', 'TRC20'], [null], 'TRC20'] as $value) {
            $this->assertTrue(app('validator')->make(['commission_withdraw_usdt_networks' => $value], ConfigSave::RULES)->fails());
        }
        $this->assertFalse(app('validator')->make(['commission_withdraw_usdt_networks' => ['TRC20', 'BEP20']], ConfigSave::RULES)->fails());
        $this->assertFalse(app('validator')->make(['commission_withdraw_usdt_networks' => CommissionWithdrawalService::NETWORK_IDS], ConfigSave::RULES)->fails());
    }

    public function test_other_methods_do_not_receive_an_invented_network(): void
    {
        $this->submit(['withdraw_method' => 'Paypal', 'withdraw_account' => 'payee@example.test']);
        $this->assertNull(Ticket::query()->firstOrFail()->withdrawal['network']);
    }

    public function test_polygon_requests_keep_the_selected_network_after_configuration_changes(): void
    {
        app(\App\Support\Setting::class)->save(['commission_withdraw_usdt_networks' => ['POLYGON']]);
        $address = '0x' . str_repeat('a', 40);
        $response = $this->submit(['withdraw_method' => 'USDT', 'withdraw_network' => 'POLYGON', 'withdraw_account' => $address]);
        $this->assertSame('success', $response->getData(true)['status']);
        $ticket = Ticket::query()->firstOrFail();
        $this->assertSame('POLYGON', $ticket->withdrawal['network']);
        $this->assertSame('Polygon PoS (POL)', $ticket->withdrawal['network_name']);
        $this->assertSame('USDT', $ticket->withdrawal['method']);
        $this->assertSame($address, $ticket->withdrawal['account']);
        $this->assertStringContainsString('Polygon PoS (POL)', TicketMessage::query()->firstOrFail()->message);
        try {
            app(CommissionWithdrawalService::class)->destination(['withdraw_method' => 'USDT', 'withdraw_network' => 'POLYGON', 'withdraw_account' => 'T' . str_repeat('A', 33)]);
            $this->fail('TRON address accepted for Polygon');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('withdraw_account', $exception->errors());
        }
        app(\App\Support\Setting::class)->save(['commission_withdraw_usdt_networks' => ['ERC20']]);
        $details = (new TicketResource($ticket->fresh()))->toArray(Request::create('/'));
        $this->assertSame('POLYGON', $details['withdrawal']['network']);
        $this->assertSame('Polygon PoS (POL)', $details['withdrawal']['network_name']);
    }

    public function test_evm_address_does_not_determine_or_replace_the_explicit_network(): void
    {
        app(\App\Support\Setting::class)->save(['commission_withdraw_usdt_networks' => ['POLYGON', 'ERC20', 'BEP20']]);
        $service = app(CommissionWithdrawalService::class);
        $address = '0x' . str_repeat('a', 40);
        foreach (['POLYGON', 'ERC20', 'BEP20'] as $network) {
            $destination = $service->destination(['withdraw_method' => 'USDT', 'withdraw_network' => $network, 'withdraw_account' => $address]);
            $this->assertSame($network, $destination['network']);
            $this->assertSame($address, $destination['account']);
        }
        $this->submit(['withdraw_method' => 'USDT', 'withdraw_network' => 'BEP20', 'withdraw_account' => $address]);
        app(\App\Support\Setting::class)->save(['commission_withdraw_usdt_networks' => ['ERC20']]);
        $resource = (new TicketResource(Ticket::query()->firstOrFail()))->toArray(Request::create('/'));
        $this->assertSame('BEP20', $resource['withdrawal']['network']);
        $this->assertSame('BNB Smart Chain (BEP20)', $resource['withdrawal']['network_name']);
    }

    public function test_legacy_requests_are_not_backfilled_and_ordinary_tickets_are_unchanged(): void
    {
        $ticket = Ticket::query()->create(['user_id' => 1, 'subject' => '[Commission Withdrawal Request] This ticket is opened by the system']);
        $resource = (new TicketResource($ticket))->toArray(Request::create('/'));
        $this->assertSame(['legacy' => true], $resource['withdrawal']);
        $this->assertNull($ticket->fresh()->withdrawal);
        $this->assertNull(app(CommissionWithdrawalService::class)->ticketDetails(null, 'Ordinary support'));
        $this->migration()->up();
        $this->assertNull($ticket->fresh()->withdrawal);
    }

    public function test_missing_migration_fails_closed_without_an_orphan_ticket(): void
    {
        $this->migration()->down();
        try {
            $this->submit(['withdraw_method' => 'USDT', 'withdraw_network' => 'TRC20', 'withdraw_account' => 'T' . str_repeat('A', 33)]);
            $this->fail('Withdrawal accepted without storage');
        } catch (\App\Exceptions\ApiException $exception) {
            $this->assertStringContainsString('storage is not ready', $exception->getMessage());
        }
        $this->assertSame(0, Ticket::query()->count());
        $this->assertSame(0, TicketMessage::query()->count());
    }
}
