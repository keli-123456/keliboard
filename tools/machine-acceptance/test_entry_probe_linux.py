"""Linux kernel/process checks with synthetic child output; never ping, SSH or cloud."""
import json
import os
from pathlib import Path
import signal
import subprocess
import sys
import tempfile
import time
from types import SimpleNamespace
import unittest
from unittest.mock import Mock, patch

import diagnostic_capture as bootstrap_capture
import entry_probe_capture as capture
import entry_probe_diagnostics as probe
from test_entry_probe_diagnostics import FakeCommands, RUN, SECRET, SOURCE, TARGETS, TASK


CHILD_PYTHON = "/usr/bin/python3"
SCRIPTS = {
    "icmp_stdout": "import sys; print('From synthetic-router Destination Host Unreachable'); print('1 packets transmitted, 0 received, +1 errors'); sys.exit(1)",
    "icmp_stderr": "import sys; print('From synthetic-router Time to live exceeded',file=sys.stderr); print('1 packets transmitted, 0 received, +1 errors'); sys.exit(1)",
    "echo": "print('64 bytes from 39.156.70.37: icmp_seq=1 ttl=50 time=1 ms'); print('1 packets transmitted, 1 received')",
    "no_echo": "import sys; print('1 packets transmitted, 0 received, 100% packet loss'); sys.exit(1)",
    "permission": "import sys; print('ping: Operation not permitted',file=sys.stderr); sys.exit(2)",
    "sleep": "import time; time.sleep(30)",
    "overflow": "import os,time; os.write(1,b'x'*8192); time.sleep(30)",
    "eof_sleep": "import os,time; os.close(1); os.close(2); time.sleep(30)",
    "no_summary": "raise SystemExit(1)",
}


