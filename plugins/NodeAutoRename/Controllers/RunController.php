<?php

namespace Plugin\NodeAutoRename\Controllers;

use App\Http\Controllers\PluginController;
use Illuminate\Http\Request;
use Plugin\NodeAutoRename\Services\NodeAutoRenameService;

class RunController extends PluginController
{
    public function __construct()
    {
        $this->setPluginCode('node_auto_rename');
    }

    public function run(Request $request)
    {
        if ($error = $this->beforePluginAction()) {
            return $this->fail($error);
        }

        $params = $request->validate([
            'server_id' => 'nullable|integer|min:1',
            'dry_run' => 'nullable|boolean',
            'force' => 'nullable|boolean',
        ]);

        $result = (new NodeAutoRenameService($this->getConfig()))->sync(
            serverId: isset($params['server_id']) ? (int) $params['server_id'] : null,
            dryRun: (bool) ($params['dry_run'] ?? false),
            force: (bool) ($params['force'] ?? false)
        );

        return $this->success($result);
    }
}
