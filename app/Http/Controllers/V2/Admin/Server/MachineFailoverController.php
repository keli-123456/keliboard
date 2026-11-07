<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Http\Controllers\Controller;
use App\Services\ServerMachine\Cloud\CloudException;
use App\Services\ServerMachine\Cloud\MachineFailoverPolicyService;
use App\Services\ServerMachine\Cloud\MachineFailoverObserver;
use App\Services\ServerMachine\Cloud\MachineReplacementService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;

class MachineFailoverController extends Controller
{
    private function run(Request $request, callable $work)
    {
        abort_unless($request->user()?->is_admin && !$request->user()->banned, 403);
        try { return $this->success($work()); }
        catch (LockTimeoutException) { return response()->json(['message' => 'busy'], 409); }
        catch (CloudException $error) { return response()->json(['message' => $error->getMessage()], 422); }
    }

    public function fetch(Request $request, MachineFailoverPolicyService $service)
    {
        return $this->run($request, fn () => $service->overview());
    }

    public function replacements(Request $request, MachineReplacementService $service)
    {
        return $this->run($request, fn () => $service->overview());
    }

    public function save(Request $request, MachineFailoverPolicyService $service)
    {
        return $this->run($request, fn () => $service->save($request->all(), (int) $request->user()->id));
    }

    public function drop(Request $request, MachineFailoverPolicyService $service)
    {
        return $this->run($request, function () use ($request, $service) {
            $input = $request->validate(['id' => 'required|integer|min:1', 'expected_revision' => 'required|integer|min:1', 'confirmed' => 'required|accepted']);
            $service->drop($input['id'], $input['expected_revision'], (int) $request->user()->id);
            return true;
        });
    }

    public function rehearse(Request $request, MachineFailoverPolicyService $service)
    {
        return $this->run($request, function () use ($request, $service) {
            $input = $request->validate(['id' => 'required|integer|min:1', 'expected_revision' => 'required|integer|min:1',
                'scenario' => 'required|in:current,initial_setup,agent_stale,ipv4_unreachable']);
            return $service->rehearse($input['id'], $input['expected_revision'], $input['scenario']);
        });
    }

    public function history(Request $request, MachineFailoverObserver $observer)
    {
        return $this->run($request, function () use ($request, $observer) {
            $input = $request->validate(['id' => 'required|integer|min:1', 'expected_revision' => 'required|integer|min:1']);
            return $observer->history($input['id'], $input['expected_revision']);
        });
    }
}
