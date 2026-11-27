"""No kernel or network operations: replay the host adapter's fixed calls."""

from dataclasses import replace
import json
import subprocess
import unittest
from unittest.mock import patch

import usdo1_host_guard as guard


SCOPE = guard.Scope("349c6833-1234-4e33-9149-b8d31246dd12", "59902", "1.2.3.4", "10.20.13.7", "10.20.13.6",
                    1000, 2000, "8234832f-bcbb-4f97-855c-14e4488c7469", "b" * 64, True)


class MemoryLedger:
    state = None

    def read(self):
        return self.state

    def write(self, record):
        self.state = record


class MockHost:
    def __init__(self):
        self.events, self.timer, self.ready_state = [], False, True
        self.table, self.link, self.callback = ("absent",) * 3
        self.monotonic = 130
        self.now = 1200
        self.change_during_check = 0
        self.fail_after_add = False
        self.fail_stop = False
        self.confirm_timer = True

    def ready(self, _):
        return self.ready_state

    def schedule(self, _, seconds):
        self.events.append(("timer", seconds))
        self.timer = self.confirm_timer

    def timer_bound(self, _):
        return self.timer

    def check(self, content):
        self.events.append("check")
        self.monotonic += self.change_during_check
        self.now -= self.change_during_check

    def add(self, _, content):
        self.events.append("add")
        self.rendered = content
        self.table = "owned"
        if self.fail_after_add:
            raise TimeoutError("synthetic lost acknowledgement")

    def table_state(self, _):
        return self.table

    def link_state(self, _):
        return self.link

    def callback_state(self, _):
        return self.callback

    def fence(self, _):
        self.events.append("fence")

    def stop_callback(self, _):
        self.events.append("stop")
        if self.fail_stop:
            raise TimeoutError("synthetic stop failure")
        self.callback = "absent"

    def remove_link(self, _):
        self.events.append("delete_link")
        self.link = "absent"

    def remove_table(self, _):
        self.events.append("delete_table")
        self.table = "absent"


class GuardPolicyTest(unittest.TestCase):
    def setUp(self):
        self.kernel, self.ledger = MockHost(), MemoryLedger()
        self.boot = SCOPE.boot_id

    def service(self, scope=SCOPE):
        return guard.Guard(scope, self.kernel, self.ledger, now=lambda: self.kernel.now,
                           monotonic=lambda: self.kernel.monotonic, boot=lambda: self.boot)

    def test_closed_or_rebooted_scope_does_not_touch_host(self):
        with self.assertRaisesRegex(guard.Refused, "not_approved"):
            self.service(replace(SCOPE, approved=False)).arm()
        self.boot = "4234832f-bcbb-4f97-855c-14e4488c7469"
        with self.assertRaisesRegex(guard.Refused, "rebooted"):
            self.service().arm()
        self.assertIsNone(self.ledger.state)
        self.assertEqual([], self.kernel.events)

    def test_timeout_and_address_validation(self):
        for changes in ({"source_ipv4": "1.2.3.4/24"}, {"source_ipv4": "127.0.0.1"}, {"source_ipv4": guard.HOST},
                        {"source_ipv4": "::1"}, {"withdraw_at": SCOPE.issued_at + 5101},
                        {"callback_private_ipv4": "100.64.0.1"}, {"instance_id": "8;anything"}):
            with self.subTest(changes=changes), self.assertRaises(guard.Refused):
                replace(SCOPE, **changes)
        self.kernel.now = SCOPE.withdraw_at - 25
        with self.assertRaisesRegex(guard.Refused, "window_closed"):
            self.service().arm()
        self.assertEqual([], self.kernel.events)

    def test_never_publishes_before_readback_of_expiry(self):
        self.kernel.confirm_timer = False
        with self.assertRaisesRegex(guard.Refused, "timer_unconfirmed"):
            self.service().arm()
        self.assertEqual(self.kernel.events, [("timer", 790)])
        self.assertEqual(self.ledger.state["phase"], "withdrawn")

    def test_one_shot_no_renewal_and_filter_scope(self):
        service = self.service()
        self.assertEqual(service.arm(), {"state": "armed", "starts_callback_or_tunnel": False})
        self.assertEqual(self.kernel.events, [("timer", 790), "check", "add"])
        field = self.kernel.rendered
        self.assertIn('iifname "eth0" ip daddr 152.53.37.160 ip saddr @outer4 udp dport 51821 accept', field)
        self.assertIn("outer4 { 1.2.3.4 timeout 790s }", field)
        self.assertIn("inner4 { 10.20.13.7 timeout 790s }", field)
        self.assertIn('iifname "wg-keli123" ip daddr 10.20.13.6 ip saddr @inner4 tcp dport 443 accept', field)
        self.assertIn("tcp dport 443 drop", field)
        self.assertIn("udp dport 51821 drop", field)
        self.assertIn('forwarding iifname "wg-keli123" drop', field)
        self.assertIn('forwarding oifname "wg-keli123" drop', field)
        self.assertNotRegex(field, r"0\.0\.0\.0/0|flush ruleset|established|route add")
        with self.assertRaisesRegex(guard.Refused, "round_spent"):
            self.service().arm()

    def test_wall_clock_rewind_cannot_extend_monotonic_budget(self):
        self.kernel.change_during_check = 20
        self.service().arm()
        self.assertIn("outer4 { 1.2.3.4 timeout 770s }", self.kernel.rendered)
        self.assertEqual(self.kernel.events[0], ("timer", 790))

    def test_restart_and_expiry_cleanup_remove_only_owned_resources(self):
        self.service().arm()
        self.kernel.callback = self.kernel.link = "owned"
        self.kernel.timer = False  # timer is executing cleanup, not waiting.
        self.kernel.now = SCOPE.withdraw_at
        self.assertEqual(self.service().withdraw(), {"state": "withdrawn"})
        self.assertEqual(self.kernel.events[-4:], ["fence", "stop", "delete_link", "delete_table"])
        self.assertEqual(self.service().withdraw(), {"state": "withdrawn"})
        with self.assertRaisesRegex(guard.Refused, "window_closed"):
            self.service().arm()
        self.kernel.now = SCOPE.issued_at + 50
        with self.assertRaisesRegex(guard.Refused, "round_spent"):
            self.service().arm()

    def test_unknown_apply_result_is_fenced_without_rearming(self):
        self.kernel.fail_after_add = True
        with self.assertRaises(TimeoutError):
            self.service().arm()
        self.assertEqual(self.kernel.events[-3:], ["add", "fence", "delete_table"])
        with self.assertRaisesRegex(guard.Refused, "round_spent"):
            self.service().arm()

    def test_foreign_state_is_not_deleted_and_guard_stays_closed(self):
        self.service().arm()
        self.kernel.link = "foreign"
        with self.assertRaisesRegex(guard.Refused, "foreign_resource"):
            self.service().withdraw()
        self.assertEqual(self.kernel.events[-1], "fence")
        self.assertEqual(self.ledger.state["phase"], "withdrawing")
        self.assertNotIn("delete_link", self.kernel.events)
        self.assertEqual(self.kernel.table, "owned")

    def test_failed_stop_leaves_fence_and_later_cleanup_can_finish(self):
        self.service().arm()
        self.kernel.callback = self.kernel.link = "owned"
        self.kernel.fail_stop = True
        with self.assertRaises(TimeoutError):
            self.service().withdraw()
        self.assertEqual(self.kernel.events[-2:], ["fence", "stop"])
        self.assertEqual(self.kernel.table, "owned")
        self.kernel.fail_stop = False
        self.assertEqual(self.service().withdraw(), {"state": "withdrawn"})

    def test_collision_detected_before_claim_or_timer(self):
        self.kernel.ready_state = False
        with self.assertRaisesRegex(guard.Refused, "preflight_collision"):
            self.service().arm()
        self.assertEqual([], self.kernel.events)
        self.assertEqual(None, self.ledger.state)


