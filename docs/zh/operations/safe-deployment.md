# 安全部署与回滚

Keli 的正式发布由六个仓库和四组构建产物组成。发布清单回答“这次准备发布什么”，部署凭证回答“这次实际上上线了什么、每道检查是否通过、失败后恢复了什么”。两者缺一不可。

## 日常兼容入口

原有命令保持可用：

```bash
docker compose pull && sh update.sh
```

无参数的 `update.sh` 保持原有日常更新语义：拉取 Git 与 Compose 镜像、按 `composer.lock` 安装依赖、执行数据库增量更新并重建应用服务。它不会删除 `composer.lock`，也不会运行 `composer update`。

需要候选环境、强制备份恢复演练和自动回滚门禁时，显式使用：

```bash
sh update.sh --safe --image ghcr.io/keli-123456/keliboard:main
```

严格安全发布会依次执行：

1. 确认已跟踪源码没有本地修改。
2. 锁定目标 Git SHA 和容器镜像 ID。
3. 在独立工作区和 `17001` 端口启动候选站点。
4. 检查首页、站点配置、套餐接口和管理端静态资源。
5. 同步创建数据库备份，取得精确的备份记录 ID 并只演练这一份备份。
6. 停止主服务，安装锁文件中的依赖并执行更新。
7. 检查 `web`、`horizon`、`ws-server`、两个 Redis 进程和 HTTP 接口。
8. 失败时自动恢复原 Git SHA 和原镜像，并再次执行健康检查。

未提交的源码不会被覆盖。请先提交或自行备份，再重新执行更新。

## 先看计划

计划模式只解析本地版本，不拉取代码、不启动容器、不修改数据库：

```bash
sh update.sh --plan --no-fetch --ref=HEAD
```

需要预览远端目标时，先手动 `git fetch`，再将 `HEAD` 换成明确的远端分支、标签或提交。

## 正式发布

先按[发布流程](release.md)生成并严格验证 `release-manifest.json`，然后使用明确的代码版本和镜像：

```bash
sh scripts/deploy-release.sh \
  --release-manifest storage/releases/v1.2.3/release-manifest.json \
  --ref v1.2.3 \
  --image ghcr.io/keli-123456/keliboard@sha256:你的镜像摘要 \
  --health-host 你的站点域名
```

正式发布应使用镜像摘要。脚本会把实际 Docker 镜像 ID 写入凭证，因此标签后来发生变化也不会影响本次发布和回滚证据。

## 部署凭证

每次执行都会创建：

```text
storage/app/releases/deployments/<部署编号>/
```

其中包括：

- `deployment-receipt.jsonl`：每道门禁的时间、状态、原版本和目标版本。
- `canary-smoke.json`：候选站点只读检查结果。
- `backup.json`：本次新建备份的记录 ID、路径和校验值。
- `backup-drill.json`：与该记录 ID 绑定的数据库备份恢复演练结果。
- `cutover-*.log`：切换阶段的依赖、迁移和容器结果。
- `post-deploy-smoke.json`：正式端口的最终检查结果。
- `rollback-*.log`：发生自动回滚时的恢复证据。

校验成功部署凭证：

```bash
php scripts/verify-deployment-receipt.php \
  --strict \
  --expect=succeeded \
  --release-manifest=storage/releases/v1.2.3/release-manifest.json \
  storage/app/releases/deployments/<部署编号>/deployment-receipt.jsonl
```

## 主动回退

需要主动恢复某次部署前的版本时：

```bash
sh scripts/rollback-release.sh \
  storage/app/releases/deployments/<部署编号>/deployment-receipt.jsonl
```

回退也会作为一次新的灰度部署执行。旧代码必须先在候选端口通过当前数据库的检查，之后才会切换，避免盲目回到已不兼容的版本。
回退直接使用部署凭证中已经锁定并保留在本机的 Git SHA 与镜像 ID，不依赖 GitHub 网络。

代码和镜像可以自动恢复，数据库不会自动执行破坏性降级。部署前生成的备份已经通过恢复演练；确需恢复数据库时，先执行：

```bash
docker compose exec -T web php artisan backup:restore-plan --id=备份记录ID
```

按输出步骤在维护窗口恢复。正式迁移应继续采用向后兼容的增量结构，避免把数据库回退作为日常发布手段。

## 手动 Compose 操作

安全部署会把当前锁定镜像写入：

```text
storage/app/releases/active-compose.override.yaml
```

以后若绕过 `update.sh` 手动重建容器，应同时带上这个文件：

```bash
docker compose \
  -f compose.yaml \
  -f storage/app/releases/active-compose.override.yaml \
  up -d
```

仅执行 `docker compose restart` 不会改变已运行容器的镜像。
