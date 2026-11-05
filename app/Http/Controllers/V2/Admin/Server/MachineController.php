<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\ServerMachineLoadHistory;
use App\Services\NodeRealtime\NodeRealtimePublisher;
use App\Services\ServerMachine\BatchBindingException;
use App\Services\ServerMachine\BatchBindingService;
use App\Services\ServerMachine\MachineReleaseDistributionService;
use App\Services\ServerMachine\MachineTelemetryService;
use App\Services\ServerMachine\MachineInsightService;
use App\Services\ServerMachine\MachineIdentityGuard;
use App\Services\ServerMachine\MachineAddressInventory;
use App\Services\ServerMachine\MachineReportingInstances;
use App\Services\ServerMachine\MachineUpgradeQueue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MachineController extends Controller
{
    private const NATIVE_NODE_INSTALL_VERSION = 'latest';
    private const PANEL_RELEASE_MIN_AGENT_VERSION = '0.1.309';
    private const MACHINE_ONLINE_WINDOW_SECONDS = 300;

    public function fetch(Request $request)
    {
        $machines = ServerMachine::query()
            ->withCount('servers')
            ->orderBy('sort', 'ASC')
            ->orderByDesc('id')
            ->get();

        $this->appendOnlineStatus($machines);
        $websiteHealth = app(\App\Services\SubscriptionProxy\WebsiteProxyEndpointService::class)
            ->adminHealthPayloads($machines->modelKeys());

        $replicaCounts = \App\Services\Node\NodeMachineBinding::replicaCounts();
        $instances = app(MachineReportingInstances::class);
        $overview = $instances->listOverview($machines->modelKeys());
        $instanceSummaries = $overview['reporters'];
        $conflicts = app(MachineIdentityGuard::class)->conflicts($machines->modelKeys());
        $inventory = app(MachineAddressInventory::class);
        $addresses = $inventory->snapshots($machines);
        $cloud = app(\App\Services\ServerMachine\Cloud\MachineCloudService::class)->listSummaries($machines);
        $now = now()->timestamp;
        $freshSince = $now - \App\Services\UserOnlineService::nodeDataExpirySeconds();
        $panelReleases = $this->releaseDistribution()->source() === MachineReleaseDistributionService::SOURCE_PANEL;
        $groupsByMachine = [];
        foreach ($machines as $machine) {
            $machine->setAttribute('servers_count', (int) $machine->servers_count + (int) ($replicaCounts[$machine->id] ?? 0));
            $machine->setAttribute('identity_conflict', $conflicts[$machine->id]);
            $snapshot = app(MachineTelemetryService::class)->snapshot($machine);
            $reporters = $instanceSummaries[$machine->id] ?? [];
            $groups = $inventory->groups($addresses[$machine->id] ?? [], $reporters, $snapshot['load_status'], $conflicts[$machine->id], $now);
            $activeGroups = array_values(array_filter($groups, fn ($group) => $group['last_seen_at'] >= $freshSince));
            $displayGroups = $activeGroups ?: $groups;
            $groupsByMachine[$machine->id] = $displayGroups;
            $machine->setAttribute('bound_nodes', $overview['nodes'][$machine->id] ?? []);
            $machine->setAttribute('node_activity', $overview['activity'][$machine->id] ?? null);
            $machine->setAttribute('search_addresses', array_values(array_unique(array_merge([], ...array_map(
                fn ($group) => array_column($group['ips'], 'ip'), $displayGroups
            )))));
            $active = [];
            foreach ([...$reporters, ...$activeGroups] as $reporter) {
                if ($reporter['last_seen_at'] >= $freshSince) $active[$reporter['installation_id'] ?? 'legacy'] = true;
            }
            $machine->setAttribute('reporting_instances', count($active));
            $machine->setAttribute('address_group', $displayGroups ? array_intersect_key($displayGroups[0], array_flip(['installation_id', 'ips'])) : null);
            $machine->setAttribute('address_count', array_sum(array_map(fn ($row) => count($row['ips']), $displayGroups)));
            $machine->setAttribute('load_status', $snapshot['load_status']);
            $machine->setAttribute('telemetry', $snapshot['telemetry']);
            $machine->setAttribute('entry_probe', $snapshot['entry_probe']);
            if ($machine->is_active && $machine->identity_conflict) $machine->setAttribute('address_probes', $inventory->probes($addresses[$machine->id] ?? [], $now));
            $machine->setAttribute('website_proxy_health', $websiteHealth[$machine->id] ?? []);
            $machine->setAttribute('cloud_instances', $cloud[$machine->id] ?? []);
        }

        $sources = $instances->listSources($groupsByMachine, $instanceSummaries);
        foreach ($machines as $machine) {
            $rows = $sources[$machine->id] ?? [];
            $machine->setAttribute('address_billing', $rows[0]['billing'] ?? null);
            if (isset($rows[0]['runtime'])) $machine->setAttribute('address_runtime', $rows[0]['runtime']);
            if (isset($rows[0]['change'])) $machine->setAttribute('address_change', $rows[0]['change']);
            if ($machine->identity_conflict || $machine->reporting_instances > 1) {
                if ($panelReleases && $machine->is_active && $machine->identity_conflict) {
                    $targets = app(MachineUpgradeQueue::class)->instanceTargets($addresses[$machine->id] ?? [], $now);
                    $eligible = array_column($targets, 'version', 'installation_id');
                    foreach ($rows as &$row) {
                        if (isset($eligible[$row['installation_id'] ?? ''])) {
                            $row['upgrade_version'] = $eligible[$row['installation_id']];
                        }
                    }
                    unset($row);
                }
                $machine->setAttribute('address_sources', $rows);
                if ($machine->is_active) $machine->setAttribute('address_rates', $inventory->rates($addresses[$machine->id] ?? [], $now));
            }
        }

        return $this->success($machines);
    }

    public function telemetry(Request $request)
    {
        $params = $request->validate([
            'ids' => 'required|array|min:1|max:200',
            'ids.*' => 'required|integer|min:1|distinct',
        ]);
        $machines = ServerMachine::query()->whereIn('id', $params['ids'])
            ->get(['id', 'token', 'is_active', 'last_seen_at', 'load_status']);
        $this->appendOnlineStatus($machines);
        $conflicts = app(MachineIdentityGuard::class)->conflicts($machines->modelKeys());
        $inventory = app(MachineAddressInventory::class);
        $addresses = $inventory->snapshots($machines->filter(fn ($machine) => $machine->is_active && $conflicts[$machine->id]));
        $cloud = app(\App\Services\ServerMachine\Cloud\MachineCloudService::class)->listSummaries($machines);
        $now = now()->timestamp;
        return $this->success($machines->map(function ($machine) use ($conflicts, $inventory, $addresses, $cloud, $now) {
            $snapshot = app(MachineTelemetryService::class)->snapshot($machine);
            return array_merge($machine->only([
                'id', 'last_seen_at', 'is_online', 'online_status',
                'last_seen_age_seconds', 'online_threshold_seconds',
            ]), [
                'load_status' => array_intersect_key($snapshot['load_status'], array_flip([
                    'cpu', 'mem', 'swap', 'disk', 'net', 'uptime', 'updated_at',
                ])),
                'telemetry' => $snapshot['telemetry'],
                'entry_probe' => $snapshot['entry_probe'],
                'cloud_instances' => $cloud[$machine->id] ?? [],
                'address_probes' => $inventory->probes($addresses[$machine->id] ?? [], $now),
                ...($conflicts[$machine->id] ? ['address_rates' => $inventory->rates($addresses[$machine->id] ?? [], $now)] : []),
                'identity_conflict' => $conflicts[$machine->id],
            ]);
        })->values());
    }

    public function insight(Request $request)
    {
        $params = $request->validate(['id' => 'required|integer|min:1']);
        $machine = ServerMachine::find((int) $params['id']);
        if (!$machine) {
            return $this->fail([400202, '机器不存在']);
        }
        return $this->success(app(MachineInsightService::class)->snapshot($machine));
    }

    public function save(Request $request)
    {
        $params = $request->validate([
            'id' => 'nullable|integer',
            'name' => 'required|string|max:128',
            'description' => 'nullable|string|max:255',
            'is_active' => 'nullable|boolean',
            'subproxy_enabled' => 'nullable|boolean',
            'webproxy_enabled' => 'nullable|boolean',
            'webproxy_path_prefix' => 'nullable|string|max:255',
            'webproxy_site_domain_id' => 'nullable|integer|min:1|exists:v2_site_domain,id',
            'webproxy_bindings' => 'nullable|array|max:64',
            'webproxy_bindings.*.site_domain_id' => 'nullable|integer|min:1|exists:v2_site_domain,id|distinct',
            'webproxy_bindings.*.https_port' => 'required|integer|min:1|max:65535|distinct',
            'webproxy_bindings.*.path_prefix' => 'nullable|string|max:255',
            'subproxy_https_port' => 'nullable|integer|min:1|max:65535',
            'subproxy_http_port' => 'nullable|integer|min:1|max:65535',
            'subproxy_cert_domain' => 'nullable|string|max:255',
            'sort' => 'nullable|integer',
        ], [
            'name.required' => '机器名称不能为空',
        ]);

        try {
            $machine = !empty($params['id'])
                ? ServerMachine::find((int) $params['id'])
                : new ServerMachine();
            if (!$machine) {
                return $this->fail([400202, '机器不存在']);
            }

            $previousSubproxyRouting = [
                'is_active' => (bool) ($machine->is_active ?? true),
                'enabled' => (bool) ($machine->subproxy_enabled ?? false),
                'https_port' => $this->normalizeNullablePort($machine->subproxy_https_port ?? null),
                'http_port' => $this->normalizeNullablePort($machine->subproxy_http_port ?? null),
                'cert_domain' => $this->normalizeCertificateDomain($machine->subproxy_cert_domain ?? null),
            ];

            $machine->fill([
                'name' => $params['name'],
                'description' => array_key_exists('description', $params) ? $params['description'] : $machine->description,
                'is_active' => array_key_exists('is_active', $params) ? (bool) $params['is_active'] : (bool) ($machine->is_active ?? true),
                'subproxy_enabled' => array_key_exists('subproxy_enabled', $params)
                    ? (bool) $params['subproxy_enabled']
                    : (bool) ($machine->subproxy_enabled ?? false),
                'webproxy_enabled' => array_key_exists('webproxy_enabled', $params)
                    ? (bool) $params['webproxy_enabled']
                    : (bool) ($machine->webproxy_enabled ?? false),
                'webproxy_path_prefix' => null,
                'webproxy_site_domain_id' => null,
                'webproxy_bindings' => null,
                'subproxy_https_port' => array_key_exists('subproxy_https_port', $params)
                    ? $this->normalizeNullablePort($params['subproxy_https_port'])
                    : $this->normalizeNullablePort($machine->subproxy_https_port ?? null),
                'subproxy_http_port' => array_key_exists('subproxy_http_port', $params)
                    ? $this->normalizeNullablePort($params['subproxy_http_port'])
                    : $this->normalizeNullablePort($machine->subproxy_http_port ?? null),
                'subproxy_cert_domain' => array_key_exists('subproxy_cert_domain', $params)
                    ? $this->normalizeCertificateDomain($params['subproxy_cert_domain'])
                    : $this->normalizeCertificateDomain($machine->subproxy_cert_domain ?? null),
                'sort' => array_key_exists('sort', $params) ? (int) $params['sort'] : (int) ($machine->sort ?? 0),
            ]);
            $currentSubproxyRouting = [
                'is_active' => (bool) $machine->is_active,
                'enabled' => (bool) $machine->subproxy_enabled,
                'https_port' => $this->normalizeNullablePort($machine->subproxy_https_port),
                'http_port' => $this->normalizeNullablePort($machine->subproxy_http_port),
                'cert_domain' => $this->normalizeCertificateDomain($machine->subproxy_cert_domain),
            ];
            if ($machine->exists && $previousSubproxyRouting !== $currentSubproxyRouting) {
                $this->clearSubscriptionProxyProbe($machine);
            }
            if (!$machine->exists) {
                $machine->token = ServerMachine::generateToken();
            }
            $machine->save();
            app(NodeRealtimePublisher::class)->invalidateConfig('admin.server_machine.saved', [
                'machine_id' => (int) $machine->id,
            ]);

            return $this->success($this->withOnlineStatus($machine->fresh() ?: $machine));
        } catch (\Throwable $e) {
            Log::error($e);
            return $this->fail([500, '保存失败']);
        }
    }

    public function drop(Request $request)
    {
        $request->validate(['id' => 'required|integer']);
        $machine = ServerMachine::find((int) $request->input('id'));
        if (!$machine) {
            return $this->fail([400202, '机器不存在']);
        }

        if ($machine->servers()->exists()) {
            return $this->fail([400, '该机器已绑定节点，无法删除']);
        }

        return $this->success($machine->delete());
    }

    public function toggleActive(Request $request)
    {
        $params = $request->validate([
            'id' => 'required|integer',
            'is_active' => 'required|boolean',
        ]);
        $machine = ServerMachine::find((int) $params['id']);
        if (!$machine) {
            return $this->fail([400202, '机器不存在']);
        }

        try {
            $activeChanged = (bool) $machine->is_active !== (bool) $params['is_active'];
            $machine->forceFill([
                'is_active' => (bool) $params['is_active'],
            ]);
            if ($activeChanged) {
                $this->clearSubscriptionProxyProbe($machine);
            }
            $machine->save();

            app(NodeRealtimePublisher::class)->invalidateConfigForMachines(
                [(int) $machine->id],
                'admin.server_machine.active_changed',
                [
                    'machine_id' => (int) $machine->id,
                    'is_active' => (bool) $machine->is_active,
                ]
            );

            return $this->success($this->withOnlineStatus($machine->fresh() ?: $machine));
        } catch (\Throwable $e) {
            Log::error($e);
            return $this->fail([500, '保存失败']);
        }
    }

    public function resetToken(Request $request)
    {
        $request->validate(['id' => 'required|integer']);
        $machine = ServerMachine::find((int) $request->input('id'));
        if (!$machine) {
            return $this->fail([400202, '机器不存在']);
        }

        $machine->token = ServerMachine::generateToken();
        $machine->save();

        return $this->success([
            'id' => (int) $machine->id,
            'token' => $machine->token,
        ]);
    }

    public function getToken(Request $request)
    {
        $request->validate(['id' => 'required|integer']);
        $machine = ServerMachine::find((int) $request->input('id'));
        if (!$machine) {
            return $this->fail([400202, '机器不存在']);
        }

        return $this->success([
            'id' => (int) $machine->id,
            'token' => $machine->token,
        ]);
    }

    public function nodes(Request $request)
    {
        $request->validate(['id' => 'required|integer']);
        $machine = ServerMachine::find((int) $request->input('id'));
        if (!$machine) {
            return $this->fail([400202, '机器不存在']);
        }

        return $this->success([
            'bound' => Server::query()
                ->whereIn('id', \App\Services\Node\NodeMachineBinding::query((int) $machine->id)->select('id'))
                ->orderBy('sort', 'ASC')
                ->orderBy('id', 'ASC')
                ->get(),
            'available' => Server::query()
                ->where(function ($query) use ($machine) {
                    $query->whereNull('machine_id')
                        ->orWhereIn('id', \App\Services\Node\NodeMachineBinding::query((int) $machine->id)->select('id'));
                })
                ->orderBy('sort', 'ASC')
                ->orderBy('id', 'ASC')
                ->get(),
        ]);
    }

    public function bindNodes(Request $request)
    {
        $params = $request->validate([
            'id' => 'required|integer',
            'node_ids' => 'nullable|array',
            'node_ids.*' => 'integer',
        ]);
        $machine = ServerMachine::find((int) $params['id']);
        if (!$machine) {
            return $this->fail([400202, '机器不存在']);
        }

        $nodeIds = collect($params['node_ids'] ?? [])
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        try {
            DB::beginTransaction();
            $sharedIds = \App\Services\Node\NodeMachineBinding::available() ? DB::table('v2_server_machine_binding')->where('machine_id', $machine->id)->pluck('server_id')->map(fn ($id) => (int) $id)->all() : [];
            if ($sharedIds) DB::table('v2_server_machine_binding')->where('machine_id', $machine->id)->whereNotIn('server_id', $nodeIds)->delete();
            $primaryIds = array_values(array_diff($nodeIds, $sharedIds));
            $previousOwners = Server::query()->whereIn('id', $primaryIds)->whereNotNull('machine_id')->pluck('machine_id')->all();
            $oldIds = Server::query()
                ->where('machine_id', (int) $machine->id)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            Server::query()
                ->where('machine_id', (int) $machine->id)
                ->whereNotIn('id', $nodeIds ?: [0])
                ->update(['machine_id' => null]);

            if (!empty($nodeIds)) {
                Server::query()
                    ->whereIn('id', $primaryIds)
                    ->update(['machine_id' => (int) $machine->id]);
            }
            DB::commit();

            $affected = array_values(array_unique(array_merge($oldIds, $nodeIds, $sharedIds)));
            $affectedMachines = array_merge([(int) $machine->id], $previousOwners);
            foreach (Server::query()->whereIn('id', $affected)->get() as $affectedNode) {
                $affectedMachines = array_merge($affectedMachines, \App\Services\Node\NodeMachineBinding::machineIds($affectedNode));
            }
            app(NodeRealtimePublisher::class)->invalidateConfigForMachines($affectedMachines, 'admin.server_machine.bound', [
                'server_ids' => $affected,
            ]);

            return $this->success(true);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error($e);
            return $this->fail([500, '保存失败']);
        }
    }

    public function batchBindNodes(Request $request)
    {
        $params = $request->validate([
            'mode' => 'nullable|in:replace,append',
            'allow_transfer' => 'nullable|boolean',
            'items' => 'required|array|min:1|max:100',
            'items.*.machine_id' => 'required|integer',
            'items.*.node_ids' => 'nullable|array',
            'items.*.node_ids.*' => 'integer',
        ]);

        try {
            return $this->success(app(BatchBindingService::class)->bind($params));
        } catch (BatchBindingException $e) {
            return $this->fail([(int) $e->getCode(), $e->getMessage()]);
        } catch (\Throwable $e) {
            Log::error($e);
            return $this->fail([500, '保存失败']);
        }
    }

    public function history(Request $request)
    {
        $request->validate([
            'id' => 'required|integer',
            'limit' => 'nullable|integer|min:1|max:500',
        ]);

        $machine = ServerMachine::find((int) $request->input('id'));
        if (!$machine) {
            return $this->fail([400202, '机器不存在']);
        }

        $limit = max(1, min((int) $request->input('limit', 120), 500));
        $history = ServerMachineLoadHistory::query()
            ->where('machine_id', (int) $machine->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();

        return $this->success($history);
    }

    private function normalizeBatchBindItems(array $items): array
    {
        return collect($items)
            ->map(function ($item): ?array {
                if (!is_array($item) || !isset($item['machine_id'])) {
                    return null;
                }

                $machineId = (int) $item['machine_id'];
                if ($machineId <= 0) {
                    return null;
                }

                $nodeIds = collect($item['node_ids'] ?? [])
                    ->map(fn ($id): int => (int) $id)
                    ->filter(fn (int $id): bool => $id > 0)
                    ->unique()
                    ->values()
                    ->all();

                return [
                    'machine_id' => $machineId,
                    'node_ids' => $nodeIds,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function findBatchBindDuplicateNodeId(array $items): ?int
    {
        $nodeToMachine = [];
        foreach ($items as $item) {
            $machineId = (int) $item['machine_id'];
            foreach ($item['node_ids'] as $nodeId) {
                if (isset($nodeToMachine[$nodeId]) && $nodeToMachine[$nodeId] !== $machineId) {
                    return (int) $nodeId;
                }
                $nodeToMachine[$nodeId] = $machineId;
            }
        }

        return null;
    }

    private function collectBatchBindNodeIds(array $items): array
    {
        return collect($items)
            ->flatMap(fn (array $item): array => $item['node_ids'])
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    public function installCommand(Request $request)
    {
        $request->validate(['id' => 'required|integer']);
        $machine = ServerMachine::find((int) $request->input('id'));
        if (!$machine) {
            return $this->fail([400202, '机器不存在']);
        }

        $baseURL = $this->resolveMachineApiBaseURL($request);
        $defaultAgent = $this->serverMachineDefaultAgent();
        $nativeEnabled = $defaultAgent === 'kelinode-rs';
        $legacyConfig = $this->buildMachineConfig($baseURL, $machine);
        $legacyCommand = $this->buildInstallCommand($baseURL, $machine);
        $nativeConfig = $this->buildNativeInstallConfig($baseURL, $machine);
        $nativeCommand = $this->buildNativeInstallCommand($baseURL, $machine);

        $data = [
            'machine_id' => (int) $machine->id,
            'token' => $machine->token,
            'config' => $nativeEnabled ? $nativeConfig : $legacyConfig,
            'command' => $nativeEnabled ? $nativeCommand : $legacyCommand,
            'default_agent' => $defaultAgent,
            'distribution_source' => $this->releaseDistribution()->source(),
            'native_enabled' => $nativeEnabled,
            'install_commands' => $this->buildInstallCommandOptions($legacyCommand, $nativeCommand, $defaultAgent),
        ];

        $data += [
            'native_config' => $nativeConfig,
            'native_command' => $nativeCommand,
            'native_uninstall_command' => $this->buildNativeUninstallCommand($baseURL),
            'native_log_command' => $this->buildNativeLogCommand(),
            'native_version' => self::NATIVE_NODE_INSTALL_VERSION,
            'legacy_config' => $legacyConfig,
            'legacy_command' => $legacyCommand,
        ];

        return $this->success($data);
    }

    private function buildMachineConfig(string $baseURL, ServerMachine $machine): string
    {
        return implode(PHP_EOL, [
            'machine:',
            '  enabled: true',
            '  continue_on_error: true',
            '  profiles:',
            '    - name: ' . $this->yamlScalar($machine->name ?: ('machine-' . $machine->id)),
            '      url: ' . $this->yamlScalar($baseURL),
            '      token: ' . $this->yamlScalar((string) $machine->token),
            '      machine_id: ' . (int) $machine->id,
            '',
        ]);
    }

    private function buildNativeInstallConfig(string $baseURL, ServerMachine $machine): string
    {
        return implode(PHP_EOL, [
            'kernel:',
            '  type: keli-core-rs',
            '  config_dir: "/etc/kelinode"',
            '',
            'machine:',
            '  enabled: true',
            '  continue_on_error: true',
            '  profiles:',
            '    - name: ' . $this->yamlScalar($machine->name ?: ('machine-' . $machine->id)),
            '      url: ' . $this->yamlScalar($baseURL),
            '      token: ' . $this->yamlScalar((string) $machine->token),
            '      machine_id: ' . (int) $machine->id,
            '      config_dir: "/etc/kelinode"',
            '',
        ]);
    }

    public function versionInfo(Request $request)
    {
        $force = (bool) $request->boolean('force', false);
        $component = $this->normalizeUpgradeComponent($request->input('component', 'node'));
        if ($component === null) {
            return $this->fail([422, '无效的升级组件']);
        }

        return $this->success($this->resolveLatestKelinodeVersion($force, $component));
    }

    public function upgrade(Request $request)
    {
        $params = $request->validate([
            'id' => 'required|integer',
            'target_version' => 'nullable|string|max:64',
            'component' => 'nullable|string|max:64',
            'force' => 'nullable|boolean',
            'installation_id' => 'prohibited',
            'observed_ip' => 'prohibited',
        ]);

        $machine = ServerMachine::find((int) $params['id']);
        if (!$machine) {
            return $this->fail([400202, '机器不存在']);
        }
        if (!$machine->is_active) {
            return $this->fail([422, '机器已停用，不能下发升级任务']);
        }
        $component = $this->normalizeUpgradeComponent($params['component'] ?? 'node');
        if ($component === null) {
            return $this->fail([422, '无效的升级组件']);
        }
        if (!$this->machineSupportsUpgradeComponent($machine, $component)) {
            return $this->fail([422, '该机器当前节点端不支持该组件升级，请先安装 kelinode-rs']);
        }
        if ($component === 'kelinode-rs' && $this->requiresPanelReleaseBootstrap($machine)) {
            return $this->fail([
                422,
                '当前 kelinode-rs 版本过旧，尚不支持面板版本仓库，请先复制最新的一键安装命令完成一次引导升级',
            ]);
        }

        $targetVersion = trim((string) ($params['target_version'] ?? ''));
        if ($targetVersion === '') {
            $latest = $this->resolveLatestKelinodeVersion(false, $component);
            $targetVersion = (string) ($latest['latest_version'] ?? '');
        }
        if (!$this->isValidKelinodeVersion($targetVersion)) {
            return $this->fail([422, '无法获取有效的目标版本']);
        }

        try {
            $machine = app(MachineUpgradeQueue::class)->enqueue((int) $machine->id, $component, $targetVersion, (bool) ($params['force'] ?? false));
        } catch (\RuntimeException $error) {
            return $this->fail([$error->getCode() ?: 409, $error->getMessage()]);
        }

        return $this->success($this->withOnlineStatus($machine->fresh() ?: $machine));
    }

    public function upgradeInstance(Request $request)
    {
        $params = $request->validate([
            'id' => 'required|integer|min:1',
            'target_version' => ['required', 'string', 'max:64', 'regex:/^v?\d+\.\d+\.\d+$/D'],
            'installation_id' => ['required', 'string', 'regex:/^[a-f0-9]{32}$/D'],
            'observed_ip' => 'required|ip',
            'force' => 'prohibited',
        ]);
        try {
            $machine = app(MachineUpgradeQueue::class)->enqueue((int) $params['id'], 'kelinode-rs', $params['target_version'],
                installationId: $params['installation_id'], observedIp: $params['observed_ip']);
        } catch (\RuntimeException $error) {
            return $this->fail([$error->getCode() ?: 409, $error->getMessage()]);
        }
        return $this->success($machine->upgrade_state);
    }

    private function buildInstallCommandOptions(string $legacyCommand, string $nativeCommand, string $defaultAgent): array
    {
        return [
            [
                'agent' => 'kelinode',
                'label' => 'kelinode',
                'command' => $legacyCommand,
                'is_default' => $defaultAgent === 'kelinode',
            ],
            [
                'agent' => 'kelinode-rs',
                'label' => 'kelinode-rs',
                'command' => $nativeCommand,
                'is_default' => $defaultAgent === 'kelinode-rs',
                'version' => self::NATIVE_NODE_INSTALL_VERSION,
            ],
        ];
    }

    private function buildInstallCommand(string $baseURL, ServerMachine $machine): string
    {
        return $this->buildPanelInstallScript($baseURL, $machine, 'kelinode');
    }

    private function buildNativeInstallCommand(string $baseURL, ServerMachine $machine): string
    {
        return $this->buildPanelInstallScript($baseURL, $machine, 'kelinode-rs');
    }

    private function buildPanelInstallScript(string $baseURL, ServerMachine $machine, string $agent): string
    {
        $template = (string) file_get_contents($this->panelInstallScriptTemplatePath());
        $distribution = $this->releaseDistribution();
        $machineName = $machine->name ?: ('machine-' . $machine->id);

        return strtr($template, [
            '{{APT_DEPENDENCY_HELPER}}' => (string) file_get_contents(dirname($this->panelInstallScriptTemplatePath()) . DIRECTORY_SEPARATOR . 'apt-dependencies.sh'),
            '{{AGENT_TYPE}}' => $this->bashString($agent),
            '{{MACHINE_URL}}' => $this->bashString($baseURL),
            '{{MACHINE_ID}}' => $this->bashString((string) ((int) $machine->id)),
            '{{MACHINE_TOKEN}}' => $this->bashString((string) $machine->token),
            '{{MACHINE_NAME}}' => $this->bashString($machineName),
            '{{KELINODE_INSTALL_SCRIPT_URL}}' => $this->bashString('https://raw.githubusercontent.com/keli-123456/kelinode/main/script/install.sh'),
            '{{KELINODE_RS_INSTALL_SCRIPT_URL}}' => $this->bashString($distribution->installScriptUrl($baseURL)),
            '{{KELINODE_RS_RELEASE_BASE_URL}}' => $this->bashString($distribution->releaseBaseUrl($baseURL)),
        ]);
    }

    private function panelInstallScriptTemplatePath(): string
    {
        return dirname(__DIR__, 6) . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'server-machine' . DIRECTORY_SEPARATOR . 'install-script.sh';
    }

    private function bashString(string $value): string
    {
        $escaped = str_replace(
            ["\\", '"', '$', '`', "\r", "\n"],
            ["\\\\", '\\"', '\\$', '\\`', '', ''],
            $value
        );

        return '"' . $escaped . '"';
    }
    private function buildNativeUninstallCommand(string $baseURL): string
    {
        $scriptURL = $this->releaseDistribution()->installScriptUrl($baseURL);
        return implode(' ', [
            'curl -fsSL',
            $this->shellQuote($scriptURL),
            '-o /tmp/keli-native-node-install.sh',
            '&& bash /tmp/keli-native-node-install.sh uninstall',
        ]);
    }

    private function buildNativeLogCommand(): string
    {
        return 'kelinode log';
    }

    private function serverMachineDefaultAgent(): string
    {
        $agent = strtolower(trim((string) admin_setting('server_machine_default_agent', 'kelinode-rs')));
        return in_array($agent, ['kelinode-rs', 'native-node', 'native_node'], true) ? 'kelinode-rs' : 'kelinode';
    }

    private function releaseDistribution(): MachineReleaseDistributionService
    {
        return app(MachineReleaseDistributionService::class);
    }

    private function resolveMachineApiBaseURL(Request $request): string
    {
        $nodeApiBaseURL = rtrim(trim((string) admin_setting('node_api_base_url', '')), '/');
        if ($nodeApiBaseURL !== '') {
            return $nodeApiBaseURL;
        }

        $baseURL = rtrim((string) admin_setting('app_url', ''), '/');
        if ($baseURL !== '') {
            return $baseURL;
        }

        return rtrim($request->getSchemeAndHttpHost(), '/');
    }

    private function resolveLatestKelinodeVersion(bool $force = false, string $component = 'node'): array
    {
        $component = $this->normalizeUpgradeComponent($component) ?? 'node';
        $repository = $this->upgradeComponentRepository($component);
        $distribution = $this->releaseDistribution();
        $source = $distribution->source();
        $cacheKey = 'server_machine:stable_release_v2:' . $source . ':' . $component;
        if ($force) {
            Cache::forget($cacheKey);
        }

        if ($source === MachineReleaseDistributionService::SOURCE_PANEL && $component !== 'node') {
            $checkedAt = now()->timestamp;
            $version = $distribution->latestLocalVersion(
                $distribution->componentFromUpgradeComponent($component),
                MachineReleaseDistributionService::PLATFORM_LINUX_X86_64
            );
            return [
                'latest_version' => $version,
                'checked_at' => $checkedAt,
                'component' => $component,
                'repository' => $repository,
                'source' => 'panel',
                'error' => $version ? null : 'no_local_release',
            ];
        }

        return Cache::remember($cacheKey, now()->addMinutes(10), function () use ($component, $repository): array {
            $checkedAt = now()->timestamp;
            try {
                $response = Http::timeout(5)
                    ->acceptJson()
                    ->get('https://api.github.com/repos/keli-123456/' . $repository . '/releases?per_page=20');
                if (!$response->ok()) {
                    return [
                        'latest_version' => null,
                        'checked_at' => $checkedAt,
                        'component' => $component,
                        'repository' => $repository,
                        'source' => 'github',
                        'error' => 'github_status_' . $response->status(),
                    ];
                }

                $release = collect($response->json())
                    ->first(function ($release): bool {
                        if ((bool) data_get($release, 'draft', false) || (bool) data_get($release, 'prerelease', false)) {
                            return false;
                        }

                        return $this->isValidKelinodeVersion((string) data_get($release, 'tag_name', ''));
                    });
                $version = trim((string) data_get($release, 'tag_name', ''));
                return [
                    'latest_version' => $this->isValidKelinodeVersion($version) ? $version : null,
                    'checked_at' => $checkedAt,
                    'component' => $component,
                    'repository' => $repository,
                    'source' => 'github',
                    'error' => null,
                ];
            } catch (\Throwable $e) {
                Log::warning('Fetch machine component latest release failed', [
                    'component' => $component,
                    'repository' => $repository,
                    'error' => $e->getMessage(),
                ]);
                return [
                    'latest_version' => null,
                    'checked_at' => $checkedAt,
                    'component' => $component,
                    'repository' => $repository,
                    'source' => 'github',
                    'error' => 'request_failed',
                ];
            }
        });
    }

    private function normalizeUpgradeComponent(mixed $value): ?string
    {
        $value = strtolower(trim((string) ($value ?? '')));
        if ($value === '' || in_array($value, ['node', 'v2node', 'kelinode', 'agent'], true)) {
            return 'node';
        }
        if (in_array($value, ['kelinode-rs', 'native-node', 'native_node'], true)) {
            return 'kelinode-rs';
        }
        if (in_array($value, ['core', 'keli-core', 'keli-core-rs'], true)) {
            return 'core';
        }

        return null;
    }

    private function upgradeComponentRepository(string $component): string
    {
        return match ($component) {
            'kelinode-rs' => 'kelinode-rs',
            'core' => 'keli-core-rs',
            default => 'kelinode',
        };
    }

    private function machineSupportsUpgradeComponent(ServerMachine $machine, string $component): bool
    {
        if ($component === 'node') {
            return true;
        }

        $status = is_array($machine->load_status) ? $machine->load_status : [];
        $agent = strtolower(trim((string) data_get($status, 'runtime.agent', '')));
        if (in_array($agent, ['kelinode-rs', 'native-node'], true)) {
            return true;
        }

        $version = strtolower(trim((string) data_get($status, 'version', '')));
        return (bool) preg_match('/^v?0\.1\./', $version);
    }

    private function requiresPanelReleaseBootstrap(ServerMachine $machine): bool
    {
        if ($this->releaseDistribution()->source() !== MachineReleaseDistributionService::SOURCE_PANEL) {
            return false;
        }

        $status = is_array($machine->load_status) ? $machine->load_status : [];
        $version = ltrim(trim((string) data_get($status, 'version', '')), 'vV');
        return $version !== '' && version_compare($version, self::PANEL_RELEASE_MIN_AGENT_VERSION, '<');
    }

    private function isValidKelinodeVersion(string $version): bool
    {
        return (bool) preg_match('/^v?[0-9A-Za-z][0-9A-Za-z._-]{0,63}$/', trim($version));
    }

    private function appendOnlineStatus($machines): void
    {
        foreach ($machines as $machine) {
            if ($machine instanceof ServerMachine) {
                $this->withOnlineStatus($machine);
            }
        }
    }

    private function withOnlineStatus(ServerMachine $machine): ServerMachine
    {
        $lastSeenAt = (int) ($machine->last_seen_at ?? 0);
        $ageSeconds = $lastSeenAt > 0 ? max(0, time() - $lastSeenAt) : null;
        $isOnline = $ageSeconds !== null && $ageSeconds <= self::MACHINE_ONLINE_WINDOW_SECONDS;

        $machine->setAttribute('is_online', $isOnline);
        $machine->setAttribute('online_status', $lastSeenAt <= 0 ? 'never' : ($isOnline ? 'online' : 'offline'));
        $machine->setAttribute('last_seen_age_seconds', $ageSeconds);
        $machine->setAttribute('online_threshold_seconds', self::MACHINE_ONLINE_WINDOW_SECONDS);

        return $machine;
    }

    private function shellQuote(string $value): string
    {
        return "'" . str_replace("'", "'\"'\"'", $value) . "'";
    }

    private function clearSubscriptionProxyProbe(ServerMachine $machine): void
    {
        $state = is_array($machine->subproxy_cert_state) ? $machine->subproxy_cert_state : [];
        unset($state['probe']);
        $machine->subproxy_cert_state = $state;
    }

    private function normalizeNullablePort(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $port = (int) $value;
        return $port >= 1 && $port <= 65535 ? $port : null;
    }

    private function normalizeCertificateDomain(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : $value;
    }

    private function yamlScalar(string $value): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }
}
