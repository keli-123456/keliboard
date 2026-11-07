<?php
namespace App\Http\Controllers\V2\Admin\Server;

use App\Http\Controllers\Controller;
use App\Services\ServerMachine\Cloud\CloudException;
use App\Services\ServerMachine\Cloud\MachineIsolatedAcceptance;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;

class MachineIsolatedAcceptanceController extends Controller
{
    private function run(Request $request, array $rules, callable $work)
    {
        abort_unless($request->user()?->is_admin && !$request->user()->banned, 403);
        if (array_diff(array_keys($request->all()), array_keys($rules))) return response()->json(['message' => 'invalid_acceptance_input'], 422);
        try { return $this->success($work($request->validate($rules), (int) $request->user()->id)); }
        catch (LockTimeoutException) { return response()->json(['message' => 'busy'], 409); }
        catch (CloudException $error) { return response()->json(['message' => $error->getMessage()], 422); }
    }

    private function selection(): array { return ['policy_id' => 'required|integer|min:1', 'policy_revision' => 'required|integer|min:1']; }

    public function report(Request $request, MachineIsolatedAcceptance $service)
    {
        return $this->run($request, $this->selection() + ['task_id' => 'nullable|uuid', 'request_id' => 'nullable|regex:/^[a-f0-9]{32}$/D'],
            fn ($v, $admin) => $service->report((int) $v['policy_id'], (int) $v['policy_revision'], $v['task_id'] ?? null, $v['request_id'] ?? null, $admin));
    }

    public function preview(Request $request, MachineIsolatedAcceptance $service)
    {
        return $this->run($request, $this->selection() + ['release_id' => 'required|integer|min:1'],
            fn ($v, $admin) => $service->preview((int) $v['policy_id'], (int) $v['policy_revision'], (int) $v['release_id'], $admin));
    }

    public function queue(Request $request, MachineIsolatedAcceptance $service)
    {
        return $this->run($request, $this->selection() + ['release_id' => 'required|integer|min:1', 'request_id' => 'required|regex:/^[a-f0-9]{32}$/D',
            'fingerprint' => 'required|regex:/^[a-f0-9]{64}$/D', 'token' => 'required|regex:/^[a-f0-9]{64}$/D', 'expires_at' => 'required|integer',
            'confirmed' => 'required|accepted', 'isolation_confirmed' => 'required|accepted', 'cost_confirmed' => 'required|accepted'],
            fn ($v, $admin) => $service->queue(array_replace($v, ['policy_id' => (int) $v['policy_id'], 'policy_revision' => (int) $v['policy_revision'],
                'release_id' => (int) $v['release_id'], 'expires_at' => (int) $v['expires_at']]), $admin));
    }
}
