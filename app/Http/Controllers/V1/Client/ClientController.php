<?php

namespace App\Http\Controllers\V1\Client;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Protocols\General;
use App\Services\Plugin\HookManager;
use App\Services\RiskEventService;
use App\Services\ServerService;
use App\Services\SubscriptionProxy\SubscriptionProxyProbeService;
use App\Services\SubscriptionProxy\WebsiteProxyEndpointService;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    private const DEFAULT_CLIENT_VERSIONS = [
        'sing-box' => '1.12.0',
    ];

    private const CLIENT_CORE_VERSION_OVERRIDES = [
        'karing' => '1.13.0',
    ];

    /**
     * Protocol prefix mapping for server names
     */
    private const PROTOCOL_PREFIXES = [
        'hysteria' => [
            1 => '[Hy]',
            2 => '[Hy2]'
        ],
        'vless' => '[vless]',
        'shadowsocks' => '[ss]',
        'vmess' => '[vmess]',
        'trojan' => '[trojan]',
        'tuic' => '[tuic]',
        'socks' => '[socks]',
        'anytls' => '[anytls]',
        'naive' => '[naive]',
        'mieru' => '[mieru]',
    ];


    public function subscribe(Request $request)
    {
        $probeService = app(SubscriptionProxyProbeService::class);
        if ($probeService->isHealthToken((string) $request->route('token'))) {
            return response($probeService->healthResponseBody(), 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        HookManager::call('client.subscribe.before');
        $request->validate([
            'types' => ['nullable', 'string'],
            'filter' => ['nullable', 'string'],
            'flag' => ['nullable', 'string'],
        ]);

        $user = $request->user();
        HookManager::filter('client.subscribe.access', [], $user, $request);

        $userService = new UserService();

        if (!$userService->isAvailable($user)) {
            HookManager::call('client.subscribe.unavailable');
            return response('', 403, ['Content-Type' => 'text/plain']);
        }

        return $this->doSubscribe($request, $user);
    }

    public function doSubscribe(Request $request, $user, $servers = null)
    {
        if ($servers === null) {
            $servers = ServerService::getAvailableServers($user);
            $servers = HookManager::filter('client.subscribe.servers', $servers, $user, $request);
        }

        $clientInfo = $this->getClientInfo($request);
        $this->recordSubscribeEvent($request, $user, $clientInfo);

        $requestedTypes = $this->parseRequestedTypes($request->input('types'));
        $filterKeywords = $this->parseFilterKeywords($request->input('filter'));

        $protocolClassName = app('protocols.manager')->matchProtocolClassName($clientInfo['name'] ?: $clientInfo['flag'])
            ?? General::class;

        $serversFiltered = $this->filterServers(
            servers: $servers,
            allowedTypes: $requestedTypes,
            filterKeywords: $filterKeywords
        );
        $serversFiltered = app('protocols.capabilities')->filterServersForClient(
            $serversFiltered,
            $clientInfo['name'] ?? null,
            $clientInfo['version'] ?? null
        );

        $this->setSubscribeInfoToServers($serversFiltered, $user, count($servers) - count($serversFiltered));
        $serversFiltered = $this->addPrefixToServerName($serversFiltered);

        // Instantiate the protocol class with filtered servers and client info
        $protocolInstance = app()->make($protocolClassName, [
            'user' => $user,
            'servers' => $serversFiltered,
            'clientName' => $clientInfo['name'] ?? null,
            'clientVersion' => $clientInfo['version'] ?? null
        ]);

        $response = $protocolInstance->handle();
        $websiteUrl = app(WebsiteProxyEndpointService::class)->urlForSubscription($user, $request);
        if ($websiteUrl !== null && is_object($response) && isset($response->headers)) {
            $response->headers->set('profile-web-page-url', $websiteUrl);
            $response->headers->set('support-url', $websiteUrl);
        }

        return $response;
    }

    private function recordSubscribeEvent(Request $request, $user, array $clientInfo): void
    {
        try {
            $network = [
                'remote_addr' => $request->server('REMOTE_ADDR'),
                'x_forwarded_for' => $request->header('X-Forwarded-For'),
                'x_real_ip' => $request->header('X-Real-IP'),
                'forwarded' => $request->header('Forwarded'),
                'cf_connecting_ip' => $request->header('CF-Connecting-IP'),
            ];
            $network = array_filter($network, fn($v) => $v !== null && $v !== '');

            RiskEventService::record('subscribe', [
                'user_id' => $user?->id ?? null,
                'token' => $user?->token ?? null,
                'ip' => $request->getClientIp(),
                'ua' => $request->userAgent(),
                'client_name' => $clientInfo['name'] ?? null,
                'client_version' => $clientInfo['version'] ?? null,
                'route' => (string) ($request->route()?->getName() ?? 'client.subscribe'),
                'status_code' => 200,
                'meta' => [
                    'types' => $request->input('types'),
                    'filter' => $request->input('filter'),
                    'flag' => $request->input('flag'),
                    'network' => $network ?: null,
                ],
            ]);
        } catch (\Throwable $e) {
            // ignore
        }
    }

    /**
     * Parses the input string for requested server types.
     */
    private function parseRequestedTypes(?string $typeInputString): array
    {
        if (blank($typeInputString) || $typeInputString === 'all') {
            return Server::VALID_TYPES;
        }

        $requested = collect(preg_split('/[|,｜]+/', $typeInputString))
            ->map(fn($type) => trim($type))
            ->filter() // Remove empty strings that might result from multiple delimiters
            ->all();

        return array_values(array_intersect($requested, Server::VALID_TYPES));
    }

    /**
     * Parses the input string for filter keywords.
     */
    private function parseFilterKeywords(?string $filterInputString): ?array
    {
        if (blank($filterInputString) || mb_strlen($filterInputString) > 20) {
            return null;
        }

        return collect(preg_split('/[|,｜]+/', $filterInputString))
            ->map(fn($keyword) => trim($keyword))
            ->filter() // Remove empty strings
            ->all();
    }

    /**
     * Filters servers based on allowed types and keywords.
     */
    private function filterServers(array $servers, array $allowedTypes, ?array $filterKeywords): array
    {
        return collect($servers)->filter(function ($server) use ($allowedTypes, $filterKeywords) {
            // Condition 1: Server type must be in the list of allowed types
            if ($allowedTypes && !in_array($server['type'], $allowedTypes)) {
                return false; // Filter out (don't keep)
            }

            // Condition 2: If filterKeywords are provided, at least one keyword must match
            if (!empty($filterKeywords)) { // Check if $filterKeywords is not empty
                $keywordMatch = collect($filterKeywords)->contains(function ($keyword) use ($server) {
                    return stripos($server['name'], $keyword) !== false
                        || in_array($keyword, $server['tags'] ?? []);
                });
                if (!$keywordMatch) {
                    return false; // Filter out if no keywords match
                }
            }
            // Keep the server if its type is allowed AND (no filter keywords OR at least one keyword matched)
            return true;
        })->values()->all();
    }

    private function getClientInfo(Request $request): array
    {
        $flag = strtolower($request->input('flag') ?? $request->header('User-Agent', ''));
        $protocolManager = app('protocols.manager');

        // Prefer matching against the full user-agent/flag string first.
        // This avoids partial token matches like "Mozilla/5.0" being treated as client versions.
        $clientName = $protocolManager->matchClientFlag($flag);
        $clientVersion = $clientName
            ? $protocolManager->extractClientVersion($flag, $clientName)
            : null;

        // Fallback to first-token detection when full-string matching cannot identify the client.
        if (!$clientName && preg_match('/([a-zA-Z0-9\-_]+)[\/\s]+(v?[0-9]+(?:\.[0-9]+)*)/', $flag, $matches)) {
            $potentialName = strtolower($matches[1]);
            $matchedName = $protocolManager->matchClientFlag($potentialName);
            if ($matchedName) {
                $clientName = $matchedName;
                $clientVersion = preg_replace('/^v/', '', $matches[2]) ?: null;
            }
        }

        if ($clientName && !$clientVersion && preg_match('/(?:^|[^0-9])v?(\d+(?:\.\d+)+)(?=$|[^0-9])/', $flag, $matches)) {
            $clientVersion = $matches[1];
        }

        if ($clientName && isset(self::CLIENT_CORE_VERSION_OVERRIDES[$clientName])) {
            $clientVersion = self::CLIENT_CORE_VERSION_OVERRIDES[$clientName];
        }

        if ($clientName && !$clientVersion && isset(self::DEFAULT_CLIENT_VERSIONS[$clientName])) {
            $clientVersion = self::DEFAULT_CLIENT_VERSIONS[$clientName];
        }

        return [
            'flag' => $flag,
            'name' => $clientName,
            'version' => $clientVersion
        ];
    }

    private function setSubscribeInfoToServers(&$servers, $user, $rejectServerCount = 0)
    {
        if (!isset($servers[0]))
            return;
        if ($rejectServerCount > 0) {
            array_unshift($servers, array_merge($servers[0], [
                'name' => "过滤掉{$rejectServerCount}条线路",
            ]));
        }
        if (!(int) admin_setting('show_info_to_server_enable', 0))
            return;
        $useTraffic = $user['u'] + $user['d'];
        $totalTraffic = $user['transfer_enable'];
        $remainingTraffic = Helper::trafficConvert($totalTraffic - $useTraffic);
        $expiredDate = $user['expired_at'] ? date('Y-m-d', $user['expired_at']) : __('长期有效');
        $userService = new UserService();
        $resetDay = $userService->getResetDay($user);
        array_unshift($servers, array_merge($servers[0], [
            'name' => "套餐到期：{$expiredDate}",
        ]));
        if ($resetDay) {
            array_unshift($servers, array_merge($servers[0], [
                'name' => "距离下次重置剩余：{$resetDay} 天",
            ]));
        }
        array_unshift($servers, array_merge($servers[0], [
            'name' => "剩余流量：{$remainingTraffic}",
        ]));
    }

    private function addPrefixToServerName(array $servers): array
    {
        if (!admin_setting('show_protocol_to_server_enable', false)) {
            return $servers;
        }
        return collect($servers)
            ->map(function (array $server): array {
                $server['name'] = $this->getPrefixedServerName($server);
                return $server;
            })
            ->all();
    }

    private function getPrefixedServerName(array $server): string
    {
        $type = $server['type'] ?? '';
        if (!isset(self::PROTOCOL_PREFIXES[$type])) {
            return $server['name'] ?? '';
        }
        $prefix = is_array(self::PROTOCOL_PREFIXES[$type])
            ? self::PROTOCOL_PREFIXES[$type][$server['protocol_settings']['version'] ?? 1] ?? ''
            : self::PROTOCOL_PREFIXES[$type];
        return $prefix . ($server['name'] ?? '');
    }
}
