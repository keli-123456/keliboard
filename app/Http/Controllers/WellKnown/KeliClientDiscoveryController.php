<?php

namespace App\Http\Controllers\WellKnown;

use App\Http\Controllers\Controller;
use App\Services\KeliClientDiscoveryService;
use Illuminate\Http\Request;

class KeliClientDiscoveryController extends Controller
{
    public function show(Request $request, KeliClientDiscoveryService $discovery)
    {
        if (!$discovery->enabled()) {
            abort(404);
        }

        $ttl = max(60, (int) config('keli_client.discovery.ttl', 3600));

        return response()
            ->json($discovery->payload($request))
            ->header('Access-Control-Allow-Origin', '*')
            ->header('Cache-Control', 'public, max-age=' . min($ttl, 3600));
    }
}
