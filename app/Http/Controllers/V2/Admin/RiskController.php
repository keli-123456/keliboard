<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RiskController extends Controller
{
    private const MAX_DAYS = 90;
    private const DEFAULT_DAYS = 30;
    private const DEFAULT_MIN_USERS = 3;

    private function getSinceTs(Request $request): int
    {
        $days = (int) $request->input('days', self::DEFAULT_DAYS);
        $days = max(1, min(self::MAX_DAYS, $days));
        return time() - ($days * 86400);
    }

    private function getEventTypes(Request $request): array
    {
        $raw = $request->input('event_types');
        $types = [];

        if (is_string($raw) && trim($raw) !== '') {
            $types = preg_split('/[|,\\s]+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        } elseif (is_array($raw)) {
            $types = $raw;
        }

        $types = array_values(array_filter(array_map(function ($t) {
            $t = is_string($t) || is_numeric($t) ? trim((string) $t) : '';
            return $t !== '' ? $t : null;
        }, $types)));

        if (!$types) {
            return ['subscribe'];
        }

        // Whitelist known event types (future types can be added here).
        $allowed = ['subscribe', 'login_success', 'login_failed'];
        $types = array_values(array_intersect($types, $allowed));
        return $types ?: ['subscribe'];
    }

    private function ensureTableReady()
    {
        if (Schema::hasTable('v2_risk_event')) {
            return null;
        }
        return $this->fail([500000, '风控事件表不存在，请先执行数据库迁移（php artisan migrate）']);
    }

    public function ipSummary(Request $request)
    {
        if ($resp = $this->ensureTableReady()) {
            return response(['data' => [], 'total' => 0]);
        }

        $since = $this->getSinceTs($request);
        $eventTypes = $this->getEventTypes($request);

        $minUsers = (int) $request->input('min_users', self::DEFAULT_MIN_USERS);
        $minUsers = max(1, min(1000, $minUsers));

        $q = trim((string) $request->input('q', ''));

        $current = (int) $request->input('current', 1);
        $pageSize = (int) $request->input('pageSize', 20);
        $current = max(1, $current);
        $pageSize = max(1, min(200, $pageSize));

        $builder = DB::table('v2_risk_event')
            ->where('created_at', '>=', $since)
            ->whereIn('event_type', $eventTypes)
            ->whereNotNull('ip')
            ->where('ip', '<>', '')
            ->selectRaw('ip, COUNT(*) AS event_count, COUNT(DISTINCT user_id) AS user_count, COUNT(DISTINCT ua_hash) AS ua_count, MAX(created_at) AS last_seen')
            ->groupBy('ip')
            ->having('user_count', '>=', $minUsers);

        if ($q !== '') {
            $builder->where('ip', 'like', '%' . $q . '%');
        }

        $total = DB::query()->fromSub(clone $builder, 't')->count();
        $rows = $builder
            ->orderByDesc('user_count')
            ->orderByDesc('last_seen')
            ->forPage($current, $pageSize)
            ->get();

        return response([
            'data' => $rows,
            'total' => $total,
        ]);
    }

    public function ipDetail(Request $request)
    {
        if ($resp = $this->ensureTableReady()) {
            return $resp;
        }

        $request->validate([
            'ip' => 'required|string|max:64',
        ], [
            'ip.required' => 'IP不能为空',
        ]);

        $ip = trim((string) $request->input('ip'));
        $since = $this->getSinceTs($request);
        $eventTypes = $this->getEventTypes($request);

        $users = DB::table('v2_risk_event as e')
            ->join('v2_user as u', 'u.id', '=', 'e.user_id')
            ->where('e.created_at', '>=', $since)
            ->whereIn('e.event_type', $eventTypes)
            ->where('e.ip', '=', $ip)
            ->selectRaw('u.id as user_id, u.email, u.is_admin, u.banned, u.plan_id, COUNT(*) as event_count, COUNT(DISTINCT e.ua_hash) as ua_count, MAX(e.created_at) as last_seen')
            ->groupBy('u.id', 'u.email', 'u.is_admin', 'u.banned', 'u.plan_id')
            ->orderByDesc('event_count')
            ->orderByDesc('last_seen')
            ->get();

        $uas = DB::table('v2_risk_event as e')
            ->where('e.created_at', '>=', $since)
            ->whereIn('e.event_type', $eventTypes)
            ->where('e.ip', '=', $ip)
            ->whereNotNull('e.ua_hash')
            ->where('e.ua_hash', '<>', '')
            ->selectRaw('e.ua_hash, MAX(e.ua) as ua, COUNT(*) as event_count, MAX(e.created_at) as last_seen')
            ->groupBy('e.ua_hash')
            ->orderByDesc('event_count')
            ->orderByDesc('last_seen')
            ->limit(30)
            ->get();

        $clients = DB::table('v2_risk_event as e')
            ->where('e.created_at', '>=', $since)
            ->whereIn('e.event_type', $eventTypes)
            ->where('e.ip', '=', $ip)
            ->whereNotNull('e.client_name')
            ->where('e.client_name', '<>', '')
            ->selectRaw('e.client_name, e.client_version, COUNT(*) as event_count, MAX(e.created_at) as last_seen')
            ->groupBy('e.client_name', 'e.client_version')
            ->orderByDesc('event_count')
            ->orderByDesc('last_seen')
            ->limit(30)
            ->get();

        return $this->success([
            'ip' => $ip,
            'since' => $since,
            'event_types' => $eventTypes,
            'users' => $users,
            'uas' => $uas,
            'clients' => $clients,
        ]);
    }

    public function userDetail(Request $request)
    {
        if ($resp = $this->ensureTableReady()) {
            return $resp;
        }

        $request->validate([
            'user_id' => 'required|integer|min:1',
        ], [
            'user_id.required' => '用户ID不能为空',
        ]);

        $userId = (int) $request->input('user_id');
        $user = User::query()
            ->select(['id', 'email', 'is_admin', 'banned', 'plan_id', 'expired_at', 'last_login_at', 'created_at'])
            ->find($userId);
        if (!$user) {
            return $this->fail([400202, '用户不存在']);
        }

        $since = $this->getSinceTs($request);
        $eventTypes = $this->getEventTypes($request);

        $ips = DB::table('v2_risk_event')
            ->where('created_at', '>=', $since)
            ->whereIn('event_type', $eventTypes)
            ->where('user_id', '=', $userId)
            ->whereNotNull('ip')
            ->where('ip', '<>', '')
            ->selectRaw('ip, COUNT(*) as event_count, COUNT(DISTINCT ua_hash) as ua_count, MAX(created_at) as last_seen')
            ->groupBy('ip')
            ->orderByDesc('last_seen')
            ->limit(200)
            ->get();

        $uas = DB::table('v2_risk_event')
            ->where('created_at', '>=', $since)
            ->whereIn('event_type', $eventTypes)
            ->where('user_id', '=', $userId)
            ->whereNotNull('ua_hash')
            ->where('ua_hash', '<>', '')
            ->selectRaw('ua_hash, MAX(ua) as ua, COUNT(*) as event_count, MAX(created_at) as last_seen')
            ->groupBy('ua_hash')
            ->orderByDesc('event_count')
            ->orderByDesc('last_seen')
            ->limit(60)
            ->get();

        $events = DB::table('v2_risk_event')
            ->where('created_at', '>=', $since)
            ->whereIn('event_type', $eventTypes)
            ->where('user_id', '=', $userId)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get([
                'id',
                'event_type',
                'ip',
                'ua',
                'client_name',
                'client_version',
                'route',
                'status_code',
                'meta',
                'created_at',
            ]);

        return $this->success([
            'user' => $user,
            'since' => $since,
            'event_types' => $eventTypes,
            'ips' => $ips,
            'uas' => $uas,
            'events' => $events,
        ]);
    }
}
