<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SiteNavigation extends Model
{
    protected $table = 'v2_site_navigation';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];

    protected $casts = [
        'enabled' => 'boolean',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id', 'id');
    }

    public function domains(): HasMany
    {
        return $this->hasMany(SiteNavigationDomain::class, 'navigation_id', 'id');
    }

    public function links(): HasMany
    {
        return $this->hasMany(SiteNavigationLink::class, 'navigation_id', 'id');
    }
}
