<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\SubscriptionProxy\LetsEncryptAcmeClient;
use App\Support\Setting;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class LetsEncryptAcmeClientTest extends TestCase
{
    public function test_creates_short_lived_ip_order_with_acme_jws(): void
    {
        app()->instance(Setting::class, new class extends Setting {
            public function __construct() {}

            public function get(string $key, mixed $default = null): mixed
            {
                return [
                    'letsencrypt_environment' => 'production',
                    'letsencrypt_email' => 'admin@example.com',
                ][strtolower($key)] ?? $default;
            }
        });

        $orderPayload = null;
        Http::fake(function ($request) use (&$orderPayload) {
            if ($request->method() === 'GET' && str_ends_with($request->url(), '/directory')) {
                return Http::response([
                    'newNonce' => 'https://acme.test/new-nonce',
                    'newAccount' => 'https://acme.test/new-account',
                    'newOrder' => 'https://acme.test/new-order',
                    'meta' => ['profiles' => ['shortlived' => 'six day certificate']],
                ]);
            }
            if ($request->method() === 'HEAD') {
                return Http::response('', 200, ['Replay-Nonce' => 'nonce-1']);
            }
            if ($request->url() === 'https://acme.test/new-account') {
                return Http::response(['status' => 'valid'], 201, [
                    'Location' => 'https://acme.test/account/1',
                    'Replay-Nonce' => 'nonce-2',
                ]);
            }
            if ($request->url() === 'https://acme.test/new-order') {
                $jws = json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);
                $orderPayload = json_decode($this->decodeBase64URL((string) $jws['payload']), true, 512, JSON_THROW_ON_ERROR);
                return Http::response([
                    'status' => 'pending',
                    'authorizations' => ['https://acme.test/authz/1'],
                    'finalize' => 'https://acme.test/order/1/finalize',
                ], 201, ['Location' => 'https://acme.test/order/1']);
            }
            return Http::response(['detail' => 'unexpected request'], 500);
        });

        $directory = sys_get_temp_dir() . '/keliboard-acme-' . bin2hex(random_bytes(6));
        mkdir($directory, 0700, true);
        $privateKeyDer = base64_decode(
            'MIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQDPTTqEodeG7H08'
            . 'VdUER/LoP6wMs+ResTtoy0bYCrg02F2xjGt0s8aEYXwCnfQQjod7SsdwvgHDkYaw'
            . 'tlqzyfLVaazFnxxTEnOjlZK9C8CiKIiETVBxpzBh1Vw1ozxshteGYbG8Os4I1Cj3'
            . 'lgH0a9Tpje63EMJ94jETBtJHSRoXmZSh5CPhpxC95GlQmnHrT53nWlXYQBY1teKI'
            . 'B3h9/yDilkG3hMThKrwC0QhhL0n4iVUb6RIYDLqT3GlOW4xmRIh6y8yGZ7c2E1PZ'
            . 'VxURWtwe18nSFktwEsHsOair6iZw/iRSPRiEfjm6A+GMz3nVcE821I5Bl4t0oVx5'
            . 'OXThVphLAgMBAAECggEAJnhMfcSS/KTycLn0+ABqIZN/WDQiEziMr9vZX8pNePEWIhbO8i9SjcqReuLZIiFxHv43mMKKDUL6XdzZZDf76oLb3yix1vC7qQXe31pI+07OVs8KOK0wG1e+7u1GD1XOtU937lhzV8wXdirOXg+MyXLfc/WWQkoxlThU2YnFX894zgmBLj6C9VtrD7563cxI8QQKBVpeu2BKQ8m/oQFYCVCJ3ix7kNukfhkCXgf0B62kBeC7LtHlbD2JMSkkj5KgKFTMaljlkeFxG07rWxD01wJy67GtfS3r1Q6gQ3W/JHyP/XvDbabvWlTLhoWq1L5WpXUN6AjUGkNFxykGvWgTkQKBgQD078s+Y5np3t3aBDWiKOSX+YSSCwL7AXL/dARQncWkVYDvb8fGNE3ZBs/pWKIXh2TMleU+wZUsbpGokl4s4kk6zUhNMxUgWY4VsULPKNCSpiu0I1WxfuxugPpT3KHEyBnfmZOeQvIkpTMpq/a5bjHxv/WVYEZ9HenZwVZUU610rQKBgQDYqkKsqjgRG+8aKAfNsNa8vBijJW0uR7BvOM5VqbqUiIZJ2HcIafFxvs3LJtBCJwOz7+BbPZs1GtTY7RyIIBlzlpeQA6/hhwogYJMZ9T9P1DV+wc9v6OktgoSjsz66cLJUbE0Cqr5I1erSf7NhwfDf+Ta38QvCXtAYXmlL8phn1wKBgHhMfgo6aRHQgC3f+2eVphBuYIpKFkCpyY1lsejWVIgN5rGyuO/EKKf7DIqTGalsujkxNdLIyTd1ZtzgZpis20KiKGyiNjIZSgulcCbG6Qndy4FCCYiPyhfMCSa/KkS38t07VKFaSAtvh91jtF4GnUka+sdO7c/trTliF8B7CKpRAoGAO/ldPWhc3reJxwa/qjtCJbo3Y6mvgDkN6KujyeiSohzsdzJ5OJYC5IZ5druGuFkOWFeVFgyGkvubYXS5CiFAilNsHsw2ekokDnRNI8lUPieyqyTA4+xn51YSmzG5smgRpPbZllxnEchNGPmKUQwbPhRBBkeuBp6yIZy4rvI3J78CgYEAkkPQX3JECu9I5+x70pLq4TKqAbVXCUl2zSE0L/bh/hKdSdDi3MzAwu+bHkWHD+AXcoxXNMUdazlnI6ZPVM6wbzw3qqhUblktTyAjIF1OlW2+OCBgD9uo8QWf3gbGyNwCFKS78FbTr+9poISwa/RaCQJOsAz/Z/NAyeMCMQx7AY8=',
            true
        );
        $privateKey = "-----BEGIN PRIVATE KEY-----\n" . chunk_split(base64_encode((string) $privateKeyDer), 64, "\n") . "-----END PRIVATE KEY-----\n";
        file_put_contents($directory . '/account.pem', $privateKey);
        try {
            $order = (new LetsEncryptAcmeClient($directory))->createOrder('203.0.113.10');
        } finally {
            foreach (glob($directory . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($directory);
        }

        $this->assertSame('https://acme.test/order/1', $order['order_url']);
        $this->assertSame('shortlived', $orderPayload['profile']);
        $this->assertSame([
            ['type' => 'ip', 'value' => '203.0.113.10'],
        ], $orderPayload['identifiers']);
    }

    private function decodeBase64URL(string $value): string
    {
        $padding = (4 - strlen($value) % 4) % 4;
        return (string) base64_decode(strtr($value, '-_', '+/') . str_repeat('=', $padding), true);
    }
}
