<?php

namespace App\Support;

use App\Models\User;
use App\Services\Plugin\HookManager;
use App\Services\NotificationSiteContextService;

abstract class AbstractProtocol
{
    /**
     * @var array 用户信息
     */
    protected $user;

    /**
     * @var array 服务器信息
     */
    protected $servers;

    /**
     * @var string|null 客户端名称
     */
    protected $clientName;

    /**
     * @var string|null 客户端版本
     */
    protected $clientVersion;

    /**
     * @var array 协议标识
     */
    public $flags = [];

    /**
     * @var array 协议需求配置
     */
    protected $protocolRequirements = [];

    /**
     * @var array 允许的协议类型（白名单） 为空则不进行过滤
     */
    protected $allowedProtocols = [];

    /**
     * 构造函数
     *
     * @param array $user 用户信息
     * @param array $servers 服务器信息
     * @param string|null $clientName 客户端名称
     * @param string|null $clientVersion 客户端版本
     */
    public function __construct($user, $servers, $clientName = null, $clientVersion = null)
    {
        $this->user = $user;
        $this->servers = $servers;
        $this->clientName = is_string($clientName) ? strtolower(trim($clientName)) : $clientName;
        $this->clientVersion = $clientVersion;
        $this->protocolRequirements = $this->normalizeProtocolRequirements($this->protocolRequirements);
        $this->servers = HookManager::filter('protocol.servers.filtered', $this->filterServersByVersion());
    }

    /**
     * 获取协议标识
     *
     * @return array
     */
    public function getFlags(): array
    {
        return $this->flags;
    }

    /**
     * 处理请求
     *
     * @return mixed
     */
    abstract public function handle();

    /**
     * 根据客户端版本过滤不兼容的服务器
     *
     * @return array
     */
    protected function filterServersByVersion()
    {
        $this->filterByAllowedProtocols();
        $hasGlobalConfig = isset($this->protocolRequirements['*']);
        $hasClientConfig = isset($this->protocolRequirements[$this->clientName]);

        if (blank($this->clientName) && !$hasGlobalConfig) {
            return $this->servers;
        }

        if (!$hasGlobalConfig && !$hasClientConfig) {
            return $this->servers;
        }

        return collect($this->servers)
            ->filter(fn($server) => $this->isCompatible($server))
            ->values()
            ->all();
    }

    /**
     * 检查服务器是否与当前客户端兼容
     *
     * @param array $server 服务器信息
     * @return bool
     */
    protected function isCompatible($server)
    {
        $serverType = $server['type'] ?? null;
        if (isset($this->protocolRequirements['*'][$serverType])) {
            $globalRequirements = $this->protocolRequirements['*'][$serverType];
            if (!$this->checkRequirements($globalRequirements, $server)) {
                return false;
            }
        }

        if (!isset($this->protocolRequirements[$this->clientName][$serverType])) {
            return true;
        }

        $requirements = $this->protocolRequirements[$this->clientName][$serverType];
        return $this->checkRequirements($requirements, $server);
    }

    /**
     * 检查版本要求
     *
     * @param array $requirements 要求配置
     * @param array $server 服务器信息
     * @return bool
     */
    private function checkRequirements(array $requirements, array $server): bool
    {
        $baseVersion = $requirements['base_version'] ?? null;
        if ($baseVersion !== null) {
            if (blank($this->clientVersion)) {
                return false;
            }
            if (version_compare($this->clientVersion, $baseVersion, '<') && !$this->shouldBypassCoreVersionCheck()) {
                return false;
            }
        }

        foreach ($requirements as $field => $filterRule) {
            if (in_array($field, ['base_version', 'incompatible'], true)) {
                continue;
            }

            $actualValue = data_get($server, $field);

            if (is_array($filterRule) && isset($filterRule['whitelist'])) {
                $allowedValues = $filterRule['whitelist'];
                $strict = $filterRule['strict'] ?? false;
                if ($strict) {
                    if ($actualValue === null) {
                        return false;
                    }
                    if (!is_string($actualValue) && !is_int($actualValue)) {
                        return false;
                    }
                    if (!isset($allowedValues[$actualValue])) {
                        return false;
                    }
                    $requiredVersion = $allowedValues[$actualValue];
                    if ($requiredVersion !== '0.0.0') {
                        if (
                            blank($this->clientVersion)
                            || (version_compare($this->clientVersion, $requiredVersion, '<') && !$this->shouldBypassCoreVersionCheck())
                        ) {
                            return false;
                        }
                    }
                    continue;
                }
            } else {
                $allowedValues = $filterRule;
                $strict = false;
            }

            if ($actualValue === null) {
                continue;
            }
            if (!is_string($actualValue) && !is_int($actualValue)) {
                continue;
            }
            if (!isset($allowedValues[$actualValue])) {
                continue;
            }
            $requiredVersion = $allowedValues[$actualValue];
            if ($requiredVersion !== '0.0.0') {
                if (
                    blank($this->clientVersion)
                    || (version_compare($this->clientVersion, $requiredVersion, '<') && !$this->shouldBypassCoreVersionCheck())
                ) {
                    return false;
                }
            }
        }

        return true;
    }

