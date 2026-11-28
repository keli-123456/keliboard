<?php

declare(strict_types=1);

namespace App\Services\ServerMachine\Cloud;

use App\Models\MachineFailoverHandoverGrant as Grant;
use App\Models\ServerMachineRelease as Release;

/**
 * Maintenance-only control plane. Configuration and independent public keys are
 * supplied by the local CLI, never by Web or a queue message.
 */
final class MachineFailoverOperatorControl
{
    private const IDS = ['service_id', 'service_revision', 'member_id', 'machine_id', 'release_id', 'admin_id'];
    private const KEYS = ['stop_issuer', 'stop_public_key', 'closure_issuer', 'closure_public_key'];
    private const WINDOW = 1800;

    public function __construct(private MachineFailoverServiceRegistry $registry, private MachineFailoverHandover $handover,
        private MachineFailoverRunner $runner, private MachineFailoverHandoverRecovery $recovery) {}

    public function execute(array $policy, string $action, array $input): array
    {
        if (PHP_SAPI !== 'cli') throw new CloudException('operator_control_unavailable');
        $this->policy($policy);
        $admin = $policy['admin_id'];
        $this->registry->actor($admin);

        if ($action === 'scope' || $action === 'issue') {
            $this->keys($input, $action === 'scope' ? ['expires_at', 'evidence_sha256'] : ['expires_at', 'evidence_sha256', 'proof']);
            $scope = $this->scope($policy, $input);
            [$evidence, $authority] = $this->authority($policy);
            $mode = MachineFailoverOperatorPermit::MODE;
            if ($action === 'scope') return ['scope' => $scope + ['launch_mode' => $mode, 'authority_sha256' => $authority, 'operator_id' => $admin]];
            $proof = $this->proof($input);
            return $this->using($evidence, fn () => ['grant_id' => $this->handover->issue($scope, $admin, $proof, $mode, $authority)]);
        }
        if ($action === 'status') {
            $this->keys($input, []);
            $id = Grant::query()->useWritePdo()->where('service_id', $policy['service_id'])
                ->where('service_revision', $policy['service_revision'])->where('admin_id', $admin)
                ->whereNotNull('writer_slot')->value('id');
            if ($id === null) return ['grant_id' => null];
            $grant = $this->grant($policy, $id);
            return ['grant_id' => $grant->id] + $grant->only(['execution_id', 'expires_at', 'revoked_at']);
        }

        $fields = match ($action) {
            'revoke', 'release-unused', 'closure-scope' => ['grant_id'],
            'close' => ['grant_id', 'proof'],
            'read-cloud', 'read-dns' => ['grant_id', 'attempt_id'],
            'read-dns-set' => ['grant_id', 'attempt_id', 'dns_run_id'],
            default => throw new CloudException('invalid_operator_control_request'),
        };
        $this->keys($input, $fields);
        $grant = $this->grant($policy, $this->uuid($input['grant_id']));
        return match ($action) {
            'revoke' => $this->revoke($grant, $admin),
            'release-unused' => $this->releaseUnused($grant, $admin),
            'closure-scope' => ['scope' => $this->handover->closureScope($grant->id, $admin)],
            'close' => $this->close($policy, $grant, $admin, $this->proof($input)),
            default => MachineFailoverOperatorPermit::readback($grant, $admin, fn () => match ($action) {
                'read-cloud' => $this->recovery->cloud($grant->execution_id, $this->uuid($input['attempt_id']), $admin),
                'read-dns' => $this->recovery->dns($grant->execution_id, $this->uuid($input['attempt_id']), $admin),
                'read-dns-set' => $this->recovery->dnsSet($grant->execution_id, $this->uuid($input['attempt_id']), $this->uuid($input['dns_run_id']), $admin),
            }),
        };
    }

    private function policy(array $policy): void
    {
        $this->keys($policy, [...self::IDS, ...self::KEYS, 'old_writer']);
        foreach (self::IDS as $key) {
            if (!is_int($policy[$key]) || $policy[$key] < 1) throw new CloudException('operator_scope_changed');
        }
        if ($policy['machine_id'] === 17 || !in_array($policy['old_writer'], ['tanzhen', 'manual'], true))
            throw new CloudException('operator_scope_changed');
    }

