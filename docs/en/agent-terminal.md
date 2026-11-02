# Agent Terminal

Server management defaults to the Agent terminal. Traditional SSH remains a
separate fallback and still requires independent host-key verification and SSH
credentials. Agent mode never requests an SSH password or private key.

## Authorization

The feature requires all three independent grants:

1. The panel operator enables `AGENT_TERMINAL_ENABLED=true`, reloads the web
   application and restarts `ws-server` after installing the migration.
2. A non-banned administrator with a current `server:terminal` (or `*`) Sanctum
   token explicitly authorizes the individual machine in its terminal dialog.
3. The node operator locally enables `realtime.terminal_enabled: true`. This
   setting is never taken from panel-provided node settings. The Agent must
   reconnect after the local configuration is applied.

An upgraded Linux Agent advertises protocol 1 and local availability through its
existing authenticated control connection. Older agents and unsupported hosts
fail closed; the panel does not silently fall back to executing SSH commands.
An existing machine-authenticated control connection is required. An HTTP-only
or unbound machine without one remains on SSH fallback: this implementation
does not add an always-on idle websocket just to provide terminals.
The agent-side setting is shared by this Agent's configured panel connections;
only configure panels you trust to administer this host.
Web and websocket processes must share the application key, database and cache
store. `AGENT_TERMINAL_CACHE_STORE` defaults to `CACHE_DRIVER`; use shared Redis
or the same file-cache directory, never separate per-process array stores.

## Lifecycle And Isolation

The browser receives a 45-second, origin-bound, encrypted, one-use connection
ticket. Only after browser admission does the panel send a 30-second random
capability to exactly one authenticated machine control connection. A machine
token alone cannot open a terminal. The reverse terminal connection must present
both that machine's current token and the single-use capability.

Only the invitation uses the control channel. PTY input/output use a separate
WSS connection and a separate transient systemd service, not the proxy runtime,
configuration-update queue or user-sync loop. Credentials and capabilities are
not placed in URLs, process arguments or persistent files. The shell environment
is cleared. Terminal output and keystrokes are not added to application logs;
only administrator/machine IDs and session lifecycle metadata are audited.

The helper and shell run with the Agent's effective UID/GID, not automatically
as root. With a root Agent, this is privileged host administration. Resource
limits are guardrails, **not a security sandbox for hostile root commands**.
An administrator can still deliberately affect business by changing services,
files, network settings or starting workloads outside the terminal's cgroup.
Do not claim arbitrary shell commands are business-impact-free.

Per Agent: one active terminal. Per panel administrator and machine: one active
session. Per websocket worker: at most four sessions. A session lasts at most
20 minutes and closes after 10 minutes without input. Missing browser heartbeats
close the relay after 45 seconds; a helper without renewed panel authorization
exits within 15 seconds. Role/token/machine authorization and token rotation are
rechecked every five seconds. Disconnecting the selected control connection
also closes its terminal. There is no automatic reconnect or command replay.

## Resource Budget

- No terminal helper, PTY, extra connection, timer or thread is created on an
  unused node. A small optional control-message field and compiled code remain;
  this is not a promise of literally zero binary-size or memory change.
- Active sessions use `CPUQuota=10%` of one core, `MemoryMax=64M`, no swap,
  `TasksMax=16`, low CPU/I/O priority and a 20-minute runtime limit.
- The helper verifies its own cgroup identity and actual CPU/memory/task/swap
  limits **before connecting or spawning a shell**. No systemd/cgroup v2 CPU,
  memory or pids controllers means no Agent terminal. There is no unbounded
  fallback.
- PTY queues are bounded to eight 8 KiB chunks per direction. Websocket frames
  are at most 16 KiB. Input/output rate and browser send queues are bounded;
  excessive output or backpressure closes the terminal instead of accumulating
  memory. Terminal output cannot enter the node's control channel.
- Closing the helper stops the service's entire cgroup, including background
  shell children. The original proxy service is not restarted or reconfigured.
  The parent reaps the launcher on the next existing control message; it does
  not introduce an idle polling task.
- The stop command is asynchronous and is reaped with the launcher. A new
  terminal is not launched while cleanup is pending or unconfirmed, avoiding
  overlapping helpers or accumulating unreaped cleanup processes.

## Verification Boundary

Unit tests cover origin/replay/role/token/capability/machine boundaries,
revocation, relay limits, control disconnect and idle lease expiry. The browser
preview is synthetic, not a real server login. Linux cross-compilation is not
evidence of live PTY/cgroup behavior, RSS, proxy throughput or UDP latency.
Keep the production feature off until an isolated Linux integration and
idle/active resource comparison through the actual WSS proxy have passed.
