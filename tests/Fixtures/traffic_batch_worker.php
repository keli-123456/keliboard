<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Models\Server;
use App\Services\TrafficBatchAccounting;
use App\Services\TrafficBatchService;
use Illuminate\Support\Facades\DB;
use Tests\Support\InteractsWithInMemoryDatabase;

// This fixture boots only the unit-test container, never the application .env/database.
$worker = new class('traffic-batch-worker') extends \Tests\TestCase {
    use InteractsWithInMemoryDatabase;

    public function runWorker(string $database, string $action, string $marker, string $go): void
    {
        file_put_contents($marker . '.trace', "boot\n");
        parent::setUp();
        $this->setUpInMemoryDatabase();
        config(['database.connections.default.database' => $database]);
        DB::purge('default');
        DB::statement('PRAGMA busy_timeout = 5000');
        DB::statement('PRAGMA synchronous = FULL');
        file_put_contents($marker . '.trace', "database-ready\n", FILE_APPEND);
        if ($action === 'before_commit') {
            app()->instance(TrafficBatchAccounting::class, new class($marker, $go) extends TrafficBatchAccounting {
                public function __construct(private string $marker, private string $go) {}
                public function apply(array $payload): void
                {
                    parent::apply($payload);
                    file_put_contents($this->marker, 'inside-transaction', LOCK_EX);
                    $until = microtime(true) + 15;
                    while (!file_exists($this->go) && microtime(true) < $until) {
                        usleep(10000);
                        clearstatcache();
                    }
                    if (!file_exists($this->go)) {
                        throw new RuntimeException('Process fixture barrier timed out.');
                    }
                }
            });
        } elseif ($action !== 'after_commit') {
            file_put_contents($marker, 'ready', LOCK_EX);
            $until = microtime(true) + 15;
            while (!file_exists($go) && microtime(true) < $until) {
                usleep(10000);
                clearstatcache();
            }
            if (!file_exists($go)) {
                throw new RuntimeException('Process fixture start timed out.');
            }
        }
        if ($action === 'accept') {
            file_put_contents($marker . '.trace', "accepting\n", FILE_APPEND);
            $row = (new TrafficBatchService())->accept(Server::findOrFail(1), str_repeat('a', 32), [7 => [10, 20]]);
            echo 'RECEIPT=' . $row->id . PHP_EOL;
        } else {
            file_put_contents($marker . '.trace', "processing\n", FILE_APPEND);
            (new TrafficBatchService())->process(1);
            if ($action === 'after_commit') {
                file_put_contents($marker, 'committed', LOCK_EX);
                $until = microtime(true) + 15;
                while (!file_exists($go) && microtime(true) < $until) {
                    usleep(10000);
                    clearstatcache();
                }
            }
        }
        file_put_contents($marker . '.trace', "finished\n", FILE_APPEND);
    }
};

if (count($argv) !== 5 || !is_file($argv[1]) || !str_contains(basename(dirname($argv[1])), 'keli-traffic-batch-proc-')) {
    throw new RuntimeException('Expected an existing isolated traffic database.');
}
$worker->runWorker($argv[1], $argv[2], $argv[3], $argv[4]);
