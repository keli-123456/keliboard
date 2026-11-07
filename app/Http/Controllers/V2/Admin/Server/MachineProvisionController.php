<?php
namespace App\Http\Controllers\V2\Admin\Server;

use App\Http\Controllers\Controller;
use App\Services\ServerMachine\Cloud\CloudException;
use App\Services\ServerMachine\Cloud\MachineProvisionService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;

class MachineProvisionController extends Controller
{
    private function run(Request $request, callable $work)
    {
        abort_unless($request->user()?->is_admin && !$request->user()->banned, 403);
        try { return $this->success($work()); }
        catch (LockTimeoutException) { return response()->json(['message' => 'busy'], 409); }
        catch (CloudException $error) { return response()->json(['message' => $error->getMessage()], 422); }
    }

    public function fetch(Request $request, MachineProvisionService $service)
    {
        return $this->run($request, fn () => $service->overview());
    }

    public function preview(Request $request, MachineProvisionService $service)
    {
        return $this->run($request, function () use ($request, $service) {
            $input = $request->validate(['plan' => 'required|array']);
            return $service->preview($input['plan']);
        });
    }

    public function queue(Request $request, MachineProvisionService $service)
    {
        return $this->run($request, function () use ($request, $service) {
            $input = $request->validate(['plan' => 'required|array', 'request_id' => 'required|regex:/^[a-f0-9]{32}$/D',
                'confirmed' => 'required|accepted']);
            return $service->queue($input['plan'], $input['request_id'], (int) $request->user()->id);
        });
    }

    public function cancel(Request $request, MachineProvisionService $service)
    {
        return $this->run($request, function () use ($request, $service) {
            $input = $request->validate(['id' => 'required|uuid', 'confirmed' => 'required|accepted']);
            return $service->cancel($input['id'], (int) $request->user()->id);
        });
    }
}
