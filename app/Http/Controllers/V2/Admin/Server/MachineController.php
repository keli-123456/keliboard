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
    public function fetch(Request $request)
    {
        $machines = ServerMachine::query()
            ->withCount('servers')
            ->orderBy('sort', 'ASC')
            ->orderByDesc('id')
            ->get();

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

            return $this->success($machine->fresh());
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

    public function installCommand(Request $request)
    {
        $request->validate(['id' => 'required|integer']);
        $machine = ServerMachine::find((int) $request->input('id'));
        if (!$machine) {
            return $this->fail([400202, '机器不存在']);
        }

        $baseURL = $this->resolveMachineApiBaseURL($request);

        $config = implode(PHP_EOL, [
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

        return $this->success([
            'machine_id' => (int) $machine->id,
            'token' => $machine->token,
            'config' => $config,
            'command' => $this->buildInstallCommand($baseURL, $machine),
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

        return $this->success($machine->fresh());
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
                    ->get('https://api.github.com/repos/keli-123456/' . $repository . '/releases/latest');
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

                $version = trim((string) data_get($response->json(), 'tag_name', ''));
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

    private function isValidKelinodeVersion(string $version): bool
    {
        return (bool) preg_match('/^v?[0-9A-Za-z][0-9A-Za-z._-]{0,63}$/', trim($version));
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
