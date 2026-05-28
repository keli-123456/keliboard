<?php

namespace Plugin\SubscriptionControl;

use App\Jobs\SendEmailJob;
use App\Jobs\SendTelegramJob;
use App\Services\Plugin\AbstractPlugin;
use App\Services\Plugin\InterceptResponseException;
use App\Services\UserOnlineService;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Plugin\SubscriptionControl\Services\SubscriptionClientIpResolver;
use Plugin\SubscriptionControl\Services\SubscriptionRiskAnalyzer;

class Plugin extends AbstractPlugin
{
    private const RECENT_EVENTS_KEY = 'subscription_control:recent_events';
    private const RECENT_EVENTS_LIMIT = 100;

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

    public function schedule(Schedule $schedule): void
    {
        if (!$this->getConfig('enable_online_ip_threshold', false)) {
            return;
        }

        $schedule
            ->call(function (): void {
                $this->scanOnlineIpThreshold();
            })
            ->name('plugin:subscription_control:online_ip_threshold')
            ->everyMinute()
            ->onOneServer()
            ->withoutOverlapping(10);
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

            $ipInfo = (new SubscriptionClientIpResolver($this->getConfig()))->resolve($request);
            $ip = (string) $ipInfo['client_ip'];
            $ipMeta = $this->clientIpMeta($ipInfo);
            $userAgent = $request->header('User-Agent', '');
            $userAgentLower = strtolower($userAgent);

            $riskDecisionHalted = false;
            $servers = $this->applyRiskDecisions(
                $servers,
                $user,
                (new SubscriptionRiskAnalyzer($this->getConfig()))->inspectSubscriptionPull(
                    (int) $user->id,
                    (string) $user->token,
                    $ip,
                    $userAgent,
                    ['online_ips' => $this->collectOnlineIpsForRisk((int) $user->id)]
                ),
                $ip,
                $userAgent,
                $ipMeta,
                $riskDecisionHalted
            );
            if ($riskDecisionHalted) {
                return $servers;
            }

            // 0. UA 告警并重置凭据（可选）
            if ($this->getConfig('enable_ua_reset_token', false) && $this->isResetUA($userAgentLower)) {
                $this->resetSubscriptionCredentials($user);
                Log::warning('[SubscriptionControl] UA 可疑，已重置用户订阅凭证', [
                    'user_id' => $user->id,
                    'ua' => $userAgent,
                    'ip' => $ip
                ]);
                $this->blockAccess('ua_reset', 'UA 可疑，已重置订阅凭证', $user->id, [
                    'action' => 'reset_token_uuid',
                    'client_ip' => $ip,
                    'user_agent' => $userAgent,
                ] + $ipMeta);
            }

            // 0.5 在线唯一 IP 数超阈值时，重置订阅凭证并阻断订阅
            if ($this->getConfig('enable_online_ip_threshold', false)) {
                $onlineIpThreshold = max(1, (int) $this->getConfig('online_ip_threshold', 10));
                $onlineIpCount = app(UserOnlineService::class)->getOnlineCount((int) $user->id);
                if ($onlineIpCount > $onlineIpThreshold) {
                    $this->handleOnlineIpThreshold(
                        $user,
                        $onlineIpCount,
                        $onlineIpThreshold,
                        [
                            'client_ip' => $ip,
                            'user_agent' => $userAgent,
                            'source' => 'subscribe_request',
                        ] + $ipMeta,
                        true
                    );
                }
            }

            // 1. 检查UA黑名单
            if ($this->getConfig('enable_ua_blacklist', false)) {
                if ($this->isBlacklistedUA($userAgentLower)) {
                    $this->blockAccess('ua_blacklist', 'UA 拦截', $user->id, [
                        'client_ip' => $ip,
                        'user_agent' => $userAgent,
                    ] + $ipMeta);
                }
            }

            // 2. 检查IP限制（时间窗口内不同IP数量）
            if ($this->getConfig('enable_ip_limit', false)) {
                if (!$this->checkIpLimit($user->id, $ip)) {
                    $this->blockAccess('ip_limit', 'IP 数量超限', $user->id, [
                        'client_ip' => $ip,
                        'user_agent' => $userAgent,
                    ] + $ipMeta);
                }
            }

            // 3. 检查访问频率限制
            if ($this->getConfig('enable_rate_limit', false)) {
                if (!$this->checkRateLimit($user->id)) {
                    $this->blockAccess('rate_limit', '访问频率超限', $user->id, [
                        'client_ip' => $ip,
                        'user_agent' => $userAgent,
                    ] + $ipMeta);
                }
            }

            // 4. 检查阅后即焚（直接限制订阅链接的使用次数）
            if ($this->getConfig('enable_one_time', false)) {
                if (!$this->checkSubscriptionUsage($user->id, $user->token)) {
                    $this->blockAccess('one_time', '订阅链接使用次数已达上限', $user->id, [
                        'action' => 'reset_token_uuid',
                        'client_ip' => $ip,
                        'user_agent' => $userAgent,
                    ] + $ipMeta);
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
                'client_ua_whitelist' => (bool) $this->getConfig('enable_client_ua_whitelist', false),
                'leak_guard' => (bool) $this->getConfig('enable_leak_guard', false),
                'multi_ua_detection' => (bool) $this->getConfig('enable_multi_ua_detection', false),
                'multi_region_pull_detection' => (bool) $this->getConfig('enable_multi_region_pull_detection', false),
                'multi_region_online_detection' => (bool) $this->getConfig('enable_multi_region_online_detection', false),
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
                'leak_guard_score_threshold' => (int) $this->getConfig('leak_guard_score_threshold', 80),
                'leak_guard_window_seconds' => (int) $this->getConfig('leak_guard_window_seconds', 600),
                'multi_ua_allowed_count' => (int) $this->getConfig('multi_ua_allowed_count', 2),
                'multi_ua_window_seconds' => (int) $this->getConfig('multi_ua_window_seconds', 600),
                'multi_region_pull_allowed_count' => (int) $this->getConfig('multi_region_pull_allowed_count', 2),
                'multi_region_pull_window_seconds' => (int) $this->getConfig('multi_region_pull_window_seconds', 600),
                'multi_region_online_allowed_count' => (int) $this->getConfig('multi_region_online_allowed_count', 2),
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
            $user = User::find($userId);
            if ($user) {
                $this->resetSubscriptionCredentials($user, $token);
            }
            
            Log::warning('[SubscriptionControl] 订阅使用次数超限，已重置订阅凭证', [
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
     * 重置用户的订阅凭证：订阅 token 与节点 uuid 作为同一个泄露面处理。
     */
    private function resetSubscriptionCredentials(User $user, ?string $oldToken = null): void
    {
        try {
            $oldToken = $oldToken ?: (string) $user->token;

            $user->uuid = Helper::guid(true);
            $user->token = Helper::guid();
            $user->save();

            Cache::forget("subscription_control:usage:{$user->id}:{$oldToken}");

            Log::info('[SubscriptionControl] 用户订阅凭证已重置', [
                'user_id' => $user->id,
                'old_token' => $oldToken,
                'new_token' => $user->token
            ]);
        } catch (\Exception $e) {
            Log::error('[SubscriptionControl] 重置订阅凭证失败: ' . $e->getMessage(), [
                'user_id' => $user->id
            ]);
        }
    }

    /**
     * 获取 UA 重置关键词列表
     */
    private function getUaResetKeywords(): array
    {
        return $this->parseKeywordList($this->getConfig('ua_reset_keywords', ''));
    }

    private function clientIpMeta(array $ipInfo): array
    {
        return [
            'proxy_ip' => $ipInfo['proxy_ip'] ?? null,
            'client_ip_source' => $ipInfo['client_ip_source'] ?? null,
            'trusted_proxy' => (bool) ($ipInfo['trusted_proxy'] ?? false),
            'cf_ray' => $ipInfo['cf_ray'] ?? null,
        ];
    }

    private function collectOnlineIpsForRisk(int $userId): array
    {
        if (!$this->getConfig('enable_multi_region_online_detection', false)) {
            return [];
        }

        try {
            $summary = UserOnlineService::getUserDeviceIps($userId);
            return array_values(array_filter(
                (array) ($summary['ips'] ?? []),
                static fn($ip): bool => is_string($ip) && trim($ip) !== ''
            ));
        } catch (\Throwable $e) {
            Log::warning('[SubscriptionControl] 在线 IP 风险信息读取失败', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    private function applyRiskDecisions(
        array $servers,
        User $user,
        array $decisions,
        string $ip,
        string $userAgent,
        array $ipMeta,
        bool &$halt = false
    ): array
    {
        foreach ($decisions as $decision) {
            if (!is_array($decision)) {
                continue;
            }

            $code = (string) ($decision['code'] ?? 'subscription_risk');
            $reason = (string) ($decision['reason'] ?? '订阅访问行为异常');
            $action = (string) ($decision['action'] ?? 'observe');
            $meta = array_merge($ipMeta, (array) ($decision['meta'] ?? []), [
                'action' => $action,
                'client_ip' => $ip,
                'user_agent' => $userAgent,
            ]);

            if ($action === 'observe') {
                $this->recordRiskEvent($code, $reason, (int) $user->id, $user, $meta);
                continue;
            }

            if ($action === 'empty') {
                $this->recordRiskEvent($code, $reason, (int) $user->id, $user, $meta);
                $halt = true;
                return [];
            }

            if (in_array($action, ['reset_token', 'reset_token_uuid'], true)) {
                $meta['action'] = 'reset_token_uuid';
                $this->resetSubscriptionCredentials($user);
                $this->interceptRiskDecision($code, $reason, 403, $user, $meta);
            }

            if ($action === 'throttle') {
                $this->interceptRiskDecision($code, $reason, 429, $user, $meta);
            }

            $this->interceptRiskDecision($code, $reason, 403, $user, $meta);
        }

        return $servers;
    }

    public function scanOnlineIpThreshold(): void
    {
        if (!$this->getConfig('enable_online_ip_threshold', false)) {
            return;
        }

        $threshold = max(1, (int) $this->getConfig('online_ip_threshold', 10));
        $exceededUsers = app(UserOnlineService::class)->getUsersExceedingOnlineIpThreshold($threshold, 1000);
        if (empty($exceededUsers)) {
            return;
        }

        $users = User::query()
            ->select(['id', 'email', 'telegram_id'])
            ->whereIn('id', array_keys($exceededUsers))
            ->get()
            ->keyBy('id');

        foreach ($exceededUsers as $userId => $onlineIpCount) {
            $user = $users->get((int) $userId);
            if (!$user) {
                continue;
            }

            $this->handleOnlineIpThreshold(
                $user,
                (int) $onlineIpCount,
                $threshold,
                ['source' => 'scheduled_scan'],
                false
            );
        }
    }

    private function handleOnlineIpThreshold(User $user, int $onlineIpCount, int $threshold, array $meta = [], bool $intercept = true): void
    {
        $reason = '在线唯一IP数超阈值，已重置订阅凭证';
        $cooldownKey = "subscription_control:action_cooldown:{$user->id}:online_ip_threshold";
        $cooldown = max(60, (int) $this->getConfig('notify_cooldown_seconds', 1800));
        $baseMeta = array_merge($meta, [
            'action' => 'reset_token_uuid',
            'online_ip_count' => $onlineIpCount,
            'threshold' => $threshold,
        ]);

        if (!Cache::add($cooldownKey, time(), $cooldown)) {
            Log::info('[SubscriptionControl] 在线唯一IP数超阈值命中冷却窗口，跳过重复处理', [
                'user_id' => $user->id,
                'online_ip_count' => $onlineIpCount,
                'threshold' => $threshold,
                'source' => $meta['source'] ?? 'unknown',
            ]);

            if ($intercept) {
                $this->intercept(response($this->buildBlockMessage('online_ip_threshold', $reason, $baseMeta), 403, [
                    'Content-Type' => 'text/plain; charset=UTF-8',
                ]));
            }

            return;
        }

        $this->resetSubscriptionCredentials($user);
        Log::warning('[SubscriptionControl] 在线唯一IP数超阈值，已重置用户订阅凭证', [
            'user_id' => $user->id,
            'online_ip_count' => $onlineIpCount,
            'threshold' => $threshold,
            'source' => $meta['source'] ?? 'unknown',
            'ip' => $meta['client_ip'] ?? null,
            'ua' => $meta['user_agent'] ?? null,
        ]);

        $this->recordRiskEvent('online_ip_threshold', $reason, $user->id, $user, $baseMeta);

        if ($intercept) {
            $this->intercept(response($this->buildBlockMessage('online_ip_threshold', $reason, $baseMeta), 403, [
                'Content-Type' => 'text/plain; charset=UTF-8',
            ]));
        }
    }

    /**
     * 阻止访问
     */
    private function blockAccess(string $code, string $reason, int $userId = null, array $meta = []): never
    {
        $this->recordRiskEvent($code, $reason, $userId, null, $meta);

        // 返回403错误（部分客户端会展示响应体，帮助用户自查/自救）
        $message = $this->buildBlockMessage($code, $reason, $meta);
        $this->intercept(response($message, 403, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]));
    }

    private function interceptRiskDecision(string $code, string $reason, int $status, User $user, array $meta = []): never
    {
        $meta['http_status'] = $status;
        $this->recordRiskEvent($code, $reason, (int) $user->id, $user, $meta);
        $this->intercept(response($this->buildBlockMessage($code, $reason, $meta), $status, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]));
    }

    private function recordRiskEvent(string $code, string $reason, int $userId = null, ?User $user = null, array $meta = []): void
    {
        $action = (string) ($meta['action'] ?? 'block');
        if ($action !== 'observe') {
            Cache::increment('subscription_control:blocked_count:' . date('Y-m-d'));
        }

        // 记录最近一次拦截事件，便于用户在面板中定位原因
        if ($userId) {
            $eventTtl = 60 * 60 * 24 * 3;
            Cache::put("subscription_control:last_event:{$userId}", [
                'code' => $code,
                'action' => $action,
                'reason' => $reason,
                'at' => time(),
                'online_ip_count' => isset($meta['online_ip_count']) ? (int) $meta['online_ip_count'] : null,
                'risk_score' => isset($meta['risk_score']) ? (int) $meta['risk_score'] : null,
                'signals' => $meta['signals'] ?? null,
                'threshold' => isset($meta['threshold']) ? (int) $meta['threshold'] : null,
            ], $eventTtl);
            try {
                if (!$user) {
                    $user = User::query()->select(['id', 'email', 'telegram_id'])->find($userId);
                }
            } catch (\Throwable $e) {
                Log::warning('[SubscriptionControl] 风控用户信息读取失败', [
                    'user_id' => $userId,
                    'code' => $code,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $notificationResult = [
            'cooldown_hit' => false,
            'email_sent' => false,
            'telegram_sent' => false,
        ];

        if ($user && $action !== 'observe') {
            try {
                $notificationResult = $this->sendRiskNotifications($user, $code, $reason, $meta);
            } catch (\Throwable $e) {
                Log::warning('[SubscriptionControl] 风控通知发送失败', [
                    'user_id' => $userId,
                    'code' => $code,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->appendRecentEvent($userId, $user?->email, $code, $reason, $meta, $notificationResult);
    }

    private function buildBlockMessage(string $code, string $reason, array $meta = []): string
    {
        $action = (string) ($meta['action'] ?? 'block');
        $status = (int) ($meta['http_status'] ?? 403);
        $lines = [
            "订阅请求已被系统限制（{$status}）。",
        ];

        if (in_array($action, ['reset_token', 'reset_token_uuid'], true)) {
            $lines[] = '订阅凭证可能已被重置：旧订阅链接和旧节点配置将立即失效，请登录面板复制新链接并重新导入客户端。';
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

    private function sendRiskNotifications(User $user, string $code, string $reason, array $meta = []): array
    {
        $result = [
            'cooldown_hit' => false,
            'email_sent' => false,
            'telegram_sent' => false,
        ];

        $cooldown = max(60, (int) $this->getConfig('notify_cooldown_seconds', 1800));
        $cooldownKey = "subscription_control:notify_cooldown:{$user->id}:{$code}";

        if (!Cache::add($cooldownKey, time(), $cooldown)) {
            $result['cooldown_hit'] = true;
            return $result;
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
            $result['email_sent'] = true;
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
            $result['telegram_sent'] = true;
        }

        return $result;
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

        if ($code === 'subscription_leak_guard') {
            $score = max(0, (int) ($meta['risk_score'] ?? 0));
            $threshold = max(1, (int) ($meta['score_threshold'] ?? $meta['threshold'] ?? 0));
            if ($score > 0 && $threshold > 0) {
                $lines[] = "风险分：{$score}（阈值 {$threshold}）";
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

        if ($code === 'subscription_leak_guard') {
            $score = max(0, (int) ($meta['risk_score'] ?? 0));
            $threshold = max(1, (int) ($meta['score_threshold'] ?? $meta['threshold'] ?? 0));
            if ($score > 0 && $threshold > 0) {
                $lines[] = "风险分：{$score}（阈值 {$threshold}）";
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
            'reset_token' => '系统已重置订阅链接和节点凭据，旧订阅与旧节点配置已失效，请重新获取并导入。',
            default => '本次订阅请求已被拦截，请检查客户端或网络环境后重试。',
        };
    }

    private function appendRecentEvent(?int $userId, ?string $email, string $code, string $reason, array $meta = [], array $notificationResult = []): void
    {
        $events = Cache::get(self::RECENT_EVENTS_KEY, []);
        if (!is_array($events)) {
            $events = [];
        }

        array_unshift($events, [
            'id' => uniqid('sc_', true),
            'user_id' => $userId,
            'email' => $email,
            'code' => $code,
            'reason' => $reason,
            'action' => (string) ($meta['action'] ?? 'block'),
            'client_ip' => $meta['client_ip'] ?? null,
            'proxy_ip' => $meta['proxy_ip'] ?? null,
            'client_ip_source' => $meta['client_ip_source'] ?? null,
            'trusted_proxy' => isset($meta['trusted_proxy']) ? (bool) $meta['trusted_proxy'] : null,
            'cf_ray' => $meta['cf_ray'] ?? null,
            'user_agent' => $meta['user_agent'] ?? null,
            'ua_category' => $meta['ua_category'] ?? null,
            'ua_categories' => $meta['ua_categories'] ?? null,
            'region' => $meta['region'] ?? null,
            'regions' => $meta['regions'] ?? null,
            'online_regions' => $meta['online_regions'] ?? null,
            'online_ip_count' => isset($meta['online_ip_count']) ? (int) $meta['online_ip_count'] : null,
            'ip_count' => isset($meta['ip_count']) ? (int) $meta['ip_count'] : null,
            'risk_score' => isset($meta['risk_score']) ? (int) $meta['risk_score'] : null,
            'score_threshold' => isset($meta['score_threshold']) ? (int) $meta['score_threshold'] : null,
            'signals' => $meta['signals'] ?? null,
            'threshold' => isset($meta['threshold']) ? (int) $meta['threshold'] : null,
            'cooldown_hit' => (bool) ($notificationResult['cooldown_hit'] ?? false),
            'email_sent' => (bool) ($notificationResult['email_sent'] ?? false),
            'telegram_sent' => (bool) ($notificationResult['telegram_sent'] ?? false),
            'created_at' => time(),
        ]);

        if (count($events) > self::RECENT_EVENTS_LIMIT) {
            $events = array_slice($events, 0, self::RECENT_EVENTS_LIMIT);
        }

        Cache::put(self::RECENT_EVENTS_KEY, $events, 60 * 60 * 24 * 14);
    }
}
