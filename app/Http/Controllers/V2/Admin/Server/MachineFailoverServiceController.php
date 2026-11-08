<?php
namespace App\Http\Controllers\V2\Admin\Server;

use App\Http\Controllers\Controller;
use App\Services\ServerMachine\Cloud\CloudException;
use App\Services\ServerMachine\Cloud\MachineFailoverServiceRegistry;
use App\Services\ServerMachine\Cloud\MachineFailoverSimulation;
use App\Services\ServerMachine\Cloud\MachineFailoverShadow;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;

class MachineFailoverServiceController extends Controller
{
    private function run(Request $request, callable $work)
    {
        abort_unless($request->user()?->is_admin && !$request->user()->banned, 403);
        try { return $this->success($work((int) $request->user()->id)); }
        catch (LockTimeoutException) { return response()->json(['message' => 'busy'], 409); }
        catch (CloudException $error) { return response()->json(['message' => $error->getMessage()], 422); }
    }
    public function fetch(Request $request, MachineFailoverServiceRegistry $service)
    {
        return $this->run($request, function ($admin) use ($service) { $service->actor($admin); return $service->overview(); });
    }
    public function save(Request $request, MachineFailoverServiceRegistry $service)
    {
        return $this->run($request, fn ($admin) => $service->save($request->all(), $admin));
    }
    public function drop(Request $request, MachineFailoverServiceRegistry $service)
    {
        return $this->run($request, function ($admin) use ($request, $service) {
            $this->fields($request, ['id', 'expected_revision', 'confirmed']);
            $input = $request->validate(['id' => 'required|integer|min:1', 'expected_revision' => 'required|integer|min:1', 'confirmed' => 'required|accepted']);
            $service->drop((int) $input['id'], (int) $input['expected_revision'], $admin); return true;
        });
    }
    public function simulate(Request $request, MachineFailoverSimulation $service)
    {
        return $this->run($request, function ($admin) use ($request, $service) {
            $this->fields($request, ['service_id', 'service_revision', 'scenario', 'member_id', 'request_id']);
            $input = $request->validate(['service_id' => 'required|integer|min:1', 'service_revision' => 'required|integer|min:1',
                'scenario' => 'required|string', 'member_id' => 'nullable|integer|min:1', 'request_id' => 'required|regex:/^[a-f0-9]{32}$/D']);
            $run = $service->start((int) $input['service_id'], (int) $input['service_revision'], $input['scenario'],
                isset($input['member_id']) ? (int) $input['member_id'] : null, $input['request_id'], $admin);
            return $service->advance($run['id'], $admin);
        });
    }
    public function report(Request $request, MachineFailoverSimulation $service)
    {
        return $this->run($request, function ($admin) use ($request, $service) {
            if ($request->has('id')) {
                $this->fields($request, ['id']); $input = $request->validate(['id' => 'required|uuid']);
                return $service->report($input['id'], $admin);
            }
            $this->fields($request, ['service_id', 'request_id']);
            $input = $request->validate(['service_id' => 'required|integer|min:1', 'request_id' => 'required|regex:/^[a-f0-9]{32}$/D']);
            return $service->resolve((int) $input['service_id'], $input['request_id'], $admin);
        });
    }
    public function shadow(Request $request, MachineFailoverShadow $service)
    {
        return $this->run($request, function ($admin) use ($request, $service) {
            if (array_diff(array_keys($request->all()), ['service_id', 'service_revision'])) throw new CloudException('invalid_shadow');
            $input = $request->validate(['service_id' => 'required|integer|min:1', 'service_revision' => 'required|integer|min:1']);
            return $service->report((int) $input['service_id'], (int) $input['service_revision'], $admin);
        });
    }
    public function triggerHistory(Request $request, \App\Services\ServerMachine\Cloud\MachineFailoverTriggerPreview $service)
    {
        return $this->run($request, function ($admin) use ($request, $service) {
            $this->fields($request, ['service_id', 'service_revision', 'before_id']);
            $input = $request->validate(['service_id' => 'required|integer|min:1', 'service_revision' => 'required|integer|min:1',
                'before_id' => 'nullable|integer|min:1']);
            return $service->history((int) $input['service_id'], (int) $input['service_revision'], $admin,
                isset($input['before_id']) ? (int) $input['before_id'] : null);
        });
    }
    public function advance(Request $request, MachineFailoverSimulation $service)
    {
        return $this->run($request, function ($admin) use ($request, $service) {
            $this->fields($request, ['id']); $input = $request->validate(['id' => 'required|uuid']);
            return $service->advance($input['id'], $admin);
        });
    }
    public function cancel(Request $request, MachineFailoverSimulation $service)
    {
        return $this->run($request, function ($admin) use ($request, $service) {
            $this->fields($request, ['id', 'confirmed']); $input = $request->validate(['id' => 'required|uuid', 'confirmed' => 'required|accepted']);
            return $service->cancel($input['id'], $admin);
        });
    }
    private function fields(Request $request, array $allowed): void
    {
        if (array_diff(array_keys($request->all()), $allowed)) throw new CloudException('invalid_simulation');
    }
}
