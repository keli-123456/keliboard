"""Disposable runner-only packet fixture. No uplink, provider, DNS or actual WireGuard."""

import asyncio
import fcntl
import hashlib
import json
import os
from pathlib import Path
import shutil
import signal
import socket
import ssl
import subprocess
import sys
import tempfile
import time
import uuid


REPO = Path(__file__).resolve().parents[2]
PUBLIC = "1.2.3.10"
ALLOWED = "1.2.3.4"
OTHER = "1.2.3.5"
PUBLIC6 = "2001:db8:731::10"
OTHER6 = "2001:db8:731::20"
PRIVATE = "10.198.123.1"
PRIVATE_SRC = "10.198.123.2"
PRIVATE_BAD = "10.198.123.3"
DEVICE = "wg-keli123"
PY = "/usr/bin/python3"
IP = "/usr/sbin/ip"
NFT = "/usr/sbin/nft"
SYSTEMCTL = "/usr/bin/systemctl"
ENV = {"PATH": "/usr/sbin:/usr/bin:/sbin:/bin", "LC_ALL": "C"}


def require(ok, reason):
    if not ok:
        raise RuntimeError(reason)


def run(*args, data=None, ok=(0,), timeout=12):
    out = subprocess.run(args, input=data, capture_output=True, text=True, env=ENV, timeout=timeout)
    require(out.returncode in ok, f"{Path(args[0]).name} failed ({out.returncode}): {out.stderr[:300]}")
    return out


async def serve(kind, work):
    async def conn(reader, writer):
        try:
            if await asyncio.wait_for(reader.read(256), timeout=2):
                writer.write(b"HTTP/1.1 204 No Content\r\nConnection: close\r\n\r\n")
                await writer.drain()
        finally:
            writer.close()

    class Echo(asyncio.DatagramProtocol):
        def connection_made(self, transport):
            self.transport = transport

        def datagram_received(self, data, addr):
            if data == b"keli123-fixture":
                self.transport.sendto(data, addr)

    if kind == "baseline":
        servers = [await asyncio.start_server(conn, PUBLIC, 43000)]
    else:
        tls = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        tls.load_cert_chain(work / "tls.pem", work / "tls.key")
        servers = []
        udp = []
        for family, host in ((socket.AF_INET, "0.0.0.0"), (socket.AF_INET6, "::")):
            for proto, port in ((socket.SOCK_STREAM, 443), (socket.SOCK_DGRAM, 51821)):
                s = socket.socket(family, proto)
                if family == socket.AF_INET6:
                    s.setsockopt(socket.IPPROTO_IPV6, socket.IPV6_V6ONLY, 1)
                s.bind((host, port))
                s.setblocking(False)
                if proto == socket.SOCK_STREAM:
                    servers.append(await asyncio.start_server(conn, sock=s, ssl=tls))
                else:
                    udp.append(await asyncio.get_running_loop().create_datagram_endpoint(Echo, sock=s))
    (work / (kind + ".ready")).touch()
    try:
        await asyncio.Event().wait()
    finally:
        for server in servers:
            server.close()


def probe(mode, src, dest, work):
    family = socket.AF_INET6 if ":" in src else socket.AF_INET
    with socket.socket(family, socket.SOCK_DGRAM if mode == "udp" else socket.SOCK_STREAM) as s:
        s.bind((src, 0))
        s.settimeout(0.65)
        try:
            if mode == "udp":
                s.sendto(b"keli123-fixture", (dest, 51821))
                require(s.recvfrom(64)[0] == b"keli123-fixture", "wrong UDP response")
            else:
                s.connect((dest, 43000 if mode == "baseline" else 443))
                if mode == "tls":
                    ctx = ssl.create_default_context(cafile=work / "tls.pem")
                    with ctx.wrap_socket(s, server_hostname="receipt-123.fixture.test") as app:
                        app.sendall(b"GET / HTTP/1.1\r\nHost: receipt-123.fixture.test\r\n\r\n")
                        require(app.recv(256).startswith(b"HTTP/1.1 204"), "unexpected HTTPS response")
                else:
                    s.sendall(b"GET / HTTP/1.1\r\n\r\n")
                    require(s.recv(256).startswith(b"HTTP/1.1 204"), "unrelated TCP response changed")
        except TimeoutError:
            return 40
    return 0


