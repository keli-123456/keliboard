<?php
namespace App\Http\Controllers\V2\Admin\Server;

use App\Http\Controllers\Controller;
use App\Services\ServerMachine\Cloud\CloudException;
use App\Services\ServerMachine\Cloud\MachineFailoverDnsRegistry;
use App\Services\ServerMachine\Cloud\MachineFailoverDnsSimulation;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;

class MachineFailoverDnsController extends Controller
{
    private function run(Request $request, array $fields, callable $work)
    {
        abort_unless($request->user()?->is_admin && !$request->user()->banned, 403);
        try {
            if (array_diff(array_keys($request->all()), $fields)) throw new CloudException('invalid_dns_simulation');
            return $this->success($work((int) $request->user()->id));
        } catch (LockTimeoutException) { return response()->json(['message' => 'busy'], 409); }
        catch (CloudException $error) { return response()->json(['message' => $error->getMessage()], 422); }
    }
    public function fetch(Request $request, MachineFailoverDnsRegistry $service)
    {
        return $this->run($request, ['service_id', 'service_revision'], function ($admin) use ($request, $service) {
            $v = $request->validate(['service_id' => 'required|integer|min:1', 'service_revision' => 'required|integer|min:1']);
            return $service->overview((int) $v['service_id'], (int) $v['service_revision'], $admin);
        });
    }
    public function save(Request $request, MachineFailoverDnsRegistry $service)
    {
        return $this->run($request, ['id', 'expected_revision', 'service_id', 'service_revision', 'credential_id', 'credential_revision', 'zone', 'zone_name', 'hostname', 'ttl', 'members'],
            fn ($admin) => $service->save($request->all(), $admin));
    }
    public function drop(Request $request, MachineFailoverDnsRegistry $service)
    {
        return $this->run($request, ['id', 'expected_revision', 'service_id', 'service_revision', 'confirmed'], function ($admin) use ($request, $service) {
            $v = $request->validate(['id' => 'required|integer|min:1', 'expected_revision' => 'required|integer|min:1',
                'service_id' => 'required|integer|min:1', 'service_revision' => 'required|integer|min:1', 'confirmed' => 'required|accepted']);
            $service->drop((int) $v['id'], (int) $v['expected_revision'], (int) $v['service_id'], (int) $v['service_revision'], $admin); return true;
        });
    }
    public function simulate(Request $request, MachineFailoverDnsSimulation $service)
    {
        return $this->run($request, ['set_id', 'set_revision', 'service_revision', 'member_id', 'scenario', 'request_id'], function ($admin) use ($request, $service) {
            $v = $request->validate(['set_id' => 'required|integer|min:1', 'set_revision' => 'required|integer|min:1', 'service_revision' => 'required|integer|min:1',
                'member_id' => 'required|integer|min:1', 'scenario' => 'required|string|max:40', 'request_id' => 'required|regex:/^[a-f0-9]{32}$/D']);
            $run = $service->start((int) $v['set_id'], (int) $v['set_revision'], (int) $v['service_revision'], (int) $v['member_id'], $v['scenario'], $v['request_id'], $admin);
            return $service->advance($run['id'], $admin);
        });
    }
    public function report(Request $request, MachineFailoverDnsSimulation $service)
    {
        return $this->run($request, ['id', 'service_id', 'request_id'], function ($admin) use ($request, $service) {
            if ($request->has('id')) {
                if ($request->has('service_id') || $request->has('request_id')) throw new CloudException('invalid_dns_simulation');
                $v = $request->validate(['id' => 'required|uuid']); return $service->report($v['id'], $admin);
            }
            $v = $request->validate(['service_id' => 'required|integer|min:1', 'request_id' => 'required|regex:/^[a-f0-9]{32}$/D']);
            return $service->resolve((int) $v['service_id'], $v['request_id'], $admin);
        });
    }
    public function stop(Request $request, MachineFailoverDnsSimulation $service)
    {
        return $this->run($request, ['id', 'confirmed'], function ($admin) use ($request, $service) {
            $v = $request->validate(['id' => 'required|uuid', 'confirmed' => 'required|accepted']); return $service->stop($v['id'], $admin);
        });
    }
    public function advance(Request $request, MachineFailoverDnsSimulation $service)
    {
        return $this->run($request, ['id'], function ($admin) use ($request, $service) {
            $v = $request->validate(['id' => 'required|uuid']); return $service->advance($v['id'], $admin);
        });
    }
}
