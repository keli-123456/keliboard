#!/usr/bin/python3 -I
"""Closed-by-default service 123 host guard; the CLI has no caller-chosen paths."""

import contextlib
import hashlib
import ipaddress
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import sys
import time
from dataclasses import dataclass


HOST = "152.53.37.160"
UNDERLAY = "eth0"
DEVICE = "wg-keli123"
UDP = 51821
TCP = 443
MAX_WORK_SECONDS = 85 * 60
ENTRY = "/usr/local/libexec/keli123-host-guard"
CONF_DIR = Path("/etc/keli123-host-guard")
STATE_DIR = Path("/var/lib/keli123-host-guard")
PRIVATE = tuple(ipaddress.IPv4Network(v) for v in ("10.0.0.0/8", "172.16.0.0/12", "192.168.0.0/16"))


class Refused(RuntimeError):
    pass


def need(condition, reason):
    if not condition:
        raise Refused(reason)


def shape(pattern, value):
    return isinstance(value, str) and re.fullmatch(pattern, value) is not None


def ipv4(value):
    need(isinstance(value, str), "guard_address_invalid")
    try:
        return ipaddress.IPv4Address(value)
    except ipaddress.AddressValueError:
        raise Refused("guard_address_invalid") from None


def decode(raw):
    def unique(pairs):
        result = {}
        for k, v in pairs:
            need(k not in result, "guard_duplicate_json_key")
            result[k] = v
        return result
    return json.loads(raw, object_pairs_hook=unique)


@dataclass(frozen=True)
class Scope:
    task: str
    instance_id: str
    source_ipv4: str
    candidate_private_ipv4: str
    callback_private_ipv4: str
    issued_at: int
    withdraw_at: int
    boot_id: str
    callback_unit_sha256: str
    approved: bool = False

    def __post_init__(self):
        for value in (self.task, self.boot_id):
            need(shape(r"[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}", value), "guard_id_invalid")
        need(shape(r"[1-9][0-9]{0,15}", self.instance_id)
             and shape(r"[a-f0-9]{64}", self.callback_unit_sha256)
             and type(self.approved) is bool, "guard_scope_invalid")
        outer, client, local = map(ipv4, (self.source_ipv4, self.candidate_private_ipv4, self.callback_private_ipv4))
        need(outer.is_global and not outer.is_multicast and not outer.is_reserved and str(outer) != HOST
             and client != local and any(client in n and local in n for n in PRIVATE), "guard_route_scope_invalid")
        need(type(self.issued_at) is int and type(self.withdraw_at) is int
             and 0 < self.issued_at < self.withdraw_at <= min(self.issued_at + MAX_WORK_SECONDS, 2**53),
             "guard_deadline_invalid")

    @property
    def token(self):
        return self.task.replace("-", "")

    @property
    def table(self):
        return "keli123_" + self.token

    @property
    def owner(self):
        return "owner_" + self.token

    @property
    def timer(self):
        return "keli123-expire-" + self.token

    @property
    def callback(self):
        return "keli123-test-callback-" + self.token + ".service"

    @property
    def alias(self):
        return "keli123-" + self.token

    @property
    def fingerprint(self):
        return hashlib.sha256(json.dumps(vars(self), sort_keys=True).encode("ascii")).hexdigest()


def rules(scope, seconds):
    need(type(seconds) is int and 0 < seconds <= MAX_WORK_SECONDS, "guard_lease_invalid")
    t = scope.table
    return f"""create table inet {t}
create chain inet {t} {scope.owner}
create set inet {t} outer4 {{ type ipv4_addr; flags timeout; size 1; }}
create set inet {t} inner4 {{ type ipv4_addr; flags timeout; size 1; }}
add element inet {t} outer4 {{ {scope.source_ipv4} timeout {seconds}s }}
add element inet {t} inner4 {{ {scope.candidate_private_ipv4} timeout {seconds}s }}
create chain inet {t} incoming {{ type filter hook input priority -150; policy accept; }}
add rule inet {t} incoming iifname "{UNDERLAY}" ip daddr {HOST} ip saddr @outer4 udp dport {UDP} accept
add rule inet {t} incoming udp dport {UDP} drop
add rule inet {t} incoming iifname "{DEVICE}" ip daddr {scope.callback_private_ipv4} ip saddr @inner4 tcp dport {TCP} accept
add rule inet {t} incoming iifname "{DEVICE}" drop
add rule inet {t} incoming tcp dport {TCP} drop
create chain inet {t} forwarding {{ type filter hook forward priority -150; policy accept; }}
add rule inet {t} forwarding iifname "{DEVICE}" drop
add rule inet {t} forwarding oifname "{DEVICE}" drop
"""


