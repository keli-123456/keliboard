# usdo1 原生任务、停写和独立回退联测

2026-11-18，北京时间。本地与隔离 Linux CI 验收，云平台和 DNS 均使用模拟。未做线上预检、建机、改 DNS、发布镜像或 Agent 正式版、部署。保留 `#17`，不动 `#8`、旧域名、生产门禁、自动开关和 tanzhen；不测客户端协议握手或转发。

## 本地接口和证据

- [原生绑定](../../../tools/machine-acceptance/usdo1_native.php)调用现有原生 `start` / `advance` / `stop`。记录并固定单成员、Linode 账号、故障周期、原请求及尝试，读回原任务不追加候选、不轮换账号。只有提供单 A 写入适配后才允许原生 DNS 调度处理已登记的这条记录；不调用通用 DNS 写入。模拟入口检查测试环境、内存 SQLite、关闭后台运行器和轮换。
- [独立回退](../../../tools/machine-acceptance/usdo1_rollback.php)不接受任意 DNS 操作。要有同一原生执行及 DNS 子任务的停写读回、无有效租约、故障已恢复或未注入的证据、独立签名的原操作结案和 `#17` 身份核对，才可用单独传输恢复同一 RecordId 的地址、TTL 60 和原备注。涉及未知结果时必须签入原意图的确切结果；不能用 DNS 恰好匹配替代停写证明。外部解析和缓存窗口仍是清理条件。
- Agent 增加默认关闭的 `isolated-callback-ca` 构建特性。只允许构建时固定的 `.test` HTTPS 回调源和专用 CA 的公开证书（不含 CA 私钥），禁用代理、跳转和通用根证书；证书链和主机名仍校验。Windows 回环实测使用 Agent 的 `report_machine_status`，只有正确主机名和受信任链实际收到上报。证书的私钥由测试临时生成，不使用生产证书。生产构建不启用该特性。

## 隔离 Linux 复跑

先在已准备好的隔离 Linux 中备齐 PHP/SQLite/OpenSSL、已锁定的 Rust 依赖，以及 `unshare`、`ip`、`nft`、`ping`。不要求任何真实云平台或 DNS 访问。

```text
# keliboard
php vendor/bin/phpunit --do-not-cache-result --filter test_usdo1_bound_native tests/Unit/Services/MachineFailoverNativeBridgeTest.php
python3 -B tools/machine-acceptance/usdo1_linux_lease_check.py

# kelinode-rs
cargo test --offline --locked --lib panel::client::callback_tls::tests
cargo check --offline --locked --lib --features isolated-callback-ca
```

Linux 检查使用新的无外部路由、只有 `lo` 的网络命名空间。实际挂载核内超时，杀死模拟控制进程，记录固定样本恢复所用时间，并检查非目标仍通；超过 90 秒、没有真实内核计时或环境不符合都要失败。模拟租约时间不替代本项。使用隔离 Agent 构建时需提供 `KELI_ISOLATED_CALLBACK_ORIGIN` 和 `KELI_ISOLATED_CALLBACK_CA_PEM`（后者是 CA 公共证书内容，不是文件名），地址仅在隔离环境内解析，不新增线上 DNS，也不借用主站。

## Linux 验收结果

- keliboard：[`531d0c0d` 验收运行](https://github.com/keli-123456/keliboard/actions/runs/44307428719)，51 项 PHP 测试及 7 项 Python 租约测试通过。隔离网络命名空间内强制终止控制进程后，内核自动解除 ICMP 限制，实测 **89.159 秒**，小于 90 秒；非目标保持可达。
- kelinode-rs：[`93b096dc` 验收运行](https://github.com/keli-123456/kelinode-rs/actions/runs/44309420271)，2 项 TLS 单测通过。实际 Linux Agent 使用独立 CA 和正确主机名成功上报，接收端核对了身份和状态；错误主机名或错误 CA 都在 TLS 阶段被拒绝，没有发出应用请求。未配置代理节点，也未测试客户端握手或转发。

## 冻结记录

隔离复验包 `usdo1-linux-isolated-execution-20261118.zip`（38,956,659 字节）已在本地冻结，SHA-256 为 `79d53efd0bc29e1691706385db12249448564df13061d557ba1b04aa6d7ff5a5`。

包内锁定验收源码、构建输入和脱敏证据。历史 CI 二进制受产物配额限制未归档；包中不包含该二进制或 TLS 私钥，复验时以新隔离 CA 重新构建。包和临时 CI 文件不纳入主分支。该冻结仅供隔离复验，不构成线上预检、真机执行或生产接管授权。
