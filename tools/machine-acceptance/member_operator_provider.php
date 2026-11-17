<?php
declare(strict_types=1);

namespace Keli\Acceptance;

require_once __DIR__ . '/member_operator_store.php';

/** Operator-local cleanup only. It never boots the controller application or a database. */
final class MemberOperatorProvider
{
    private MemberOperatorStore $store;
    private array $scope;
    private array $binding;
    private array $receipts = [];
    private MemberProviderBindings $provider;

    private static function need(bool $value, string $code): void
    {
        if (!$value) throw new \RuntimeException('member_operator_' . $code);
    }

    public static function validateScope(array $scope): void
    {
        self::need(count($scope) === 5 && !array_diff(array_keys($scope),
            ['run_id', 'bundle_sha256', 'baseline_ids', 'pins', 'dns_receipts'])
            && $scope['run_id'] === MemberProviderBindings::RUN
            && is_string($scope['bundle_sha256']) && preg_match('/^[a-f0-9]{64}$/D', $scope['bundle_sha256'])
            && is_array($scope['baseline_ids']) && array_is_list($scope['baseline_ids']) && count($scope['baseline_ids']) <= 996
            && count(array_unique($scope['baseline_ids'], SORT_REGULAR)) === count($scope['baseline_ids'])
            && is_array($scope['pins']) && array_is_list($scope['pins']) && count($scope['pins']) <= 5
            && is_array($scope['dns_receipts']) && array_is_list($scope['dns_receipts']) && count($scope['dns_receipts']) <= 4,
            'scope_invalid');
        foreach ($scope['baseline_ids'] as $id)
            self::need(is_string($id) && preg_match('/^[1-9][0-9]{0,15}$/D', $id), 'scope_invalid');
        $controls = 0; $ids = []; $names = [];
        foreach ($scope['pins'] as $pin) {
            self::need(is_array($pin) && ($pin['run_id'] ?? null) === $scope['run_id'], 'scope_invalid');
            $control = array_key_exists('external_id', $pin);
            $keys = $control ? ['run_id', 'external_id', 'task_id', 'label', 'marker', 'region', 'size', 'image', 'provider_created_at']
                : ['run_id', 'record_id', 'name', 'type', 'line', 'value', 'marker'];
            self::need(count($pin) === count($keys) && !array_diff(array_keys($pin), $keys), 'scope_invalid');
            foreach ($pin as $value) self::need(is_string($value) && strlen($value) <= 1000, 'scope_invalid');
            $id = ($control ? 'control:' : 'dns:') . ($control ? $pin['external_id'] : $pin['record_id']);
            self::need(!isset($ids[$id]), 'scope_invalid'); $ids[$id] = true;
            if ($control) self::need(++$controls <= 1, 'scope_invalid');
            else { self::need(!isset($names[$pin['name']]), 'scope_invalid'); $names[$pin['name']] = true; }
        }
        $seen = [];
        foreach ($scope['dns_receipts'] as $receipt) {
            $pin = $receipt['pin'] ?? null; $state = $receipt['state'] ?? null;
            self::need(is_array($receipt) && array_keys($receipt) === ['pin', 'state'] && is_array($pin) && is_array($state)
                && in_array($pin, $scope['pins'], true) && isset($pin['record_id'])
                && count($state) === 5 && !array_diff(array_keys($state), ['create_sent', 'rejected', 'completed', 'created_id', 'request'])
                && is_array($state['request']) && count($state['request']) === 7
                && !array_diff(array_keys($state['request']), ['run_id', 'name', 'type', 'line', 'value', 'marker', 'ttl']),
                'dns_receipt_invalid');
            $key = MemberDnsInitialization::key($pin['name']);
            self::need(!isset($seen[$key]) && MemberDnsInitialization::hasCreateReceipt($pin,
                static fn ($requested) => $requested === $key ? $state : null), 'dns_receipt_invalid');
            $seen[$key] = true;
        }
    }

