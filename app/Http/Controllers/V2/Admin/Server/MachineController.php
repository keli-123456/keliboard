<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\ServerMachineLoadHistory;
use App\Services\NodeRealtime\NodeRealtimePublisher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MachineController extends Controller
{
    private const NATIVE_NODE_INSTALL_VERSION = 'latest';
    private const MACHINE_ONLINE_WINDOW_SECONDS = 300;

    public function fetch(Request $request)
    {
        $machines = ServerMachine::query()
            ->withCount('servers')
            ->orderBy('sort', 'ASC')
            ->orderByDesc('id')
            ->get();

        $this->appendOnlineStatus($machines);

        return $this->success($machines);
    }

    public function save(Request $request)
    {
        $params = $request->validate([
            'id' => 'nullable|integer',
            'name' => 'required|string|max:128',
            'description' => 'nullable|string|max:255',
            'is_active' => 'nullable|boolean',
            'subproxy_enabled' => 'nullable|boolean',
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

            $machine->fill([
                'name' => $params['name'],
                'description' => array_key_exists('description', $params) ? $params['description'] : $machine->description,
                'is_active' => array_key_exists('is_active', $params) ? (bool) $params['is_active'] : (bool) ($machine->is_active ?? true),
                'subproxy_enabled' => array_key_exists('subproxy_enabled', $params)
                    ? (bool) $params['subproxy_enabled']
                    : (bool) ($machine->subproxy_enabled ?? false),
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
            $machine->forceFill([
                'is_active' => (bool) $params['is_active'],
            ])->save();

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
                ->where('machine_id', (int) $machine->id)
                ->orderBy('sort', 'ASC')
                ->orderBy('id', 'ASC')
                ->get(),
            'available' => Server::query()
                ->where(function ($query) use ($machine) {
                    $query->whereNull('machine_id')
                        ->orWhere('machine_id', (int) $machine->id);
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
                    ->whereIn('id', $nodeIds)
                    ->update(['machine_id' => (int) $machine->id]);
            }
            DB::commit();

            $affected = array_values(array_unique(array_merge($oldIds, $nodeIds)));
            app(NodeRealtimePublisher::class)->invalidateConfigForMachines([(int) $machine->id], 'admin.server_machine.bound', [
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

        $mode = ($params['mode'] ?? 'replace') === 'append' ? 'append' : 'replace';
        $allowTransfer = (bool) ($params['allow_transfer'] ?? false);
        $items = $this->normalizeBatchBindItems($params['items'] ?? []);
        if (empty($items)) {
            return $this->fail([422, '请选择需要关联的机器']);
        }

        $duplicateNodeId = $this->findBatchBindDuplicateNodeId($items);
        if ($duplicateNodeId !== null) {
            return $this->fail([422, '同一个节点不能在一次批量关联中分配给多台机器']);
        }

        $machineIds = array_column($items, 'machine_id');
        if (count($machineIds) !== count(array_unique($machineIds))) {
            return $this->fail([422, '同一台机器不能重复提交']);
        }

        $existingMachineIds = ServerMachine::query()
            ->whereIn('id', $machineIds)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        if (count($existingMachineIds) !== count($machineIds)) {
            return $this->fail([400202, '机器不存在']);
        }

        $requestedNodeIds = $this->collectBatchBindNodeIds($items);
        $nodes = empty($requestedNodeIds)
            ? collect()
            : Server::query()
                ->whereIn('id', $requestedNodeIds)
                ->get(['id', 'machine_id'])
                ->keyBy('id');
        if ($nodes->count() !== count($requestedNodeIds)) {
            return $this->fail([400202, '节点不存在']);
        }

        try {
            DB::beginTransaction();

            $summary = [
                'machines' => count($items),
                'bound' => 0,
                'unbound' => 0,
                'transferred' => 0,
                'skipped' => 0,
            ];
            $results = [];
            $affectedMachineIds = $machineIds;
            $affectedServerIds = [];
            $currentMachineByNode = $nodes
                ->mapWithKeys(fn ($node, $id): array => [(int) $id => $node->machine_id === null ? null : (int) $node->machine_id])
                ->all();
            $oldNodeIdsByMachine = Server::query()
                ->whereIn('machine_id', $machineIds)
                ->get(['id', 'machine_id'])
                ->groupBy(fn ($node): int => (int) $node->machine_id)
                ->map(fn ($rows): array => $rows->pluck('id')->map(fn ($id): int => (int) $id)->all())
                ->all();

            foreach ($items as $item) {
                $machineId = (int) $item['machine_id'];
                $requestedIds = $item['node_ids'];
                $bindableIds = [];
                $skippedIds = [];
                $transferredIds = [];
                $newlyBoundIds = [];

                foreach ($requestedIds as $nodeId) {
                    $currentMachineId = $currentMachineByNode[$nodeId] ?? null;
                    if ($currentMachineId === null || $currentMachineId === $machineId || $allowTransfer) {
                        $bindableIds[] = $nodeId;
                        if ($currentMachineId !== null && $currentMachineId !== $machineId) {
                            $transferredIds[] = $nodeId;
                            $affectedMachineIds[] = $currentMachineId;
                        }
                        if ($currentMachineId !== $machineId) {
                            $newlyBoundIds[] = $nodeId;
                        }
                        continue;
                    }

                    $skippedIds[] = $nodeId;
                }

                $oldNodeIds = $oldNodeIdsByMachine[$machineId] ?? [];
                $unboundIds = [];
                if ($mode === 'replace') {
                    $unboundIds = array_values(array_diff($oldNodeIds, $bindableIds));
                    if (!empty($unboundIds)) {
                        Server::query()
                            ->whereIn('id', $unboundIds)
                            ->update(['machine_id' => null]);
                    }
                }

                if (!empty($bindableIds)) {
                    Server::query()
                        ->whereIn('id', $bindableIds)
                        ->update(['machine_id' => $machineId]);
                }

                $summary['bound'] += count($newlyBoundIds);
                $summary['unbound'] += count($unboundIds);
                $summary['transferred'] += count($transferredIds);
                $summary['skipped'] += count($skippedIds);
                $affectedServerIds = array_merge($affectedServerIds, $requestedIds, $unboundIds);

                $results[] = [
                    'machine_id' => $machineId,
                    'requested_node_ids' => $requestedIds,
                    'bound_node_ids' => $bindableIds,
                    'skipped_node_ids' => $skippedIds,
                    'unbound_node_ids' => $unboundIds,
                    'transferred_node_ids' => $transferredIds,
                ];
            }

            DB::commit();

            $affectedMachineIds = array_values(array_unique(array_filter(array_map('intval', $affectedMachineIds))));
            $affectedServerIds = array_values(array_unique(array_filter(array_map('intval', $affectedServerIds))));
            app(NodeRealtimePublisher::class)->invalidateConfigForMachines($affectedMachineIds, 'admin.server_machine.batch_bound', [
                'server_ids' => $affectedServerIds,
                'mode' => $mode,
                'allow_transfer' => $allowTransfer,
            ]);

            return $this->success([
                'mode' => $mode,
                'allow_transfer' => $allowTransfer,
                'summary' => $summary,
                'items' => $results,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
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
        $nativeConfig = $nativeEnabled ? $this->buildNativeInstallConfig($baseURL, $machine) : null;
        $nativeCommand = $nativeEnabled ? $this->buildNativeInstallCommand($baseURL, $machine) : null;

        $data = [
            'machine_id' => (int) $machine->id,
            'token' => $machine->token,
            'config' => $nativeConfig ?: $legacyConfig,
            'command' => $nativeCommand ?: $legacyCommand,
            'default_agent' => $defaultAgent,
            'native_enabled' => $nativeEnabled,
        ];

        if ($nativeEnabled) {
            $data += [
                'native_config' => $nativeConfig,
                'native_command' => $nativeCommand,
                'native_uninstall_command' => $this->buildNativeUninstallCommand(),
                'native_log_command' => $this->buildNativeLogCommand(),
                'native_version' => self::NATIVE_NODE_INSTALL_VERSION,
                'legacy_config' => $legacyConfig,
                'legacy_command' => $legacyCommand,
            ];
        }

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

        $currentUpgrade = is_array($machine->upgrade_state) ? $machine->upgrade_state : [];
        if (
            !(bool) ($params['force'] ?? false)
            && in_array((string) ($currentUpgrade['status'] ?? ''), ['queued', 'dispatched', 'running'], true)
        ) {
            return $this->fail([409, '该机器已有进行中的升级任务']);
        }

        $targetVersion = trim((string) ($params['target_version'] ?? ''));
        if ($targetVersion === '') {
            $latest = $this->resolveLatestKelinodeVersion(false, $component);
            $targetVersion = (string) ($latest['latest_version'] ?? '');
        }
        if (!$this->isValidKelinodeVersion($targetVersion)) {
            return $this->fail([422, '无法获取有效的目标版本']);
        }

        $machine->forceFill([
            'upgrade_state' => [
                'id' => (string) Str::uuid(),
                'status' => 'queued',
                'component' => $component,
                'target_version' => $targetVersion,
                'requested_at' => now()->timestamp,
                'updated_at' => now()->timestamp,
            ],
        ])->save();

        return $this->success($this->withOnlineStatus($machine->fresh() ?: $machine));
    }

    private function buildInstallCommand(string $baseURL, ServerMachine $machine): string
    {
        $scriptURL = 'https://raw.githubusercontent.com/keli-123456/kelinode/main/script/install.sh';
        return implode(' ', [
            'curl -fsSL',
            $this->shellQuote($scriptURL),
            '-o /tmp/v2node-install.sh',
            '&& bash /tmp/v2node-install.sh',
            '--machine-url',
            $this->shellQuote($baseURL),
            '--machine-id',
            (string) ((int) $machine->id),
            '--machine-token',
            $this->shellQuote((string) $machine->token),
            '--machine-name',
            $this->shellQuote($machine->name ?: ('machine-' . $machine->id)),
        ]);
    }

    private function buildNativeInstallCommand(string $baseURL, ServerMachine $machine): string
    {
        $scriptURL = 'https://raw.githubusercontent.com/keli-123456/kelinode-rs/main/script/install.sh';
        return implode(' ', [
            'curl -fsSL',
            $this->shellQuote($scriptURL),
            '-o /tmp/keli-native-node-install.sh',
            '&& bash /tmp/keli-native-node-install.sh',
            '--machine-url',
            $this->shellQuote($baseURL),
            '--machine-id',
            (string) ((int) $machine->id),
            '--machine-token',
            $this->shellQuote((string) $machine->token),
            '--machine-name',
            $this->shellQuote($machine->name ?: ('machine-' . $machine->id)),
        ]);
    }

    private function buildNativeUninstallCommand(): string
    {
        $scriptURL = 'https://raw.githubusercontent.com/keli-123456/kelinode-rs/main/script/install.sh';
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
        $agent = strtolower(trim((string) admin_setting('server_machine_default_agent', 'kelinode')));
        return in_array($agent, ['kelinode-rs', 'native-node', 'native_node'], true) ? 'kelinode-rs' : 'kelinode';
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
        $cacheKey = 'server_machine:latest_release:' . $component;
        if ($force) {
            Cache::forget($cacheKey);
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
                        if ((bool) data_get($release, 'draft', false)) {
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
