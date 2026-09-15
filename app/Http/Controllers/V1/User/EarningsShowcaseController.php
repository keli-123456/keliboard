<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Services\EarningsShowcaseService;
use Illuminate\Http\Request;

class EarningsShowcaseController extends Controller
{
    public function show(Request $request)
    {
        return $this->success(app(EarningsShowcaseService::class)->forUser($request))
            ->header('Cache-Control', 'no-store, private, max-age=0')->header('Pragma', 'no-cache');
    }
}
