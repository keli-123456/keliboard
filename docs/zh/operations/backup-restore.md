# 备份恢复手册

本文档用于验证和恢复 `backup:database` 生成的数据库备份。当前系统支持本地备份、Google Cloud Storage 和 FTP 上传；恢复动作仍建议由管理员在服务器上手动执行，避免误操作覆盖生产数据库。

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
