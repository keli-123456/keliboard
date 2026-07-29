<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteNavigationLink extends Model
{
    protected $table = 'v2_site_navigation_link';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];

    protected $casts = [
        'enabled' => 'boolean',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public function navigation(): BelongsTo
    {
        return $this->belongsTo(SiteNavigation::class, 'navigation_id', 'id');
    }
}
