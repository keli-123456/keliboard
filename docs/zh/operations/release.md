# 发布流程

Keli 是多仓库项目，正式发布前必须同时确认：

- `keli-admin` 私有仓库已经构建并同步到 `keliboard/public/assets/admin-xboard`。
- `keliboard` 内置的管理端 bundle 和 `keli-admin` 当前提交一致。
- `keliboard` 后端依赖、PHP 平台版本和管理端静态资源版本校验通过。
- 如发布 `kelinode`，节点 API contract 与面板仓库一致。
- 需要打 tag 时，相关仓库工作区必须干净。

推荐使用统一脚本：

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
