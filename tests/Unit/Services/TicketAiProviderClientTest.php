<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\TicketAiProviderException;
use App\Services\TicketAiProviderClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class TicketAiProviderClientTest extends TestCase
{
    #[DataProvider('structuredContentProvider')]
    public function test_structured_json_is_decoded_from_common_provider_formats(string $content): void
    {
        Http::fake([
            '*' => Http::response($this->responsePayload($content, 12, 8)),
        ]);

        $result = (new TicketAiProviderClient())->complete($this->settings(), [
            ['role' => 'user', 'content' => '请分析工单'],
        ]);

        $this->assertTrue($result['structured']);
        $this->assertSame('订阅问题', $result['decoded']['category']);
        $this->assertSame(12, $result['input_tokens']);
        $this->assertSame(8, $result['output_tokens']);
        $this->assertSame(20, $result['total_tokens']);
        $this->assertGreaterThanOrEqual(0, $result['latency_ms']);
    }

    public static function structuredContentProvider(): array
    {
        $json = '{"category":"订阅问题","draft":"请重新导入"}';

        return [
            'direct' => [$json],
            'fenced' => ["```json\n{$json}\n```"],
            'embedded' => ["分析完成，结果如下：{$json}\n请人工确认。"],
        ];
    }

    #[DataProvider('invalidStructuredContentProvider')]
    public function test_json_without_a_valid_draft_schema_is_rejected(string $content): void
    {
        Http::fake(['*' => Http::response($this->responsePayload($content))]);

        try {
            (new TicketAiProviderClient())->complete($this->settings(), [
                ['role' => 'user', 'content' => '请分析工单'],
            ]);
            $this->fail('Expected invalid provider response.');
        } catch (TicketAiProviderException $exception) {
            $this->assertSame('invalid_response', $exception->errorCode());
        }
    }

    public static function invalidStructuredContentProvider(): array
    {
        return [
            'missing draft' => ['{"message":"ok"}'],
            'empty draft' => ['{"draft":"  "}'],
            'array draft' => ['{"draft":[]}'],
            'invalid boolean field' => ['{"draft":"ok","needs_human":"no"}'],
            'invalid reference field' => ['{"draft":"ok","knowledge_refs":{"id":1}}'],
        ];
    }

    public function test_plain_text_falls_back_without_claiming_structured_output(): void
    {
        Http::fake(['*' => Http::response($this->responsePayload('请让用户重新导入订阅。'))]);

        $result = (new TicketAiProviderClient())->complete($this->settings(), [
            ['role' => 'user', 'content' => '订阅失败'],
        ]);

        $this->assertFalse($result['structured']);
        $this->assertNull($result['decoded']);
        $this->assertSame('请让用户重新导入订阅。', $result['content']);
    }

    public function test_request_clamps_controls_and_enables_optional_json_mode(): void
    {
        Http::fake(['*' => Http::response($this->responsePayload('{"draft":"ok"}'))]);

        (new TicketAiProviderClient())->complete($this->settings([
            'max_tokens' => 99999,
            'timeout' => 1,
            'json_mode' => true,
        ]), [['role' => 'user', 'content' => 'hello']]);

        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return $request->url() === 'https://ai.example.test/v1/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer sk-test')
                && $payload['max_tokens'] === 4096
                && $payload['response_format'] === ['type' => 'json_object'];
        });
    }

    #[DataProvider('httpErrorProvider')]
    public function test_http_failures_are_mapped_without_exposing_response_body(int $status, string $code): void
    {
        Http::fake(['*' => Http::response(['secret' => 'provider-body-must-not-leak'], $status)]);

        try {
            (new TicketAiProviderClient())->complete($this->settings(), [['role' => 'user', 'content' => 'x']]);
            $this->fail('Expected provider exception.');
        } catch (TicketAiProviderException $exception) {
            $this->assertSame($code, $exception->errorCode());
            $this->assertStringNotContainsString('provider-body-must-not-leak', $exception->getMessage());
        }
    }

    public static function httpErrorProvider(): array
    {
        return [
            'unauthorized' => [401, 'authentication'],
            'forbidden' => [403, 'authentication'],
            'rate limited' => [429, 'rate_limited'],
            'server error' => [500, 'upstream'],
        ];
    }

    public function test_missing_content_is_an_invalid_response(): void
    {
        Http::fake(['*' => Http::response(['choices' => []])]);

        $this->expectException(TicketAiProviderException::class);
        $this->expectExceptionMessage('invalid_response');

        (new TicketAiProviderClient())->complete($this->settings(), [['role' => 'user', 'content' => 'x']]);
    }

    public function test_connection_errors_are_normalized(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect'));

        try {
            (new TicketAiProviderClient())->complete($this->settings(), [['role' => 'user', 'content' => 'x']]);
            $this->fail('Expected connection exception.');
        } catch (TicketAiProviderException $exception) {
            $this->assertSame('connection', $exception->errorCode());
        }
    }

    public function test_timeout_errors_are_normalized(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        try {
            (new TicketAiProviderClient())->complete($this->settings(), [['role' => 'user', 'content' => 'x']]);
            $this->fail('Expected timeout exception.');
        } catch (TicketAiProviderException $exception) {
            $this->assertSame('timeout', $exception->errorCode());
        }
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('unsafeEndpointProvider')]
    public function test_protected_provider_endpoints_are_rejected(string $url): void
    {
        $client = new TicketAiProviderClient();

        $this->assertSame('unsafe_endpoint', $client->endpointSafetyReason($url));
    }

    public static function unsafeEndpointProvider(): array
    {
        return [
            'loopback' => ['http://127.0.0.1:11434/v1'],
            'private address' => ['http://10.0.0.2/v1'],
            'local hostname' => ['http://localhost/v1'],
            'cloud metadata' => ['http://169.254.169.254/latest'],
            'url credentials' => ['https://admin:secret@ai.example.test/v1'],
        ];
    }

    public function test_private_provider_requires_explicit_opt_in(): void
    {
        $client = new TicketAiProviderClient();

        $this->assertNull($client->endpointSafetyReason('http://127.0.0.1:11434/v1', true));
        $this->assertSame(
            'unsafe_endpoint',
            $client->endpointSafetyReason('http://169.254.169.254/latest', true)
        );
    }

    public function test_unsafe_endpoint_is_blocked_before_an_http_request_is_sent(): void
    {
        Http::fake();

        try {
            (new TicketAiProviderClient())->complete($this->settings([
                'base_url' => 'http://127.0.0.1:11434/v1',
            ]), [['role' => 'user', 'content' => 'x']]);
            $this->fail('Expected unsafe endpoint exception.');
        } catch (TicketAiProviderException $exception) {
            $this->assertSame('unsafe_endpoint', $exception->errorCode());
        }

        Http::assertNothingSent();
    }

    private function settings(array $overrides = []): array
    {
        return array_merge([
            'base_url' => 'https://ai.example.test/v1',
            'api_key' => 'sk-test',
            'model' => 'test-model',
            'temperature' => 0.2,
            'max_tokens' => 800,
            'timeout' => 30,
            'json_mode' => false,
        ], $overrides);
    }

    private function responsePayload(string $content, int $inputTokens = 0, int $outputTokens = 0): array
    {
        return [
            'choices' => [[
                'message' => ['content' => $content],
            ]],
            'usage' => [
                'prompt_tokens' => $inputTokens,
                'completion_tokens' => $outputTokens,
                'total_tokens' => $inputTokens + $outputTokens,
            ],
        ];
    }
}
