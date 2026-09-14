<?php

namespace App\Console\Commands;

use App\Services\AgentProfitService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SettleAgentProfit extends Command
{
    protected $signature = 'agent-profit:settle';
    protected $description = 'Settle fulfilled platform-collected agent earnings after the settlement delay';

    public function handle(AgentProfitService $service): int
    {
        if (!DB::getSchemaBuilder()->hasTable('v2_agent_profit')) {
            return self::SUCCESS;
        }
        $failed = 0;
        DB::table('v2_agent_profit')->where('status', 'pending')->where('available_at', '<=', time())
            ->orderBy('id')->chunkById(200, function ($rows) use ($service, &$failed) {
                foreach ($rows as $row) {
                    try {
                        $service->settle((int) $row->order_id);
                    } catch (\Throwable $e) {
                        report($e);
                        $failed++;
                    }
                }
            });
        $this->info('Agent profit settlement failures: ' . $failed);
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
