<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SitePayment extends Model
{
    protected $table = 'v2_site_payment';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];

    protected $casts = [
        'enabled' => 'boolean',
        'sort' => 'integer',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id', 'id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id', 'id');
    }
}
