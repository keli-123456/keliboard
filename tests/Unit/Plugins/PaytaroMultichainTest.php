<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins;

use App\Exceptions\ApiException;
use App\Services\PaytaroChannelService;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Plugin\Paytaro\Plugin;
use Psr\Log\NullLogger;
use Tests\TestCase;

final class PaytaroMultichainTest extends TestCase
{
    private const METHOD = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';
    private const PAY = 'https://v3.paytaro.com/v1/invoice/pay';
    private const METHODS = 'https://v3.paytaro.com/v1/app/methods';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        app()->instance('log', new NullLogger());
    }

    #[DataProvider('channels')]
    public function test_channel_selection_and_checkout_agree_without_changing_recipient_or_precision(
        string $currency, string $type, string $network, string $address, string $amount, bool $fromMetadata,
    ): void {
        $method = $this->method($currency, $type);
        $invoice = $this->invoice($method, $address, $amount);
        if ($fromMetadata) {
            unset($invoice['payment']['type']);
        }
        Http::fake([self::PAY => Http::response($invoice), self::METHODS => Http::response([
            'app' => ['app_id' => 'app-1', 'currency' => 'CNY'],
            'methods' => [array_replace($method, ['uuid' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', 'type' => 'other-chain']), $method],
        ])]);
        $channel = app(PaytaroChannelService::class)->fetch('app-1', 'test-secret')[1];
        $this->assertTrue($channel['available']);
        $this->assertSame($network, $channel['network']);
        $data = $this->plugin()->pay($this->order())['data'];
        $this->assertSame($network, $data['network']);
        $this->assertSame($currency, $data['currency']);
        $this->assertSame($amount, $data['amount']);
        $this->assertSame('10.50', $data['fiat_amount']);
        $this->assertSame($address, $data['address']);
        $this->assertSame($address, $data['qr_data']);
        $this->assertSame('', $data['payment_url']);
        $this->assertSame('', $data['mobile_url']);
        $this->assertSame(1800, $data['expires_in']);
        $this->assertStringNotContainsString('OpaqueToken', json_encode($data));
        Http::assertSent(fn ($r) => $r->url() === self::PAY && $r['method_uuid'] === self::METHOD
            && $r['order_amount'] === 10.5);
        $this->assertCount(1, Http::recorded(fn ($r) => $r->method() === 'POST'));
        Http::assertSentCount($fromMetadata ? 3 : 2);
    }

    public static function channels(): iterable
    {
        $evm = '0x0123456789abcdef0123456789abcdef01234567';
        $tron = 'TTestRecipient1234567890123456789012';
        $solana = '8TestRecipient123456789ABCDEFGHJKLMNPQRST';
        $cases = [
            ['USDT', 'TRON:MAINNET:OpaqueToken', 'TRON MAINNET', $tron, '16.906408'],
            ['USDT', 'BSC:MAINNET:OpaqueToken', 'BNB SMART CHAIN (BEP20) MAINNET', $evm, '16.906408123456789012'],
            ['USDT', 'POLYGON:MAINNET:OpaqueToken', 'POLYGON MAINNET', $evm, '16.906408'],
            ['USDC', 'EVM:56:OpaqueToken', 'BNB SMART CHAIN (BEP20) MAINNET', $evm, '0.123456789012345678'],
            ['USDC', 'EVM:137:OpaqueToken', 'POLYGON MAINNET', $evm, '16.906408'],
            ['USDC', 'SOLANA:MAINNET-BETA:OpaqueToken', 'SOLANA MAINNET-BETA', $solana, '16.906408'],
            ['TRX', 'TRON:MAINNET:NATIVE', 'TRON MAINNET', $tron, '0.000001'],
            ['BNB', 'EVM:56:NATIVE', 'BNB SMART CHAIN (BEP20) MAINNET', $evm, '0.000000000000000001'],
            ['SOL', 'SOLANA:MAINNET:NATIVE', 'SOLANA MAINNET', $solana, '0.000000001'],
            ['USDT', 'EVM:97:OpaqueToken', 'BNB SMART CHAIN TESTNET (97)', $evm, '1.000001'],
            ['USDC', 'EVM:80002:OpaqueToken', 'POLYGON AMOY TESTNET (80002)', $evm, '1.000001'],
            ['SOL', 'solana:devnet:native', 'SOLANA DEVNET', $solana, '0.000000001'],
            ['USDT', 'BEP20', 'BEP20', $evm, '1.000001'],
            ['USDC', 'polygon', 'POLYGON', $evm, '1.000001'],
            ['SOL', 'solana', 'SOLANA', $solana, '1.000000001'],
        ];
        foreach ($cases as $index => $case) {
            foreach ([false, true] as $metadata) {
                yield $index . ($metadata ? '-metadata' : '-invoice') => [...$case, $metadata];
            }
        }
    }

    #[DataProvider('unsupportedNetworks')]
    public function test_picker_and_order_both_reject_ambiguous_or_invalid_descriptors(mixed $type): void
    {
        $method = $this->method('USDT', $type);
        Http::fake([self::PAY => Http::response($this->invoice($method, 'TTestRecipient', '1.000001')),
            self::METHODS => Http::response(['app' => ['app_id' => 'app-1'], 'methods' => [$method]])]);
        $channel = app(PaytaroChannelService::class)->fetch('app-1', 'test-secret')[0];
        $this->assertFalse($channel['available']);
        $this->assertSame('unsupported', $channel['unavailable_reason']);
        $this->assertNull($channel['network']);
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('PT_CRYPTO_NETWORK');
        try {
            $this->plugin()->pay($this->order());
        } finally {
            Http::assertSentCount(2);
        }
    }

    public static function unsupportedNetworks(): array
    {
        return [['EVM:MAINNET:Token'], ['EVM:0:Token'], ['EVM:056:Token'], ['EVM:999999:Token'],
            ['BSC:MAINNET:'], ['SOLANA:MAINNET:Token:extra'], ['SOLANA::Token'], ['POLYGON:137:Token'],
            ['UNKNOWN:MAINNET:Token'], ['SOLANA:MAINNET:<script>'], ["BSC:MAINNET:Token\n"],
            [str_repeat('x', 241)], [false], [[]]];
    }

    private function method(string $currency, mixed $type): array
    {
        return ['uuid' => self::METHOD, 'name' => $currency, 'type' => $type,
            'currency_type' => 'CRYPTO', 'pay_currency' => $currency, 'show' => true];
    }

    private function invoice(array $method, string $address, string $amount): array
    {
        return ['merchant_no' => 'ORDER_TEST', 'uuid' => 'cccccccc-cccc-cccc-cccc-cccccccccccc',
            'status' => 'UNPAID', 'order_currency' => 'CNY', 'order_amount' => '10.50',
            'server_time' => 1700000000, 'expired_at' => 1700001800,
            'payment' => array_replace($method, ['pay_amount' => $amount, 'data' => $address, 'link_type' => 'address'])];
    }

    private function plugin(): Plugin
    {
        $plugin = new Plugin('paytaro');
        $plugin->setConfig(['app_id' => 'app-1', 'app_secret' => 'test-secret', 'method_uuid' => self::METHOD]);
        return $plugin;
    }

    private function order(): array
    {
        return ['trade_no' => 'ORDER_TEST', 'total_amount' => 1050,
            'notify_url' => 'https://panel.example.test/notify', 'return_url' => 'https://panel.example.test/return'];
    }
}
