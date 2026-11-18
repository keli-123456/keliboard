<?php

namespace App\Jobs;

use App\Jobs\Concerns\WritesStatBatch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class StatUserJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WritesStatBatch;

    protected array $data;
    protected array $server;
    protected string $protocol;
    protected string $recordType;
    protected ?int $recordAt = null;

    public $tries = 3;
    public $timeout = 60;
    public $maxExceptions = 3;

    public function __construct(array $server, array $data, string $protocol, string $recordType = 'd', ?int $recordAt = null)
    {
        $this->onQueue('stat');
        $this->data = $data;
        $this->server = $server;
        $this->protocol = $protocol;
        $this->recordType = $recordType;
        $this->recordAt = $this->initializeStatBatch($recordAt, $recordType);
    }

    public function backoff(): array
    {
        return [1, 5, 10];
    }

    public function handle(): void
    {
        $rows = $this->buildRows($this->statRecordAt($this->recordAt, $this->recordType));
        $this->writeStatBatch('v2_stat_user', $rows);
    }

    private function buildRows(int $recordAt): array
    {
        $rows = [];
        $now = time();
        $rate = (float) ($this->server['rate'] ?? 1);

        foreach ($this->data as $uid => $traffic) {
            $userId = (int) $uid;
            if ($userId <= 0 || !is_array($traffic) || count($traffic) < 2) {
                continue;
            }

            $upload = is_numeric($traffic[0] ?? null) ? (float) $traffic[0] : 0.0;
            $download = is_numeric($traffic[1] ?? null) ? (float) $traffic[1] : 0.0;

            $rows[] = [
                'user_id' => $userId,
                'server_rate' => $rate,
                'record_at' => $recordAt,
                'record_type' => $this->recordType,
                'u' => $upload * $rate,
                'd' => $download * $rate,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return $rows;
    }
}
