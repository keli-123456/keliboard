<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\ServerMachine\Cloud\MachineFailoverHandoverEvidence as Evidence;

final class HandoverEvidenceFixture
{
    private const NAMES = [Evidence::STOP => 'fixture-old-writer', Evidence::CLOSE => 'fixture-reviewer',
        Evidence::MANUAL_STOP => 'fixture-manual-fence', Evidence::MANUAL_ROLLBACK => 'fixture-rollback-authority',
        Evidence::SOURCE_ADMISSION => 'fixture-source-reviewer'];
    private static array $keys = [];

    public static function trust(): void
    {
        app()->instance(Evidence::class, new Evidence(self::publicKeys()));
    }

    public static function publicKeys(): array
    {
        $public = [];
        foreach (self::NAMES as $purpose => $issuer) {
            $key = self::key($purpose);
            $public[$purpose][$issuer] = openssl_pkey_get_details($key)['key'];
        }
        return $public;
    }

    public static function stop(array $scope, int $admin, array $overrides = []): array
    {
        $now = now()->getTimestamp();
        return self::sign(Evidence::STOP, $overrides + ['scope_sha256' => Evidence::hash($scope + ['operator_id' => $admin]),
            'observed_at' => $now, 'valid_until' => $now + 60, 'writer' => 'tanzhen', 'fence_state' => 'fenced_until_handback',
            'fence_id' => 'fixture-fence-1', 'queued' => 0, 'in_flight' => 0, 'unresolved' => 0,
            'baseline_sha256' => hash('sha256', 'fixture-reviewed-router-baseline')]);
    }

    public static function close(array $scope, array $overrides = []): array
    {
        $now = now()->getTimestamp();
        return self::sign(Evidence::CLOSE, $overrides + ['scope_sha256' => Evidence::hash($scope), 'observed_at' => $now,
            'valid_until' => $now + 60, 'fence_state' => 'fenced_until_handback', 'in_flight' => 0,
            'outcomes' => array_map(fn ($op) => ['operation_id' => $op['operation_id'],
                'result' => $op['known_outcome'] ?? 'confirmed_applied', 'basis' => 'independent_exact_reconciliation',
                'evidence_sha256' => hash('sha256', 'fixture-review:' . $op['intent_sha256'])], $scope['operations'])]);
    }

    public static function manualStop(array $scope, int $admin, array $pin, array $overrides = []): array
    {
        $now = now()->getTimestamp();
        return self::sign(Evidence::MANUAL_STOP, $overrides + ['scope_sha256' => Evidence::hash($scope + ['operator_id' => $admin,
            'manual_scope_sha256' => Evidence::hash($pin)]), 'observed_at' => $now, 'valid_until' => $now + 60,
            'writer' => 'aliyun_console', 'fence_state' => 'fenced_until_handback', 'fence_id' => 'fixture-console-fence',
            'queued' => 0, 'in_flight' => 0, 'unresolved' => 0, 'baseline_sha256' => $pin['baseline_sha256']]);
    }

    public static function manualRollback(array $scope, array $overrides = []): array
    {
        $now = now()->getTimestamp();
        return self::sign(Evidence::MANUAL_ROLLBACK, $overrides + ['scope_sha256' => Evidence::hash($scope),
            'observed_at' => $now, 'valid_until' => $now + 60, 'decision' => 'restore_original_a']);
    }

    public static function sourceAdmission(array $scope): array
    {
        $now = now()->getTimestamp();
        return self::sign(Evidence::SOURCE_ADMISSION, ['scope_sha256' => Evidence::hash($scope), 'observed_at' => $now,
            'valid_until' => $now + 60, 'decision' => 'admit_existing_source']);
    }

    private static function sign(string $purpose, array $body): array
    {
        $issuer = self::NAMES[$purpose];
        if (!openssl_sign(Evidence::signingInput($purpose, $issuer, $body), $signature, self::key($purpose), OPENSSL_ALGO_SHA256))
            throw new \RuntimeException('Cannot sign synthetic handover evidence.');
        return ['issuer' => $issuer, 'body' => $body, 'signature' => base64_encode($signature)];
    }

    private static function key(string $purpose): \OpenSSLAsymmetricKey
    {
        if (!isset(self::$keys[$purpose])) {
            $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
            if (!$key) throw new \RuntimeException('Cannot create an ephemeral fixture key.');
            self::$keys[$purpose] = $key;
        }
        return self::$keys[$purpose];
    }
}
