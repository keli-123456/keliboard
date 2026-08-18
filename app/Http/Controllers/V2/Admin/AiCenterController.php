<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Services\AiCenterService;
use Illuminate\Http\Request;

class AiCenterController extends Controller
{
    public function overview(Request $request, AiCenterService $service)
    {
        $days = $request->query('days');
        $days = $days === null ? 7 : max(1, min(90, (int) $days));

        return $this->success($service->overview($days));
    }
}
