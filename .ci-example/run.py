"""Run exact candidate and rollback assets with Linux's real socket supervisor."""

from contextlib import closing
from hashlib import sha256
import grp
import io
import json
import os
from pathlib import Path
import pwd
import re
import secrets
import shutil
import sqlite3
import stat
import subprocess
import sys
import tarfile
import time
import traceback


SOURCE = "0e92f73dc987156d65bb2399eaddf904d607330f"
ROLLBACK = "984af21185a71879425a9796aadc22ef8f745d62"
BRANCH = "audit/failover-example-linux-20261119-0e92f73d"
REPO = "keli-123456/keliboard"
SOCKET = Path("/run/keli-failover-example/worker.sock")


class Rejected(RuntimeError):
    pass


def need(condition, reason):
    if not condition:
        raise Rejected(reason)


def call(*args, env=None, timeout=20, check=True):
    result = subprocess.run(args, capture_output=True, env=env, timeout=timeout)
    if check and result.returncode:
        # Never print an arbitrary subprocess command, env, or raw output.
        reason = Path(args[0]).name.replace("-", "_")
        raise Rejected("failed_" + reason)
    return result


def text(*args):
    return call(*args).stdout.decode().strip()


def system(*args, check=True):
    return call("systemctl", "--no-ask-password", *args, check=check).stdout.decode().strip()


def properties(name, *keys):
    return dict(line.split("=", 1) for line in system("show", name,
                *("--property=" + key for key in keys)).splitlines() if "=" in line)


def eventually(check, seconds, reason):
    end = time.monotonic() + seconds
    while time.monotonic() < end:
        value = check()
        if value:
            return value
        time.sleep(.06)
    raise Rejected(reason)


