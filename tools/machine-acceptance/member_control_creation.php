<?php
declare(strict_types=1);

namespace Keli\Acceptance;

use App\Models\MachineCloudAccount;
use App\Services\ServerMachine\Cloud\MachineProvisionProviders;
use App\Services\ServerMachine\Cloud\CloudRequestFailure;
use Illuminate\Support\Facades\Crypt;

/** One control role, using the existing provider request and reconciliation rules. */
final class MemberControlCreation
{
    private array $state;
    private array $plan;

    public function __construct(private array $scope, private MachineCloudAccount $account,
        private string $bootstrap, private \Closure $guard, private \Closure $load,
        private \Closure $save, private \Closure $clock)
    {
        self::need(($scope['run_id'] ?? null) === MemberProviderBindings::RUN
            && ($scope['account_id'] ?? null) === $account->id && $account->provider === 'linode'
            && preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D', $scope['task_id'] ?? '') === 1
            && is_int($scope['started_at'] ?? null) && is_int($scope['deadline_at'] ?? null)
            && $scope['started_at'] > 0 && $scope['deadline_at'] - $scope['started_at'] > 1200
            && $scope['deadline_at'] - $scope['started_at'] <= 7200
            && ($scope['bootstrap_sha256'] ?? null) === hash('sha256', $bootstrap)
            && preg_match('/^[a-f0-9]{64}$/D', $scope['bundle_sha256'] ?? '') === 1
            && is_array($scope['baseline_ids'] ?? null) && array_is_list($scope['baseline_ids'])
            && count($scope['baseline_ids']) <= 996, 'scope_invalid');
        foreach ($scope['baseline_ids'] as $id)
            self::need(is_string($id) && preg_match('/^[1-9][0-9]{0,15}$/D', $id) === 1, 'scope_invalid');
        $this->plan = $scope['plan'] ?? [];
        self::need(($this->plan['region'] ?? null) === 'sg-sin-2'
            && ($this->plan['size'] ?? null) === 'g6-standard-2'
            && ($this->plan['image'] ?? null) === 'linode/debian12'
            && is_array($this->plan['authorized_keys'] ?? null) && count($this->plan['authorized_keys']) === 1
            && is_string($this->plan['authorized_keys'][0])
            && str_starts_with($this->plan['authorized_keys'][0], 'ssh-ed25519 '), 'plan_invalid');
        $binding = MemberProviderBindings::digest($scope);
        $this->state = ($load)('control-creation') ?? ['binding' => $binding,
            'dispatched_at' => null, 'request_ciphertext' => null, 'pin' => null,
            'first_error' => null, 'stopped' => false, 'last_at' => $scope['started_at']];
        self::need(($this->state['binding'] ?? null) === $binding, 'scope_changed');
    }

    private static function need(bool $ok, string $code): void
    {
        if (!$ok) throw new \RuntimeException('member_control_' . $code);
    }

    private function persist(): void
    {
        ($this->save)('control-creation', $this->state);
        self::need(($this->load)('control-creation') === $this->state, 'journal_unconfirmed');
    }

    private function enter(string $operation): int
    {
        self::need(($this->guard)($operation, $this->scope) === true, 'authority_required');
        // Calls share the host driver's exclusive journal lock. Refresh even
        // when an object was constructed before a previous serialized call.
        $saved = ($this->load)('control-creation');
        if ($saved !== null) {
            self::need(($saved['binding'] ?? null) === $this->state['binding'], 'scope_changed');
            $this->state = $saved;
        }
        $now = ($this->clock)();
        self::need(is_int($now) && $now >= $this->state['last_at'], 'clock_rollback');
        if ($operation === 'control-create')
            self::need(!$this->state['stopped'] && $now < $this->scope['deadline_at'] - 1200, 'stopped_or_expired');
        $this->state['last_at'] = $now;
        $this->persist();
        return $now;
    }

