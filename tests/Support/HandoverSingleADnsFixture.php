<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\ServerMachine\Cloud\MachineFailoverHandoverEvidence as Evidence;
use App\Services\ServerMachine\Cloud\MachineIntentJson;

final class HandoverSingleADnsFixture
{
    public const OLD_IP = '192.0.2.10';
    public const NEW_IP = '192.0.2.20';
    public const ORIGINAL = ['id' => 'fixture-a', 'zone' => 'handover.example.test',
        'name' => 'edge.handover.example.test', 'type' => 'A', 'line' => 'default',
        'content' => self::OLD_IP, 'ttl' => 60, 'marker' => 'fixture-owned'];
    private const NEIGHBOR = ['id' => 'fixture-neighbor', 'name' => 'other.handover.example.test',
        'content' => '192.0.2.30'] + self::ORIGINAL;

    private array $records = [self::ORIGINAL, self::NEIGHBOR];
    private string $cachedIp = self::OLD_IP;
    private array $healthy = [self::OLD_IP => true, self::NEW_IP => true];
    private array $requests = [];
    private int $writes = 0;
    private string $nextReply = 'normal';

    public static function switched(): array { return ['content' => self::NEW_IP] + self::ORIGINAL; }
    public function record(): array { return $this->records[0]; }
    public function inventory(): array { return $this->records; }
    public function dispatches(): int { return count($this->requests); }
    public function writes(): int { return $this->writes; }
    public function healthy(string $address): bool { return $this->healthy[$address] ?? false; }
    public function setHealthy(string $address, bool $healthy): void { $this->healthy[$address] = $healthy; }
    public function resolvedIp(): string { return $this->cachedIp; }
    public function propagate(): void { $this->cachedIp = $this->record()['content']; }
    public function drift(array $fields): void { $this->records[0] = $fields + $this->records[0]; }
    public function nextReply(string $mode): void { $this->nextReply = $mode; }

    public function send(array $intent): bool
    {
        $id = $intent['operation_id'];
        if (isset($this->requests[$id]) || !MachineIntentJson::same($intent['before'], $this->record())) {
            throw new \LogicException('Unexpected fixture DNS write.');
        }
        $this->requests[$id] = ['intent' => Evidence::hash($intent), 'final' => null, 'change' => $intent];
        $mode = $this->nextReply;
        $this->nextReply = 'normal';
        if ($mode === 'pending') return false;

        $this->finish($id, $mode !== 'rejected');
        return $mode !== 'lost_ack';
    }

    public function finish(string $id, bool $applied): void
    {
        $request = $this->requests[$id];
        if ($request['final'] !== null || !MachineIntentJson::same($request['change']['before'], $this->record())) {
            throw new \LogicException('Unexpected fixture DNS completion.');
        }
        if ($applied) {
            $this->records[0] = $request['change']['after'];
            $this->writes++;
        }
        $this->requests[$id]['final'] = $applied ? 'confirmed_applied' : 'confirmed_not_applied';
    }

    public function finalObservation(array $intent): ?array
    {
        $id = $intent['operation_id'];
        $request = $this->requests[$id] ?? null;
        if ($request === null || $request['final'] === null || $request['intent'] !== Evidence::hash($intent)) return null;
        $expected = $request['final'] === 'confirmed_applied' ? $intent['after'] : $intent['before'];
        if (!MachineIntentJson::same($expected, $this->record())) return null;

        return ['operation_id' => $id, 'result' => $request['final'], 'basis' => 'provider_final_receipt',
            'evidence_sha256' => Evidence::hash(['intent' => $request['intent'], 'final' => $request['final'], 'record' => $this->record()])];
    }
}
