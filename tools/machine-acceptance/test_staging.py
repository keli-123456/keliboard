import contextlib
import hashlib
import io
import json
import os
from pathlib import Path
import stat
import tempfile
import time
import types
import unittest
from unittest.mock import Mock, patch

import staging


class StagingTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="keli-staging-test-")
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name).resolve()
        self.bundle = self.root / "bundle"
        self.state = self.root / "state"
        self.bundle.mkdir(mode=0o700)
        self.state.mkdir(mode=0o700)
        self.run_id = "keli-accept-synthetic-01234567"
        self.now = 2000000000
        self.files = {"run-scope.json": json.dumps({"schema": 1, "run_id": self.run_id, "role": "control",
                      "started_at": self.now - 60, "expires_at": self.now + 600}).encode(),
                      "control.py": b"# synthetic inert control artifact\n",
                      "certificate-hook.php": b"<?php /* synthetic fixture only */\n",
                      "application.tar.gz": b"synthetic-not-a-deployable-archive"}
        self.write(self.state / "owner.json", json.dumps({"schema": 1, "run_id": self.run_id, "role": "control"}).encode())
        for name, content in self.files.items():
            self.write(self.bundle / name, content)
        self.publish()

    def write(self, path, raw):
        path.write_bytes(raw)
        path.chmod(0o600)

    def publish(self):
        self.manifest = {"schema": 1, "run_id": self.run_id, "role": "control", "files": {
            name: {"bytes": len(raw), "sha256": hashlib.sha256(raw).hexdigest()} for name, raw in self.files.items()}}
        self.repin()

    def repin(self):
        raw = json.dumps(self.manifest, sort_keys=True).encode()
        self.write(self.bundle / staging.MANIFEST, raw)
        self.pin = hashlib.sha256(raw).hexdigest()

    def check(self, **overrides):
        arguments = {"bundle": self.bundle, "state": self.state, "run_id": self.run_id,
                     "manifest_sha256": self.pin, "required_files": list(self.files), "clock": lambda: self.now}
        return staging.check_staging(**(arguments | overrides))

    def reject(self, reason, **overrides):
        with self.assertRaisesRegex(staging.Rejected, reason):
            self.check(**overrides)

    def change_scope(self, **changes):
        scope = json.loads(self.files["run-scope.json"])
        scope.update(changes)
        self.files["run-scope.json"] = json.dumps(scope).encode()
        self.write(self.bundle / "run-scope.json", self.files["run-scope.json"])
        self.publish()

    def test_complete_pinned_bundle_passes_without_authorizing_execution(self):
        result = self.check()
        self.assertTrue(result["ok"])
        self.assertTrue(result["read_only"])
        self.assertFalse(result["authorizes_execution"])
        self.assertEqual(result["file_count"], 4)
        self.assertEqual(result["total_bytes"], sum(map(len, self.files.values())))

    def test_does_not_write_or_return_any_file_content(self):
        before = {path: (path.read_bytes(), path.stat().st_mtime_ns) for path in self.root.rglob("*") if path.is_file()}
        result = self.check()
        self.assertEqual(before, {path: (path.read_bytes(), path.stat().st_mtime_ns) for path in self.root.rglob("*") if path.is_file()})
        self.assertNotIn("synthetic", json.dumps(result).replace(self.run_id, ""))

    def test_missing_last_uploaded_scope_blocks_before_next_stage(self):
        (self.bundle / "run-scope.json").unlink()
        certificate, envelope, provider = Mock(), Mock(), Mock()
        with self.assertRaises(staging.Rejected):
            self.check()
            envelope()
            certificate()
            provider()
        for operation in (certificate, envelope, provider):
            operation.assert_not_called()

    def test_missing_manifest_is_not_a_ready_bundle(self):
        (self.bundle / staging.MANIFEST).unlink()
        self.reject("staging_unavailable")

    def test_each_required_file_must_arrive_before_preflight(self):
        for name, raw in self.files.items():
            with self.subTest(name=name):
                (self.bundle / name).unlink()
                self.reject("staging_unavailable")
                self.write(self.bundle / name, raw)

    def test_truncated_or_same_length_tampered_upload_fails(self):
        name = "control.py"
        for raw in (self.files[name][:-1], b"x" * len(self.files[name]), self.files[name] + b"x"):
            with self.subTest(size=len(raw)):
                self.write(self.bundle / name, raw)
                self.reject("staging_(digest_changed|size_exceeded)")

    def test_unpinned_or_foreign_manifest_cannot_supply_its_own_trust(self):
        self.reject("staging_manifest_changed", manifest_sha256="b" * 64)
        self.manifest["run_id"] = "keli-accept-another-01234567"
        self.repin()
        self.reject("staging_manifest_invalid")

    def test_manifest_cannot_omit_required_hook_or_add_unreviewed_file(self):
        del self.manifest["files"]["certificate-hook.php"]
        self.repin()
        self.reject("staging_files_incomplete")
        self.publish()
        self.manifest["files"]["unreviewed.py"] = {"bytes": 0, "sha256": "0" * 64}
        self.repin()
        self.reject("staging_files_incomplete")

    def test_path_traversal_and_duplicate_requirements_are_refused(self):
        for name in ("../outside", "/etc/passwd", "C:secret", "a/b", "a\\b", "..", "owner.json", staging.MANIFEST):
            with self.subTest(name=name):
                self.reject("staging_required_files_invalid", required_files=["run-scope.json", name])
        self.reject("staging_required_files_invalid", required_files=["run-scope.json", "run-scope.json"])
        self.reject("staging_required_files_invalid", required_files=["control.py"])

    def test_foreign_owner_role_or_schema_blocks(self):
        for change in ({"run_id": "foreign-run"}, {"role": "source"}, {"schema": True}):
            with self.subTest(change=change):
                owner = {"schema": 1, "run_id": self.run_id, "role": "control"} | change
                self.write(self.state / "owner.json", json.dumps(owner).encode())
                self.reject("staging_owner_mismatch")

    def test_scope_identity_and_window_require_exact_types(self):
        original = self.files["run-scope.json"]
        for change, reason in (({"run_id": "foreign"}, "scope_mismatch"), ({"role": "source"}, "scope_mismatch"),
                               ({"schema": True}, "scope_mismatch"), ({"started_at": True}, "window_invalid"),
                               ({"expires_at": str(self.now + 600)}, "window_invalid"),
                               ({"expires_at": self.now + 7201}, "window_invalid"),
                               ({"started_at": self.now + 1}, "window_expired"),
                               ({"expires_at": self.now + 299}, "window_expired")):
            with self.subTest(change=change):
                self.files["run-scope.json"] = original
                self.change_scope(**change)
                self.reject(reason)

    def test_scope_digest_is_checked_not_only_run_id(self):
        raw = json.loads(self.files["run-scope.json"])
        raw["unreviewed"] = "changed"
        self.write(self.bundle / "run-scope.json", json.dumps(raw).encode())
        self.reject("staging_digest_changed")

    def test_stopped_or_closed_run_cannot_pass(self):
        for name in staging.STOP_MARKERS:
            with self.subTest(marker=name):
                self.write(self.state / name, b"{}")
                self.reject("staging_run_stopped_or_closed")
                (self.state / name).unlink()

    def test_stop_during_hashing_prevents_later_stages(self):
        original = staging.read_file
        def stop_after_file(path, *args, **kwargs):
            result = original(path, *args, **kwargs)
            if path.name == "control.py":
                self.write(self.state / "stop-request.json", b"{}")
            return result
        with patch.object(staging, "read_file", side_effect=stop_after_file):
            self.reject("staging_run_stopped_or_closed")

    def test_insufficient_remaining_time_and_clock_rollback_after_hashing_block(self):
        for clock, reason in ((Mock(side_effect=[self.now, self.now - 1]), "staging_clock_changed"),
                              (Mock(side_effect=[self.now, self.now + 400]), "staging_window_expired")):
            with self.subTest(reason=reason):
                self.reject(reason, clock=clock)

    def test_changed_file_after_hash_is_detected(self):
        original = staging.read_file
        def change_previous(path, *args, **kwargs):
            result = original(path, *args, **kwargs)
            if path.name == "control.py":
                self.write(self.bundle / "certificate-hook.php", b"changed after hash")
            return result
        with patch.object(staging, "read_file", side_effect=change_previous):
            self.reject("staging_file_changed")

    def test_size_budgets_and_boolean_sizes_block_before_artifact_read(self):
        for size in (True, -1, staging.MAX_FILE_BYTES + 1):
            with self.subTest(size=size):
                self.manifest["files"]["control.py"]["bytes"] = size
                self.repin()
                self.reject("staging_manifest_invalid")
        self.publish()
        with patch.object(staging, "MAX_TOTAL_BYTES", 1):
            self.reject("staging_size_exceeded")

    def test_duplicate_json_fields_and_non_finite_values_are_rejected(self):
        for raw in (b'{"schema":1,"schema":1}', b'{"schema":NaN}', b'{"schema":Infinity}', b'[]', b'{'):
            with self.subTest(raw=raw):
                self.write(self.bundle / staging.MANIFEST, raw)
                self.pin = hashlib.sha256(raw).hexdigest()
                self.reject("staging_json_invalid")

    def test_symlink_cannot_replace_an_artifact_or_stop_marker(self):
        outside = self.root / "outside"
        self.write(outside, self.files["control.py"])
        target = self.bundle / "control.py"
        target.unlink()
        try:
            target.symlink_to(outside)
        except (OSError, NotImplementedError):
            self.skipTest("symlink creation unavailable")
        self.reject("staging_file_invalid")
        target.unlink()
        self.write(target, self.files["control.py"])
        (self.state / "closed.json").symlink_to(self.root / "missing")
        self.reject("staging_run_stopped_or_closed")

    def test_hard_link_cannot_replace_a_reviewed_artifact(self):
        target = self.bundle / "control.py"
        outside = self.root / "hardlink"
        os.link(target, outside)
        self.reject("staging_file_invalid")

    def test_posix_owner_and_mode_policy_without_transport(self):
        with patch.object(staging, "os", types.SimpleNamespace(name="posix", geteuid=lambda: 1234)):
            staging.permissions(types.SimpleNamespace(st_uid=1234, st_mode=stat.S_IFREG | 0o600), private=True)
            staging.permissions(types.SimpleNamespace(st_uid=1234, st_mode=stat.S_IFREG | 0o644))
            for uid, mode, private, reason in ((999, 0o600, True, "staging_owner_mismatch"),
                                               (1234, 0o644, True, "staging_permissions_unsafe"),
                                               (1234, 0o622, False, "staging_permissions_unsafe")):
                with self.subTest(uid=uid, mode=mode), self.assertRaisesRegex(staging.Rejected, reason):
                    staging.permissions(types.SimpleNamespace(st_uid=uid, st_mode=stat.S_IFREG | mode), private)

    @unittest.skipUnless(os.name == "posix", "POSIX ownership and mode checks")
    def test_private_directory_and_json_permissions_are_required(self):
        for path, mode in ((self.state, 0o755), (self.bundle, 0o770), (self.state / "owner.json", 0o644),
                           (self.bundle / staging.MANIFEST, 0o644), (self.bundle / "control.py", 0o622)):
            with self.subTest(path=path.name):
                previous = stat.S_IMODE(path.stat().st_mode)
                path.chmod(mode)
                self.reject("staging_permissions_unsafe")
                path.chmod(previous)

    def test_cli_failure_is_redacted_and_nonzero(self):
        output = io.StringIO()
        with contextlib.redirect_stdout(output):
            code = staging.main(["--bundle", str(self.bundle), "--state", str(self.state), "--run-id", self.run_id,
                                 "--manifest-sha256", "b" * 64, "--require", "run-scope.json"])
        self.assertEqual(code, 1)
        result = json.loads(output.getvalue())
        self.assertEqual(result["reason"], "staging_manifest_changed")
        self.assertFalse(result["authorizes_execution"])
        self.assertNotIn(str(self.root), output.getvalue())

    def test_cli_success_emits_only_non_authorizing_aggregate(self):
        now = int(time.time())
        self.change_scope(started_at=now - 60, expires_at=now + 600)
        arguments = ["--bundle", str(self.bundle), "--state", str(self.state), "--run-id", self.run_id,
                     "--manifest-sha256", self.pin]
        for name in self.files:
            arguments.extend(["--require", name])
        output = io.StringIO()
        with contextlib.redirect_stdout(output):
            self.assertEqual(0, staging.main(arguments))
        result = json.loads(output.getvalue())
        self.assertTrue(result["ok"])
        self.assertFalse(result["authorizes_execution"])
        self.assertNotIn(str(self.root), output.getvalue())


if __name__ == "__main__":
    unittest.main()
