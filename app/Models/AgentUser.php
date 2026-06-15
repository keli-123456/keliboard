<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentUser extends Model
{
    protected $table = 'v2_agent_user';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];

    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_user_id', 'id');
    }

    public function subordinate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sub_user_id', 'id');
    }
}
