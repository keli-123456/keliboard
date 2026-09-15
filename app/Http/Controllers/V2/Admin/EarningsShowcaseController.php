<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Services\EarningsShowcaseService;
use Illuminate\Http\Request;

class EarningsShowcaseController extends Controller
{
    public function show(Request $request)
    {
        abort_unless((bool) $request->user()?->is_admin, 403);
        return $this->success(app(EarningsShowcaseService::class)->adminView())->header('Cache-Control', 'no-store, private');
    }
}
