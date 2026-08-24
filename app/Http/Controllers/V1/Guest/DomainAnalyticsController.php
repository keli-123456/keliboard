<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Services\DomainAnalyticsService;
use Illuminate\Http\Request;

class DomainAnalyticsController extends Controller
{
    public function track(Request $request, DomainAnalyticsService $analytics)
    {
        $request->validate(['path' => 'nullable|string|max:500']);
        $analytics->recordPageView($request);
        return $this->success(true);
    }
}
