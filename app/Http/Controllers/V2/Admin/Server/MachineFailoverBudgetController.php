<?php
namespace App\Http\Controllers\V2\Admin\Server;

use App\Http\Controllers\Controller;
use App\Services\ServerMachine\Cloud\CloudException;
use App\Services\ServerMachine\Cloud\MachineFailoverBudget;
use App\Services\ServerMachine\Cloud\MachineFailoverHistory;
use App\Services\ServerMachine\Cloud\MachineFailoverReview;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;

class MachineFailoverBudgetController extends Controller
{
    private function run(Request $request, array $fields, array $rules, callable $work)
    {
        abort_unless($request->user()?->is_admin && !$request->user()->banned, 403);
        try {
            if (array_diff(array_keys($request->all()), $fields)) throw new CloudException('invalid_budget_request');
            return $this->success($work($request->validate($rules), (int) $request->user()->id));
        } catch (LockTimeoutException) { return response()->json(['message' => 'busy'], 409); }
        catch (CloudException $error) { return response()->json(['message' => $error->getMessage()], 422); }
    }
    public function workspace(Request $request, MachineFailoverBudget $service)
    {
        return $this->run($request, ['service_id', 'service_revision'], ['service_id' => 'required|integer|min:1', 'service_revision' => 'required|integer|min:1'],
            fn ($input, $admin) => $service->workspace((int) $input['service_id'], (int) $input['service_revision'], $admin));
    }
    public function capture(Request $request, MachineFailoverBudget $service)
    {
        return $this->run($request, ['service_id', 'service_revision'], ['service_id' => 'required|integer|min:1', 'service_revision' => 'required|integer|min:1'],
            fn ($input, $admin) => $service->capture((int) $input['service_id'], (int) $input['service_revision'], $admin));
    }
    public function report(Request $request, MachineFailoverBudget $service)
    {
        return $this->run($request, ['id'], ['id' => 'required|uuid'], fn ($input, $admin) => $service->report($input['id'], $admin));
    }
    public function preview(Request $request, MachineFailoverBudget $service)
    {
        return $this->run($request, ['attempt_id'], ['attempt_id' => 'required|uuid'], fn ($input, $admin) => $service->previewRelease($input['attempt_id'], $admin));
    }
    public function release(Request $request, MachineFailoverBudget $service)
    {
        return $this->run($request, ['attempt_id', 'proof_token', 'request_id', 'confirmed'], ['attempt_id' => 'required|uuid',
            'proof_token' => 'required|string|max:8192', 'request_id' => 'required|regex:/^[a-f0-9]{32}$/D', 'confirmed' => 'required|accepted'],
            fn ($input, $admin) => $service->release($input['attempt_id'], $input['proof_token'], $input['request_id'], $admin));
    }
    public function history(Request $request, MachineFailoverHistory $service)
    {
        return $this->run($request, ['service_id', 'service_revision', 'filter', 'cursor'], ['service_id' => 'required|integer|min:1',
            'service_revision' => 'required|integer|min:1', 'filter' => 'required|in:open,all,closed', 'cursor' => 'nullable|string|max:8192'],
            fn ($input, $admin) => $service->page((int) $input['service_id'], (int) $input['service_revision'], $admin, $input['filter'], $input['cursor'] ?? null));
    }
    public function review(Request $request, MachineFailoverReview $service)
    {
        return $this->run($request, ['attempt_id', 'member_id'], ['attempt_id' => 'nullable|uuid', 'member_id' => 'nullable|integer|min:1'],
            fn ($input, $admin) => $service->read($input['attempt_id'] ?? null, isset($input['member_id']) ? (int) $input['member_id'] : null, $admin));
    }
    public function resolve(Request $request, MachineFailoverReview $service)
    {
        return $this->run($request, ['check_id', 'operation', 'request_id', 'confirmed'], ['check_id' => 'required|uuid',
            'operation' => 'required|in:claim,close,restore', 'request_id' => 'required|regex:/^[a-f0-9]{32}$/D', 'confirmed' => 'required|accepted'],
            fn ($input, $admin) => $service->act($input['check_id'], $input['operation'], $input['request_id'], $admin));
    }
}
