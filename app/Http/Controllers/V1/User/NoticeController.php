<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\Notice;
use App\Services\AgentCommerceContextResolver;
use App\Services\AgentSiteContextService;
use App\Services\SiteDataScopeService;
use Illuminate\Http\Request;

class NoticeController extends Controller
{
    public function fetch(Request $request)
    {
        $current = (int) ($request->input('current') ? $request->input('current') : 1);
        $pageSize = 5;
        $agentContext = app(AgentCommerceContextResolver::class)->resolveRequest($request, $request->user());
        $siteContext = $agentContext ? app(AgentSiteContextService::class)->resolve($request, $request->user()) : null;
        $announcement = trim((string) ($siteContext['announcement'] ?? ''));
        $announcementTitle = trim((string) ($siteContext['announcement_title'] ?? ''));

        if ($agentContext) {
            if ($announcement === '') {
                return response([
                    'data' => [],
                    'total' => 0,
                ]);
            }

            return response([
                'data' => $current === 1 ? [[
                    'id' => 'agent-announcement',
                    'title' => $announcementTitle !== '' ? $announcementTitle : (string) ($siteContext['site_name'] ?? ''),
                    'content' => $announcement,
                    'show' => true,
                    'agent_context' => true,
                    'created_at' => time(),
                    'updated_at' => $siteContext['updated_at'] ?? time(),
                ]] : [],
                'total' => 1,
            ]);
        }

        $hasAgentAnnouncement = $announcement !== '';
        $includeAgentAnnouncement = $current === 1 && $hasAgentAnnouncement;
        $globalLimit = $includeAgentAnnouncement ? $pageSize - 1 : $pageSize;
        $globalOffset = $hasAgentAnnouncement && $current > 1
            ? (($current - 1) * $pageSize) - 1
            : ($current - 1) * $pageSize;
        $model = Notice::orderBy('sort', 'ASC')
            ->orderBy('id', 'DESC')
            ->where('show', true);
        $siteScope = app(SiteDataScopeService::class);
        $siteScope->applyNoticeScope(
            $model,
            $siteScope->siteIdForRequest($request, $request->user())
        );
        $total = $model->count() + ($hasAgentAnnouncement ? 1 : 0);
        $res = $model->skip($globalOffset)
            ->take($globalLimit)
            ->get();
        if ($includeAgentAnnouncement) {
            $res->prepend([
                'id' => 'agent-announcement',
                'title' => $announcementTitle !== '' ? $announcementTitle : (string) ($siteContext['site_name'] ?? ''),
                'content' => $announcement,
                'show' => true,
                'agent_context' => true,
                'created_at' => time(),
                'updated_at' => $siteContext['updated_at'] ?? time(),
            ]);
        }
        return response([
            'data' => $res,
            'total' => $total
        ]);
    }
}
