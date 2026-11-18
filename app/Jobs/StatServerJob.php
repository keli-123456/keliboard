<?php

namespace App\Jobs;

use App\Jobs\Concerns\WritesStatBatch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class StatServerJob implements ShouldQueue
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

    public function __construct(array $server, array $data, $protocol, string $recordType = 'd', ?int $recordAt = null)
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
        $u = $d = 0;
        foreach ($this->data as $traffic) {
            $u += $traffic[0];
            $d += $traffic[1];
        }

        $now = time();
        $this->writeStatBatch('v2_stat_server', [[
            'server_id' => $this->server['id'],
            'server_type' => $this->protocol,
            'record_at' => $this->statRecordAt($this->recordAt, $this->recordType),
            'record_type' => $this->recordType,
            'u' => (int) $u,
            'd' => (int) $d,
            'created_at' => $now,
            'updated_at' => $now,
        ]]);
    }
}
