<?php

declare(strict_types=1);

namespace Plugin\SubscriptionControl\Services;

use Illuminate\Cache\RedisStore;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

final class SubscriptionRateLimiter
{
    private const WINDOW = <<<'LUA'
redis.replicate_commands()
local clock = redis.call('TIME')
local now = tonumber(clock[1]) * 1000 + math.floor(tonumber(clock[2]) / 1000)
local window = tonumber(ARGV[1])
redis.call('ZREMRANGEBYSCORE', KEYS[1], '-inf', now - window)
if redis.call('ZCARD', KEYS[1]) >= tonumber(ARGV[2]) then return 0 end
if redis.call('ZADD', KEYS[1], 'NX', now, ARGV[3]) ~= 1 then return 0 end
redis.call('PEXPIRE', KEYS[1], window)
return 1
LUA;

    public function admit(int $userId, int $requests, int $window): bool
    {
        if ($requests < 1) return false;
        $window = max(1, $window);
        $key = 'subscription_control:rate_limit:' . $userId;
        $cache = Cache::store();
        $store = $cache->getStore();
        if ($store instanceof RedisStore) {
            // The versioned key keeps legacy serialized counters separate from the sorted set.
            $admission = $store->connection()->eval(self::WINDOW, 1, $store->getPrefix() . $key . ':v2',
                $window * 1000, $requests, bin2hex(random_bytes(16)));
            if (!in_array($admission, [0, 1, '0', '1'], true)) throw new RuntimeException('Subscription counter reply was not confirmed');
            return (int) $admission === 1;
        }
        if (!$store instanceof LockProvider) throw new RuntimeException('Subscription counter requires an atomic cache store');
        return $store->lock($key . ':lock', 5)->block(1, function () use ($cache, $key, $requests, $window): bool {
            $now = time();
            $timestamps = array_values(array_filter($cache->get($key, []), fn ($at) => $at > $now - $window));
            if (count($timestamps) >= $requests) return false;
            $timestamps[] = $now;
            if (!$cache->put($key, $timestamps, $window)) throw new RuntimeException('Subscription counter could not be saved');
            return true;
        });
    }
}
