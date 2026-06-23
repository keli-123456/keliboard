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
            $siteId = $request->input('site_id');
            if (is_scalar($siteId) && trim((string) $siteId) !== '') {
                $normalized = strtolower(trim((string) $siteId));
                if (in_array($normalized, ['0', 'global', 'null'], true)) {
                    $query->whereNull('site_id');
                } elseif ((int) $normalized > 0) {
                    $query->where('site_id', (int) $normalized);
                }
            }
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
        if (app(SiteDataScopeService::class)->hasColumn('v2_notice', 'site_id')) {
            $siteId = $request->input('site_id');
            $data['site_id'] = is_scalar($siteId) && trim((string) $siteId) !== '' && (int) $siteId > 0
                ? (int) $siteId
                : null;
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
}
