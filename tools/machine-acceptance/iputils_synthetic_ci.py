"""Temporary-branch Linux aggregate; workload has no credentials or network access."""
import contextlib
import hashlib
import io
import json
import os
from pathlib import Path
import platform
import re
import shutil
import sys
import traceback
import unittest


HERE = Path(__file__).resolve().parent
ROOT = HERE.parent.parent
BASE = "4eefa62b05db9ce6e6b66b1b9d9f9b44ab0aebd1"
MODULES = ["test_iputils_namespace", "test_staging", "test_bootstrap_diagnostics", "test_diagnostic_capture", "test_entry_probe_diagnostics",
           "test_entry_probe_capture", "test_driver_journal", "test_install_only_runner", "test_worker.WorkerTests",
           "test_driver_linux", "test_application_bundle", "test_entry_probe_linux"]
WINDOWS_ONLY = {
    "test_driver_journal.WindowsSharingTests.test_real_sharing_lock_exhausts_with_numeric_evidence",
    "test_driver_journal.WindowsSharingTests.test_real_short_lock_releases_during_bounded_replacement",
    "test_driver_journal.WindowsSharingTests.test_real_observer_closes_promptly_without_corrupting_snapshots",
    "test_install_only_runner.DriverTests.test_real_progress_handle_lock_does_not_prevent_capture_and_cleanup",
}
FILES = ["iputils_namespace.py", "test_iputils_namespace.py", "test_entry_probe_iputils.py", "staging.py", "worker.py", "bootstrap_diagnostics.py", "diagnostic_capture.py", "diagnostic_round_runner.py",
         "entry_probe_diagnostics.py", "entry_probe_capture.py", "driver_journal.py", "install_only_runner.py",
         "application_bundle.py", "linux_bootstrap_lifecycle.py", "test_staging.py", "test_worker.py",
         "test_bootstrap_diagnostics.py", "test_diagnostic_capture.py", "test_entry_probe_diagnostics.py",
         "test_entry_probe_capture.py", "test_driver_journal.py", "test_install_only_runner.py", "test_driver_linux.py",
         "test_application_bundle.py", "test_entry_probe_linux.py", "iputils_synthetic_ci.py"]
DENIED = {"os.system", "os.exec", "os.posix_spawn", "os.fork", "os.forkpty", "os.spawn", "socket.__new__",
          "socket.connect", "socket.bind", "socket.getaddrinfo", "socket.gethostbyname", "socket.gethostbyaddr", "socket.getnameinfo"}
GIT = shutil.which("git")


def hashes():
    paths = [HERE / name for name in FILES] + [ROOT / ".gitattributes", ROOT / ".github/workflows/ci.yml",
             ROOT / ".github/workflows/iputils-single-packet-acceptance.yml", ROOT / ".github/workflows/docker-publish.yml"]
    return {path.relative_to(ROOT).as_posix(): hashlib.sha256(path.read_bytes()).hexdigest() for path in paths}


def isolation():
    status = dict(line.split(":", 1) for line in Path("/proc/self/status").read_text().splitlines() if ":" in line)
    caps = {name: int(status[name].strip(), 16) for name in ["CapInh", "CapPrm", "CapEff", "CapBnd", "CapAmb"]}
    interfaces = [line.split(":", 1)[0].strip() for line in Path("/proc/self/net/dev").read_text().splitlines()[2:] if ":" in line]
    routes = Path("/proc/self/net/route").read_text().splitlines()[1:]
    parent = os.environ.get("CI_PARENT_NETNS", "")
    value = {"nonroot": os.geteuid() != 0, "pid_namespace_init": os.getpid() == 1,
             "network_namespace_changed": bool(re.fullmatch(r"net:\[[0-9]+\]", parent)) and os.readlink("/proc/self/ns/net") != parent,
             "only_loopback_interface": interfaces == ["lo"], "no_ipv4_routes": not routes,
             "capabilities_cleared": all(number == 0 for number in caps.values()), "no_new_privileges": status["NoNewPrivs"].strip() == "1",
             "credential_environment_absent": not any(any(token in name.upper() for token in ["TOKEN", "SECRET", "PASSWORD", "API_KEY", "SSH_"])
                                                       for name in os.environ)}
    value["ok"] = all(value.values())
    return value


def cases(suite):
    for item in suite:
        if isinstance(item, unittest.TestSuite):
            yield from cases(item)
        else:
            yield item


