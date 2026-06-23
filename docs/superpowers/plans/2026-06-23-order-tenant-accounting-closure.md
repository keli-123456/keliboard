# Order Tenant Accounting Closure Plan

1. Add a regression test for admin manual paid on an agent order whose agent balance is insufficient at capture time.
2. Update the shared paid-order failure path to mark insufficient agent balance on the tenant context.
3. Run available verification commands and push a focused commit.
