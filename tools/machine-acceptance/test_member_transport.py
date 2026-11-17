"""Offline RPC and teardown faults. No SSH/cloud/DNS connection is permitted."""
import copy
import hashlib
import json
from pathlib import Path
import tempfile
import sys
import unittest
from unittest.mock import patch

from member_transport import MemberCleanup, StrictControllerTransport, TransportError


class MemberTransportTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(); self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.key = self.root / 'key'; self.key.write_text('synthetic fixture')
        self.known = self.root / 'known'; self.known.write_text('synthetic fixture')
        self.saved, self.calls = {}, []
        self.guard_calls = []
        self.rpc = StrictControllerTransport(run_id='keli-accept-offline-r35-fixture', host='192.0.2.20',
            identity_file=self.key, known_hosts=self.known, guard=lambda *v: self.guard_calls.append(v),
            load=lambda k: self.saved.get(k), save=lambda k, v: self.saved.__setitem__(k, copy.deepcopy(v)), execute=self.execute)
        self.deny_network = patch('socket.socket', side_effect=AssertionError('offline only'))
        self.deny_network.start(); self.addCleanup(self.deny_network.stop)

    def execute(self, command, payload, timeout):
        self.calls.append((command, payload, timeout))
        request = json.loads(payload)
        return json.dumps(dict(request, ok=True, result={'absent': True})).encode()

    def test_strict_host_verification_fixed_command_and_bound_request(self):
        self.assertTrue(self.rpc.call('task-inspect', 'source-a')['absent'])
        argv, payload, timeout = self.calls[0]
        for option in ('BatchMode=yes', 'IdentitiesOnly=yes', 'StrictHostKeyChecking=yes', 'UpdateHostKeys=no',
                       'PasswordAuthentication=no', 'KbdInteractiveAuthentication=no', 'ForwardAgent=no'):
            self.assertIn(option, argv)
        self.assertEqual(argv[-1], 'php /opt/keli-isolated/runtime/member_transport_entry.php')
        self.assertEqual(argv[-2], 'root@192.0.2.20'); self.assertEqual(timeout, 45)
        self.assertNotIn(str(self.key), payload.decode())

    def test_bad_role_and_commands_are_rejected_before_transport(self):
        for operation, role in [('create', 'source-a'), ('task-delete', 'control'), ('source-register', 'candidate-a1')]:
            with self.assertRaises(TransportError): self.rpc.call(operation, role)
        self.assertEqual(self.calls, [])

    def test_authority_denial_happens_before_ssh(self):
        self.rpc.guard = lambda *_: (_ for _ in ()).throw(TransportError('not_authorized'))
        with self.assertRaisesRegex(TransportError, 'not_authorized'): self.rpc.call('task-inspect', 'source-a')
        self.assertEqual(self.calls, [])

    def test_known_hosts_missing_never_relaxes_verification(self):
        self.known.unlink()
        with self.assertRaisesRegex(TransportError, 'hosts_missing'): self.rpc.call('task-inspect', 'source-a')
        self.assertEqual(self.calls, [])

    def test_lost_delete_response_has_one_intent_and_no_replay(self):
        def fail(*args):
            self.calls.append(args); raise TimeoutError('private credential data')
        self.rpc.execute = fail
        with self.assertRaises(TransportError): self.rpc.call('task-delete', 'source-a')
        with self.assertRaisesRegex(TransportError, 'reconcile_required'): self.rpc.call('task-delete', 'source-a')
        self.assertEqual(len(self.calls), 1)
        self.assertNotIn('private credential', json.dumps(self.saved))
        self.rpc.execute = self.execute
        self.assertTrue(self.rpc.call('task-inspect', 'source-a')['absent'])

    def test_mismatched_duplicate_oversized_and_noisy_responses_fail_closed(self):
        for response in (b'{"ok":true,"ok":true}', b'noise\n{}', b'x' * 262145,
                         b'{"schema":1,"ok":true,"request_id":"other"}'):
            self.rpc.execute = lambda *_, raw=response: raw
            with self.assertRaises(TransportError): self.rpc.call('task-inspect', 'source-a')
        first = copy.deepcopy(self.saved['transport-first-error'])
        self.rpc.execute = lambda *_: (_ for _ in ()).throw(RuntimeError('second'))
        with self.assertRaises(TransportError): self.rpc.call('task-inspect', 'source-b')
        self.assertEqual(first, self.saved['transport-first-error'])

    def test_intent_save_failure_prevents_remote_delete(self):
        self.rpc.save = lambda *_: (_ for _ in ()).throw(OSError('disk full'))
        with self.assertRaises(OSError): self.rpc.call('task-delete', 'source-a')
        self.assertEqual(self.calls, [])

    def test_upload_is_exclusive_hash_bound_and_never_executes_archive(self):
        file = self.root / 'archive.tar.gz'; file.write_bytes(b'synthetic frozen archive')
        digest = hashlib.sha256(file.read_bytes()).hexdigest()
        def upload(command, payload, timeout):
            self.assertEqual(payload, file.read_bytes()); self.assertEqual(timeout, 120)
            self.assertTrue(command[-1].startswith('python3 -c '))
            self.assertIn('/opt/keli-isolated/incoming', command[-1]); self.assertNotIn('tar -', command[-1])
            return json.dumps({'sha256': digest, 'bytes': len(payload), 'staged': True, 'executed': False}).encode()
        self.rpc.execute = upload
        self.assertFalse(self.rpc.upload(file, digest)['executed'])
        with self.assertRaisesRegex(TransportError, 'upload_reconcile_required'): self.rpc.upload(file, digest)

    def test_upload_hash_mismatch_does_not_connect(self):
        file = self.root / 'archive.tar.gz'; file.write_bytes(b'changed')
        with self.assertRaisesRegex(TransportError, 'bundle_changed'): self.rpc.upload(file, 'a' * 64)
        self.assertEqual(self.calls, []); self.assertEqual(self.saved, {})

    def test_local_process_timeout_exit_and_output_limit(self):
        for code, timeout, reason in [('import time;time.sleep(10)', .05, 'timeout'),
                                      ('raise SystemExit(2)', 2, 'remote_failed'),
                                      ('print("x"*262145)', 2, 'too_large')]:
            with self.assertRaisesRegex(TransportError, reason):
                StrictControllerTransport._execute([sys.executable, '-c', code], b'', timeout)

    def test_setup_fixed_cli_and_bound_identity_with_advancing_sequences(self):
        pin = dict(grant_id='a' * 32, grant_sha256='b' * 64, deadline_at=9000)
        for operation in ('advance', 'advance', 'status', 'stop'):
            self.rpc.setup_call(operation, pin)
        self.assertEqual([json.loads(c[1])['sequence'] for c in self.calls], [0, 1, 2, 2])
        self.assertTrue(self.saved['setup-mutation']['completed'])
        for command, payload, timeout in self.calls:
            self.assertEqual(command[-1], 'php /opt/keli-isolated/runtime/member_setup_entry.php')
            self.assertIn('StrictHostKeyChecking=yes', command)
            self.assertEqual(timeout, 45)
            self.assertEqual(json.loads(payload)['grant_sha256'], pin['grant_sha256'])
        with self.assertRaisesRegex(TransportError, 'binding_changed'):
            self.rpc.setup_call('stop', dict(pin, grant_sha256='c' * 64))
        self.assertEqual(len(self.calls), 4)

    def test_setup_uncertain_advance_never_replays_but_status_and_stop_remain_available(self):
        pin = dict(grant_id='a' * 32, grant_sha256='b' * 64, deadline_at=9000)
        def timeout(*args):
            self.calls.append(args)
            raise TimeoutError('private value')
        self.rpc.execute = timeout
        with self.assertRaisesRegex(TransportError, 'uncertain'):
            self.rpc.setup_call('advance', pin)
        first = copy.deepcopy(self.saved['transport-first-error'])
        self.rpc.execute = self.execute
        with self.assertRaisesRegex(TransportError, 'readback_required'):
            self.rpc.setup_call('advance', pin)
        for operation in ('status', 'stop'):
            self.rpc.setup_call(operation, pin)
        self.assertEqual(len(self.calls), 3)
        self.assertFalse(self.saved['setup-mutation']['completed'])
        self.assertEqual(first, self.saved['transport-first-error'])
        self.assertNotIn('private value', json.dumps(self.saved))

    def test_setup_changed_reply_and_unconfirmed_intent_never_allow_another_advance(self):
        pin = dict(grant_id='a' * 32, grant_sha256='b' * 64, deadline_at=9000)
        self.rpc.save = lambda *_: None
        with self.assertRaisesRegex(TransportError, 'journal_unconfirmed'):
            self.rpc.setup_call('advance', pin)
        self.assertEqual(self.calls, [])
        self.rpc.save = lambda k, v: self.saved.__setitem__(k, copy.deepcopy(v))
        self.rpc.execute = lambda *_: b'{"ok":true,"result":{}}'
        with self.assertRaisesRegex(TransportError, 'binding_changed'):
            self.rpc.setup_call('advance', pin)
        self.assertFalse(self.saved['setup-mutation']['completed'])

    def test_setup_cleanup_does_not_clear_uncertain_advance_or_first_error(self):
        pin = dict(grant_id='a' * 32, grant_sha256='b' * 64, deadline_at=9000)
        self.rpc.execute = lambda *_: (_ for _ in ()).throw(TimeoutError('private'))
        with self.assertRaises(TransportError): self.rpc.setup_call('advance', pin)
        pending = copy.deepcopy(self.saved['setup-mutation'])
        first = copy.deepcopy(self.saved['transport-first-error'])
        with self.assertRaises(TransportError): self.rpc.setup_call('cleanup', pin)
        self.assertEqual(self.saved['setup-cleanup']['request']['sequence'], 1)
        self.rpc.execute = self.execute
        self.rpc.setup_call('cleanup', pin)
        self.assertEqual(self.saved['setup-cleanup']['request']['sequence'], 2)
        self.assertTrue(self.saved['setup-cleanup']['completed'])
        self.assertEqual(self.saved['setup-mutation'], pending)
        self.assertEqual(self.saved['transport-first-error'], first)
        with self.assertRaises(TransportError): self.rpc.setup_call('advance', pin)

    def test_setup_cleanup_even_after_completed_advance_prevents_new_create_work(self):
        pin = dict(grant_id='a' * 32, grant_sha256='b' * 64, deadline_at=9000)
        self.rpc.setup_call('advance', pin)
        self.rpc.setup_call('cleanup', pin)
        with self.assertRaisesRegex(TransportError, 'cleanup_started'): self.rpc.setup_call('advance', pin)
        self.assertEqual(len(self.calls), 2)

    def test_setup_rejects_malformed_scope_and_denied_authority_without_ssh(self):
        pin = dict(grant_id='a' * 32, grant_sha256='b' * 64, deadline_at=9000)
        for bad in (dict(pin, deadline_at=True), dict(pin, extra=True), dict(pin, grant_id='../key')):
            with self.assertRaises(TransportError): self.rpc.setup_call('advance', bad)
        self.rpc.guard = lambda *_: (_ for _ in ()).throw(TransportError('not_authorized'))
        with self.assertRaisesRegex(TransportError, 'not_authorized'): self.rpc.setup_call('advance', pin)
        self.assertEqual(self.calls, [])