@unittest.skipUnless(sys.platform == "linux", "real Linux process and procfs checks")
class LinuxEntryProbeTests(unittest.TestCase):
    def setUp(self):
        self.assertNotEqual(os.geteuid(), 0, "non-root Linux required")
        self.children = []
        self.real_popen = subprocess.Popen
        self.addCleanup(self.reap_remaining)

    def reap_remaining(self):
        for child in self.children:
            if child.poll() is None:
                child.kill()
                child.wait(timeout=2)
            if child.stdout is not None and not child.stdout.closed:
                child.stdout.close()

    def child(self, name, timeout=2, limit=16384):
        def launch(*args, **kwargs):
            process = self.real_popen(*args, **kwargs)
            self.children.append(process)
            return process
        with patch.object(probe.subprocess, "Popen", side_effect=launch):
            return probe.command([CHILD_PYTHON, "-I", "-c", SCRIPTS[name]], timeout, limit)

    def assert_reaped(self):
        self.assertTrue(self.children)
        for child in self.children:
            self.assertIsNotNone(child.returncode)
            self.assertTrue(child.stdout.closed)
            with self.assertRaises(ChildProcessError):
                os.waitpid(child.pid, os.WNOHANG)

    def test_real_stdout_and_stderr_returned_errors_are_not_timeout(self):
        for name, category in [("icmp_stdout", "icmp_host_unreachable"), ("icmp_stderr", "icmp_ttl_exceeded")]:
            row = probe.classify_ping(self.child(name), TARGETS[0])
            self.assertEqual(row["classification"], "icmp_error")
            self.assertIn(category, row["error_categories"])
            self.assertEqual(row["reported_errors"], 1)
        self.assert_reaped()

    def test_real_echo_child_preserves_target_and_summary_proof(self):
        row = probe.classify_ping(self.child("echo"), TARGETS[0])
        self.assertEqual(row["classification"], "echo_reply")
        self.assertEqual((row["sent"], row["received"], row["exit_code"]), (1, 1, 0))
        self.assert_reaped()

    def test_real_no_echo_exit_is_distinct_from_killed_process_timeout(self):
        row = probe.classify_ping(self.child("no_echo"), TARGETS[0])
        self.assertEqual(row["state"], "observed")
        self.assertEqual(row["classification"], "no_echo_by_deadline")
        self.assert_reaped()

    def test_real_child_permission_stderr_is_execution_error(self):
        row = probe.classify_ping(self.child("permission"), TARGETS[0])
        self.assertEqual(row["classification"], "execution_error")
        self.assertIn("permission_denied", row["error_categories"])
        self.assertIsNone(row["received"])
        self.assert_reaped()

    def test_real_timeout_kills_child_closes_pipe_and_reaps_pid(self):
        start = time.monotonic()
        row = self.child("sleep", timeout=0.2)
        self.assertEqual(row.state, "timeout")
        self.assertEqual(row.output, b"")
        self.assertEqual(self.children[-1].returncode, -signal.SIGKILL)
        self.assertLess(time.monotonic() - start, 5)
        self.assert_reaped()

    def test_real_output_limit_kills_and_reaps_without_exporting_partial_text(self):
        row = self.child("overflow", limit=4096)
        self.assertEqual(row.state, "oversized")
        self.assertEqual(row.output, b"")
        self.assertEqual(self.children[-1].returncode, -signal.SIGKILL)
        self.assert_reaped()

    def test_real_eof_before_exit_still_obeys_wait_timeout_and_reaps(self):
        row = self.child("eof_sleep", timeout=0.2)
        self.assertEqual(row.state, "timeout")
        self.assertEqual(self.children[-1].returncode, -signal.SIGKILL)
        self.assert_reaped()

    def test_real_nonzero_without_packet_summary_is_unknown_not_zero(self):
        row = probe.classify_ping(self.child("no_summary"), TARGETS[0])
        self.assertEqual(row["classification"], "execution_error")
        self.assertIsNone(row["received"])
        self.assertIsNone(row["reported_errors"])
        self.assert_reaped()

    def test_real_procfs_namespace_links_match_current_synthetic_unit_pid(self):
        row = probe.namespace_summary(Path("/"), probe.CommandResult("observed", 0, str(os.getpid()).encode()))
        self.assertEqual(row, {"state": "observed", "agent_pid": os.getpid(), "matches_agent": True})

    def test_real_zero_size_procfs_icmp_counters_are_read_without_sending_packets(self):
        self.assertEqual(Path("/proc/self/net/snmp").stat().st_size, 0)
        row = probe.icmp_counters(Path("/"))
        self.assertEqual(row["state"], "observed")
        self.assertEqual(set(row["values"]), set(probe.ICMP_COUNTERS))
        self.assertEqual(row["values"]["OutEchos"], 0)

    def test_real_private_owner_permission_denial_stops_before_any_command(self):
        with tempfile.TemporaryDirectory(prefix="keli-linux-owner-") as temporary:
            root = Path(temporary)
            owner = root / "var/lib/keli-test-owner/owner.json"
            owner.parent.mkdir(parents=True)
            owner.write_text(json.dumps({"schema": 1, "run_id": RUN, "task_id": TASK}))
            owner.chmod(0)
            commands = Mock(side_effect=AssertionError("must not execute"))
            try:
                result = probe.collect(RUN, TASK, "source", TARGETS, SOURCE, root=root, run_command=commands)
            finally:
                owner.chmod(0o600)
            self.assertFalse(result["ok"])
            self.assertEqual(result["reason"], "owner_unverified")
            commands.assert_not_called()

    def test_real_child_timeouts_are_durable_before_single_original_cleanup(self):
        with tempfile.TemporaryDirectory(prefix="keli-linux-controller-") as control, tempfile.TemporaryDirectory(prefix="keli-linux-node-") as node_path:
            base, root = Path(control), Path(node_path)
            owner = root / "var/lib/keli-test-owner/owner.json"
            owner.parent.mkdir(parents=True)
            owner.write_text(json.dumps({"schema": 1, "run_id": RUN, "task_id": TASK}))
            owner.chmod(0o600)
            for relative in ["proc/self/ns/net", "proc/123/ns/net"]:
                path = root / relative
                path.parent.mkdir(parents=True, exist_ok=True)
                path.symlink_to(os.readlink("/proc/self/ns/net"))
            events = []
            def save(name, value, exclusive=False):
                with (base / name).open("x" if exclusive else "w") as stream:
                    json.dump(value, stream)
                events.append("save:" + name)
            def load(name):
                return json.loads((base / name).read_text())
            now = int(time.time())
            save("authorization.json", {"run_id": RUN, "user_approved": True, "entry_probe_diagnostics": {
                "schema": 1, "enabled": True, "targets": TARGETS, "timeout_seconds": 2}})
            save("campaign-window.json", {"run_id": RUN, "started_at": now - 1, "expires_at": now + 300})
            campaign = SimpleNamespace(BASE=base, RUN=RUN, assert_scope=Mock(), save=save, load=load)
            fake = FakeCommands()
            def commands(args, timeout, limit):
                if "--" in args:
                    return self.child("sleep", timeout=min(timeout, 0.1), limit=limit)
                return fake(args, timeout, limit)
            def execute(role, code, timeout):
                self.assertLessEqual(timeout, 22)
                self.assertIn("owner_unverified", code)
                with patch.object(probe, "program", side_effect=lambda name: probe.PROGRAMS[name][0]):
                    return probe.collect(RUN, TASK, role, TARGETS, SOURCE, root=root, run_command=commands)
            def task(role):
                if role != "source":
                    raise ValueError("synthetic_candidate_absent")
                return {"id": TASK, "label": RUN + "-source", "external_id": "123", "public_ips": [SOURCE],
                        "host_public_key": "ssh-ed25519 synthetic-only-public-key"}
            node = SimpleNamespace(task=Mock(side_effect=task), execute=Mock(side_effect=execute))
            def cleanup():
                events.append("cleanup")
                self.assert_reaped()
                self.assertTrue(all(child.returncode == -signal.SIGKILL for child in self.children))
                self.assertTrue((base / capture.REPORT).is_file())
                return {"ok": True}
            original_cleanup = Mock(side_effect=cleanup)
            modules = {"campaign": campaign, "node": node, "cleanup": SimpleNamespace(main=original_cleanup)}
            with patch.object(bootstrap_capture, "capture", return_value={}):
                result = bootstrap_capture.loader_with_diagnostics(modules.__getitem__)("cleanup").main()
            self.assertTrue(result["ok"])
            original_cleanup.assert_called_once()
            node.execute.assert_called_once()
            evidence = load(capture.REPORT)["records"][0]["evidence"]
            self.assertTrue(evidence["private_permissions_checked"])
            self.assertTrue(evidence["agent_context_stable"])
            self.assertTrue(all(row["ping"]["state"] == "timeout" and row["ping"]["received"] is None for row in evidence["rows"]))
            self.assertLess(events.index("save:" + capture.REPORT), events.index("cleanup"))
            self.assertNotIn(SECRET, json.dumps(evidence))


if __name__ == "__main__":
    unittest.main()
