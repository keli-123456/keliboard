import hashlib
import io
import json
from pathlib import Path
import subprocess
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import Mock, patch

import bootstrap_diagnostics as probe
import diagnostic_capture as capture
import diagnostic_round_runner as entry


RUN = "keli-accept-synthetic-diagnostics-01234567"
TASK = "11111111-1111-4111-8111-111111111111"
SECRET = "NEVER_EXPORT_PRIVATE_CALLBACK_TOKEN"


def response(role="source"):
    return {"schema": 2, "ok": True, "run_id": RUN, "task_id": TASK, "role": role, "read_only": True,
            "owner_verified": True, "private_permissions_checked": True, "root_cause_confirmed": False,
            "installer": {"state": "missing", "bytes_total": None, "bytes_sampled": 0, "truncated": False,
                          "phase": "unknown", "category_counts": None, "curl_error_codes": [], "http_error_statuses": []},
            "bootstrap": {"state": "observed", "result": "failed"},
            "execution": {script: {"state": "missing", "phase": "unknown", **dict.fromkeys(probe.EXECUTION_FIELDS - {"phase"})}
                          for script in probe.EXECUTION_SCRIPTS},
            "service": {"state": "unreadable", **dict.fromkeys(probe.UNIT_KEYS)},
            "files": {"agent_binary_present": False, "configuration_present": None}}