    private function authority(array $policy): array
    {
        $open = [];
        foreach ([MachineFailoverHandoverEvidence::STOP => 'stop', MachineFailoverHandoverEvidence::CLOSE => 'closure'] as $purpose => $name) {
            $issuer = $policy[$name.'_issuer']; $pem = $policy[$name.'_public_key'];
            if (!is_string($issuer) || !preg_match('/^[a-zA-Z0-9_.-]{3,80}$/D', $issuer) || !is_string($pem)
                || strlen($pem) > 4096 || !str_starts_with($pem, '-----BEGIN PUBLIC KEY-----')
                || !($key = @openssl_pkey_get_public($pem))) throw new CloudException('handover_authority_unavailable');
            $details = openssl_pkey_get_details($key);
            if (!$details || !(($details['type'] === OPENSSL_KEYTYPE_RSA && $details['bits'] >= 2048)
                || ($details['type'] === OPENSSL_KEYTYPE_EC && $details['bits'] >= 256)))
                throw new CloudException('handover_authority_unavailable');
            $open[$purpose] = [$issuer => $details['key']];
        }
        if ($policy['stop_issuer'] === $policy['closure_issuer']
            || array_values($open[MachineFailoverHandoverEvidence::STOP]) === array_values($open[MachineFailoverHandoverEvidence::CLOSE]))
            throw new CloudException('handover_authorities_not_independent');
        return [new MachineFailoverHandoverEvidence($open, $policy['old_writer']),
            MachineFailoverHandoverEvidence::hash(['keys' => $open, 'old_writer' => $policy['old_writer']])];
    }

    private function scope(array $policy, array $input): array
    {
        $now = now()->getTimestamp();
        if (!is_int($input['expires_at']) || $input['expires_at'] <= $now || $input['expires_at'] > $now + self::WINDOW
            || !is_string($input['evidence_sha256']) || !preg_match('/^[a-f0-9]{64}$/D', $input['evidence_sha256']))
            throw new CloudException('handover_window_invalid');
        $snapshot = $this->registry->snapshot($policy['service_id'], $policy['service_revision']);
        $member = $snapshot['members'][0] ?? [];
        if (count($snapshot['members']) !== 1 || ($member['machine_id'] ?? null) !== $policy['machine_id']
            || ($member['id'] ?? null) !== $policy['member_id'] || MachineFailoverExistingSource::isDraftSlot($member['policy']['slot'] ?? '')
            || $snapshot['service']['max_parallel'] !== 1 || $snapshot['service']['attempt_limit'] !== 1)
            throw new CloudException('operator_scope_changed');
        $release = Release::query()->useWritePdo()->find($policy['release_id']);
        if (!$release || $release->status !== Release::STATUS_ACTIVE) throw new CloudException('release_unavailable');
        return ['service_id' => $policy['service_id'], 'service_revision' => $policy['service_revision'],
            'context_hash' => $this->registry->fingerprint($snapshot),
            'release' => $release->only(['id', 'component', 'platform', 'version', 'sha256', 'binary_sha256']),
            'evidence_sha256' => $input['evidence_sha256'], 'expires_at' => $input['expires_at']];
    }

    private function grant(array $policy, string $id): Grant
    {
        $grant = Grant::query()->useWritePdo()->find($id);
        if (!MachineFailoverOperatorPermit::issuedForOperator($grant) || $grant->service_id !== $policy['service_id']
            || $grant->service_revision !== $policy['service_revision'] || $grant->admin_id !== $policy['admin_id']
            || ($grant->release['id'] ?? null) !== $policy['release_id'])
            throw new CloudException('handover_not_authorized');
        return $grant;
    }

    private function revoke(Grant $grant, int $admin): array
    {
        // Revoke first; a failed stop check must not undo the denial of new work.
        $this->handover->revoke($grant->id, $admin);
        return ['revoked' => true, 'settlement' => $this->runner->advanceOperator(true, $grant->id)];
    }

    private function releaseUnused(Grant $grant, int $admin): array
    {
        $this->handover->releaseUnused($grant->id, $admin);
        return ['released' => true];
    }

    private function close(array $policy, Grant $grant, int $admin, array $proof): array
    {
        [$evidence, $authority] = $this->authority($policy);
        if (!hash_equals((string) ($grant->stop_receipt['authority_sha256'] ?? ''), $authority))
            throw new CloudException('handover_authority_changed');
        return $this->using($evidence, function () use ($grant, $admin, $proof) {
            $this->handover->releaseStopped($grant->id, $admin, $proof);
            return ['released' => true];
        });
    }

    private function using(MachineFailoverHandoverEvidence $evidence, callable $work): array
    {
        // Do not replace a global authority or expose these keys to worker processes.
        $app = app();
        if ($app->bound(MachineFailoverHandoverEvidence::class)) throw new CloudException('handover_authority_already_bound');
        $app->instance(MachineFailoverHandoverEvidence::class, $evidence);
        try { return $work(); }
        finally { $app->forgetInstance(MachineFailoverHandoverEvidence::class); }
    }

    private function proof(array $input): array
    {
        if (!is_array($input['proof'])) throw new CloudException('handover_evidence_required');
        return $input['proof'];
    }

    private function keys(array $value, array $fields): void
    {
        if (count($value) !== count($fields) || array_diff(array_keys($value), $fields))
            throw new CloudException('invalid_operator_control_request');
    }

    private function uuid(mixed $id): string
    {
        if (!is_string($id) || !preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D', $id))
            throw new CloudException('invalid_operator_control_request');
        return $id;
    }
}
