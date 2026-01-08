<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting as SettingModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class StaffSitesController extends Controller
{
    private const SITES_KEY = 'staff_desk_sites';
    private const SITE_KEY_PREFIX = 'staff_desk_site.';

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

    /**
     * @return array<int, array{id:string,name:string,baseUrl:string,adminPath:string,enabled:bool}>
     */
    private function loadSites(): array
    {
        // Prefer per-site rows directly from DB to avoid stale admin_settings cache in multi-instance deployments.
        $rows = SettingModel::query()
            ->where('name', 'like', self::SITE_KEY_PREFIX . '%')
            ->orderBy('name')
            ->get(['name', 'value']);

        $items = [];
        if ($rows->isNotEmpty()) {
            foreach ($rows as $row) {
                $name = (string) ($row->name ?? '');
                if ($name === '' || !Str::startsWith($name, self::SITE_KEY_PREFIX)) {
                    continue;
                }
                $id = trim(Str::after($name, self::SITE_KEY_PREFIX));
                if ($id === '') {
                    continue;
                }

                $value = $row->value;
                if (is_string($value)) {
                    $decoded = json_decode($value, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                        $value = $decoded;
                    }
                }
                if (!is_array($value)) {
                    continue;
                }

                $value['id'] = $id;
                $items[] = $value;
            }

            return $this->normalizeSites($items);
        }

        $sites = admin_setting(self::SITES_KEY, null);
        if (is_array($sites)) {
            return $this->normalizeSites($sites);
        }

        return [];
    }

    private function encodeJson(array $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || $json === '') {
            throw new \RuntimeException('json_encode_failed');
        }
        return $json;
    }

    public function fetch()
    {
        return $this->success($this->loadSites());
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

        SettingModel::query()
            ->where('name', 'like', self::SITE_KEY_PREFIX . '%')
            ->delete();

        foreach ($sites as $site) {
            $id = (string) ($site['id'] ?? '');
            if ($id === '') {
                continue;
            }

            SettingModel::createOrUpdate(self::SITE_KEY_PREFIX . $id, $this->encodeJson([
                'name' => (string) ($site['name'] ?? ''),
                'baseUrl' => (string) ($site['baseUrl'] ?? ''),
                'adminPath' => (string) ($site['adminPath'] ?? ''),
                'enabled' => (bool) ($site['enabled'] ?? true),
            ]));
        }

        SettingModel::createOrUpdate(self::SITES_KEY, $this->encodeJson($sites));

        // Safety: ensure cache is cleared even if settings store changes.
        try {
            Cache::store('redis')->forget(\App\Support\Setting::CACHE_KEY);
        } catch (\Throwable) {
        }

        return $this->success($this->loadSites());
    }
}
