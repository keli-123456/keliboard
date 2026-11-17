"""Concrete host ports with simulated SSH bytes; no cloud, DNS or sockets."""
import base64
import copy
import hashlib
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

from member_auxiliary import digest
from member_cli import RUN
from member_remote_setup import MemberRemoteSetup
from member_transport import StrictControllerTransport, TransportError


class RemoteSetupTests(unittest.TestCase):
    def setUp(self):
        temp = tempfile.TemporaryDirectory(); self.addCleanup(temp.cleanup)
        self.root = Path(temp.name).resolve(); self.root.chmod(0o700)
        for name in ('key', 'known'): (self.root / name).write_bytes(b'fixture only')
        self.saved, self.calls = {}, []
        self.scope = dict(run_id=RUN, bundle_sha256='a' * 64, started_at=1000, deadline_at=8200)
        self.identity = dict(grant_id='b' * 32, grant_sha256='c' * 64, deadline_at=8200)
        self.raw = json.dumps(dict(schema=2, execution_authorized=True, isolated=True, run_id=RUN,
            grant_id='d' * 32, bundle_sha256='a' * 64, started_at=1000, deadline_at=8200), indent=2).encode()
        self.native = dict(ready=True, identity=dict(run_id=RUN, grant_id='d' * 32,
            grant_sha256=hashlib.sha256(self.raw).hexdigest(), bundle_sha256='a' * 64), deadline_at=8200,
            cloud_or_dns_writes=False)
        self.resources = dict(resolved=True, roles={'source-a': dict(task_id='11111111-1111-1111-1111-111111111111',
            external_id='901')}, records=[], private_files=[])
        self.waiting = False
        self.rpc = StrictControllerTransport(run_id=RUN, host='192.0.2.20', identity_file=self.root / 'key',
            known_hosts=self.root / 'known', load=self.saved.get, save=self.save, guard=lambda *_: None, execute=self.execute)
        self.binding = MemberRemoteSetup(scope=self.scope, transport=self.rpc, grant_file=self.root / 'grant.json',
            load=self.saved.get, save=self.save)
        self.saved['remote-setup-identity'] = self.identity
        self.saved['bootstrap-setup-issue'] = dict(completed=True)
        network = patch('socket.socket', side_effect=AssertionError('offline'))
        network.start(); self.addCleanup(network.stop)

    def save(self, key, value): self.saved[key] = copy.deepcopy(value)

    def execute(self, command, raw, timeout):
        request = json.loads(raw); operation = request['operation']; self.calls.append(operation)
        if command[-1].endswith('member_import_entry.php'):
            if operation == 'stop': return json.dumps(dict(ok=True, operation=operation, stopped=True)).encode()
            if operation == 'configure': value, field = dict(initialization_scope=dict(run_id=RUN),
                health_or_forwarding_claimed=False, cloud_or_dns_writes=False), 'configuration'
            elif operation in ('setup-read', 'setup-issue'): value, field = self.identity, 'setup'
            else: raise AssertionError(operation)
            return json.dumps(dict(ok=True, operation=operation, **{field: value})).encode()
        self.assertEqual(command[-1], 'php /opt/keli-isolated/runtime/member_setup_entry.php')
        if operation == 'resources': value = self.resources
        elif operation == 'native-issue' and self.waiting: value = dict(ready=False, phase='setup_cooldown', next_check_at=2000)
        elif operation.startswith('native-'):
            value = copy.deepcopy(self.native)
            if operation == 'native-export': value['grant_base64'] = base64.b64encode(self.raw).decode()
        elif operation == 'advance': value = dict(ready=not self.waiting, waiting=self.waiting)
        elif operation in ('dns-control', 'dns-edges'): value = dict(ready=True, records={})
        else: raise AssertionError(operation)
        if operation in ('resources', 'native-read', 'native-export'):
            value = dict(value, uncertain_request=None, uncertain_cleanup=None)
        return json.dumps(dict(request, ok=True, result=value)).encode()

    def dispatch(self, owner, stage):
        receipts = {'fixture_predecessors': True}
        request = dict(run_id=RUN, bundle_sha256='a' * 64, stage=stage,
            predecessors_sha256=digest(receipts), request_id=digest([self.scope, stage])[:32])
        return self.binding.port(owner).dispatch(stage, request, receipts)

    def test_sources_wait_and_edges_use_real_rpc_operations_without_synthetic_completion(self):
        self.waiting = True
        self.assertTrue(self.dispatch('sources', 'sources-initialize')['waiting'])
        self.waiting = False
        self.assertTrue(self.dispatch('sources', 'sources-initialize')['completed'])
        self.assertTrue(self.dispatch('dns', 'dns-initialize')['completed'])
        self.assertEqual(self.calls, ['advance', 'advance', 'dns-edges'])

    def test_control_dns_requires_import_then_binds_setup_to_original_control_pin(self):
        self.saved.clear()
        with self.assertRaisesRegex(TransportError, 'import_required'): self.dispatch('dns', 'control-dns')
        self.assertEqual(self.calls, [])
        self.saved['bootstrap-import'] = dict(completed=True)
        task = '11111111-1111-1111-1111-111111111111'; suffix = task.replace('-', '')
        self.saved['setup-control-pin'] = dict(run_id=RUN, external_id='900', task_id=task,
            label='keli-' + suffix, marker='keli-create-' + suffix, region='sg-sin-2', size='g6-standard-2',
            image='linode/debian12', provider_created_at='2026-11-17T10:00:00Z')
        self.assertTrue(self.dispatch('dns', 'control-dns')['completed'])
        self.assertEqual(self.calls, ['configure', 'setup-issue', 'dns-control'])
        self.assertEqual(self.saved['remote-setup-identity'], self.identity)

    def test_native_wait_then_exact_remote_bytes_and_readiness_not_reserialized(self):
        self.waiting = True
        self.assertTrue(self.dispatch('authority', 'native-grant')['waiting'])
        self.assertFalse(self.binding.grant_file.exists())
        self.waiting = False
        receipt = self.dispatch('authority', 'native-grant')['receipt']
        self.assertEqual(receipt['grant_sha256'], hashlib.sha256(self.raw).hexdigest())
        self.assertEqual(self.binding.grant_file.read_bytes(), self.raw)
        self.assertTrue(self.dispatch('authority', 'native-ready')['completed'])
        self.assertNotIn('grant_base64', json.dumps(self.saved))

    def test_native_export_mismatch_is_not_saved_or_rewritten(self):
        self.raw += b' '
        with self.assertRaisesRegex(TransportError, 'export_changed'): self.dispatch('authority', 'native-grant')
        self.assertFalse(self.binding.grant_file.exists())
        self.assertNotIn('remote-native-grant', self.saved)

    def test_partial_existing_grant_file_is_not_overwritten(self):
        self.binding.grant_file.write_bytes(b'partial'); self.binding.grant_file.chmod(0o600)
        with self.assertRaisesRegex(TransportError, 'grant_changed'): self.dispatch('authority', 'native-grant')
        self.assertEqual(self.binding.grant_file.read_bytes(), b'partial')

    def test_stop_precedes_readonly_resources_and_preserves_partial_source(self):
        request = dict(scope=self.scope)
        with self.assertRaisesRegex(TransportError, 'stop_required'): self.binding.port('sources').reconcile(request)
        for owner in ('authority', 'sources', 'dns'):
            self.assertTrue(self.binding.port(owner).reconcile(request)['resolved'])
        self.assertEqual(self.calls[0], 'stop')
        self.assertEqual(self.binding.port('sources').snapshot()['roles'], self.resources['roles'])
        self.assertEqual(self.binding.port('dns').snapshot()['records'], [])
        with self.assertRaisesRegex(TransportError, 'stopped'): self.dispatch('sources', 'sources-initialize')
        self.assertNotIn('advance', self.calls)

    def test_unknown_resource_receipt_never_becomes_empty_or_deletion_permission(self):
        self.resources['resolved'] = False
        with self.assertRaisesRegex(TransportError, 'unresolved'):
            self.binding.port('authority').reconcile(dict(scope=self.scope))
        self.assertNotIn('remote-setup-resources', self.saved)
        self.assertIsNotNone(self.saved.get('remote-setup-stopped'))

    def test_changed_resource_receipt_on_resume_blocks_cleanup(self):
        request = dict(scope=self.scope)
        self.binding.port('authority').reconcile(request)
        self.resources['roles']['source-a']['external_id'] = '999'
        with self.assertRaisesRegex(TransportError, 'resources_changed'): self.binding.port('dns').reconcile(request)
        self.assertEqual(self.saved['remote-setup-resources']['roles']['source-a']['external_id'], '901')

    def test_never_issued_setup_has_empty_receipts_only_after_confirmed_stop(self):
        self.saved.clear()
        self.binding.port('authority').reconcile(dict(scope=self.scope))
        self.assertEqual(self.binding.port('sources').snapshot()['roles'], {})
        self.assertEqual(self.calls, ['stop'])

    def test_wrong_owner_or_scope_never_calls_ssh(self):
        with self.assertRaisesRegex(TransportError, 'request'): self.dispatch('sources', 'dns-initialize')
        with self.assertRaisesRegex(TransportError, 'scope'): self.binding.port('authority').reconcile(dict(scope={}))
        self.assertEqual(self.calls, [])


if __name__ == '__main__': unittest.main()
