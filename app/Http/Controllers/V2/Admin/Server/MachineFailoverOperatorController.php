<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Http\Controllers\Controller;
use App\Services\ServerMachine\Cloud\CloudException;
use App\Services\ServerMachine\Cloud\MachineFailoverOperator;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;

final class MachineFailoverOperatorController extends Controller
{
    public function preview(Request $request, MachineFailoverOperator $operator)
    {
        return $this->run($request, ['service_id' => 'required|integer|min:1', 'service_revision' => 'required|integer|min:1'],
            fn ($input, $admin) => $operator->preview((int) $input['service_id'], (int) $input['service_revision'], $admin));
    }

    public function start(Request $request, MachineFailoverOperator $operator)
    {
        return $this->run($request, ['service_id' => 'required|integer|min:1', 'confirmation' => 'required|string|max:4096', 'confirmed' => 'required|accepted'],
            fn ($input, $admin) => $operator->start((int) $input['service_id'], $input['confirmation'], $admin));
    }

    public function receipt(Request $request, MachineFailoverOperator $operator)
    {
        return $this->run($request, ['service_id' => 'required|integer|min:1', 'request_id' => 'required|regex:/^[a-f0-9]{32}$/D'],
            fn ($input, $admin) => $operator->receipt((int) $input['service_id'], $input['request_id'], $admin));
    }

    private function run(Request $request, array $rules, callable $work)
    {
        abort_unless($request->user()?->is_admin && !$request->user()->banned, 403);
        try {
            if (array_diff(array_keys($request->all()), array_keys($rules))) throw new CloudException('invalid_operator_request');
            return $this->success($work($request->validate($rules), (int) $request->user()->id))->header('Cache-Control', 'no-store');
        } catch (LockTimeoutException) {
            return response()->json(['message' => 'busy'], 409);
        } catch (CloudException $error) {
            return response()->json(['message' => $error->getMessage()], 422);
        }
    }
}