class Guard:
    """Mutations require storage and an independently verified timer before accepting any packet."""

    def __init__(self, scope, kernel, ledger, *, now, monotonic, boot):
        self.scope, self.kernel, self.ledger = scope, kernel, ledger
        self.now, self.monotonic, self.boot = now, monotonic, boot

    def _state(self, phase):
        self.ledger.write({"scope": self.scope.fingerprint, "phase": phase})

    def _read(self):
        state = self.ledger.read()
        need(state is None or (isinstance(state, dict) and set(state) == {"scope", "phase"}
             and state["scope"] == self.scope.fingerprint
             and state["phase"] in ("arming", "armed", "withdrawing", "withdrawn")), "guard_state_changed")
        return None if state is None else state["phase"]

    def arm(self):
        s = self.scope
        need(s.approved and self.boot() == s.boot_id, "guard_not_approved_or_rebooted")
        now = self.now()
        remaining = s.withdraw_at - now
        need(s.issued_at <= now and remaining > 25, "guard_window_closed")
        finish = self.monotonic() + remaining
        need(self._read() is None, "guard_round_spent")
        need(self.kernel.ready(s), "guard_preflight_collision")
        self._state("arming")
        try:
            delay = int(min(s.withdraw_at - self.now(), finish - self.monotonic())) - 10
            need(delay > 10, "guard_window_closed")
            self.kernel.schedule(s, delay)
            need(self.kernel.timer_bound(s), "guard_timer_unconfirmed")
            self.kernel.check(rules(s, 1))
            need(self.kernel.timer_bound(s), "guard_timer_unconfirmed")
            # Recalculate after syntax check and reserve the full nft write timeout.
            ttl = int(min(s.withdraw_at - self.now(), finish - self.monotonic())) - 10
            need(ttl > 5, "guard_window_closed")
            self.kernel.add(s, rules(s, ttl))
            need(self.kernel.timer_bound(s) and self.kernel.table_state(s) == "owned", "guard_arm_unconfirmed")
            self._state("armed")
        except (Refused, OSError, ValueError, TypeError, KeyError, subprocess.SubprocessError):
            try:
                self.withdraw()
            except (Refused, OSError, ValueError, TypeError, KeyError, subprocess.SubprocessError):
                raise Refused("guard_cleanup_unconfirmed") from None
            raise
        return {"state": "armed", "starts_callback_or_tunnel": False}

    def status(self):
        return {"state": self._read() or "closed", "table": self.kernel.table_state(self.scope),
                "link": self.kernel.link_state(self.scope), "callback": self.kernel.callback_state(self.scope),
                "expiry_bound": self.kernel.timer_bound(self.scope)}

    def withdraw(self):
        s = self.scope
        old = self._read()
        if old is None:
            return {"state": "closed"}
        if old == "withdrawn":
            need(self.kernel.table_state(s) == self.kernel.link_state(s) == self.kernel.callback_state(s) == "absent",
                 "guard_cleanup_drifted")
            return {"state": "withdrawn"}
        self._state("withdrawing")
        table = self.kernel.table_state(s)
        need(table != "foreign", "guard_foreign_table")
        if table == "owned":
            self.kernel.fence(s)
        callback, link = self.kernel.callback_state(s), self.kernel.link_state(s)
        need("foreign" not in (callback, link), "guard_foreign_resource")
        if callback == "owned":
            self.kernel.stop_callback(s)
        if link == "owned":
            self.kernel.remove_link(s)
        need(self.kernel.callback_state(s) == self.kernel.link_state(s) == "absent", "guard_cleanup_unconfirmed")
        if table == "owned":
            need(self.kernel.table_state(s) == "owned", "guard_foreign_table")
            self.kernel.remove_table(s)
        need(self.kernel.table_state(s) == "absent", "guard_cleanup_unconfirmed")
        self._state("withdrawn")
        # Keep the timer: its later invocation is a readback and never rearms anything.
        return {"state": "withdrawn"}


def root_dir(path):
    info = path.lstat()
    need(stat.S_ISDIR(info.st_mode) and info.st_uid == 0 and stat.S_IMODE(info.st_mode) == 0o700
         and path.resolve() == path, "guard_root_directory_invalid")


