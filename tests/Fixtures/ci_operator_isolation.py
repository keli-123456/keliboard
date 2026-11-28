import configparser
import json
import os
import pathlib
import secrets
import shutil
import subprocess
import sys
import tempfile
import time


ROOT = pathlib.Path(__file__).resolve().parents[2]
CASE = sys.argv[1]
assert CASE in {"timeout", "recovery"}
assert os.environ.get("GITHUB_ACTIONS") == "true"
assert os.environ.get("GITHUB_WORKFLOW") == "Manual failover failure journal"
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
ENV["KELI_OPERATOR_FIXTURE_PASS"] = secrets.token_hex(24)
ENV["KELI_NATIVE_HORIZON_ACCEPTANCE"] = "1"


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
    assert state["shared_failed_unchanged"] and state["shared_listener"] == 0


def load(name):
    parsed = configparser.ConfigParser(interpolation=None)
    parsed.optionxform = str
    with (ROOT / "docs/operations" / ("keliboard-failover-operator" + name)).open() as f:
        parsed.read_file(f)
    return parsed


def install_units(directory, installed):
    environment = directory / "fixture.env"
    run_state = directory / "state"
    run_state.mkdir(mode=0o700)
    journal = run_state / "failure-state.json"
    ENV["KELI_OPERATOR_FIXTURE_JOURNAL"] = str(run_state)
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
            service["ExecStart"] = " ".join(task(action))
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
    return journal


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

    journal = install_units(pathlib.Path(temporary.name), installed)

    def journal_state():
        if not journal.exists():
            return {}
        assert journal.stat().st_mode & 0o777 == 0o600
        raw = journal.read_text()
        value = json.loads(raw)
        assert len(raw) < 8192 and set(value) == {"schema", "active", "failures", "worker_error"}
        for entry in value["failures"].values() if isinstance(value["failures"], dict) else []:
            assert set(entry) <= {"delivery", "started_at", "reason", "at", "exception", "file", "line"}
            assert entry["reason"] in {"worker_interrupted", "job_failed", "job_timeout"}
            assert len(entry["delivery"]) == 64
        return value

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
    assert status()["acl_checks"] == 12
    sudo("systemctl", "start", TIMER)
    call("launch")
    started = time.monotonic()
    pid = int(show("MainPID"))
    assert pid != business.pid
    delivery = wait_for(lambda: journal_state().get("active"), 18, "Manual delivery marker missing")
    barrier = "provider_barrier" if CASE == "recovery" else "preflight_barrier"
    def entered():
        s = status()
        assert s["run"] != "needs_review", "Manual path stopped before the simulated provider barrier"
        return s if s[barrier] == 1 else None
    first = wait_for(entered, 18, "Mock provider barrier missing")
    assert first["runtime_state"] == "processing" and not first["provider_known"]
    business_checkpoint(business, 2, int(CASE == "recovery"))
    if CASE == "timeout":
        # Leave the current oneshot running, but do not let the periodic timer
        # replace its timeout result with a new invocation before readback.
        process_proof(wait_for(lambda: status()["timer_process"], 24, "Timer did not run the advance service"))
        sudo("systemctl", "stop", TIMER)

    evidence = {"case": CASE, "cpu_percent": 25, "memory_bytes": 192 * 1024 * 1024, "processes": 8, "redis_denials": 12}
    if CASE == "recovery":
        assert 270 <= first["member_lock_ttl"] <= 300, "Live member lock did not use its real TTL"
        sudo("systemctl", "kill", "--signal=KILL", "--kill-whom=main", UNIT)
        wait_for(lambda: (new := status()["worker_process"]) and new["pid"] != pid, 20, "Worker did not restart")
        interrupted = wait_for(lambda: (j := journal_state()["failures"]) and j.get(delivery["delivery"]),
                               12, "Killed delivery not archived after restart")
        assert interrupted["reason"] == "worker_interrupted" and interrupted["started_at"] == delivery["started_at"]
        early = status()
        assert early["recovered_events"] == 0 and not early["provider_known"], "Original lease was bypassed"
        def restored():
            s = status()
            assert s["run"] == "running" and s["creates_and_dns"] == 1 and not s["unexpected_acl"]
            return s if s["recovered_events"] >= 1 and s["provider_known"] else None
        recovered = wait_for(restored, 360, "Leased task never resumed its original create intent")
        elapsed = round(time.monotonic() - started, 2)
        assert elapsed >= 285 and recovered["candidate_attempts"] == 1 and recovered["creates_and_dns"] == 1
        assert recovered["task_signature"] == first["task_signature"]
        assert journal_state()["failures"][delivery["delivery"]] == interrupted
        call("revoke")
        wait_for(lambda: status()["run"] == "stopped", 150, "Timer did not settle the revoked original lease")
        process_proof(status()["timer_process"])
        sudo("systemctl", "stop", TIMER, ADVANCE, UNIT, timeout=90)
        call("read-close")
        assert status()["closed"] == 1
        evidence.update(timer_non_root=True, sigkill_first_error=True, original_task_resumed=True, stopped_after_revocation=True,
                        original_readback=True, closure_verified=True, simulated_creates=1, recovery_wait_seconds=elapsed,
                        initial_member_lock_ttl=first["member_lock_ttl"])
    else:
        wait_for(lambda: show("ActiveState") in {"failed", "inactive"}, 96, "Manual worker did not stop within the fixture deadline")
        timeout = journal_state()["failures"][delivery["delivery"]]
        assert timeout["reason"] == "job_timeout" and 73 <= timeout["at"]-delivery["started_at"] <= 80
        wait_for(lambda: show("Result", ADVANCE) == "timeout", 20, "Advance process did not hard time out")
        elapsed = (int(show("ExecMainExitTimestampMonotonic", ADVANCE)) - int(show("ExecMainStartTimestampMonotonic", ADVANCE))) / 1e6
        assert 28 <= elapsed <= 42, "Advance unit did not stop at its hard deadline"
        process_proof(status()["timer_process"])
        evidence.update(job_timeout_seconds=timeout["at"]-delivery["started_at"], timer_timeout_seconds=round(elapsed, 2), timer_non_root=True)
    business_checkpoint(business, 3, int(CASE == "recovery"))
    wait_for(lambda: not status()["ready"], 18, "Dead worker heartbeat survived its TTL")
    if CASE == "timeout":
        wait_for(lambda: json.loads(call("stop"))["run"] == "stopped", 60, "Original timed-out lease did not settle")
    state = status()
    assert state["run"] == "stopped" and state["operator_generation"] >= 2
    assert state["legacy_failover"] == 0 and state["legacy_admin"] == 1 and state["creates_and_dns"] == int(CASE == "recovery")
    evidence.update(stat_receipts=3, traffic_receipts=3, legacy_receipts=1, old_queue_reroutes=0, shared_failed_jobs_writes=0,
                    production_writes=0, worker_non_root=True)
    print(json.dumps(evidence, sort_keys=True))
except Exception:
    if seeded:
        try:
            state = status()
            keys = ("run", "runtime_state", "runtime_reason", "member_phase", "member_reason", "worker_error",
                    "timer_ticks", "provider_barrier", "preflight_barrier", "creates_and_dns", "acl_checks", "shared_listener",
                    "unexpected_acl")
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
