<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteNavigationDomain extends Model
{
    protected $table = 'v2_site_navigation_domain';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];

    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';

    protected $casts = [
        'is_primary' => 'boolean',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public function navigation(): BelongsTo
    {
        return $this->belongsTo(SiteNavigation::class, 'navigation_id', 'id');
    }
}
