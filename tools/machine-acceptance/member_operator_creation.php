<?php
declare(strict_types=1);

namespace Keli\Acceptance;

use App\Models\MachineCloudAccount;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;

require_once __DIR__ . '/member_operator_store.php';

/** Operator-side control creation before the controller application exists. */
final class MemberOperatorCreation
{
    private MemberOperatorStore $store;
    private MemberControlCreation $control;
    private array $scope;
    private array $binding;
    private ?array $previousConfig = null;
    private mixed $previousEncrypter = null;
    private bool $configured = false;

    private static function need(bool $value, string $code): void
    {
        if (!$value) throw new \RuntimeException('member_operator_creation_' . $code);
    }

    public function __construct(string $directory, string $scopeHash)
    {
        self::need(preg_match('/^[a-f0-9]{64}$/D', $scopeHash) === 1, 'scope_invalid');
        $this->store = new MemberOperatorStore($directory, 'creation');
        try {
            $raw = [];
            foreach (['creation-scope', 'creation-permit', 'creation-bootstrap', 'creation-key', 'provider-credentials'] as $name)
                $raw[$name . '.json'] = $this->store->read($name . '.json');
            $this->scope = json_decode($raw['creation-scope.json'], true, 32, JSON_THROW_ON_ERROR);
            $keys = ['run_id', 'task_id', 'account_id', 'bundle_sha256', 'bootstrap_sha256', 'started_at', 'deadline_at', 'baseline_ids', 'plan'];
            self::need(count($this->scope) === count($keys) && !array_diff(array_keys($this->scope), $keys)
                && $this->scope['run_id'] === MemberProviderBindings::RUN
                && is_int($this->scope['account_id']) && $this->scope['account_id'] > 0
                && MemberProviderBindings::digest($this->scope) === $scopeHash, 'scope_invalid');
            $credentials = MemberPrerequisiteImport::material(json_decode($raw['provider-credentials.json'], true, 16, JSON_THROW_ON_ERROR));
            self::need($credentials['baseline_ids'] === $this->scope['baseline_ids'], 'baseline_changed');
            $bootstrap = json_decode($raw['creation-bootstrap.json'], true, 8, JSON_THROW_ON_ERROR);
            self::need(is_array($bootstrap) && array_keys($bootstrap) === ['content'] && is_string($bootstrap['content'])
                && str_starts_with($bootstrap['content'], "#cloud-config\n") && strlen($bootstrap['content']) <= 16384
                && hash('sha256', $bootstrap['content']) === $this->scope['bootstrap_sha256'], 'bootstrap_invalid');
            $key = json_decode($raw['creation-key.json'], true, 8, JSON_THROW_ON_ERROR);
            self::need(is_array($key) && array_keys($key) === ['key'] && is_string($key['key']), 'key_invalid');
            $decoded = base64_decode($key['key'], true);
            self::need(is_string($decoded) && strlen($decoded) === 32 && base64_encode($decoded) === $key['key'], 'key_invalid');
            $machine = file_get_contents('/etc/machine-id');
            self::need(is_string($machine) && $machine !== '', 'machine_identity');
            $expected = ['schema' => 1, 'run_id' => $this->scope['run_id'], 'operation' => 'control-create', 'authorized' => true,
                'scope_sha256' => $scopeHash, 'credentials_sha256' => hash('sha256', $raw['provider-credentials.json']),
                'journal_key_sha256' => hash('sha256', $raw['creation-key.json']), 'operator_machine_sha256' => hash('sha256', $machine)];
            $permit = json_decode($raw['creation-permit.json'], true, 16, JSON_THROW_ON_ERROR);
            self::need(is_array($permit) && count($permit) === count($expected) && !array_diff(array_keys($permit), array_keys($expected)), 'permit_invalid');
            foreach ($expected as $name => $value) self::need($permit[$name] === $value, 'permit_invalid');
            $this->binding = ['scope_sha256' => $scopeHash, 'operator_machine_sha256' => $expected['operator_machine_sha256'],
                'files' => array_map(static fn ($bytes) => hash('sha256', $bytes), $raw)];
            $this->store->save('binding', $this->binding);
            $this->previousEncrypter = app()->bound('encrypter') ? app('encrypter') : null;
            $this->previousConfig = array_combine(['machine_provision.allow_live', 'machine_provision.supported_sizes', 'machine_provision.supported_images'],
                [config('machine_provision.allow_live'), config('machine_provision.supported_sizes'), config('machine_provision.supported_images')]);
            $this->configured = true;
            app()->instance('encrypter', new Encrypter($decoded, 'AES-256-CBC'));
            Crypt::clearResolvedInstance('encrypter');
            config(['machine_provision.allow_live' => false, 'machine_provision.supported_sizes' => ['g6-standard-2'],
                'machine_provision.supported_images' => ['linode/debian12']]);
            $account = new MachineCloudAccount();
            $account->forceFill(['id' => $this->scope['account_id'], 'provider' => 'linode', 'credentials' => $credentials['linode']]);
            $this->control = new MemberControlCreation($this->scope, $account, $bootstrap['content'],
                fn ($operation) => $this->guard($operation), $this->store->load(...), $this->store->save(...), time(...));
        } catch (\Throwable $error) { $this->close(); throw $error; }
    }

