# usdo1 单源整轮模拟验收

2026-11-18，北京时间。本轮只跑本地模拟，不执行线上预检、云机创建、DNS 实写或部署。不测客户端握手和转发。`#17` 保留为回退，`#8`、旧域名、生产门禁、自动开关和 tanzhen 不动。

## 复跑入口

```text
php vendor/bin/phpunit --do-not-cache-result tests/Unit/Services/MachineUsdo1RoundTest.php tests/Unit/Services/MachineUsdo1ExistingATest.php
```

[整轮入口](../../../tools/machine-acceptance/usdo1_round.php)只能从 Laravel 测试环境和内存 SQLite 加载，不是 Artisan 命令、Web 路由或真实执行授权。测试从模拟控制机和新源机 S 开始，采用 [原有 DNS 计划器](../../../app/Services/ServerMachine/Dns/MachineDnsRecordSetPlanner.php)和[单 A 适配](../../../tools/machine-acceptance/usdo1_existing_a.php)，所有云操作、原生任务回执、探测报告、DNS 和停止证据均由夹具提供，不连接实际服务。

## 验证结果

- S 必须有三轮新鲜、不同时间、同安装身份的完整失败，且原生观测达到阈值。缺测、部分失败、工具错误和重复回执不计入。候选创建意图绑定原故障周期，只有一个账号、一次派发。源机恢复或故障周期变化后，原候选不得切换。
- 候选必须有三条独立的新鲜上报和三轮国内 IPv4 可达，每轮任一固定目标通即可。然后才提交唯一的原生 A 更新计划，更改 `usdo1.dnscloudcloud.top` 的同一 RecordId。未获得权威和递归读回，不确认切换。
- 超时或停止后只进入恢复路径。创建、停止、DNS 和删除回执丢失均按原意图读回，不补发。未知写入仍可能在途、回退目标身份变化、未恢复原备注或 TTL 60、外部读回仍指近期端点，均不得删机。
- DNS 读回 `#17`、原 TTL 60、原备注，且已发布的 600 秒缓存窗口结束后，才清理本轮精确登记的候选、源机和控制机。每台需两次相隔至少十秒的精确不存在读回；失读重置该确认，控制机最后清理。把受保护的实例冒作本轮候选会拒绝。

本轮没有生成真机执行包。后续仍需绑定受限的实际原生任务、停止与独立核对入口，在隔离 Linux 上验证真实故障恢复和可信 HTTPS 回调，再考虑线上只读预检。上轮离线复核及其冻结包继续保留在本地，不随本轮提交。
