<?php

declare(strict_types=1);

namespace App\Services\ServerMachine;

use App\Models\ServerMachine;
use App\Services\Node\NodeReportSource;

final class MachineRiskNodeSync
{
    public const MAX_SAMPLE_AGE = 120;

    /** Capture only the proof carried by this authenticated online request. */
    public function capture(ServerMachine $machine, string $installation, int $node, mixed $header, int $now): ?array
    {
        if (!$machine->is_active || !config('app.key') || !NodeReportSource::installation($installation)
            || !is_string($header) || strlen($header) > 850
            || !preg_match('/^v1\.([a-f0-9]{32})\.([1-9][0-9]{0,10})\.([1-9][0-9]{0,18})\.([1-9][0-9]{0,9})\.([0-9,]+)$/D', $header, $parts)) {
            return null;
        }
        $at = (int) $parts[2]; $revision = (int) $parts[3];
        if ((string) $revision !== $parts[3] || (int) $parts[4] !== $node || $at > $now + 5 || $at < $now - self::MAX_SAMPLE_AGE) return null;
        $users = explode(',', $parts[5]);
        if (count($users) > MachineRiskActivity::USERS || count(array_unique($users)) !== count($users)) return null;
        foreach ($users as $id) {
            if (!preg_match('/^[1-9][0-9]{0,9}$/D', $id) || (int) $id > 4294967295) return null;
        }
        return ['run_id' => $parts[1], 'sampled_at' => $at, 'revision' => $revision, 'users' => array_map('intval', $users),
            'node_id' => $node, 'source' => 'machine:' . $machine->id . ':' . $installation,
            'authority' => app(MachineRiskEventService::class)->authority($machine)];
    }

    public function installation(?string $source, int $machine): ?string
    {
        $prefix = 'machine:' . $machine . ':';
        return $source !== null && str_starts_with($source, $prefix) ? NodeReportSource::installation(substr($source, strlen($prefix))) : null;
    }

    public function forActivity(int $machine, string $source, int $node, int $at, mixed $proof): ?array
    {
        if (!is_array($proof) || ($proof['source'] ?? null) !== $source || ($proof['node_id'] ?? null) !== $node
            || $this->installation($source, $machine) === null || !self::validReceipt($proof, $at)
            || !is_array($proof['users'] ?? null) || count($proof['users']) > MachineRiskActivity::USERS) return null;
        $owner = ServerMachine::query()->find($machine, ['id', 'token', 'is_active']);
        if (!$owner || !$owner->is_active || !config('app.key')
            || !hash_equals((string) ($proof['authority'] ?? ''), app(MachineRiskEventService::class)->authority($owner))) return null;
        return array_intersect_key($proof, array_flip(['revision', 'sampled_at', 'run_id', 'users']));
    }

    public static function validReceipt(array $proof, int $receivedAt): bool
    {
        $sampledAt = $proof['sampled_at'] ?? null;
        return NodeReportSource::installation($proof['run_id'] ?? null) !== null
            && is_int($proof['revision'] ?? null) && $proof['revision'] > 0
            && is_int($sampledAt) && $sampledAt > 0 && $sampledAt <= $receivedAt + 5 && $sampledAt >= $receivedAt - self::MAX_SAMPLE_AGE;
    }
}