    private function guard(string $operation): bool
    {
        $this->store->assertOpen();
        foreach ($this->binding['files'] as $name => $hash)
            self::need(hash('sha256', $this->store->read($name)) === $hash, 'material_changed');
        self::need(hash_file('sha256', '/etc/machine-id') === $this->binding['operator_machine_sha256'], 'machine_changed');
        if ($operation === 'control-create') self::need(time() >= $this->scope['started_at']
            && time() < $this->scope['deadline_at'] - 1200, 'expired');
        return true;
    }

    public function dispatch(array $request): array
    {
        self::need(count($request) === 3 && !array_diff(array_keys($request), ['operation', 'scope_sha256', 'request'])
            && in_array($request['operation'], ['control-create', 'control-readback'], true)
            && $request['scope_sha256'] === $this->binding['scope_sha256'] && is_array($request['request']), 'request_invalid');
        $inner = $request['request'];
        $scope = array_intersect_key($this->scope, array_flip(['run_id', 'bundle_sha256', 'started_at', 'deadline_at']));
        $expected = ['run_id' => $scope['run_id'], 'bundle_sha256' => $scope['bundle_sha256'], 'stage' => 'control-create',
            'predecessors_sha256' => hash('sha256', '{}'), 'request_id' => substr(MemberProviderBindings::digest([$scope, 'control-create']), 0, 32)];
        self::need(count($inner) === count($expected) && !array_diff(array_keys($inner), array_keys($expected)), 'request_invalid');
        foreach ($expected as $name => $value) self::need($inner[$name] === $value, 'request_invalid');
        try {
            $this->guard($request['operation']);
            // A process-local switch exists only for this validated control request.
            config(['machine_provision.allow_live' => $request['operation'] === 'control-create']);
            return ['request_sha256' => MemberProviderBindings::digest($request),
                'result' => $this->control->dispatch($request['operation'], $inner)];
        } catch (\Throwable $error) {
            try { $this->store->save('first-error', ['operation' => $request['operation'],
                'error_class' => (new \ReflectionClass($error))->getShortName()]); } catch (\Throwable) { }
            throw $error;
        } finally { config(['machine_provision.allow_live' => false]); }
    }

    public function close(): void
    {
        if ($this->configured) {
            config($this->previousConfig);
            if ($this->previousEncrypter === null) app()->forgetInstance('encrypter');
            else app()->instance('encrypter', $this->previousEncrypter);
            Crypt::clearResolvedInstance('encrypter');
            $this->configured = false;
        }
        if (isset($this->store)) $this->store->close();
    }

    public function __destruct() { $this->close(); }
}
