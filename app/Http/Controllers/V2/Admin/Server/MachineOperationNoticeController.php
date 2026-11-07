<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Http\Controllers\Controller;
use App\Services\ServerMachine\MachineOperationNoticeException;
use App\Services\ServerMachine\MachineOperationNotifier;
use Illuminate\Http\Request;

class MachineOperationNoticeController extends Controller
{
    public function fetch(Request $request, MachineOperationNotifier $notifier)
    {
        abort_unless($request->user()?->is_admin && !$request->user()?->banned, 403);
        $input = $request->validate(['scope' => 'required|in:cloud,dns,provision,failover', 'resource_id' => 'sometimes|integer|min:1']);
        return $this->success($notifier->overview($input['scope'], $input['resource_id'] ?? null));
    }
    public function save(Request $request, MachineOperationNotifier $notifier)
    {
        abort_unless($request->user()?->is_admin && !$request->user()?->banned, 403);
        $input = $request->validate(['scope' => 'required|in:cloud,dns,provision,failover', 'enabled' => 'required|boolean',
            'expected_revision' => 'required|integer|min:1', 'confirmed' => 'required|accepted']);
        try { return $this->success($notifier->save($input['scope'], $input['enabled'], $input['expected_revision'], (int) $request->user()->id)); }
        catch (MachineOperationNoticeException $error) { return response()->json(['message' => $error->getMessage()], 422); }
    }
}
