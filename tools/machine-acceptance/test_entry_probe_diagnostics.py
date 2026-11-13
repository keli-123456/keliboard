import copy
import json
from pathlib import Path
import subprocess
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import Mock, patch

import entry_probe_diagnostics as probe


RUN = "keli-accept-synthetic-icmp-01234567"
TASK = "11111111-1111-4111-8111-111111111111"
SOURCE = "45.79.10.20"
TARGETS = ["39.156.70.37", "111.33.167.71", "123.150.76.218", "157.255.219.143"]
SECRET = "NEVER_EXPORT_RULE_COMMENT_OR_GATEWAY_OR_TOKEN"


def observed(value, exit_code=0):
    return probe.CommandResult("observed", exit_code, value if isinstance(value, bytes) else json.dumps(value).encode())


def ruleset(packets=10):
    return {"nftables": [
        {"metainfo": {"version": "synthetic"}},
        {"chain": {"family": "inet", "table": SECRET, "name": "input", "hook": "input", "prio": -10, "policy": "accept"}},
        {"rule": {"family": "inet", "table": SECRET, "chain": "input", "handle": 3, "comment": SECRET,
                  "expr": [{"match": {"op": "==", "left": {"meta": {"key": "l4proto"}}, "right": "icmp"}},
                           {"counter": {"packets": packets, "bytes": packets * 64}}, {"accept": None}]}},
        {"chain": {"family": "ip", "table": SECRET, "name": "output", "hook": "output", "prio": 0, "policy": "drop"}},
        {"rule": {"family": "ip", "table": SECRET, "chain": "output", "handle": 4,
                  "expr": [{"counter": {"packets": packets, "bytes": packets * 64}}, {"drop": None}]}},
        {"rule": {"family": "ip", "table": SECRET, "chain": "output", "handle": 5,
                  "expr": [{"match": {"op": "==", "left": {"ct": {"key": "state"}}, "right": {"set": ["established", "related"]}}},
                           {"jump": {"target": SECRET}}]}},
    ]}


class FakeCommands:
    def __init__(self):
        self.calls = []
        self.route_source = SOURCE
        self.route_type = "unicast"
        self.ping_exit = 0
        self.ping_output = None
        self.nft_result = None
        self.version = b"ping from iputils synthetic"

    def __call__(self, args, timeout, limit):
        self.calls.append((args, timeout, limit))
        if args[-1] == "-V":
            return observed(self.version)
        if args[0].endswith("systemctl"):
            return observed(b"123\n")
        if args[0].endswith("nft"):
            return self.nft_result or observed(ruleset())
        if args[0].endswith("ip"):
            return observed([{"prefsrc": self.route_source, "type": self.route_type, "gateway": SECRET, "dev": SECRET}])
        if args[0].endswith("ping"):
            return observed(self.ping_output or ("64 bytes from " + args[-1] + ": icmp_seq=1 ttl=52 time=100 ms\n"
                            "1 packets transmitted, 1 received, 0% packet loss\n").encode(), self.ping_exit)
        raise AssertionError("unreviewed command")


def make_record(role="source", active=True, commands=None, **kwargs):
    with tempfile.TemporaryDirectory(prefix="keli-icmp-synthetic-") as directory:
        root = Path(directory)
        owner = root / "var/lib/keli-test-owner/owner.json"
        owner.parent.mkdir(parents=True)
        owner.write_text(json.dumps({"schema": 1, "run_id": RUN, "task_id": TASK}))
        owner.chmod(0o600)
        with patch.object(probe, "program", side_effect=lambda name: probe.PROGRAMS[name][0]), \
                patch.object(probe.os, "readlink", return_value="net:[123]"), \
                patch.object(subprocess, "Popen", side_effect=AssertionError("real process forbidden")):
            result = probe.collect(RUN, TASK, role, TARGETS, SOURCE, active=active, root=root,
                                   run_command=commands or FakeCommands(), **kwargs)
        # This fixture is synthetic; setting the wire flag is not Linux permission evidence.
        if result.get("ok"):
            result["private_permissions_checked"] = True
        return result


