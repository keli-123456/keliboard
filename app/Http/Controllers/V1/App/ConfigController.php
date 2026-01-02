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
    public function config(Request $request)
    {
        $validated = $request->validate([
            'core' => ['nullable', 'string', 'in:sing-box'],
            'platform' => ['nullable', 'string', 'in:android,windows,macos'],
            'server_id' => ['required', 'integer', 'min:1'],
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
        $serverId = (int) $validated['server_id'];
        $selectedServer = collect($servers)->firstWhere('id', $serverId);

        if (!$selectedServer) {
            return $this->fail([404, '节点不存在或不可用']);
        }

        $clientVersion = $validated['core_version'] ?? null;
        $platform = $validated['platform'] ?? null;

        /** @var SingBox $protocol */
        $protocol = app()->make(SingBox::class, [
            'user' => $user,
            'servers' => [$selectedServer],
            'clientName' => 'sing-box',
            'clientVersion' => $clientVersion
        ]);

        $config = $protocol->generateConfig(
            defaultOutboundTag: (string) $selectedServer['name'],
            platform: $platform
        );

        return $this->success($config);
    }
}
