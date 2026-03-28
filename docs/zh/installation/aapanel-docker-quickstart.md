# Xboard 宝塔 + Docker 快速部署

这份文档只保留最短路径，适合你自己或熟悉宝塔的用户快速上线。

如果你需要完整说明、Cloudflare、`ws-server`、排障细节，请看：

- [宝塔 + Docker 部署（完整）](./aapanel-docker.md)

## 1. 先决条件

准备好：

- 一台已安装宝塔的 Linux 服务器
- 宝塔已安装 `Nginx` 和 `MySQL`
- 域名已解析到服务器

不需要：

- 宝塔 PHP
- 宝塔 Redis

## 2. 安装 Docker

```bash
curl -sSL https://get.docker.com | bash
systemctl enable docker
systemctl start docker
```

## 3. 创建站点

在宝塔里：

1. `网站 -> 添加站点`
2. 域名填你的正式域名
3. 数据库选择 MySQL
4. PHP 版本选择 `纯静态`

## 4. 拉取项目

```bash
cd /www/wwwroot/你的域名

chattr -i .user.ini 2>/dev/null || true
rm -rf .htaccess 404.html 502.html index.html .user.ini

git clone https://github.com/keli-123456/keliboard.git ./
cp compose.sample.yaml compose.yaml
```

## 5. 修改 `compose.yaml`

至少改这 2 个点：

1. `ws-server` 本机监听
2. 面板 websocket 公网端口按 `443` 生成

参考配置：

```yaml
services:
  web:
    environment:
      - docker=true
      - REDIS_HOST=/data/redis.sock
      - REDIS_PORT=0
      - REDIS_CACHE_HOST=/data-cache/redis.sock
      - REDIS_CACHE_PORT=0
      - NODE_REALTIME_ENABLED=true
      - NODE_REALTIME_PUBLIC_PORT=443
    network_mode: host
    command: php artisan octane:start --port=7001 --host=0.0.0.0

  ws-server:
    environment:
      - docker=true
      - REDIS_HOST=/data/redis.sock
      - REDIS_PORT=0
      - REDIS_CACHE_HOST=/data-cache/redis.sock
      - REDIS_CACHE_PORT=0
      - NODE_REALTIME_ENABLED=true
      - NODE_REALTIME_HOST=127.0.0.1
      - NODE_REALTIME_PORT=6001
    network_mode: host
    command: php artisan ws-server start
```

注意：

- 如果同一台机器有多个 Xboard 站点，每个站点的 `NODE_REALTIME_PORT` 必须不同。

## 6. 初始化安装

```bash
docker compose run -it --rm web sh init.sh
docker compose up -d
```

安装时记下：

- 后台地址
- 管理员账号
- 管理员密码

## 7. 配置宝塔反代

把下面两段加到宝塔站点配置里，`/ws/node` 必须放前面：

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

然后执行：

```bash
nginx -t
systemctl reload nginx
```

## 8. 后台推荐设置

进入：

- `系统设置 -> 节点配置`

推荐：

- `node_realtime_enable = 开启`
- `node_realtime_path = /ws/node`
- `node_realtime_public_port = 443`

## 9. 快速验证

检查容器：

```bash
docker compose ps
docker compose logs --tail=100 ws-server
```

检查本机 websocket：

```bash
curl -v --http1.1 \
  -H "Upgrade: websocket" \
  -H "Connection: Upgrade" \
  -H "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==" \
  -H "Sec-WebSocket-Version: 13" \
  "http://127.0.0.1:6001/ws/node"
```

出现下面这行就对了：

```text
HTTP/1.1 101 Switching Protocol
```

## 10. 更新

```bash
cd /www/wwwroot/你的域名
sh update.sh
```

## 11. 最常见问题

### `ws-server` 起不来

通常是：

- `NODE_REALTIME_PORT` 被占用
- `compose.yaml` 里没写 `command: php artisan ws-server start`

### 节点 websocket 连不上

通常是：

- 没配 `/ws/node` 反代
- `/ws/node` 写在主 `/` 规则后面
- Cloudflare 用了 `Flexible`

需要完整排障时，回到：

- [宝塔 + Docker 部署（完整）](./aapanel-docker.md)
