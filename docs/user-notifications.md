# User Notifications

The keli-user navigation bell separates unread support replies from outstanding tasks.

## Behaviour

- The red badge counts unread support messages, not open tickets. Customer messages are excluded; support replies in closed tickets remain unread until viewed.
- Pending orders and traffic warnings appear under "To do" without increasing the badge.
- The visible, online page checks one summary endpoint every 30 seconds. Returning to the page, opening the bell, and confirmed user-data changes trigger a refresh. Failures back off to 60 and then 120 seconds.
- Failed refreshes retain the previous snapshot and show an update warning. An unavailable endpoint never masquerades as zero unread messages.
- Only support messages visible in the active conversation are acknowledged. Opening the bell or loading ticket details does not mark replies read.
- Read acknowledgements are stored on the server, apply only to the requested message IDs, and are idempotent. Other tabs refresh after acknowledgement; other devices converge on their next refresh.
- A newly observed support reply produces one lightweight popup per page session. The initial snapshot does not replay historical popups.

## Deployment

Deploy the panel and its database migration before uploading the matching keli-user theme. Run the normal panel upgrade workflow, including `php artisan migrate --force` in the application environment. No change to keli-admin or node software is needed.

Migration `2026_09_12_000001_add_ticket_message_user_read_at` adds an indexed nullable timestamp to `v2_ticket_message`. Existing rows receive the legacy-read sentinel `0`; subsequent inserts default to `null` (unread). Message contents are unchanged. Replies inserted during the default change are restored to unread. Complete the migration before resuming application workers under the deployment workflow; if interrupted, finish it before serving the new theme.

Old panel versions do not provide the new bell contract. The theme shows an unavailable notice instead of guessing unread counts. Old themes can continue using existing ticket endpoints but do not persist these read acknowledgements.

## Endpoints

- `GET /api/v1/user/notification/fetch`: authenticated, private/no-store version-1 summary; exact unread message total, up to 20 ticket groups, pending-order summary and traffic percentage. No message bodies or other users' data are returned.
- `POST /api/v1/user/ticket/read`: accepts `id` and `message_ids` (1 to 100 distinct positive IDs). Returns `read_ids` for support messages belonging to that user's ticket. It cannot close tickets, send messages or mark unseen IDs implicitly.
- Ticket detail responses add `unread_message_ids`, restricted to the messages included in that response. Fetching details is read-only.

## Verification

`tests/Unit/Http/UserNotificationTest.php` covers the migration baseline, new replies, customer-message exclusion, closed tickets, cross-user isolation, bounded summaries, precise and repeated acknowledgements, request validation and detail-fetch races.

The matching user theme includes notification contract tests in `src/lib/notificationInbox.test.ts`. Browser verification uses isolated local preview fixtures rather than live customer data; temporary preview pages and screenshots are not part of the release.
