<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InviteClick extends Model
{
    protected $table = 'v2_invite_click';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'clicked_at' => 'timestamp',
        'last_clicked_at' => 'timestamp',
        'converted_at' => 'timestamp',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public function inviteCode(): BelongsTo
    {
        return $this->belongsTo(InviteCode::class, 'invite_code_id', 'id');
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inviter_user_id', 'id');
    }

    public function registeredUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_user_id', 'id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id', 'id');
    }
}
