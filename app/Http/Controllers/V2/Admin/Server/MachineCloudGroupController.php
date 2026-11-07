<?php
namespace App\Http\Controllers\V2\Admin\Server;

use App\Http\Controllers\Controller;
use App\Services\ServerMachine\Cloud\CloudException;
use App\Services\ServerMachine\Cloud\MachineCloudGroupService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;

class MachineCloudGroupController extends Controller
{
    private function run(Request $request, callable $work)
    {
        abort_unless($request->user()?->is_admin && !$request->user()->banned, 403);
        try { return $this->success($work()); }
        catch (LockTimeoutException) { return response()->json(['message' => 'busy'], 409); }
        catch (CloudException $error) { return response()->json(['message' => $error->getMessage()], 422); }
    }
    public function save(Request $request, MachineCloudGroupService $service)
    {
        return $this->run($request, function () use ($request, $service) {
            $input = $request->validate(['id' => 'sometimes|integer|min:1', 'expected_revision' => 'required_with:id|integer|min:1', 'name' => 'required|string|max:60']);
            return $service->save($input);
        });
    }
    public function assign(Request $request, MachineCloudGroupService $service)
    {
        return $this->run($request, function () use ($request, $service) {
            $input = $request->validate(['id' => 'required|integer|min:1', 'group_id' => 'present|nullable|integer|min:1', 'expected_group_revision' => 'required|integer|min:1']);
            return $service->assign($input['id'], $input['group_id'], $input['expected_group_revision']);
        });
    }
    public function drop(Request $request, MachineCloudGroupService $service)
    {
        return $this->run($request, function () use ($request, $service) {
            $input = $request->validate(['id' => 'required|integer|min:1', 'expected_revision' => 'required|integer|min:1', 'confirmed' => 'required|accepted']);
            $service->delete($input['id'], $input['expected_revision']); return true;
        });
    }
}
