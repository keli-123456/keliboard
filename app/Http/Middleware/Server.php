<?php


namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Services\NodeRealtime\NodeRealtimeAuthenticator;
use Closure;
use Illuminate\Http\Request;

class Server
{
    public function handle(Request $request, Closure $next, ?string $nodeType = null)
    {
        if ($nodeType !== null && !$request->filled('node_type')) {
            $request->merge(['node_type' => $nodeType]);
        }

        $request->validate([
            'token' => 'required|string',
            'node_id' => 'required',
            'node_type' => 'nullable',
        ]);

        $auth = app(NodeRealtimeAuthenticator::class)->authenticate([
            'token' => $request->input('token'),
            'node_id' => $request->input('node_id'),
            'node_type' => $request->input('node_type'),
        ]);
        if (!$auth) {
            throw new ApiException('Invalid server credentials');
        }

        if ($auth['is_v2node']) {
            $request->attributes->set('is_v2node', true);
        }

        $request->merge(['node_type' => $auth['normalized_node_type']]);
        $request->attributes->set('node_info', $auth['server']);
        return $next($request);
    }
}
