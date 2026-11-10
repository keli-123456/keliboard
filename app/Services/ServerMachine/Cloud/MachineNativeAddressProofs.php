<?php
namespace App\Services\ServerMachine\Cloud;

use App\Models\MachineFailoverAttempt as Attempt;
use App\Models\MachineNativeAddressProof as Proof;
use App\Models\MachineOwnedInstance as Instance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MachineNativeAddressProofs
{
    public function __construct(private MachineForwardingVerifier $verifier, private MachineFailoverExecutionGate $gate) {}
    public function available(): bool { return Schema::hasTable('v2_machine_native_address_proof'); }
    public function ensure(Attempt $attempt, Instance $instance): array
    {
        $this->gate->assertAllowed('native_address_verify');
        if (!$this->available()) throw new CloudException('migration_required');
        $admission = app(MachineNativeDnsAdmission::class); $context = $admission->candidateContext($attempt, $instance, true);
        $hash = $this->hash($context); $configuration = $this->verifier->configuration($instance->policy_id); $links = [];
        foreach ($context['families'] as $family) {
            $row = Proof::query()->where('attempt_id', $attempt->id)->where('family', $family)->first();
            if (!$row) {
                $id = bin2hex(random_bytes(16)); $frames = [random_bytes(32), random_bytes(32), random_bytes(32)]; $now = time();
                $challenge = ['schema' => 2, 'challenge_id' => $id, 'issued_at' => $now, 'deadline_at' => $now + 45,
                    'profile' => $configuration['profile'], 'task_id' => $instance->task_id, 'installation_id' => $instance->installation_id,
                    'target_ip' => $context['addresses'][$family], 'address_family' => $family, 'attempt_id' => $attempt->id,
                    'native_context_sha256' => $hash, 'source_installation_id' => $context['source']['instance']['installation_id'],
                    'configuration_fingerprint' => $context['runtime']['configuration_fingerprint'], 'session' => $context['runtime']['session'],
                    'binary_sha256' => $instance->binary_sha256, 'nodes' => $context['runtime']['nodes'],
                    'echo_frames' => array_map('base64_encode', $frames), 'echo_sha256' => hash('sha256', implode('', $frames))];
                $row = Proof::query()->create(['id' => $id, 'attempt_id' => $attempt->id, 'instance_id' => $instance->id, 'family' => $family,
                    'ip' => $context['addresses'][$family], 'state' => 'issued', 'context_hash' => $hash,
                    'request_sha256' => hash('sha256', json_encode($challenge, JSON_THROW_ON_ERROR)), 'issued_at' => $now,
                    'deadline_at' => $challenge['deadline_at'], 'payload' => ['context' => $context, 'origin' => 'native_signed_worker_v2']]);
                try {
                    $receipt = $this->verifier->verify($challenge, $configuration);
                    if (!MachineIntentJson::same($admission->candidateContext($attempt->fresh(), $instance->fresh(), true), $context)
                        || $this->verifier->configuration($instance->policy_id)['fingerprint'] !== $configuration['fingerprint']) throw new CloudException('native_address_context_changed');
                    $until = min(time() + 120, $instance->verified_until, $context['deadline_at']);
                    if ($until <= time() || time() > $challenge['deadline_at']) throw new CloudException('native_address_expired');
                    DB::transaction(function () use ($row, $receipt, $context, $until, $attempt) {
                        app(MachineFailoverNativeBridge::class)->assertAttempt($attempt->fresh());
                        $current = Proof::query()->whereKey($row->id)->lockForUpdate()->firstOrFail();
                        if ($current->state !== 'issued') throw new CloudException('native_address_replay');
                        $current->fill(['state' => 'passed', 'valid_until' => $until, 'receipt_sha256' => $receipt['receipt_sha256'],
                            'payload' => ['context' => $context, 'receipt' => $receipt, 'origin' => 'native_signed_worker_v2']]);
                        $current->seal = $this->seal($current); $current->save();
                    });
                } catch (\Throwable $e) {
                    Proof::query()->whereKey($row->id)->where('state', 'issued')->update(['state' => 'failed',
                        'reason' => $e instanceof CloudException && preg_match('/^[a-z_]{1,60}$/D', $e->getMessage()) ? $e->getMessage() : 'forwarding_failed']);
                    throw $e;
                }
                $row->refresh();
            }
            $this->proof($row, $context, $attempt, $instance); $links[$family] = $row->id;
        }
        DB::transaction(function () use ($attempt, $links) {
            app(MachineFailoverNativeBridge::class)->assertAttempt($attempt->fresh());
            $row = Attempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail(); $p = $row->payload;
            if (isset($p['address_proofs']) && !MachineIntentJson::same($p['address_proofs'], $links)) throw new CloudException('native_address_replay');
            $p['address_proofs'] = $links; $row->payload = $p; $row->save();
        });
        return $links;
    }
    public function assertCurrent(Attempt $attempt, Instance $instance, bool $remote = false): array
    {
        $this->gate->assertAllowed('native_address_read');
        if (!$this->available()) throw new CloudException('migration_required');
        $context = app(MachineNativeDnsAdmission::class)->candidateContext($attempt, $instance, $remote); $links = $attempt->payload['address_proofs'] ?? [];
        if (!is_array($links) || array_keys($links) !== $context['families']) throw new CloudException('native_address_required');
        foreach ($links as $family => $id) {
            $row = is_string($id) ? Proof::query()->find($id) : null;
            if (!$row || $row->family !== $family) throw new CloudException('native_address_required');
            $this->proof($row, $context, $attempt, $instance);
        }
        return $context;
    }
    private function proof(Proof $row, array $context, Attempt $attempt, Instance $instance): void
    {
        $receipt = $row->payload['receipt'] ?? [];
        if ($row->state !== 'passed' || $row->attempt_id !== $attempt->id || $row->instance_id !== $instance->id
            || $row->valid_until <= time() || $row->issued_at > time() || $row->valid_until > $row->issued_at + 165
            || $row->ip !== ($context['addresses'][$row->family] ?? null) || !hash_equals($row->context_hash, $this->hash($context))
            || ($row->payload['origin'] ?? null) !== 'native_signed_worker_v2' || !MachineIntentJson::same($row->payload['context'], $context)
            || !is_string($row->seal) || !hash_equals($row->seal, $this->seal($row))
            || ($receipt['cleanup_complete'] ?? null) !== true || ($receipt['receipt_sha256'] ?? null) !== $row->receipt_sha256
            || ($receipt['native_context_sha256'] ?? null) !== $row->context_hash || ($receipt['address_family'] ?? null) !== $row->family
            || ($receipt['target_ip'] ?? null) !== $row->ip || ($receipt['task_id'] ?? null) !== $instance->task_id
            || ($receipt['installation_id'] ?? null) !== $instance->installation_id || ($receipt['attempt_id'] ?? null) !== $attempt->id
            || ($receipt['source_installation_id'] ?? null) !== $context['source']['instance']['installation_id']
            || ($receipt['session'] ?? null) !== $context['runtime']['session'] || ($receipt['binary_sha256'] ?? null) !== $instance->binary_sha256
            || ($receipt['configuration_fingerprint'] ?? null) !== $context['runtime']['configuration_fingerprint']
            || ($receipt['verifier_fingerprint'] ?? null) !== $context['verifier_fingerprint'])
            throw new CloudException('native_address_not_verified');
    }
    private function seal(Proof $row): string
    {
        $key = config('app.key'); if (!is_string($key) || strlen($key) < 32) throw new CloudException('native_address_authority_missing');
        return hash_hmac('sha256', 'native-address-v2:' . MachineIntentJson::encode([$row->id, $row->attempt_id, $row->instance_id,
            $row->family, $row->ip, $row->state, $row->issued_at, $row->deadline_at, $row->valid_until, $row->context_hash,
            $row->request_sha256, $row->receipt_sha256, $row->payload]), $key);
    }
    private function hash(array $v): string { return hash('sha256', MachineIntentJson::encode($v)); }
}
