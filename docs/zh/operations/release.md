# 发布流程

Keli 是多仓库项目，正式发布前必须同时确认：

- `keli-admin` 私有仓库已经构建并同步到 `keliboard/public/assets/admin-xboard`。
- `keliboard` 内置的管理端 bundle 和 `keli-admin` 当前提交一致。
- `keliboard` 后端依赖、PHP 平台版本和管理端静态资源版本校验通过。
- 如发布 `kelinode`，节点 API contract 与面板仓库一致。
- 需要打 tag 时，相关仓库工作区必须干净。

## 全栈发布（推荐）

当前完整发布单元包含六个仓库：

- `keliboard`
- `keli-admin`
- `keli-user`
- `kelinode-rs`
- `keli-core-rs`
- `keli-native-client`

新流程不会直接修改线上，分为三个明确阶段。每次发布都会生成
`keliboard/build/releases/<版本>/release-manifest.json`，记录六个源码提交、依赖锁文件 SHA256、
前端主题和管理端 bundle、节点端与内嵌 core、客户端安装包与更新清单。

发布清单验证通过后，再按[安全部署与回滚](safe-deployment.md)执行候选端口检查、数据库备份演练、
主服务切换和最终健康门禁。构建发布与线上部署是两张独立凭证，不能用“已经打过标签”代替上线检查。

### 1. 准备候选包

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts\release-full-stack.ps1 `
  -ReleaseVersion v1.2.3 `
  -Mode Prepare `
  -WorkspaceRoot C:\path\to\keli
```

该阶段会运行发布契约测试，构建并同步 `keli-admin`，构建并打包 `keli-user`，然后生成非严格候选清单。
同步后的管理端静态资源需要审核并提交；也可以显式加入 `-CommitSyncedAssets` 让脚本只提交该目录。

### 2. 严格验证

节点端和客户端仍由各自的签名/发布流水线产出。准备好这些文件后执行：

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts\release-full-stack.ps1 `
  -ReleaseVersion v1.2.3 `
  -Mode Verify `
  -WorkspaceRoot C:\path\to\keli `
  -UserThemePath C:\path\to\theme.zip `
  -KelinodeRsManifestPath C:\path\to\keli-native-node.manifest.json `
  -NativeClientManifestPath C:\path\to\keli-tauri-update.json `
  -NativeClientArtifactPath C:\path\to\Keli-setup.exe
```

严格验证要求：六个仓库没有已跟踪的未提交修改、四组产物全部存在、产物来源提交与仓库一致、
所有文件 SHA256 一致、节点 API contract 一致、`kelinode-rs` 内嵌的 `keli-core-rs` 提交一致。
任意一项不满足都会停止，不会创建标签或发布。

PHP 不在 `PATH` 时可传入 `-PhpPath`，或设置环境变量 `KELI_PHP`。

### 3. 统一标签与推送

严格验证通过后，再执行：

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts\release-full-stack.ps1 `
  -ReleaseVersion v1.2.3 `
  -Mode Publish `
  -WorkspaceRoot C:\path\to\keli `
  -UserThemePath C:\path\to\theme.zip `
  -KelinodeRsManifestPath C:\path\to\keli-native-node.manifest.json `
  -NativeClientManifestPath C:\path\to\keli-tauri-update.json `
  -NativeClientArtifactPath C:\path\to\Keli-setup.exe `
  -Push
```

`Publish` 会再次完整验证后，给六个仓库创建同一个发布标签。标签步骤可安全重跑：已经存在且指向当前
提交的标签会复用，指向其他提交时会立即停止。

## 兼容发布入口

原脚本继续保留，用于只发布 `keliboard`、`keli-admin` 和旧版 `kelinode` 的兼容场景：

```powershell
powershell -ExecutionPolicy Bypass -File scripts\release-stack.ps1 -ReleaseVersion v0.4.0
```

脚本会执行：

1. 检查 `keli-admin` 工作区。
2. 运行 `npm run build`。
3. 运行 `node scripts/sync-to-xboardpro.mjs` 同步到 `keliboard`。
4. 校验 `keliboard` 管理端静态资源 hash。
5. 校验 composer PHP 平台版本。
6. 运行 `scripts/release-preflight.ps1`。

如果同步后希望脚本直接提交 `keliboard` 管理端静态资源：

```powershell
powershell -ExecutionPolicy Bypass -File scripts\release-stack.ps1 -ReleaseVersion v0.4.0 -CommitSyncedAssets
```

如果确认要给 `keliboard` 和 `keli-admin` 同时打 tag：

```powershell
powershell -ExecutionPolicy Bypass -File scripts\release-stack.ps1 -ReleaseVersion v0.4.0 -Tag
```

同时发布 `kelinode` 时指定节点版本：

```powershell
powershell -ExecutionPolicy Bypass -File scripts\release-stack.ps1 -ReleaseVersion v0.4.0 -KelinodeVersion v0.4.0 -Tag
```

确认要推送当前分支和 tag：

```powershell
powershell -ExecutionPolicy Bypass -File scripts\release-stack.ps1 -ReleaseVersion v0.4.0 -KelinodeVersion v0.4.0 -Tag -Push
```

注意：

- `-Push` 必须和 `-Tag` 一起使用，避免误推不明确的 refs。
- `-AllowDirty` 只用于本地预检，不允许和打 tag 一起使用。
- 如果只想校验后端包，不重新构建私有管理端，可以加 `-SkipAdminBuild`。
- 如果本次不发布 `kelinode`，可以加 `-SkipKelinodeRepo`。