class CaptureTests(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory(prefix="keli-pre-cleanup-")
        self.addCleanup(temporary.cleanup)
        self.base = Path(temporary.name).resolve()
        self.events = []
        self.campaign = SimpleNamespace(BASE=self.base, RUN=RUN, assert_scope=Mock(), save=Mock(side_effect=self.save), load=self.load)
        self.node = SimpleNamespace(task=Mock(side_effect=self.task), execute=Mock(side_effect=self.execute))
        self.modules = {"campaign": self.campaign, "node": self.node,
                        "cleanup": SimpleNamespace(main=Mock(side_effect=lambda *a, **k: self.events.append("delete") or {"ok": True}))}
        self.loader = capture.loader_with_diagnostics(self.modules.__getitem__)
        blocked = patch.object(subprocess, "Popen", side_effect=AssertionError("real subprocess forbidden"))
        blocked.start()
        self.addCleanup(blocked.stop)

    def save(self, name, value, exclusive=False):
        mode = "x" if exclusive else "w"
        with (self.campaign.BASE / name).open(mode, encoding="utf-8") as stream:
            json.dump(value, stream)
        self.events.append("save:" + name)

    def load(self, name):
        return json.loads((self.campaign.BASE / name).read_text())

    def task(self, role):
        if role == "candidate":
            raise RuntimeError(SECRET)
        return {"id": TASK, "label": RUN + "-source", "external_id": "123", "public_ips": ["45.79.10.20"],
                "host_public_key": "ssh-ed25519 synthetic-public-test-only"}

    def execute(self, role, code, timeout):
        self.events.append("probe:" + role)
        self.assertLessEqual(timeout, 10)
        self.assertIn("owner_unverified", code)
        return response(role)

    def test_collection_and_durable_summary_happen_before_delete(self):
        self.assertTrue(self.loader("cleanup").main()["ok"])
        self.assertLess(self.events.index("probe:source"), self.events.index("delete"))
        self.assertLess(self.events.index("save:" + capture.REPORT), self.events.index("delete"))
        report = self.load(capture.REPORT)
        self.assertEqual(report["records"][0]["status"], "collected")
        self.assertEqual(report["records"][1]["status"], "receipt_unavailable")
        self.assertNotIn(SECRET, json.dumps(report))

    def test_transport_failure_does_not_prevent_cleanup_or_leak_exception(self):
        self.node.execute.side_effect = TimeoutError(SECRET)
        self.assertTrue(self.loader("cleanup").main()["ok"])
        self.assertEqual(self.events.count("delete"), 1)
        result = self.load(capture.REPORT)
        self.assertEqual(result["records"][0]["status"], "transport_unavailable")
        self.assertNotIn(SECRET, json.dumps(result))

    def test_bad_remote_owner_or_raw_output_is_not_persisted(self):
        for value in [{"ok": False, "reason": "owner_unverified", "raw_log": SECRET}, dict(response(), raw_log=SECRET)]:
            with self.subTest(kind=value.get("reason")), tempfile.TemporaryDirectory() as directory:
                self.campaign.BASE = Path(directory)
                self.node.execute.return_value = value
                self.node.execute.side_effect = None
                self.assertTrue(self.loader("cleanup").main()["ok"])
                report = json.loads((self.campaign.BASE / capture.REPORT).read_text())
                self.assertNotEqual(report["records"][0]["status"], "collected")
                self.assertNotIn(SECRET, json.dumps(report))

    def test_untrusted_execution_detail_is_discarded_without_blocking_cleanup(self):
        value = response()
        value["execution"]["native_installer"]["command"] = SECRET
        self.node.execute.side_effect = None
        self.node.execute.return_value = value
        self.assertTrue(self.loader("cleanup").main()["ok"])
        self.assertEqual(self.events.count("delete"), 1)
        report = self.load(capture.REPORT)
        self.assertEqual(report["records"][0]["status"], "response_unavailable")
        self.assertNotIn(SECRET, json.dumps(report))

    def test_summary_write_failure_still_calls_cleanup(self):
        self.campaign.save.side_effect = OSError(SECRET)
        self.assertTrue(self.loader("cleanup").main()["ok"])
        self.assertEqual(self.events, ["delete"])
        self.node.execute.assert_not_called()

    def test_missing_node_module_still_calls_cleanup(self):
        del self.modules["node"]
        self.assertTrue(self.loader("cleanup").main()["ok"])
        self.assertEqual(self.load(capture.REPORT)["status"], "collector_failed")

    def test_cleanup_exception_is_not_swallowed_or_retried(self):
        self.modules["cleanup"].main.side_effect = RuntimeError("synthetic_cleanup_failure")
        with self.assertRaisesRegex(RuntimeError, "synthetic_cleanup_failure"):
            self.loader("cleanup").main()
        self.modules["cleanup"].main.assert_called_once()
        self.assertTrue((self.base / capture.REPORT).is_file())

    def test_closed_round_does_not_read_node_or_modify_evidence(self):
        (self.base / "closed.json").write_text("{}")
        self.assertTrue(self.loader("cleanup").main()["ok"])
        self.node.task.assert_not_called()
        self.node.execute.assert_not_called()
        self.assertEqual(sorted(p.name for p in self.base.iterdir()), ["closed.json"])

    def test_delete_intent_blocks_collection_of_that_role(self):
        (self.base / "source-delete-intent.json").write_text("{}")
        result = capture.capture(self.campaign, self.node)
        self.assertEqual(result["records"][0]["status"], "delete_already_started")
        self.node.execute.assert_not_called()

    def test_stop_request_does_not_prevent_readonly_capture_and_cleanup(self):
        (self.base / "stop-request.json").write_text("{}")
        self.assertTrue(self.loader("cleanup").main()["ok"])
        self.assertIn("probe:source", self.events)

    def test_repeated_cleanup_only_collects_once(self):
        wrapped = self.loader("cleanup")
        wrapped.main()
        first = (self.base / capture.REPORT).read_bytes()
        wrapped.main()
        self.node.execute.assert_called_once()
        self.assertEqual((self.base / capture.REPORT).read_bytes(), first)
        self.assertEqual(self.events.count("delete"), 2)

    def test_foreign_task_and_private_address_do_not_trigger_ssh(self):
        for change in [{"label": "business"}, {"id": "not-a-uuid"}, {"public_ips": ["127.0.0.1"]}, {"external_id": None}]:
            with self.subTest(change=next(iter(change))), tempfile.TemporaryDirectory() as directory:
                self.campaign.BASE = Path(directory)
                self.node.task.side_effect = lambda role: dict(self.task("source"), **change)
                capture.capture(self.campaign, self.node)
        self.node.execute.assert_not_called()

    def test_expiry_and_elapsed_budget_prevent_more_calls(self):
        self.save("campaign-window.json", {"run_id": RUN, "expires_at": 100})
        result = capture.capture(self.campaign, self.node, wall=lambda: 101)
        self.assertEqual(result["records"][0]["status"], "budget_exhausted")
        self.node.execute.assert_not_called()

    def test_both_roles_share_one_twenty_second_budget(self):
        self.node.task.side_effect = lambda role: dict(self.task("source"), label=RUN + "-" + role)
        ticks = iter([0, 0, 21])
        result = capture.capture(self.campaign, self.node, clock=lambda: next(ticks))
        self.assertEqual(result["records"][1]["status"], "budget_exhausted")
        self.node.execute.assert_called_once()

    def test_final_minute_is_reserved_for_cleanup(self):
        self.save("campaign-window.json", {"run_id": RUN, "expires_at": 150})
        result = capture.capture(self.campaign, self.node, wall=lambda: 100)
        self.assertEqual(result["records"][0]["status"], "budget_exhausted")
        self.node.execute.assert_not_called()

    def test_scope_error_cannot_skip_cleanup(self):
        self.campaign.assert_scope.side_effect = RuntimeError(SECRET)
        self.assertTrue(self.loader("cleanup").main()["ok"])
        self.node.execute.assert_not_called()
        self.assertEqual(self.load(capture.REPORT)["status"], "collector_failed")


class EntryTests(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory(prefix="keli-diagnostic-entry-")
        self.addCleanup(temporary.cleanup)
        self.base = Path(temporary.name).resolve()
        self.auth = {"run_id": RUN, "user_approved": True, "peak_instance_count": 3, "maximum_total_create_attempts": 3,
                     "maximum_campaign_seconds": 7200, **dict.fromkeys(entry.FLAGS, False)}
        (self.base / "authorization.json").write_text(json.dumps(self.auth))
        for name in entry.REQUIRED - {"authorization.json"}:
            source = Path(entry.__file__).with_name(name)
            (self.base / name).write_bytes(source.read_bytes() if name in entry.TOOLS else b"# inert local fixture\n")
        self.repin()
        blocked = patch.object(subprocess, "Popen", side_effect=AssertionError("real subprocess forbidden"))
        blocked.start()
        self.addCleanup(blocked.stop)

    def repin(self):
        pins = {name: hashlib.sha256((self.base / name).read_bytes()).hexdigest() for name in entry.REQUIRED}
        (self.base / "offline-result.json").write_text(json.dumps({"ok": True, "run_id": RUN, "file_digests": pins}))

    def test_fresh_pinned_scope_is_readonly_preparation_not_dispatch(self):
        with patch.object(entry.importlib, "import_module", side_effect=AssertionError("dispatch forbidden")):
            base, run_id, pins = entry.prepare(self.base)
        self.assertEqual(base, self.base)
        self.assertEqual(run_id, RUN)
        self.assertTrue(set(entry.TOOLS) <= set(pins))

    def test_closed_stopped_existing_or_ambiguous_intent_refuses_before_import(self):
        for marker in entry.STOP_FILES:
            with self.subTest(marker=marker), patch.object(entry.importlib, "import_module") as load:
                path = self.base / marker
                path.write_text("{}")
                with self.assertRaisesRegex(entry.Refused, "new_round_required"):
                    entry.run(self.base)
                load.assert_not_called()
                path.unlink()

    def test_changed_frozen_file_cannot_dispatch(self):
        (self.base / "node.py").write_text(SECRET)
        with patch.object(entry.importlib, "import_module") as load:
            with self.assertRaisesRegex(entry.Refused, "local_file_changed"):
                entry.run(self.base)
            load.assert_not_called()

    def test_automation_permission_expansion_is_refused(self):
        for flag in entry.FLAGS:
            with self.subTest(flag=flag):
                auth = dict(self.auth, **{flag: True})
                (self.base / "authorization.json").write_text(json.dumps(auth))
                self.repin()
                with self.assertRaisesRegex(entry.Refused, "approved_isolated_scope_required"):
                    entry.prepare(self.base)

    def test_missing_pinned_probe_cannot_use_unreviewed_repo_tool(self):
        path = self.base / "offline-result.json"
        data = json.loads(path.read_text())
        del data["file_digests"]["bootstrap_diagnostics.py"]
        path.write_text(json.dumps(data))
        with self.assertRaisesRegex(entry.Refused, "pinned_diagnostics_required"):
            entry.prepare(self.base)

    def test_network_diagnostic_tools_require_their_own_frozen_pins(self):
        path = self.base / "offline-result.json"
        original = json.loads(path.read_text())
        for name in ["entry_probe_diagnostics.py", "entry_probe_capture.py", "probe_sampling.py", "aliyun_cleanup.py", "aliyun_cleanup.php"]:
            data = json.loads(json.dumps(original))
            del data["file_digests"][name]
            path.write_text(json.dumps(data))
            with self.assertRaisesRegex(entry.Refused, "pinned_diagnostics_required"):
                entry.prepare(self.base)

    def test_invalid_network_probe_scope_refuses_before_import_or_dispatch(self):
        auth = dict(self.auth, entry_probe_diagnostics={"schema": 1, "enabled": True, "targets": ["127.0.0.1"], "timeout_seconds": 2})
        (self.base / "authorization.json").write_text(json.dumps(auth))
        self.repin()
        with patch.object(entry.importlib, "import_module") as load:
            with self.assertRaisesRegex(entry.Refused, "probe_diagnostic_scope_invalid"):
                entry.run(self.base)
        load.assert_not_called()

    def test_non_integer_scope_limits_are_refused(self):
        for key in ['peak_instance_count', 'maximum_total_create_attempts', 'maximum_campaign_seconds']:
            auth = dict(self.auth, **{key: float(self.auth[key])})
            (self.base / 'authorization.json').write_text(json.dumps(auth))
            self.repin()
            with self.assertRaisesRegex(entry.Refused, 'bounded_scope_required'):
                entry.prepare(self.base)

    def test_cli_returns_fixed_error_without_private_exception(self):
        with patch.object(entry, "run", side_effect=RuntimeError(SECRET)), patch("sys.stdout", new_callable=io.StringIO) as out:
            self.assertEqual(entry.main(["--work", str(self.base)]), 1)
        self.assertEqual(json.loads(out.getvalue()), {"ok": False, "reason": "scoped_runner_unavailable"})

    def test_fresh_entry_runs_synthetic_driver_with_pre_delete_capture(self):
        self.fresh_entry()

    def test_sampler_failure_retains_original_stop_type_reason_and_cleanup(self):
        self.fresh_entry('baseline_report_gap')

    def fresh_entry(self, baseline_error=None):
        campaign = '''from pathlib import Path
import json
BASE=Path(BASE_VALUE)
RUN=RUN_VALUE
def assert_scope(): pass
def save(name,value,exclusive=False):
 with (BASE/name).open('x' if exclusive else 'w') as stream: json.dump(value,stream)
def load(name): return json.loads((BASE/name).read_text())
'''.replace('BASE_VALUE', repr(str(self.base))).replace('RUN_VALUE', repr(RUN))
        node = '''import campaign
def task(role):
 if role!='source': raise RuntimeError('no candidate receipt')
 return ROW_VALUE
def execute(role,code,timeout):
 assert 0<timeout<=10
 campaign.save('synthetic-probe-ordered.json',{'read_only':True})
 return RESPONSE_VALUE
'''.replace('ROW_VALUE', repr(CaptureTests.task(self, 'source'))).replace('RESPONSE_VALUE', repr(response()))
        cleanup = '''import campaign
CLEANUP_DNS_PHP='legacy synthetic read adapter'
def main():
 assert 'InvalidRR.NoExist' in CLEANUP_DNS_PHP
 assert (campaign.BASE/'synthetic-probe-ordered.json').is_file()
 assert (campaign.BASE/'bootstrap-diagnostics.json').is_file()
 campaign.save('synthetic-delete-ordered.json',{'ok':True})
 return {'ok':True}
'''
        driver = '''class RoundStopped(RuntimeError): pass
class RoundRunner:
 def __init__(self,loader): self.loader=loader
 def baseline(self,role): raise AssertionError('legacy sampling must not run')
 def run(self):
  reason=None
  try: self.baseline('source')
  except RoundStopped as error: reason=str(error)
  result=self.loader('cleanup').main()
  return {'ok':False,'cleanup_complete':result['ok'],'reason':reason}
'''
        for name, text in [('campaign.py', campaign), ('node.py', node), ('cleanup.py', cleanup), ('round_runner.py', driver)]:
            (self.base / name).write_text(text)
        self.repin()
        modules = __import__('sys').modules
        before = dict(modules)
        for name in ['campaign','node','cleanup','round_runner']:
            modules.pop(name, None)
        try:
            sampled = []
            def bounded_baseline(driver, role):
                sampled.append(role)
                if baseline_error:
                    raise entry.probe_sampling.Refused(baseline_error)
            with patch.object(entry.probe_sampling, 'baseline', bounded_baseline):
                result = entry.run(self.base)
            self.assertEqual(sampled, ['source'])
            self.assertEqual(result['reason'], baseline_error)
            self.assertFalse(result['ok'])
            self.assertTrue(result['cleanup_complete'])
            self.assertTrue((self.base/'synthetic-delete-ordered.json').is_file())
            self.assertNotIn(SECRET, (self.base/capture.REPORT).read_text())
        finally:
            for name in ['campaign','node','cleanup','round_runner']:
                modules.pop(name, None)
                if name in before:
                    modules[name] = before[name]


if __name__ == "__main__":
    unittest.main()