class PingClassificationTests(unittest.TestCase):
    def test_echo_requires_real_target_summary_and_zero_exit(self):
        text = ("64 bytes from " + TARGETS[0] + ": icmp_seq=1 ttl=51 time=2 ms\n1 packets transmitted, 1 received\n").encode()
        self.assertEqual(probe.classify_ping(observed(text), TARGETS[0])["classification"], "echo_reply")
        for raw, code in [(text, 1), (text.replace(TARGETS[0].encode(), TARGETS[1].encode()), 0), (b"1 packets transmitted, 1 received", 0)]:
            self.assertNotEqual(probe.classify_ping(observed(raw, code), TARGETS[0])["classification"], "echo_reply")

    def test_zero_echo_deadline_is_not_proof_of_network_filter(self):
        result = probe.classify_ping(observed(b"1 packets transmitted, 0 received, 100% packet loss\n", 1), TARGETS[0])
        self.assertEqual(result["classification"], "no_echo_by_deadline")
        self.assertEqual(result["reported_errors"], 0)

    def test_multiple_packets_preserve_counts_and_are_never_echo_or_single_packet_loss(self):
        for sent, received, code in [(3, 0, 1), (3, 1, 0), (1, 2, 0), (4294967295, 0, 1)]:
            raw = ("64 bytes from " + TARGETS[0] + ": icmp_seq=1 ttl=51 time=1 ms\n"
                   + str(sent) + " packets transmitted, " + str(received) + " received, 100% packet loss\n").encode()
            row = probe.classify_ping(observed(raw, code), TARGETS[0])
            self.assertEqual(row['classification'], 'packet_budget_exceeded')
            self.assertEqual((row['sent'], row['received'], row['reported_errors']), (sent, received, 0))

    def test_whitespace_singular_and_optional_packets_are_parsed(self):
        for raw in [b'  1 packet transmitted,\t0 packets received, 100% packet loss\n',
                    b'1 packets transmitted, 0 received, +1 errors, 100% packet loss\n']:
            row = probe.classify_ping(observed(raw, 1), TARGETS[0])
            self.assertEqual((row['sent'], row['received']), (1, 0))
            self.assertIn(row['classification'], {'no_echo_by_deadline', 'icmp_error'})

    def test_missing_ambiguous_overflow_or_zero_transmitted_never_become_no_reply(self):
        for raw in [b'', b'0 packets transmitted, 0 received', b'4294967296 packets transmitted, 0 received',
                    b'99999999999 packets transmitted, 0 received', b'-1 packets transmitted, 0 received',
                    b'1 packets transmitted, 0 received\n1 packets transmitted, 0 received\n',
                    b'1 packets transmitted, 0 received, +99999999999 errors']:
            row = probe.classify_ping(observed(raw, 1), TARGETS[0])
            self.assertEqual(row['classification'], 'execution_error')
            if not raw.startswith(b'0 '):
                self.assertIsNone(row['sent'])

    def test_returned_icmp_errors_on_stdout_do_not_disappear_into_timeout(self):
        for message, category in [
            ("Destination Host Unreachable", "icmp_host_unreachable"),
            ("Destination Net Unreachable", "icmp_network_unreachable"),
            ("Destination Port Unreachable", "icmp_port_unreachable"),
            ("Destination Protocol Unreachable", "icmp_protocol_unreachable"),
            ("Destination Host Prohibited", "icmp_prohibited"),
            ("Packet filtered", "icmp_prohibited"),
            ("Frag needed and DF set", "icmp_fragmentation_needed"),
            ("Time to live exceeded", "icmp_ttl_exceeded"),
            ("Parameter problem", "icmp_parameter_problem"),
        ]:
            with self.subTest(category=category):
                raw = ("From " + SECRET + " icmp_seq=1 " + message + "\n1 packets transmitted, 0 received, +1 errors\n").encode()
                row = probe.classify_ping(observed(raw, 1), TARGETS[0])
                self.assertEqual(row["classification"], "icmp_error")
                self.assertIn(category, row["error_categories"])
                self.assertEqual(row["reported_errors"], 1)
                self.assertNotIn(SECRET, json.dumps(row))

    def test_unknown_returned_error_is_not_silently_classified_as_no_reply(self):
        row = probe.classify_ping(observed(b"1 packets transmitted, 0 received, +1 errors", 1), TARGETS[0])
        self.assertEqual(row["classification"], "icmp_error")
        self.assertEqual(row["error_categories"], [])

    def test_local_permission_bind_and_route_errors_are_execution_errors(self):
        for message, category in [(b"Operation not permitted", "permission_denied"), (b"bind: Cannot assign requested address", "source_bind_error"),
                                  (b"connect: Network is unreachable", "local_route_error")]:
            row = probe.classify_ping(observed(message, 2), TARGETS[0])
            self.assertEqual(row["classification"], "execution_error")
            self.assertIn(category, row["error_categories"])

    def test_process_timeout_missing_and_output_limit_are_unavailable_not_zero(self):
        for state in ["timeout", "missing", "oversized", "budget_exhausted", "unavailable"]:
            row = probe.classify_ping(probe.CommandResult(state), TARGETS[0])
            self.assertEqual(row["state"], state)
            self.assertEqual(row["classification"], "unavailable")
            self.assertIsNone(row["received"])

    def test_redirect_warning_does_not_erase_valid_echo_or_its_warning(self):
        raw = ("Redirect Host\n64 bytes from " + TARGETS[0] + ": icmp_seq=1 ttl=50 time=1 ms\n1 packets transmitted, 1 received").encode()
        row = probe.classify_ping(observed(raw), TARGETS[0])
        self.assertEqual(row["classification"], "echo_reply")
        self.assertIn("icmp_redirect", row["error_categories"])


