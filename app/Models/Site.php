<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
}
