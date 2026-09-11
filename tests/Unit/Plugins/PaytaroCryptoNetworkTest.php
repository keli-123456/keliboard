<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins;

use App\Exceptions\ApiException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Plugin\Paytaro\Plugin;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Tests\TestCase;

final class PaytaroCryptoNetworkTest extends TestCase
{
    private const METHOD = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';
    private const PAY = 'https://v3.paytaro.com/v1/invoice/pay';
    private const METHODS = 'https://v3.paytaro.com/v1/app/methods';
    private const TRON_TYPE = 'TRON:MAINNET:TR7NHQJEKQXGTCI8Q8ZY4PL8OTSZGJLJ6T';
    private Plugin $plugin;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        app()->instance('log', new NullLogger());
        $this->plugin = new Plugin('paytaro');
        $this->plugin->setConfig(['app_id' => 'app-1', 'app_secret' => 'test-secret', 'method_uuid' => self::METHOD]);
    }

    #[DataProvider('networkNames')]
    public function test_network_display_names_do_not_require_a_lookup(string $type, string $expected): void
    {
        Http::fake([self::PAY => Http::response($this->invoice(['type' => $type]))]);
        $this->assertSame($expected, $this->plugin->pay($this->order())['data']['network']);
        Http::assertSentCount(1);
    }

    public static function networkNames(): array
    {
        return [['tron', 'TRON'], [' TRON ', 'TRON'], ['Tron (TRC20)', 'TRON (TRC20)'],
            ['波场（TRC20）', '波场（TRC20）'], ['Arbitrum One', 'ARBITRUM ONE'], ['chain_1', 'CHAIN_1'],
            [' tron:mainnet:TestToken ', 'TRON MAINNET'], ['TRON:TESTNET:TestToken', 'TRON TESTNET'],
            ['TRON:NILE:TestToken', 'TRON NILE']];
    }

    #[DataProvider('structuredNetworkSources')]
    public function test_structured_tron_descriptor_never_replaces_the_recipient_address(bool $fromMetadata): void
    {
        $invoice = $this->invoice(['currency_type' => 'CRYPTO', 'name' => 'USDT TRC20']);
        if (!$fromMetadata) {
            $invoice['payment']['type'] = self::TRON_TYPE;
        }
        $metadata = self::metadata();
        $metadata['methods'][0] = array_replace($metadata['methods'][0], [
            'type' => self::TRON_TYPE, 'name' => 'USDT TRC20', 'currency_type' => 'CRYPTO',
        ]);
        Http::fake([self::PAY => Http::response($invoice), self::METHODS => Http::response($metadata)]);

        $data = $this->plugin->pay($this->order())['data'];

        $this->assertSame('TRON MAINNET', $data['network']);
        $this->assertSame('USDT', $data['currency']);
        $this->assertSame('16.906408', $data['amount']);
        $this->assertSame('10.50', $data['fiat_amount']);
        $this->assertSame($invoice['payment']['data'], $data['address']);
        $this->assertSame($invoice['payment']['data'], $data['qr_data']);
        $this->assertSame('', $data['payment_url']);
        $this->assertSame('', $data['mobile_url']);
        $this->assertStringNotContainsString(explode(':', self::TRON_TYPE)[2], json_encode($data));
        Http::assertSent(fn ($request) => $request->url() === self::PAY && $request['method_uuid'] === self::METHOD);
        Http::assertSentCount($fromMetadata ? 2 : 1);
    }

    public static function structuredNetworkSources(): array
    {
        return ['order-response' => [false], 'matched-channel-metadata' => [true]];
    }

    public function test_single_structured_tron_channel_cannot_override_a_different_configured_uuid(): void
    {
        $metadata = self::metadata();
        $metadata['methods'][0] = array_replace($metadata['methods'][0], [
            'uuid' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', 'type' => self::TRON_TYPE,
            'name' => 'USDT TRC20', 'currency_type' => 'CRYPTO',
        ]);
        Http::fake([self::PAY => Http::response($this->invoice()), self::METHODS => Http::response($metadata)]);
        $this->expectExceptionMessage('PT_CRYPTO_NETWORK_CHANNEL');
        try {
            $this->plugin->pay($this->order());
        } finally {
            Http::assertSent(fn ($request) => $request->url() === self::PAY && $request['method_uuid'] === self::METHOD);
            Http::assertSentCount(2);
        }
    }

    #[DataProvider('missingTypes')]
    public function test_missing_network_uses_only_the_selected_channel_and_preserves_payment(array $type): void
    {
        $options = null;
        Http::fake([
            self::PAY => Http::response($this->invoice($type)),
            self::METHODS => function ($request, array $requestOptions) use (&$options) {
                $options = $requestOptions;
                $metadata = $this->metadata();
                array_unshift($metadata['methods'], array_replace($metadata['methods'][0], [
                    'uuid' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', 'type' => 'ethereum',
                ]));
                return Http::response($metadata);
            },
        ]);
        $data = $this->plugin->pay($this->order())['data'];
        $this->assertSame('TRON', $data['network']);
        $this->assertSame('USDT', $data['currency']);
        $this->assertSame('16.906408', $data['amount']);
        $this->assertSame('10.50', $data['fiat_amount']);
        $this->assertSame($this->invoice()['payment']['data'], $data['qr_data']);
        $this->assertSame($data['qr_data'], $data['address']);
        $this->assertSame('', $data['payment_url']);
        $this->assertSame('', $data['mobile_url']);
        $this->assertSame(1800, $data['expires_in']);
        $this->assertTrue($options['verify']);
        $this->assertFalse($options['allow_redirects']);
        $this->assertSame(5, $options['timeout']);
        $this->assertSame(3, $options['connect_timeout']);
        Http::assertSent(fn ($request) => $request->url() === self::METHODS && $request->method() === 'GET'
            && $request->hasHeader('X-App-Secret', 'test-secret') && $request->data() === []);
        Http::assertSentCount(2);
        $this->assertCount(1, Http::recorded(fn ($request) => $request->method() === 'POST'));
    }

    public static function missingTypes(): array
    {
        return [[[]], [['type' => null]], [['type' => '']], [['type' => '  ']]];
    }

    #[DataProvider('selectedMethodNames')]
    public function test_authenticated_channel_metadata_resolves_explicit_network(array $changes, string $expected): void
    {
        $metadata = $this->metadata();
        $metadata['methods'][0] = array_replace($metadata['methods'][0], $changes);
        Http::fake([self::PAY => Http::response($this->invoice()), self::METHODS => Http::response($metadata)]);
        $this->assertSame($expected, $this->plugin->pay($this->order())['data']['network']);
        Http::assertSentCount(2);
    }

    public static function selectedMethodNames(): array
    {
        return [
            [['type' => 'Tron (TRC20)'], 'TRON (TRC20)'],
            [['type' => '波场（TRC20）'], '波场（TRC20）'],
            [['type' => null, 'name' => 'USDT-TRC20'], 'TRON'],
            [['type' => '', 'name' => 'USDT TRC20'], 'TRON'],
            [['type' => '  ', 'name' => 'usdt-trc-20'], 'TRON'],
        ];
    }

    #[DataProvider('invalidTypes')]
    public function test_invalid_nonempty_network_is_not_overridden_by_another_source(mixed $type): void
    {
        Http::fake([self::PAY => Http::response($this->invoice(['type' => $type]))]);
        $this->expectExceptionMessage('PT_CRYPTO_NETWORK');
        try {
            $this->plugin->pay($this->order());
        } finally {
            Http::assertSentCount(1);
        }
    }

    public static function invalidTypes(): array
    {
        return [[false], [[]], [42], ['<script>'], ['https://example.test/tron'], ["TRON\nERC20"],
            ["TRON\u{202e}"], [str_repeat('x', 81)], [str_repeat('网', 81)], ['TRC20/ERC20'],
            ['TRON:MAINNET'], ['TRON::TestToken'], ['TRON:MAINNET:'], ['TRON:MAINNET:TestToken:extra'],
            ['TRON:MAINNET:<script>'], ['TRON:MAINNET:https://example.test'], ['TRON:MAIN NET:TestToken'],
            ['TRON:MAINNET:' . str_repeat('x', 129)], ['TRON:' . str_repeat('x', 33) . ':TestToken'],
            ['https:MAINNET:TestToken'], ['javascript:MAINNET:TestToken']];
    }

    #[DataProvider('invalidMetadata')]
    public function test_untrusted_or_ambiguous_metadata_never_supplies_a_network(array $metadata, string $reason): void
    {
        Http::fake([self::PAY => Http::response($this->invoice()), self::METHODS => Http::response($metadata)]);
        $this->expectExceptionMessage($reason);
        try {
            $this->plugin->pay($this->order());
        } finally {
            Http::assertSentCount(2);
        }
    }

    public static function invalidMetadata(): array
    {
        $base = self::metadata();
        $cases = [
            [[], 'PT_CRYPTO_NETWORK_LOOKUP'],
            [array_replace($base, ['app' => ['app_id' => 'other-merchant']]), 'PT_CRYPTO_NETWORK_LOOKUP'],
            [array_replace($base, ['methods' => null]), 'PT_CRYPTO_NETWORK_LOOKUP'],
            [array_replace($base, ['methods' => []]), 'PT_CRYPTO_NETWORK_CHANNEL'],
            [array_replace($base, ['methods' => [$base['methods'][0], $base['methods'][0]]]), 'PT_CRYPTO_NETWORK_CHANNEL'],
        ];
        foreach ([['uuid' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa'], ['show' => false], ['show' => 'true'],
            ['currency_type' => 'fiat'], ['pay_currency' => 'BTC']] as $changes) {
            $metadata = $base;
            $metadata['methods'][0] = array_replace($metadata['methods'][0], $changes);
            $cases[] = [$metadata, 'PT_CRYPTO_NETWORK_CHANNEL'];
        }
        foreach ([['type' => '<script>'], ['type' => false], ['type' => ''], ['type' => null, 'name' => 'USDT'],
            ['type' => null, 'name' => 'USDT TRC20 / ERC20']] as $changes) {
            $metadata = $base;
            $metadata['methods'][0] = array_replace($metadata['methods'][0], ['name' => 'USDT'], $changes);
            $cases[] = [$metadata, 'PT_CRYPTO_NETWORK'];
        }
        return $cases;
    }

    #[DataProvider('lookupFailures')]
    public function test_lookup_failures_do_not_retry_orders_or_expose_upstream_content(int $status): void
    {
        $logged = null;
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->willReturnCallback(
            function ($message, array $context) use (&$logged): void { $logged = $context; }
        );
        app()->instance('log', $logger);
        Http::fake([
            self::PAY => Http::response($this->invoice()),
            self::METHODS => Http::response('private-metadata-test-secret', $status, ['Location' => 'https://evil.test']),
        ]);
        try {
            $this->plugin->pay($this->order());
            $this->fail('Unavailable method metadata was accepted.');
        } catch (ApiException $exception) {
            $this->assertStringContainsString('PT_CRYPTO_NETWORK_LOOKUP', $exception->getMessage());
            $serialized = $exception->getMessage() . json_encode($logged);
            foreach (['test-secret', 'private-metadata', self::METHOD, 'trade-1', $this->invoice()['payment']['data']] as $secret) {
                $this->assertStringNotContainsString($secret, $serialized);
            }
        }
        $this->assertSame('PT_CRYPTO_NETWORK_LOOKUP', $logged['reason']);
        Http::assertSentCount(2);
    }

    public static function lookupFailures(): array
    {
        return [[200], [302], [401], [429], [500]];
    }

    public function test_lookup_timeout_remains_a_safe_payment_error(): void
    {
        Http::fake([self::PAY => Http::response($this->invoice()), self::METHODS => Http::failedConnection('test-secret')]);
        $this->expectExceptionMessage('PT_CRYPTO_NETWORK_LOOKUP');
        $this->plugin->pay($this->order());
    }

    private static function metadata(): array
    {
        return ['app' => ['app_id' => 'app-1'], 'methods' => [[
            'uuid' => self::METHOD, 'type' => 'tron', 'name' => 'USDT-TRC20',
            'currency_type' => 'crypto', 'pay_currency' => 'USDT', 'show' => true,
        ]]];
    }

    private function invoice(array $changes = []): array
    {
        return ['merchant_no' => 'trade-1', 'uuid' => 'e5b62e61-1dff-41ed-b6ce-45404b0b60da',
            'status' => 'UNPAID', 'order_currency' => 'CNY', 'order_amount' => 10.5,
            'expired_at' => 1700001800, 'server_time' => 1700000000,
            'payment' => array_replace(['data' => 'T' . str_repeat('1', 33), 'pay_amount' => 16.906408,
                'currency_type' => 'crypto', 'pay_currency' => 'USDT', 'link_type' => 'address', 'mobile_url' => null], $changes)];
    }

    private function order(): array
    {
        return ['trade_no' => 'trade-1', 'total_amount' => 1050, 'notify_url' => 'https://notify.example.test/notify'];
    }
}
