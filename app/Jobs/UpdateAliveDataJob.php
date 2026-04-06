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

  private const NODE_DATA_EXPIRY = 100;

  public function __construct(
    private readonly array $data,
    private readonly string $nodeType,
    private readonly int $nodeId
  ) {
    $this->onQueue('online_sync');
  }

  public function handle(): void
  {
    try {
      $updateAt = time();
      $nodeKey = $this->nodeType . $this->nodeId;
      $mode = UserOnlineService::getDeviceLimitMode();
      $ttlSeconds = UserOnlineService::aliveCacheTtlSeconds();
      $ttl = now()->addSeconds($ttlSeconds);
      $lastOnlineAt = now();
      $cacheUpdates = [];
      $changedOnlineUpdates = [];
      $realtimeCounts = [];
      $onlineIps = [];
      $cacheKeys = [];

      foreach ($this->data as $uid => $ips) {
        $uid = (int) $uid;
        if ($uid <= 0) {
          continue;
        }
        $onlineIps[$uid] = array_values((array) $ips);
        $cacheKeys[$uid] = UserOnlineService::cacheKey($uid);
      }

      $cachedAliveData = $this->loadCachedAliveData($cacheKeys);

      foreach ($cacheKeys as $uid => $cacheKey) {
        $cachedUserData = $cachedAliveData[$cacheKey] ?? [];
        $previousCount = is_array($cachedUserData) && isset($cachedUserData['alive_ip'])
          ? (int) $cachedUserData['alive_ip']
          : null;

        $ipsArray = $this->filterFreshNodeData($cachedUserData, $updateAt);
        $ipsArray[$nodeKey] = [
          'aliveips' => $onlineIps[$uid] ?? [],
          'lastupdateAt' => $updateAt,
        ];

        $count = UserOnlineService::calculateDeviceCount($ipsArray, $mode);
        $ipsArray['alive_ip'] = $count;

        $cacheUpdates[$cacheKey] = $ipsArray;
        $realtimeCounts[$uid] = $count;
        if ($previousCount !== $count) {
          $changedOnlineUpdates[] = [
            'id' => $uid,
            'online_count' => $count,
            'last_online_at' => $lastOnlineAt,
          ];
        }
      }

      $this->storeCachedAliveData($cacheUpdates, $ttl);
      UserOnlineService::updateRealtimeIndex($realtimeCounts, $updateAt + $ttlSeconds);
      $this->syncOnlineCounts($changedOnlineUpdates);
    } catch (\Throwable $e) {
      Log::error('UpdateAliveDataJob failed', [
        'error' => $e->getMessage(),
      ]);
      $this->fail($e);
    }
  }

  private function filterFreshNodeData(mixed $cachedData, int $updateAt): array
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
      if ($updateAt - $lastUpdateAt > self::NODE_DATA_EXPIRY) {
        continue;
      }

      $filtered[$key] = $value;
    }

    return $filtered;
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
