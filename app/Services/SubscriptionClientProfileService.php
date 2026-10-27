<?php

namespace App\Services;

use Illuminate\Http\Request;

class SubscriptionClientProfileService
{
    public function resolve(Request $request): array
    {
        $flag = strtolower($request->input('flag') ?? $request->header('User-Agent', ''));
        $manager = app('protocols.manager');
        $name = $manager->matchClientFlag($flag);
        $version = $name ? $manager->extractClientVersion($flag, $name) : null;
        if (!$name && preg_match('/([a-zA-Z0-9\-_]+)[\/\s]+(v?[0-9]+(?:\.[0-9]+)*)/', $flag, $matches)) {
            $matched = $manager->matchClientFlag(strtolower($matches[1]));
            if ($matched) {
                $name = $matched;
                $version = preg_replace('/^v/', '', $matches[2]) ?: null;
            }
        }
        if ($name && !$version && preg_match('/(?:^|[^0-9])v?(\d+(?:\.\d+)+)(?=$|[^0-9])/', $flag, $matches)) {
            $version = $matches[1];
        }
        if ($name === 'karing') {
            $version = '1.13.0';
        }
        if ($name === 'sing-box' && !$version) {
            $version = '1.12.0';
        }
        return ['flag' => $flag, 'name' => $name, 'version' => $version];
    }
}
