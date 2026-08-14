<?php

namespace App\Http\Controllers\V2\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\KnowledgeSave;
use App\Models\AgentDomain;
use App\Models\AgentProfile;
use App\Models\Knowledge;
use App\Models\Site;
use App\Services\OfficialKnowledgePackService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class KnowledgeController extends Controller
{
    public function fetch(Request $request)
    {
        if ($request->input('id')) {
            $knowledge = Knowledge::find($request->input('id'));
            if (!$knowledge) {
                return $this->fail([400202, '知识不存在']);
            }
            return $this->success($knowledge);
        }
        $columns = ['title', 'id', 'updated_at', 'category', 'show'];
        if ($this->hasScopeColumns()) {
            array_push($columns, 'scope_type', 'site_id', 'agent_user_id', 'agent_domain_id');
        }
        if ($this->hasSourceColumns()) {
            array_push($columns, 'source_type', 'source_key', 'source_version', 'source_hash', 'source_synced_at');
        }
        $data = Knowledge::select($columns)
            ->orderBy('sort', 'ASC')
            ->get();
        return $this->success($data);
    }

    public function getCategory(Request $request)
    {
        return $this->success(array_keys(Knowledge::get()->groupBy('category')->toArray()));
    }

    public function scopeOptions()
    {
        $sites = Site::query()
            ->select(['id', 'code', 'name', 'status'])
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(fn (Site $site): array => [
                'id' => (int) $site->id,
                'code' => (string) $site->code,
                'name' => (string) $site->name,
                'status' => (string) $site->status,
            ])
            ->values();

        $profiles = AgentProfile::query()
            ->with(['user:id,email'])
            ->orderBy('user_id')
            ->get();
        $domains = AgentDomain::query()
            ->select(['id', 'agent_user_id', 'domain', 'status'])
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get()
            ->groupBy('agent_user_id');
        $agents = $profiles->map(fn (AgentProfile $profile): array => [
            'user_id' => (int) $profile->user_id,
            'email' => (string) ($profile->user?->email ?? ''),
            'status' => (string) $profile->status,
            'domains' => collect($domains->get($profile->user_id, []))->map(fn (AgentDomain $domain): array => [
                'id' => (int) $domain->id,
                'domain' => (string) $domain->domain,
                'status' => (string) $domain->status,
            ])->values()->all(),
        ])->values();

        return $this->success([
            'sites' => $sites,
            'agents' => $agents,
        ]);
    }

    public function officialStatus(OfficialKnowledgePackService $service)
    {
        try {
            return $this->success($service->status());
        } catch (Throwable $exception) {
            \Log::error($exception);
            return $this->fail([500, $exception->getMessage()]);
        }
    }

    public function officialSync(OfficialKnowledgePackService $service)
    {
        try {
            return $this->success($service->sync());
        } catch (Throwable $exception) {
            \Log::error($exception);
            return $this->fail([500, $exception->getMessage()]);
        }
    }

    public function save(KnowledgeSave $request)
    {
        $params = $request->validated();

        if (!$request->input('id')) {
            if (!Knowledge::create($params)) {
                return $this->fail([500, '创建失败']);
            }
        } else {
            try {
                $knowledge = Knowledge::find($request->input('id'));
                if (!$knowledge) {
                    return $this->fail([400202, '知识不存在']);
                }
                $knowledge->update($params);
            } catch (\Exception $e) {
                \Log::error($e);
                return $this->fail([500, '创建失败']);
            }
        }

        return $this->success(true);
    }

    public function show(Request $request)
    {
        $request->validate([
            'id' => 'required|numeric'
        ], [
            'id.required' => '知识库ID不能为空'
        ]);
        $knowledge = Knowledge::find($request->input('id'));
        if (!$knowledge) {
            throw new ApiException('知识不存在');
        }
        $knowledge->show = !$knowledge->show;
        if (!$knowledge->save()) {
            throw new ApiException('保存失败');
        }

        return $this->success(true);
    }

    public function sort(Request $request)
    {
        $request->validate([
            'ids' => 'required|array'
        ], [
            'ids.required' => '参数有误',
            'ids.array' => '参数有误'
        ]);
        try {
            DB::beginTransaction();
            foreach ($request->input('ids') as $k => $v) {
                $knowledge = Knowledge::find($v);
                if (!$knowledge) {
                    continue;
                }
                $knowledge->timestamps = false;
                $knowledge->update(['sort' => $k + 1]);
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw new ApiException('保存失败');
        }
        return $this->success(true);
    }

    public function drop(Request $request)
    {
        $request->validate([
            'id' => 'required|numeric'
        ], [
            'id.required' => '知识库ID不能为空'
        ]);
        $knowledge = Knowledge::find($request->input('id'));
        if (!$knowledge) {
            return $this->fail([400202, '知识不存在']);
        }
        if (!$knowledge->delete()) {
            return $this->fail([500, '删除失败']);
        }

        return $this->success(true);
    }

    private function hasScopeColumns(): bool
    {
        try {
            $schema = app('db')->connection()->getSchemaBuilder();

            return $schema->hasColumn('v2_knowledge', 'scope_type')
                && $schema->hasColumn('v2_knowledge', 'site_id')
                && $schema->hasColumn('v2_knowledge', 'agent_user_id')
                && $schema->hasColumn('v2_knowledge', 'agent_domain_id');
        } catch (\Throwable) {
            return false;
        }
    }

    private function hasSourceColumns(): bool
    {
        try {
            $schema = app('db')->connection()->getSchemaBuilder();

            return $schema->hasColumn('v2_knowledge', 'source_type')
                && $schema->hasColumn('v2_knowledge', 'source_key')
                && $schema->hasColumn('v2_knowledge', 'source_version')
                && $schema->hasColumn('v2_knowledge', 'source_hash')
                && $schema->hasColumn('v2_knowledge', 'source_synced_at');
        } catch (Throwable) {
            return false;
        }
    }
}
