# Remote Check Proxy Plugin

Proxy an external check API and return the response unchanged.

## Usage
- 在后台安装并启用插件。
- 在插件配置中填写 `upstream_url` 与 `random_ip_url`（建议使用环境变量），否则接口返回 400。
- 本地调用（已加 `api` 前缀）：
  - `GET /api/plugin/remote-check/fetch` → 透传 `upstream_url`
  - `GET /api/plugin/remote-check/random-ip` → 透传 `random_ip_url`
  （默认只挂 `api` 中间件，不校验登录；如需鉴权可在路由中追加中间件）

## Config
- **upstream_url**: External API URL to fetch. Default: _empty_ (must set).
- **timeout_seconds**: HTTP request timeout in seconds.
- **random_ip_url**: External API returning random IP. Default: _empty_ (must set).
- **enable_cache**: Cache upstream response to avoid repeated hits within TTL.
- **cache_ttl_seconds**: Cache duration in seconds (0 disables caching). Default: 60.
