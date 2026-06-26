<?php

namespace App\Http\Controllers\V2\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\NoticeSave;
use App\Models\Notice;
use App\Services\SiteDataScopeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NoticeController extends Controller
{
    public function fetch(Request $request)
    {
        $query = Notice::query()
            ->orderBy('sort', 'ASC')
            ->orderBy('id', 'DESC');

        if (app(SiteDataScopeService::class)->hasColumn('v2_notice', 'site_id')) {
            $query->with('site:id,code,name,status,is_default');
            $this->applyScopeFilter($query, $request);
        }

        return $this->success($query->get());
    }

    public function save(NoticeSave $request)
    {
        $data = $request->only([
            'title',
            'content',
            'img_url',
            'tags',
            'show',
            'popup'
        ]);
        $siteScope = app(SiteDataScopeService::class);
        if ($siteScope->hasColumn('v2_notice', 'site_id')) {
            [$scopeType, $siteId] = $this->normalizeScope($request);
            if ($scopeType === 'site' && !$siteId) {
                return $this->fail([422, '请选择分站']);
            }
            if ($siteScope->hasColumn('v2_notice', 'scope_type')) {
                $data['scope_type'] = $scopeType;
            }
            $data['site_id'] = $scopeType === 'site' ? $siteId : null;
        }
        if (!$request->input('id')) {
            if (!Notice::create($data)) {
                return $this->fail([500, '保存失败']);
            }
        } else {
            try {
                $notice = Notice::find($request->input('id'));
                if (!$notice) {
                    return $this->fail([400202, '公告不存在']);
                }
                $notice->update($data);
            } catch (\Exception $e) {
                return $this->fail([500, '保存失败']);
            }
        }
        return $this->success(true);
    }



    public function show(Request $request)
    {
        if (empty($request->input('id'))) {
            return $this->fail([500, '公告ID不能为空']);
        }
        $notice = Notice::find($request->input('id'));
        if (!$notice) {
            return $this->fail([400202, '公告不存在']);
        }
        $notice->show = $notice->show ? 0 : 1;
        if (!$notice->save()) {
            return $this->fail([500, '保存失败']);
        }

        return $this->success(true);
    }

    public function drop(Request $request)
    {
        if (empty($request->input('id'))) {
            return $this->fail([422, '公告ID不能为空']);
        }
        $notice = Notice::find($request->input('id'));
        if (!$notice) {
            return $this->fail([400202, '公告不存在']);
        }
        if (!$notice->delete()) {
            return $this->fail([500, '删除失败']);
        }
        return $this->success(true);
    }

    public function sort(Request $request)
    {
        $params = $request->validate([
            'ids' => 'required|array'
        ]);

        try {
            DB::beginTransaction();
            foreach ($params['ids'] as $k => $v) {
                $notice = Notice::findOrFail($v);
                $notice->update(['sort' => $k + 1]);
            }
            DB::commit();
            return $this->success(true);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error($e);
            return $this->fail([500, '排序保存失败']);
        }
    }

    private function applyScopeFilter($query, Request $request): void
    {
        $siteScope = app(SiteDataScopeService::class);
        $hasScopeType = $siteScope->hasColumn('v2_notice', 'scope_type');
        $scopeType = strtolower(trim((string) $request->input('scope_type', '')));
        $siteId = $request->input('site_id');

        if ($scopeType === '' && is_scalar($siteId)) {
            $normalized = strtolower(trim((string) $siteId));
            if (in_array($normalized, ['0', 'global', 'null'], true)) {
                $scopeType = 'global';
            } elseif ($normalized === 'platform') {
                $scopeType = 'platform';
            } elseif ((int) $normalized > 0) {
                $scopeType = 'site';
            }
        }

        if ($hasScopeType) {
            if ($scopeType === 'global') {
                $query->where('scope_type', 'global')->whereNull('site_id');

                return;
            }
            if ($scopeType === 'platform') {
                $query->where('scope_type', 'platform')->whereNull('site_id');

                return;
            }
            if ($scopeType === 'site') {
                if (is_scalar($siteId) && (int) $siteId > 0) {
                    $query->where('scope_type', 'site')->where('site_id', (int) $siteId);
                } else {
                    $query->whereRaw('1 = 0');
                }

                return;
            }

            return;
        }

        if (is_scalar($siteId) && trim((string) $siteId) !== '') {
            $normalized = strtolower(trim((string) $siteId));
            if (in_array($normalized, ['0', 'global', 'null'], true)) {
                $query->whereNull('site_id');
            } elseif ((int) $normalized > 0) {
                $query->where('site_id', (int) $normalized);
            }
        }
    }

    private function normalizeScope(Request $request): array
    {
        $siteIdInput = $request->input('site_id');
        $siteId = is_scalar($siteIdInput) && trim((string) $siteIdInput) !== '' && (int) $siteIdInput > 0
            ? (int) $siteIdInput
            : null;
        $scopeType = strtolower(trim((string) $request->input('scope_type', '')));

        if ($scopeType === '' || !in_array($scopeType, ['global', 'platform', 'site'], true)) {
            $scopeType = $siteId ? 'site' : 'global';
        }

        if ($scopeType !== 'site') {
            $siteId = null;
        }

        return [$scopeType, $siteId];
    }
}
