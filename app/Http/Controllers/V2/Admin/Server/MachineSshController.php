<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Http\Controllers\Controller;
use App\Models\ServerMachine;
use App\Models\User;
use App\Services\ServerMachine\MachineSshService;
use App\Services\ServerMachine\SshTerminalException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\PersonalAccessToken;

class MachineSshController extends Controller
{
    public function __construct(private MachineSshService $ssh) {}

    private function guard(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->is_admin && !$user->banned, 403);
        $token = $user->currentAccessToken();
        abort_unless($token instanceof PersonalAccessToken && $token->can('server:ssh'), 403);
        abort_unless($request->isSecure() || app()->environment('testing', 'local'), 403);
        return $user;
    }

    private function run(Request $request, callable $action)
    {
        $user = $this->guard($request);
        $params = $request->validate(['id' => 'required|integer|min:1']);
        $machine = ServerMachine::findOrFail($params['id']);
        try {
            return response()->json(['status' => 'success', 'data' => $action($machine, $user)])
                ->header('Cache-Control', 'no-store, private');
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (SshTerminalException $e) {
            return response()->json(['status' => 'fail', 'message' => 'SSH request rejected', 'error_code' => $e->getMessage()], $e->httpStatus)
                ->header('Cache-Control', 'no-store, private');
        } catch (\Throwable) {
            return response()->json(['status' => 'fail', 'message' => 'SSH unavailable', 'error_code' => 'unavailable'], 503)
                ->header('Cache-Control', 'no-store, private');
        }
    }

    public function profile(Request $request) { return $this->run($request, fn ($machine) => $this->ssh->profile($machine)); }

    private function target(Request $request): array
    {
        return $request->validate(['host' => 'required|string|max:253', 'port' => 'required|integer|between:1,65535',
            'username' => 'required|string|max:64']);
    }

    private function throttle(User $user, string $action): void
    {
        $key = 'ssh:http:' . $action . ':' . $user->id;
        if (RateLimiter::tooManyAttempts($key, 6)) throw new SshTerminalException('rate_limited', 429);
        RateLimiter::hit($key, 60);
    }

    public function probe(Request $request)
    {
        return $this->run($request, function ($machine, $user) use ($request) {
            $this->throttle($user, 'probe');
            return $this->ssh->probe($machine, (int) $user->id, $this->target($request));
        });
    }

    public function save(Request $request)
    {
        return $this->run($request, function ($machine, $user) use ($request) {
            $params = $this->target($request) + $request->validate(['probe_id' => 'required|string|size:64', 'confirmed' => 'required|accepted']);
            $params['confirmed'] = $request->boolean('confirmed');
            return $this->ssh->confirm($machine, (int) $user->id, $params);
        });
    }

    public function ticket(Request $request)
    {
        return $this->run($request, function ($machine, $user) use ($request) {
            $this->throttle($user, 'ticket');
            $credentials = $request->validate(['auth_type' => 'required|in:password,key',
                'password' => 'nullable|required_if:auth_type,password|string|max:4096',
                'private_key' => 'nullable|required_if:auth_type,key|string|max:32768', 'key_password' => 'nullable|string|max:4096']);
            $origin = (string) $request->header('Origin', $request->getSchemeAndHttpHost());
            if ($origin !== $request->getSchemeAndHttpHost()) throw new SshTerminalException('origin_rejected', 403);
            $credentials = $credentials['auth_type'] === 'password'
                ? ['auth_type' => 'password', 'password' => $credentials['password']]
                : ['auth_type' => 'key', 'private_key' => $credentials['private_key'], 'key_password' => $credentials['key_password'] ?? false];
            return $this->ssh->issue($machine, $user, $user->currentAccessToken(), $origin, $credentials);
        });
    }
}
