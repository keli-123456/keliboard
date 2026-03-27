<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\ServerRoute;
use App\Services\NodeRealtime\NodeRealtimePublisher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class RouteController extends Controller
{
    private const ROUTE_ACTIONS = [
        'block',
        'block_ip',
        'block_port',
        'protocol',
        'dns',
        'route',
        'route_ip',
        'default_out',
    ];

    private const ACTIONS_REQUIRE_VALUE = [
        'dns',
        'route',
        'route_ip',
        'default_out',
    ];

    public function fetch(Request $request)
    {
        $routes = ServerRoute::get();
        return [
            'data' => $routes
        ];
    }

    public function save(Request $request)
    {
        $params = $request->validate([
            'remarks' => 'required|string',
            'match' => 'array|required_unless:action,default_out',
            'match.*' => 'string',
            'action' => 'required|in:' . implode(',', self::ROUTE_ACTIONS),
            'action_value' => 'nullable|string|required_if:action,' . implode(',', self::ACTIONS_REQUIRE_VALUE),
        ], [
            'remarks.required' => '备注不能为空',
            'match.required_unless' => '匹配值不能为空',
            'action.required' => '动作类型不能为空',
            'action.in' => '动作类型参数有误',
            'action_value.required_if' => '动作值不能为空',
        ]);

        $action = $params['action'] ?? '';
        if ($action === 'default_out') {
            $params['match'] = [];
        } else {
            $params['match'] = array_values(array_filter((array) ($params['match'] ?? [])));
        }

        $params['action_value'] = isset($params['action_value']) && is_string($params['action_value']) && trim($params['action_value']) === ''
            ? null
            : ($params['action_value'] ?? null);
        // TODO: remove on 1.8.0
        if ($request->input('id')) {
            try {
                $route = ServerRoute::find($request->input('id'));
                if (!$route) {
                    throw new ApiException('路由不存在');
                }
                $route->update($params);
                app(NodeRealtimePublisher::class)->invalidateConfigForRoutes([(int) $route->id], 'admin.server_route.saved');
                return $this->success(true);
            } catch (\Exception $e) {
                Log::error($e);
                return $this->fail([500,'保存失败']);
            }
        }
        try{
            ServerRoute::create($params);
            return $this->success(true);
        }catch(\Exception $e){
            Log::error($e);
            return $this->fail([500,'创建失败']);
        }
    }

    public function drop(Request $request)
    {
        $route = ServerRoute::find($request->input('id'));
        if (!$route) throw new ApiException('路由不存在');
        $routeId = (int) $route->id;
        if (!$route->delete()) throw new ApiException('删除失败');
        app(NodeRealtimePublisher::class)->invalidateConfigForRoutes([$routeId], 'admin.server_route.deleted');
        return [
            'data' => true
        ];
    }
}