def root_file(path, limit, strict=True):
    with os.fdopen(os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK), "rb") as f:
        info = os.fstat(f.fileno())
        need(stat.S_ISREG(info.st_mode) and info.st_uid == 0 and info.st_nlink == 1
             and not stat.S_IMODE(info.st_mode) & (0o077 if strict else 0o022)
             and 0 < info.st_size <= limit, "guard_root_file_invalid")
        result = f.read(limit + 1)
        need(0 < len(result) <= limit, "guard_root_file_invalid")
        return result


class Ledger:
    def __init__(self, scope):
        self.path = STATE_DIR / (scope.token + ".json")

    def read(self):
        try:
            return decode(root_file(self.path, 2048))
        except FileNotFoundError:
            return None

    def write(self, record):
        import tempfile
        fd, raw_path = tempfile.mkstemp(prefix=".keli123-", dir=STATE_DIR)
        path = Path(raw_path)
        try:
            with os.fdopen(fd, "w", encoding="ascii") as f:
                json.dump(record, f, sort_keys=True)
                f.flush()
                os.fsync(f.fileno())
            os.replace(path, self.path)
            fd = os.open(STATE_DIR, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
            try:
                os.fsync(fd)
            finally:
                os.close(fd)
        finally:
            path.unlink(missing_ok=True)


class Host:
    PATHS = {"nft": "/usr/sbin/nft", "ip": "/usr/sbin/ip", "ss": "/usr/bin/ss",
             "systemctl": "/usr/bin/systemctl", "systemd-run": "/usr/bin/systemd-run"}

    def __init__(self, invoke=subprocess.run):
        self.invoke = invoke

    def run(self, name, *args, data=None):
        read = {"stdin": subprocess.DEVNULL} if data is None else {"input": data}
        result = self.invoke([self.PATHS[name], *args], **read, capture_output=True, text=True, timeout=8,
                             cwd="/", env={"PATH": "/usr/sbin:/usr/bin", "LC_ALL": "C"})
        need(result.returncode == 0 and len(result.stdout) <= 65536, "guard_host_read_or_write_failed")
        return result.stdout

    def _units(self, unit, properties):
        out = self.run("systemctl", "show", "--no-pager", "--property=" + ",".join(properties), unit)
        return dict(line.split("=", 1) for line in out.splitlines() if "=" in line)

    def ready(self, s):
        address = decode(self.run("ip", "-j", "-4", "address", "show", "dev", UNDERLAY))
        pub = [ip.get("local") for link in address if link.get("ifname") == UNDERLAY for ip in link.get("addr_info", ())]
        ports = self.run("ss", "-H", "-lntu", f"( sport = :{UDP} or sport = :{TCP} )").strip()
        return (HOST in pub and not ports and self.table_state(s) == self.link_state(s) == self.callback_state(s) == "absent"
                and self._units(s.timer + ".timer", ("LoadState",)).get("LoadState") == "not-found")

    def schedule(self, s, seconds):
        self.run("systemd-run", "--quiet", "--unit=" + s.timer, "--on-active=" + str(seconds) + "s",
                 "--timer-property=AccuracySec=1s", "--timer-property=RemainAfterElapse=no",
                 "--property=Type=oneshot", "--property=TimeoutStartSec=65s",
                 "--property=NoNewPrivileges=yes", "--property=CapabilityBoundingSet=CAP_NET_ADMIN",
                 ENTRY, "expire", s.fingerprint)

    def timer_bound(self, s):
        unit = self._units(s.timer + ".timer", ("ActiveState", "SubState", "Triggers", "NextElapseUSecMonotonic"))
        next_run = unit.pop("NextElapseUSecMonotonic", "")
        return next_run not in ("", "0", "0us", "n/a", "infinity") \
            and unit == {"ActiveState": "active", "SubState": "waiting", "Triggers": s.timer + ".service"}

    def table_state(self, s):
        rows = decode(self.run("nft", "-j", "list", "tables"))["nftables"]
        if not any((row.get("table", {}).get("family"), row.get("table", {}).get("name"))
                   == ("inet", s.table) for row in rows):
            return "absent"
        rows = decode(self.run("nft", "-j", "list", "table", "inet", s.table))["nftables"]
        chains = {r["chain"]["name"] for r in rows if "chain" in r}
        sets = {r["set"]["name"] for r in rows if "set" in r}
        rules_by_chain = [r["rule"]["chain"] for r in rows if "rule" in r]
        return "owned" if chains == {s.owner, "incoming", "forwarding"} and sets == {"outer4", "inner4"} \
            and sorted(rules_by_chain) == ["forwarding"] * 2 + ["incoming"] * 5 else "foreign"

    def link_state(self, s):
        rows = [v for v in decode(self.run("ip", "-j", "-d", "link", "show")) if v.get("ifname") == DEVICE]
        if not rows:
            return "absent"
        return "owned" if len(rows) == 1 and rows[0].get("ifalias") == s.alias \
            and rows[0].get("linkinfo", {}).get("info_kind") == "wireguard" else "foreign"

    def callback_state(self, s):
        u = self._units(s.callback, ("LoadState", "ActiveState", "SubState", "FragmentPath", "DropInPaths", "Restart"))
        if u.get("LoadState") == "not-found":
            return "absent" if u.get("ActiveState") == "inactive" else "foreign"
        path = Path("/run/systemd/system") / s.callback
        if u.get("LoadState") != "loaded" or u.get("FragmentPath") != str(path) or u.get("DropInPaths", "") \
                or u.get("Restart") != "no":
            return "foreign"
        if hashlib.sha256(root_file(path, 32768, strict=False)).hexdigest() != s.callback_unit_sha256:
            return "foreign"
        return "absent" if u.get("ActiveState") == "inactive" and u.get("SubState") == "dead" else "owned"

    def check(self, conf):
        self.run("nft", "-c", "-f", "-", data=conf)

    def add(self, s, conf):
        self.run("nft", "-f", "-", data=conf)

    def fence(self, s):
        self.run("nft", "-f", "-", data=f"flush set inet {s.table} outer4\nflush set inet {s.table} inner4\n")

    def stop_callback(self, s):
        need(self.callback_state(s) == "owned", "guard_foreign_resource")
        self.run("systemctl", "stop", s.callback)

    def remove_link(self, s):
        need(self.link_state(s) == "owned", "guard_foreign_resource")
        self.run("ip", "link", "delete", "dev", DEVICE)

    def remove_table(self, s):
        need(self.table_state(s) == "owned", "guard_foreign_table")
        self.run("nft", "delete", "table", "inet", s.table)


@contextlib.contextmanager
def root_lock():
    import fcntl
    fd = os.open(STATE_DIR / "operation.lock", os.O_NOFOLLOW | os.O_RDWR | os.O_NONBLOCK)
    with os.fdopen(fd, "r+b") as f:
        info = os.fstat(f.fileno())
        need(stat.S_ISREG(info.st_mode) and info.st_uid == 0 and info.st_nlink == 1
             and stat.S_IMODE(info.st_mode) == 0o600, "guard_lock_invalid")
        limit = time.monotonic() + 40
        while True:
            try:
                fcntl.flock(f, fcntl.LOCK_EX | fcntl.LOCK_NB)
                break
            except BlockingIOError:
                need(time.monotonic() < limit, "guard_lock_timeout")
                time.sleep(0.1)
        yield


def main():
    need(sys.platform == "linux" and os.geteuid() == 0, "guard_root_linux_required")
    args = sys.argv[1:]
    need((len(args) == 1 and args[0] in ("arm", "status", "withdraw"))
         or (len(args) == 2 and args[0] == "expire" and shape(r"[a-f0-9]{64}", args[1])), "guard_operation_denied")
    root_dir(CONF_DIR)
    root_dir(STATE_DIR)
    with root_lock():
        s = Scope(**decode(root_file(CONF_DIR / "round.json", 4096)))
        need(args[0] != "expire" or args[1] == s.fingerprint, "guard_expiry_scope_changed")
        guard = Guard(s, Host(), Ledger(s), now=lambda: int(time.time()), monotonic=time.monotonic,
                      boot=lambda: Path("/proc/sys/kernel/random/boot_id").read_text(encoding="ascii").strip())
        op = args[0]
        result = guard.arm() if op == "arm" else guard.status() if op == "status" else guard.withdraw()
    print(json.dumps(result, sort_keys=True))


if __name__ == "__main__":
    try:
        main()
    except (Refused, OSError, ValueError, TypeError, KeyError, subprocess.SubprocessError) as error:
        print(str(error) if isinstance(error, Refused) else "guard_failed_closed", file=sys.stderr)
        raise SystemExit(1)
