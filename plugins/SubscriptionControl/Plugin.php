<?php

namespace Plugin\SubscriptionControl;

use App\Services\Plugin\AbstractPlugin;
use App\Services\Plugin\HookManager;
use App\Services\Plugin\InterceptResponseException;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;

class Plugin extends AbstractPlugin
{
    /**
     * 插件启动时调用
     */
    public function boot(): void
    {
        // 使用 filter 钩子，在获取服务器列表前进行风控检查
        $this->filter('client.subscribe.servers', [$this, 'checkSubscribeAccess'], 5);
    }

    /**
     * 检查订阅访问权限
     * 
     * @param array $servers 服务器列表
     * @param User $user 用户对象
     * @param Request $request 请求对象
     * @return array 返回服务器列表
     */
    public function checkSubscribeAccess($servers, $user, $request)
    {
        try {
            if (!$user) {
                return $servers;
            }

            $ip = $this->getClientIp($request);
            $userAgent = $request->header('User-Agent', '');
            $userAgentLower = strtolower($userAgent);

            // 0. UA 告警并重置凭据（可选）
            if ($this->getConfig('enable_ua_reset_token', false) && $this->isResetUA($userAgentLower)) {
                $this->resetUserTokenAndUuid($user);
                Log::warning('[SubscriptionControl] UA 可疑，已重置用户 token/uuid', [
                    'user_id' => $user->id,
                    'ua' => $userAgent,
                    'ip' => $ip
                ]);
                $this->blockAccess('UA可疑，重置凭据', $user->id);
            }

            // 1. 检查UA黑名单
            if ($this->getConfig('enable_ua_blacklist', false)) {
                if ($this->isBlacklistedUA($userAgentLower)) {
                    $this->blockAccess('UA黑名单拦截', $user->id);
                }
            }

            // 2. 检查IP限制（时间窗口内不同IP数量）
            if ($this->getConfig('enable_ip_limit', false)) {
                if (!$this->checkIpLimit($user->id, $ip)) {
                    $this->blockAccess('IP数量超限', $user->id);
                }
            }

            // 3. 检查访问频率限制
            if ($this->getConfig('enable_rate_limit', false)) {
                if (!$this->checkRateLimit($user->id)) {
                    $this->blockAccess('访问频率超限', $user->id);
                }
            }

            // 4. 检查阅后即焚（直接限制订阅链接的使用次数）
            if ($this->getConfig('enable_one_time', false)) {
                if (!$this->checkSubscriptionUsage($user->id, $user->token)) {
                    $this->blockAccess('订阅链接使用次数已达上限', $user->id);
                }
            }

        } catch (InterceptResponseException $e) {
            // 交由全局异常处理器返回 403
            throw $e;
        } catch (\Throwable $e) {
            Log::error('[SubscriptionControl] 检查失败: ' . $e->getMessage());
        }

        // 返回服务器列表（filter钩子必须返回值）
        return $servers;
    }

