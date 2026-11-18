<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

use App\Services\StatBatchWriter;
use Illuminate\Support\Str;
use RuntimeException;

trait WritesStatBatch
{
    private ?string $statDeliveryId = null;

    protected function initializeStatBatch(?int $recordAt, string $period): int
    {
        $this->statDeliveryId = (string) Str::uuid();

        return $this->statPeriodStart($recordAt !== null && $recordAt > 0 ? $recordAt : time(), $period);
    }

    protected function statRecordAt(?int $recordAt, string $period): int
    {
        if ($recordAt !== null && $recordAt > 0) {
            return $recordAt;
        }

        // Pre-upgrade jobs did not always serialize the period; use the original enqueue time.
        $createdAt = $this->job?->payload()['createdAt'] ?? null;
        if ((!is_int($createdAt) && (!is_string($createdAt) || !ctype_digit($createdAt))) || (int) $createdAt <= 0) {
            throw new RuntimeException('Statistics delivery has no stable record date.');
        }

        return $this->statPeriodStart((int) $createdAt, $period);
    }

    protected function writeStatBatch(string $table, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        // Older serialized jobs have no application delivery ID; Laravel keeps the payload UUID on retry.
        $deliveryId = $this->statDeliveryId ?? $this->job?->uuid();
        if (!is_string($deliveryId)) {
            throw new RuntimeException('Statistics delivery has no stable identifier.');
        }

        app(StatBatchWriter::class)->write($table, $deliveryId, $rows);
    }

    private function statPeriodStart(int $timestamp, string $period): int
    {
        return (int) strtotime(date($period === 'm' ? 'Y-m-01' : 'Y-m-d', $timestamp));
    }
}
