<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MachineDnsCredential extends Model
{
    protected $table = 'v2_machine_dns_credential';
    protected $guarded = ['id'];
    protected $hidden = ['credentials'];
    protected $dateFormat = 'U';
    protected $casts = ['credentials' => 'encrypted:array', 'revision' => 'integer'];
}
