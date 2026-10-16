<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Http\Controllers\Controller;
use App\Models\MachineRollout;
use App\Services\ServerMachine\MachineRolloutService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class MachineRolloutController extends Controller
{
    public function __construct(private MachineRolloutService $rollouts) {}

    public function index(Request $request)
    {
        if (!Schema::hasTable('v2_machine_rollout') || !Schema::hasTable('v2_machine_rollout_target')) return $this->success([]);
        return $this->success(MachineRollout::with('targets')->where('admin_id', $request->user()->id)->orderByDesc('created_at')->limit(10)->get()->map(fn ($row) => $this->rollouts->serialize($row))->all());
    }

    public function create(Request $request)
    {
        $input = $request->validate([
            'release_id' => 'required|integer|min:1', 'client_token' => 'required|uuid',
            'machine_ids' => 'required|array|min:1|max:20', 'machine_ids.*' => 'required|integer|min:1|distinct',
            'observe_minutes' => 'required|integer|min:10|max:120',
        ]);
        if (!Schema::hasTable('v2_machine_rollout_target')) return $this->fail([422, '请先完成灰度升级数据迁移']);
        try {
            return $this->success($this->rollouts->serialize($this->rollouts->create((int) $request->user()->id, $input)));
        } catch (RuntimeException $error) {
            return $this->fail([422, $error->getMessage()]);
        }
    }

    public function action(Request $request)
    {
        if (!Schema::hasTable('v2_machine_rollout_target')) return $this->fail([422, '请先完成灰度升级数据迁移']);
        $input = $request->validate(['id' => 'required|uuid', 'action' => 'required|in:continue,cancel,restore', 'machine_id' => 'required_if:action,restore|integer|min:1']);
        $row = MachineRollout::where('admin_id', $request->user()->id)->whereKey($input['id'])->firstOrFail();
        try {
            if ($input['action'] === 'restore') {
                $this->rollouts->restore($row, (int) $input['machine_id']);
            } elseif ($input['action'] === 'continue') {
                $this->rollouts->continue($row);
            } else {
                $this->rollouts->cancel($row);
            }
            return $this->success($this->rollouts->serialize($row->fresh()));
        } catch (RuntimeException $error) {
            return $this->fail([422, $error->getMessage()]);
        }
    }
}
