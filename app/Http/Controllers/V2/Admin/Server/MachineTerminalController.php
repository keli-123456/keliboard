<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Http\Controllers\Controller;
use App\Models\ServerMachine;
use App\Models\User;
use App\Services\ServerMachine\MachineTerminalService;
use App\Services\ServerMachine\SshTerminalException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\PersonalAccessToken;

class MachineTerminalController extends Controller
{
    public function __construct(private MachineTerminalService $terminal) {}

    private function run(Request $request, callable $action)
    {
        $user = $request->user(); $token = $user?->currentAccessToken();
        abort_unless($user instanceof User && $user->is_admin && !$user->banned
            && $token instanceof PersonalAccessToken && $token->can('server:terminal'), 403);
        abort_unless($request->isSecure() || app()->environment('testing', 'local'), 403);
        if ($request->isMethod('post')) abort_unless($request->header('Origin', $request->getSchemeAndHttpHost()) === $request->getSchemeAndHttpHost(), 403);
        $params = $request->validate(['id' => 'required|integer|min:1']);
        $machine = ServerMachine::findOrFail($params['id']);
        try {
            $this->terminal->authorizeAdmin($user, $token);
            return response()->json(['status' => 'success', 'data' => $action($machine, $user, $token)])
                ->header('Cache-Control', 'no-store, private');
        } catch (\Illuminate\Validation\ValidationException $e) { throw $e; }
        catch (SshTerminalException $e) {
            return response()->json(['status' => 'fail', 'message' => 'Terminal request rejected', 'error_code' => $e->getMessage()], $e->httpStatus)->header('Cache-Control', 'no-store, private');
        } catch (\Throwable) {
            return response()->json(['status' => 'fail', 'message' => 'Terminal unavailable', 'error_code' => 'unavailable'], 503)->header('Cache-Control', 'no-store, private');
        }
    }

    public function profile(Request $r) { return $this->run($r, fn ($machine) => $this->terminal->profile($machine)); }

    public function policy(Request $r)
    {
        return $this->run($r, function ($machine) use ($r) {
            $params = $r->validate(['enabled' => 'required|boolean', 'confirmed' => 'required|accepted']);
            $machine->terminal_enabled = (bool) $params['enabled']; $machine->save();
            return $this->terminal->profile($machine);
        });
    }

    public function ticket(Request $r)
    {
        return $this->run($r, function ($machine, $user, $token) use ($r) {
            $key = 'terminal:http:' . $user->id;
            if (RateLimiter::tooManyAttempts($key, 6)) throw new SshTerminalException('rate_limited', 429);
            RateLimiter::hit($key, 60);
            return $this->terminal->issue($machine, $user, $token, $r->getSchemeAndHttpHost());
        });
    }
}
