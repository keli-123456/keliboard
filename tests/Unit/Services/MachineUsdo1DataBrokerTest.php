<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\ServerMachine\Cloud\CloudException;
use App\Services\ServerMachine\Cloud\MachineUsdo1DataBroker as Broker;
use App\Services\ServerMachine\Cloud\MachineUsdo1DataClient as Client;
use App\Services\ServerMachine\Cloud\MachineUsdo1LaunchScope as Scope;
use App\Services\ServerMachine\Cloud\MachineUsdo1PrivateCallback as PrivateCallback;
use PHPUnit\Framework\TestCase;

final class MachineUsdo1DataBrokerTest extends TestCase
{
    private const GRANT = '00000000-0000-4000-8000-000000000001';

    public function test_two_socket_roles_accept_only_their_operation_and_never_accept_caller_scope(): void
    {
        $scope = new Scope(self::GRANT, 1, 'https://receipt.example.test');
        $receiptKey = str_repeat('1', 64); $workerKey = str_repeat('2', 64);
        $calls = [];
        $broker = new Broker($scope, $receiptKey, $workerKey,
            static function (Scope $s) use (&$calls, $scope): bool {
                self::assertSame($scope, $s);
                $calls[] = 'receipt';
                return true;
            },
            static function (Scope $s) use (&$calls, $scope): array {
                self::assertSame($scope, $s);
                $calls[] = 'worker';
                return ['status' => 'running', 'runtime' => 'idle', 'revoked' => false, 'token' => 'must-not-escape'];
            });
        $receipt = ['task_id' => '00000000-0000-4000-8000-000000000002',
            'installation_id' => str_repeat('a', 32), 'receipt_key' => str_repeat('b', 64), 'state' => 'succeeded'];
        $body = json_encode($receipt, JSON_THROW_ON_ERROR);
        foreach ([
            [404, 'receipt', 'POST', Broker::WORKER, $receiptKey, '{}'],
            [404, 'worker', 'POST', Broker::RECEIPT, $workerKey, $body],
            [404, 'worker', 'POST', Broker::WORKER . '?grant=1', $workerKey, '{}'],
            [404, 'arbitrary', 'POST', Broker::WORKER, $workerKey, '{}'],
            [405, 'worker', 'GET', Broker::WORKER, $workerKey, '{}'],
            [401, 'worker', 'POST', Broker::WORKER, $receiptKey, '{}'],
            [401, 'receipt', 'POST', Broker::RECEIPT, $workerKey, $body],
            [400, 'worker', 'POST', Broker::WORKER, $workerKey, '{"grant_id":"other"}'],
            [400, 'worker', 'POST', Broker::WORKER, $workerKey, '[]'],
            [413, 'receipt', 'POST', Broker::RECEIPT, $receiptKey, str_repeat('x', 2049)],
            [400, 'receipt', 'POST', Broker::RECEIPT, $receiptKey, json_encode($receipt + ['service_id' => 2])],
        ] as [$status, $lane, $method, $path, $key, $request]) {
            self::assertSame([$status, ['ok' => false]], $broker->respond($lane, $method, $path, 'Bearer ' . $key, $request));
        }
        self::assertSame([], $calls);
        $transport = static function ($socket, $path, $auth, $body) use ($broker) {
            $lane = match ($socket) {
                '/run/keli-usdo1/data-receipt.sock' => 'receipt',
                '/run/keli-usdo1/data-worker.sock' => 'worker',
                default => self::fail('unexpected socket'),
            };
            [$status, $result] = $broker->respond($lane, 'POST', $path, $auth, $body);
            return [$status, json_encode($result, JSON_THROW_ON_ERROR)];
        };
        self::assertTrue((new Client('receipt', $receiptKey, $transport))->receipt(...array_values($receipt)));
        self::assertSame(['status' => 'running', 'runtime' => 'idle', 'revoked' => false], (new Client('worker', $workerKey, $transport))->tick());
        self::assertSame(['receipt', 'worker'], $calls);
    }

    public function test_client_cannot_call_the_other_operation_or_retry_on_unknown_response(): void
    {
        $client = new Client('receipt', str_repeat('1', 64), static fn () => self::fail('wrong lane reached transport'));
        try { $client->tick(); self::fail('receipt identity was allowed to tick'); }
        catch (CloudException $e) { self::assertSame('usdo1_data_operation_denied', $e->getMessage()); }
        $sent = 0;
        $broken = new Client('worker', str_repeat('2', 64), static function () use (&$sent) {
            ++$sent;
            return [503, '{"ok":false}'];
        });
        try { $broken->tick(); self::fail('failed transport was accepted'); }
        catch (\RuntimeException $e) { self::assertSame('usdo1_data_unavailable', $e->getMessage()); }
        self::assertSame(1, $sent);
    }

    public function test_pending_private_config_and_public_or_untrusted_candidates_fail_closed(): void
    {
        foreach ([
            ['8.8.4.4', 'wg-keli123', null],
            ['127.0.0.1', 'wg-keli123', null],
            ['169.254.169.254', 'wg-keli123', null],
            ['10.2.3.4', 'lo', null],
            ['10.2.3.4', "device\nproxy", null],
            ['10.2.3.4', 'wg-keli123', '-----BEGIN PRIVATE KEY-----'],
        ] as [$ip, $dev, $ca]) {
            try { new PrivateCallback($ip, $dev, $ca); self::fail('unsafe private callback accepted'); }
            catch (CloudException $e) { self::assertContains($e->getMessage(), ['usdo1_private_callback_invalid', 'usdo1_callback_ca_invalid']); }
        }
        $route = new PrivateCallback('10.2.3.4', 'wg-keli123');
        self::assertNotSame($route->fingerprint(), (new PrivateCallback('10.2.3.5', 'wg-keli123'))->fingerprint());
    }
}
