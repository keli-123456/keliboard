<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class StaffSitesController extends Controller
{
    /**
     * @param array<int, mixed> $sites
     * @return array<int, array{id:string,name:string,baseUrl:string,adminPath:string,enabled:bool}>
     */
    private function normalizeSites(array $sites): array
    {
        $result = [];
        $seen = [];

        foreach ($sites as $site) {
            if (!is_array($site)) {
                continue;
            }

            $id = trim((string) ($site['id'] ?? ''));
            $name = trim((string) ($site['name'] ?? ''));
            $baseUrl = trim((string) ($site['baseUrl'] ?? ''));
            $adminPath = trim((string) ($site['adminPath'] ?? ''));
            $enabled = isset($site['enabled']) ? (bool) $site['enabled'] : true;

            $baseUrl = preg_replace('/\/+$/', '', $baseUrl) ?: '';
            $adminPath = preg_replace('/^\/+|\/+$/', '', $adminPath) ?: '';

            if ($id === '') {
                $id = (string) Str::uuid();
            }

            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;

            $result[] = [
                'id' => $id,
                'name' => $name,
                'baseUrl' => $baseUrl,
                'adminPath' => $adminPath,
                'enabled' => $enabled,
            ];
        }

        return $result;
    }

    public function fetch()
    {
        $sites = admin_setting('staff_desk_sites', []);
        if (!is_array($sites)) {
            $sites = [];
        }
        return $this->success($sites);
    }

    public function save(Request $request)
    {
        $request->validate([
            'sites' => 'required|array',
            'sites.*.id' => 'nullable|string|max:64',
            'sites.*.name' => 'required|string|max:64',
            'sites.*.baseUrl' => 'required|string|max:255|regex:/^https?:\\/\\//i',
            'sites.*.adminPath' => 'required|string|max:64|regex:/^[\\w-]{3,64}$/',
            'sites.*.enabled' => 'nullable|boolean',
        ], [
            'sites.required' => '站点列表不能为空',
            'sites.array' => '站点列表格式不正确',
            'sites.*.name.required' => '站点名称不能为空',
            'sites.*.baseUrl.required' => '站点 URL 不能为空',
            'sites.*.baseUrl.regex' => '站点 URL 必须以 http(s):// 开头',
            'sites.*.adminPath.required' => '后台路径不能为空',
            'sites.*.adminPath.regex' => '后台路径格式不正确',
        ]);

        $sites = $this->normalizeSites($request->input('sites', []));

        admin_setting([
            'staff_desk_sites' => $sites,
        ]);

        return $this->success(true);
    }
}

