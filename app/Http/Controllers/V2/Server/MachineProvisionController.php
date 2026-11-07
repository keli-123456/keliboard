<?php
namespace App\Http\Controllers\V2\Server;

use App\Http\Controllers\Controller;
use App\Services\ServerMachine\Cloud\CloudException;
use App\Services\ServerMachine\Cloud\MachineProvisionService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;

class MachineProvisionController extends Controller
{
    public function result(Request $request, MachineProvisionService $service)
    {
        if (strlen($request->getContent()) > 2048) return response()->json(['message' => 'invalid_callback'], 401);
        $input = $request->json()->all();
        $secret = $request->bearerToken();
        if (!is_string($secret) || !preg_match('/^[a-f0-9]{64}$/D', $secret)
            || array_diff(array_keys($input), ['task_id', 'installation_id', 'state'])
            || !is_string($input['task_id'] ?? null) || !is_string($input['installation_id'] ?? null)
            || !is_string($input['state'] ?? null)) return response()->json(['message' => 'invalid_callback'], 401);
        try {
            return $this->success(['accepted' => $service->callback($input['task_id'], $input['installation_id'], $secret, $input['state'])]);
        } catch (LockTimeoutException) { return response()->json(['message' => 'busy'], 503); }
        catch (CloudException $error) {
            return response()->json(['message' => $error->getMessage() === 'bootstrap_conflict' ? 'bootstrap_conflict' : 'invalid_callback'],
                $error->getMessage() === 'bootstrap_conflict' ? 409 : 401);
        }
    }
}
