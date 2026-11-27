import configparser
import json
import os
import pathlib
import secrets
import shutil
import signal
import subprocess
import sys
import time


ROOT = pathlib.Path(__file__).resolve().parents[2]
CASE = sys.argv[1]
assert CASE in {"kill", "timeout", "limits"}
assert os.environ.get("GITHUB_ACTIONS") == "true"
assert os.environ.get("GITHUB_WORKFLOW") == "Manual failover isolated process limits"
assert os.getuid() != 0
OWNER = secrets.token_hex(16)
UNIT = f"keli-operator-ci-{OWNER}.service"
CLI = ROOT / "tests/Fixtures/ci_operator_isolation.php"
PHP = shutil.which("php")
assert PHP
ENV_NAMES = ["GITHUB_ACTIONS", "GITHUB_WORKFLOW", "KELI_NATIVE_ACCEPTANCE", "KELI_NATIVE_HOST",
             "KELI_NATIVE_DATABASE", "KELI_NATIVE_USER", "KELI_NATIVE_PASSWORD", "KELI_NATIVE_ISOLATION",
             "KELI_NATIVE_MYSQL_PORT", "KELI_NATIVE_REDIS_PORT"]
ENV = {key: os.environ[key] for key in ENV_NAMES}
ENV["PATH"] = "/usr/local/bin:/usr/bin:/bin"


def task(action, memory="160M"):
    return [PHP, "-d", f"memory_limit={memory}", str(CLI), OWNER, action, CASE]


def call(action):
    return subprocess.check_output(task(action), cwd=ROOT, env=ENV, timeout=16, text=True).strip()


def status():
    return json.loads(call("status"))


def wait_for(check, limit, reason):
    deadline = time.monotonic() + limit
    while time.monotonic() < deadline:
        result = check()
        if result:
            return result
        time.sleep(0.25)
    raise RuntimeError(reason)


def show(key):
    return subprocess.check_output(["systemctl", "show", UNIT, f"--property={key}", "--value"], text=True, timeout=5).strip()


def business_checkpoint(worker, ordinal):
    call("produce")
    def applied():
        assert worker.poll() is None, "Existing lanes stopped"
        state = status()
        return state if state["traffic_receipts"] == ordinal and state["stat_receipts"] == ordinal else None
    state = wait_for(applied, 12, "Existing lanes did not settle")
    assert state["stat"] == 5 * ordinal and state["traffic_u"] == 11 * ordinal and state["traffic_d"] == 13 * ordinal
    assert state["legacy"] == "succeeded" and state["legacy_steps"] == 1
    assert state["legacy_admin"] == 1 and state["legacy_failover"] == 0 and state["creates_and_dns"] == 0


config = configparser.ConfigParser(interpolation=None)
config.optionxform = str
config.read(ROOT / "docs/operations/keliboard-failover-operator.service")
profile = {key: config["Service"][key] for key in ("CPUQuota", "MemoryMax", "MemorySwapMax", "OOMPolicy", "TasksMax", "RuntimeMaxSec", "TimeoutStopSec")}
assert profile == {"CPUQuota": "25%", "MemoryMax": "192M", "MemorySwapMax": "0", "OOMPolicy": "stop",
                   "TasksMax": "8", "RuntimeMaxSec": "17min", "TimeoutStopSec": "85s"}
assert not subprocess.check_output(["systemctl", "list-units", "--all", "--no-legend", UNIT], text=True, timeout=5).strip()
business = None
seeded = False
try:
    call("seed")
    seeded = True
    business = subprocess.Popen(task("business"), cwd=ROOT, env=ENV, start_new_session=True)
    business_checkpoint(business, 1)

    subprocess.run(["sudo", "-n", "systemd-run", "--quiet", "--unit", UNIT, f"--uid={os.getuid()}",
                    f"--gid={os.getgid()}", f"--working-directory={ROOT}", "--property=Type=exec",
                    "--property=IPAddressDeny=any", "--property=IPAddressAllow=localhost", "--property=NoNewPrivileges=yes",
                    *[f"--property={key}={value}" for key, value in profile.items()],
                    *[f"--setenv={key}={value}" for key, value in ENV.items()], "--",
                    *task("worker", "-1" if CASE == "limits" else "160M")], check=True, timeout=10)
    wait_for(lambda: status()["ready"], 18, "Independent worker did not publish a heartbeat")
    cgroup = show("ControlGroup")
    assert cgroup == f"/system.slice/{UNIT}", "Unexpected unit ownership"
    path = pathlib.Path("/sys/fs/cgroup" + cgroup)
    quota, period = map(int, (path / "cpu.max").read_text().split())
    assert quota * 4 == period and int((path / "memory.max").read_text()) == 192 * 1024 * 1024
    assert (path / "memory.swap.max").read_text().strip() == "0"
    assert int((path / "pids.max").read_text()) == 8
    call("launch")
    started = time.monotonic()
    pid = wait_for(lambda: status()["entered"], 18, "Manual job never reached its own queue worker")
    assert str(pid) == show("MainPID") and pid != business.pid
    business_checkpoint(business, 2)

    evidence = {"case": CASE, "cpu_percent": 25, "memory_bytes": 192 * 1024 * 1024, "processes": 8}
    if CASE == "kill":
        subprocess.run(["sudo", "-n", "systemctl", "kill", "--signal=KILL", "--kill-whom=main", UNIT], check=True, timeout=8)
    elif CASE == "limits":
        wait_for(lambda: status()["cpu"], 12, "CPU exercise did not finish")
        stats = dict(line.split() for line in (path / "cpu.stat").read_text().splitlines())
        assert int(stats["nr_throttled"]) > 0, "CPU quota did not throttle"
        evidence["throttled_periods"] = int(stats["nr_throttled"])
        call("allocate")
        wait_for(lambda: status()["allocation_started"] == 1, 10, "Allocation never reached the cgroup")
        assert status()["allocation_exceeded"] == 0, "Allocation crossed the hard cap without OOM"

    wait_for(lambda: show("ActiveState") in {"failed", "inactive"}, 96, "Manual worker did not stop within the fixture deadline")
    if CASE == "timeout":
        assert status()["timeouts"] == 1 and 70 <= time.monotonic() - started <= 95
        evidence["job_timeout"] = True
    if CASE == "limits":
        assert show("Result") == "oom-kill", "Memory limit did not cause the expected cgroup OOM"
        evidence["kernel_oom"] = True
    if CASE == "kill":
        assert show("ExecMainStatus") == str(signal.SIGKILL)
    business_checkpoint(business, 3)
    wait_for(lambda: not status()["ready"], 18, "Dead worker heartbeat survived its TTL")
    state = json.loads(call("stop"))
    assert state["run"] == "stopped" and state["operator_generation"] == 2
    assert state["legacy_failover"] == 0 and state["legacy_admin"] == 1 and state["creates_and_dns"] == 0
    evidence.update(stat_receipts=3, traffic_receipts=3, legacy_receipts=1, old_queue_reroutes=0, production_writes=0)
    print(json.dumps(evidence, sort_keys=True))
finally:
    subprocess.run(["sudo", "-n", "systemctl", "stop", UNIT], timeout=12, check=False)
    if business is not None and business.poll() is None:
        business.terminate()
        try:
            business.wait(timeout=8)
        except subprocess.TimeoutExpired:
            business.kill()
            business.wait(timeout=5)
    if seeded:
        call("cleanup")
