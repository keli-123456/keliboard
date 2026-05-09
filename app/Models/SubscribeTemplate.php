<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class SubscribeTemplate extends Model
{
    protected $table = 'v2_subscribe_templates';
    protected $guarded = [];
    protected $casts = [
        'name' => 'string',
        'content' => 'string',
    ];

    private static string $cachePrefix = 'subscribe_template:';
    private static ?bool $tableExists = null;

    private static function hasStorageTable(): bool
    {
        if (self::$tableExists !== null) {
            return self::$tableExists;
        }

        try {
            return self::$tableExists = Schema::hasTable((new static())->getTable());
        } catch (\Throwable) {
            return self::$tableExists = false;
        }
    }

    public static function getContent(string $name): ?string
    {
        if (!self::hasStorageTable()) {
            return null;
        }

        return Cache::remember(self::$cachePrefix . $name, 3600, function () use ($name) {
            return self::query()->where('name', $name)->value('content');
        });
    }

    public static function setContent(string $name, ?string $content): void
    {
        if (self::hasStorageTable()) {
            self::query()->updateOrCreate(
                ['name' => $name],
                ['content' => $content]
            );
            Cache::forget(self::$cachePrefix . $name);
        }

        if (function_exists('admin_setting')) {
            admin_setting(['subscribe_template_' . $name => $content]);
        }
    }
}
