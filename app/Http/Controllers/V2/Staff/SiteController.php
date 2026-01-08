<?php

namespace App\Http\Controllers\V2\Staff;

use App\Http\Controllers\Controller;

class SiteController extends Controller
{
    public function fetch()
    {
        $sites = admin_setting('staff_desk_sites', []);
        if (!is_array($sites)) {
            $sites = [];
        }

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

