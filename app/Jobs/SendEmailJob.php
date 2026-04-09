<?php

namespace App\Jobs;

use App\Models\MessageDispatchLog;
use App\Services\MailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    protected $params;

    public $tries = 3;
    public $timeout = 10;
    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($params, $queue = 'send_email')
    {
        $this->onQueue($queue);
        $this->params = $params;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $result = MailService::sendEmail($this->params);
        if (
            $result['error'] &&
            in_array($result['failure_classification'] ?? null, [
                MessageDispatchLog::FAILURE_TEMPORARY,
                MessageDispatchLog::FAILURE_PROVIDER,
                MessageDispatchLog::FAILURE_RATE_LIMIT,
                MessageDispatchLog::FAILURE_TIMEOUT,
            ], true)
        ) {
            $this->release(); // 仅对可恢复错误触发重试
        }
    }
}
