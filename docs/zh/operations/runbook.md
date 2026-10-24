# Xboard 宝塔 + Docker 运维手册

这份文档面向站长或运维，重点是：

- 更新
- 日常检查
- 备份
- `ws-server` / realtime 排障
- 节点接入排障
- [支付方式收款目标与开放时段](payment-collection.md)

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

### 内置插件目录

随系统发布的插件代码位于 `plugins-core/`，管理员上传的扩展仍位于 `plugins/`。
数据库中的插件配置、安装和启用状态不会因目录调整而重置。
内置插件可按需禁用或卸载，但不能在后台删除其代码，也不能上传同名 ZIP 覆盖；安全补丁随系统版本更新。
旧的 `plugins/` 同名副本不会被自动删除，加载时优先使用内置目录。存在本地修改时应先备份和核对，不要手工清空用户插件目录。

目录调整后必须通过正常更新流程重新生成 Composer 自动加载文件，并重启常驻的 `web`、`horizon` 和 `ws-server`。
只拉取代码、不更新自动加载或不切换常驻进程，不能视为插件升级完成。

支付安全校验会拒绝空密钥、错误商户/应用身份和异常回调参数。发布前应在测试环境验证实际使用渠道的签名回调；不要为通过回调而关闭校验。

### 发布前资金一致性验收

CI 的 MySQL 验收使用临时 MySQL 8.4 服务、仅有测试库权限的专用账号和回环端口，分别检查 `REPEATABLE READ` 与 `READ COMMITTED`。
测试夹具拒绝正式数据库地址、root 账号和未明确启用的环境；仅在空测试库创建带所有权标记的数据表，结束后校验标记再清理。
多个独立 PHP 进程先加载旧快照，再由同一个起跑屏障进入真实 `redeem()`；测试专用持锁延迟用于制造可观察的重叠锁等待，不进入业务代码。
验收覆盖兑换上限、代理扣费与账本、扣费后发奖失败回滚、同用户多卡、站点隔离，以及模拟签名 HTTP 支付回调的金额、身份和重复入账保护。
这里的锁等待耗时不是业务性能指标，模拟回调也不等于实际支付平台沙箱或真实收款验证。

手动触发镜像构建时，`candidate_only` 默认开启，只验证不可变候选镜像，不更新正式 `main`、`latest` 等标签，也不部署线上。

推荐统一使用：

```bash
cd /www/wwwroot/你的域名
sh update.sh
```

这个脚本现在使用安全部署事务：锁定目标代码和镜像、启动独立候选站点、验证数据库备份、按
`composer.lock` 安装依赖、切换服务并执行健康检查。任意关键步骤失败会恢复原代码和原镜像，
完整过程保存在部署凭证中。

先预览而不修改任何内容：

```bash
sh update.sh --plan --no-fetch --ref=HEAD
```

完整门禁、正式发布清单和主动回退方法见[安全部署与回滚](safe-deployment.md)。

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

## 13. 主题上传后仍显示旧页面

主题更新与面板程序更新是两件事。上传更高版本的主题包后，不需要再切换一次主题。

当前实现：

- `ThemeService` 上传成功前同步 `public/theme/<主题名>`，并清理该主题的 Blade 编译缓存。
- 更新现有主题保留已保存的主题配置，不改变当前主题选择，不清理登录或业务缓存。
- 用户首页返回 `private, no-store, no-cache`，避免入口 HTML 继续引用旧资源。
- 旧主题使用的 `?v=` 参数跟随主题配置文件变化，不再只跟随面板程序版本。
- 新构建的 `keli-user` 主题包为 `umi.js` 和 `umi.css` 写入内容指纹；仍是原来的单文件脚本构建，不增加动态分包。

部署后正常重新打开或刷新页面即可加载新主题。已打开的页面不会被强制刷新，避免打断填写工单或支付操作。只上传新主题包可获得资源指纹；首页响应头和上传缓存清理需要同步更新 `keliboard`。

如果站点经过 CDN 或自建反向代理，还需要保证入口 HTML 不被强制缓存、静态资源缓存键包含 `v` 查询参数。首次应用修复时，外部缓存中已经保存的旧 HTML 不会被源站代码主动删除；必要时只清理受影响域名的旧首页缓存。无需清空 Redis 或让用户退出登录。

排查时先看首页响应头是否包含 `Cache-Control: ... no-store ...`，再对比更新前后 `umi.js?v=...` 与 `umi.css?v=...` 是否变化。如果源站已更新而域名仍是旧页面，检查该域名的反代/CDN 缓存规则。
