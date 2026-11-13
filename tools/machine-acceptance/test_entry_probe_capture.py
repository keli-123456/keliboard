import copy
import json
from pathlib import Path
import subprocess
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import Mock, patch

import diagnostic_capture as bootstrap_capture
import diagnostic_round_runner as entry
import entry_probe_capture as capture
from test_entry_probe_diagnostics import FakeCommands, RUN, SECRET, SOURCE, TARGETS, TASK, make_record, observed


SCOPE = {"schema": 1, "enabled": True, "targets": TARGETS, "timeout_seconds": 2}


class WireTests(unittest.TestCase):
    def setUp(self):
        self.value = make_record()

    def validate(self, value):
        return capture.validate_record(value, RUN, TASK, "source", SCOPE, SOURCE, True)

    def test_valid_fixed_schema_is_accepted(self):
        self.assertEqual(self.validate(self.value), self.value)

    def test_multi_packet_evidence_is_retained_but_cannot_claim_echo_or_no_reply(self):
        runner = FakeCommands()
        runner.ping_output, runner.ping_exit = b'3 packets transmitted, 0 received, 100% packet loss', 1
        value = make_record(commands=runner)
        safe = self.validate(value)
        self.assertIsNotNone(safe)
        self.assertEqual(safe['rows'][0]['ping']['sent'], 3)
        self.assertEqual(safe['rows'][0]['ping']['classification'], 'packet_budget_exceeded')
        for category in ['echo_reply', 'no_echo_by_deadline', 'execution_error', 'unavailable']:
            changed = copy.deepcopy(value)
            changed['rows'][0]['ping']['classification'] = category
            self.assertIsNone(self.validate(changed))

    def test_no_packet_sent_or_timeout_cannot_be_reported_as_no_reply(self):
        for mutation in [{'sent': 0}, {'exit_code': 2}, {'reported_errors': 1}, {'state': 'timeout'}]:
            value = copy.deepcopy(self.value)
            value['rows'][0]['ping'].update(classification='no_echo_by_deadline', received=0, **mutation)
            self.assertIsNone(self.validate(value))

    def test_failure_observations_are_accepted_but_never_become_success_counts(self):
        runner = FakeCommands()
        runner.nft_result = observed(b"permission denied " + SECRET.encode(), 1)
        runner.ping_output = b"From router Destination Host Unreachable\n1 packets transmitted, 0 received, +1 errors"
        runner.ping_exit = 1
        value = self.validate(make_record(commands=runner))
        self.assertIsNotNone(value)
        self.assertEqual(value["firewall_before"]["state"], "unavailable")
        self.assertTrue(all(row["ping"]["classification"] == "icmp_error" for row in value["rows"]))

    def test_unknown_fields_at_every_nested_level_are_discarded(self):
        paths = [[], ["rows", 0], ["rows", 0, "route"], ["rows", 0, "ping"], ["firewall_before"],
                 ["firewall_after", "chains", 0], ["firewall_before", "rules", 0], ["firewall_delta"],
                 ["firewall_delta", "rules", 0], ["host_icmp_before"], ["network_namespace"], ["network_namespace_after"]]
        for path in paths:
            with self.subTest(path=path):
                value = copy.deepcopy(self.value)
                item = value
                for part in path:
                    item = item[part]
                item["raw_log"] = SECRET
                self.assertIsNone(self.validate(value))

    def test_untrusted_strings_and_non_strict_numbers_cannot_escape_schema(self):
        mutations = [(["rows", 0, "ping", "error_categories"], [SECRET]), (["rows", 0, "route", "route_type"], SECRET),
                     (["firewall_after", "chains", 0, "policy"], SECRET), (["rows", 0, "ping", "received"], True),
                     (["firewall_delta", "shared_counters_not_probe_attribution"], 1), (["censorship_confirmed"], True),
                     (["private_permissions_checked"], False), (["firewall_after", "rules", 0, "packets"], float("nan"))]
        for path, replacement in mutations:
            with self.subTest(path=path):
                value = copy.deepcopy(self.value)
                item = value
                for part in path[:-1]:
                    item = item[part]
                item[path[-1]] = replacement
                self.assertIsNone(self.validate(value))

    def test_source_target_role_and_timeout_mismatch_are_not_accepted(self):
        for name, value in [("expected_source", "45.79.10.21"), ("role", "candidate"), ("task_id", RUN), ("timeout_seconds", True)]:
            self.assertIsNone(self.validate(dict(self.value, **{name: value})))
        value = copy.deepcopy(self.value)
        value["rows"].reverse()
        self.assertIsNone(self.validate(value))

    def test_false_source_or_namespace_cannot_claim_a_valid_echo(self):
        for path in [["rows", 0, "route", "source_matches_receipt"], ["network_namespace", "matches_agent"]]:
            value = copy.deepcopy(self.value)
            item = value
            for part in path[:-1]:
                item = item[part]
            item[path[-1]] = False
            self.assertIsNone(self.validate(value))

    def test_rendered_program_embeds_only_the_two_reviewed_modules(self):
        code = capture.render_probe(RUN, TASK, "source", SCOPE, SOURCE, 20, True)
        compile(code, "synthetic-probe", "exec")
        self.assertIn("entry_probe_diagnostics", code)
        self.assertIn("bootstrap_diagnostics", code)
        self.assertIn("owner_unverified", code)
        self.assertNotIn("import campaign", code)
        self.assertLess(len(code), 100000)


