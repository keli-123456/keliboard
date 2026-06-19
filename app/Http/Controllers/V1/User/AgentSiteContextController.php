<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Services\AgentSiteContextService;
use Illuminate\Http\Request;

class AgentSiteContextController extends Controller
{
    public function show(Request $request)
    {
        return $this->success([
            'site' => app(AgentSiteContextService::class)->resolve($request, $request->user()),
        ]);
    }
}