class MemberCleanupTests(unittest.TestCase):
    def setUp(self):
        self.now, self.deleted, self.aux_calls, self.saved = 100, [], [], []
        self.roles = {role: {'task_id': f'00000000-0000-4000-8000-{i:012d}', 'external_id': str(900 + i)}
                      for i, role in enumerate(('source-a', 'source-b', 'candidate-a1', 'candidate-a2'), 1)}
        self.present = {'source-a', 'source-b', 'candidate-a2'}  # A1 already natively deleted.
        self.error_role = None; self.aux_ready = True
        self.cleanup = MemberCleanup(run_id='keli-accept-offline-r35-fixture', roles=self.roles,
            call=self.call, auxiliary=self.aux, save=lambda v: self.saved.append(copy.deepcopy(v)), clock=lambda: self.now)

    def call(self, op, role):
        if role == self.error_role: raise TimeoutError('secret error')
        if op == 'task-delete':
            self.deleted.append(role); self.present.discard(role)
        return dict(self.roles[role], absent=role not in self.present)

    def aux(self, op):
        self.aux_calls.append(op)
        return {'restored': True, 'dns_absent_twice': self.aux_ready, 'private_materials_removed': True,
                'intents_reconciled': True, 'evidence_saved_and_read_back': True, 'exact_identity': True, 'absent': True}

    def test_all_roles_cleanup_then_dns_materials_and_control_last_twice(self):
        self.cleanup.step(); self.assertEqual(self.deleted, ['candidate-a2', 'source-a', 'source-b'])
        self.cleanup.step(); self.now += 9; self.cleanup.step()
        self.assertNotIn('control-delete-or-inspect', self.aux_calls)
        self.now += 1; self.assertEqual(self.cleanup.step()['phase'], 'control')
        self.assertEqual(self.cleanup.step()['phase'], 'control')
        self.now += 10; self.assertEqual(self.cleanup.step()['phase'], 'closed')
        count = len(self.aux_calls); self.cleanup.step(); self.assertEqual(len(self.aux_calls), count)
        self.assertNotIn('candidate-a1', self.deleted)

    def test_unknown_breaks_consecutive_absence_and_preserves_controller(self):
        self.cleanup.step(); self.cleanup.step(); self.now += 10; self.error_role = 'source-b'
        result = self.cleanup.step(); self.assertEqual(result['phase'], 'tasks'); self.assertEqual(result['reads'], {})
        self.assertNotIn('control-delete-or-inspect', self.aux_calls)
        self.error_role = None; self.cleanup.step(); self.assertEqual(self.cleanup.state['phase'], 'tasks')
        self.assertNotIn('secret error', json.dumps(self.cleanup.state))

    def test_dns_or_private_cleanup_not_confirmed_keeps_control(self):
        self.aux_ready = False
        self.cleanup.step(); self.cleanup.step(); self.now += 10
        self.assertEqual(self.cleanup.step()['phase'], 'auxiliary')
        self.assertNotIn('control-delete-or-inspect', self.aux_calls)

    def test_restore_failure_never_deletes_nodes(self):
        self.cleanup.auxiliary = lambda _: {'restored': False}
        self.assertEqual(self.cleanup.step()['phase'], 'restore'); self.assertEqual(self.deleted, [])

    def test_resume_preserves_delete_intents_and_scope(self):
        self.cleanup.step()
        other = MemberCleanup(run_id='keli-accept-offline-r35-fixture', roles=self.roles, call=self.call,
            auxiliary=self.aux, save=lambda _: None, clock=lambda: self.now, restored=copy.deepcopy(self.cleanup.state))
        self.present.add('source-a'); other.step()
        self.assertEqual(self.deleted.count('source-a'), 1)
        with self.assertRaisesRegex(TransportError, 'resume_changed'):
            MemberCleanup(run_id='keli-accept-other-fixture', roles=self.roles, call=self.call,
                auxiliary=self.aux, save=lambda _: None, clock=lambda: self.now, restored=self.cleanup.state)

    def test_clock_rollback_never_qualifies_absence(self):
        self.cleanup.step(); self.now -= 1; state = self.cleanup.step()
        self.assertEqual(state['reads'], {}); self.assertEqual(state['phase'], 'tasks')
        self.assertNotIn('control-delete-or-inspect', self.aux_calls)

    def test_wrong_id_is_not_cleanup_proof(self):
        self.cleanup.call = lambda *_: {'task_id': 'other', 'external_id': '700', 'absent': True}
        self.assertEqual(self.cleanup.step()['phase'], 'tasks'); self.assertEqual(self.deleted, [])
        self.assertNotIn('control-delete-or-inspect', self.aux_calls)

    def test_absence_delay_is_measured_after_response_not_before_slow_ssh(self):
        self.cleanup.roles = {'source-a': self.roles['source-a']}
        self.present.clear()
        def slow(op, role):
            self.now += 20
            return self.call(op, role)
        self.cleanup.call = slow
        self.cleanup.step()
        self.assertEqual(self.cleanup.state['reads']['source-a'], [120])
        self.now = 129; self.cleanup.step()
        self.assertEqual(self.cleanup.state['reads']['source-a'], [120])
        self.now = 130; self.cleanup.step()
        self.assertEqual(self.cleanup.state['reads']['source-a'], [120, 150])


if __name__ == '__main__':
    unittest.main()