class HostAdapterTest(unittest.TestCase):
    def test_argv_is_exact_and_expiry_is_bound_to_frozen_scope(self):
        calls = []
        def invoke(args, **options):
            calls.append((args, options))
            out = ("ActiveState=active\nSubState=waiting\nTriggers=" + SCOPE.timer +
                   ".service\nNextElapseUSecMonotonic=45s\n") if args[0].endswith("systemctl") else ""
            return subprocess.CompletedProcess(args, 0, out, "")
        kernel = guard.Host(invoke)
        kernel.schedule(SCOPE, 200)
        self.assertTrue(kernel.timer_bound(SCOPE))
        kernel.check(guard.rules(SCOPE, 1))
        kernel.add(SCOPE, guard.rules(SCOPE, 150))
        kernel.fence(SCOPE)
        timer = calls[0][0]
        self.assertEqual(timer[0], "/usr/bin/systemd-run")
        self.assertEqual(timer[-3:], [guard.ENTRY, "expire", SCOPE.fingerprint])
        self.assertIn("--on-active=200s", timer)
        self.assertIn("--property=CapabilityBoundingSet=CAP_NET_ADMIN", timer)
        self.assertEqual(calls[2][0], ["/usr/sbin/nft", "-c", "-f", "-"])
        self.assertEqual(calls[3][0], ["/usr/sbin/nft", "-f", "-"])
        self.assertEqual(calls[4][1]["input"].splitlines(),
                         [f"flush set inet {SCOPE.table} outer4", f"flush set inet {SCOPE.table} inner4"])

    def test_wrong_timer_or_no_next_trigger_is_not_proof(self):
        for target, when in ((SCOPE.timer + ".service", "0"), ("other.service", "100s")):
            with self.subTest(target=target, when=when):
                out = f"ActiveState=active\nSubState=waiting\nTriggers={target}\nNextElapseUSecMonotonic={when}\n"
                self.assertFalse(guard.Host(lambda args, **_: subprocess.CompletedProcess(args, 0, out, "")).timer_bound(SCOPE))

    def test_link_inspection_does_not_count_unrelated_types(self):
        for item, want in (({"ifname":"eth0"}, "absent"),
                           ({"ifname":guard.DEVICE, "ifalias":"unrelated"}, "foreign"),
                           ({"ifname":guard.DEVICE, "ifalias":SCOPE.alias, "linkinfo":{"info_kind":"wireguard"}}, "owned")):
            with self.subTest(want=want):
                out = json.dumps([item])
                self.assertEqual(guard.Host(lambda args, **_: subprocess.CompletedProcess(args, 0, out, "")).link_state(SCOPE), want)

    def test_no_arbitrary_command_or_paths_and_no_unbound_expire(self):
        for args in (["arm", "/tmp/request.json"], ["iptables"], ["expire"]):
            with self.subTest(args=args), patch.object(guard.sys, "argv", ["guard", *args]), \
                    patch.object(guard.sys, "platform", "linux"), patch.object(guard.os, "geteuid", lambda: 0, create=True), \
                    self.assertRaisesRegex(guard.Refused, "operation_denied"):
                guard.main()


if __name__ == "__main__":
    unittest.main()
