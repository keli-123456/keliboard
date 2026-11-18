"""Connected native/partial recovery, faking only SSH/provider byte replies."""
import copy
import hashlib
import json
import unittest

from member_auxiliary import digest
from member_cli import RUN
from member_connected_runtime import MemberConnectedRuntime
from member_initialization import STAGES
from member_transport import TransportError
import test_member_connected_setup as fixture


class ConnectedRuntimeTests(unittest.TestCase):
    def setUp(self):
        self.f = fixture.ConnectedSetupTests('runTest'); self.f.setUp(); self.addCleanup(self.f.doCleanups)
        self.setup, self.saved, self.scope = self.f.port, self.f.saved, self.f.scope
        self.setup.components()
        self.calls, self.fail_operation, self.change = [], None, lambda v: v
        self.setup.execute = self.execute
        self.runtime = MemberConnectedRuntime(self.setup, self.f.f.root.resolve())
        self.pin = dict(task_id='22222222-2222-4222-8222-222222222222', external_id='2')
        self.setup_identity = dict(grant_id='c' * 32, grant_sha256='d' * 64, deadline_at=self.scope['deadline_at'])
        self.saved['remote-setup-identity'] = self.setup_identity
        self.saved['setup-binding'] = dict(run_id=RUN, host=self.f.host, **self.setup_identity)
        self.saved['initialization'] = dict(binding=digest(self.scope), stages=list(STAGES), index=6, receipts={})
        self.cleanup = dict(run_id=RUN, roles={'source-a': self.pin}, auxiliary=dict(run_id=RUN,
            control=self.saved['setup-control-pin'], records=[], faults=[], private_files=[],
            requests=[self.saved['setup-control-request']['request_id']]))
        self.inspection = dict(self.pin, complete=False, classification='present')

    def stop(self):
        self.saved['connected-setup-stopped'] = dict(binding=digest(self.scope))
        self.saved['connected-setup-cleanup'] = self.cleanup
        self.saved['initialization-reconciled'] = dict(binding=digest(self.scope), cleanup_scope=self.cleanup)

    def native(self):
        grant = dict(schema=2, execution_authorized=True, isolated=True, **self.scope,
            grant_id='a' * 32, controller_machine_id_sha256='b' * 64)
        raw = json.dumps(grant).encode()
        self.setup.grant.write_bytes(raw); self.setup.grant.chmod(0o600)
        identity = dict(run_id=RUN, grant_id=grant['grant_id'], grant_sha256=hashlib.sha256(raw).hexdigest(),
            bundle_sha256=self.scope['bundle_sha256'])
        self.saved['bootstrap-binding'] = dict(run_id=RUN, host=self.f.host,
            identity_sha256=hashlib.sha256(self.setup.identity.read_bytes()).hexdigest(),
            known_hosts_sha256=hashlib.sha256(self.setup.known.read_bytes()).hexdigest())
        self.saved['setup-native-grant'] = dict(ready=True, identity=identity,
            deadline_at=self.scope['deadline_at'], cloud_or_dns_writes=False)
        receipt = dict(run_id=RUN, stage='native-grant', request_id='e' * 32,
            grant_sha256=identity['grant_sha256'], evidence_sha256='f' * 64)
        self.saved['initialization'].update(index=len(STAGES), receipts={'native-grant': receipt})
        return receipt

    def execute(self, command, payload, timeout):
        self.assertEqual('root@' + self.f.host, command[-2])
        self.assertIn('StrictHostKeyChecking=yes', command)
        request = json.loads(payload); operation = request['operation']; self.calls.append(operation)
        if operation == self.fail_operation: raise TimeoutError('synthetic-private-value')
        if operation.startswith('source-inspect-'): result = copy.deepcopy(self.inspection)
        elif operation.startswith('source-delete-'): result = dict(complete=False, classification='delete_dispatched')
        elif operation == 'round-evidence':
            identity = self.saved['setup-native-grant']['identity']
            body = dict(schema=1, **identity, scope_sha256='f' * 64, stopped=True, reconciled=True,
                reconcile_diagnostic=None, tasks={}, driver={}, setup={}, cleanup_complete_not_asserted=True)
            raw = json.dumps(body, sort_keys=True, separators=(',', ':'))
            result = dict(schema=1, content=raw, sha256=hashlib.sha256(raw.encode()).hexdigest())
        else: result = dict(stopped=True) if operation == 'round-stop' else dict(ready=True)
        return json.dumps(dict(request, ok=True, result=self.change(result))).encode()

    def test_native_uses_original_endpoint_and_grant_without_connection_or_new_authority(self):
        receipt = self.native()
        before = copy.deepcopy(self.saved)
        bound = self.runtime.native_transport(receipt)
        self.assertEqual(before, self.saved)
        self.assertEqual(self.f.host, bound.host)
        self.assertEqual(['control-connection'], self.f.provider_calls)
        self.assertEqual([], self.calls)
        bound.call('round-ready')
        self.assertEqual(['round-ready'], self.calls)

    def test_expired_binding_can_stop_but_cannot_dispatch_new_native_work(self):
        receipt = self.native(); self.f.f.now = self.scope['deadline_at'] + 1
        bound = self.runtime.native_transport(receipt)
        with self.assertRaisesRegex(TransportError, 'expired_or_stopped'): bound.call('round-step')
        bound.call('round-stop')
        self.assertEqual(['round-stop'], self.calls)
        self.assertEqual(['control-connection'], self.f.provider_calls)

    def test_foreign_or_incomplete_native_receipts_fail_without_io(self):
        receipt = self.native()
        with self.assertRaisesRegex(TransportError, 'native_receipt'):
            self.runtime.native_transport(dict(receipt, grant_sha256='0' * 64))
        self.saved['initialization']['index'] -= 1
        with self.assertRaisesRegex(TransportError, 'native_receipt'): self.runtime.native_transport(receipt)
        self.assertEqual([], self.calls)

    def test_partial_source_query_and_delete_are_distinct_and_exact(self):
        with self.assertRaisesRegex(TransportError, 'not_stopped'):
            self.runtime.task_call('task-inspect', 'source-a', self.pin)
        self.stop()
        self.assertEqual(dict(self.pin, absent=False), self.runtime.task_call('task-inspect', 'source-a', self.pin))
        self.assertNotIn('setup-cleanup', self.saved)
        self.runtime.task_call('task-delete', 'source-a', self.pin)
        self.assertEqual('source-delete-a', self.saved['setup-cleanup']['request']['operation'])
        self.inspection = dict(self.pin, complete=False, classification='exact_absence', retry_at=100)
        self.assertEqual(dict(self.pin, absent=True), self.runtime.task_call('task-inspect', 'source-a', self.pin))
        self.assertEqual(['source-inspect-a', 'source-delete-a', 'source-inspect-a'], self.calls)

    def test_lost_delete_response_preserves_once_intent_and_allows_only_readback(self):
        self.stop(); self.fail_operation = 'source-delete-a'
        with self.assertRaises(TransportError): self.runtime.task_call('task-delete', 'source-a', self.pin)
        self.fail_operation = None
        resumed = MemberConnectedRuntime(self.setup, self.runtime.directory)
        with self.assertRaisesRegex(TransportError, 'readback_required'):
            resumed.task_call('task-delete', 'source-a', self.pin)
        resumed.task_call('task-inspect', 'source-a', self.pin)
        self.assertEqual(1, self.calls.count('source-delete-a'))
        self.assertNotIn('synthetic-private-value', json.dumps(self.saved))

    def test_unknown_or_changed_source_readback_cannot_mean_absence(self):
        self.stop()
        for change in (dict(complete=True, classification='never_queued'), dict(self.pin, external_id='3'),
                       dict(self.pin, complete=False, classification='create_receipt_unknown')):
            self.inspection = change
            with self.subTest(change=change), self.assertRaisesRegex(TransportError, 'task_unconfirmed'):
                self.runtime.task_call('task-inspect', 'source-a', self.pin)
        with self.assertRaisesRegex(TransportError, 'task_pin'):
            self.runtime.task_call('task-delete', 'source-b', self.pin)
        with self.assertRaisesRegex(TransportError, 'task_pin'):
            self.runtime.task_call('task-delete', 'candidate-a1', self.pin)
        self.native()
        with self.assertRaisesRegex(TransportError, 'native_required'):
            self.runtime.task_call('task-delete', 'source-a', self.pin)
        self.assertFalse(any('delete' in op for op in self.calls))

    def test_native_evidence_is_saved_and_verified_without_ssh_after_first_export(self):
        receipt = self.native(); self.stop()
        self.runtime.native_transport(receipt).call('round-stop')
        first = self.runtime.export()
        self.fail_operation = 'round-evidence'
        self.assertEqual(first, MemberConnectedRuntime(self.setup, self.runtime.directory).export())
        self.assertEqual(1, self.calls.count('round-evidence'))
        path = self.runtime.directory / ('evidence-' + first['sha256'] + '.json')
        path.write_bytes(b'{}')
        with self.assertRaises(TransportError): self.runtime.export()
        self.assertEqual(1, self.calls.count('round-evidence'))

    def test_only_original_stopped_request_can_be_reconciled(self):
        self.stop(); request = self.saved['setup-control-request']
        calls = []
        def execute(command, payload):
            outer = json.loads(payload); calls.append(outer)
            reply = dict(request_sha256=digest(request), resolved=True, dispatched=True, ready=False,
                pin=self.saved['setup-control-pin'])
            return json.dumps(dict(ok=True, request_sha256=digest(outer), result=reply)).encode()
        self.setup.control.execute = execute
        self.assertEqual(dict(requests_sha256=digest([request['request_id']]), resolved=True),
            self.runtime.reconcile([request['request_id']]))
        self.assertEqual('control-readback', calls[0]['operation'])
        with self.assertRaisesRegex(TransportError, 'requests_changed'): self.runtime.reconcile(['unknown'])
        self.assertEqual(1, len(calls))

    def test_unbound_fault_recovery_fails_closed_without_ssh_or_fake_restoration(self):
        self.stop()
        pin = dict(run_id=RUN, role='source-a', external_id=self.pin['external_id'])
        self.cleanup['auxiliary']['faults'] = [pin]
        for method in (self.runtime.restore_fault, self.runtime.inspect_fault):
            with self.assertRaisesRegex(TransportError, 'operation_unavailable'): method(pin)
        self.assertEqual([], self.calls)


if __name__ == '__main__': unittest.main()
