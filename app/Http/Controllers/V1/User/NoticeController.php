<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\Notice;
use App\Services\AgentSiteContextService;
use Illuminate\Http\Request;

class NoticeController extends Controller
{
    public function fetch(Request $request)
    {
        $current = (int) ($request->input('current') ? $request->input('current') : 1);
        $pageSize = 5;
        $siteContext = app(AgentSiteContextService::class)->resolve($request, $request->user());
        $announcement = trim((string) ($siteContext['announcement'] ?? ''));
        $hasAgentAnnouncement = $announcement !== '';
        $includeAgentAnnouncement = $current === 1 && $hasAgentAnnouncement;
        $globalLimit = $includeAgentAnnouncement ? $pageSize - 1 : $pageSize;
        $globalOffset = $hasAgentAnnouncement && $current > 1
            ? (($current - 1) * $pageSize) - 1
            : ($current - 1) * $pageSize;
        $model = Notice::orderBy('sort', 'ASC')
            ->orderBy('id', 'DESC')
            ->where('show', true);
        $total = $model->count() + ($hasAgentAnnouncement ? 1 : 0);
        $res = $model->skip($globalOffset)
            ->take($globalLimit)
            ->get();
        if ($includeAgentAnnouncement) {
            $res->prepend([
                'id' => 'agent-announcement',
                'title' => (string) ($siteContext['site_name'] ?? ''),
                'content' => $announcement,
                'show' => true,
                'agent_context' => true,
                'updated_at' => $siteContext['updated_at'] ?? null,
            ]);
        }
        return response([
            'data' => $res,
            'total' => $total
        ]);
    }
}