    public function advance(): array
    {
        $now = $this->enter('control-create');
        self::need(config('machine_provision.allow_live') === true, 'execution_disabled');
        $client = app(MachineProvisionProviders::class)->client($this->account);
        if ($this->state['dispatched_at'] === null) {
            $client->preflight($this->account, $this->plan);
            $marker = 'keli-create-' . str_replace('-', '', $this->scope['task_id']);
            $request = $client->buildRequest($this->plan, $this->scope['task_id'], $marker, $this->bootstrap);
            $now = $this->enter('control-create');
            // Freeze the original request before POST. Unknown responses never
            // mint another password, UUID, request, or second machine.
            $this->state['request_ciphertext'] = Crypt::encryptString(json_encode($request, JSON_THROW_ON_ERROR));
            $this->state['dispatched_at'] = $now;
            $this->persist();
            try {
                $row = $client->create($this->account, $request, $now);
                return array_replace($this->receipt($row, $request), ['ready' => false]);
            } catch (\Throwable $error) {
                $decision = $error instanceof CloudRequestFailure ? $error->decision : [];
                $rejected = ($decision['outcome'] ?? null) === 'rejected'
                    && in_array($decision['http_status'] ?? null, [400, 401, 402, 403, 404, 422, 429], true);
                if ($rejected) $this->state['rejection'] = array_intersect_key($decision,
                    array_flip(['kind', 'http_status', 'code']));
                $code = $rejected ? 'control_create_rejected' : 'control_create_unconfirmed';
                $this->state['first_error'] ??= $code;
                $this->state['stopped'] = true;
                $this->persist();
                throw new \RuntimeException('member_' . $code);
            }
        }
        return $this->readback();
    }

    public function readback(bool $stop = false): array
    {
        $this->enter('control-readback');
        if ($stop) { $this->state['stopped'] = true; $this->persist(); }
        if ($this->state['dispatched_at'] === null)
            return ['resolved' => true, 'dispatched' => false, 'pin' => null, 'ready' => false];
        if (isset($this->state['rejection']))
            return ['resolved' => true, 'dispatched' => true, 'pin' => null, 'ready' => false, 'creation_rejected' => true];
        $request = json_decode(Crypt::decryptString($this->state['request_ciphertext']), true, 512, JSON_THROW_ON_ERROR);
        $row = app(MachineProvisionProviders::class)->client($this->account)
            ->reconcile($this->account, $request, $this->state['dispatched_at']);
        // A list that does not yet show an uncertain POST is NOT proof of no creation.
        if ($row === null) return ['resolved' => false, 'dispatched' => true,
            'pin' => $this->state['pin'], 'ready' => false];
        return $this->receipt($row, $request);
    }

    private function receipt(array $row, array $request): array
    {
        $id = (string) ($row['external_id'] ?? '');
        self::need(preg_match('/^[1-9][0-9]{0,15}$/D', $id) === 1
            && !in_array($id, $this->scope['baseline_ids'], true), 'identity_changed');
        $pin = ['run_id' => $this->scope['run_id'], 'external_id' => $id, 'task_id' => $this->scope['task_id'],
            'label' => $request['label'], 'marker' => $request['tags'][0], 'region' => $this->plan['region'],
            'size' => $this->plan['size'], 'image' => $this->plan['image'],
            'provider_created_at' => $row['provider_created_at']];
        self::need($this->state['pin'] === null || $this->state['pin'] === $pin, 'identity_changed');
        $this->state['pin'] = $pin;
        $this->persist();
        return ['resolved' => true, 'dispatched' => true, 'pin' => $pin,
            'ready' => !$this->state['stopped'] && $row['status'] === 'running'
                && count(array_filter($row['public_ips'], static fn ($ip) =>
                    filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false)) > 0];
    }

    public function dispatch(string $operation, array $request): array
    {
        self::need(in_array($operation, ['control-create', 'control-readback'], true)
            && ($request['run_id'] ?? null) === $this->scope['run_id']
            && ($request['bundle_sha256'] ?? null) === $this->scope['bundle_sha256']
            && ($request['stage'] ?? null) === 'control-create'
            && preg_match('/^[a-f0-9]{32}$/D', $request['request_id'] ?? '') === 1
            && preg_match('/^[a-f0-9]{64}$/D', $request['predecessors_sha256'] ?? '') === 1, 'request_invalid');
        $prior = ($this->load)('control-bound-request');
        self::need($prior === null || $prior === $request, 'request_changed');
        ($this->save)('control-bound-request', $request);
        self::need(($this->load)('control-bound-request') === $request, 'journal_unconfirmed');
        $value = $operation === 'control-create' ? $this->advance() : $this->readback(true);
        return $value + ['request_sha256' => MemberProviderBindings::digest($request)];
    }
}
