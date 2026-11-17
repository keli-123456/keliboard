"""Exercise the actual host binding at SSH's byte boundary; no network or cloud."""
import copy
import hashlib
import json
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from member_cli import BoundControllerTransport, MemberControllerPort, RUN
from member_round import MemberRound
from member_transport import TransportError, REMOTE_ENTRY
from member_auxiliary import digest
from test_member_round import RoundFixture


class MemberCliTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(); self.addCleanup(self.tmp.cleanup)
        self.directory = Path(self.tmp.name)
        self.f = RoundFixture()
        self.f.scope = json.loads(json.dumps(self.f.scope).replace(self.f.scope['run_id'], RUN))
        self.f.pins = {digest(p): True for p in [self.f.scope['control'], *self.f.scope['records'], *self.f.scope['private_files']]}
        self.key, self.known, self.grant = [self.directory / n for n in ('key', 'known_hosts', 'grant.json')]
        self.key.write_bytes(b'synthetic-private-key'); self.known.write_bytes(b'synthetic-verified-host-key')
        self.g = dict(schema=2, execution_authorized=True, isolated=True, run_id=RUN,
                      grant_id='a' * 32, bundle_sha256='b' * 64, controller_machine_id_sha256='c' * 64,
                      started_at=999, deadline_at=8199, controller_ipv4='192.0.2.8',
                      identity_sha256=hashlib.sha256(self.key.read_bytes()).hexdigest(),
                      known_hosts_sha256=hashlib.sha256(self.known.read_bytes()).hexdigest())
        self.grant.write_text(json.dumps(self.g), encoding='utf-8')
        for p in (self.key, self.known, self.grant): p.chmod(0o600)
        self.requests, self.commands = [], []
        self.fail_operation, self.changed, self.unknown = None, None, False
        self.stopped = False
        self.trans = self.make_transport()
        self.port = MemberControllerPort(self.trans)
        for name in ('socket.socket', 'subprocess.Popen'):
            p = patch(name, side_effect=AssertionError('no real transports')); p.start(); self.addCleanup(p.stop)

    def make_transport(self):
        return BoundControllerTransport(grant_file=self.grant, grant_sha256=hashlib.sha256(self.grant.read_bytes()).hexdigest(),
            clock=lambda: self.f.now, run_id=RUN, host='192.0.2.8', identity_file=self.key, known_hosts=self.known,
            guard=lambda *_: None, load=self.f.journal.get, save=self.f.save, execute=self.execute)

    def execute(self, command, payload, timeout):
        self.commands.append(command)
        r = json.loads(payload); self.requests.append(r)
        self.assertEqual(r['schema'], 2); self.assertEqual(timeout, 45)
        self.assertEqual(command[-1], 'php ' + REMOTE_ENTRY)
        self.assertIn('StrictHostKeyChecking=yes', command); self.assertIn('UpdateHostKeys=no', command)
        self.assertIn('BatchMode=yes', command); self.assertIn('IdentitiesOnly=yes', command)
        op = r['operation']
        if op == 'round-ready': result = {'ready': True}
        elif op == 'round-stop': self.stopped = True; result = {'stopped': True}
        elif op == 'round-status': result = dict(run_id=RUN, resolved=not self.unknown, stopped=self.stopped, roles=self.f.roles)
        elif op == 'round-step':
            result = dict(phase='finished', branches=dict.fromkeys(('native_cleanup', 'cooldown', 'retry', 'b_unchanged', 'dns_cutover'), True))
        elif op in ('task-delete', 'task-inspect'): result = self.f.task(op, r['role'])
        else: result = {'ready': True}
        if op == self.fail_operation: raise TimeoutError('private/token must stay private')
        reply = dict(r, ok=True, result=result)
        if self.changed: reply[self.changed] = 'changed'
        return json.dumps(reply).encode()

    def driver(self, restored=None):
        return MemberRound(run_id=RUN, bundle_sha256=self.g['bundle_sha256'], port=self.port,
            cleanup_factory=self.port.cleanup_factory(self.f.aux(), lambda s: self.f.save('cleanup', s), lambda: self.f.now),
            load=self.f.journal.get, save=self.f.save, guard=lambda _: None, clock=lambda: self.f.now, restored=restored)

    def finish(self, driver):
        for _ in range(30):
            state = driver.step()
            if state['phase'] == 'closed': return state
            self.f.now += 10
        self.fail('round did not close')

    def test_host_round_bound_rpc_and_exact_cleanup_control_last(self):
        state = self.finish(self.driver())
        self.assertTrue(state['resources_cleaned']); self.assertTrue(state['acceptance_passed'])
        ops = [r['operation'] for r in self.requests]
        self.assertEqual(ops[:4], ['round-ready', 'round-step', 'round-stop', 'round-status'])
        self.assertEqual(len({r['sequence'] for r in self.requests}), len(self.requests))
        self.assertEqual(ops.count('round-step'), 1)
        self.assertFalse(any(self.f.pins.values())); self.assertEqual(self.f.present, set())
        self.assertEqual([e[0] for e in self.f.events].count('control-delete'), 1)

    def test_lost_native_receipt_stops_reconciles_and_never_resends_create(self):
        self.fail_operation = 'round-step'
        d = self.driver(); d.step(); d.step()
        self.fail_operation = None
        self.trans = self.make_transport(); self.port = MemberControllerPort(self.trans)
        state = self.finish(self.driver(d.state))
        self.assertFalse(state['acceptance_passed']); self.assertTrue(state['resources_cleaned'])
        self.assertEqual([r['operation'] for r in self.requests].count('round-step'), 1)
        self.assertNotIn('private/token', str(self.f.journal))

    def test_expiry_still_allows_stop_inspect_delete_but_no_native_step(self):
        d = self.driver(); d.step(); self.f.now = self.g['deadline_at'] + 1
        state = self.finish(d)
        self.assertTrue(state['resources_cleaned']); self.assertFalse(state['acceptance_passed'])
        self.assertNotIn('round-step', [r['operation'] for r in self.requests])

    def test_stop_blocks_future_work_and_delete_requires_stop(self):
        with self.assertRaisesRegex(TransportError, 'cleanup_requires_stop'): self.trans.call('task-delete', 'source-a')
        self.trans.call('round-stop')
        with self.assertRaisesRegex(TransportError, 'expired_or_stopped'): self.trans.call('round-step')
        self.trans.call('task-delete', 'source-a')
        with self.assertRaisesRegex(TransportError, 'reconcile_required'): self.trans.call('task-delete', 'source-a')

    def test_changed_grant_host_material_and_response_fail_closed(self):
        self.known.write_bytes(b'different host key')
        with self.assertRaisesRegex(TransportError, 'ssh_material_changed'): self.trans.call('round-ready')
        self.assertEqual([], self.requests)
        self.known.write_bytes(b'synthetic-verified-host-key')
        self.changed = 'grant_sha256'
        with self.assertRaisesRegex(TransportError, 'binding_changed'): self.trans.call('round-step')
        self.changed = None
        with self.assertRaisesRegex(TransportError, 'reconcile_required'): self.trans.call('round-step')
        self.trans.call('round-stop'); self.trans.call('round-status')

    def test_mutated_authority_file_and_arbitrary_operations_are_rejected(self):
        with self.assertRaisesRegex(TransportError, 'operation_invalid'): self.trans.call('create', 'candidate-a1')
        with self.assertRaisesRegex(TransportError, 'role_invalid'): self.trans.call('round-step', 'source-b')
        self.grant.write_text(json.dumps(dict(self.g, grant_id='e' * 32)))
        with self.assertRaisesRegex(TransportError, 'grant_changed'): self.trans.call('round-ready')
        self.assertEqual([], self.requests)

    def test_unknown_candidate_id_prevents_false_cleanup_success(self):
        self.unknown = True
        d = self.driver(); d.step(); d.step(); d.step()
        self.assertEqual('reconcile', d.state['phase'])
        self.assertFalse(d.state['resources_cleaned'])
        self.assertNotIn('task-delete', [r['operation'] for r in self.requests])

    def test_process_crash_pending_intent_blocks_new_request_even_without_error_receipt(self):
        self.f.save('bound-active', {'request_id': 'e' * 32, 'completed': False})
        transport = self.make_transport()
        with self.assertRaisesRegex(TransportError, 'reconcile_required'): transport.call('round-step')
        self.assertEqual([], self.requests)
        transport.call('round-stop'); transport.call('round-status')
        self.assertEqual([r['operation'] for r in self.requests], ['round-stop', 'round-status'])

    def test_error_prefix_cannot_smuggle_secret_into_round_journal(self):
        d = self.driver()
        def fail(*_): raise TransportError('transport_failure private/token')
        d.port = fail
        state = d.step()
        self.assertEqual(state['first_error'], 'round_operation_failed')
        self.assertNotIn('private/token', str(self.f.journal))

    def setup_issued_grant(self):
        bootstrap = dict(run_id=RUN, host=self.g.pop('controller_ipv4'),
            identity_sha256=self.g.pop('identity_sha256'), known_hosts_sha256=self.g.pop('known_hosts_sha256'))
        self.grant.write_text(json.dumps(self.g), encoding='utf-8')
        self.f.save('bootstrap-binding', bootstrap)
        self.f.save('setup-binding', dict(run_id=RUN, host=bootstrap['host'], grant_id='d' * 32,
            grant_sha256='e' * 64, deadline_at=self.g['deadline_at']))
        identity = dict(run_id=RUN, grant_id=self.g['grant_id'], bundle_sha256=self.g['bundle_sha256'],
            grant_sha256=hashlib.sha256(self.grant.read_bytes()).hexdigest())
        self.f.save('setup-native-grant', dict(ready=True, identity=identity,
            deadline_at=self.g['deadline_at'], cloud_or_dns_writes=False))

    def test_setup_issued_grant_binds_original_remote_hash_without_rewriting_it(self):
        self.setup_issued_grant(); before = self.grant.read_bytes()
        transport = self.make_transport()
        transport.call('round-ready')
        self.assertEqual(before, self.grant.read_bytes())
        self.assertEqual(hashlib.sha256(before).hexdigest(), self.requests[-1]['grant_sha256'])
        self.assertNotIn('identity_sha256', self.g)
        self.make_transport().call('round-status')

    def test_setup_issued_grant_requires_complete_immutable_bootstrap_and_native_receipts(self):
        self.setup_issued_grant(); valid = copy.deepcopy(self.f.journal)
        for key in ('bootstrap-binding', 'setup-binding', 'setup-native-grant'):
            self.f.journal = copy.deepcopy(valid); self.f.journal.pop(key)
            with self.assertRaises(TransportError): self.make_transport()
        for key, field, value in [('bootstrap-binding', 'host', '192.0.2.9'),
                                 ('setup-binding', 'deadline_at', 8200),
                                 ('setup-native-grant', 'identity', {})]:
            self.f.journal = copy.deepcopy(valid); self.f.journal[key][field] = value
            with self.assertRaises(TransportError): self.make_transport()
        self.f.journal = copy.deepcopy(valid); transport = self.make_transport()
        self.f.journal['setup-binding']['grant_id'] = 'f' * 32
        with self.assertRaisesRegex(TransportError, 'setup_binding_changed'): transport.call('round-ready')
        self.assertEqual([], self.requests)

    def test_setup_issued_grant_rejects_changed_ssh_files_before_dispatch(self):
        self.setup_issued_grant(); transport = self.make_transport()
        self.key.write_bytes(b'changed')
        with self.assertRaisesRegex(TransportError, 'ssh_material_changed'): transport.call('round-status')
        self.assertEqual([], self.requests)


if __name__ == '__main__': unittest.main()
