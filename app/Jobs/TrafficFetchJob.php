<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\UserSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class TrafficFetchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    protected $data;
    protected $server;
    protected $protocol;
    protected $timestamp;
    public $tries = 1;
    public $timeout = 20;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(array $server, array $data, $protocol, int $timestamp)
    {
        $this->onQueue('traffic_fetch');
        $this->server = $server;
        $this->data = $data;
        $this->protocol = $protocol;
        $this->timestamp = $timestamp;
    }

    public function handle(): void
    {
        foreach ($this->data as $uid => $v) {
            User::where('id', $uid)
                ->incrementEach(
                    [
                        'u' => $v[0] * $this->server['rate'],
                        'd' => $v[1] * $this->server['rate'],
                    ],
                    ['t' => time()]
                );
        }

        // Emit user_sync events when a user crosses the traffic quota boundary.
        $uids = array_keys($this->data);
        if (empty($uids) || !DB::getSchemaBuilder()->hasTable('user_sync_states')) {
            return;
        }

        $exceeded = DB::table('v2_user as u')
            ->join('user_sync_states as s', 's.user_id', '=', 'u.id')
            ->whereIn('u.id', $uids)
            ->where('s.available', 1)
            ->whereNotNull('u.transfer_enable')
            ->whereRaw('(COALESCE(u.u, 0) + COALESCE(u.d, 0)) >= u.transfer_enable')
            ->pluck('u.id');

        if ($exceeded->isEmpty()) {
            return;
        }

        $sync = app(UserSyncService::class);
        foreach ($exceeded as $userId) {
            $sync->syncUserById((int) $userId, 'traffic_exceeded');
        }
    }
}
