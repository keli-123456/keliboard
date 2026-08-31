# Xboard 宝塔 + Docker 部署指南

本文适用于下面这种常见生产部署方式：

- 宝塔负责网站、域名和反向代理
- Docker Compose 负责运行 `web`、`horizon`、`ws-server` 和 Redis
- MySQL 由宝塔提供
- 宝塔 Nginx 将请求转发到本机 Docker 服务

## 1. 推荐架构

推荐端口规划：

- 网站服务：`127.0.0.1:7001`
- 节点实时同步 WebSocket：`127.0.0.1:6001`
- 对外访问：
  - `https://你的域名/` -> `127.0.0.1:7001`
  - `wss://你的域名/ws/node` -> `127.0.0.1:6001`

这样 `ws-server` 只监听本机，不直接暴露公网端口。

## 2. 环境要求

### 硬件

- CPU：1 核及以上
- 内存：2 GB 及以上
- 磁盘：10 GB 及以上可用空间

### 软件

- Ubuntu 20.04+ / Debian 10+ / CentOS 7+
- 最新版宝塔面板
- Docker 与 Docker Compose
- 宝塔已安装 Nginx
- MySQL 5.7+ 或兼容 Laravel 12 的 MariaDB

## 3. 安装基础环境

### 3.1 安装宝塔

```bash
curl -sSL https://www.aapanel.com/script/install_6.0.sh -o install_6.0.sh
bash install_6.0.sh aapanel
```

### 3.2 安装 Docker

```bash
curl -sSL https://get.docker.com | bash
systemctl enable docker
systemctl start docker
```

### 3.3 在宝塔安装组件

在宝塔面板中安装：

- Nginx
- MySQL

不需要额外安装：

- 宝塔 PHP
- 宝塔 Redis

## 4. 在宝塔创建站点

在宝塔中：

1. 进入 `网站`
2. 点击 `添加站点`
3. 推荐设置：
   - 域名：填写你的正式域名
   - 数据库：创建或选择 MySQL 数据库
   - PHP 版本：选择 `纯静态`

这里要用纯静态站点，因为 PHP 会在 Docker 容器里跑。

## 5. 部署 Xboard

### 5.1 准备站点目录

```bash
cd /www/wwwroot/你的域名

chattr -i .user.ini 2>/dev/null || true
rm -rf .htaccess 404.html 502.html index.html .user.ini
```

### 5.2 拉取项目

```bash
git clone https://github.com/keli-123456/keliboard.git ./
cp compose.sample.yaml compose.yaml
```

### 5.3 修改 `compose.yaml`

部署前，建议先按宝塔反代场景调整 `compose.yaml`。

推荐配置如下：

```yaml
services:
  web:
    image: ghcr.io/keli-123456/keliboard:main
    volumes:
      - ./.docker/.data/redis/:/data/
      - ./.docker/.data/redis-cache/:/data-cache/
      - ./:/www/
    environment:
      - docker=true
      - REDIS_HOST=/data/redis.sock
      - REDIS_PORT=0
      - REDIS_CACHE_HOST=/data-cache/redis.sock
      - REDIS_CACHE_PORT=0
      - NODE_REALTIME_ENABLED=true
      - NODE_REALTIME_PUBLIC_PORT=443
    depends_on:
      - redis
      - redis-cache
    network_mode: host
    command: php artisan octane:start --port=7001 --host=0.0.0.0
    restart: always

  horizon:
    image: ghcr.io/keli-123456/keliboard:main
    volumes:
      - ./.docker/.data/redis/:/data/
      - ./.docker/.data/redis-cache/:/data-cache/
      - ./:/www/
    restart: always
    network_mode: host
    command: php artisan horizon
    depends_on:
      - redis
      - redis-cache

  ws-server:
    image: ghcr.io/keli-123456/keliboard:main
    volumes:
      - ./.docker/.data/redis/:/data/
      - ./.docker/.data/redis-cache/:/data-cache/
      - ./:/www/
    environment:
      - docker=true
      - REDIS_HOST=/data/redis.sock
      - REDIS_PORT=0
      - REDIS_CACHE_HOST=/data-cache/redis.sock
      - REDIS_CACHE_PORT=0
      - NODE_REALTIME_ENABLED=true
      - NODE_REALTIME_HOST=127.0.0.1
      - NODE_REALTIME_PORT=6001
    restart: always
    network_mode: host
    command: php artisan ws-server start
    depends_on:
      - redis
      - redis-cache
```

### 说明

- `NODE_REALTIME_HOST=127.0.0.1`：只允许本机访问 websocket
- `NODE_REALTIME_PORT=6001`：仅供本机反代使用
- `NODE_REALTIME_PUBLIC_PORT=443`：让节点自动拿到 `wss://域名/ws/node`

如果一台机器上有多个 Xboard 站点：

- 每个站点必须使用不同的 `NODE_REALTIME_PORT`
- 例如：
  - 站点 A：`6001`
  - 站点 B：`6002`
  - 站点 C：`6003`

如果你想强制指定公网 websocket 地址，也可以写：

```yaml
- NODE_REALTIME_PUBLIC_URL=wss://你的域名/ws/node
```

