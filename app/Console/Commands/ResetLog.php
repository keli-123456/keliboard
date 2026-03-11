<?php

namespace App\Console\Commands;

use App\Models\AdminAuditLog;
use App\Models\Log;
use App\Models\RiskEvent;
use App\Models\StatServer;
use App\Models\StatUser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class ResetLog extends Command
{
    protected $builder;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reset:log';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '清空日志';

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
    public function handle()
    {
        StatUser::where('record_at', '<', strtotime('-2 month', time()))->delete();
        StatServer::where('record_at', '<', strtotime('-2 month', time()))->delete();
        if (Schema::hasTable('v2_log')) {
            Log::where('created_at', '<', strtotime('-1 month', time()))->delete();
        }
        if (Schema::hasTable('v2_admin_audit_log')) {
            AdminAuditLog::where('created_at', '<', strtotime('-3 month', time()))->delete();
        }
        if (Schema::hasTable('v2_risk_event')) {
            RiskEvent::where('created_at', '<', strtotime('-30 days', time()))->delete();
        }
    }
}
