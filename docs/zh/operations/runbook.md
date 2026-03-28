# Xboard 宝塔 + Docker 运维手册

这份文档面向站长或运维，重点是：

- 更新
- 日常检查
- 备份
- `ws-server` / realtime 排障
- 节点接入排障

如果你需要首次部署，请先看：

- [宝塔 + Docker 快速部署（中文）](../installation/aapanel-docker-quickstart.md)
- [宝塔 + Docker 部署（完整）](../installation/aapanel-docker.md)

## 1. 服务结构

典型的宝塔 + Docker 部署包含这些容器：

- `web`
- `horizon`
- `ws-server`
- `redis`
- `redis-cache`

常见本地端口：

- `7001`：网站服务
- `6001`：节点 realtime websocket

## 2. 常用命令

站点目录：

```bash
cd /www/wwwroot/你的域名
```

查看容器：

```bash
docker compose ps
```

查看日志：

```bash
docker compose logs
docker compose logs -f web
docker compose logs -f ws-server
docker compose logs -f horizon
```

查看 realtime 状态：

```bash
docker compose exec -T web php artisan ws-server status
docker compose exec -T web php artisan ws-server connections
```

## 3. 更新流程

推荐统一使用：

```bash
cd /www/wwwroot/你的域名
sh update.sh
```

这个脚本会自动完成：

- Git 更新
- Composer 依赖更新
- `php artisan xboard:update`
- 容器拉起
- `config:clear/config:cache`
- `horizon` 重启

更新后建议检查：

```bash
docker compose ps
docker compose logs --tail=100 web
docker compose logs --tail=100 ws-server
```

## 4. 备份建议

至少备份：

- MySQL 数据库
- 站点代码目录
- `.docker/.data`
- `compose.yaml`

推荐最小备份项：

```bash
tar -czf xboard-backup-$(date +%F).tar.gz \
  compose.yaml \
  .env \
  config \
  plugins \
  public/assets/admin-xboard
```

数据库请使用宝塔自带备份，或单独导出。

## 5. ws-server / Realtime 日常检查

### 5.1 检查 websocket 服务是否正常

```bash
docker compose logs --tail=100 ws-server
```

正常应看到：

```text
Workerman[artisan] start in DEBUG mode
... websocket://127.0.0.1:6001 ...
Start success.
```

### 5.2 检查本机握手

```bash
curl -v --http1.1 \
  -H "Upgrade: websocket" \
  -H "Connection: Upgrade" \
  -H "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==" \
  -H "Sec-WebSocket-Version: 13" \
  "http://127.0.0.1:6001/ws/node"
```

正常应返回：

```text
HTTP/1.1 101 Switching Protocol
```

### 5.3 检查经过宝塔反代后的握手

```bash
curl -ik --resolve 你的域名:443:源站IP --http1.1 \
  -H "Upgrade: websocket" \
  -H "Connection: Upgrade" \
  -H "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==" \
  -H "Sec-WebSocket-Version: 13" \
  "https://你的域名/ws/node"
```

正常也应返回 `101 Switching Protocol`。

## 6. 后台侧日常检查

优先看这两个地方：

- 仪表盘里的 `实时同步状态`
- 节点列表里的 `Realtime` 状态列

重点关注：

- `ws-server` 是否运行
- 已认证连接数
- 未完成认证的活跃节点
- 最近 `config/users` 回执是否失败

## 7. 节点接入排障

### 7.1 节点日志里看什么

正常接入会看到：

```text
Realtime websocket connected
Realtime websocket authenticated
Realtime invalidate received
```

### 7.2 常见异常

#### `no such host`

说明节点机器 DNS 解析不到面板域名。优先处理：

- 节点机 DNS
- Docker `--dns`
- 域名解析记录

#### `x509: certificate signed by unknown authority`

通常是节点直连源站，但源站证书不是公网受信任证书。

处理方向：

- 使用公网受信任证书
- 或改回正确的 Cloudflare 代理链路

#### `i/o timeout`

优先检查：

- 宝塔 `/ws/node` 反代是否正确
- Cloudflare 是否不是 `Flexible`
- `ws-server` 是否真的监听本机端口

## 8. Cloudflare 使用规范

如果走 Cloudflare：

- SSL/TLS 用 `Full` 或 `Full (strict)`
- 不要用 `Flexible`
- 打开 `WebSockets`
- 确保 `/ws/node` 没被 WAF / Bot / Rate Limit 拦截

一旦 websocket 连接异常，先验证：

1. 本机 `127.0.0.1:6001`
2. 源站 `https://域名/ws/node`
3. 再看 Cloudflare 层

## 9. 多站点同机注意事项

同一台机器部署多个 Xboard 站点时：

- 每个站点必须使用不同的 `NODE_REALTIME_PORT`
- 不建议把 `V2NODE_HEALTH_PORT` 做成统一全局默认值
- 每个站点的反代、证书和 websocket 地址都要单独核对

推荐：

- 站点 A：`6001`
- 站点 B：`6002`
- 站点 C：`6003`

## 10. 推荐巡检清单

每天或每次更新后，至少检查：

- `docker compose ps`
- `docker compose logs --tail=100 ws-server`
- 后台 `实时同步状态`
- 节点列表 `Realtime` 列
- 抽查 1 台节点是否能实时收到一次用户或配置变更

## 11. 迁移场景

如果你从旧面板迁移：

- `wyx2685/v2board`：看 [迁移文档](../migration/v2board-wyx2685.md)
- 配置迁移：看 [配置迁移文档](../migration/config.md)

## 12. 问题收敛顺序

出现问题时，建议按这个顺序排：

1. 容器是否正常运行
2. 本机端口是否正常监听
3. 宝塔反代是否正确
4. Cloudflare 是否干预
5. 节点日志是否已连上并认证
6. 后台是否显示实时连接和回执

这样排障会比直接改代码快很多。
