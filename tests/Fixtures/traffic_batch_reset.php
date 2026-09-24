<?php

declare(strict_types=1);

use App\Models\Plan;
use App\Models\User;
use App\Services\TrafficResetService;
use App\Services\UserSyncService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

if (!isset($root, $action) || getenv('KELI_TRAFFIC_RESET_FIXTURE') !== '1') {
    throw new RuntimeException('Guarded traffic fixture include only.');
}

return new class($root, $action) {
    private ?bool $resetResult = null;

    public function __construct(private string $root, private string $currentAction) {}

    public function boot(): void
    {
        config(['app.timezone' => 'UTC']);
        DB::connection()->setTransactionManager(new \Illuminate\Database\DatabaseTransactionsManager());
        \Illuminate\Database\Eloquent\Model::setEventDispatcher(app('events'));
        User::observe(app(\App\Observers\UserObserver::class));
        app()->instance('log', new class($this->root) {
            public function __construct(private string $root) {}
            public function __call(string $level, array $arguments): void
            {
                file_put_contents($this->root . '/service-log.jsonl', json_encode(
                    ['level' => $level, 'arguments' => $arguments], JSON_THROW_ON_ERROR) . PHP_EOL, FILE_APPEND | LOCK_EX);
            }
        });
        \Illuminate\Support\Facades\Facade::clearResolvedInstance('log');
        app()->instance(\App\Services\NodeRealtime\NodeRealtimePublisher::class, new class($this->root) extends \App\Services\NodeRealtime\NodeRealtimePublisher {
            public function __construct(private string $root) {}
            public function invalidateUsersForGroups(array $groupIds, string $reason = 'users.updated', array $payload = []): void
            {
                // Capture only the external publish boundary; SQL state/events and observers are real.
                $event = DB::table('user_sync_events')->where('id', $payload['revision'] ?? 0)->first();
                if (DB::transactionLevel() !== 0 || !$event) { throw new RuntimeException('Publish before committed event.'); }
                file_put_contents($this->root . '/published.jsonl', json_encode([
                    'groups' => $groupIds, 'reason' => $reason, 'revision' => $payload['revision'],
                    'available' => (bool) $event->available, 'transaction_level' => DB::transactionLevel(),
                ], JSON_THROW_ON_ERROR) . PHP_EOL, FILE_APPEND | LOCK_EX);
            }
        });
        if (in_array($this->currentAction, ['apply', 'reset'], true) && in_array(DB::getDriverName(), ['pgsql', 'mysql'], true)) {
            file_put_contents($this->root . '/actor-' . getmypid() . '.json', json_encode([
                // MySQL identifies the server session by connection ID, not an OS PID.
                'pid' => getmypid(), 'backend_pid' => (int) DB::selectOne(DB::getDriverName() === 'pgsql'
                    ? 'SELECT pg_backend_pid() AS pid' : 'SELECT CONNECTION_ID() AS pid')->pid,
                'action' => $this->currentAction,
            ], JSON_THROW_ON_ERROR));
        }
        $hold = (string) getenv('KELI_TRAFFIC_RESET_HOLD');
        if ($hold !== '') {
            $phase = (string) getenv('KELI_TRAFFIC_RESET_HOLD_PHASE');
            if (!in_array($this->currentAction, ['apply', 'reset'], true) || dirname($hold) !== $this->root
                || !preg_match('/^hold-[a-f0-9]{32}$/D', basename($hold))
                || !in_array($phase, ['user-update', 'before-sync'], true) || !in_array(DB::getDriverName(), ['pgsql', 'mysql'], true)) {
                throw new RuntimeException('Invalid isolated reset coordination barrier.');
            }
            $fired = false;
            DB::connection()->beforeExecuting(function ($sql, $bindings, $connection) use ($hold, $phase, &$fired): void {
                $sql = str_replace('`', '"', $sql);
                $target = $phase === 'user-update'
                    ? str_starts_with($sql, 'update "v2_user" set ')
                    : (str_starts_with($sql, 'select * from "v2_user"') && str_ends_with($sql, 'for update'));
                if ($fired || !$target) { return; }
                $fired = true;
                if ($connection->transactionLevel() < 1) { throw new RuntimeException('Expected real service transaction.'); }
                file_put_contents($hold . '.ready', json_encode(['phase' => $phase, 'level' => $connection->transactionLevel()], JSON_THROW_ON_ERROR));
                $deadline = microtime(true) + 10;
                while (!is_file($hold)) {
                    if (microtime(true) >= $deadline) { throw new RuntimeException('Reset coordination barrier timed out.'); }
                    usleep(10000);
                    clearstatcache(true, $hold);
                }
            });
        }
    }

    public function initialize(): void
    {
        Schema::create('v2_plan', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->integer('reset_traffic_method');
        });
        DB::table('v2_plan')->insert(['id' => 20, 'reset_traffic_method' => Plan::RESET_TRAFFIC_FIRST_DAY_MONTH]);
        Schema::table('v2_user', function (Blueprint $table): void {
            $table->integer('plan_id')->default(20);
            $table->integer('group_id')->default(10);
            $table->string('uuid')->default('fixture-user-not-production');
            $table->string('token')->default('fixture-token-not-production');
            $table->string('email')->default('fixture@example.invalid');
            $table->bigInteger('transfer_enable')->default(180);
            $table->boolean('banned')->default(false);
            $table->integer('expired_at')->nullable();
            $table->integer('next_reset_at')->nullable();
            $table->integer('last_reset_at')->nullable();
            $table->integer('reset_count')->default(0);
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
            $table->integer('speed_limit')->default(0);
            $table->integer('device_limit')->default(0);
        });
        require_once base_path('database/migrations/2025_06_21_000002_create_traffic_reset_logs_table.php');
        (new \CreateTrafficResetLogsTable())->up();
        (require base_path('database/migrations/2025_12_30_000001_create_user_sync_tables.php'))->up();
        $this->seed('empty');
    }

    private function seed(string $variant): void
    {
        if (!in_array($variant, ['empty', 'due', 'over-quota'], true)
            || DB::table('v2_traffic_batch')->exists() || DB::table('v2_traffic_reset_logs')->exists()
            || DB::table('user_sync_events')->exists()) {
            throw new RuntimeException('Only seed a pristine, explicitly isolated fixture.');
        }
        [$u, $d] = match ($variant) { 'due' => [90, 60], 'over-quota' => [190, 160], default => [0, 0] };
        DB::table('v2_user')->where('id', 7)->update([
            'u' => $u, 'd' => $d, 'next_reset_at' => time() - 60, 'created_at' => time() - 86400 * 40,
        ]);
        $snapshot = app(UserSyncService::class)->computeSnapshot(User::query()->findOrFail(7));
        DB::table('user_sync_states')->updateOrInsert(['user_id' => 7], $snapshot);
    }

    private function fault(string $name, string $table, string $event, bool $on): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared($on
                ? "CREATE TRIGGER $name BEFORE $event ON $table BEGIN SELECT RAISE(ABORT, 'fixture $name'); END"
                : "DROP TRIGGER $name");
        } elseif (DB::getDriverName() === 'mysql') {
            DB::unprepared($on
                ? "CREATE TRIGGER $name BEFORE $event ON $table FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture $name'"
                : "DROP TRIGGER $name");
        } elseif ($on) {
            DB::unprepared("CREATE FUNCTION $name() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'fixture $name'; END; \$\$");
            DB::unprepared("CREATE TRIGGER $name BEFORE $event ON $table FOR EACH ROW EXECUTE FUNCTION $name()");
        } else {
            DB::unprepared("DROP TRIGGER $name ON $table");
            DB::unprepared("DROP FUNCTION $name()");
        }
    }

    public function action(string $action, string $argument): void
    {
        switch ($action) {
            case 'reset-seed': $this->seed($argument); break;
            case 'reset':
                $user = User::query()->findOrFail(7);
                $this->resetResult = match ($argument) {
                    'manual' => app(TrafficResetService::class)->manualReset($user, ['fixture' => true]),
                    'cron' => app(TrafficResetService::class)->checkAndReset($user, \App\Models\TrafficResetLog::SOURCE_CRON),
                    default => throw new RuntimeException('Unsupported fixture reset source.'),
                };
                break;
            case 'reset-audit-off': $this->fault('fixture_reset_audit', 'v2_traffic_reset_logs', 'INSERT', true); break;
            case 'reset-audit-on': $this->fault('fixture_reset_audit', 'v2_traffic_reset_logs', 'INSERT', false); break;
            case 'quota-sync-off': $this->fault('fixture_quota_sync', 'user_sync_states', 'UPDATE', true); break;
            case 'quota-sync-on': $this->fault('fixture_quota_sync', 'user_sync_states', 'UPDATE', false); break;
            default: throw new RuntimeException('Unknown reset fixture command.');
        }
    }

    public function snapshot(): array
    {
        $lines = function (string $name): array {
            $file = $this->root . '/' . $name . '.jsonl';
            return !is_file($file) ? [] : array_map(fn ($line) => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
                file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        };
        return [
            'reset_result' => $this->resetResult,
            'user' => DB::table('v2_user')->where('id', 7)->first(['id', 'u', 'd', 'reset_count', 'next_reset_at', 'last_reset_at', 'transfer_enable']),
            'logs' => DB::table('v2_traffic_reset_logs')->orderBy('id')->get()->all(),
            'states' => DB::table('user_sync_states')->orderBy('user_id')->get()->all(),
            'events' => DB::table('user_sync_events')->orderBy('id')->get()->all(),
            'published' => $lines('published'), 'service_log' => $lines('service-log'),
        ];
    }
};
