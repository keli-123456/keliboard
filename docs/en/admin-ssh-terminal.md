# Browser SSH Terminal

The server-management list provides an SSH action in the row and its action
menu. This is an interactive remote SSH shell, not a node-control command and
not a shell on the panel server. It does not install, restart or upgrade nodes.

## Deployment

- Install the committed Composer dependencies and run `php artisan migrate`.
- Rebuild the admin application. This feature bundles xterm.js locally; it does
  not fetch terminal scripts from a CDN.
- Restart the existing `ws-server` process after deploying the PHP changes. A
  restart also disconnects existing node realtime sockets; schedule deployment
  appropriately. No extra public listener or port is required.
- Configure a working **WSS** node-realtime public URL. Its reverse proxy must
  preserve the WebSocket upgrade, query string and `Origin` header.
- The web and websocket processes must share the application key, database and
  cache store. `SSH_TERMINAL_CACHE_STORE` defaults to the application's cache
  store. Shared Redis or a shared file-cache directory is required; an in-memory
  per-process cache is not suitable in production.
- The WebSocket process needs permission to launch PHP child processes using
  `proc_open`. Run it as the existing unprivileged application user. Each active
  SSH session uses one bounded child process, not the node-event loop.
- The feature is **off by default**. After dependency/security review and a
  controlled WSS check, explicitly set `SSH_TERMINAL_ENABLED=true`. Turning it
  off requires reloading the web application and restarting the websocket
  service so long-lived processes load the new configuration. The websocket
  restart closes active SSH sessions. This does not change the node realtime
  switch. Token/role/profile revocation is checked separately every five seconds.

## Access And Host Identity

Only a non-banned administrator with a valid Sanctum token carrying `server:ssh`
or `*` can issue a connection ticket. Staff, customer and machine tokens cannot
open terminals. Each machine has a separate host/port/username/host-key profile;
that profile is hidden from generic machine serialization.

Before first use, the administrator probes the host and compares its SHA256
host-key fingerprint against an independent server console. Probing does **not**
authenticate and does not silently trust the host. Saving requires an explicit
confirmation bound to the same administrator, machine and target within two
minutes. The key is checked again before sending an SSH password or signature.
A changed key stops login. Never accept a replacement fingerprint solely because
the panel offers it; confirm it through the provider console first.

IP addresses and DNS names are accepted. Every resolved address is checked, and
the chosen literal IP is pinned for that connection to prevent DNS rebinding.
Loopback, private and reserved destinations are blocked by default. Operators
may explicitly allow individual private IPs with comma-separated
`SSH_TERMINAL_PRIVATE_HOSTS`; there is no browser override. Cloud metadata and
link-local targets remain forbidden even when listed.

## Credentials And Session Lifecycle

SSH passwords, private keys and key passphrases are supplied per connection.
They are not stored in the machine profile. An encrypted, one-use cache record
holds them for at most 45 seconds and is consumed by the session worker. Closing
a pending browser request may leave that encrypted record until its short TTL.

The browser sends the random connection ticket in the **first WebSocket frame**,
not in the URL. The ticket is origin-bound, single-admission and single-use.
Authentication, role, token expiry and profile changes are rechecked during the
session. Logout, permission revocation or changed profile closes the shell.

The worker allows one session per administrator and four active sessions per
websocket worker. Sessions last at most 20 minutes and close after 10 minutes
without terminal input. Disconnecting or closing the browser stops the local
child and its SSH connection. A foreground remote command may be interrupted;
this is not a durable job scheduler and does not promise that remote background
processes are terminated.

Input/output rates, frame sizes, send buffers and terminal dimensions are bounded.
The terminal supports UTF-8 output, PTY resizing, interrupt, selection copying and
screen clearing. Multiline/control-character pastes require confirmation. Output
is rendered only through xterm.js, not injected as HTML; no remote clipboard or
link-handling addon is enabled.

Audit entries record administrative profile/ticket actions and session lifecycle
metadata, not passwords, private keys, passphrases, terminal output or keystrokes.
Do not add raw websocket frames, exception objects or SSH debug logs to production
logging. The admin page and its JavaScript are part of the trust boundary: an XSS
or compromised admin asset can compromise an active terminal. Review application
dependency/security advisories before enabling this on a public deployment.

## Verification Scope

Automated tests cover tickets, replay/origin/role boundaries, leases, target
policy, pre-authentication host-key checks, credential redaction and UI cleanup.
The loopback SSH fixture verifies password and encrypted-key login, rejected keys
and passwords, UTF-8 input/output and RFC 4254 window-change messages. Browser
preview screenshots use a **simulated** WebSocket peer; they are not evidence of
a deployed production SSH session. Deployment still needs a controlled WSS
end-to-end check through the installation's actual reverse proxy.
