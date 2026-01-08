<?php

namespace App\Http\Controllers\V2\Staff;

use App\Http\Controllers\Controller;
use App\Models\Setting as SettingModel;
use Illuminate\Support\Str;

class SiteController extends Controller
{
    private const SITES_KEY = 'staff_desk_sites';
    private const SITE_KEY_PREFIX = 'staff_desk_site.';

    /**
     * @return array<int, array{id:string,name:string,baseUrl:string,adminPath:string,enabled:bool}>
     */
    private function loadSites(): array
    {
        $sites = admin_setting(self::SITES_KEY, null);
        if (is_array($sites)) {
            return $sites;
        }

        $rows = SettingModel::query()
            ->where('name', 'like', self::SITE_KEY_PREFIX . '%')
            ->orderBy('name')
            ->get(['name', 'value']);

        $items = [];
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
        return $items;
    }

    public function fetch()
    {
        $sites = $this->loadSites();

        $enabled = [];
        foreach ($sites as $site) {
            if (!is_array($site)) {
                continue;
            }
            if (isset($site['enabled']) && !$site['enabled']) {
                continue;
            }
            $enabled[] = $site;
        }

        return $this->success($enabled);
    }
}