class CaptureTests(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory(prefix="keli-icmp-capture-")
        self.addCleanup(temporary.cleanup)
        self.base = Path(temporary.name).resolve()
        self.events = []
        self.campaign = SimpleNamespace(BASE=self.base, RUN=RUN, assert_scope=Mock(), save=Mock(side_effect=self.save), load=self.load)
        self.node = SimpleNamespace(task=Mock(side_effect=self.task), execute=Mock(side_effect=self.execute))
        self.save("authorization.json", {"run_id": RUN, "user_approved": True, "entry_probe_diagnostics": SCOPE})
        self.save("campaign-window.json", {"run_id": RUN, "started_at": 50, "expires_at": 700})
        self.events.clear()
        blocked = patch.object(subprocess, "Popen", side_effect=AssertionError("SSH/cloud/DNS subprocess forbidden"))
        blocked.start()
        self.addCleanup(blocked.stop)

    def save(self, name, value, exclusive=False):
        with (self.base / name).open("x" if exclusive else "w") as stream:
            json.dump(value, stream)
        self.events.append("save:" + name)

    def load(self, name):
        return json.loads((self.base / name).read_text())

    def task(self, role):
        if role == "candidate":
            raise ValueError(SECRET)
        return {"id": TASK, "label": RUN + "-source", "external_id": "123", "public_ips": [SOURCE],
                "host_public_key": "ssh-ed25519 synthetic-only-public-key"}

    def execute(self, role, code, timeout):
        self.assertLessEqual(timeout, 22)
        self.assertIn("owner_unverified", code)
        self.events.append("collect:" + role)
        return make_record(role=role, active=not (self.base / "stop-request.json").exists())

    def collect(self, **kwargs):
        return capture.capture(self.campaign, self.node, wall=lambda: 100, **kwargs)

    def cleanup(self):
        self.events.append("delete")
        return {"ok": True}

    def wrapper(self, cleanup=None):
        modules = {"campaign": self.campaign, "node": self.node, "cleanup": SimpleNamespace(main=Mock(side_effect=cleanup or self.cleanup))}
        return bootstrap_capture.loader_with_diagnostics(modules.__getitem__)("cleanup"), modules

    def test_one_owned_role_collects_without_using_missing_candidate(self):
        result = self.collect()
        self.assertEqual([row["status"] for row in result["records"]], ["collected", "receipt_unavailable"])
        self.node.execute.assert_called_once()
        self.assertNotIn(SECRET, json.dumps(result))

    def test_absent_or_disabled_scope_never_connects_or_creates_intent(self):
        for config in [None, dict(SCOPE, enabled=False)]:
            auth = {"run_id": RUN, "user_approved": True}
            if config is not None:
                auth["entry_probe_diagnostics"] = config
            self.save("authorization.json", auth)
            self.assertEqual(self.collect()["status"], "not_enabled")
        self.node.execute.assert_not_called()
        self.node.task.assert_not_called()
        self.assertFalse((self.base / capture.INTENT).exists())

    def test_invalid_or_unapproved_target_scope_is_refused_before_ssh(self):
        for scope in [dict(SCOPE, targets=["127.0.0.1"]), dict(SCOPE, targets=["224.0.0.1"]), dict(SCOPE, timeout_seconds=True), dict(SCOPE, raw_config=SECRET)]:
            self.save("authorization.json", {"run_id": RUN, "user_approved": True, "entry_probe_diagnostics": scope})
            with self.assertRaises(capture.bootstrap.Refused):
                self.collect()
        self.node.execute.assert_not_called()
        self.assertFalse((self.base / capture.INTENT).exists())

    def test_existing_collection_intent_prevents_a_second_ssh_even_without_report(self):
        self.save(capture.INTENT, {"run_id": RUN})
        self.assertEqual(self.collect()["status"], "original_collection_only")
        self.node.execute.assert_not_called()
        self.assertFalse((self.base / capture.REPORT).exists())

    def test_closed_round_leaves_all_old_bytes_unchanged(self):
        self.save("closed.json", {"run_id": RUN})
        before = {path.name: path.read_bytes() for path in self.base.iterdir()}
        self.assertEqual(self.collect()["status"], "closed_round_refused")
        self.assertEqual(before, {path.name: path.read_bytes() for path in self.base.iterdir()})
        self.node.task.assert_not_called()

    def test_delete_intent_and_foreign_task_do_not_connect(self):
        self.save("source-delete-intent.json", {})
        self.assertEqual(self.collect()["records"][0]["status"], "delete_or_close_started")
        self.node.execute.assert_not_called()

    def test_foreign_or_incomplete_receipt_does_not_connect(self):
        for change in [{"label": "business"}, {"public_ips": ["127.0.0.1"]}, {"host_public_key": ""}, {"external_id": None}]:
            with tempfile.TemporaryDirectory() as directory:
                prior = self.base
                self.base = self.campaign.BASE = Path(directory)
                self.save("authorization.json", {"run_id": RUN, "user_approved": True, "entry_probe_diagnostics": SCOPE})
                self.save("campaign-window.json", {"run_id": RUN, "started_at": 50, "expires_at": 700})
                original = self.task("source")
                self.node.task.side_effect = lambda role: dict(original, **change)
                self.collect()
                self.base = self.campaign.BASE = prior
        self.node.execute.assert_not_called()

    def test_stop_request_collects_passive_evidence_but_no_new_echo_requests(self):
        self.save("stop-request.json", {"reason": "synthetic_stop"})
        result = self.collect()
        evidence = result["records"][0]["evidence"]
        self.assertFalse(evidence["active_requested"])
        self.assertTrue(all(row["skip_reason"] == "stop_requested" for row in evidence["rows"]))

    def test_final_minute_and_expired_window_do_not_start_ssh(self):
        for expires in [99, 150]:
            if (self.base / capture.INTENT).exists():
                (self.base / capture.INTENT).unlink()
                (self.base / capture.REPORT).unlink()
            self.save("campaign-window.json", {"run_id": RUN, "started_at": 50, "expires_at": expires})
            result = self.collect()
            self.assertEqual(result["records"][0]["status"], "budget_exhausted")
        self.node.execute.assert_not_called()

    def test_two_roles_share_one_44_second_budget(self):
        original = self.task("source")
        self.node.task.side_effect = lambda role: dict(original, label=RUN + "-" + role)
        ticks = iter([0, 0, 45])
        result = self.collect(clock=lambda: next(ticks))
        self.assertEqual(result["records"][1]["status"], "budget_exhausted")
        self.node.execute.assert_called_once()

    def test_bad_response_is_not_saved_as_zero_errors(self):
        self.node.execute.side_effect = None
        self.node.execute.return_value = dict(make_record(), raw_log=SECRET)
        result = self.collect()
        self.assertEqual(result["records"][0]["status"], "response_unavailable")
        self.assertNotIn(SECRET, json.dumps(result))

    def test_transport_error_is_aggregate_only(self):
        self.node.execute.side_effect = TimeoutError(SECRET)
        result = self.collect()
        self.assertEqual(result["records"][0]["status"], "transport_unavailable")
        self.assertNotIn(SECRET, json.dumps(result))

    def test_opt_in_summary_is_saved_before_original_cleanup(self):
        wrapper, _ = self.wrapper()
        with patch.object(bootstrap_capture, "capture", return_value={}):
            self.assertTrue(wrapper.main()["ok"])
        self.assertLess(self.events.index("save:" + capture.REPORT), self.events.index("delete"))
        self.assertEqual(self.events.count("delete"), 1)

    def test_failure_branch_captures_before_stop_and_cleanup_does_not_reprobe(self):
        result = self.collect()
        self.assertTrue(result["records"][0]["evidence"]["active_requested"])
        original = (self.base / capture.REPORT).read_bytes()
        self.save("stop-request.json", {"reason": "synthetic_baseline_unavailable"})
        wrapper, _ = self.wrapper()
        with patch.object(bootstrap_capture, "capture", return_value={}):
            self.assertTrue(wrapper.main()["ok"])
        self.node.execute.assert_called_once()
        self.assertEqual((self.base / capture.REPORT).read_bytes(), original)
        self.assertEqual(self.events.count("delete"), 1)

    def test_collect_failure_does_not_hide_original_cleanup_or_raw_exception(self):
        wrapper, _ = self.wrapper()
        with patch.object(bootstrap_capture, "capture", return_value={}), patch.object(capture, "capture", side_effect=OSError(SECRET)):
            self.assertTrue(wrapper.main()["ok"])
        self.assertEqual(self.load(capture.REPORT)["status"], "collector_failed")
        self.assertNotIn(SECRET, (self.base / capture.REPORT).read_text())
        self.assertEqual(self.events.count("delete"), 1)

    def test_failed_summary_write_still_executes_cleanup_exactly_once(self):
        wrapper, _ = self.wrapper()
        self.campaign.save.side_effect = OSError(SECRET)
        with patch.object(bootstrap_capture, "capture", return_value={}):
            self.assertTrue(wrapper.main()["ok"])
        self.node.execute.assert_not_called()
        self.assertEqual(self.events, ["delete"])

    def test_original_cleanup_failure_is_visible_and_never_retried(self):
        cleanup = Mock(side_effect=RuntimeError("synthetic_delete_failure"))
        wrapper, _ = self.wrapper(cleanup)
        with patch.object(bootstrap_capture, "capture", return_value={}), self.assertRaisesRegex(RuntimeError, "synthetic_delete_failure"):
            wrapper.main()
        cleanup.assert_called_once()
        self.assertTrue((self.base / capture.REPORT).is_file())

    def test_fresh_launcher_pins_both_new_tools_and_validates_scope(self):
        self.assertTrue({"entry_probe_capture.py", "entry_probe_diagnostics.py"} <= set(entry.TOOLS))
        self.assertIsNone(capture.configuration({"run_id": RUN, "user_approved": True}, RUN))
        with self.assertRaises(capture.bootstrap.Refused):
            capture.configuration({"run_id": RUN, "user_approved": False, "entry_probe_diagnostics": SCOPE}, RUN)


if __name__ == "__main__":
    unittest.main()
