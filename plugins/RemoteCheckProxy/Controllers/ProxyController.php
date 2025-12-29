<?php

namespace Plugin\RemoteCheckProxy\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Plugin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProxyController extends Controller
{
    private const PLUGIN_CODE = 'remote_check_proxy';

    public function fetch(Request $request)
    {
        $url = $this->getConfigValue('upstream_url')
            ?? '';
        $timeout = (int) ($this->getConfigValue('timeout_seconds') ?? 10);

        if (trim($url) === '') {
            return response()->json([
                'status' => 'fail',
                'message' => '未配置上游接口地址',
                'data' => null
            ], 400);
        }

        return $this->proxyGet($url, $timeout, 'fetch');
    }

    public function randomIp(Request $request)
    {
        $url = $this->getConfigValue('random_ip_url')
            ?? '';
        $timeout = (int) ($this->getConfigValue('timeout_seconds') ?? 10);

        if (trim($url) === '') {
            return response()->json([
                'status' => 'fail',
                'message' => '未配置随机IP接口地址',
                'data' => null
            ], 400);
        }

        return $this->proxyGet($url, $timeout, 'random_ip');
    }

    private function getConfigValue(string $key)
    {
        $raw = Plugin::where('code', self::PLUGIN_CODE)->value('config');
        if (!$raw) {
            return null;
        }
        $decoded = json_decode($raw, true);
        return $decoded[$key] ?? null;
    }

    private function proxyGet(string $url, int $timeout, string $cacheKeySuffix)
    {
        $cacheEnabled = (bool) $this->getConfigValue('enable_cache');
        $cacheTtl = (int) ($this->getConfigValue('cache_ttl_seconds') ?? 0);
        $cacheKey = "remote_check_proxy:{$cacheKeySuffix}:" . sha1($url);

        if ($cacheEnabled && $cacheTtl > 0) {
            $cached = Cache::get($cacheKey);
            if ($cached) {
                return response(
                    $cached['body'],
                    $cached['status'],
                    ['Content-Type' => $cached['content_type']]
                );
            }
        }

        try {
            $response = Http::timeout($timeout)->get($url);

            $contentType = $response->header('Content-Type', 'application/json');

            if ($cacheEnabled && $cacheTtl > 0 && $response->successful()) {
                Cache::put($cacheKey, [
                    'body' => $response->body(),
                    'status' => $response->status(),
                    'content_type' => $contentType
                ], $cacheTtl);
            }

            return response(
                $response->body(),
                $response->status(),
                ['Content-Type' => $contentType]
            );
        } catch (\Throwable $e) {
            Log::error('[RemoteCheckProxy] Upstream request failed: ' . $e->getMessage());

            return response()->json([
                'status' => 'fail',
                'message' => '上游接口请求失败',
                'data' => null,
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 502);
        }
    }
}
