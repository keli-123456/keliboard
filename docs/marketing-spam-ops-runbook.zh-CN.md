# 营销自动触达 + 垃圾注册清理 上线运行说明

## 1. 上线前必须完成的数据库变更

按顺序执行以下 migration：

1. `2026_04_09_000001_create_marketing_and_spam_cleanup_tables.php`
2. `2026_04_09_000002_add_ops_columns_to_dispatch_and_spam_tables.php`

如果当前环境尚未安装 PHP 依赖，先执行 Composer 安装，再运行 migration。

## 2. 队列与调度

### Cron

需要确保 Laravel Scheduler 每分钟执行一次：

```bash
* * * * * cd /path/to/Xboardpro && php artisan schedule:run >> /dev/null 2>&1
```

### 需要被调度的命令

当前功能依赖以下调度项：

- `message-dispatch:release --limit=200`：每分钟释放待发送任务
- `message-dispatch:recover-stuck --limit=200`：每 5 分钟回收卡死在 `sending` 的任务
- `marketing:scan`：每 10 分钟扫描营销规则命中
- `spam-registration:scan`：每小时扫描垃圾注册候选

### Horizon / Queue Worker

需要至少有一个消费者能够处理 `message_dispatch` 队列。

如果使用 Horizon，确认 `message_dispatch` 已被纳入 supervisor 配置并已重启 Horizon。

如果使用普通 worker，至少需要：

```bash
php artisan queue:work --queue=message_dispatch,send_email,send_email_mass,send_telegram --sleep=3 --tries=3 --timeout=60
```

## 3. 推荐的首批启用策略

### 建议先启用

- `order_pending_unpaid`
- `plan_expiring_3d`
- `plan_expired_1d`

原因：这些属于生命周期提醒，误触达风险低，可先验证链路、模板和配额。

### 建议暂缓到第二阶段再打开

- `registered_no_purchase_1d`
- `inactive_7d`

原因：这两条属于营销召回，虽然已受冷却与配额保护，但更容易受模板质量、退订策略和误触达投诉影响。

### 建议默认关闭

- Telegram 通道开关

原因：第一版 Email 已完整接入并带有日志、失败分类、限流与抑制。Telegram 当前只复用现有能力，建议等 bot 配置、模板和运营流程确认后再逐步启用。

## 4. 运行时观察点

后台 `营销中心` 重点看：

- 本小时发送量
- 本小时失败量
- 待发送任务
- 发送中任务
- 邮件健康状态
- 发送记录里的失败分类和人工备注

如果发现以下现象，需要先暂停营销类规则：

- 本小时失败量明显抬升
- `healthy` 变成 `degraded` / `unhealthy`
- `pending` 长时间堆积不下降
- `sending` 长时间高位且无法回落

## 5. 卡死 sending 任务回收

系统会把长期停留在 `sending` 的任务视为卡死任务。

- 超时时间：15 分钟
- 回收动作：
  - 若这次回收后仍未达到 `max_attempts`，回退到 `pending`
  - 若这次回收后达到 `max_attempts`，标记为 `failed`
- 回收时会：
  - 增加 `attempt_count`
  - 写入 `failure_classification=timeout`
  - 更新 `last_error=sending timeout recovered by scheduler`
  - 记录 `recovery_count` 和 `last_recovered_at`

这套机制的目标是避免任务永久卡死，同时不把基础设施异常误记为供应商邮件健康异常。

## 6. 垃圾注册候选上线验证

建议按下面顺序验证，先观察再放量：

1. 先执行扫描，不做任何物理删除
2. 后台查看 `垃圾注册候选` 列表
3. 随机抽样至少 20 个候选，核对：
   - 是否确实无套餐
   - 是否确实无已支付订单
   - 是否确实无余额
   - 是否确实无工单
   - 是否确实无邀请/返佣/下级关系
   - 是否确实存在 `permanent_failure`
   - 当时邮局健康是否为 `healthy`
4. 对有疑问的记录，先使用“保留”并填写备注
5. 连续观察 1-3 天，再决定是否允许人工批量逻辑软删除

## 7. 当前环境依赖要求

如果运行 `php artisan` 报错缺少 `vendor/autoload.php`，说明当前后端依赖未安装。

至少需要：

- `composer install`
- 正确的 `.env`
- 可用的数据库连接
- 可用的 Redis / 队列连接

依赖装好后建议执行：

```bash
composer install
php artisan migrate
php artisan config:clear
php artisan cache:clear
php artisan horizon:terminate
php artisan schedule:run
php artisan marketing:scan
php artisan message-dispatch:release --limit=50
php artisan message-dispatch:recover-stuck --limit=50
php artisan spam-registration:scan
```

## 8. 上线后仍需人工观察的风险

- 邮件失败分类目前仍主要基于错误文本启发式判断，不是供应商 webhook 级别
- 营销模板文案与退订策略是否足够稳妥，需要运营继续迭代
- 垃圾注册候选虽已偏保守，但仍应以“标记 + 冻结 + 人工确认”为主，不建议自动物理删除
- 如果 worker 不稳定或 Horizon 无人值守，`pending` 和 `sending` 仍可能积压，需要持续看队列健康
