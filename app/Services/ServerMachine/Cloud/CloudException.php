<?php
namespace App\Services\ServerMachine\Cloud;

class CloudException extends \RuntimeException
{
    public const REPLACEMENT_CHECKS = [
        'replacement_context' => ['context_available', 'context_matches', 'installation_matches'],
        'replacement_decision' => ['evidence_current', 'threshold_reached', 'probe_failed', 'decision_matches', 'episode_present'],
        'native_source' => ['installation_matches', 'context_matches', 'evidence_current', 'threshold_reached', 'probe_failed', 'episode_matches'],
        'dispatch_reservation' => ['revision_matches', 'episode_matches'],
    ];
    private ?array $replacementDiagnostic = null;

    public static function replacementEvidence(string $guard, array $checks, bool $pending = false): self
    {
        $safe = [];
        foreach (self::REPLACEMENT_CHECKS[$guard] ?? [] as $name) $safe[$name] = ($checks[$name] ?? null) === true;
        $failed = array_keys(array_filter($safe, fn ($value) => !$value));
        $error = new self($pending ? 'replacement_evidence_pending' : 'replacement_evidence_changed');
        if ($safe && $failed) $error->replacementDiagnostic = ['schema' => 1, 'guard' => $guard,
            'checks' => $safe, 'failed_fields' => $failed];
        return $error;
    }

    public function replacementDiagnostic(): ?array { return $this->replacementDiagnostic; }

    public static function safeReplacementDiagnostic(mixed $value): ?array
    {
        if (!is_array($value) || count($value) !== 4 || ($value['schema'] ?? null) !== 1
            || !is_string($value['guard'] ?? null) || !isset(self::REPLACEMENT_CHECKS[$value['guard']])) return null;
        $names = self::REPLACEMENT_CHECKS[$value['guard']]; $checks = $value['checks'] ?? null;
        if (!is_array($checks) || count($checks) !== count($names)) return null;
        $safe = [];
        foreach ($names as $name) {
            if (!is_bool($checks[$name] ?? null)) return null;
            $safe[$name] = $checks[$name];
        }
        $failed = array_keys(array_filter($safe, fn ($value) => !$value));
        if (!$failed || ($value['failed_fields'] ?? null) !== $failed) return null;
        return ['schema' => 1, 'guard' => $value['guard'], 'checks' => $safe, 'failed_fields' => $failed];
    }
}