class RouteAndFirewallTests(unittest.TestCase):
    def test_route_only_exports_canonical_public_source_and_fixed_type(self):
        for source in [SOURCE, "127.0.0.1", "10.0.0.1", "2606:4700::1111", SECRET]:
            row = probe.route_summary(observed([{"prefsrc": source, "gateway": SECRET, "dev": SECRET}]), SOURCE)
            self.assertEqual(row["source_matches_receipt"], source == SOURCE)
            self.assertEqual(row["source_ip"], SOURCE if source == SOURCE else None)
            self.assertNotIn(SECRET, json.dumps(row))

    def test_malformed_or_duplicate_route_json_is_invalid(self):
        for raw in [b'[{"prefsrc":"45.79.10.20","prefsrc":"45.79.10.21"}]', b'{}', b'[]', b'[{"type":[]}]']:
            row = probe.route_summary(observed(raw), SOURCE)
            self.assertEqual(row["state"], "invalid")

    def test_all_chains_and_catch_all_drop_are_preserved_without_raw_rules(self):
        row = probe.firewall_summary(observed(ruleset()))
        self.assertEqual(row["state"], "observed")
        self.assertEqual([chain["policy"] for chain in row["chains"]], ["accept", "drop"])
        self.assertEqual([rule["verdict"] for rule in row["rules"]], ["accept", "drop", "jump"])
        self.assertTrue(row["rules"][0]["explicit_icmp"])
        self.assertFalse(row["rules"][1]["explicit_icmp"])
        self.assertTrue(all(rule["conditions_not_evaluated"] for rule in row["rules"]))
        self.assertFalse(row["effective_policy_evaluated"])
        self.assertNotIn(SECRET, json.dumps(row))

    def test_counter_deltas_require_same_structure_and_never_claim_attribution(self):
        before = probe.firewall_summary(observed(ruleset(10)))
        after = probe.firewall_summary(observed(ruleset(12)))
        delta = probe.firewall_delta(before, after)
        self.assertTrue(delta["structure_stable"])
        self.assertEqual(delta["rules"][0]["packets"], 2)
        self.assertTrue(delta["shared_counters_not_probe_attribution"])
        self.assertIsNone(delta["rules"][2]["packets"])

    def test_counter_reset_and_rule_change_do_not_become_zero_packets(self):
        before = probe.firewall_summary(observed(ruleset(10)))
        reset = probe.firewall_summary(observed(ruleset(1)))
        self.assertIsNone(probe.firewall_delta(before, reset)["rules"][0]["packets"])
        changed = ruleset(12)
        changed["nftables"][3]["chain"]["policy"] = "accept"
        delta = probe.firewall_delta(before, probe.firewall_summary(observed(changed)))
        self.assertFalse(delta["structure_stable"])
        self.assertEqual(delta["rules"], [])

    def test_named_counter_and_dynamic_set_remain_unmodelled(self):
        value = ruleset()
        value["nftables"].append({"set": {"family": "ip", "table": SECRET, "name": SECRET, "elem": [SOURCE]}})
        value["nftables"][2]["rule"]["expr"][1]["counter"] = SECRET
        row = probe.firewall_summary(observed(value))
        self.assertGreater(row["unmodelled_objects"], 0)
        self.assertIsNone(row["rules"][0]["packets"])
        self.assertNotIn(SECRET, json.dumps(row))

    def test_ruleset_errors_limits_and_unsupported_data_are_explicit(self):
        for result, state in [(observed(b"permission denied", 1), "unavailable"), (observed(b"{"), "invalid"),
                              (observed(b"x" * (probe.MAX_BYTES + 1)), "oversized"), (probe.CommandResult("missing"), "missing")]:
            row = probe.firewall_summary(result)
            self.assertEqual(row["state"], state)
            self.assertIsNone(row["structure_sha256"])
            self.assertEqual(row["chains"], [])

    def test_excessive_rules_are_unavailable_not_a_partial_allow_verdict(self):
        value = ruleset()
        value["nftables"] += [copy.deepcopy(value["nftables"][2])] * probe.MAX_RULES
        row = probe.firewall_summary(observed(value))
        self.assertEqual(row["state"], "oversized")
        self.assertEqual(row["rules"], [])

    def test_literal_source_and_target_matches_use_flow_direction_without_exporting_predicates(self):
        match = lambda field, value, op="==": {"match": {"op": op, "left": {"payload": {"protocol": "ip", "field": field}}, "right": value}}
        outgoing = [match("saddr", SOURCE), match("daddr", {"set": [TARGETS[0], TARGETS[2]]})]
        incoming = [match("daddr", SOURCE), match("saddr", {"set": [TARGETS[0], TARGETS[2]]})]
        self.assertEqual(probe.receipt_address_matches(outgoing, "output", TARGETS, SOURCE), [True, False, True, False])
        self.assertEqual(probe.receipt_address_matches(incoming, "input", TARGETS, SOURCE), [True, False, True, False])
        self.assertEqual(probe.receipt_address_matches([match("daddr", TARGETS[0], "!=")], "output", TARGETS, SOURCE), [False, True, True, True])

    def test_dynamic_or_non_base_chain_address_conditions_remain_unknown(self):
        expr = [{"match": {"op": "==", "left": {"payload": {"protocol": "ip", "field": "daddr"}}, "right": "@" + SECRET}}]
        self.assertEqual(probe.receipt_address_matches(expr, "output", TARGETS, SOURCE), [None] * 4)
        self.assertEqual(probe.receipt_address_matches(expr, None, TARGETS, SOURCE), [None] * 4)
        self.assertIsNone(probe.address_member(TARGETS[0], {"prefix": {"addr": SECRET, "len": 24}}))
        self.assertTrue(probe.address_member(TARGETS[0], {"prefix": {"addr": "39.156.70.0", "len": 24}}))

    def test_rule_summary_exports_only_authorized_flow_membership(self):
        value = ruleset()
        value["nftables"][4]["rule"]["expr"].insert(0, {"match": {"op": "==", "left": {"payload": {"protocol": "ip", "field": "daddr"}}, "right": TARGETS[1]}})
        row = probe.firewall_summary(observed(value), TARGETS, SOURCE)
        self.assertEqual(row["rules"][1]["receipt_address_matches"], [False, True, False, False])
        self.assertNotIn(TARGETS[1], json.dumps(row))
        self.assertFalse(row["effective_policy_evaluated"])


