<?php

namespace App\Jobs;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use App\Services\UserOnlineService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UpdateAliveDataJob implements ShouldQueue
{
  use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

  private const SNAPSHOT_LOCK_KEY = 'ALIVE_IP_SNAPSHOT_UPDATE_LOCK';
  private const SNAPSHOT_LOCK_SECONDS = 120;
  private const SNAPSHOT_LOCK_WAIT_SECONDS = 30;
  private ?float $reportedAt = null;

  public function __construct(
    private readonly array $data,
    private readonly string $nodeType,
    private readonly int $nodeId,
    ?float $reportedAt = null
  ) {
    $this->reportedAt = $reportedAt ?? microtime(true);
    $this->onQueue('online_sync');
  }

  public function handle(): void
  {
    try {
      $snapshotVersion = $this->reportedAt ?? microtime(true);
      Cache::lock(self::SNAPSHOT_LOCK_KEY, self::SNAPSHOT_LOCK_SECONDS)
        ->block(self::SNAPSHOT_LOCK_WAIT_SECONDS, function () use ($snapshotVersion): void {
          $versionCacheKey = $this->nodeSnapshotVersionCacheKey();
          if ($snapshotVersion < (float) Cache::get($versionCacheKey, 0.0)) {
            return;
          }

          $this->applySnapshot();
          Cache::put(
            $versionCacheKey,
            $snapshotVersion,
            now()->addSeconds(max(600, UserOnlineService::aliveCacheTtlSeconds() * 10))
          );
        });
    } catch (\Throwable $e) {
      Log::error('UpdateAliveDataJob failed', [
        'error' => $e->getMessage(),
      ]);
      $this->fail($e);
    }
  }

  private function applySnapshot(): void
  {
    $updateAt = time();
    $nodeKey = $this->nodeType . $this->nodeId;
    $mode = UserOnlineService::getDeviceLimitMode();
    $ttlSeconds = UserOnlineService::aliveCacheTtlSeconds();
    $nodeDataExpirySeconds = $this->nodeDataExpirySeconds();
    $ttl = now()->addSeconds($ttlSeconds);
    $lastOnlineAt = now();
    $cacheUpdates = [];
    $changedOnlineUpdates = [];
    $realtimeCounts = [];
    $currentOnlineIps = [];

    foreach ($this->data as $uid => $ips) {
      $uid = (int) $uid;
      if ($uid <= 0) {
        continue;
      }

      $normalizedIps = array_values(array_unique(array_filter(
        array_map(static fn($ip): string => trim((string) $ip), (array) $ips),
        static fn(string $ip): bool => $ip !== ''
      )));
      if (empty($normalizedIps)) {
        continue;
      }
      $currentOnlineIps[$uid] = $normalizedIps;
    }

    $nodeUsersCacheKey = UserOnlineService::nodeUsersCacheKey($this->nodeType, $this->nodeId);
    $previousNodeUserIds = array_values(array_unique(array_filter(
      array_map('intval', (array) Cache::get($nodeUsersCacheKey, [])),
      static fn(int $uid): bool => $uid > 0
    )));
    $currentNodeUserIds = array_keys($currentOnlineIps);
    sort($currentNodeUserIds, SORT_NUMERIC);
    $affectedUserIds = array_values(array_unique(array_merge(
      $previousNodeUserIds,
      $currentNodeUserIds
    )));

    $cacheKeys = [];
    foreach ($affectedUserIds as $uid) {
      $cacheKeys[$uid] = UserOnlineService::cacheKey($uid);
    }
    $cachedAliveData = $this->loadCachedAliveData($cacheKeys);

    foreach ($cacheKeys as $uid => $cacheKey) {
      $cachedUserData = $cachedAliveData[$cacheKey] ?? [];
      $previousOnlineCount = is_array($cachedUserData) && array_key_exists('online_count', $cachedUserData)
        ? (int) $cachedUserData['online_count']
        : null;

      $ipsArray = $this->filterFreshNodeData($cachedUserData, $updateAt, $nodeDataExpirySeconds);
      if (array_key_exists($uid, $currentOnlineIps)) {
        $ipsArray[$nodeKey] = [
          'aliveips' => $currentOnlineIps[$uid],
          'lastupdateAt' => $updateAt,
        ];
      } else {
        unset($ipsArray[$nodeKey]);
      }

      $deviceLimitCount = UserOnlineService::calculateDeviceCount($ipsArray, $mode);
      $onlineCount = UserOnlineService::calculateOnlineDeviceCount($ipsArray);
      $ipsArray['alive_ip'] = $deviceLimitCount;
      $ipsArray['online_count'] = $onlineCount;

      $cacheUpdates[$cacheKey] = $ipsArray;
      $realtimeCounts[$uid] = $onlineCount;
      if ($previousOnlineCount !== $onlineCount) {
        $changedOnlineUpdates[] = [
          'id' => $uid,
          'online_count' => $onlineCount,
          'last_online_at' => $lastOnlineAt,
        ];
      }
    }

    $this->storeCachedAliveData($cacheUpdates, $ttl);
    Cache::put($nodeUsersCacheKey, $currentNodeUserIds, $ttl);
    UserOnlineService::updateRealtimeIndex($realtimeCounts, $updateAt + $ttlSeconds);
    $this->syncOnlineCounts($changedOnlineUpdates);
  }

