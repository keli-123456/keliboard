<?php

namespace App\Jobs;

use App\Services\ServerMachine\MachineOperationNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class SendMachineOperationNoticeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;
    public int $tries = 1;
    public int $timeout = 30;
    public function __construct(public readonly int $noticeId)
    {
        $this->onConnection('redis');
        $this->onQueue('admin_operations');
    }
    public function handle(MachineOperationNotifier $notifier): void { $notifier->deliver($this->noticeId); }
}
