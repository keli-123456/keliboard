import configparser
import json
import os
import pathlib
import secrets
import shutil
import signal
import subprocess
import sys
import tempfile
import time


ROOT = pathlib.Path(__file__).resolve().parents[2]
CASE = sys.argv[1]
assert CASE in {"kill", "timeout", "limits", "recovery"}
assert os.environ.get("GITHUB_ACTIONS") == "true"
assert os.environ.get("GITHUB_WORKFLOW") == "Manual failover isolated process limits"
assert os.getuid() != 0
OWNER = secrets.token_hex(16)
UNIT = f"keli-operator-ci-{OWNER}.service"
ADVANCE = f"keli-operator-ci-{OWNER}-a.service"
TIMER = f"keli-operator-ci-{OWNER}-a.timer"
UNIT_DIR = pathlib.Path("/run/systemd/system")
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


def show(key, unit=UNIT):
    return subprocess.check_output(["systemctl", "show", unit, f"--property={key}", "--value"], text=True, timeout=5).strip()


def sudo(*args, check=True, timeout=12):
    return subprocess.run(["sudo", "-n", *args], check=check, timeout=timeout)


def business_checkpoint(worker, ordinal, simulated_writes=0):
    call("produce")
    def applied():
        assert worker.poll() is None, "Existing lanes stopped"
        state = status()
        return state if (state["traffic_receipts"] == ordinal and state["stat_receipts"] == ordinal
                         and state["queue_stat"] == state["queue_traffic"] == 0) else None
    state = wait_for(applied, 12, "Existing lanes did not settle")
    assert state["stat"] == 5 * ordinal and state["traffic_u"] == 11 * ordinal and state["traffic_d"] == 13 * ordinal
    assert state["legacy"] == "succeeded" and state["legacy_steps"] == 1
    assert state["legacy_admin"] == 1 and state["legacy_failover"] == 0 and state["creates_and_dns"] == simulated_writes
    assert state["queue_stat"] == 0 and state["queue_traffic"] == 0


def load(name):
    parsed = configparser.ConfigParser(interpolation=None)
    parsed.optionxform = str
    with (ROOT / "docs/operations" / ("keliboard-failover-operator" + name)).open() as f:
        parsed.read_file(f)
    return parsed


def install_units(directory, installed):
    environment = directory / "fixture.env"
    run_state = directory / "state"
    run_state.mkdir()
    paths = [UNIT_DIR / unit for unit in (UNIT, ADVANCE, TIMER)]
    assert all(not p.exists() and not p.is_symlink() for p in paths)
    environment.write_text("".join(f"{k}={v}\n" for k, v in ENV.items()))
    environment.chmod(0o600)
    for cfg, filename, action in [(config, UNIT, "worker"), (advance, ADVANCE, "timer"), (timer, TIMER, None)]:
        if action:
            # Keep the unit hardening and timing; substitute the isolated database fixture.
            service = cfg["Service"]
            cfg["Unit"]["ConditionPathExists"] = str(environment)
            service["User"] = str(os.getuid())
            service["Group"] = str(os.getgid())
            service["WorkingDirectory"] = str(ROOT)
            service["EnvironmentFile"] = str(environment)
            service["Environment"] = f"APP_CONFIG_CACHE={directory / 'nonexistent-cache.php'}"
            service["ExecStart"] = " ".join(task(action, "-1" if CASE == "limits" and action == "worker" else "160M"))
            service["ReadWritePaths"] = str(run_state)
            service["IPAddressDeny"] = "any"
            service["IPAddressAllow"] = "localhost"
            if action == "worker" and CASE != "recovery":
                service["Restart"] = "no"
            if action == "timer":
                cfg["Unit"]["Requisite"] = cfg["Unit"]["After"] = UNIT
        else:
            cfg["Timer"]["Unit"] = ADVANCE
        source = directory / filename
        with source.open("w") as out:
            cfg.write(out, space_around_delimiters=False)
        installed.append(UNIT_DIR / filename)
        sudo("install", "-o", "root", "-g", "root", "-m", "0644", str(source), str(installed[-1]))
    sudo("systemd-analyze", "verify", *map(str, installed))
    sudo("systemctl", "daemon-reload")


