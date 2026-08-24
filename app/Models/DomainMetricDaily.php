<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DomainMetricDaily extends Model
{
    protected $table = 'v2_domain_metric_daily';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];
}
