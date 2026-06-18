# Agent Order Balance Enforcement Design

## Goal

让代理域名和代理下级用户的购买链路真正闭环：用户通过代理站下单时使用代理售价和代理收款方式；平台在创建订单、进入支付、支付回调三个关键点检查代理余额；代理余额不足时订单不能成功开通。

本阶段不是重写订单系统，而是加固现有 `AgentCommerceService`、`AgentOrderContext`、`AgentBalanceHold` 和支付回调链路。

## Current State

现有代码已经具备主要基础件：

- `AgentCommerceService::createOrderFromRequest()` 可为代理域名订单创建 `Order`、`AgentBalanceHold` 和 `AgentOrderContext`。
- 代理订单会使用代理售价作为用户支付金额，并按平台价和代理扣款比例计算代理成本。
- `getPaymentMethod()` 已能按代理上下文返回代理自己的收款方式。
- `checkout()` 已验证所选支付方式属于当前代理订单。
- `PaymentController` 回调成功后通过 `OrderService::paid()` 间接触发 `AgentCommerceService::captureForPaidOrder()`。
- `captureForPaidOrder()` 会锁定上下文、hold 和代理用户，再扣代理余额并写流水。

主要缺口是链路需要更明确、更可观测、更抗边界情况：

- 支付前需要再次检查 hold 状态和代理可用余额。
- 回调余额不足时需要明确标记代理订单失败，不能留下含糊的 pending 状态。
- 用户端需要把“站点余额不足”提示成可理解的中文。
- 后台需要更清晰展示代理订单、售价、成本、hold、来源和失败原因。
- 取消或失败订单需要稳定释放或失败 hold。

## Business Rules

- 普通主站订单继续走平台价格、平台收款方式和原订单逻辑。
- 代理订单只在存在代理上下文时创建，代理上下文来自已绑定用户或当前代理域名。
- 代理订单禁用优惠券，避免平台优惠与代理自定义售价混算。
- 代理订单的用户支付金额使用代理设置的 `sale_price`。
- 代理成本使用平台原始套餐价格乘以后台代理扣款比例。
- 创建订单前，代理可用余额必须覆盖代理成本。
- 进入支付前，代理可用余额仍必须覆盖当前 hold。
- 支付回调成功后，平台再次检查代理余额；不足时不调用套餐开通成功结果，订单不能成功开通。
- 代理余额不足时给用户统一提示：站点余额不足，请联系站点客服。
- 用户通过代理域名注册或购买后，代理归属保持粘性；主域名下再次购买仍按代理规则处理。

## Backend Design

### Order Creation

`AgentCommerceService::createOrderFromRequest()` 继续作为代理订单入口。

需要保证：

- 统一使用 `AgentCommerceContextResolver`。
- 代理 profile 必须 active。
- 代理售价 period 必须存在且 enabled。
- 代理成本计算必须使用平台套餐价，而不是代理售价。
- 创建订单、创建 hold、创建 context 在同一数据库事务里完成。
- hold metadata 和 order context 保存历史快照：
  - source
  - agent user id
  - optional agent domain id
  - domain
  - plan id
  - period
  - sale amount
  - platform base amount
  - cost amount
  - discount percent

### Payment Method Listing

`OrderController::getPaymentMethod()` 继续按 `agentUserIdForPaymentMethods()` 判断返回范围。

规则：

- 如果 `trade_no` 指向代理订单，返回该代理启用的收款方式。
- 如果没有 `trade_no`，但当前登录用户已属于代理，也返回该代理启用的收款方式。
- 如果当前 Host 是代理域名，也返回该代理启用的收款方式。
- 否则返回平台收款方式。

### Checkout Guard

`OrderController::checkout()` 在调用支付插件前需要对代理订单加一层检查：

- 校验 payment owner 与 order context 的代理一致。
- 校验 `AgentBalanceHold` 存在且为 pending。
- 校验代理可用余额仍覆盖 hold amount。
- 如果不足，返回站点余额不足错误，不调用第三方支付插件。

这样可以避免用户进入支付时代理余额已经被其他订单占用。

### Payment Callback Guard

支付回调已验证：