## 6. 初始化安装

```bash
docker compose run -it --rm web sh init.sh
```

安装时请保存：

- 后台路径
- 管理员账号
- 管理员密码

安装完成后启动服务：

```bash
docker compose up -d
```

## 7. 配置宝塔反向代理

在宝塔站点配置里添加下面两段 `location`。

注意：

- `/ws/node` 这一段必须放在主 `/` 规则前面

```nginx
location ^~ /ws/node {
    proxy_pass http://127.0.0.1:6001;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_read_timeout 3600s;
    proxy_send_timeout 3600s;
    proxy_buffering off;
    proxy_cache off;
}

location ^~ / {
    proxy_pass http://127.0.0.1:7001;
    proxy_http_version 1.1;
    proxy_set_header Connection "";
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Real-PORT $remote_port;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header Host $http_host;
    proxy_set_header Scheme $scheme;
    proxy_set_header Server-Protocol $server_protocol;
    proxy_set_header Server-Name $server_name;
    proxy_set_header Server-Addr $server_addr;
    proxy_set_header Server-Port $server_port;
    proxy_cache off;
}
```

保存后执行：

```bash
nginx -t
systemctl reload nginx
```

## 8. 后台推荐设置

登录 Xboard 后，进入：

- `系统设置 -> 节点配置`

推荐值：

- `node_realtime_enable = 开启`
- `node_realtime_path = /ws/node`
- `node_realtime_public_port = 443`

如果你使用单独 websocket 域名，再填写：

- `node_realtime_public_url = wss://你的-ws-域名/ws/node`

## 9. Cloudflare 使用建议

如果域名在 Cloudflare 后面：

- SSL/TLS 模式请使用 `Full` 或 `Full (strict)`
- 不要使用 `Flexible`
- 打开 `WebSockets`
- 确保 `/ws/node` 没被 WAF / Bot 规则拦掉

如果 websocket 超时，先排查本机反代链路，不要一开始就怀疑面板代码。

## 10. 验证清单

### 10.1 检查容器状态

```bash
docker compose ps
docker compose logs --tail=100 ws-server
```

正常应看到类似：

```text
Workerman[artisan] start in DEBUG mode
... websocket://127.0.0.1:6001 ...
Start success.
```

### 10.2 检查本机 websocket 握手

```bash
curl -v --http1.1 \
  -H "Upgrade: websocket" \
  -H "Connection: Upgrade" \
  -H "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==" \
  -H "Sec-WebSocket-Version: 13" \
  "http://127.0.0.1:6001/ws/node"
```

预期结果：

```text
HTTP/1.1 101 Switching Protocol
```

### 10.3 检查经过宝塔反代后的握手

将 `YOUR_SERVER_IP` 换成你的源站 IP：

```bash
curl -ik --resolve 你的域名:443:YOUR_SERVER_IP --http1.1 \
  -H "Upgrade: websocket" \
  -H "Connection: Upgrade" \
  -H "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==" \
  -H "Sec-WebSocket-Version: 13" \
  "https://你的域名/ws/node"
```

预期结果：

```text
HTTP/1.1 101 Switching Protocol
```

## 11. 更新 Xboard

推荐使用仓库自带脚本：

```bash
cd /www/wwwroot/你的域名
sh update.sh
```

这个脚本会自动：

- 锁定目标 Git 提交和容器镜像
- 在独立端口验证候选站点
- 创建并演练数据库备份
- 严格按 `composer.lock` 安装依赖
- 切换服务并检查核心接口和进程
- 失败时恢复原代码和原镜像

执行前可用 `sh update.sh --plan --no-fetch --ref=HEAD` 只查看计划。部署凭证与主动回退见
[安全部署与回滚](../operations/safe-deployment.md)。

## 12. 常用维护命令

```bash
docker compose logs
docker compose logs -f web
docker compose logs -f ws-server
docker compose logs -f horizon
docker compose exec -T web php artisan ws-server status
docker compose exec -T web php artisan ws-server connections
```

## 13. 常见问题

### `ws-server` 容器创建了但没跑起来

检查：

- `compose.yaml` 里是否有 `command: php artisan ws-server start`
- `NODE_REALTIME_PORT` 是否被占用
- 是否保留了 `network_mode: host`

### 节点能拉配置，但 websocket 连不上

检查：

- 宝塔是否加了 `/ws/node` 反代
- `/ws/node` 是否放在主 `/` 规则前面
- Cloudflare 是否不是 `Flexible`
- `node_realtime_public_port` 或 `node_realtime_public_url` 是否正确

### 同一台机器部署多个 Xboard 站点

每个站点必须用不同的本地 websocket 端口，例如：

- 站点 A：`NODE_REALTIME_PORT=6001`
- 站点 B：`NODE_REALTIME_PORT=6002`
- 站点 C：`NODE_REALTIME_PORT=6003`

### 更新后权限有问题

```bash
chown -R www:www /www/wwwroot/你的域名
chmod -R 777 /www/wwwroot/你的域名/.docker/.data
```

只在你的宝塔环境确实使用 `www:www` 时再执行。
