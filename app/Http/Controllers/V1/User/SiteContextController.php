<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Services\SiteContextService;
use Illuminate\Http\Request;

class SiteContextController extends Controller
{
    public function show(Request $request)
    {
        return $this->success([
            'site' => app(SiteContextService::class)->resolve($request, $request->user()),
        ]);
    }
}
