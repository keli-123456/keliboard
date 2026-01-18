# Cloudmanger 控制面板 API（Xboardpro 插件）

该插件在 Xboardpro 内提供一套**独立**的 API（不改动原有 `/api/v1`/`/api/v2`），用于：

- 为 `dns_redis` / `dns_redis_web` 下发配置（每个用户一套）
- 为 worker 颁发/吊销访问 token（基于 Sanctum Personal Access Token）

API 前缀：`/api/cm/v1`

## 安装 / 启用

1. 进入 Xboardpro 后台 → 插件管理
2. 找到 `cloudmanger` → 安装 → 启用

安装时会创建表：`cm_worker_configs`（用于存储每个用户的 worker 配置，配置内容会加密存库）。

## 设计规律（建议）

- **一套配置 = 一个用户 + 一个 worker**：用 `user_id + worker` 唯一定位一份配置，后续要扩展更多 worker 也不需要改表结构。
- **worker 不需要绑定域名模型**：域名仅作为配置参数（例如 `domain`），让 worker 运行时按配置执行即可。
- **worker 通过 token 拉取配置**：worker 不需要知道 `user_id`，token 绑定到用户；接口根据 token 自动找到对应用户的配置。

## API

### 管理端（管理员权限）

- `GET /api/cm/v1/admin/me`（获取当前管理员信息，含 `id`）
- `GET /api/cm/v1/admin/users/{userId}/worker-configs`
- `GET /api/cm/v1/admin/users/{userId}/worker-configs/{worker}`
- `PUT /api/cm/v1/admin/users/{userId}/worker-configs/{worker}`（body: `{ "config": {...}, "note": "..." }`）
- `DELETE /api/cm/v1/admin/users/{userId}/worker-configs/{worker}`
- `GET /api/cm/v1/admin/users/{userId}/worker-scripts/{worker}`
- `GET /api/cm/v1/admin/users/{userId}/worker-scripts/{worker}/{scriptId}`
- `PUT /api/cm/v1/admin/users/{userId}/worker-scripts/{worker}/{scriptId}`（body: `{ "content": "#!/bin/bash\\n...", "note": "..." }`）
- `DELETE /api/cm/v1/admin/users/{userId}/worker-scripts/{worker}/{scriptId}`
- `GET /api/cm/v1/admin/users/{userId}/tokens`
- `POST /api/cm/v1/admin/users/{userId}/tokens`（body: `{ "worker": "dns_redis_web", "expires_at": 1730000000 }`）
- `DELETE /api/cm/v1/admin/users/{userId}/tokens/{tokenId}`

### Worker 拉取（需要 Bearer Token）

- `GET /api/cm/v1/worker-configs/{worker}/rendered`
- `GET /api/cm/v1/worker-scripts/{worker}`（返回该 worker 的脚本集合）
- `GET /api/cm/v1/worker-scripts/{worker}/{scriptId}`（返回单个脚本）

token 规则：
- token 名称固定为 `cm-worker:{worker}` 或 `cm-worker:*`（`*` 表示可拉取任意 worker）
- `rendered` 接口会校验 token 名称与 URL 中的 `{worker}` 是否匹配

## 使用示例（dns_redis_web）

1) 管理端写入配置（示例以 `userId=1`）：

```bash
curl -X PUT "https://YOUR-PANEL/api/cm/v1/admin/users/1/worker-configs/dns_redis_web" \
  -H "Authorization: Bearer YOUR_ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d @- <<'JSON'
{
  "note": "prod",
  "config": {
    "mysql_host": "127.0.0.1",
    "mysql_port": 3306,
    "aliyun_access_key": "YOUR_ALIYUN_ACCESS_KEY",
    "aliyun_access_secret": "YOUR_ALIYUN_ACCESS_SECRET",
    "domain": "example.com"
  }
}
JSON
```

2) 生成 worker token：

```bash
curl -X POST "https://YOUR-PANEL/api/cm/v1/admin/users/1/tokens" \
  -H "Authorization: Bearer YOUR_ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"worker":"dns_redis_web"}'
```

返回里会包含 `auth_data`（形如 `Bearer xxxxxx`）和 `token`（不带 `Bearer ` 前缀）。

3) worker 拉取配置并运行（以 `dns_redis_web` 为例）：

```bash
curl -fsSL "https://YOUR-PANEL/api/cm/v1/worker-configs/dns_redis_web/rendered" \
  -H "Authorization: Bearer YOUR_WORKER_TOKEN" \
  -o config.json

./dns_redis_web --config config.json
```

## 脚本存储（建议）

`dns_redis_web` 默认按子域名前缀寻找脚本，例如子域名 `sg1.example.com` 对应脚本 `script_id=sg1`。

建议把脚本存入数据库（`cm_worker_scripts`），由 worker 通过 API 拉取（避免依赖本地脚本文件）：

- 管理端写入：`PUT /api/cm/v1/admin/users/{userId}/worker-scripts/dns_redis_web/sg1`
- worker 拉取：`GET /api/cm/v1/worker-scripts/dns_redis_web/sg1` 或批量 `GET /api/cm/v1/worker-scripts/dns_redis_web`
