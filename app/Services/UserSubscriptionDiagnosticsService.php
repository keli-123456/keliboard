<?php

namespace App\Services;

use App\Models\Server;
use App\Models\User;
use App\Protocols\General;
use App\Services\Plugin\HookManager;
use App\Services\SubscriptionProxy\WebsiteProxyEndpointService;
use App\Support\Hysteria2Ech;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class UserSubscriptionDiagnosticsService
{
    private const LIMIT = 500;

    public function inspect(User $user, Request $request): array
    {
        $client = app(SubscriptionClientProfileService::class)->resolve($request);
        $protocol = app('protocols.manager')->matchProtocolClassName($client['name'] ?: $client['flag']) ?? General::class;
        $context = app(NotificationSiteContextService::class)->forUser($user, $request);
        $navigation = app(SiteNavigationService::class)->urlForSubscription($user, $request);
        $website = app(WebsiteProxyEndpointService::class)->urlForSubscription($user, $request);
        $columns = ['id', 'name', 'type', 'port', 'protocol_settings', 'show'];
        $hasEnabled = Schema::hasColumn('v2_server', 'enabled');
        if ($hasEnabled) $columns[] = 'enabled';
        $query = Server::query()->whereJsonContains('group_ids', (string) $user->group_id)->orderBy('sort')->orderBy('id');
        $total = (clone $query)->count();
        $servers = $query->limit(self::LIMIT)->get($columns)->toArray();
        foreach ($servers as &$server) {
            if (str_contains((string) $server['port'], '-')) $server['ports'] = $server['port'];
        }
        unset($server);
        $compatible = $protocol::filterServersForPreview($servers, $client['name'], $client['version']);
        $compatibleIds = array_fill_keys(array_column($compatible, 'id'), true);
        $capabilities = app('protocols.capabilities');
        $nodes = [];
        foreach ($servers as $server) {
            $support = $capabilities->supportsClient($client['name'], $client['version'], $server);
            $reason = !($server['show'] ?? false) ? 'hidden'
                : ($hasEnabled && !($server['enabled'] ?? false) ? 'disabled'
                : ($server['type'] === Server::TYPE_HYSTERIA && Hysteria2Ech::waiting((array) $server['protocol_settings']) ? 'ech_pending'
                : (!$support->supported ? 'client_capability'
                : (!isset($compatibleIds[$server['id']]) ? 'exporter_requirement' : 'eligible'))));
            $nodes[] = ['id' => (int) $server['id'], 'name' => $server['name'], 'protocol' => $server['type'],
                'network' => in_array($server['type'], ['hysteria', 'tuic'], true) ? 'quic'
                    : (string) data_get($server, $server['type'] === 'mieru' ? 'protocol_settings.transport' : 'protocol_settings.network', 'tcp'),
                'eligible' => $reason === 'eligible', 'reason' => $reason,
                'minimum_version' => $reason === 'client_capability' ? ($support->matchedRule['min_version'] ?? null) : null];
        }
        $accessReasons = [];
        if ($user->banned) $accessReasons[] = 'banned';
        if ($user->expired_at !== null && $user->expired_at <= time()) $accessReasons[] = 'expired';
        if (!(int) $user->transfer_enable) $accessReasons[] = 'no_traffic';
        $warnings = [];
        if (!$user->plan_id || !$user->group_id) $warnings[] = 'no_plan';
        if ((int) $user->transfer_enable <= (int) $user->u + (int) $user->d) $warnings[] = 'traffic_exhausted';
        $entries = [];
        if ($navigation !== null) $entries[] = ['kind' => 'navigation', 'url' => $navigation];
        if ($website !== null && $website !== $navigation) $entries[] = ['kind' => 'website', 'url' => $website];
        return ['user_id' => (int) $user->id, 'observed_at' => time(), 'read_only' => true,
            'context' => ['source' => $context['brand_source'], 'site_id' => $context['site_id'],
                'site_name' => $context['app_name'], 'agent_user_id' => $context['agent_user_id']],
            'client' => ['name' => $client['name'] ?? 'general', 'version' => $client['version'],
                'format' => class_basename($protocol)],
            'access' => ['allowed' => (bool) app(UserService::class)->isAvailable($user), 'reasons' => $accessReasons, 'warnings' => $warnings],
            'entries' => $entries,
            'headers' => ['profile-web-page-url' => $navigation ?? $website, 'support-url' => $website ?? $navigation],
            'summary' => ['total' => $total, 'inspected' => count($nodes),
                'eligible' => count(array_filter($nodes, fn ($node) => $node['eligible'])), 'limited' => $total > self::LIMIT],
            'nodes' => $nodes, 'credentials' => '[REDACTED]', 'network_tested' => false,
            'plugin_restrictions_evaluated' => false,
            'has_plugin_filters' => HookManager::hasHook('client.subscribe.access')
                || HookManager::hasHook('client.subscribe.servers') || HookManager::hasHook('protocol.servers.filtered')];
    }
}