    /**
     * 检查UA是否在黑名单中
     */
    private function isBlacklistedUA(string $userAgentLower): bool
    {
        $blacklist = $this->parseKeywordList($this->getConfig('ua_blacklist', ''));
        if (empty($blacklist)) {
            return false;
        }

        foreach ($blacklist as $keyword) {
            // 模糊匹配
            if (stripos($userAgentLower, strtolower($keyword)) !== false) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * 检查 UA 是否命中重置规则
     */
    private function isResetUA(string $userAgentLower): bool
    {
        $resetList = $this->getUaResetKeywords();
        if (empty($resetList)) {
            return false;
        }

        foreach ($resetList as $keyword) {
            if (stripos($userAgentLower, strtolower($keyword)) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * 检查IP限制（时间窗口内不同IP数量）
     */
    private function checkIpLimit(int $userId, string $ip): bool
    {
        $limit = $this->getConfig('ip_limit_count', 3);
        $window = $this->getConfig('ip_limit_window', 600);
        
        $cacheKey = "subscription_control:ip_limit:{$userId}";
        $ips = Cache::get($cacheKey, []);
        
        // 清理过期的IP记录
        $now = time();
        $ips = array_filter($ips, function($timestamp) use ($now, $window) {
            return ($now - $timestamp) < $window;
        });
        
        // 检查是否超过限制
        if (!isset($ips[$ip])) {
            if (count($ips) >= $limit) {
                return false;
            }
            $ips[$ip] = $now;
        } else {
            // 更新已存在IP的时间戳
            $ips[$ip] = $now;
        }
        
        Cache::put($cacheKey, $ips, $window);
        return true;
    }

    /**
     * 检查访问频率限制
     */
    private function checkRateLimit(int $userId): bool
    {
        $requests = $this->getConfig('rate_limit_requests', 10);
        $window = $this->getConfig('rate_limit_window', 86400);
        
        $cacheKey = "subscription_control:rate_limit:{$userId}";
        
        // 使用滑动窗口算法
        $timestamps = Cache::get($cacheKey, []);
        $now = time();
        
        // 清理过期的时间戳
        $timestamps = array_filter($timestamps, function($ts) use ($now, $window) {
            return ($now - $ts) < $window;
        });
        
        if (count($timestamps) >= $requests) {
            return false;
        }
        
        $timestamps[] = $now;
        Cache::put($cacheKey, $timestamps, $window);
        
        return true;
    }

    /**
     * 检查订阅使用次数（阅后即焚）
     */
    private function checkSubscriptionUsage(int $userId, string $token): bool
    {
        $maxUses = $this->getConfig('one_time_max_uses', 10);
        $duration = $this->getConfig('one_time_duration', 3600);
        
        $cacheKey = "subscription_control:usage:{$userId}:{$token}";
        $data = Cache::get($cacheKey);
        
        if (!$data) {
            // 首次使用，创建记录
            $data = [
                'uses' => 1,
                'created_at' => time(),
                'last_used' => time()
            ];
            Cache::put($cacheKey, $data, $duration);
            
            Log::info('[SubscriptionControl] 订阅首次使用', [
                'user_id' => $userId,
                'uses' => 1,
                'max_uses' => $maxUses
            ]);
            
            return true;
        }
        
        // 检查是否过期
        if ((time() - $data['created_at']) > $duration) {
            // 过期后重置计数
            Cache::forget($cacheKey);
            $data = [
                'uses' => 1,
                'created_at' => time(),
                'last_used' => time()
            ];
            Cache::put($cacheKey, $data, $duration);
            
            Log::info('[SubscriptionControl] 订阅过期重置', [
                'user_id' => $userId
            ]);
            
            return true;
        }
        
        // 检查使用次数
        if ($data['uses'] >= $maxUses) {
            // 达到使用次数上限，重置用户的订阅token
            $this->resetUserToken($userId, $token);
            
            Log::warning('[SubscriptionControl] 订阅使用次数超限，已重置token', [
                'user_id' => $userId,
                'uses' => $data['uses'],
                'max_uses' => $maxUses,
                'old_token' => $token
            ]);
            
            return false;
        }
        
        // 更新使用次数
        $data['uses']++;
        $data['last_used'] = time();
        Cache::put($cacheKey, $data, $duration);
        
        Log::info('[SubscriptionControl] 订阅使用计数', [
            'user_id' => $userId,
            'uses' => $data['uses'],
            'max_uses' => $maxUses
        ]);
        
        return true;
    }

    /**
     * 重置用户的订阅token
     */
    private function resetUserToken(int $userId, string $oldToken): void
    {
        try {
            // 生成新的token
            $newToken = bin2hex(random_bytes(16));
            
            // 更新数据库中的token
            $user = User::find($userId);
            if ($user) {
                $user->token = $newToken;
                $user->save();
                
                // 清理旧token的缓存记录
                Cache::forget("subscription_control:usage:{$userId}:{$oldToken}");
                
                Log::info('[SubscriptionControl] 用户token已重置', [
                    'user_id' => $userId,
                    'old_token' => $oldToken,
                    'new_token' => $newToken
                ]);
            }
        } catch (\Exception $e) {
            Log::error('[SubscriptionControl] 重置token失败: ' . $e->getMessage(), [
                'user_id' => $userId
            ]);
        }
    }

    /**
     * 重置用户 token 和 uuid（用于 UA 风控）
     */
    private function resetUserTokenAndUuid(User $user): void
    {
        $user->uuid = Helper::guid(true);
        $user->token = Helper::guid();
        $user->save();
    }

    /**
     * 获取 UA 重置关键词列表
     */
    private function getUaResetKeywords(): array
    {
        return $this->parseKeywordList($this->getConfig('ua_reset_keywords', ''));
    }

    /**
     * 获取客户端真实IP
     */
    private function getClientIp(Request $request): string
    {
        // 优先获取CF的真实IP
        $cfIp = $request->header('CF-Connecting-IP');
        if ($cfIp) {
            return $cfIp;
        }

        // 获取X-Forwarded-For
        $xForwardedFor = $request->header('X-Forwarded-For');
        if ($xForwardedFor) {
            $ips = explode(',', $xForwardedFor);
            return trim($ips[0]);
        }
        
        // 获取X-Real-IP
        $xRealIp = $request->header('X-Real-IP');
        if ($xRealIp) {
            return $xRealIp;
        }
        
        return $request->ip() ?? '0.0.0.0';
    }

    /**
     * 阻止访问
     */
    private function blockAccess(string $reason, int $userId = null): never
    {
        // 增加拦截计数
        Cache::increment('subscription_control:blocked_count:' . date('Y-m-d'));
        
        // 返回403错误
        $this->intercept(response('', 403, [
            'Content-Type' => 'text/plain'
        ]));
    }

    /**
     * 将配置字符串拆分为关键词数组，支持逗号或换行分隔
     */
    private function parseKeywordList(string $input): array
    {
        if (trim($input) === '') {
            return [];
        }

        $parts = preg_split('/[\r\n，,]+/', $input);
        return array_values(array_filter(array_map('trim', $parts), fn($item) => $item !== ''));
    }
}
