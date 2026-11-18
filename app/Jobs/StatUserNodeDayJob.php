<?php

namespace App\Jobs;

use App\Jobs\Concerns\WritesStatBatch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class StatUserNodeDayJob implements ShouldQueue
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
        $rows = [];
        $rate = (float) ($this->server['rate'] ?? 1);
        $recordAt = $this->statRecordAt($this->recordAt, $this->recordType);
        $now = time();

        foreach ($this->data as $uid => $traffic) {
            $upload = (float) ($traffic[0] ?? 0);
            $download = (float) ($traffic[1] ?? 0);
            if ($upload <= 0 && $download <= 0) {
                continue;
            }

            $rows[] = [
                'user_id' => (int) $uid,
                'server_id' => (int) ($this->server['id'] ?? 0),
                'server_type' => (string) ($this->protocol ?: ($this->server['type'] ?? '')),
                'server_name' => (string) ($this->server['name'] ?? ''),
                'server_rate' => $rate,
                'u' => $upload * $rate,
                'd' => $download * $rate,
                'record_type' => $this->recordType,
                'record_at' => $recordAt,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $this->writeStatBatch('v2_stat_user_node_day', $rows);
    }
}
