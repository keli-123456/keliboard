<?php

declare(strict_types=1);

namespace Tests\Integration\SubscriptionControl;

use Illuminate\Cache\CacheManager;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Cache;
use Plugin\SubscriptionControl\Services\SubscriptionRateLimiter;
use Tests\TestCase;

final class SubscriptionRateLimiterRedisTest extends TestCase
{
    public function test_concurrent_workers_admit_only_the_configured_rolling_quota(): void
    {
        $binary = getenv('KELI_RATE_LIMIT_REDIS_SERVER');
        if (!$binary || PHP_OS_FAMILY !== 'Linux' || !extension_loaded('redis') || !function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires Linux, PHP redis/pcntl, and KELI_RATE_LIMIT_REDIS_SERVER.');
        }
        $path = realpath($binary);
        $this->assertTrue(is_string($path) && is_file($path) && is_executable($path));
        $dir = sys_get_temp_dir() . '/risk-limit-' . bin2hex(random_bytes(8));
        $this->assertTrue(mkdir($dir, 0700));
        $socket = $dir . '/redis.sock';
        $server = null;
        $workers = [];
        try {
            // No TCP listener, persistence, or application .env is used.
            $server = proc_open([$path, '--port', '0', '--unixsocket', $socket, '--unixsocketperm', '600',
                '--dir', $dir, '--save', '', '--appendonly', 'no', '--daemonize', 'no'],
                [0 => ['file', '/dev/null', 'r'], 1 => ['file', $dir . '/redis.log', 'w'], 2 => ['file', $dir . '/redis.err', 'w']], $pipes, $dir);
            $this->assertIsResource($server);
            $deadline = microtime(true) + 5;
            $ready = false;
            do {
                $probe = @stream_socket_client('unix://' . $socket, timeout: 0.1);
                if (is_resource($probe)) {
                    fclose($probe);
                    $ready = true;
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $deadline && proc_get_status($server)['running']);
            $this->assertTrue($ready, 'Isolated Redis socket did not start');

            $prefix = 'risk-limit:' . bin2hex(random_bytes(8)) . ':';
            $start = microtime(true) + 1;
            for ($worker = 0; $worker < 12; $worker++) {
                $pid = pcntl_fork();
                if ($pid === 0) {
                    try {
                        $service = $this->isolatedLimiter($socket, $prefix);
                        usleep(max(0, (int) (($start - microtime(true)) * 1000000)));
                        $admitted = 0;
                        for ($request = 0; $request < 8; $request++) $admitted += (int) $service->admit(101, 30, 300);
                        exit($admitted);
                    } catch (\Throwable) {
                        exit(125);
                    }
                }
                $this->assertGreaterThan(0, $pid);
                $workers[] = $pid;
            }

            $total = 0;
            foreach ($workers as $index => $pid) {
                $this->assertSame($pid, pcntl_waitpid($pid, $status));
                unset($workers[$index]);
                $this->assertTrue(pcntl_wifexited($status));
                $count = pcntl_wexitstatus($status);
                $this->assertLessThanOrEqual(8, $count, 'An isolated worker failed');
                $total += $count;
            }
            $this->assertSame(30, $total);
            $service = $this->isolatedLimiter($socket, $prefix);
            $this->assertFalse($service->admit(101, 30, 300));
            $this->assertTrue($service->admit(102, 1, 1));
            $this->assertFalse($service->admit(102, 1, 1));
            usleep(1100000);
            $this->assertTrue($service->admit(102, 1, 1));
        } finally {
            foreach ($workers as $pid) pcntl_waitpid($pid, $status);
            if (is_resource($server)) {
                proc_terminate($server);
                proc_close($server);
            }
            foreach ([$socket, $dir . '/redis.log', $dir . '/redis.err'] as $file) {
                if (file_exists($file)) unlink($file);
            }
            rmdir($dir);
        }
    }

    private function isolatedLimiter(string $socket, string $prefix): SubscriptionRateLimiter
    {
        app()->instance('redis', new RedisManager(app(), 'phpredis', ['default' => [
            'host' => $socket, 'port' => 0, 'timeout' => 1, 'read_timeout' => 1, 'max_retries' => 0,
        ]]));
        config(['cache.default' => 'redis', 'cache.stores.redis' => ['driver' => 'redis', 'connection' => 'default'], 'cache.prefix' => $prefix]);
        Cache::swap(new CacheManager(app()));
        return app(SubscriptionRateLimiter::class);
    }
}