class SafeResult(unittest.TestResult):
    def detail(self, test, error):
        frames = [{"file": Path(frame.filename).name if Path(frame.filename).name in FILES else "stdlib_or_fixture",
                   "line": frame.lineno} for frame in traceback.extract_tb(error[2])][-8:]
        return {"test": test.id(), "error_class": type(error[1]).__name__, "frames": frames}

    def addError(self, test, error):
        self.errors.append(self.detail(test, error))

    def addFailure(self, test, error):
        self.failures.append(self.detail(test, error))

    def addSubTest(self, test, subtest, error):
        if error is not None:
            target = self.failures if issubclass(error[0], test.failureException) else self.errors
            target.append(self.detail(test, error))

    def addSkip(self, test, reason):
        self.skipped.append({"test": test.id(), "reason": "unexpected_skip"})


def main():
    value = {"schema": 1, "ok": False, "scope": "single_packet_fix_linux_synthetic", "base_commit": BASE,
             "commit": os.environ.get("CI_COMMIT"), "python": platform.python_version(), "platform": sys.platform,
             "uid": os.geteuid() if hasattr(os, "geteuid") else None, "forbidden_attempts": {}, "allowed_local_processes": {},
             "cloud_ssh_dns": "synthetic_only", "live_round_loaded": False, "ping_or_nft_executed": False,
             "production_or_tanzhen_contacted": False, "automatic_flags_changed": False}
    before = hashes()
    value["source_digests"] = before
    if sys.platform != "linux" or value["uid"] == 0:
        value["reason"] = "nonroot_linux_required"
    else:
        try:
            value["isolation"] = isolation()
            if not value["isolation"]["ok"]:
                raise RuntimeError("isolation_precondition_failed")
            import test_entry_probe_linux as linux
            def guard(event, arguments):
                allowed = False
                if event == "subprocess.Popen":
                    args = arguments[1]
                    if isinstance(args, (list, tuple)) and args:
                        if args[0] == GIT and not any(str(arg).startswith("--remote") for arg in args):
                            allowed = any(op in args for op in ["init", "add", "update-index", "commit", "rev-parse", "config", "cat-file", "archive"])
                            kind = "local_git"
                        elif list(args) == ["/bin/bash", "--noprofile", "--norc", "-s"]:
                            allowed, kind = True, "two_line_bash_prefix"
                        elif len(args) == 4 and list(args[:3]) == [linux.CHILD_PYTHON, "-I", "-c"] and args[3] in linux.SCRIPTS.values():
                            allowed, kind = True, "fixed_synthetic_python_child"
                    if allowed:
                        value["allowed_local_processes"][kind] = value["allowed_local_processes"].get(kind, 0) + 1
                if event in DENIED or event == "subprocess.Popen" and not allowed:
                    value["forbidden_attempts"][event] = value["forbidden_attempts"].get(event, 0) + 1
                    raise RuntimeError("external_dispatch_forbidden")
            sys.addaudithook(guard)
            output = io.StringIO()
            with contextlib.redirect_stdout(output), contextlib.redirect_stderr(output):
                all_cases = list(cases(unittest.defaultTestLoader.loadTestsFromNames(MODULES)))
                excluded = sorted(case.id() for case in all_cases if case.id() in WINDOWS_ONLY)
                result = SafeResult()
                unittest.TestSuite(case for case in all_cases if case.id() not in WINDOWS_ONLY).run(result)
            import entry_probe_diagnostics as probe
            counters = probe.icmp_counters(Path("/"))
            value.update(tests_run=result.testsRun, failures=result.failures, errors=result.errors, skipped=result.skipped,
                         windows_native_excluded=excluded, source_unchanged=hashes() == before,
                         captured_output_characters=len(output.getvalue()), kernel_icmp_out_echoes=counters["values"]["OutEchos"] if counters["state"] == "observed" else None)
            value["ok"] = (result.wasSuccessful() and not result.skipped and len(excluded) == 4 and result.testsRun == 224
                           and value["source_unchanged"] and not value["forbidden_attempts"] and value["kernel_icmp_out_echoes"] == 0)
        except BaseException as error:
            value.update(reason="isolated_ci_unavailable", error_class=type(error).__name__)
    destination = ROOT / "artifacts/iputils-synthetic.json"
    destination.parent.mkdir(exist_ok=True)
    destination.write_text(json.dumps(value, ensure_ascii=True, indent=2) + "\n", encoding="utf-8")
    print(json.dumps({key: value.get(key) for key in ["ok", "tests_run", "reason", "forbidden_attempts"]}))
    return 0 if value["ok"] else 1


if __name__ == "__main__":
    raise SystemExit(main())