    public function __construct(private string $directory, string $expectedScope)
    {
        self::need(PHP_SAPI === 'cli' && PHP_OS_FAMILY === 'Linux' && function_exists('posix_geteuid'), 'linux_cli_required');
        self::need(preg_match('/^[a-f0-9]{64}$/D', $expectedScope) === 1, 'scope_invalid');
        $this->store = new MemberOperatorStore($directory, 'cleanup');
        try {
            $scopeRaw = $this->read('provider-scope.json');
            $this->scope = json_decode($scopeRaw, true, 32, JSON_THROW_ON_ERROR);
            self::validateScope($this->scope);
            self::need(MemberProviderBindings::digest($this->scope) === $expectedScope, 'scope_changed');
            $rawCredentials = $this->read('provider-credentials.json');
            $credentials = MemberPrerequisiteImport::material(json_decode($rawCredentials, true, 16, JSON_THROW_ON_ERROR));
            self::need($credentials['baseline_ids'] === $this->scope['baseline_ids'], 'baseline_changed');
            $rawPermit = $this->read('provider-permit.json');
            $permit = json_decode($rawPermit, true, 16, JSON_THROW_ON_ERROR);
            $machine = file_get_contents('/etc/machine-id');
            self::need(is_string($machine) && $machine !== '', 'machine_identity');
            $expected = ['schema' => 1, 'run_id' => $this->scope['run_id'], 'operation' => 'provider-cleanup',
                'authorized' => true, 'scope_sha256' => $expectedScope,
                'credentials_sha256' => hash('sha256', $rawCredentials), 'operator_machine_sha256' => hash('sha256', $machine)];
            self::need(is_array($permit) && count($permit) === count($expected) && !array_diff(array_keys($permit), array_keys($expected)),
                'permit_invalid');
            foreach ($expected as $key => $value) self::need($permit[$key] === $value, 'permit_invalid');
            $this->binding = ['scope_sha256' => $expectedScope, 'files' => [
                'provider-scope.json' => hash('sha256', $scopeRaw), 'provider-credentials.json' => hash('sha256', $rawCredentials),
                'provider-permit.json' => hash('sha256', $rawPermit)]];
            $prior = $this->load('binding');
            self::need($prior === null || $prior === $this->binding, 'binding_changed');
            if ($prior === null) $this->save('binding', $this->binding);
            foreach ($this->scope['dns_receipts'] as $receipt)
                $this->receipts[MemberDnsInitialization::key($receipt['pin']['name'])] = $receipt['state'];
            $this->provider = new MemberProviderBindings(['run_id' => $this->scope['run_id'],
                'baseline_ids' => $this->scope['baseline_ids'], 'pins' => array_map(MemberProviderBindings::digest(...), $this->scope['pins'])],
                $credentials['linode'], $credentials['aliyun'], function (): void { $this->guard(); },
                fn ($key) => $this->receipts[$key] ?? $this->load($key), $this->save(...));
        } catch (\Throwable $error) {
            $this->store->close();
            throw $error;
        }
    }

    private function read(string $name): string { return $this->store->read($name); }
    private function load(string $key): ?array { return $this->store->load($key); }
    private function save(string $key, array $value): void { $this->store->save($key, $value); }

    private function guard(): void
    {
        $this->store->assertOpen();
        foreach ($this->binding['files'] as $file => $expected)
            self::need(hash('sha256', $this->read($file)) === $expected, 'material_changed');
    }

    public function dispatch(array $request): array
    {
        self::need(count($request) === 3 && !array_diff(array_keys($request), ['operation', 'pin', 'scope_sha256'])
            && $request['scope_sha256'] === $this->binding['scope_sha256']
            && in_array($request['operation'], ['control-inspect', 'control-delete', 'dns-inspect', 'dns-delete'], true)
            && is_array($request['pin']), 'request_invalid');
        try {
            $this->guard();
            $reply = $this->provider->dispatch($request['operation'], $request['pin']);
            return ['request_sha256' => MemberProviderBindings::digest($request),
                'result' => array_intersect_key($reply, array_flip(['pin_sha256', 'status', 'dispatched', 'reconciliation_required']))];
        } catch (\Throwable $error) {
            // No exception message, credentials, URLs or provider response is retained.
            $class = (new \ReflectionClass($error))->getShortName();
            try {
                $this->save('first-error', ['operation' => $request['operation'],
                    'error_class' => preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/D', $class) ? $class : 'Exception']);
            } catch (\Throwable) {
                // Keep the original failure; a journal failure never confirms deletion.
            }
            throw $error;
        }
    }

    public function close(): void
    {
        if (isset($this->store)) $this->store->close();
    }

    public function __destruct() { $this->close(); }
}
