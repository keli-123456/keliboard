<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Resources\TrafficLogResource;
use App\Http\Resources\TrafficNodeLogResource;
use App\Models\StatUser;
use App\Models\StatUserNodeDay;
use Carbon\Carbon;
use Illuminate\Http\Request;

class StatController extends Controller
{
    public function getTrafficLog(Request $request)
    {
        $startDate = now()->subDays(29)->startOfDay()->timestamp;
        $records = StatUser::query()
            ->where('user_id', $request->user()->id)
            ->where('record_at', '>=', $startDate)
            ->orderBy('record_at', 'DESC')
            ->get();

        $data = TrafficLogResource::collection(collect($records));
        return $this->success($data);
    }

    public function getTrafficNodeLog(Request $request)
    {
        $validated = $request->validate([
            'date' => 'nullable|date_format:Y-m-d',
        ]);

        $date = $validated['date'] ?? now()->format('Y-m-d');
        $recordAt = Carbon::createFromFormat('Y-m-d', $date)->startOfDay()->timestamp;

        $records = StatUserNodeDay::query()
            ->selectRaw('server_id, server_type, MAX(server_name) as server_name, MIN(server_rate) as min_rate, MAX(server_rate) as max_rate, SUM(u) as u, SUM(d) as d, SUM(u) + SUM(d) as total, MAX(record_at) as record_at')
            ->where('user_id', $request->user()->id)
            ->where('record_at', $recordAt)
            ->where('record_type', 'd')
            ->groupBy('server_id', 'server_type')
            ->havingRaw('SUM(u) + SUM(d) > 0')
            ->orderByRaw('SUM(u) + SUM(d) DESC')
            ->get();

        return $this->success(TrafficNodeLogResource::collection($records));
    }
}
