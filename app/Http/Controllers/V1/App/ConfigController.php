<?php

namespace App\Http\Controllers\V1\App;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Protocols\SingBox;
use App\Services\ServerService;
use App\Services\UserService;
use Illuminate\Http\Request;

class ConfigController extends Controller
{
    private const DEFAULT_SING_BOX_CORE_VERSION = '1.13.11';

    public function config(Request $request)
    {
        $validated = $request->validate([
            'core' => ['nullable', 'string', 'in:sing-box'],
            'platform' => ['nullable', 'string', 'in:android,windows,macos'],
            'server_id' => ['nullable', 'integer', 'min:1'],
            'core_version' => ['nullable', 'string', 'max:32'],
        ]);

        $user = User::find($request->user()->id);
        if (!$user) {
            return $this->fail([400, __('The user does not exist')]);
        }

        $userService = new UserService();
        if (!$userService->isAvailable($user)) {
            return $this->fail([403, '账号不可用']);
        }

        $servers = ServerService::getAvailableServers($user);
        $serverId = isset($validated['server_id']) ? (int) $validated['server_id'] : null;
        $targetServers = $serverId === null
            ? collect($servers)->values()
            : collect($servers)->filter(fn ($server) => (int) ($server['id'] ?? 0) === $serverId)->values();

        if ($targetServers->isEmpty()) {
            return $this->fail([404, '节点不存在或不可用']);
        }

        $clientVersion = $validated['core_version'] ?? self::DEFAULT_SING_BOX_CORE_VERSION;
        $platform = $validated['platform'] ?? null;
        $defaultOutboundTag = (string) ($targetServers->first()['name'] ?? '');

        /** @var SingBox $protocol */
        $protocol = app()->make(SingBox::class, [
            'user' => $user,
            'servers' => $targetServers->all(),
            'clientName' => 'sing-box',
            'clientVersion' => $clientVersion
        ]);

        $config = $protocol->generateConfig(
            defaultOutboundTag: $defaultOutboundTag,
            platform: $platform
        );

        $missingTags = $this->missingOutboundTags(
            $config,
            $targetServers
                ->pluck('name')
                ->map(fn ($name) => (string) $name)
                ->filter()
                ->values()
                ->all()
        );
        if ($missingTags !== []) {
            return $this->fail([
                500001,
                '生成 sing-box 配置失败：节点出站未生成，请检查 core_version 和协议兼容配置：' . implode(', ', $missingTags)
            ]);
        }

        return $this->success($config);
    }

    /**
     * @param array<int, string> $tags
     * @return array<int, string>
     */
    private function missingOutboundTags(array $config, array $tags): array
    {
        $outboundTags = [];
        foreach (($config['outbounds'] ?? []) as $outbound) {
            if (is_array($outbound) && is_string($outbound['tag'] ?? null) && $outbound['tag'] !== '') {
                $outboundTags[$outbound['tag']] = true;
            }
        }

        return array_values(array_filter(
            $tags,
            fn (string $tag) => !isset($outboundTags[$tag])
        ));
    }
}
