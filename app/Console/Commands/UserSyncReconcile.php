<?php

namespace App\Console\Commands;

use App\Services\UserSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UserSyncReconcile extends Command
{
    protected $signature = 'usersync:reconcile {--limit=5000 : Max users to process per run}';
    protected $description = 'Emit user sync events for time-based/quota-based changes';

	public function __construct(
		private UserSyncService $userSyncService
	) {
		parent::__construct();
	}

    public function handle(): int
    {
        if (!Schema::hasTable('v2_user') || !Schema::hasTable('user_sync_states')) {
            return self::SUCCESS;
        }

        $now = time();
        $limit = (int) $this->option('limit');
        if ($limit <= 0) {
            $limit = 5000;
        }
        $limit = min($limit, 50000);

        $ids = DB::table('v2_user as u')
            ->join('user_sync_states as s', 's.user_id', '=', 'u.id')
            ->where('s.available', 1)
            ->where(function ($q) use ($now) {
                $q->where(function ($q) use ($now) {
                    $q->whereNotNull('u.expired_at')
                        ->where('u.expired_at', '>', 0)
                        ->where('u.expired_at', '<', $now);
                })->orWhere(function ($q) {
                    $q->whereNotNull('u.transfer_enable')
                        ->whereRaw('(COALESCE(u.u, 0) + COALESCE(u.d, 0)) >= u.transfer_enable');
                });
            })
            ->orderBy('u.id')
            ->limit($limit)
            ->pluck('u.id');

        if ($ids->isEmpty()) {
            return self::SUCCESS;
        }

        foreach ($ids as $userId) {
            $this->userSyncService->syncUserById((int) $userId, 'reconcile');
        }

        $this->info('usersync: reconciled ' . $ids->count() . ' users');
        return self::SUCCESS;
    }
}