def process_proof(value, expected_pid=None):
    quota, period = map(int, value["cpu"].split())
    assert quota * 4 == period and int(value["memory"]) == 192 * 1024 * 1024
    assert value["swap"] == "0" and value["tasks"] == "8"
    assert value["uid"] == os.getuid() != 0 and value["gid"] == os.getgid()
    if expected_pid is not None:
        assert value["pid"] == expected_pid


config = load(".service")
advance = load("-advance.service")
timer = load("-advance.timer")
profile = {key: config["Service"][key] for key in ("CPUQuota", "MemoryMax", "MemorySwapMax", "OOMPolicy", "TasksMax", "RuntimeMaxSec", "TimeoutStopSec")}
assert profile == {"CPUQuota": "25%", "MemoryMax": "192M", "MemorySwapMax": "0", "OOMPolicy": "stop",
                   "TasksMax": "8", "RuntimeMaxSec": "17min", "TimeoutStopSec": "85s"}
assert advance["Service"]["TimeoutStartSec"] == "30s" and timer["Timer"]["OnUnitActiveSec"] == "15s"
assert all(advance["Service"][k] == profile[k] for k in ("CPUQuota", "MemoryMax", "MemorySwapMax", "OOMPolicy", "TasksMax"))
assert not subprocess.check_output(["systemctl", "list-units", "--all", "--no-legend", UNIT], text=True, timeout=5).strip()
business = None
seeded = False
installed = []
temporary = tempfile.TemporaryDirectory(prefix="operator-units-", dir=ROOT / "artifacts")
try:
    call("seed")
    seeded = True
    business = subprocess.Popen(task("business"), cwd=ROOT, env=ENV, start_new_session=True)
    business_checkpoint(business, 1)

    install_units(pathlib.Path(temporary.name), installed)
    sudo("systemctl", "start", UNIT)
    wait_for(lambda: status()["ready"], 18, "Independent worker did not publish a heartbeat")
    cgroup = show("ControlGroup")
    assert cgroup == f"/system.slice/{UNIT}", "Unexpected unit ownership"
    path = pathlib.Path("/sys/fs/cgroup" + cgroup)
    quota, period = map(int, (path / "cpu.max").read_text().split())
    assert quota * 4 == period and int((path / "memory.max").read_text()) == 192 * 1024 * 1024
    assert (path / "memory.swap.max").read_text().strip() == "0"
    assert int((path / "pids.max").read_text()) == 8
    process_proof(status()["worker_process"], int(show("MainPID")))
    if CASE in {"timeout", "recovery"}:
        sudo("systemctl", "start", TIMER)
    call("launch")
    started = time.monotonic()
    pid = wait_for(lambda: status()["entered"], 18, "Manual job never reached its own queue worker")
    assert str(pid) == show("MainPID") and pid != business.pid
    if CASE == "recovery":
        wait_for(lambda: status()["provider_barrier"] == 1, 14, "Mock create never reached its acknowledgement boundary")
    business_checkpoint(business, 2, int(CASE == "recovery"))
    if CASE == "timeout":
        # Leave the current oneshot running, but do not let the periodic timer
        # replace its timeout result with a new invocation before readback.
        process_proof(wait_for(lambda: status()["timer_process"], 24, "Timer did not run the advance service"))
        sudo("systemctl", "stop", TIMER)

    evidence = {"case": CASE, "cpu_percent": 25, "memory_bytes": 192 * 1024 * 1024, "processes": 8}
    if CASE in {"kill", "recovery"}:
        if CASE == "recovery":
            call("revoke")
            state = status()
            assert state["stop_evidence"] == 1 and state["run"] == "running" and state["closed"] == 0
        sudo("systemctl", "kill", "--signal=KILL", "--kill-whom=main", UNIT)
    elif CASE == "limits":
        wait_for(lambda: status()["cpu"], 12, "CPU exercise did not finish")
        stats = dict(line.split() for line in (path / "cpu.stat").read_text().splitlines())
        assert int(stats["nr_throttled"]) > 0, "CPU quota did not throttle"
        evidence["throttled_periods"] = int(stats["nr_throttled"])
        call("allocate")
        wait_for(lambda: status()["allocation_started"] == 1, 10, "Allocation never reached the cgroup")
        assert status()["allocation_exceeded"] == 0, "Allocation crossed the hard cap without OOM"

    if CASE == "recovery":
        wait_for(lambda: (new := status()["worker_process"]) and new["pid"] != pid, 20, "Worker did not restart")
        wait_for(lambda: status()["run"] == "stopped", 150, "Timer did not settle the revoked original lease")
        state = status()
        assert state["timer_ticks"] >= 2 and state["creates_and_dns"] == 1
        process_proof(state["timer_process"])
        sudo("systemctl", "stop", TIMER, ADVANCE, UNIT, timeout=90)
        call("read-close")
        assert status()["closed"] == 1
        evidence.update(timer_non_root=True, stopped_after_revocation=True, original_readback=True, closure_verified=True, simulated_creates=1)
    else:
        wait_for(lambda: show("ActiveState") in {"failed", "inactive"}, 96, "Manual worker did not stop within the fixture deadline")
    if CASE == "timeout":
        assert status()["timeouts"] == 1 and 70 <= time.monotonic() - started <= 95
        wait_for(lambda: show("Result", ADVANCE) == "timeout", 20, "Advance process did not hard time out")
        elapsed = (int(show("ExecMainExitTimestampMonotonic", ADVANCE)) - int(show("ExecMainStartTimestampMonotonic", ADVANCE))) / 1e6
        assert 28 <= elapsed <= 42, "Advance unit did not stop at its hard deadline"
        process_proof(status()["timer_process"])
        evidence.update(job_timeout=True, timer_timeout_seconds=round(elapsed, 2), timer_non_root=True)
    if CASE == "limits":
        assert show("Result") == "oom-kill", "Memory limit did not cause the expected cgroup OOM"
        evidence["kernel_oom"] = True
    if CASE == "kill":
        assert show("ExecMainStatus") == str(signal.SIGKILL)
    business_checkpoint(business, 3, int(CASE == "recovery"))
    wait_for(lambda: not status()["ready"], 18, "Dead worker heartbeat survived its TTL")
    state = status() if CASE == "recovery" else json.loads(call("stop"))
    assert state["run"] == "stopped" and state["operator_generation"] == 2
    assert state["legacy_failover"] == 0 and state["legacy_admin"] == 1 and state["creates_and_dns"] == int(CASE == "recovery")
    evidence.update(stat_receipts=3, traffic_receipts=3, legacy_receipts=1, old_queue_reroutes=0, production_writes=0, worker_non_root=True)
    print(json.dumps(evidence, sort_keys=True))
