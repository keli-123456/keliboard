<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SitePlanOverride extends Model
{
    protected $table = 'v2_site_plan_override';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];

    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id', 'id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id', 'id');
    }
}
