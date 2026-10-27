<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ServerSave;
use App\Models\Server;
use App\Services\Node\ServerConfigHistoryService;
use Illuminate\Http\Request;

class ConfigHistoryController extends Controller
{
    public function history(Request $request, ServerConfigHistoryService $service)
    {
        $request->validate(['id' => 'required|integer|min:1', 'page' => 'nullable|integer|min:1|max:10000']);
        return $this->success($service->history(Server::findOrFail($request->integer('id')), $request->integer('page', 1)))
            ->header('Cache-Control', 'private, no-store');
    }

    public function preview(ServerSave $request, ServerConfigHistoryService $service)
    {
        $request->validate(['id' => 'required|integer|min:1']);
        return $this->success($service->preview(Server::findOrFail($request->integer('id')), $request->validated()))
            ->header('Cache-Control', 'private, no-store');
    }

    public function restorePreview(Request $request, ServerConfigHistoryService $service)
    {
        $request->validate(['id' => 'required|integer|min:1', 'revision_id' => 'required|integer|min:1']);
        $server = Server::findOrFail($request->integer('id'));
        return $this->success($service->preview($server, $service->restorePayload($server, $request->integer('revision_id'))))
            ->header('Cache-Control', 'private, no-store');
    }

    public function restore(Request $request, ServerConfigHistoryService $service)
    {
        $input = $request->validate(['id' => 'required|integer|min:1', 'revision_id' => 'required|integer|min:1',
            'expected_config_hash' => 'required|string|regex:/^[a-f0-9]{64}$/D',
            'expected_draft_hash' => 'required|string|regex:/^[a-f0-9]{64}$/D']);
        $server = Server::findOrFail($request->integer('id'));
        $payload = $service->restorePayload($server, $request->integer('revision_id'));
        try {
            $service->persist($server->id, [...$payload, ...array_intersect_key($input, array_flip(['expected_config_hash', 'expected_draft_hash']))],
                $request->user()?->id, $input['revision_id']);
        } catch (\Illuminate\Validation\ValidationException | \Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Node configuration restore failed',
                ['server_id' => (int) $server->id, 'exception' => get_class($e)]);
            return $this->fail([500, '恢复失败，未完成的事务已回退']);
        }
        return $this->success(true)->header('Cache-Control', 'private, no-store');
    }
}
