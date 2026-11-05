<?php
namespace App\Http\Controllers\V2\Admin\Server;

use App\Http\Controllers\Controller;
use App\Services\ServerMachine\Cloud\CloudException;
use App\Services\ServerMachine\Cloud\MachineCloudService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;

class MachineCloudController extends Controller
{
    private function run(Request $request, MachineCloudService $service, callable $work, bool $requireReady = true)
    {
        abort_unless($request->user()?->is_admin, 403);
        try {
            if ($requireReady && !$service->available()) throw new CloudException('migration_required');
            return $this->success($work());
        } catch (LockTimeoutException) { return response()->json(['message' => 'busy'], 409); }
        catch (CloudException $error) { return response()->json(['message' => $error->getMessage()], 422); }
    }
    public function fetch(Request $request, MachineCloudService $service) { return $this->run($request, $service, fn () => $service->overview(), false); }
    public function account(Request $request, MachineCloudService $service)
    {
        $input = $request->validate(['id' => 'sometimes|integer|min:1', 'expected_revision' => 'required_with:id|integer|min:1',
            'name' => 'required|string|max:80', 'api_token' => 'nullable|string|max:4096']);
        return $this->run($request, $service, fn () => $service->saveAccount($input));
    }
    public function dropAccount(Request $request, MachineCloudService $service)
    {
        $input = $request->validate(['id' => 'required|integer|min:1', 'expected_revision' => 'required|integer|min:1', 'confirmed' => 'required|accepted']);
        return $this->run($request, $service, function () use ($service, $input) { $service->deleteAccount($input['id'], $input['expected_revision']); return true; });
    }
    public function refresh(Request $request, MachineCloudService $service)
    {
        $input = $request->validate(['id' => 'required|integer|min:1', 'page' => 'required|integer|min:1|max:100']);
        return $this->run($request, $service, function () use ($service, $input) { $service->refreshAccount($input['id'], $input['page']); return true; });
    }
    public function binding(Request $request, MachineCloudService $service)
    {
        $input = $request->validate(['id' => 'required|integer|min:1', 'expected_revision' => 'required|integer|min:1',
            'machine_id' => 'present|nullable|integer|min:1', 'installation_id' => 'required_with:machine_id|nullable|regex:/^[a-f0-9]{32}$/D', 'confirmed' => 'required|accepted']);
        return $this->run($request, $service, function () use ($service, $input) {
            $service->binding($input['id'], $input['expected_revision'], $input['machine_id'], $input['installation_id'] ?? null); return true;
        });
    }
    public function policy(Request $request, MachineCloudService $service)
    {
        $input = $request->validate(['id' => 'required|integer|min:1', 'expected_revision' => 'required|integer|min:1', 'enabled' => 'required|boolean', 'confirmed' => 'required|accepted']);
        return $this->run($request, $service, function () use ($request, $service, $input) {
            $service->policy($input['id'], $input['expected_revision'], (bool) $input['enabled'], (int) $request->user()->id); return true;
        });
    }
    public function power(Request $request, MachineCloudService $service)
    {
        $input = $request->validate(['id' => 'required|integer|min:1', 'expected_revision' => 'required|integer|min:1',
            'action' => 'required|in:boot,shutdown,reboot', 'request_id' => 'required|regex:/^[a-f0-9]{32}$/D', 'confirmed' => 'required|accepted']);
        return $this->run($request, $service, fn () => $service->queue($input['id'], $input['expected_revision'], $input['action'], $input['request_id'], (int) $request->user()->id));
    }
    public function cancel(Request $request, MachineCloudService $service)
    {
        $input = $request->validate(['id' => 'required|integer|min:1', 'confirmed' => 'required|accepted']);
        return $this->run($request, $service, function () use ($request, $service, $input) { $service->cancel($input['id'], (int) $request->user()->id); return true; });
    }
    public function verify(Request $request, MachineCloudService $service)
    {
        $input = $request->validate(['id' => 'required|integer|min:1', 'expected_revision' => 'required|integer|min:1', 'confirmed' => 'required|accepted']);
        return $this->run($request, $service, function () use ($service, $input) { $service->verifyRecovery($input['id'], $input['expected_revision']); return true; });
    }
}