    protected function shouldBypassCoreVersionCheck(): bool
    {
        if (!is_string($this->clientVersion) || $this->clientVersion === '') {
            return false;
        }

        if (preg_match('/^\d+(?:\.\d+){3,}$/', $this->clientVersion) !== 1) {
            return false;
        }

        $family = app()->bound('protocols.capabilities')
            ? app('protocols.capabilities')->resolveClientFamily($this->clientName)
            : null;

        return $this->clientName === 'sing-box'
            || $family === 'sing-box'
            || in_array($this->clientName, ['sparkle'], true);
    }

    protected function subscriptionAppName(): string
    {
        $name = trim((string) data_get($this->subscriptionBrandingContext(), 'app_name', ''));

        return $name !== '' ? $name : (string) admin_setting('app_name', 'XBoard');
    }

    protected function subscriptionAppUrl(): string
    {
        $url = trim((string) data_get($this->subscriptionBrandingContext(), 'app_url', ''));

        return $url !== '' ? rtrim($url, '/') : rtrim((string) admin_setting('app_url', ''), '/');
    }

    protected function subscriptionBrandingContext(): array
    {
        try {
            $request = request();
            $user = $this->user instanceof User ? $this->user : null;
            if (!$user && is_array($this->user) && !empty($this->user['id'])) {
                $user = User::query()->find((int) $this->user['id']);
            }

            $service = app(NotificationSiteContextService::class);
            if ($user instanceof User) {
                return $service->forUser($user, $request);
            }

            return $service->forRequest($request);
        } catch (\Throwable) {
            return [
                'app_name' => (string) admin_setting('app_name', 'XBoard'),
                'app_url' => rtrim((string) admin_setting('app_url', ''), '/'),
            ];
        }
    }

    /**
     * 检查当前客户端是否支持特定功能
     *
     * @param string $clientName 客户端名称
     * @param string $minVersion 最低版本要求
     * @param array $additionalConditions 额外条件检查
     * @return bool
     */
    protected function supportsFeature(string $clientName, string $minVersion, array $additionalConditions = []): bool
    {
        // 检查客户端名称
        if ($this->clientName !== $clientName) {
            return false;
        }

        // 检查版本号
        if (empty($this->clientVersion) || version_compare($this->clientVersion, $minVersion, '<')) {
            return false;
        }

        // 检查额外条件
        foreach ($additionalConditions as $condition) {
            if (!$condition) {
                return false;
            }
        }

        return true;
    }

    /**
     * 根据白名单过滤服务器
     *
     * @return void
     */
    protected function filterByAllowedProtocols(): void
    {
        if (!empty($this->allowedProtocols)) {
            $this->servers = collect($this->servers)
                ->filter(fn($server) => in_array($server['type'], $this->allowedProtocols))
                ->values()
                ->all();
        }
    }

    protected function buildProxyGroupProxies(array $sources, array $proxies): array
    {
        $hasFilter = false;
        $literalSources = [];
        $matchedProxies = [];

        foreach ($sources as $source) {
            if ($proxies !== [] && $this->isProxyGroupRegex($source)) {
                $hasFilter = true;
                foreach ($proxies as $proxy) {
                    if ($this->proxyGroupRegexMatches($source, $proxy)) {
                        $matchedProxies[] = $proxy;
                    }
                }
                continue;
            }

            $literalSources[] = $source;
        }

        if ($hasFilter) {
            return array_values(array_merge($literalSources, $matchedProxies));
        }

        return array_values(array_merge($sources, $proxies));
    }

    private function isProxyGroupRegex(mixed $pattern): bool
    {
        if (!is_string($pattern) || $pattern === '') {
            return false;
        }

        try {
            return @preg_match($pattern, '') !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    private function proxyGroupRegexMatches(mixed $pattern, mixed $value): bool
    {
        if (!is_string($pattern) || !is_scalar($value)) {
            return false;
        }

        try {
            return @preg_match($pattern, (string) $value) === 1;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 将平铺的协议需求转换为树形结构
     *
     * @param array $flat 平铺的协议需求
     * @return array 树形结构的协议需求
     */
    protected function normalizeProtocolRequirements(array $flat): array
    {
        $result = [];
        foreach ($flat as $key => $value) {
            if (!str_contains($key, '.')) {
                $normalizedKey = $key === '*' ? $key : strtolower($key);
                $result[$normalizedKey] = $value;
                continue;
            }
            $segments = explode('.', $key, 3);
            if (count($segments) < 3) {
                $client = $segments[0] === '*' ? $segments[0] : strtolower($segments[0]);
                $result[$client][$segments[1] ?? '*'][''] = $value;
                continue;
            }
            [$client, $type, $field] = $segments;
            $client = $client === '*' ? $client : strtolower($client);
            $result[$client][$type][$field] = $value;
        }
        return $result;
    }
}