- `trade_no`
- `callback_no`
- payment id
- paid amount

在代理订单场景下，`OrderService::paid()` 内部会执行 `captureForPaidOrder()`。本阶段要求回调失败路径更明确：

- 如果 capture 因代理余额不足失败，订单不能变成 paid。
- context 标记 `failed`，hold 标记 `failed`。
- 记录失败原因到 metadata 或 context snapshot，便于后台排查。
- 支付网关返回失败响应，避免平台误认为业务已处理成功。

如果订单已经 paid 且 hold 已 captured，回调保持幂等成功。

### Cancel And Release

用户取消 pending 代理订单时：

- 订单取消。
- hold 标记 released。
- context 标记 cancelled。

支付失败、回调失败或系统主动关闭时：

- hold 标记 failed。
- context 标记 failed。
- 不扣代理余额。

### Admin Visibility

`V2\Admin\AgentCommerceController::orders()` 和 `holds()` 是后台监控入口。本阶段让返回数据足够排查：

- trade no
- buyer user/email
- agent user/email
- source: domain / user_binding
- domain
- sale amount
- cost amount
- hold status
- context status
- payment name/code
- failure reason if available
- created/updated timestamps

前端 `keli-admin` 代理商业化页展示这些字段，不展示支付插件密钥。

## User Frontend Design

`keli-user` 不新增大页面，只修购买体验：

- 商店和购买页继续使用后端返回的代理售价。
- 支付方式列表继续使用后端返回的代理收款方式。
- 订单创建、支付、轮询时遇到 `INSUFFICIENT_SITE_BALANCE_MESSAGE` 映射为中文：
  - “站点余额不足，请联系站点客服。”
- 如果代理收款方式为空，显示“当前站点暂未配置可用收款方式，请联系站点客服。”

## Admin Frontend Design

`keli-admin` 的代理商业化页面作为管理员监控面：

- 订单列表突出 source、代理、域名、用户、售价、成本、状态。
- hold 列表显示 pending/captured/released/failed。
- 失败原因用轻量 badge 或说明文本显示。
- 不做复杂筛选，先保证可见性和排查效率。

## Error Handling

- Missing agent profile: order creation fails before any order rows are created.
- Missing agent price: order creation fails before any order rows are created.
- Insufficient balance at creation: fail without order, hold, or context.
- Insufficient balance at checkout: fail without calling payment plugin.
- Insufficient balance at callback: do not open subscription; mark context and hold failed.
- Payment method owner mismatch: checkout fails and callback rejects mismatch.
- Normal platform orders are not affected.

## Testing Strategy

Backend tests should cover:

- Agent domain order creates hold and context with correct sale/cost amounts.
- Bound agent user on main domain creates agent order.
- Checkout fails if hold is missing or non-pending.
- Checkout fails if available balance is lower than hold amount.
- Callback capture deducts agent balance and writes ledger.
- Callback capture is idempotent for already-paid/captured order.
- Callback insufficient balance marks context/hold failed and does not open subscription.
- Normal platform order still succeeds without agent tables.

Frontend tests should cover:

- User error mapping for site balance insufficiency.
- Admin helper formatting for source/status/money if helper functions are added.

Manual verification:

- Create an agent with balance.
- Add an agent domain and storefront price.
- Create a user through that domain.
- Create order, checkout with agent payment, simulate callback.
- Confirm user plan opens, agent balance decreases, ledger row exists, context is paid.
- Repeat with low agent balance and confirm order cannot proceed or callback fails cleanly.

## Rollout

Deploy order:

1. `keliboard` backend.
2. `keli-user` user-facing copy/empty-state updates.
3. `keli-admin` monitoring display.

The migration tables already exist in current branch. This phase should not require a new table unless implementation discovers a missing durable place for failure reason. If a failure reason needs persistence, prefer adding a nullable `failure_reason` field to `v2_agent_order_context` and `v2_agent_balance_hold`.

## Out Of Scope

- Agent custom coupons.
- Agent custom commission rates per plan.
- Agent self-service refund handling.
- Agent custom payment plugins outside platform-enabled plugin list.
- Separate standalone agent website builder.
