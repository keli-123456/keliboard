<?php
namespace App\Http\Controllers\V2\Admin\Server;

use App\Http\Controllers\Controller;
use App\Services\ServerMachine\Cloud\CloudException;
use App\Services\ServerMachine\Cloud\MachineInstanceAcceptance;
use App\Services\ServerMachine\Cloud\MachineOwnedDnsService;
use App\Services\ServerMachine\Dns\DnsException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;

class MachineAcceptanceController extends Controller
{
    private function run(Request $request, array $allowed, callable $work)
    {
        abort_unless($request->user()?->is_admin && !$request->user()->banned, 403);
        if (array_diff(array_keys($request->all()), $allowed)) return response()->json(['message' => 'invalid_acceptance_input'], 422);
        try { return $this->success($work()); }
        catch (LockTimeoutException) { return response()->json(['message' => 'busy'], 409); }
        catch (CloudException|DnsException $error) { return response()->json(['message' => $error->getMessage()], 422); }
    }

    public function fetch(Request $request, MachineInstanceAcceptance $service) { return $this->run($request, [], fn () => $service->overview()); }
    public function dns(Request $request, MachineOwnedDnsService $service) { return $this->run($request, [], fn () => $service->overview()); }

    public function enroll(Request $request, MachineInstanceAcceptance $service)
    {
        return $this->run($request, ['policy_id', 'policy_revision', 'task_id', 'release_id', 'ip', 'confirmed'], function () use ($request, $service) {
            $v = $request->validate(['policy_id' => 'required|integer|min:1', 'policy_revision' => 'required|integer|min:1', 'task_id' => 'required|uuid',
                'release_id' => 'required|integer|min:1', 'ip' => 'required|ipv4', 'confirmed' => 'required|accepted']);
            return $service->enroll($v['policy_id'], $v['policy_revision'], $v['task_id'], $v['release_id'], $v['ip'], $request->user()->id);
        });
    }

    public function verify(Request $request, MachineInstanceAcceptance $service)
    {
        return $this->run($request, ['id', 'policy_revision', 'revision', 'confirmed'], function () use ($request, $service) {
            $v = $request->validate(['id' => 'required|integer|min:1', 'policy_revision' => 'required|integer|min:1', 'revision' => 'required|integer|min:1', 'confirmed' => 'required|accepted']);
            return $service->verify($v['id'], $v['policy_revision'], $v['revision'], $request->user()->id);
        });
    }

    public function prepareDns(Request $request, MachineOwnedDnsService $service)
    {
        return $this->run($request, ['id', 'policy_revision', 'revision', 'zone', 'request_id', 'recovery_of', 'confirmed'], function () use ($request, $service) {
            $v = $request->validate(['id' => 'required|integer|min:1', 'policy_revision' => 'required|integer|min:1', 'revision' => 'required|integer|min:1',
                'zone' => 'required|regex:/^[a-f0-9]{32}$/D', 'request_id' => 'required|regex:/^[a-f0-9]{32}$/D', 'recovery_of' => 'nullable|uuid', 'confirmed' => 'required|accepted']);
            return $service->prepare($v['id'], $v['policy_revision'], $v['revision'], $v['zone'], $v['request_id'], $v['recovery_of'] ?? null, $request->user()->id);
        });
    }

    public function executeDns(Request $request, MachineOwnedDnsService $service)
    {
        return $this->run($request, ['id', 'confirmed'], function () use ($request, $service) {
            $v = $request->validate(['id' => 'required|uuid', 'confirmed' => 'required|accepted']);
            return $service->execute($v['id'], $request->user()->id);
        });
    }

    public function cancelDns(Request $request, MachineOwnedDnsService $service)
    {
        return $this->run($request, ['id', 'confirmed'], function () use ($request, $service) {
            $v = $request->validate(['id' => 'required|uuid', 'confirmed' => 'required|accepted']);
            return $service->cancel($v['id'], $request->user()->id);
        });
    }
}