class CollectorTests(unittest.TestCase):
    def test_owned_collection_has_exact_source_bound_argv_and_no_mutating_commands(self):
        runner = FakeCommands()
        row = make_record(commands=runner)
        self.assertTrue(row["ok"])
        pings = [args for args, _, _ in runner.calls if "--" in args]
        self.assertEqual(pings, [["/usr/bin/ping", "-I", SOURCE, "-4", "-n", "-c", "1", "-W", "2", "--", ip] for ip in TARGETS])
        self.assertTrue(all('-w' not in args and '-i' not in args for args in pings))
        nft = [args for args, _, _ in runner.calls if args[0].endswith("nft")]
        self.assertEqual(nft, [["/usr/sbin/nft", "-j", "list", "ruleset"]] * 2)
        self.assertFalse(row["root_cause_confirmed"])
        self.assertFalse(row["censorship_confirmed"])
        self.assertNotIn(SECRET, json.dumps(row))

    def test_unexpected_multi_packet_result_stops_remaining_active_probes(self):
        runner = FakeCommands()
        runner.ping_output, runner.ping_exit = b'3 packets transmitted, 0 received, 100% packet loss', 1
        value = make_record(commands=runner)
        self.assertEqual(value['rows'][0]['ping']['classification'], 'packet_budget_exceeded')
        self.assertEqual(value['rows'][0]['ping']['sent'], 3)
        self.assertEqual([r['skip_reason'] for r in value['rows'][1:]], ['packet_budget_exceeded'] * 3)
        self.assertEqual(len([args for args, _, _ in runner.calls if '--' in args]), 1)

    def test_owner_or_identity_rejection_runs_no_subprocess(self):
        runner = Mock(side_effect=AssertionError("no commands"))
        with tempfile.TemporaryDirectory() as directory:
            for run, targets in [(RUN, TARGETS), ("bad", TARGETS), (RUN, ["127.0.0.1"])]:
                row = probe.collect(run, TASK, "source", targets, SOURCE, root=Path(directory), run_command=runner)
                self.assertFalse(row["ok"])
        runner.assert_not_called()

    def test_different_source_does_not_send_a_misattributed_ping(self):
        runner = FakeCommands()
        runner.route_source = "45.79.10.21"
        row = make_record(commands=runner)
        self.assertTrue(row["ok"])
        self.assertTrue(all(item["skip_reason"] == "source_mismatch" for item in row["rows"]))
        self.assertFalse(any("--" in args for args, _, _ in runner.calls))

    def test_no_icmp_after_stop_request(self):
        runner = FakeCommands()
        row = make_record(active=False, commands=runner)
        self.assertTrue(all(item["skip_reason"] == "stop_requested" for item in row["rows"]))
        self.assertFalse(any("--" in args for args, _, _ in runner.calls))

    def test_non_unicast_route_is_not_probed(self):
        runner = FakeCommands()
        runner.route_type = "blackhole"
        row = make_record(commands=runner)
        self.assertTrue(all(item["skip_reason"] == "route_unavailable" for item in row["rows"]))
        self.assertFalse(any("--" in args for args, _, _ in runner.calls))

    def test_missing_or_non_iputils_tool_is_not_treated_as_target_failure(self):
        runner = FakeCommands()
        runner.version = b"busybox ping"
        row = make_record(commands=runner)
        self.assertTrue(all(item["skip_reason"] == "tool_unavailable" for item in row["rows"]))
        self.assertFalse(any("--" in args for args, _, _ in runner.calls))

    def test_different_agent_namespace_skips_all_pings(self):
        runner = FakeCommands()
        with patch.object(probe, "namespace_summary", return_value={"state": "observed", "agent_pid": 123, "matches_agent": False}):
            row = make_record(commands=runner)
        self.assertTrue(all(item["skip_reason"] == "network_namespace_unverified" for item in row["rows"]))
        self.assertFalse(any("--" in args for args, _, _ in runner.calls))

    def test_command_and_collection_budget_exhaustion_is_not_zero_errors(self):
        runner = FakeCommands()
        ticks = iter([0, 21])
        row = make_record(commands=runner, clock=lambda: next(ticks, 21))
        self.assertTrue(row["ok"])
        self.assertEqual(runner.calls, [])
        self.assertEqual(row["firewall_before"]["state"], "budget_exhausted")

    def test_unreadable_firewall_does_not_invent_an_empty_allow_policy(self):
        runner = FakeCommands()
        runner.nft_result = observed(b"Operation not permitted " + SECRET.encode(), 1)
        row = make_record(commands=runner)
        self.assertEqual(row["firewall_before"]["state"], "unavailable")
        self.assertFalse(row["firewall_delta"]["structure_stable"])
        self.assertNotIn(SECRET, json.dumps(row))

    def test_unsafe_target_shapes_cannot_dispatch(self):
        for targets, timeout in [([], 2), (TARGETS * 2, 2), ([TARGETS[0]] * 2, 2), (["169.254.169.254"], 2),
                                 (["2606:4700::1111"], 2), (["example.com"], 2), (TARGETS, True), (TARGETS, 3)]:
            with self.subTest(targets=targets, timeout=timeout), self.assertRaises(probe.bootstrap.Refused):
                probe.validate_targets(targets, timeout)

    def test_namespace_read_is_fixed_pid_and_never_exports_link_text(self):
        for paths, matches in [(["net:[12]", "net:[12]"], True), (["net:[12]", "net:[13]"], False), ([SECRET, SECRET], None)]:
            with patch.object(probe.os, "readlink", side_effect=paths):
                row = probe.namespace_summary(Path("/"), observed(b"123\n"))
            self.assertIs(row["matches_agent"], matches)
            self.assertNotIn(SECRET, json.dumps(row))

    def test_agent_restart_during_collection_is_not_a_stable_context(self):
        before = {"state": "observed", "agent_pid": 123, "matches_agent": True}
        after = dict(before, agent_pid=124)
        with patch.object(probe, "namespace_summary", side_effect=[before, after]):
            row = make_record()
        self.assertFalse(row["agent_context_stable"])
        self.assertNotEqual(row["network_namespace"], row["network_namespace_after"])

    def test_procfs_counter_reader_does_not_trust_zero_file_size(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            path = root / "proc/self/net/snmp"
            path.parent.mkdir(parents=True)
            path.write_text("Icmp: " + " ".join(probe.ICMP_COUNTERS) + "\nIcmp: 1 2 3 4 5 6 7\n")
            real_stat = probe.os.fstat
            with patch.object(probe.os, "fstat", side_effect=lambda fd: SimpleNamespace(st_mode=real_stat(fd).st_mode, st_size=0)):
                row = probe.icmp_counters(root)
            self.assertEqual(row["state"], "observed")
            self.assertEqual(row["values"]["OutEchos"], 2)

    def test_missing_malformed_and_oversized_proc_counters_remain_unknown(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            self.assertIsNone(probe.icmp_counters(root)["values"])
            path = root / "proc/self/net/snmp"
            path.parent.mkdir(parents=True)
            for body, state in [("Icmp: OutEchos\nIcmp: -1\n", "invalid"), ("x" * 16385, "oversized")]:
                path.write_text(body)
                row = probe.icmp_counters(root)
                self.assertEqual(row["state"], state)
                self.assertIsNone(row["values"])


class BoundedCommandTests(unittest.TestCase):
    def fake_process(self):
        stream = Mock()
        stream.fileno.return_value = 123
        process = Mock(stdout=stream)
        process.wait.return_value = 0
        process.poll.return_value = 0
        selector = Mock()
        mapping = {}
        selector.register.side_effect = lambda *args: mapping.update({123: True})
        selector.unregister.side_effect = lambda *args: mapping.clear()
        selector.get_map.side_effect = lambda: mapping
        selector.select.return_value = [(SimpleNamespace(fileobj=stream), 1)]
        return process, selector

    def run_fake(self, process, selector, chunks, **kwargs):
        with patch.object(probe.os, "name", "posix"), patch.object(probe.subprocess, "Popen", return_value=process) as launch, \
                patch.object(probe.selectors, "DefaultSelector", return_value=selector), patch.object(probe.os, "read", side_effect=chunks):
            result = probe.command(["/usr/bin/ping", "-V"], **kwargs)
        self.assertIs(launch.call_args.kwargs["stderr"], subprocess.STDOUT)
        self.assertNotIn("shell", launch.call_args.kwargs)
        process.stdout.close.assert_called_once()
        selector.close.assert_called_once()
        return result

    def test_stdout_and_stderr_share_one_bounded_pipe(self):
        process, selector = self.fake_process()
        row = self.run_fake(process, selector, [b"synthetic", b""], timeout=1, limit=16)
        self.assertEqual(row.output, b"synthetic")
        self.assertEqual(row.state, "observed")

    def test_output_overflow_kills_reaps_and_discards_raw_bytes(self):
        process, selector = self.fake_process()
        process.poll.return_value = None
        row = self.run_fake(process, selector, [SECRET.encode()], timeout=1, limit=8)
        self.assertEqual(row.state, "oversized")
        self.assertEqual(row.output, b"")
        process.kill.assert_called_once()
        process.wait.assert_called_once()

    def test_timeout_kills_and_reaps_child(self):
        process, selector = self.fake_process()
        process.poll.return_value = None
        with patch.object(probe.time, "monotonic", side_effect=[0, 2]):
            row = self.run_fake(process, selector, [], timeout=1)
        self.assertEqual(row.state, "timeout")
        process.kill.assert_called_once()
        process.wait.assert_called_once()


if __name__ == "__main__":
    unittest.main()
