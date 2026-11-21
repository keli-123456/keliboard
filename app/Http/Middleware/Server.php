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
            'machine_id' => 'nullable|integer',
        ]);

        $auth = app(NodeRealtimeAuthenticator::class)->authenticate([
            'token' => $request->input('token'),
            'node_id' => $request->input('node_id'),
            'node_type' => $request->input('node_type'),
            'machine_id' => $request->input('machine_id'),
        ]);
        if (!$auth) {
            throw new ApiException('Invalid server credentials');
        }

        if ($auth['is_v2node']) {
            $request->attributes->set('is_v2node', true);
        }

        $request->merge(['node_type' => $auth['normalized_node_type']]);
        $request->attributes->set('node_info', $auth['server']);
        $source = \App\Services\Node\NodeReportSource::resolve(
            $request, isset($auth['machine']) ? (int) $auth['machine']->id : null
        );
        $request->attributes->set('node_report_source', $source);
        if ($auth['server'] && $request->isMethod('POST')) {
            abort_unless(app(\App\Services\Node\NodeInstanceRegistry::class)->record($auth['server'], $request), 429, 'Too many active node reporting instances.');
            $proof = $request->headers->all('X-Keli-Core-Activity');
            if ($auth['is_v2node'] && isset($auth['machine'], $source['installation_id']) && count($proof) === 1) {
                try {
                    $request->attributes->set('core_online_proof', app(\App\Services\ServerMachine\MachineRiskNodeSync::class)->capture(
                        $auth['machine'], $source['installation_id'], (int) $auth['server']->id, $proof[0], time()
                    ));
                } catch (\Throwable) {
                    // A risk observation must not interfere with regular node reporting.
                }
            }
        }
        return $next($request);
    }
}
