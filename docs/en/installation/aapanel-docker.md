# Xboard Deployment Guide for aaPanel + Docker

This guide is for the common production setup:

- aaPanel manages the website and reverse proxy
- Docker Compose runs `web`, `horizon`, `ws-server`, and Redis
- MySQL is provided by aaPanel
- Nginx in aaPanel forwards traffic to local Docker services

## 1. Recommended Architecture

For aaPanel + Docker, the recommended layout is:

- Website traffic: `127.0.0.1:7001`
- Node realtime websocket: `127.0.0.1:6001`
- Public access:
  - `https://your-domain/` -> `127.0.0.1:7001`
  - `wss://your-domain/ws/node` -> `127.0.0.1:6001`

This keeps the websocket service off the public internet while still allowing nodes to connect through the same domain.

## 2. Requirements

### Hardware

- CPU: 1 core or above
- Memory: 2 GB or above
- Storage: 10 GB or above

### Software

- Ubuntu 20.04+ / Debian 10+ / CentOS 7+
- Latest aaPanel
- Docker + Docker Compose
- Nginx installed in aaPanel
- MySQL 5.7+ or MariaDB compatible with Laravel 12

## 3. Install Base Environment

### 3.1 Install aaPanel

```bash
curl -sSL https://www.aapanel.com/script/install_6.0_en.sh -o install_6.0_en.sh
bash install_6.0_en.sh aapanel
```

### 3.2 Install Docker

```bash
curl -sSL https://get.docker.com | bash
systemctl enable docker
systemctl start docker
```

### 3.3 Install Required Components in aaPanel

Install these from the aaPanel dashboard:

- Nginx
- MySQL

You do not need aaPanel PHP or aaPanel Redis for this deployment.

## 4. Create the Website in aaPanel

In aaPanel:

1. Go to `Website`
2. Click `Add Site`
3. Use these settings:
   - Domain: your production domain
   - Database: create/select your MySQL database
   - PHP version: `Pure Static`

The site should be created as a static site because PHP is handled inside Docker.

## 5. Deploy Xboard

### 5.1 Prepare the Site Directory

```bash
cd /www/wwwroot/your-domain

chattr -i .user.ini 2>/dev/null || true
rm -rf .htaccess 404.html 502.html index.html .user.ini
```

### 5.2 Clone the Project

```bash
git clone https://github.com/keli-123456/keliboard.git ./
cp compose.sample.yaml compose.yaml
```

### 5.3 Edit `compose.yaml`

Before installation, adjust `compose.yaml` for aaPanel reverse proxy usage.

Recommended configuration:

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

### Important Notes

- `NODE_REALTIME_HOST=127.0.0.1` keeps websocket local to the server.
- `NODE_REALTIME_PORT=6001` is only for local reverse proxy use.
- If you host multiple Xboard sites on the same machine, each site must use a different `NODE_REALTIME_PORT`.
- `NODE_REALTIME_PUBLIC_PORT=443` allows nodes to derive `wss://your-domain/ws/node` without exposing the local websocket port.

If you want to force a fixed websocket URL, you can use:

```yaml
- NODE_REALTIME_PUBLIC_URL=wss://your-domain/ws/node
```

## 6. Install Xboard

Run the installer inside Docker:

```bash
docker compose run -it --rm web sh init.sh
```

Save the admin URL, account, and password shown during installation.

Then start all services:

```bash
docker compose up -d
```

## 7. Configure Reverse Proxy in aaPanel

In the aaPanel site config, add these `location` blocks.

The websocket block must be placed before the main `/` block.

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

After updating the config:

```bash
nginx -t
systemctl reload nginx
```

## 8. Recommended Realtime Settings

After logging into Xboard, go to:

- `System Settings -> Node Config`

Recommended values:

- `node_realtime_enable = on`
- `node_realtime_path = /ws/node`
- `node_realtime_public_port = 443`

If you use a separate websocket domain, set:

- `node_realtime_public_url = wss://your-ws-domain/ws/node`

## 9. Cloudflare Notes

If your site is behind Cloudflare:

- Set SSL/TLS mode to `Full` or `Full (strict)`
- Do not use `Flexible`
- Enable `WebSockets`
- Make sure `/ws/node` is not blocked by WAF or bot rules

If websocket handshake times out, first verify the local reverse proxy path before checking Cloudflare.

## 10. Verification Checklist

### 10.1 Service Status

```bash
docker compose ps
docker compose logs --tail=100 ws-server
```

Expected websocket startup log:

```text
Workerman[artisan] start in DEBUG mode
... websocket://127.0.0.1:6001 ...
Start success.
```

### 10.2 Local Websocket Handshake

```bash
curl -v --http1.1 \
  -H "Upgrade: websocket" \
  -H "Connection: Upgrade" \
  -H "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==" \
  -H "Sec-WebSocket-Version: 13" \
  "http://127.0.0.1:6001/ws/node"
```

Expected result:

```text
HTTP/1.1 101 Switching Protocol
```

### 10.3 Through aaPanel Reverse Proxy

Replace `YOUR_SERVER_IP` with the origin server IP:

```bash
curl -ik --resolve your-domain:443:YOUR_SERVER_IP --http1.1 \
  -H "Upgrade: websocket" \
  -H "Connection: Upgrade" \
  -H "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==" \
  -H "Sec-WebSocket-Version: 13" \
  "https://your-domain/ws/node"
```

Expected result:

```text
HTTP/1.1 101 Switching Protocol
```

## 11. Updating Xboard

Use the repository update script:

```bash
cd /www/wwwroot/your-domain
sh update.sh
```

This script will:

- pin the target Git commit and container image
- validate the candidate on an isolated port
- create and drill a database backup
- install dependencies strictly from `composer.lock`
- cut over services and check core HTTP/process health
- restore the previous code and image if a critical gate fails

Use `sh update.sh --plan --no-fetch --ref=HEAD` to inspect the transaction without changing anything.

## 12. Routine Maintenance

Useful commands:

```bash
docker compose logs
docker compose logs -f web
docker compose logs -f ws-server
docker compose logs -f horizon
docker compose exec -T web php artisan ws-server status
docker compose exec -T web php artisan ws-server connections
```

## 13. Troubleshooting

### Websocket container exists but does not run

Check:

- `command: php artisan ws-server start` exists in `compose.yaml`
- the selected `NODE_REALTIME_PORT` is not occupied
- `network_mode: host` is present

### Nodes can pull config but websocket does not connect

Check:

- `/ws/node` reverse proxy block exists
- the websocket block is above `location ^~ /`
- Cloudflare is not using `Flexible`
- `node_realtime_public_port` or `node_realtime_public_url` is correct

### Multiple Xboard sites on one host

Use different local websocket ports for each site, for example:

- site A: `NODE_REALTIME_PORT=6001`
- site B: `NODE_REALTIME_PORT=6002`
- site C: `NODE_REALTIME_PORT=6003`

### Permission issues after install/update

Run:

```bash
chown -R www:www /www/wwwroot/your-domain
chmod -R 777 /www/wwwroot/your-domain/.docker/.data
```

Only do this if your aaPanel environment uses `www:www` ownership.
