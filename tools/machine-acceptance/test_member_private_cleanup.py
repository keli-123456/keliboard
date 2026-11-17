"""Private teardown contracts and real Linux file/lock tests, without networking."""
import copy
import hashlib
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

from member_auxiliary import digest
from member_cli import RUN
from member_private_cleanup import MemberPrivateCleanup, ROOT, validate_pin
from member_transport import StrictControllerTransport, TransportError


class PrivateTransportTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(); self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.key = self.root / 'key'; self.key.write_bytes(b'synthetic-key')
        self.known = self.root / 'known'; self.known.write_bytes(b'synthetic-host')
        self.saved = {'bootstrap-binding': dict(run_id=RUN, host='192.0.2.20',
            identity_sha256=hashlib.sha256(self.key.read_bytes()).hexdigest(),
            known_hosts_sha256=hashlib.sha256(self.known.read_bytes()).hexdigest())}
        self.calls = []
        self.pin = dict(run_id=RUN, path=ROOT + '/private/credentials', sha256='a' * 64)
        self.rpc = StrictControllerTransport(run_id=RUN, host='192.0.2.20', identity_file=self.key,
            known_hosts=self.known, guard=lambda *_: None, load=self.saved.get,
            save=lambda k, v: self.saved.__setitem__(k, copy.deepcopy(v)), execute=self.execute)

    def execute(self, command, payload, timeout):
        self.calls.append((command, json.loads(payload), timeout))
        return json.dumps(dict(ok=True, result=dict(pin_sha256=digest(self.pin), status='absent'))).encode()

    def test_fixed_command_and_stored_ssh_identity(self):
        self.assertEqual('absent', self.rpc.remove_private(self.pin)['status'])
        self.assertEqual('absent', self.rpc.inspect_private(self.pin)['status'])
        for command, request, timeout in self.calls:
            self.assertEqual('python3 -B /opt/keli-isolated/runtime/member_private_cleanup.py', command[-1])
            self.assertIn('StrictHostKeyChecking=yes', command)
            self.assertEqual(self.pin, request['pin']); self.assertEqual(45, timeout)
        self.known.write_bytes(b'changed')
        with self.assertRaisesRegex(TransportError, 'ssh_material_changed'): self.rpc.remove_private(self.pin)
        self.assertEqual(2, len(self.calls))

    def test_bad_pins_and_unbound_transport_never_dispatch(self):
        for pin in (dict(self.pin, path='/etc/passwd'), dict(self.pin, sha256='x'),
                    dict(self.pin, extra='no'), dict(self.pin, path=ROOT + '/private/../app/.env')):
            with self.assertRaises(TransportError): validate_pin(pin)
        self.saved.clear()
        with self.assertRaises(TransportError): self.rpc.remove_private(self.pin)
        self.assertEqual([], self.calls)

    def test_ack_loss_keeps_first_error_and_inspection_can_recover(self):
        self.rpc.execute = lambda *_: (_ for _ in ()).throw(TimeoutError('synthetic-secret'))
        with self.assertRaises(TransportError): self.rpc.remove_private(self.pin)
        first = copy.deepcopy(self.saved['transport-first-error'])
        self.rpc.execute = lambda *_: b'{"ok":true,"result":{"pin_sha256":"changed","status":"absent"}}'
        with self.assertRaises(TransportError): self.rpc.inspect_private(self.pin)
        self.assertEqual(first, self.saved['transport-first-error'])
        self.assertNotIn('synthetic-secret', json.dumps(self.saved))
        self.rpc.execute = self.execute
        self.assertEqual('absent', self.rpc.inspect_private(self.pin)['status'])


@unittest.skipUnless(os.name == 'posix' and getattr(os, 'geteuid', lambda: -1)() == 0,
                     'real Linux root and flock required')
class PrivateLinuxTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(); self.addCleanup(self.temp.cleanup)
        self.base = Path(self.temp.name); self.base.chmod(0o700)
        self.root = self.base / 'controller'; self.root.mkdir(mode=0o711)
        self.write('application.json', json.dumps(dict(run_id=RUN,
            machine_id_sha256=hashlib.sha256(Path('/etc/machine-id').read_bytes()).hexdigest())).encode(), 0o644)
        self.write('private/import-stopped', (RUN + '\n').encode())
        self.write('private/member-import/lock', b'')
        self.setup = b'{"fixture":"setup"}'
        self.write('member-setup-grant.json', self.setup, 0o644)
        self.write('member-setup-stop.json', json.dumps(dict(stopped=True,
            grant_sha256=hashlib.sha256(self.setup).hexdigest())).encode(), 0o644)
        self.native = json.dumps(dict(run_id=RUN, grant_id='c' * 32)).encode()
        self.write('private/member-runtime-scope.json', self.native)
        self.write('private/member-journal/round-stop.json', json.dumps(dict(stopped=True, grant_id='c' * 32)).encode())
        self.write('private/member-journal/rpc.lock', b'')
        self.secret = b'synthetic-private-value'
        self.write('private/credentials', self.secret)
        self.pin = dict(run_id=RUN, path=ROOT + '/private/credentials',
                        sha256=hashlib.sha256(self.secret).hexdigest())
        self.cleaner = MemberPrivateCleanup(self.root)

    def write(self, name, raw, mode=0o600):
        path = self.root / name
        path.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
        path.write_bytes(raw); path.chmod(mode)
        return path

    def test_remove_readback_restart_and_native_grant_then_environment(self):
        self.assertEqual('owned', self.cleaner.operation('inspect', self.pin)['status'])
        self.assertEqual('absent', self.cleaner.operation('remove', self.pin)['status'])
        recreated = MemberPrivateCleanup(self.root)
        self.assertEqual('absent', recreated.operation('inspect', self.pin)['status'])
        self.assertEqual('absent', recreated.operation('remove', self.pin)['status'])
        native_pin = dict(self.pin, path=ROOT + '/private/member-runtime-scope.json', sha256=hashlib.sha256(self.native).hexdigest())
        recreated.operation('remove', native_pin)
        self.write('app/.env', self.secret, 0o640)
        env_pin = dict(self.pin, path=ROOT + '/app/.env')
        self.assertEqual('absent', recreated.operation('remove', env_pin)['status'])
        self.assertEqual('absent', recreated.operation('inspect', native_pin)['status'])
        for path in (self.root / 'private-cleanup').iterdir():
            self.assertEqual(0o600, path.stat().st_mode & 0o777)
            self.assertNotIn(self.secret, path.read_bytes())

    def test_absence_without_intent_is_not_success(self):
        (self.root / 'private/credentials').unlink()
        with self.assertRaisesRegex(TransportError, 'absence_unproven'): self.cleaner.operation('inspect', self.pin)

    def test_stop_must_match_and_all_operations_must_be_drained(self):
        import fcntl
        for name in ('private/member-import/lock', 'member-setup-grant.json', 'private/member-journal/rpc.lock'):
            with (self.root / name).open('rb') as lock:
                fcntl.flock(lock.fileno(), fcntl.LOCK_SH | fcntl.LOCK_NB)
                with self.assertRaisesRegex(TransportError, 'operations_pending'): self.cleaner.operation('remove', self.pin)
            self.assertTrue((self.root / 'private/credentials').exists())
        self.write('member-setup-stop.json', b'{"stopped":true,"grant_sha256":"wrong"}', 0o644)
        with self.assertRaisesRegex(TransportError, 'not_stopped'): self.cleaner.operation('remove', self.pin)

    def test_changed_stop_after_seal_prevents_removal(self):
        self.cleaner.operation('inspect', self.pin)
        self.write('private/member-journal/round-stop.json', b'{"stopped":true,"grant_id":"changed"}')
        with self.assertRaisesRegex(TransportError, 'stop_changed'): self.cleaner.operation('remove', self.pin)
        self.assertTrue((self.root / 'private/credentials').exists())

    def test_links_permission_drift_and_hash_mismatch_never_remove(self):
        target = self.root / 'private/credentials'
        for mode in (0o640, 0o666):
            target.chmod(mode)
            with self.assertRaises(TransportError): self.cleaner.operation('remove', self.pin)
            self.assertTrue(target.exists())
        target.chmod(0o600); target.write_bytes(b'changed')
        with self.assertRaisesRegex(TransportError, 'hash_changed'): self.cleaner.operation('remove', self.pin)
        target.unlink(); outside = self.write('outside', self.secret)
        target.symlink_to(outside)
        with self.assertRaises(TransportError): self.cleaner.operation('remove', self.pin)
        target.unlink(); os.link(outside, target)
        with self.assertRaises(TransportError): self.cleaner.operation('remove', self.pin)
        self.assertEqual(self.secret, outside.read_bytes())

    def test_intent_publish_failure_does_not_delete_and_unlink_crash_resumes(self):
        original = self.cleaner.publish
        def publish(path, value):
            if path.name == digest(self.pin) + '.json': raise OSError('fixture-disk-full')
            return original(path, value)
        with patch.object(self.cleaner, 'publish', publish), self.assertRaises(OSError):
            self.cleaner.operation('remove', self.pin)
        self.assertTrue((self.root / 'private/credentials').exists())
        original_unlink = Path.unlink
        def unlink(path, *args, **kwargs):
            original_unlink(path, *args, **kwargs)
            if path.name == 'credentials': raise RuntimeError('fixture-crash-after-unlink')
        with patch.object(Path, 'unlink', unlink), self.assertRaises(RuntimeError):
            self.cleaner.operation('remove', self.pin)
        self.assertEqual('absent', MemberPrivateCleanup(self.root).operation('inspect', self.pin)['status'])


if __name__ == '__main__':
    unittest.main()
