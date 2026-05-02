# Keli 发布流程

本文档约束 `keliboard` 面板发布、私有 `keli-admin` 管理端产物同步，以及 `kelinode` 二进制版本发布。目标是避免漏构建、漏同步、漏 tag，保证公开仓库可以独立部署，私有仓库源码不进入公开仓库。

## 版本关系

发布时必须记录下面的版本矩阵：

| 项目 | 版本来源 | 规则 |
| --- | --- | --- |
| `keliboard` | Git tag，例如 `v0.4.0` | 面板发布版本，Docker tag 会基于 Git tag/commit 生成 |
| `keli-admin` | 私有仓库 commit sha | 不打公开版本，构建产物提交到 `keliboard/public/assets/admin-xboard` |
| `kelinode` | Git tag，例如 `v0.4.0` | 只有节点端有变更时才发布，二进制版本来自 release tag |
| node-api contract | `contracts/node-api/node-api.json` | 必须与 `kelinode/api/v2board/contract.go` 一致 |

版本建议：

- 面板和节点可以同号发布，但不是强制绑定。
- 只改管理端或面板时，可以只发布 `keliboard`。
- 改了节点接口、节点运行时、自升级、安装脚本时，必须同时评估 `kelinode` 是否需要 tag。

## 发布前顺序

### 1. 提交私有 `keli-admin`

```powershell
cd ..\keli-admin
npm run lint
npm run test
npm run build
git status --short
git add <changed-files>
git commit -m "<message>"
git push
```

要求：

- `git status --short` 必须干净。
- 构建不能来自 dirty tree。
- 私有源码不提交到 `keliboard`。

### 2. 构建并同步到 `keliboard`

```powershell
cd ..\keli-admin
npm run build
npm run sync:xboardpro
```

同步脚本会把 `dist` 复制到：

```text
keliboard/public/assets/admin-xboard
```

并在 `index.html` 上写入 JS/CSS 内容 hash，例如：

```text
/assets/admin-xboard/assets/index.js?v=<sha256-12>
```

### 3. 验证 `keliboard`

```powershell
cd ..\keliboard
php scripts/verify-admin-xboard-assets.php
php artisan route:list
vendor/bin/phpunit --testsuite Unit
```

如果本地 PHP/Composer 环境不完整，至少运行：

```powershell
php scripts/verify-admin-xboard-assets.php
```

CI 会对 `public/assets/admin-xboard/**` 变更重新运行。

### 4. 运行发布预检

```powershell
cd ..\keliboard
.\scripts\release-preflight.ps1 -ReleaseVersion v0.4.0
```

如果这次同时发布 `kelinode`：

```powershell
.\scripts\release-preflight.ps1 -ReleaseVersion v0.4.0 -KelinodeVersion v0.4.0
```

需要留下发布矩阵时：

```powershell
.\scripts\release-preflight.ps1 -ReleaseVersion v0.4.0 -ManifestPath storage\release-manifest-v0.4.0.json
```

预检会检查：

- `keliboard`、`keli-admin`、`kelinode` 工作区是否干净。
- `keliboard` release tag 是否已经存在。
- `keli-admin` bundle 内嵌的 `gitSha` 是否等于私有仓库 HEAD。
- bundle 是否来自 dirty build。
- admin JS/CSS 文件 hash 是否等于 `index.html` 的 `?v=`。
- node-api contract 是否与 `kelinode` 常量一致。
- 指定 `-KelinodeVersion` 时，`kelinode` tag 是否已经存在。

### 5. 提交并推送 `keliboard`

```powershell
git status --short
git add public\assets\admin-xboard scripts\verify-admin-xboard-assets.php .github\workflows\ci.yml
git commit -m "Release admin assets for v0.4.0"
git push
```

commit message 建议写明私有管理端 commit：

```text
Release admin assets for v0.4.0

keli-admin: <short-sha>
```

### 6. 打 tag

`keliboard`：

```powershell
git tag -a v0.4.0 -m "keliboard v0.4.0"
git push origin v0.4.0
```

如果节点端也发布：

```powershell
cd ..\kelinode
go test ./cmd/... ./node/... ./core/... ./api/v2board/...
go build -v -trimpath -o build_assets\v2node.exe
git tag -a v0.4.0 -m "kelinode v0.4.0"
git push origin v0.4.0
```

`kelinode` 的 GitHub release workflow 会在 tag 或 release 上构建多平台二进制。

## 回滚原则

- 管理端 UI 问题：优先回滚 `keliboard/public/assets/admin-xboard` 到上一个已验证 commit。
- 面板后端问题：回滚 `keliboard` tag 或 Docker image。
- 节点端问题：不要直接强制旧版本覆盖所有机器，先在一台机器验证，再通过管理端分批下发旧版本。
- 涉及数据库 migration 的发布，发布前必须确认备份可用，回滚时不能只回滚代码。

## 发布后检查

发布后至少确认：

- `keliboard` GitHub CI 通过。
- Docker image 或部署包包含最新 `public/assets/admin-xboard/index.html`。
- 管理端页面底部或构建信息显示的 FE sha 等于本次 `keli-admin` commit。
- `api/v2/server/machine/versionInfo` 能看到正确的 `kelinode` 最新版本。
- 机器升级一台测试节点成功后，再批量升级。
