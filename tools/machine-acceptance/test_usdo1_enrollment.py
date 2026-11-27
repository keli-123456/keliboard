import base64
import sqlite3
import tempfile
import threading
import unittest
from concurrent.futures import ThreadPoolExecutor
from contextlib import closing
from dataclasses import replace
from pathlib import Path

from driver_journal import Rejected
from usdo1_enrollment import EnrollmentIntent, Usdo1Enrollment


def key(byte):
    return base64.b64encode(bytes([byte]) * 32).decode("ascii")


class Usdo1EnrollmentTest(unittest.TestCase):
    CANDIDATE = "7801"
    PROTECTED = "6150"
    INTENT = EnrollmentIntent(
        service_revision=5, task_id="d0809021-f5de-4059-b787-632ed7739100", installation_id="1" * 32,
        marker="b" * 32, account_id=2, region="sg-sin-2", size="g6-standard-4", image="linode/debian12",
        label="keli-round-fixture", candidate_private_ip="10.201.12.19", transport_sha256="a" * 64,
        protected_instances=(PROTECTED,), opened_at=1000, enrollment_until=1300, task_until=1600, grant_until=3500,
    )

    def setUp(self):
        folder = tempfile.TemporaryDirectory()
        self.addCleanup(folder.cleanup)
        self.database = Path(folder.name) / "test-enrollment.sqlite3"
        self.db = sqlite3.connect(self.database)
        self.addCleanup(self.db.close)
        self.now = 1020
        self.denied = set()
        self.reads = []
        self.provider_result = {
            "account_id": 2, "region": "sg-sin-2", "size": "g6-standard-4", "image": "linode/debian12",
            "label": "keli-round-fixture", "marker": "b" * 32, "instance_id": self.CANDIDATE, "created_at": 1015,
            "ipv4": "1.2.3.4",
        }
        self.console_result = {"instance_id": self.CANDIDATE, "marker": "b" * 32, "public_key": key(19)}

    def service(self, *, db=None, intent=None, console=None, provider=None):
        return Usdo1Enrollment(
            self.db if db is None else db, intent or self.INTENT, clock=lambda: self.now,
            authorize=lambda command, _: command not in self.denied,
            provider_readback=provider or self.provider, console_readback=console or self.console,
        )

    def provider(self, candidate):
        self.reads.append(("provider", candidate))
        return self.provider_result.copy()

    def console(self, candidate):
        self.reads.append(("console", candidate))
        return self.console_result.copy()

    def prepared(self):
        service = self.service()
        service.prepare()
        service.bind_candidate(self.CANDIDATE)
        return service

    def state(self):
        return self.db.execute("SELECT * FROM usdo1_enrollment").fetchone()

    def test_frozen_scope_denies_changed_task_installation_and_transport(self):
        service = self.prepared()
        before = self.state()
        for changes in ({"service_revision": 6}, {"installation_id": "3" * 32},
                        {"transport_sha256": "d" * 64}, {"candidate_private_ip": "10.201.12.20"}):
            with self.subTest(changes=changes), self.assertRaisesRegex(Rejected, "scope_changed"):
                self.service(intent=replace(self.INTENT, **changes)).register()
        with self.assertRaisesRegex(Rejected, "already_started"):
            service.prepare()
        self.assertEqual(self.state(), before)

    def test_no_console_read_before_binding_and_protected_instance_is_denied(self):
        service = self.service()
        service.prepare()
        with self.assertRaisesRegex(Rejected, "candidate_unbound"):
            service.register()
        with self.assertRaisesRegex(Rejected, "candidate_mismatch"):
            service.bind_candidate(self.PROTECTED)
        self.assertEqual(self.reads, [])

    def test_binds_only_matching_provider_identity_and_public_address(self):
        service = self.service()
        service.prepare()
        for name, value in (("instance_id", "7802"), ("account_id", True), ("region", "us-east"),
                            ("image", "linode/debian11"), ("marker", "c" * 32), ("created_at", 999)):
            with self.subTest(name=name), self.assertRaisesRegex(Rejected, "candidate_mismatch"):
                service_using = self.service(provider=lambda _: self.provider_result | {name: value})
                service_using.bind_candidate(self.CANDIDATE)
        with self.assertRaisesRegex(Rejected, "candidate_address_invalid"):
            self.service(provider=lambda _: self.provider_result | {"ipv4": "127.0.0.1"}).bind_candidate(self.CANDIDATE)
        service.bind_candidate(self.CANDIDATE)
        before = list(self.reads)
        with self.assertRaisesRegex(Rejected, "candidate_mismatch"):
            service.bind_candidate("7802")
        self.assertEqual(self.reads, before)

    def test_wrong_console_instance_marker_or_key_does_not_consume_intent(self):
        self.prepared()
        before = self.state()
        for patch in ({"instance_id": "7802"}, {"marker": "c" * 32}, {"guest_claim": True}):
            with self.subTest(patch=patch), self.assertRaisesRegex(Rejected, "console_mismatch"):
                self.service(console=lambda _: self.console_result | patch).register()
        noncanonical = key(19)[:-2] + "1="
        for public in ("bad key", key(0), noncanonical):
            with self.subTest(public=public), self.assertRaisesRegex(Rejected, "public_key_invalid"):
                self.service(console=lambda _: self.console_result | {"public_key": public}).register()
        self.assertEqual(self.state(), before)
        self.assertEqual(self.service().register()["status"], "registered")

    def test_identical_registration_is_read_only_across_restart(self):
        service = self.prepared()
        initial = service.register()
        self.assertEqual(initial["status"], "registered")
        before = self.state()
        self.now += 1
        with closing(sqlite3.connect(self.database)) as replacement:
            repeated = self.service(db=replacement).register()
            self.assertEqual(replacement.total_changes, 0)
        self.assertEqual(repeated, initial | {"status": "already_registered"})
        self.assertEqual(self.state(), before)
        self.assertNotIn(self.console_result["public_key"], repr(before))
        with self.assertRaisesRegex(Rejected, "key_changed"):
            self.service(console=lambda _: self.console_result | {"public_key": key(21)}).register()
        self.assertEqual(self.state(), before)

    def test_provider_address_changed_before_registration(self):
        service = self.prepared()
        before = self.state()
        self.provider_result["ipv4"] = "1.2.3.5"
        with self.assertRaisesRegex(Rejected, "provider_drift"):
            service.register()
        self.assertEqual(self.state(), before)

    def test_unavailable_provider_or_console_does_not_register(self):
        self.prepared()
        before = self.state()
        with self.assertRaisesRegex(Rejected, "candidate_mismatch"):
            self.service(provider=lambda _: None).register()
        with self.assertRaisesRegex(Rejected, "console_mismatch"):
            self.service(console=lambda _: None).register()
        self.assertEqual(self.state(), before)

    def test_expires_at_exact_limit_and_stays_expired_after_clock_rewinds(self):
        service = self.prepared()
        self.now = self.INTENT.enrollment_until
        before = list(self.reads)
        with self.assertRaisesRegex(Rejected, "enrollment_expired"):
            service.register()
        self.now = self.INTENT.enrollment_until - 1
        with self.assertRaisesRegex(Rejected, "enrollment_expired"):
            self.service().register()
        self.assertEqual(self.reads, before)

    def test_deadline_rechecked_after_console_readback(self):
        self.prepared()

        def delayed(candidate):
            self.now = self.INTENT.enrollment_until
            return self.console(candidate)

        with self.assertRaisesRegex(Rejected, "enrollment_expired"):
            self.service(console=delayed).register()
        self.assertEqual(self.state()[-3:-1], (None, "expired"))

    def test_revoke_persists_and_original_key_is_rejected(self):
        service = self.prepared()
        service.register()
        self.assertEqual(service.revoke(), {"status": "revoked"})
        baseline = self.state()
        self.assertEqual(service.revoke(), {"status": "revoked"})
        with self.assertRaisesRegex(Rejected, "enrollment_revoked"):
            self.service().register()
        self.assertEqual(self.state(), baseline)

    def test_revoke_while_reading_console_wins_before_consume(self):
        self.prepared()

        def revoked(candidate):
            with closing(sqlite3.connect(self.database)) as another:
                self.service(db=another).revoke()
            return self.console(candidate)

        with self.assertRaisesRegex(Rejected, "enrollment_revoked"):
            self.service(console=revoked).register()
        self.assertEqual(self.state()[-3:-1], (None, "revoked"))

    def test_authority_rechecked_before_commit(self):
        self.prepared()
        before = self.state()

        def denied(candidate):
            self.denied.add("register")
            return self.console(candidate)

        with self.assertRaisesRegex(Rejected, "authority_denied"):
            self.service(console=denied).register()
        self.assertEqual(self.state(), before)

    def race_registrations(self, keys):
        ready = threading.Barrier(2, timeout=5)

        def attempt(byte):
            def console(_):
                ready.wait()
                return self.console_result | {"public_key": key(byte)}
            with closing(sqlite3.connect(self.database)) as db:
                try:
                    return self.service(db=db, console=console).register()["status"]
                except Rejected as error:
                    return str(error)

        with ThreadPoolExecutor(max_workers=2) as pool:
            return list(pool.map(attempt, keys))

    def test_concurrent_registration_accepts_at_most_one_different_key(self):
        self.prepared()
        self.assertCountEqual(self.race_registrations((19, 21)), ("registered", "enrollment_key_changed"))

    def test_concurrent_equal_registration_only_creates_one_receipt(self):
        self.prepared()
        self.assertCountEqual(self.race_registrations((19, 19)), ("registered", "already_registered"))

    def test_window_budget_keeps_recovery_time(self):
        for patch in ({"enrollment_until": 1301}, {"task_until": 1299}, {"grant_until": 3399}):
            with self.subTest(patch=patch), self.assertRaisesRegex(Rejected, "window_invalid"):
                replace(self.INTENT, **patch)

    def test_existing_transaction_is_not_rolled_back(self):
        service = self.prepared()
        self.db.execute("BEGIN")
        try:
            with self.assertRaisesRegex(Rejected, "transaction_in_use"):
                service.register()
            self.assertTrue(self.db.in_transaction)
        finally:
            self.db.rollback()


if __name__ == "__main__":
    unittest.main()
