<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MachineDnsBinding extends Model
{
    protected $table = 'v2_machine_dns_binding';
    protected $guarded = ['id'];
    protected $hidden = ['machine_authority', 'record_scope', 'notice_episode', 'notice_last_alert_at'];
    protected $dateFormat = 'U';
    protected $casts = ['enabled' => 'boolean', 'revision' => 'integer', 'last_verified_at' => 'integer', 'next_attempt_at' => 'integer', 'notice_last_alert_at' => 'integer'];
}
