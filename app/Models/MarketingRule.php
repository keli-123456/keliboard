<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingRule extends Model
{
    protected $table = 'v2_marketing_rule';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'enabled' => 'boolean',
        'email_enabled' => 'boolean',
        'telegram_enabled' => 'boolean',
        'trigger_config' => 'array',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public const TYPE_TRANSACTIONAL = 'transactional';
    public const TYPE_LIFECYCLE = 'lifecycle';
    public const TYPE_MARKETING = 'marketing';

    public const SCENE_REGISTERED_NO_PURCHASE_1D = 'registered_no_purchase_1d';
    public const SCENE_ORDER_PENDING_UNPAID = 'order_pending_unpaid';
    public const SCENE_PLAN_EXPIRING_3D = 'plan_expiring_3d';
    public const SCENE_PLAN_EXPIRED_1D = 'plan_expired_1d';
    public const SCENE_INACTIVE_7D = 'inactive_7d';

    public function emailTemplate(): BelongsTo
    {
        return $this->belongsTo(MarketingTemplate::class, 'email_template_id', 'id');
    }

    public function telegramTemplate(): BelongsTo
    {
        return $this->belongsTo(MarketingTemplate::class, 'telegram_template_id', 'id');
    }
}
