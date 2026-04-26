<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\ServerMachineLoadHistory;
use App\Services\NodeRealtime\NodeRealtimePublisher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
                'sort' => array_key_exists('sort', $params) ? (int) $params['sort'] : (int) ($machine->sort ?? 0),
            ]);
            if (!$machine->exists) {
                $machine->token = ServerMachine::generateToken();
            }
            $machine->save();

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
            if (!empty($affected)) {
                app(NodeRealtimePublisher::class)->invalidateConfigForServers($affected, 'admin.server_machine.bound');
            }

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

        $baseURL = rtrim((string) admin_setting('app_url', ''), '/');
        if ($baseURL === '') {
            $baseURL = rtrim($request->getSchemeAndHttpHost(), '/');
        }

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

    private function shellQuote(string $value): string
    {
        return "'" . str_replace("'", "'\"'\"'", $value) . "'";
    }

    private function yamlScalar(string $value): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }
}
