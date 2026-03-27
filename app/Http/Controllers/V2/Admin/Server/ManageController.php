<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ServerSave;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Services\NodeRealtime\NodeRealtimePublisher;
use App\Services\ServerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ManageController extends Controller
{
    private const DEFAULT_PROTOCOL_OPTIONS = [
        'shadowsocks' => [
            'ciphers' => [
                'aes-128-gcm',
                'aes-192-gcm',
                'aes-256-gcm',
                'chacha20-ietf-poly1305',
                '2022-blake3-aes-128-gcm',
                '2022-blake3-aes-256-gcm',
            ],
            'plugins' => ['none', 'obfs', 'v2ray-plugin'],
            'obfs_modes' => ['http', 'tls'],
            'v2ray_modes' => ['websocket', 'quic'],
        ],
        'vless' => [
            'networkOptions' => ['tcp', 'ws', 'grpc', 'kcp', 'httpupgrade', 'xhttp'],
            'flowOptions' => ['none', 'xtls-rprx-vision', 'xtls-rprx-direct', 'xtls-rprx-splice'],
        ],
    ];

    public function getNodes(Request $request)
    {
        $servers = ServerService::getAllServers()->map(function ($item) {
            $item['groups'] = ServerGroup::whereIn('id', $item['group_ids'])->get(['name', 'id']);
            $item['parent'] = $item->parent;
            return $item;
        });
        return $this->success($servers);
    }

    public function getOptions()
    {
        $options = self::DEFAULT_PROTOCOL_OPTIONS;

        Server::query()->get(['type', 'protocol_settings'])->each(function (Server $server) use (&$options) {
            $settings = is_array($server->protocol_settings) ? $server->protocol_settings : [];

            if ($server->type === Server::TYPE_SHADOWSOCKS) {
                $this->pushOption($options['shadowsocks']['ciphers'], data_get($settings, 'cipher'));

                $plugin = $this->normalizeShadowsocksPlugin(
                    data_get($settings, 'plugin'),
                    data_get($settings, 'obfs')
                );
                $this->pushOption($options['shadowsocks']['plugins'], $plugin ?: 'none');

                $pluginOptions = $this->parsePluginOptions(data_get($settings, 'plugin_opts'));
                if ($plugin === 'obfs' || data_get($settings, 'obfs')) {
                    $this->pushOption($options['shadowsocks']['obfs_modes'], $pluginOptions['obfs'] ?? data_get($settings, 'obfs'));
                }
                if ($plugin === 'v2ray-plugin') {
                    $this->pushOption($options['shadowsocks']['v2ray_modes'], $pluginOptions['mode'] ?? null);
                }
            }

            if ($server->type === Server::TYPE_VLESS) {
                $this->pushOption($options['vless']['networkOptions'], data_get($settings, 'network'));
                $this->pushOption($options['vless']['flowOptions'], data_get($settings, 'flow'));
            }
        });

        return $this->success($options);
    }

    public function sort(Request $request)
    {
        ini_set('post_max_size', '1m');
        $params = $request->validate([
            '*.id' => 'numeric',
            '*.order' => 'numeric'
        ]);

        try {
            DB::beginTransaction();
            collect($params)->each(function ($item) {
                if (isset($item['id']) && isset($item['order'])) {
                    Server::where('id', $item['id'])->update(['sort' => $item['order']]);
                }
            });
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e);
            return $this->fail([500, '保存失败']);

        }
        return $this->success(true);
    }

    public function save(ServerSave $request)
    {
        $params = $request->validated();
        if ($request->input('id')) {
            $server = Server::find($request->input('id'));
            if (!$server) {
                return $this->fail([400202, '服务器不存在']);
            }
            try {
                $server->update($params);
                app(NodeRealtimePublisher::class)->invalidateConfigForServers([(int) $server->id], 'admin.server.saved');
                return $this->success(true);
            } catch (\Exception $e) {
                Log::error($e);
                return $this->fail([500, '保存失败']);
            }
        }

        try {
            Server::create($params);
            return $this->success(true);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '创建失败']);
        }


    }

    public function update(Request $request)
    {
        $request->validate([
            'id' => 'required|integer',
            'show' => 'integer',
        ]);

        if (!Server::where('id', $request->id)->update(['show' => $request->show])) {
            return $this->fail([500, '保存失败']);
        }
        return $this->success(true);
    }

    /**
     * 删除
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function drop(Request $request)
    {
        $request->validate([
            'id' => 'required|integer',
        ]);
        $server = Server::find($request->id);
        if (!$server) {
            return $this->fail([400202, '服务器不存在']);
        }
        $serverId = (int) $server->id;
        if ($server->delete() === false) {
            return $this->fail([500, '删除失败']);
        }
        app(NodeRealtimePublisher::class)->invalidateConfigForServers([$serverId], 'admin.server.deleted');
        return $this->success(true);
    }


    /**
     * 复制节点
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function copy(Request $request)
    {
        $server = Server::find($request->input('id'));
        if (!$server) {
            return $this->fail([400202, '服务器不存在']);
        }
        $server->show = 0;
        $server->code = null;
        Server::create($server->toArray());
        return $this->success(true);
    }

    private function pushOption(array &$options, $value): void
    {
        $text = trim((string) $value);
        if ($text === '' || in_array($text, $options, true)) {
            return;
        }
        $options[] = $text;
    }

    private function normalizeShadowsocksPlugin($plugin, $obfs): string
    {
        $text = trim((string) $plugin);
        if ($text === 'obfs-local' || $text === 'simple-obfs') {
            return 'obfs';
        }
        if ($text !== '') {
            return $text;
        }
        return trim((string) $obfs) !== '' ? 'obfs' : '';
    }

    private function parsePluginOptions($raw): array
    {
        $pairs = [];
        foreach (explode(';', (string) $raw) as $segment) {
            $segment = trim($segment);
            if ($segment === '' || strpos($segment, '=') === false) {
                continue;
            }
            [$key, $value] = explode('=', $segment, 2);
            $key = trim($key);
            if ($key === '') {
                continue;
            }
            $pairs[$key] = trim($value);
        }
        return $pairs;
    }
}