class Lab:
    def __init__(self, workspace, temp, php):
        self.workspace, self.temp, self.php = workspace, temp, php
        self.run = os.environ["GITHUB_RUN_ID"]
        attempt = os.environ["GITHUB_RUN_ATTEMPT"]
        need(re.fullmatch("[0-9]{1,14}", self.run) and re.fullmatch("[0-9]{1,3}", attempt), "bad_run_identity")
        self.prefix = "keli-example-linux-" + self.run + "-" + attempt
        self.area = Path("/run", self.prefix)
        self.units = Path("/run/systemd/system")
        self.names = (self.prefix + ".socket", self.prefix + "@.service", self.prefix + "-hardkill.service")
        self.worker = "keliw" + self.run
        self.panel = "kelip" + self.run
        self.other = "kelio" + self.run
        self.socket_group = "kelis" + self.run
        self.users = []
        self.group_made = False
        self.made = False
        self.unit_files = []
        self.stage = "scope"
        self.state = self.area / "state"
        now = int(time.time())
        self.env = dict(FAILOVER_EXAMPLE_GRANT_ID=secrets.token_hex(16), FAILOVER_EXAMPLE_ADMIN_ID="42",
                        FAILOVER_EXAMPLE_OPENS_AT=str(now - 10), FAILOVER_EXAMPLE_EXPIRES_AT=str(now + 420),
                        FAILOVER_EXAMPLE_STATE_DIR=str(self.state))
        self.proof = {}

    def advance(self, name):
        self.stage = name
        print("acceptance: " + name, flush=True)

    def export(self, label, revision, asset_source):
        archive = call("git", "-c", "safe.directory=" + str(self.workspace), "-c", "core.autocrlf=false",
                       "-c", "core.eol=lf", "-C", self.workspace, "archive", revision, timeout=25).stdout
        target = self.area / label
        target.mkdir(mode=0o755)
        with tarfile.open(fileobj=io.BytesIO(archive)) as export:
            export.extractall(target, filter="data")
        shutil.copytree(self.workspace / "vendor", target / "vendor")
        need(not (target / ".env").exists(), "panel_environment_present")
        assets = target / "public/assets/admin-xboard"
        manifest = json.loads((assets / "build-manifest.json").read_text())
        need(manifest["source_git_short_sha"] == asset_source and manifest["source_dirty"] is False, "asset_revision_mismatch")
        html = (assets / "index.html").read_text()
        for name, info in manifest["files"].items():
            content = (assets / name).read_bytes()
            need(b"\r" not in content and len(content) == info["bytes"] and sha256(content).hexdigest() == info["sha256"],
                 "asset_hash_mismatch")
            if name.startswith("assets/"):
                need("/assets/admin-xboard/" + name + "?v=" + info["sha256"][:12] in html, "asset_version_mismatch")

    def prepare(self):
        self.advance("prepare_archives_and_identities")
        need(self.units.is_dir() and not self.area.exists() and not SOCKET.parent.exists()
             and not any((self.units / n).exists() for n in self.names), "fixture_paths_in_use")
        self.area.mkdir(mode=0o755)
        self.made = True
        self.export("candidate", SOURCE, "c718b74")
        self.export("rollback", ROLLBACK, "cac50ff")
        for name in ("client.php", "sandbox.py"):
            shutil.copyfile(self.workspace / ".ci-example" / name, self.area / name)
        self.worker_path = self.area / "candidate/tools/failover-example/worker.php"
        need(self.worker_path.is_file(), "worker_source_missing")
        self.proof["worker_sha256"] = sha256(self.worker_path.read_bytes()).hexdigest()
        call("groupadd", "--system", self.socket_group)
        self.group_made = True
        for user in (self.worker, self.panel, self.other):
            extras = ("--groups", self.socket_group) if user == self.panel else ()
            call("useradd", "--system", "--user-group", "--no-create-home", "--shell", "/usr/sbin/nologin", *extras, user)
            self.users.append(user)
        self.uid = pwd.getpwnam(self.worker).pw_uid
        self.gid = pwd.getpwnam(self.worker).pw_gid
        need(self.uid > 0 and len({self.uid, pwd.getpwnam(self.panel).pw_uid, pwd.getpwnam(self.other).pw_uid}) == 3, "identity_conflict")
        self.state.mkdir(mode=0o700)
        os.chown(self.state, self.uid, self.gid)
        mock = self.area / "forbidden"
        mock.mkdir(mode=0o755)
        for name in ("db.fixture", "redis.fixture"):
            f = mock / name
            f.write_text("synthetic sentinel\n")
            f.chmod(0o644)
        trap = self.area / "outside-write-trap"
        trap.mkdir()
        trap.chmod(0o777)
        call("runuser", "--user", self.worker, "--", "test", "-r", mock / "db.fixture")
        call("runuser", "--user", self.worker, "--", "test", "-r", mock / "redis.fixture")
        call("runuser", "--user", self.worker, "--", "test", "-w", trap)
        self.proof["candidate_and_rollback_asset_manifests_match"] = True

    def write_unit(self, which, body):
        path = self.units / self.names[which]
        with path.open("x", encoding="utf-8") as out:
            out.write(body)
        self.unit_files.append(path)

    def isolation(self, runtime):
        envs = self.env | {"CI_FIXTURE_WORKER_UID": str(self.uid), "CI_FIXTURE_PARENT_NETNS": os.readlink("/proc/self/ns/net")}
        return f"""[Service]
Type=exec
User={self.worker}
Group={self.worker}
WorkingDirectory={self.state}
UMask=0077
{"".join('Environment="' + key + '=' + val + '"\n' for key, val in envs.items())}NoNewPrivileges=yes
PrivateNetwork=yes
RestrictAddressFamilies=AF_UNIX
CapabilityBoundingSet=
RestrictNamespaces=yes
ProtectSystem=strict
ProtectHome=yes
PrivateTmp=yes
InaccessiblePaths={self.area / "forbidden"}
ReadWritePaths={self.state}
LimitCORE=0
TasksMax=8
MemoryMax=256M
TimeoutStartSec=4s
RuntimeMaxSec={runtime}s
TimeoutStopSec=1s
KillMode=control-group
SendSIGKILL=yes
Restart=no
"""

    def start(self):
        self.advance("socket_activation_and_permission")
        self.write_unit(0, f"""[Socket]
ListenStream={SOCKET}
SocketUser=root
SocketGroup={self.socket_group}
SocketMode=0660
DirectoryMode=0755
RemoveOnStop=yes
Accept=yes
MaxConnections=4
""")
        self.write_unit(1, self.isolation(13) + f"""ExecStartPre=/usr/bin/python3 -B {self.area / "sandbox.py"} inspect
ExecStart={self.php} -d allow_url_fopen=0 -d ffi.enable=0 {self.worker_path}
StandardInput=socket
StandardOutput=socket
StandardError=null
""")
        self.write_unit(2, self.isolation(2) + f"""ExecStart=/usr/bin/python3 -B {self.area / "sandbox.py"} stubborn
StandardOutput=null
StandardError=null
""")
        self.stage = "register_units"
        system("daemon-reload")
        self.stage = "start_socket"
        system("start", self.names[0])
        self.stage = "inspect_socket_inode"
        info = properties(self.names[0], "ActiveState", "SubState", "Listen", "Result")
        self.proof["socket_diagnostics"] = {
            "pid_one_systemd": Path("/proc/1/comm").read_text().strip() == "systemd",
            "active": info.get("ActiveState") == "active", "listening": info.get("SubState") == "listening",
            "listen_target_matches": str(SOCKET) in info.get("Listen", ""),
            "socket_directory_present": SOCKET.parent.is_dir(), "manager_clean": info.get("Result") == "success",
        }
        s = eventually(lambda: SOCKET.lstat() if SOCKET.exists() else None, 2, "socket_inode_missing")
        need(stat.S_ISSOCK(s.st_mode) and stat.S_IMODE(s.st_mode) == 0o660 and s.st_uid == 0
             and s.st_gid == grp.getgrnam(self.socket_group).gr_gid, "unexpected_socket_mode")
        denied = self.as_user(self.other, "/usr/bin/python3", "-B", self.area / "sandbox.py", "deny")
        need(denied == {"permission_denied": True}, "unrelated_user_reached_socket")
        self.proof["socket_mode_0660_and_unrelated_user_denied"] = True

    def as_user(self, user, *args, env=None, start=False):
        clean = {"PATH": "/usr/bin:/bin", "LANG": "C.UTF-8"} | (self.env if env is None else env)
        command = ["runuser", "--user", user, "--", *args]
        if start:
            return subprocess.Popen(command, env=clean, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        result = call(*command, env=clean, check=False)
        try:
            data = json.loads(result.stdout)
        except ValueError:
            raise Rejected("fixture_response_missing") from None
        if result.returncode:
            if isinstance(data.get("line"), int) and re.fullmatch(r"[a-zA-Z0-9_\\]{1,120}", data.get("class", "")):
                self.proof["fixture_failure"] = {"class": data["class"], "line": data["line"]}
            raise Rejected("fixture_client_failed")
        return data

    def web(self, action="status", actor="granted", code=200, root="candidate", env=None):
        result = self.as_user(self.panel, self.php, self.area / "client.php", self.area / root, actor, action, env=env)
        need(result["status"] == code, "unexpected_response_" + str(result["status"]))
        if code == 200:
            need(result.get("no_store") is True, "candidate_reply_cached")
            return result["report"]
        return result

    def accepted(self):
        value = properties(self.names[0], "NAccepted")["NAccepted"]
        need(value.isdigit(), "activation_count_unavailable")
        return int(value)

    def run_stages(self):
        self.advance("admin_gate_and_two_stage_rehearsal")
        n = self.accepted()
        for actor, status in (("none", 401), ("staff", 403), ("other", 404), ("banned", 404)):
            self.web(actor=actor, code=status)
        self.web(action="bad_input", code=422)
        expired = self.env | {"FAILOVER_EXAMPLE_EXPIRES_AT": str(int(time.time()) - 1),
                              "FAILOVER_EXAMPLE_OPENS_AT": str(int(time.time()) - 30)}
        self.web(code=404, env=expired)
        need(n == self.accepted(), "denied_request_reached_worker")
        need(self.web()["phase"] == "ready", "worker_not_ready")
        self.web("dns", code=409)
        v2 = self.web("v2")
        need(v2["phase"] == "v2_passed" and v2["dns"] is None and v2["live_operations"] == 0
             and v2["v2"] == {"failure_rounds": 3, "candidate_creates": 1, "b_unchanged": True}, "v2_result_mismatch")
        before = self.db_count(False)
        n = self.accepted()
        need(self.as_user(self.panel, "/usr/bin/python3", "-B", self.area / "sandbox.py", "drop_dns") == {"reply_discarded": True},
             "could_not_discard_socket_receipt")

        def readback():
            result = self.as_user(self.panel, self.php, self.area / "client.php", self.area / "candidate", "granted", "status")
            if result["status"] == 409:
                return None
            need(result["status"] == 200 and result["no_store"] is True, "could_not_read_original")
            report = result["report"]
            if report["phase"] == "v2_passed": return None
            return report

        final = eventually(readback, 8, "dns_readback_timeout")
        need(final["phase"] == "passed" and final["live_operations"] == 0 and final["v2"] == v2["v2"]
             and final["dns"] == {"a_updated": 4, "b_unchanged": 4, "receipt_recovered": True}, "dns_result_mismatch")
        need(self.accepted() >= n + 2 and before == self.db_count(False)
             and before == [1, 0, 0, 0, 0] and self.db_count(True) == [1, 1, 8, 0, 0], "stage_count_mismatch")
        need(self.web("stop") == final, "stop_overwrote_final_receipt")
        self.proof.update(admin_and_expiry_closed_before_worker=True, dns_before_v2_rejected=True,
                          socket_receipt_discarded_then_read_only=True, rehearsal=final)

    def db_count(self, dns):
        file = self.state / ("rehearsal-" + self.env["FAILOVER_EXAMPLE_GRANT_ID"] + (".dns" if dns else "") + ".sqlite3")
        need(stat.S_IMODE(file.stat().st_mode) == 0o600 and file.stat().st_uid == self.uid, "sqlite_permissions_mismatch")
        with closing(sqlite3.connect(file.as_uri() + "?mode=ro", uri=True)) as conn:
            return [conn.execute("SELECT COUNT(*) FROM " + table).fetchone()[0] for table in
                    ("v2_machine_failover_run", "v2_machine_failover_dns_run", "v2_machine_failover_dns_slot",
                     "v2_machine_provision_task", "v2_machine_failover_execution")]

    def instances(self):
        out = system("list-units", "--all", "--type=service", "--no-legend", "--plain", self.prefix + "@*.service")
        return [line.split()[0] for line in out.splitlines() if line.startswith(self.prefix + "@")]

    def active_worker(self):
        for name in self.instances():
            info = properties(name, "MainPID")
            if info.get("MainPID", "0").isdigit() and int(info["MainPID"]) > 0:
                pid = int(info["MainPID"])
                try:
                    if (Path("/proc", str(pid), "cmdline").read_bytes().split(b"\0")[-2:] == [os.fsencode(self.worker_path), b""]):
                        return name, pid
                except FileNotFoundError:
                    continue
        return None

    def hard_timeout(self):
        self.advance("supervisor_hard_timeout")
        process = self.as_user(self.panel, "/usr/bin/python3", "-B", self.area / "sandbox.py", "hold", start=True)
        try:
            instance, pid = eventually(self.active_worker, 6, "stalled_real_worker_not_observed")
            proc = Path("/proc", str(pid))
            status = dict(line.split(":", 1) for line in (proc / "status").read_text().splitlines() if ":" in line)
            need([int(x) for x in status["Uid"].split()] == [self.uid] * 4
                 and int(status["CapEff"], 16) == 0 and status["Seccomp"].strip() == "2"
                 and os.readlink(proc / "ns/net") != os.readlink("/proc/self/ns/net"), "real_worker_not_isolated")
            self.proof["actual_worker_dedicated_uid_and_private_netns"] = True
            raw, _ = process.communicate(timeout=18)
            result = json.loads(raw)
            worker = properties(instance, "Result", "MainPID", "ExecMainStatus")
            need(process.returncode == 0 and result["closed_without_report"] and 12_000 <= result["elapsed_ms"] < 16_000
                 and worker["Result"] == "timeout" and worker["MainPID"] == "0" and worker["ExecMainStatus"] in {"15", "9"},
                 "actual_worker_hard_timeout_failed")
            self.proof["actual_worker_hard_timeout_ms"] = result["elapsed_ms"]
        finally:
            if process.poll() is None:
                process.kill()
                process.communicate(timeout=5)

        t0 = time.monotonic()
        system("start", self.names[2])
        eventually(lambda: (self.state / "stubborn-ready").is_file(), 1.9, "sigterm_fixture_not_ready")
        eventually(lambda: properties(self.names[2], "ActiveState")["ActiveState"] == "failed", 5, "sigkill_not_observed")
        stubborn = properties(self.names[2], "Result", "MainPID", "ExecMainStatus")
        elapsed = round((time.monotonic() - t0) * 1000)
        need(stubborn == {"MainPID": "0", "Result": "timeout", "ExecMainStatus": "9"} and 2_700 <= elapsed < 6_500,
             "sigkill_escalation_failed")
        self.proof["sigterm_resistant_fixture_sigkilled"] = True
        self.proof["sigkill_elapsed_ms"] = elapsed
        probe = json.loads((self.state / "sandbox-check.json").read_text())
        need(len(probe) == 10 and all(value is True for value in probe.values()), "sandbox_probe_failed")
        self.proof["sandbox_checks"] = probe

    def rollback(self):
        self.advance("disable_and_restore_frozen_baseline")
        result = self.web()
        need(result == self.proof["rehearsal"], "timeout_disturbed_original_receipt")
        n = self.accepted()
        self.web(code=404, env={})
        self.web("v2", code=404, env={})
        need(n == self.accepted(), "revoked_entry_still_connected")
        system("stop", self.names[0])
        eventually(lambda: not SOCKET.exists() and self.active_worker() is None, 5, "worker_ingress_not_removed")
        self.web(code=503)
        self.web(code=404, root="rollback", env={})
        self.web("v2", code=404, root="rollback", env={})
        need(self.db_count(False) == [1, 0, 0, 0, 0] and self.db_count(True) == [1, 1, 8, 0, 0], "rollback_changed_durable_receipt")
        self.proof.update(revoked_entry_denied=True, socket_removed_no_tcp_fallback=True, old_release_entry_absent=True,
                          completed_receipt_survived_supervisor_timeout_and_rollback=True)

    def cleanup(self):
        if not self.made:
            return False
        if self.unit_files:
            system("stop", self.names[0], *self.instances(), self.names[2], check=False)
            system("reset-failed", *self.instances(), self.names[2], check=False)
            for file in self.unit_files:
                file.unlink(missing_ok=True)
            system("daemon-reload")
        need(self.active_worker() is None and not SOCKET.exists(), "active_worker_or_socket_remains")
        if SOCKET.parent.is_dir():
            SOCKET.parent.rmdir()
        for user in self.users:
            call("userdel", user)
        if self.group_made:
            call("groupdel", self.socket_group)
        need(self.area.parent == Path("/run") and self.area.name == self.prefix and not self.area.is_symlink(), "unsafe_cleanup_path")
        shutil.rmtree(self.area)
        return not self.area.exists() and not SOCKET.parent.exists() and not any(p.exists() for p in self.unit_files)


def main():
    workspace, temp, php = map(lambda value: Path(value).resolve(strict=True), sys.argv[1:])
    need(sys.platform == "linux" and os.geteuid() == 0 and os.environ["GITHUB_ACTIONS"] == "true"
         and os.environ["GITHUB_REPOSITORY"] == REPO and os.environ["GITHUB_REF_NAME"] == BRANCH
         and workspace.name == "keliboard" and temp.name == "_temp"
         and php.is_file() and re.fullmatch(r"/[a-zA-Z0-9_./-]+", str(php)), "disposable_github_runner_required")
    need(re.fullmatch("[a-f0-9]{40}", os.environ["GITHUB_SHA"]), "missing_ci_commit")
    lab = Lab(workspace, temp, php)
    report = {"source_commit": SOURCE, "rollback_commit": ROLLBACK, "ci_commit": os.environ["GITHUB_SHA"],
              "checks": lab.proof, "synthetic_only": True, "production_connections": 0, "real_cloud_creates": 0,
              "real_dns_writes": 0, "ok": False}
    try:
        lab.prepare()
        lab.start()
        lab.run_stages()
        lab.hard_timeout()
        lab.rollback()
        report["ok"] = True
    except Exception as error:
        report["failed_stage"] = lab.stage
        report["error"] = str(error) if isinstance(error, Rejected) else type(error).__name__
        frames = [frame for frame in traceback.extract_tb(error.__traceback__) if Path(frame.filename).name == "run.py"]
        if frames:
            report["fixture_line"] = frames[-1].lineno
    try:
        report["cleaned"] = lab.cleanup()
    except Exception as error:
        report["cleaned"] = False
        report["cleanup_error"] = str(error) if isinstance(error, Rejected) else type(error).__name__
    report["ok"] = report["ok"] and report["cleaned"]
    destination = temp / "failover-example-result.json"
    with destination.open("x", encoding="utf-8") as out:
        json.dump(report, out, indent=2)
        out.write("\n")
    destination.chmod(0o644)
    print(json.dumps(report), flush=True)
    return 0 if report["ok"] else 1


if __name__ == "__main__":
    sys.exit(main())