DRIVER = '''#!/usr/bin/python3 -I
import sys,json,subprocess,time
from pathlib import Path
base = Path(__file__).resolve().parent
sys.path.insert(0, str(base))
import usdo1_host_guard as guard
guard.HOST = "1.2.3.10"
guard.ENTRY = str(base / "entry")
guard.CONF_DIR = base / "conf"
guard.STATE_DIR = base / "state"
ns = (base / "isolated-namespace").read_text().strip()
original = guard.Host
def invoke(args, **kwargs):
    if args[0] == "/usr/bin/systemd-run":
        args = args[:1] + ["--property=NetworkNamespacePath=/run/netns/" + ns] + args[1:]
    return subprocess.run(args, **kwargs)
class FixtureHost(original):
    def __init__(self):
        super().__init__(invoke)
    def link_state(self, s):
        native = super().link_state(s)
        if native == "foreign":
            links = json.loads(self.run("ip", "-j", "-d", "link", "show"))
            exact = [x for x in links if x.get("ifname") == guard.DEVICE and x.get("ifalias") == s.alias
                     and x.get("linkinfo", {}).get("info_kind") == "veth"]
            if len(exact) == 1:
                return "owned"
        return native
guard.Host = FixtureHost
hold = sys.argv[1:] == ["fixture-control"]
if hold:
    sys.argv[1:] = ["arm"]
try:
    guard.main()
except Exception as error:
    print(type(error).__name__ + ":" + str(error) if isinstance(error, guard.Refused) else type(error).__name__, file=sys.stderr)
    raise SystemExit(1)
if hold:
    (base / "control.ready").touch()
    time.sleep(100)
'''


def wait_for(check, label, seconds=12):
    until = time.monotonic() + seconds
    while time.monotonic() < until:
        if check():
            return
        time.sleep(0.1)
    raise RuntimeError(label + " did not become ready")


