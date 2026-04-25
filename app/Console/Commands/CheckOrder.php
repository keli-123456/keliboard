<?php

namespace App\Console\Commands;

use App\Jobs\OrderHandleJob;
use Illuminate\Console\Command;
use App\Models\Order;

class CheckOrder extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check:order';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '订单检查任务';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle(): int
    {
        Order::query()
            ->whereIn('status', [Order::STATUS_PENDING, Order::STATUS_PROCESSING])
            ->select(['id', 'trade_no'])
            ->chunkById(500, function ($orders): void {
                foreach ($orders as $order) {
                    OrderHandleJob::dispatch($order->trade_no);
                }
            });

        return self::SUCCESS;
    }
}
