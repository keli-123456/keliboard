<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Services\InviteTrackingService;
use Illuminate\Http\Request;

class InviteTrackingController extends Controller
{
    public function track(Request $request, InviteTrackingService $tracking)
    {
        $request->validate([
            'code' => 'required|string|max:64',
            'referrer' => 'nullable|string|max:2048',
            'utm_source' => 'nullable|string|max:80',
            'utm_medium' => 'nullable|string|max:80',
            'utm_campaign' => 'nullable|string|max:120',
        ]);

        return $this->success($tracking->track($request));
    }
}
