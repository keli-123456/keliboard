<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Site extends Model
{
    protected $table = 'v2_site';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];

    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';

    protected $casts = [
        'is_default' => 'boolean',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public function domains(): HasMany
    {
        return $this->hasMany(SiteDomain::class, 'site_id', 'id');
    }

    public function setting(): HasOne
    {
        return $this->hasOne(SiteSetting::class, 'site_id', 'id');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(SitePlanPrice::class, 'site_id', 'id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SitePayment::class, 'site_id', 'id');
    }

    public function orderContexts(): HasMany
    {
        return $this->hasMany(SiteOrderContext::class, 'site_id', 'id');
    }
}
