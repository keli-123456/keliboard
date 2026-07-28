<?php

namespace App\Http\Controllers\V2\Staff;

use App\Http\Controllers\Controller;
use App\Models\Site;

class SiteController extends Controller
{
    public function fetch()
    {
        $sites = Site::query()
            ->where('status', Site::STATUS_ACTIVE)
            ->orderBy('name')
            ->get(['id', 'code', 'name'])
            ->map(static fn (Site $site): array => [
                'id' => (string) $site->id,
                'code' => (string) $site->code,
                'name' => (string) $site->name,
                'is_platform' => false,
            ])
            ->values()
            ->all();

        array_unshift($sites, [
            'id' => 'platform',
            'code' => 'platform',
            'name' => '主站',
            'is_platform' => true,
        ]);

        return $this->success($sites);
    }
}
