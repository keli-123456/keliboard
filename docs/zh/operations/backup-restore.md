# 备份恢复手册

本文档用于验证和恢复 `backup:database` 生成的数据库备份。当前系统支持本地备份、Google Cloud Storage 和 FTP 上传；远程存储可以在管理端配置，也兼容旧的 `.env` 配置。恢复动作仍建议由管理员在服务器上手动执行，避免误操作覆盖生产数据库。

## 备份文件

数据库备份默认生成在：

```text
storage/backup
```

文件名格式：

```text
YYYY-mm-dd_HH-ii-ss_<database>_database_backup.sql.gz
```

备份记录保存在 `v2_backup_record`，关键字段：

| 字段 | 说明 |
| --- | --- |
| `disk` | `local`、`google_cloud` 或 `ftp` |
| `status` | `succeeded` 表示本地成功，`uploaded` 表示已上传远程并删除本地文件 |
| `size` | 压缩文件字节数 |
| `checksum` | 压缩文件 SHA256 |
| `path` | 本地相对路径 |
| `remote_path` | 远程对象路径 |

新版本备份会在 SQL dump 开头写入恢复元数据，其中包含整份 `.env` 文件的 base64 内容。SQL 导入会忽略这些注释，不影响数据库恢复；新机器恢复时可以先从备份包提取 `.env`：

```bash
gzip -dc storage/backup/<backup>.sql.gz \
  | sed -n '/^-- KELI_RECOVERY_ENV_BASE64_BEGIN$/,/^-- KELI_RECOVERY_ENV_BASE64_END$/p' \
  | sed '1d;$d;s/^-- //' \
  | tr -d '\n' \
  | base64 -d > .env
```

安全说明：备份压缩包现在包含 `.env`，也就包含 `APP_KEY`、数据库密码、支付密钥和远程存储密钥。请按最高敏感级别保管本地和远程备份文件。

如果运行环境没有实际的 `.env` 文件，而是完全通过 Docker 环境变量注入，备份会记录 `.env` 缺失，无法凭空导出这些环境变量；文件映射部署会正常备份 `.env`。

## 远程存储配置

管理端“备份中心”可以配置远程存储：

- Google Cloud Storage：Bucket、目录前缀、Service Account JSON。
- FTP：主机、端口、用户名、密码、根目录、SSL、被动模式、超时秒数。

安全和兼容性说明：

- 管理端配置优先于 `.env`；未在管理端填写的字段继续兼容 `.env`。
- Service Account JSON 和 FTP 密码会加密存入 `v2_settings`，接口不会回显明文。
- 密钥输入框留空表示不修改已保存密钥。
- “清除面板密钥”会删除面板保存的密钥；如果 `.env` 仍有配置，会继续使用 `.env` fallback。
- “测试连接”会验证 Google Cloud Bucket 可访问，或 FTP 可连接、登录并确认根目录。
- 自动备份完成后会使用“保留份数”同步清理本地和远程旧备份；例如保留 7 份时，本地、Google Cloud 和 FTP 各自最多保留最近 7 条成功备份记录。
- 如果远程清理因为密钥、网络或权限失败，系统会保留该条备份记录并写入 `backup` 日志，避免远程对象变成不可追踪的孤儿文件。

对应接口：

```bash
curl -X POST "$APP_URL/api/v2/$ADMIN_PATH/system/backup/remote-storage" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"ftp":{"host":"ftp.example.com","port":21,"username":"backup","password":"secret","root":"backup","passive":true,"ssl":false,"timeout":30}}'

curl -X POST "$APP_URL/api/v2/$ADMIN_PATH/system/backup/remote-storage/test" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"disk":"ftp"}'
```

## 校验备份

管理端“备份中心”可以对本地成功备份执行“校验备份”。校验项包括：

- 文件路径在 `storage/backup` 内。
- 本地文件存在且可读。
- 文件大小与记录表一致。
- SHA256 与记录表一致。
- gzip 文件可读取。
- 解压预览内容像 SQL dump。

也可以直接调用接口：

```bash
curl -X POST "$APP_URL/api/v2/$ADMIN_PATH/system/backup/verify" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"id": 1}'
```

远程备份需要先从 Google Cloud Storage 或 FTP 下载回服务器，再按本地文件流程校验和恢复。

## 恢复前检查

恢复前必须确认：

