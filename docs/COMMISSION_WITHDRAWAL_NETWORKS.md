# USDT Commission Withdrawal Networks

Commission withdrawals now require an explicit receiving network when the method is USDT. Supported network IDs are `TRC20`, `ERC20`, and `BEP20`. This is still a manual withdrawal ticket workflow, not automatic crypto settlement or currency conversion.

## Configuration

System Settings > Invite & Commission > Withdrawal Rules contains the `commission_withdraw_usdt_networks` whitelist. Its default is an empty list. Administrators must explicitly enable the networks they accept; an empty list pauses USDT withdrawals without disabling other configured methods.

Authenticated user configuration exposes only enabled networks as `withdraw_usdt_networks`. A USDT withdrawal submits the original `withdraw_method` and `withdraw_account`, plus `withdraw_network`. The server rechecks the current whitelist and basic address syntax. Address syntax cannot identify a network, prove wallet ownership, or verify exchange support. In particular, ERC20 and BEP20 addresses use the same syntax; the selected network must not be inferred or replaced.

Each new withdrawal ticket stores a `withdrawal` snapshot containing the method, account, network ID/name, server-side commission amount in cents, and configured currency. The displayed commission amount is not a converted USDT payment amount. Non-USDT methods have no network. The separate agent profit withdrawal flow is unchanged.

## Coordinated Upgrade

1. Include the matching `keliboard`, `keli-admin` build, and `keli-user` theme in one coordinated rollout. Old user frontends do not send the required USDT network field.
2. Run the normal panel database migration process, including `2026_10_15_210000_add_commission_withdrawal_details_to_tickets.php`. This adds the nullable `v2_ticket.withdrawal` JSON column without backfilling existing requests. If storage is missing, withdrawal creation fails before creating a ticket or message.
3. Update the admin bundle and upload the matching user theme using the normal theme workflow.
4. Explicitly select the accepted USDT networks in settings. Do not assume TRC20 is enabled by default.
5. Verify the workflow with disposable data before processing a real withdrawal.

Historical withdrawal tickets are marked as lacking recorded network information. For an old USDT request, confirm the network with the user before manual payment; never guess from its address. Changing the enabled network list does not rewrite an existing request's saved destination.

## Verification

The implementation was checked with 2,118 admin tests, 897 user tests, and 155 affected backend tests (640 assertions). Both production frontend builds, TypeScript checks, and scoped lint checks passed. Browser checks used blocked, local mock APIs for explicit selection, switching networks, empty configuration, address validation, server validation failures, saved destinations, legacy requests, English, dark mode, and mobile layout.

These checks did not send a real payment, test on-chain transfers, apply a production migration, or deploy to a live panel.