def main(work):
    require(os.geteuid() == 0 and Path("/proc/1/comm").read_text().strip() == "systemd", "isolated CI runner required")
    round_id = uuid.uuid4()
    token = round_id.hex
    a, b = "keli-sut-" + token[:8], "keli-client-" + token[:8]
    callback = "keli123-test-callback-" + token + ".service"
    timer = "keli123-expire-" + token
    unit = Path("/run/systemd/system") / callback
    peers = [("ea"+token[:7], "eb"+token[:7]), ("wa"+token[:7], "wb"+token[:7])]
    opened = []
    baseline = control = None
    fixture = Path(__file__).resolve()
    def ns(name, *cmd, **kwargs):
        return run(IP, "netns", "exec", name, *cmd, **kwargs)
    def ip(name, *cmd):
        return run(IP, "-n", name, *cmd)
    def pair(names, left, right):
        p, q = names
        run(IP, "link", "add", p, "type", "veth", "peer", "name", q)
        run(IP, "link", "set", p, "netns", a)
        run(IP, "link", "set", q, "netns", b)
        ip(a, "link", "set", p, "name", left)
        ip(b, "link", "set", q, "name", right)
        ip(a, "link", "set", left, "up")
        ip(b, "link", "set", right, "up")
    def sends(mode, src, dst, allowed):
        answer = ns(b, PY, "-B", str(fixture), "probe", mode, src, dst, str(work), ok=(0, 40))
        require(answer.returncode == (0 if allowed else 40), f"unexpected {mode} verdict: {src} to {dst}")
    def original_hash():
        return hashlib.sha256(ns(a, NFT, "-j", "list", "table", "inet", "fixture_baseline").stdout.encode()).hexdigest()
    def active(name):
        return run(SYSTEMCTL, "show", "--property=ActiveState", "--value", name).stdout.strip()
    def no_default():
        for name in (a, b):
            for fam in ("-4", "-6"):
                require(not ip(name, fam, "route", "show", "default").stdout.strip(), "test namespace has an uplink")
    def kill(proc):
        if proc is not None:
            if proc.poll() is None:
                os.killpg(proc.pid, signal.SIGKILL)
            proc.wait(5)
    try:
        for name in (a, b):
            run(IP, "netns", "add", name)
            opened.append(name)
            ip(name, "link", "set", "lo", "up")
        pair(peers[0], "eth0", "public0")
        ip(a, "addr", "add", PUBLIC+"/24", "dev", "eth0")
        ip(b, "addr", "add", ALLOWED+"/24", "dev", "public0")
        ip(b, "addr", "add", OTHER+"/24", "dev", "public0")
        ip(a, "-6", "addr", "add", PUBLIC6+"/64", "dev", "eth0", "nodad")
        ip(b, "-6", "addr", "add", OTHER6+"/64", "dev", "public0", "nodad")
        no_default()
        ns(a, NFT, "-f", "-", data="""table inet fixture_baseline {
  chain incoming { type filter hook input priority 0; policy accept;
    iifname "eth0" tcp dport 43000 accept
  }
}
""")
        original = original_hash()
        baseline = subprocess.Popen([IP, "netns", "exec", a, PY, "-B", str(fixture), "serve", "baseline", str(work)],
                                    start_new_session=True, env=ENV, stdin=subprocess.DEVNULL)
        wait_for(lambda: (work/"baseline.ready").exists(), "unrelated baseline")
        sends("baseline", OTHER, PUBLIC, True)

        (work/"conf").mkdir(mode=0o700)
        (work/"state").mkdir(mode=0o700)
        (work/"state/operation.lock").touch(mode=0o600)
        shutil.copyfile(REPO/"tools/machine-acceptance/usdo1_host_guard.py", work/"usdo1_host_guard.py")
        (work/"entry").write_text(DRIVER, encoding="ascii")
        (work/"entry").chmod(0o700)
        (work/"isolated-namespace").write_text(a, encoding="ascii")
        run("/usr/bin/openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1",
            "-subj", "/CN=receipt-123.fixture.test", "-addext", "subjectAltName=DNS:receipt-123.fixture.test",
            "-keyout", str(work/"tls.key"), "-out", str(work/"tls.pem"))
        unit_text = f"""[Unit]
Description=Service 123 isolated packet fixture
[Service]
NetworkNamespacePath=/run/netns/{a}
ExecStart={PY} -B {fixture} serve leased {work}
Restart=no
NoNewPrivileges=yes
"""
        with unit.open("x", encoding="ascii") as file:
            file.write(unit_text)
        run(SYSTEMCTL, "daemon-reload")
        now = int(time.time())
        scope = {"task":str(round_id),"instance_id":"501","source_ipv4":ALLOWED,"candidate_private_ipv4":PRIVATE_SRC,
                 "callback_private_ipv4":PRIVATE,"issued_at":now-2,"withdraw_at":now+45,
                 "boot_id":Path("/proc/sys/kernel/random/boot_id").read_text().strip(),
                 "callback_unit_sha256":hashlib.sha256(unit_text.encode()).hexdigest(),"approved":True}
        (work/"conf/round.json").write_text(json.dumps(scope), encoding="ascii")
        (work/"conf/round.json").chmod(0o600)
        with (work/"control.log").open("wb") as log:
            control = subprocess.Popen([IP, "netns", "exec", a, str(work/"entry"), "fixture-control"],
                                       stdout=log, stderr=log, start_new_session=True, env=ENV)
        def control_ready():
            require(control.poll() is None, "control arm failed: "+(work/"control.log").read_text()[:400])
            return (work/"control.ready").exists()
        wait_for(control_ready, "host guard")
        pair(peers[1], DEVICE, "private0")
        ip(a, "link", "set", DEVICE, "alias", "keli123-"+token)
        ip(a, "addr", "add", PRIVATE+"/24", "dev", DEVICE)
        ip(b, "addr", "add", PRIVATE_SRC+"/24", "dev", "private0")
        ip(b, "addr", "add", PRIVATE_BAD+"/24", "dev", "private0")
        no_default()
        run(SYSTEMCTL, "start", callback)
        wait_for(lambda: (work/"leased.ready").exists(), "isolated endpoints")
        require(original_hash() == original, "unrelated nft rules changed when arming")
        sends("baseline", OTHER, PUBLIC, True)
        sends("udp", ALLOWED, PUBLIC, True)
        sends("udp", OTHER, PUBLIC, False)
        sends("udp", OTHER6, PUBLIC6, False)
        sends("tls", PRIVATE_SRC, PRIVATE, True)
        sends("tls", PRIVATE_BAD, PRIVATE, False)
        sends("tls", ALLOWED, PUBLIC, False)
        sends("tls", OTHER6, PUBLIC6, False)

        kill(control)
        require(control.returncode == -signal.SIGKILL, "control was not force-killed")
        with (work/"state/operation.lock").open("r+b") as hold:
            # Delay the cleanup executor, not the kernel deadline.
            fcntl.flock(hold, fcntl.LOCK_EX)
            time.sleep(max(0, scope["withdraw_at"] - time.time() + 1))
            require(active(callback) == "active", "cleanup ran before the independent packet test")
            sends("udp", ALLOWED, PUBLIC, False)
            sends("tls", PRIVATE_SRC, PRIVATE, False)
            sends("baseline", OTHER, PUBLIC, True)
            require(original_hash() == original, "unrelated nft rules changed while expiry was blocked")
        state = work/"state"/(token+".json")
        wait_for(lambda: json.loads(state.read_bytes())["phase"] == "withdrawn", "independent cleanup", 18)
        require(active(callback) == "inactive", "test listener still active")
        require(DEVICE not in ip(a, "-br", "link").stdout, "owned fixture link remains")
        require("keli123_"+token not in ns(a, NFT, "list", "tables").stdout, "owned rules remain")
        require(not ns(a, "/usr/bin/ss", "-H", "-lntu", "( sport = :443 or sport = :51821 )").stdout.strip(),
                "leased ports still listening")
        require(original_hash() == original, "unrelated nft rules changed after cleanup")
        sends("baseline", OTHER, PUBLIC, True)
        print("USDO1_HOST_GUARD_CI " + json.dumps({"udp_candidate_only":True,"https_private_only":True,
            "kernel_expired_while_cleanup_blocked":True,"control_sigkill":True,"independent_systemd_cleanup":True,
            "baseline_rules_and_traffic_unchanged":True,"private_media":"isolated_veth_no_wireguard",
            "temporary_test_ports_closed":True}, sort_keys=True), flush=True)
    finally:
        kill(control)
        run(SYSTEMCTL, "stop", callback, timer+".timer", timer+".service", ok=(0, 1, 5))
        unit.unlink(missing_ok=True)
        run(SYSTEMCTL, "daemon-reload")
        kill(baseline)
        for name in reversed(opened):
            run(IP, "netns", "del", name)
        visible = {row["ifname"] for row in json.loads(run(IP, "-j", "link", "show").stdout)}
        for p, q in peers:
            for name in (p, q):
                if name in visible:
                    run(IP, "link", "del", name)


if __name__ == "__main__":
    if len(sys.argv) == 4 and sys.argv[1] == "serve":
        asyncio.run(serve(sys.argv[2], Path(sys.argv[3])))
    elif len(sys.argv) == 6 and sys.argv[1] == "probe":
        sys.exit(probe(sys.argv[2], sys.argv[3], sys.argv[4], Path(sys.argv[5])))
    else:
        os.umask(0o077)
        with tempfile.TemporaryDirectory(prefix="keli123-guard-ci-", dir="/run") as d:
            main(Path(d))