- 已经额外创建一份当前生产数据库备份。
- 已在测试环境做过一次恢复演练。
- 新机器恢复时，已经从备份包提取 `.env` 并检查数据库连接、域名、队列和缓存配置。
- 队列、定时任务、Octane 或 Web 进程已经停止。
- 当前代码版本与备份时间点的数据库结构兼容。
- 备份校验通过。

管理端“备份中心”在校验本地备份后，可以继续执行“恢复预检”。恢复预检不会修改数据库，只会检查：

- 备份文件校验是否通过。
- 当前数据库连接类型是否与备份记录一致。
- 当前是否有备份任务正在运行。
- 当前是否处于维护模式。
- 是否存在必须先处理的阻断项。

也可以直接调用接口：

```bash
curl -X POST "$APP_URL/api/v2/$ADMIN_PATH/system/backup/restore-preflight" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"id": 1}'
```

说明：

- “运行中备份”和“数据库类型不一致”属于阻断项。
- “未进入维护模式”属于风险提示，因为进入维护模式后管理端 API 可能不可用；实际恢复前仍应停止 Web 流量、队列、定时任务和 Octane。

## 恢复演练记录

校验备份或恢复预检后，管理端可以记录一次恢复演练结果。记录只保存演练摘要，不会执行真实恢复，也不会修改数据库数据。

记录内容包括：

- 演练状态：`passed`、`failed`、`incomplete`。
- 演练环境：`local`、`staging`、`production_rehearsal`。
- 备注和操作人。
- 记录时间、备份 ID 和备份文件名。

接口示例：

```bash
curl -X POST "$APP_URL/api/v2/$ADMIN_PATH/system/backup/restore-drill" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"id": 1, "status": "passed", "environment": "staging", "note": "测试库恢复、迁移和登录检查通过"}'
```

说明：

- 演练记录保存在 `v2_backup_record.options.restore_drills`，单个备份最多保留最近 20 条。
- 备份中心首页会展示最近一次恢复演练，备份列表会展示每个备份最近一次演练结果。
- 该功能用于审计“备份是否真的能恢复”，不能替代实际恢复前的人工确认。

## MySQL 恢复

示例：

```bash
php artisan down
gzip -dc storage/backup/2026-05-02_03-30-00_xboard_database_backup.sql.gz \
  | mysql -h "$DB_HOST" -P "${DB_PORT:-3306}" -u "$DB_USERNAME" -p "$DB_DATABASE"
php artisan migrate --force
php artisan optimize:clear
php artisan up
```

Docker 部署时在容器内执行：

```bash
docker compose exec web php artisan down
docker compose exec web sh -lc 'gzip -dc storage/backup/<backup>.sql.gz | mysql -h "$DB_HOST" -P "${DB_PORT:-3306}" -u "$DB_USERNAME" -p "$DB_DATABASE"'
docker compose exec web php artisan migrate --force
docker compose exec web php artisan optimize:clear
docker compose exec web php artisan up
```

## SQLite 恢复

示例：

```bash
php artisan down
gzip -dc storage/backup/2026-05-02_03-30-00_sqlite_database_backup.sql.gz \
  | sqlite3 database/database.sqlite
php artisan migrate --force
php artisan optimize:clear
php artisan up
```

## 远程备份恢复

### Google Cloud Storage

下载到服务器：

```bash
gcloud storage cp gs://<bucket>/backup/<backup>.sql.gz storage/backup/<backup>.sql.gz
```

然后按 MySQL 或 SQLite 的本地恢复步骤执行。

### FTP

下载到服务器：

```bash
curl --ftp-pasv -u "$BACKUP_FTP_USERNAME:$BACKUP_FTP_PASSWORD" \
  "ftp://$BACKUP_FTP_HOST/$BACKUP_FTP_ROOT/<backup>.sql.gz" \
  -o storage/backup/<backup>.sql.gz
```

然后按 MySQL 或 SQLite 的本地恢复步骤执行。

## 回滚恢复

如果恢复后发现异常：

1. 保持维护模式。
2. 使用恢复前创建的新备份再次恢复。
3. 执行 `php artisan optimize:clear`。
4. 检查 `storage/logs/laravel.log` 和 `storage/logs/backup.log`。
5. 只在核心页面、用户登录、订单、节点配置接口检查通过后执行 `php artisan up`。
