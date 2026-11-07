<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class MachineFailoverReviewAction extends Model
{
    protected $table = 'v2_machine_failover_review_action';
    protected $guarded = [];
    protected $dateFormat = 'U';
    protected $hidden = ['payload', 'request_key', 'admin_id'];
    protected $casts = ['member_id' => 'integer', 'admin_id' => 'integer', 'payload' => 'encrypted:array',
        'created_at' => 'timestamp', 'updated_at' => 'timestamp'];
}
