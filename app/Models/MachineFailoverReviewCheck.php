<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class MachineFailoverReviewCheck extends Model
{
    protected $table = 'v2_machine_failover_review_check';
    protected $guarded = [];
    public $incrementing = false;
    protected $keyType = 'string';
    protected $dateFormat = 'U';
    protected $hidden = ['payload', 'context_hash', 'admin_id'];
    protected $casts = ['member_id' => 'integer', 'admin_id' => 'integer', 'payload' => 'encrypted:array',
        'checked_at' => 'integer', 'valid_until' => 'integer', 'created_at' => 'timestamp', 'updated_at' => 'timestamp'];
}