  private function nodeSnapshotVersionCacheKey(): string
  {
    return UserOnlineService::nodeUsersCacheKey($this->nodeType, $this->nodeId) . ':VERSION';
  }

  private function filterFreshNodeData(mixed $cachedData, int $updateAt, int $expirySeconds): array
  {
    if (!is_array($cachedData)) {
      return [];
    }

    $filtered = [];
    foreach ($cachedData as $key => $value) {
      if (!is_array($value)) {
        continue;
      }

      $lastUpdateAt = (int) ($value['lastupdateAt'] ?? 0);
      if ($updateAt - $lastUpdateAt > $expirySeconds) {
        continue;
      }

      $filtered[$key] = $value;
    }

    return $filtered;
  }

  private function nodeDataExpirySeconds(): int
  {
    $pushInterval = max(1, (int) admin_setting('server_push_interval', 60));

    // Keep the default 60s -> 100s behavior while adapting to longer push intervals.
    return max(100, $pushInterval + 40);
  }

  private function loadCachedAliveData(array $cacheKeys): array
  {
    if (empty($cacheKeys)) {
      return [];
    }

    $cachedAliveData = [];
    foreach (array_chunk(array_values($cacheKeys), 1000) as $chunk) {
      $cachedAliveData += Cache::many($chunk);
    }

    return $cachedAliveData;
  }

  private function storeCachedAliveData(array $cacheUpdates, \DateTimeInterface $ttl): void
  {
    if (empty($cacheUpdates)) {
      return;
    }

    foreach (array_chunk($cacheUpdates, 1000, true) as $chunk) {
      Cache::putMany($chunk, $ttl);
    }
  }

  private function syncOnlineCounts(array $onlineUpdates): void
  {
    if (empty($onlineUpdates)) {
      return;
    }

    foreach (array_chunk($onlineUpdates, 1000) as $chunk) {
      $ids = [];
      foreach ($chunk as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
          continue;
        }
        $ids[] = $id;
      }

      if (empty($ids)) {
        continue;
      }

      User::query()
        ->whereIn('id', $ids)
        ->update([
          'online_count' => DB::raw($this->buildOnlineCountCaseExpression($chunk)),
          'last_online_at' => $chunk[0]['last_online_at'],
        ]);
    }
  }

  private function buildOnlineCountCaseExpression(array $rows): string
  {
    $cases = ['CASE id'];

    foreach ($rows as $row) {
      $id = (int) ($row['id'] ?? 0);
      if ($id <= 0) {
        continue;
      }

      $cases[] = sprintf(
        'WHEN %d THEN %d',
        $id,
        (int) ($row['online_count'] ?? 0)
      );
    }

    $cases[] = 'ELSE online_count END';

    return implode(' ', $cases);
  }


}
