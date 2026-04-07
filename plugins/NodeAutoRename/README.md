# 节点自动命名

根据节点协议、当前解析 IP 所属国家和节点 ID，定时自动更新 `v2_server.name`。

`trojan` 节点会优先使用 TLS 的 `server_name` 作为命名和国家识别来源；未配置时才回退到节点地址 `host`。

默认命名模板：

```text
{protocol}-{country}-{node_id}
```

支持占位符：

- `{protocol}`
- `{country}`
- `{node_id}`
- `{id}`
- `{code}`
- `{host}`
- `{ip}`
- `{runtime}`

## 可选过滤

插件支持通过 `include_types` 只处理指定协议类型，例如：

```text
vmess,vless,trojan
```

## 工作方式

1. 定时扫描节点表 `v2_server`
2. 解析节点 `host` 的当前 IP
3. 使用 `Ip2Region` 识别 IPv4 所属国家
4. 名称变化时自动更新节点名
5. 更新后触发节点配置失效通知

## 配置建议

- 如果你希望始终由插件接管命名，保持 `覆盖现有名称 = 开`
- 如果你的节点经常因为域名解析变更国家，建议扫描间隔设为 `5` 或 `10`
- 如果节点大量使用 IPv6，建议先开启“国家未知时也改名”再观察效果

## 手动执行

```bash
php artisan node:auto-rename
php artisan node:auto-rename --dry-run
php artisan node:auto-rename --server-id=12
```

## 管理员立即执行接口

```text
POST /api/v1/node-auto-rename/run
```

请求体示例：

```json
{
  "dry_run": true,
  "force": false,
  "server_id": 12
}
```

该接口要求管理员登录态。
