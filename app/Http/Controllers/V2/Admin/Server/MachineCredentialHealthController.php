<?php
namespace App\Http\Controllers\V2\Admin\Server;

use App\Http\Controllers\Controller;
use App\Services\ServerMachine\Cloud\CloudException;
use App\Services\ServerMachine\Cloud\MachineCloudCredentialHealth;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;

class MachineCredentialHealthController extends Controller
{
    private function run(Request $request, array $fields, callable $work)
    {
        abort_unless($request->user()?->is_admin && !$request->user()->banned, 403);
        try {
            if (array_diff(array_keys($request->all()), $fields)) throw new CloudException('credential_health_invalid');
            return $this->success($work((int) $request->user()->id));
        } catch (CloudException $error) { return response()->json(['message' => $error->getMessage()], 422); }
        catch (LockTimeoutException) { return response()->json(['message' => 'busy'], 409); }
    }
    public function report(Request $request, MachineCloudCredentialHealth $service)
    {
        return $this->run($request, ['id'], function ($admin) use ($request, $service) {
            $input = $request->validate(['id' => 'required|integer|min:1']);
            return $service->report((int) $input['id'], $admin);
        });
    }
    public function verify(Request $request, MachineCloudCredentialHealth $service)
    {
        return $this->run($request, ['id', 'expected_revision', 'confirmed'], function ($admin) use ($request, $service) {
            $input = $request->validate(['id' => 'required|integer|min:1', 'expected_revision' => 'required|integer|min:1', 'confirmed' => 'required|accepted']);
            return $service->verify((int) $input['id'], (int) $input['expected_revision'], $admin);
        });
    }
}
