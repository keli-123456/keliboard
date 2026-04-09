<?php

namespace App\Services;

class MessageOpsSettings
{
    public const SETTING_KEY = 'message_ops_enable';

    public static function enabled(): bool
    {
        try {
            $raw = admin_setting(self::SETTING_KEY, false);
            $parsed = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            return $parsed ?? (bool) $raw;
        } catch (\Throwable) {
            return false;
        }
    }
}