except Exception:
    if seeded:
        try:
            state = status()
            keys = ("run", "runtime_state", "runtime_reason", "member_phase", "member_reason", "worker_error",
                    "timer_ticks", "provider_barrier", "creates_and_dns", "timeouts")
            print("CI_DIAGNOSTIC", json.dumps({k: state.get(k) for k in keys}), flush=True)
        except Exception:
            pass
    for unit in (UNIT, ADVANCE, TIMER):
        diagnostic = {k: show(k, unit) for k in ("ActiveState", "SubState", "Result", "ExecMainCode", "ExecMainStatus", "ConditionResult")}
        print("CI_DIAGNOSTIC", unit, json.dumps(diagnostic), flush=True)
        logs = subprocess.run(["sudo", "-n", "journalctl", "-u", unit, "-n", "18", "--no-pager", "-o", "cat"],
                              check=False, capture_output=True, text=True, timeout=5).stdout
        for line in logs.splitlines():
            if line.startswith("Fixture failed:") or any(s in line for s in ("Failed with result", "timed out", "status=", "skipped")):
                print("CI_DIAGNOSTIC", line[:250], flush=True)
    raise
finally:
    if installed:
        sudo("systemctl", "stop", TIMER, ADVANCE, UNIT, timeout=92, check=False)
        sudo("rm", "-f", "--", *map(str, installed))
        sudo("systemctl", "daemon-reload")
    if business is not None and business.poll() is None:
        business.terminate()
        try:
            business.wait(timeout=8)
        except subprocess.TimeoutExpired:
            business.kill()
            business.wait(timeout=5)
    if seeded:
        call("cleanup")
    temporary.cleanup()
