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
use Illuminate\Support\Facades\Log;

class UpdateAliveDataJob implements ShouldQueue
{
  use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

  private const CACHE_PREFIX = 'ALIVE_IP_USER_';
  private const CACHE_TTL = 120;
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
      $ttl = now()->addSeconds(self::CACHE_TTL);
      $lastOnlineAt = now();
      $cacheUpdates = [];
      $onlineUpdates = [];
      $onlineIps = [];
      $cacheKeys = [];

      foreach ($this->data as $uid => $ips) {
        $uid = (int) $uid;
        if ($uid <= 0) {
          continue;
        }
        $onlineIps[$uid] = array_values((array) $ips);
        $cacheKeys[$uid] = self::CACHE_PREFIX . $uid;
      }

      $cachedAliveData = $this->loadCachedAliveData($cacheKeys);

      foreach ($cacheKeys as $uid => $cacheKey) {
        $ipsArray = $this->filterFreshNodeData($cachedAliveData[$cacheKey] ?? [], $updateAt);
        $ipsArray[$nodeKey] = [
          'aliveips' => $onlineIps[$uid] ?? [],
          'lastupdateAt' => $updateAt,
        ];

        $count = UserOnlineService::calculateDeviceCount($ipsArray, $mode);
        $ipsArray['alive_ip'] = $count;

        $cacheUpdates[$cacheKey] = $ipsArray;
        $onlineUpdates[] = [
          'id' => $uid,
          'online_count' => $count,
          'last_online_at' => $lastOnlineAt,
        ];
      }

      $this->storeCachedAliveData($cacheUpdates, $ttl);
      $this->syncOnlineCounts($onlineUpdates);
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

    $existingIds = [];
    foreach (array_chunk(array_column($onlineUpdates, 'id'), 1000) as $idChunk) {
      $existingIds = [
        ...$existingIds,
        ...User::query()
          ->whereIn('id', $idChunk)
          ->pluck('id')
          ->map(fn($id): int => (int) $id)
          ->all(),
      ];
    }

    if (empty($existingIds)) {
      return;
    }

    $existingIdSet = array_fill_keys($existingIds, true);
    $rows = [];

    foreach ($onlineUpdates as $update) {
      $id = (int) ($update['id'] ?? 0);
      if ($id <= 0 || !isset($existingIdSet[$id])) {
        continue;
      }

      $rows[] = [
        'id' => $id,
        'online_count' => (int) ($update['online_count'] ?? 0),
        'last_online_at' => $update['last_online_at'],
      ];
    }

    foreach (array_chunk($rows, 1000) as $chunk) {
      User::query()->upsert($chunk, ['id'], ['online_count', 'last_online_at']);
    }
  }


}
