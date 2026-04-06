<?php

namespace Plugin\SubscriptionControl;

use App\Jobs\SendEmailJob;
use App\Jobs\SendTelegramJob;
use App\Services\Plugin\AbstractPlugin;
use App\Services\Plugin\HookManager;
use App\Services\Plugin\InterceptResponseException;
use App\Services\UserOnlineService;
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

        // 在用户侧获取订阅信息时，附带风控提示（用于前端展示）
        $this->filter('user.subscribe.response', [$this, 'attachSubscribeNotice'], 5);
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
                $this->blockAccess('ua_reset', 'UA 可疑，已重置订阅链接', $user->id, [
                    'action' => 'reset_token_uuid',
                    'client_ip' => $ip,
                    'user_agent' => $userAgent,
                ]);
            }

            // 0.5 在线唯一 IP 数超阈值时，重置 token/uuid 并阻断订阅
            if ($this->getConfig('enable_online_ip_threshold', false)) {
                $onlineIpThreshold = max(1, (int) $this->getConfig('online_ip_threshold', 10));
                $onlineIpCount = app(UserOnlineService::class)->getOnlineCount((int) $user->id);
                if ($onlineIpCount > $onlineIpThreshold) {
                    $this->resetUserTokenAndUuid($user);
                    Log::warning('[SubscriptionControl] 在线唯一IP数超阈值，已重置用户 token/uuid', [
                        'user_id' => $user->id,
                        'online_ip_count' => $onlineIpCount,
                        'threshold' => $onlineIpThreshold,
                        'ip' => $ip,
                        'ua' => $userAgent,
                    ]);
                    $this->blockAccess('online_ip_threshold', '在线唯一IP数超阈值，已重置订阅链接', $user->id, [
                        'action' => 'reset_token_uuid',
                        'online_ip_count' => $onlineIpCount,
                        'threshold' => $onlineIpThreshold,
                        'client_ip' => $ip,
                        'user_agent' => $userAgent,
                    ]);
                }
            }

            // 1. 检查UA黑名单
            if ($this->getConfig('enable_ua_blacklist', false)) {
                if ($this->isBlacklistedUA($userAgentLower)) {
                    $this->blockAccess('ua_blacklist', 'UA 拦截', $user->id, [
                        'client_ip' => $ip,
                        'user_agent' => $userAgent,
                    ]);
                }
            }

            // 2. 检查IP限制（时间窗口内不同IP数量）
            if ($this->getConfig('enable_ip_limit', false)) {
                if (!$this->checkIpLimit($user->id, $ip)) {
                    $this->blockAccess('ip_limit', 'IP 数量超限', $user->id, [
                        'client_ip' => $ip,
                        'user_agent' => $userAgent,
                    ]);
                }
            }

            // 3. 检查访问频率限制
            if ($this->getConfig('enable_rate_limit', false)) {
                if (!$this->checkRateLimit($user->id)) {
                    $this->blockAccess('rate_limit', '访问频率超限', $user->id, [
                        'client_ip' => $ip,
                        'user_agent' => $userAgent,
                    ]);
                }
            }

            // 4. 检查阅后即焚（直接限制订阅链接的使用次数）
            if ($this->getConfig('enable_one_time', false)) {
                if (!$this->checkSubscriptionUsage($user->id, $user->token)) {
                    $this->blockAccess('one_time', '订阅链接使用次数已达上限', $user->id, [
                        'action' => 'reset_token',
                        'client_ip' => $ip,
                        'user_agent' => $userAgent,
                    ]);
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
     * 为前端订阅信息附加风控提示（不改变风控行为，仅用于展示/定位）
     */
    public function attachSubscribeNotice($subscribe)
    {
        try {
            $userId = request()->user()?->id;
        } catch (\Throwable) {
            $userId = null;
        }

        $notice = [
            'features' => [
                'online_ip_threshold' => (bool) $this->getConfig('enable_online_ip_threshold', false),
                'ua_reset_token' => (bool) $this->getConfig('enable_ua_reset_token', false),
                'ua_blacklist' => (bool) $this->getConfig('enable_ua_blacklist', false),
                'ip_limit' => (bool) $this->getConfig('enable_ip_limit', false),
                'rate_limit' => (bool) $this->getConfig('enable_rate_limit', false),
                'one_time' => (bool) $this->getConfig('enable_one_time', false),
            ],
            'limits' => [
                'online_ip_threshold' => (int) $this->getConfig('online_ip_threshold', 10),
                'ip_limit_count' => (int) $this->getConfig('ip_limit_count', 3),
                'ip_limit_window' => (int) $this->getConfig('ip_limit_window', 600),
                'rate_limit_requests' => (int) $this->getConfig('rate_limit_requests', 10),
                'rate_limit_window' => (int) $this->getConfig('rate_limit_window', 86400),
                'one_time_max_uses' => (int) $this->getConfig('one_time_max_uses', 10),
                'one_time_duration' => (int) $this->getConfig('one_time_duration', 3600),
            ],
        ];

        if ($userId) {
            $notice['last_event'] = Cache::get("subscription_control:last_event:{$userId}");
        }

        if (is_array($subscribe)) {
            $subscribe['subscription_control'] = $notice;
            return $subscribe;
        }

        if (is_object($subscribe) && method_exists($subscribe, 'offsetSet')) {
            $subscribe->offsetSet('subscription_control', $notice);
            return $subscribe;
        }

        return $subscribe;
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
    private function blockAccess(string $code, string $reason, int $userId = null, array $meta = []): never
    {
        // 增加拦截计数
        Cache::increment('subscription_control:blocked_count:' . date('Y-m-d'));

        // 记录最近一次拦截事件，便于用户在面板中定位原因
        if ($userId) {
            $eventTtl = 60 * 60 * 24 * 3;
            Cache::put("subscription_control:last_event:{$userId}", [
                'code' => $code,
                'action' => $meta['action'] ?? 'block',
                'reason' => $reason,
                'at' => time(),
            ], $eventTtl);
        }

        if ($userId) {
            try {
                $user = User::query()->select(['id', 'email', 'telegram_id'])->find($userId);
                if ($user) {
                    $this->sendRiskNotifications($user, $code, $reason, $meta);
                }
            } catch (\Throwable $e) {
                Log::warning('[SubscriptionControl] 风控通知发送失败', [
                    'user_id' => $userId,
                    'code' => $code,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // 返回403错误（部分客户端会展示响应体，帮助用户自查/自救）
        $message = $this->buildBlockMessage($code, $reason, $meta);
        $this->intercept(response($message, 403, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]));
    }

    private function buildBlockMessage(string $code, string $reason, array $meta = []): string
    {
        $action = (string) ($meta['action'] ?? 'block');
        $lines = [
            '订阅请求已被系统拦截（403）。',
        ];

        if (in_array($action, ['reset_token', 'reset_token_uuid'], true)) {
            $lines[] = '订阅链接可能已被重置：旧链接将立即失效，请登录面板复制新链接并重新导入客户端。';
        } else {
            $lines[] = '请检查客户端/网络环境后重试，或登录面板查看提示信息。';
        }

        // 不直接暴露UA关键词等敏感细节，仅给出粗粒度原因，便于定位
        $displayReason = trim((string) $reason);
        if ($displayReason !== '') {
            $lines[] = "原因：{$displayReason}";
        }

        // 追加通用自查项
        $lines[] = '常见触发：浏览器/不支持的客户端访问订阅、频繁刷新订阅、短时间内切换过多IP。';

        return implode("\n", $lines) . "\n";
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

    private function sendRiskNotifications(User $user, string $code, string $reason, array $meta = []): void
    {
        $cooldown = max(60, (int) $this->getConfig('notify_cooldown_seconds', 1800));
        $cooldownKey = "subscription_control:notify_cooldown:{$user->id}:{$code}";

        if (!Cache::add($cooldownKey, time(), $cooldown)) {
            return;
        }

        $subject = $this->buildNotificationSubject();
        $content = $this->buildNotificationContent($code, $reason, $meta);

        if ($this->getConfig('enable_email_notice', true) && !empty($user->email)) {
            SendEmailJob::dispatch([
                'email' => $user->email,
                'subject' => $subject,
                'template_name' => 'notify',
                'template_value' => [
                    'name' => admin_setting('app_name', 'XBoard'),
                    'content' => $content,
                    'url' => admin_setting('app_url'),
                ],
            ]);
            Log::info('[SubscriptionControl] 风控邮件已提交', [
                'user_id' => $user->id,
                'code' => $code,
                'email' => $user->email,
            ]);
        }

        if (
            $this->getConfig('enable_telegram_notice', true)
            && !empty($user->telegram_id)
            && trim((string) admin_setting('telegram_bot_token', '')) !== ''
        ) {
            SendTelegramJob::dispatch((int) $user->telegram_id, $this->buildTelegramMessage($code, $reason, $meta));
            Log::info('[SubscriptionControl] 风控 TG 消息已提交', [
                'user_id' => $user->id,
                'code' => $code,
                'telegram_id' => $user->telegram_id,
            ]);
        }
    }

    private function buildNotificationSubject(): string
    {
        return '[' . admin_setting('app_name', 'XBoard') . '] 订阅风控提醒';
    }

    private function buildNotificationContent(string $code, string $reason, array $meta = []): string
    {
        $lines = [
            '检测到您的订阅触发了风控规则。',
            '时间：' . date('Y-m-d H:i:s'),
            '类型：' . $reason,
            '处理：' . $this->buildActionText($meta),
        ];

        if ($code === 'online_ip_threshold') {
            $threshold = max(1, (int) ($meta['threshold'] ?? 0));
            $onlineIpCount = max(0, (int) ($meta['online_ip_count'] ?? 0));
            if ($onlineIpCount > 0 && $threshold > 0) {
                $lines[] = "当前在线唯一IP数：{$onlineIpCount}（阈值 {$threshold}）";
            }
        }

        if (!empty($meta['client_ip'])) {
            $lines[] = '来源IP：' . (string) $meta['client_ip'];
        }
        if (!empty($meta['user_agent'])) {
            $lines[] = '客户端UA：' . (string) $meta['user_agent'];
        }

        $appUrl = trim((string) admin_setting('app_url', ''));
        if ($appUrl !== '') {
            $lines[] = '面板地址：' . $appUrl;
        }

        return implode("\n", $lines);
    }

    private function buildTelegramMessage(string $code, string $reason, array $meta = []): string
    {
        $lines = [
            '检测到您的订阅触发了风控规则。',
            '类型：' . $reason,
            '处理：' . $this->buildActionText($meta),
        ];

        if ($code === 'online_ip_threshold') {
            $threshold = max(1, (int) ($meta['threshold'] ?? 0));
            $onlineIpCount = max(0, (int) ($meta['online_ip_count'] ?? 0));
            if ($onlineIpCount > 0 && $threshold > 0) {
                $lines[] = "当前在线唯一IP数：{$onlineIpCount}（阈值 {$threshold}）";
            }
        }

        if (!empty($meta['client_ip'])) {
            $lines[] = '来源IP：' . (string) $meta['client_ip'];
        }

        $appUrl = trim((string) admin_setting('app_url', ''));
        if ($appUrl !== '') {
            $lines[] = '面板地址：' . $appUrl;
        }

        return implode("\n", $lines);
    }

    private function buildActionText(array $meta = []): string
    {
        $action = (string) ($meta['action'] ?? 'block');

        return match ($action) {
            'reset_token_uuid' => '系统已重置订阅链接和节点凭据，旧订阅与旧节点配置已失效，请重新获取并导入。',
            'reset_token' => '系统已重置订阅链接，旧订阅链接已失效，请重新获取并导入。',
            default => '本次订阅请求已被拦截，请检查客户端或网络环境后重试。',
        };
    }
}
