<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteOrderContext extends Model
{
    protected $table = 'v2_site_order_context';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];

    protected $casts = [
        'sale_amount' => 'integer',
        'platform_plan_price' => 'integer',
        'pricing_snapshot' => 'array',
        'domain_snapshot' => 'array',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id', 'id');
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(SiteDomain::class, 'site_domain_id', 'id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id', 'id');
    }
}
