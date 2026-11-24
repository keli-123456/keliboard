<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\ServerMachine\Cloud\CloudException;
use App\Services\ServerMachine\Cloud\MachineUsdo1LaunchScope as Scope;
use App\Services\ServerMachine\Cloud\MachineUsdo1Runtime as Runtime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MachineUsdo1LaunchTest extends TestCase
{
    private const GRANT = '00000000-0000-4000-8000-000000000001';
    private const TASK = '00000000-0000-4000-8000-000000000002';

    public static function malformedOrigins(): array
    {
        return array_map(fn ($url) => [$url], [
            'http://receipt.example.test', 'https://receipt.example.test/extra', 'https://receipt.example.test:444',
            'https://operator@receipt.example.test', 'https://receipt.example.test?grant=1',
            'https://127.0.0.1', 'https://usdo1.dnscloudcloud.top',
        ]);
    }

    #[DataProvider('malformedOrigins')]
    public function test_invalid_or_moving_callback_origin_is_rejected(string $url): void
    {
        $this->expectException(CloudException::class);
        new Scope(self::GRANT, 1, $url);
    }

    public function test_same_origin_as_panel_is_rejected(): void
    {
        $scope = new Scope(self::GRANT, 1, 'https://receipt.example.test');
        $this->expectExceptionMessage('usdo1_callback_not_independent');
        $scope->assertIndependent('https://receipt.example.test');
    }

    public function test_only_exact_https_endpoint_reaches_receipt_handler(): void
    {
        $scope = new Scope(self::GRANT, 1, 'https://receipt.example.test');
        $secret = str_repeat('a', 64);
        $installation = str_repeat('1', 32);
        $server = ['HTTPS'=>'on','HTTP_HOST'=>'receipt.example.test','REQUEST_URI'=>Scope::CALLBACK_PATH,
            'REQUEST_METHOD'=>'POST','CONTENT_TYPE'=>'application/json','HTTP_AUTHORIZATION'=>'Bearer ' . $secret];
        $fields = ['task_id'=>self::TASK,'installation_id'=>$installation,'state'=>'succeeded'];
        $body = json_encode($fields, JSON_THROW_ON_ERROR);
        $called = 0;
        $handler = function (...$receipt) use ($scope, $secret, $installation, &$called): bool {
            ++$called;
            self::assertSame([$scope, self::TASK, $installation, $secret, 'succeeded'], $receipt);
            return true;
        };
        foreach ([
            [404, ['HTTPS'=>'off','HTTP_X_FORWARDED_PROTO'=>'https'], $body],
            [404, ['HTTP_HOST'=>'panel.example.test'], $body],
            [404, ['REQUEST_URI'=>Scope::CALLBACK_PATH . '?grant=other'], $body],
            [405, ['REQUEST_METHOD'=>'GET'], $body],
            [413, [], str_repeat('x', 2049)],
            [415, ['CONTENT_TYPE'=>'text/plain'], $body],
            [401, ['HTTP_AUTHORIZATION'=>'Bearer bad'], $body],
            [400, [], '{'],
            [400, [], json_encode($fields + ['grant_id'=>self::GRANT], JSON_THROW_ON_ERROR)],
            [400, [], json_encode(array_replace($fields,['state'=>'ok']), JSON_THROW_ON_ERROR)],
        ] as [$status, $override, $input]) {
            self::assertSame($status, Runtime::receive($scope, array_replace($server, $override), $input, $handler));
        }
        self::assertSame(0, $called);
        self::assertSame(200, Runtime::receive($scope, $server, $body, $handler));
        self::assertSame(1, $called);
        self::assertSame(503, Runtime::receive($scope, $server, $body, fn () => throw new \RuntimeException('not available')));
    }
}
