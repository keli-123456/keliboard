<?php

declare(strict_types=1);

namespace App\Http\Controllers\V2\Admin\Server;

use App\Http\Controllers\Controller;
use App\Services\ServerMachine\ExampleRehearsal\Grant;
use App\Services\ServerMachine\ExampleRehearsal\Wire;
use App\Services\ServerMachine\ExampleRehearsal\WorkerSocket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MachineFailoverExampleController extends Controller
{
    public function status(Request $request, WorkerSocket $worker): JsonResponse
    {
        return $this->exchange($request, $worker, false);
    }

    public function operate(Request $request, WorkerSocket $worker): JsonResponse
    {
        return $this->exchange($request, $worker, true);
    }

    private function exchange(Request $request, WorkerSocket $worker, bool $operate): JsonResponse
    {
        $grant = Grant::forUser($request->user());
        abort_if($grant === null, 404);
        $input = $request->all();
        abort_unless($operate
            ? $request->isJson() && $request->query->count() === 0 && count($input) === 1 && in_array($input['action'] ?? null, Wire::ACTIONS, true)
            : $input === [], 422);
        $result = $worker->send($grant, $operate ? $input['action'] : 'status');
        $error = $result['error'] ?? null;
        $reply = match ($error) {
            null => $this->success($result),
            'busy', 'phase_conflict' => response()->json(['message' => $error], 409),
            'closed' => response()->json(['message' => 'preview_closed'], 404),
            default => response()->json(['message' => 'preview_unavailable'], 503),
        };
        return $reply->header('Cache-Control', 'no-store');
    }
}
