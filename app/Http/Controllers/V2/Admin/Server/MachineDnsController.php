<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Http\Controllers\Controller;
use App\Services\ServerMachine\Dns\DnsException;
use App\Services\ServerMachine\Dns\MachineDnsService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;

class MachineDnsController extends Controller
{
    private function run(Request $request, MachineDnsService $service, callable $work)
    {
        abort_unless($request->user()?->is_admin, 403);
        try { return $this->success($work()); }
        catch (LockTimeoutException) { return response()->json(['message' => 'busy'], 409); }
        catch (DnsException $error) { return response()->json(['message' => $error->getMessage()], 422); }
    }

    public function fetch(Request $request, MachineDnsService $service)
    {
        return $this->run($request, $service, fn () => $service->overview());
    }

    public function instances(Request $request, MachineDnsService $service)
    {
        $data = $request->validate(['machine_id' => 'required|integer|min:1']);
        return $this->run($request, $service, fn () => $service->instances($data['machine_id']));
    }

    public function credential(Request $request, MachineDnsService $service)
    {
        $data = $request->validate(['id' => 'sometimes|integer|min:1', 'expected_revision' => 'required_with:id|integer|min:1',
            'name' => 'required|string|max:80', 'provider' => 'required|in:cloudflare,aliyun',
            'api_token' => 'nullable|string|max:4096', 'access_key_id' => 'nullable|string|max:256', 'access_key_secret' => 'nullable|string|max:4096']);
        return $this->run($request, $service, function () use ($request, $service, $data) {
            if (!$service->available()) throw new DnsException('migration_required');
            return $service->saveCredential($data, (int) $request->user()->id);
        });
    }

    public function dropCredential(Request $request, MachineDnsService $service)
    {
        $data = $request->validate(['id' => 'required|integer|min:1', 'expected_revision' => 'required|integer|min:1']);
        return $this->run($request, $service, function () use ($request, $service, $data) {
            $service->deleteCredential($data['id'], $data['expected_revision'], (int) $request->user()->id);
            return true;
        });
    }

    public function binding(Request $request, MachineDnsService $service)
    {
        $data = $request->validate(['credential_id' => 'required|integer|min:1', 'machine_id' => 'required|integer|min:1',
            'installation_id' => 'required|string|regex:/^[a-f0-9]{32}$/D', 'zone' => 'required|string|max:253',
            'record_id' => 'required|string|max:64', 'hostname' => 'required|string|max:253', 'type' => 'required|in:A,AAAA']);
        return $this->run($request, $service, fn () => $service->createBinding($data, (int) $request->user()->id));
    }

    public function change(Request $request, MachineDnsService $service)
    {
        $data = $request->validate(['id' => 'required|integer|min:1', 'expected_revision' => 'required|integer|min:1',
            'enabled' => 'required|boolean', 'delete' => 'sometimes|boolean', 'confirmed' => 'required|accepted']);
        return $this->run($request, $service, function () use ($request, $service, $data) {
            $service->changeBinding($data['id'], $data['expected_revision'], (bool) $data['enabled'], (bool) ($data['delete'] ?? false), (int) $request->user()->id);
            return true;
        });
    }

    public function sync(Request $request, MachineDnsService $service)
    {
        $data = $request->validate(['id' => 'required|integer|min:1', 'expected_revision' => 'required|integer|min:1', 'confirmed' => 'required|accepted']);
        return $this->run($request, $service, function () use ($request, $service, $data) {
            $service->queue($data['id'], $data['expected_revision'], (int) $request->user()->id);
            return true;
        });
    }
}
