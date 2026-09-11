<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\ApiException;
use App\Services\PaytaroChannelService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class PaytaroChannelServiceTest extends TestCase
{
    private const UUID = 'ef3072be-a160-44ad-8a33-e29657707fb1';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_only_reads_current_app_channels_and_returns_safe_fields(): void
    {
        Http::beforeSending(function ($request, $options) {
            $this->assertTrue($options['verify']);
            $this->assertFalse($options['allow_redirects']);
            $this->assertSame(10, $options['timeout']);
            $this->assertSame(3, $options['connect_timeout']);
        });
        $body = $this->catalog();
        $body['app']['app_secret'] = 'upstream-secret';
        $body['methods'][0]['private_data'] = 'private';
        $body['methods'][] = array_replace($body['methods'][0], [
            'uuid' => '0d1d1e40-85e2-4ab6-903d-aacae38e562c', 'name' => 'USDT TRC20',
            'type' => 'TRON:MAINNET:TR7NHQJEKQXGTCI8Q8ZY4PL8OTSZGJLJ6T', 'pay_currency' => 'USDT', 'currency_type' => 'CRYPTO',
        ]);
        Http::fake(['*' => Http::response($body)]);

        $result = app(PaytaroChannelService::class)->fetch(' app-test ', ' secret-test ');

        $this->assertCount(2, $result);
        $this->assertSame(self::UUID, $result[0]['uuid']);
        $this->assertTrue($result[0]['available']);
        $this->assertTrue($result[1]['available']);
        $this->assertSame('crypto', $result[1]['currency_type']);
        $this->assertSame('USDT', $result[1]['pay_currency']);
        $this->assertStringNotContainsString('secret', json_encode($result));
        $this->assertArrayNotHasKey('private_data', $result[0]);
        Http::assertSent(fn ($r) => $r->method() === 'GET' && $r->url() === 'https://v3.paytaro.com/v1/app/methods'
            && $r->hasHeader('X-App-Secret', 'secret-test') && $r->data() === []);
        Http::assertSentCount(1);
    }

    public function test_hidden_and_unsupported_channels_are_not_selectable(): void
    {
        $body = $this->catalog();
        $body['methods'][0]['show'] = false;
        $body['methods'][] = array_replace($body['methods'][0], ['uuid' => '00000000-0000-4000-8000-000000000002', 'show' => true, 'type' => 'unknown']);
        Http::fake(['*' => Http::response($body)]);
        $result = app(PaytaroChannelService::class)->fetch('app-test', 'secret-test');
        $this->assertFalse($result[0]['available']);
        $this->assertSame('hidden', $result[0]['unavailable_reason']);
        $this->assertFalse($result[1]['available']);
        $this->assertSame('unsupported', $result[1]['unavailable_reason']);
    }

    public function test_empty_list_is_not_replaced_with_a_default_channel(): void
    {
        Http::fake(['*' => Http::response(array_replace($this->catalog(), ['methods' => []]))]);
        $this->assertSame([], app(PaytaroChannelService::class)->fetch('app-test', 'secret-test'));
        Http::assertSentCount(1);
    }

    #[DataProvider('invalidCredentials')]
    public function test_invalid_credentials_never_send_a_request(string $id, string $secret): void
    {
        try {
            app(PaytaroChannelService::class)->fetch($id, $secret);
            $this->fail('Expected validation failure');
        } catch (ApiException) {
            Http::assertNothingSent();
        }
    }

    public static function invalidCredentials(): array
    {
        return [['', 'secret'], ['app', ''], ['app', "secret\r\nInjected: true"], ['app', str_repeat('x', 513)], [str_repeat('a', 129), 'secret']];
    }

    #[DataProvider('invalidCatalogs')]
    public function test_rejects_mismatched_or_ambiguous_channel_data(string $field, mixed $value): void
    {
        $body = $this->catalog();
        data_set($body, $field, $value);
        Http::fake(['*' => Http::response($body)]);
        $this->expectException(ApiException::class);
        app(PaytaroChannelService::class)->fetch('app-test', 'secret-test');
    }

    public static function invalidCatalogs(): array
    {
        return [['app.app_id', 'another-app'], ['app.currency', 'USD'], ['methods', null], ['methods', ['wrong-key' => []]],
            ['methods.0.uuid', 'invalid'], ['methods.1.uuid', strtoupper(self::UUID)], ['methods', array_fill(0, 201, [])]];
    }

    #[DataProvider('errorStatuses')]
    public function test_gateway_errors_do_not_expose_secrets_or_follow_redirects(int $status): void
    {
        Http::fake(['*' => Http::response(['error' => 'private-secret'], $status, ['Location' => 'https://untrusted.example.test'])]);
        try {
            app(PaytaroChannelService::class)->fetch('app-test', 'secret-test');
            $this->fail('Expected lookup failure');
        } catch (ApiException $e) {
            $this->assertStringNotContainsString('private-secret', $e->getMessage());
            $this->assertStringNotContainsString('secret-test', $e->getMessage());
            Http::assertSentCount(1);
        }
    }

    public static function errorStatuses(): array
    {
        return [[302], [401], [403], [429], [500]];
    }

    public function test_connection_error_is_sanitized_without_retries(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            throw new ConnectionException('X-App-Secret: secret-test');
        });
        try {
            app(PaytaroChannelService::class)->fetch('app-test', 'secret-test');
            $this->fail('Expected lookup failure');
        } catch (ApiException $e) {
            $this->assertStringNotContainsString('secret-test', $e->getMessage());
            $this->assertSame(1, $calls);
        }
    }

    private function catalog(): array
    {
        return ['app' => ['app_id' => 'app-test', 'currency' => 'CNY'], 'methods' => [[
            'uuid' => self::UUID, 'name' => 'Alipay', 'type' => 'alipay', 'currency_type' => 'fiat', 'pay_currency' => 'CNY', 'show' => true,
        ]]];
    }
}
