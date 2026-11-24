<?php

declare(strict_types=1);

namespace App\Services\ServerMachine\Cloud;

final class MachineFailoverHandoverEvidence
{
    public const STOP = 'old_writer_stop';
    public const MANUAL_STOP = 'manual_dns_writer_stop';
    public const CLOSE = 'execution_closure';
    private const MAX_AGE = 300;

    // Use independent, trusted public keys per purpose. No production authority is configured here.
    public function __construct(private array $trustedKeys = []) {}

    public function stopped(array $scope, int $admin, ?array $proof, int $now): array
    {
        return $this->stopReceipt(self::STOP, 'tanzhen', $scope + ['operator_id' => $admin], $proof, $now);
    }

    public function stoppedManual(array $scope, int $admin, array $pin, ?array $proof, int $now): array
    {
        MachineUsdo1HandoverScope::validate($pin);
        $receipt = $this->stopReceipt(self::MANUAL_STOP, 'aliyun_console', $scope + [
            'operator_id' => $admin, 'manual_scope_sha256' => self::hash($pin),
        ], $proof, $now);
        if (!hash_equals($pin['baseline_sha256'], $receipt['baseline_sha256'])) throw new CloudException('handover_stop_unconfirmed');
        return $receipt + ['manual_usdo1' => $pin];
    }

    private function stopReceipt(string $purpose, string $writer, array $scope, ?array $proof, int $now): array
    {
        $body = $this->body($proof, ['scope_sha256', 'observed_at', 'valid_until', 'writer', 'fence_state', 'fence_id',
            'queued', 'in_flight', 'unresolved', 'baseline_sha256']);
        $receipt = $this->verify($purpose, $proof, $body, $scope, $now);
        if ($body['writer'] !== $writer || $body['fence_state'] !== 'fenced_until_handback'
            || !is_string($body['fence_id']) || !preg_match('/^[A-Za-z0-9_-]{8,96}$/D', $body['fence_id'])
            || $body['queued'] !== 0 || $body['in_flight'] !== 0 || $body['unresolved'] !== 0
            || !$this->digest($body['baseline_sha256'])) throw new CloudException('handover_stop_unconfirmed');
        return $receipt + ['fence_sha256' => hash('sha256', $body['fence_id']), 'baseline_sha256' => $body['baseline_sha256']];
    }

    public function closed(array $scope, array $proof, int $now): array
    {
        $body = $this->body($proof, ['scope_sha256', 'observed_at', 'valid_until', 'fence_state', 'in_flight', 'outcomes']);
        $outcomes = $body['outcomes'];
        if (!is_array($outcomes) || !array_is_list($outcomes) || count($outcomes) !== count($scope['operations']))
            throw new CloudException('handover_closure_incomplete');
        $remaining = array_column($scope['operations'], 'known_outcome', 'operation_id');
        foreach ($outcomes as $row) {
            if (!is_array($row) || !$this->keys($row, ['operation_id', 'result', 'basis', 'evidence_sha256'])
                || !is_string($row['operation_id']) || !array_key_exists($row['operation_id'], $remaining)
                || !in_array($row['result'], ['confirmed_applied', 'confirmed_not_applied'], true)
                || !in_array($row['basis'], ['provider_final_receipt', 'independent_exact_reconciliation'], true)
                || ($remaining[$row['operation_id']] !== null && $remaining[$row['operation_id']] !== $row['result'])
                || !$this->digest($row['evidence_sha256'])) throw new CloudException('handover_closure_incomplete');
            unset($remaining[$row['operation_id']]);
        }
        $receipt = $this->verify(self::CLOSE, $proof, $body, $scope, $now);
        if ($body['fence_state'] !== 'fenced_until_handback' || $body['in_flight'] !== 0 || $body['observed_at'] < $scope['settled_at'])
            throw new CloudException('handover_closure_incomplete');
        return $receipt + ['scope_sha256' => $body['scope_sha256'], 'outcomes' => $outcomes];
    }

    public static function signingInput(string $purpose, string $issuer, array $body): string
    {
        return MachineIntentJson::encode(['protocol' => 'keli-machine-handover-v1', 'purpose' => $purpose, 'issuer' => $issuer, 'body' => $body]);
    }

    public static function hash(array $scope): string
    {
        return hash('sha256', MachineIntentJson::encode($scope));
    }

    private function body(?array $proof, array $keys): array
    {
        if ($proof === null) throw new CloudException('handover_evidence_required');
        if (!$this->keys($proof, ['issuer', 'body', 'signature']) || !is_string($proof['issuer'])
            || !preg_match('/^[a-z0-9_-]{1,40}$/D', $proof['issuer']) || !is_array($proof['body']) || !$this->keys($proof['body'], $keys)
            || !is_string($proof['signature']) || strlen($proof['signature']) > 2048) throw new CloudException('handover_evidence_invalid');
        foreach ($proof['body'] as $field => $value) if ($field !== 'outcomes' && !is_int($value) && (!is_string($value) || strlen($value) > 96))
            throw new CloudException('handover_evidence_invalid');
        return $proof['body'];
    }

    private function verify(string $purpose, array $proof, array $body, array $scope, int $now): array
    {
        $issuer = $proof['issuer']; $key = $this->trustedKeys[$purpose][$issuer] ?? null;
        if (!$key || !function_exists('openssl_verify')) throw new CloudException('handover_authority_unavailable');
        $signature = base64_decode($proof['signature'], true);
        try { $input = self::signingInput($purpose, $issuer, $body); }
        catch (\JsonException) { throw new CloudException('handover_evidence_invalid'); }
        if ($signature === false || $signature === '' || @openssl_verify($input, $signature, $key, OPENSSL_ALGO_SHA256) !== 1)
            throw new CloudException('handover_evidence_invalid');
        if (!$this->digest($body['scope_sha256']) || !hash_equals(self::hash($scope), $body['scope_sha256']))
            throw new CloudException('handover_evidence_scope_changed');
        $from = $body['observed_at']; $until = $body['valid_until'];
        if (!is_int($from) || !is_int($until) || $from <= 0 || $from > $now || $until <= $now || $until - $from > self::MAX_AGE)
            throw new CloudException('handover_evidence_expired');
        return ['issuer' => $issuer, 'proof_sha256' => hash('sha256', $input), 'observed_at' => $from];
    }

    private function keys(array $input, array $keys): bool
    {
        return count($input) === count($keys) && !array_diff(array_keys($input), $keys);
    }

    private function digest(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }
}
