"""Linux CI-only sandbox and socket probes; all destinations are synthetic."""

import errno
import json
import os
from pathlib import Path
import signal
import socket
import sys
import time


SOCKET = "/run/keli-failover-example/worker.sock"


def blocked(fn, accepted):
    try:
        handle = fn()
    except OSError as error:
        return error.errno in accepted
    handle.close()
    return False


def check_environment():
    state = Path(os.environ["FAILOVER_EXAMPLE_STATE_DIR"])
    uid = int(os.environ["CI_FIXTURE_WORKER_UID"])
    before = os.environ["CI_FIXTURE_PARENT_NETNS"]
    mock = state.parent / "forbidden"
    status = dict(line.split(":", 1) for line in Path("/proc/self/status").read_text().splitlines() if ":" in line)
    with socket.socket(socket.AF_UNIX):
        pass
    checks = {
        "dedicated_user": uid > 0 and os.getuid() == os.geteuid() == uid,
        "no_capabilities": int(status["CapEff"], 16) == 0 and int(status["CapBnd"], 16) == 0,
        "no_new_privileges": status["NoNewPrivs"].strip() == "1",
        "private_network_namespace": os.readlink("/proc/self/ns/net") != before,
        "ipv4_socket_denied": blocked(lambda: socket.socket(socket.AF_INET), {errno.EAFNOSUPPORT, errno.EPERM}),
        "ipv6_socket_denied": blocked(lambda: socket.socket(socket.AF_INET6), {errno.EAFNOSUPPORT, errno.EPERM}),
        "no_database_fixture_read": blocked(lambda: (mock / "db.fixture").open("rb"), {errno.EACCES, errno.ENOENT}),
        "no_redis_fixture_read": blocked(lambda: (mock / "redis.fixture").open("rb"), {errno.EACCES, errno.ENOENT}),
        "outside_state_not_writable": blocked(lambda: (state.parent / "outside-write-trap" / "test").open("xb"),
                                             {errno.EACCES, errno.EROFS, errno.EPERM}),
        "no_panel_or_provider_environment": not any(name in os.environ for name in
            ("APP_KEY", "DB_HOST", "DB_PASSWORD", "REDIS_HOST", "LINODE_TOKEN", "ALIYUN_ACCESS_KEY_SECRET",
             "GITHUB_TOKEN", "ACTIONS_RUNTIME_TOKEN", "SSH_AUTH_SOCK")),
    }
    tmp = state / ("sandbox-check-" + str(os.getpid()) + ".tmp")
    tmp.write_text(json.dumps(checks))
    tmp.replace(state / "sandbox-check.json")
    if not all(checks.values()):
        raise SystemExit(1)


def receive(mode):
    with socket.socket(socket.AF_UNIX) as stream:
        stream.settimeout(18)
        if mode == "deny":
            try:
                stream.connect(SOCKET)
            except PermissionError as error:
                print(json.dumps({"permission_denied": error.errno == errno.EACCES}))
                return
            print(json.dumps({"permission_denied": False}))
            return
        start = time.monotonic()
        stream.connect(SOCKET)
        if mode == "hold":
            stream.sendall(b"{")
            try:
                reply = stream.recv(1024)
            except ConnectionResetError:
                reply = b""
            print(json.dumps({"closed_without_report": not reply, "elapsed_ms": round((time.monotonic() - start) * 1000)}))
        elif mode == "drop_dns":
            import hashlib
            base = ":".join(os.environ["FAILOVER_EXAMPLE_" + key] for key in ("GRANT_ID", "ADMIN_ID", "OPENS_AT", "EXPIRES_AT"))
            who = hashlib.sha256(base.encode()).hexdigest()
            stream.sendall((json.dumps({"grant": who, "action": "dns"}) + "\n").encode())
            # No receive: the next actual admin read must find the committed result.
            print(json.dumps({"reply_discarded": True}))


if __name__ == "__main__":
    arg, = sys.argv[1:]
    if arg == "inspect":
        check_environment()
    elif arg in {"deny", "hold", "drop_dns"}:
        receive(arg)
    elif arg == "stubborn":
        signal.signal(signal.SIGTERM, signal.SIG_IGN)
        Path(os.environ["FAILOVER_EXAMPLE_STATE_DIR"], "stubborn-ready").touch(exist_ok=False)
        time.sleep(90)
    else:
        raise SystemExit(2)
