"""Whole local composition; SSH byte boundary, cloud and DNS are synthetic."""
import copy
import hashlib
import json
import tempfile
import unittest
from contextlib import contextmanager
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

from member_assembly import MemberRoundAssembly, run_assembled_round
from member_auxiliary import digest
from member_cli import BoundControllerTransport, RUN
from member_initialization import STAGES
from member_transport import TransportError
from test_member_initialization import BootstrapFixture, SetupInterfaces


class Setup(SetupInterfaces):
    def __init__(self, fixture, owner):
        super().__init__(fixture)
        self.owner = owner

    def invoke(self, stage, request, receipts):
        value = super().invoke(stage, request, receipts)
        if stage == 'native-grant' and 'receipt' in value:
            value['receipt']['grant_sha256'] = self.owner.grant_hash
        return value

    def cleanup_scope(self, result):
        auxiliary = super().cleanup_scope(result)
        auxiliary['control'] = None
        return {'run_id': RUN, 'roles': {r: self.f.roles[r] for r in self.f.created_roles
                                        if r.startswith('source-')}, 'auxiliary': auxiliary}


class AssemblyTests(unittest.TestCase):
    def setUp(self):
        self.f = BootstrapFixture()
        self.prepare_control()
        self.tmp = tempfile.TemporaryDirectory(); self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.key, self.known, self.grant_file = [self.root / n for n in ('key', 'known', 'grant.json')]
        self.key.write_bytes(b'synthetic-key'); self.known.write_bytes(b'synthetic-verified-host-key')
        self.grant = dict(schema=2, execution_authorized=True, isolated=True, run_id=RUN,
            grant_id='a' * 32, controller_machine_id_sha256='c' * 64,
            bundle_sha256=self.f.setup_scope['bundle_sha256'], started_at=self.f.now, deadline_at=self.f.now + 7200,
            controller_ipv4='192.0.2.8', identity_sha256=hashlib.sha256(self.key.read_bytes()).hexdigest(),
            known_hosts_sha256=hashlib.sha256(self.known.read_bytes()).hexdigest())
        self.grant_file.write_text(json.dumps(self.grant), encoding='utf-8')
        for p in (self.key, self.known, self.grant_file): p.chmod(0o600)
        self.grant_hash = hashlib.sha256(self.grant_file.read_bytes()).hexdigest()
        self.setup = Setup(self.f, self)
        self.stopped, self.lose_native = False, False
        self.requests, self.partial_calls = [], []
        for name in ('socket.socket', 'subprocess.Popen'):
            p = patch(name, side_effect=AssertionError('real transport forbidden')); p.start(); self.addCleanup(p.stop)

    def prepare_control(self):
        task = '00000000-0000-4000-8000-000000000071'
        self.f.original_scope['control'] = dict(run_id=RUN, external_id='800', task_id=task,
            label='keli-' + task.replace('-', ''), marker='keli-create-' + task.replace('-', ''),
            region='sg-sin-2', size='g6-standard-2', image='linode/debian12',
            provider_created_at='2026-11-16T10:00:00Z')

    def transport(self, _receipt):
        return BoundControllerTransport(grant_file=self.grant_file, grant_sha256=self.grant_hash,
            run_id=RUN, host='192.0.2.8', identity_file=self.key, known_hosts=self.known,
            clock=lambda: self.f.now, guard=lambda *_: None, load=self.f.journal.get,
            save=self.f.save, execute=self.execute)

    def execute(self, command, payload, _timeout):
        self.assertIn('StrictHostKeyChecking=yes', command)
        self.assertIn('UpdateHostKeys=no', command)
        request = json.loads(payload); self.requests.append(request)
        op = request['operation']; self.f.events.append((op, request, self.f.now))
        if op == 'round-step':
            self.f.created_roles.update(('candidate-a1', 'candidate-a2'))
            self.f.present.add('candidate-a2')
            # This is a simulated switch receipt, not an observed real DNS value.
            record = self.f.original_scope['records'][1]
            self.f.pins.pop(digest(record)); record['value'] = '192.0.2.55'; self.f.pins[digest(record)] = True
            result = dict(phase='finished', branches=dict.fromkeys(
                ('native_cleanup', 'cooldown', 'retry', 'b_unchanged', 'dns_cutover'), True))
            if self.lose_native: raise TimeoutError('do-not-export-private-log')
        elif op == 'round-stop':
            self.stopped = True; result = {'stopped': True}
        elif op == 'round-status':
            result = dict(run_id=RUN, stopped=self.stopped, resolved=True,
                          roles={r: self.f.roles[r] for r in self.f.created_roles})
        else:
            self.assertIn(op, ('task-inspect', 'task-delete'))
            result = self.f.task(op, request['role'])
        return json.dumps(dict(request, ok=True, result=result)).encode()

    def partial_task(self, op, role, pin):
        self.assertEqual(pin, self.f.roles[role]); self.partial_calls.append((op, role))
        return self.f.task(op, role)

    def control(self, operation, request):
        if operation == 'control-create':
            self.setup.invoke('control-create', request, {})
        else:
            self.assertEqual(operation, 'control-readback')
        return dict(request_sha256=digest(request), resolved=self.f.resolved,
            dispatched=self.f.created_control, ready=operation == 'control-create',
            pin=copy.deepcopy(self.f.original_scope['control']) if self.f.created_control else None)

    def assembly(self):
        def provider(operation, pin):
            reply = self.f.io(operation, pin)
            if operation.endswith('-delete'):
                reply.update(status='owned', dispatched=True)
            return reply
        return MemberRoundAssembly(scope=self.f.setup_scope, setup=self.setup,
            control=SimpleNamespace(dispatch=self.control), native_transport=self.transport,
            provider=SimpleNamespace(dispatch=provider),
            controller=SimpleNamespace(restore_fault=lambda p: self.f.io('fault-restore', p),
                inspect_fault=lambda p: self.f.io('fault-inspect', p),
                remove_private=lambda p: self.f.io('private-remove', p),
                inspect_private=lambda p: self.f.io('private-inspect', p)),
            task_call=self.partial_task, reconcile=lambda requests: dict(requests_sha256=digest(requests), resolved=self.f.resolved),
            export=lambda: dict(run_id=RUN, sha256='e' * 64, verified=self.f.export_ok),
            load=self.f.journal.get, save=self.f.save, guard=lambda _: None, clock=lambda: self.f.now)

    def finish(self, driver):
        for _ in range(80):
            state = driver.step()
            if state['phase'] == 'closed': return state
            self.f.now += 10
        self.fail(str(state))

    def test_full_assembly_uses_bound_rpc_then_unified_teardown_control_last(self):
        state = self.finish(self.assembly().round())
        self.assertTrue(state['acceptance_passed']); self.assertTrue(state['resources_cleaned'])
        self.assertFalse(any(self.f.pins.values())); self.assertFalse(self.f.present)
        self.assertFalse(self.partial_calls)
        ops = [e[0] for e in self.f.events]
        self.assertLess(ops.index('round-stop'), ops.index('setup-reconcile'))
        self.assertLess(ops.index('private-remove'), ops.index('control-delete'))
        self.assertEqual(ops.count('control-create'), 1); self.assertEqual(ops.count('control-delete'), 1)
        self.assertEqual(self.f.journal['assembly-cleanup-scope']['auxiliary']['records'][1]['value'], '192.0.2.55')

    def test_failure_before_or_after_each_stage_cleans_only_created_resources(self):
        for stage in STAGES:
            for boundary in ('before', 'after'):
                with self.subTest(stage=stage, boundary=boundary):
                    self.f = BootstrapFixture(); self.prepare_control(); self.setup = Setup(self.f, self)
                    self.f.fail = (stage, boundary)
                    state = self.finish(self.assembly().round())
                    self.assertTrue(state['resources_cleaned']); self.assertFalse(state['acceptance_passed'])
                    self.assertFalse(any(self.f.pins.values())); self.assertFalse(self.f.present)
                    self.assertNotIn('private-error-token', str(self.f.journal))

    def test_partial_source_a_without_b_or_grant_is_cleaned_by_pinned_setup_channel(self):
        self.f.fail = ('sources-initialize', 'partial')
        state = self.finish(self.assembly().round())
        self.assertTrue(state['resources_cleaned']); self.assertFalse(state['acceptance_passed'])
        self.assertEqual({role for _, role in self.partial_calls}, {'source-a'})
        self.assertFalse(self.requests)

    def test_restart_after_native_receipt_loss_stops_and_cleans_no_second_dispatch(self):
        self.lose_native = True; driver = self.assembly().round()
        for _ in range(len(STAGES) + 1): driver.step(); self.f.now += 1
        first = driver.state['first_error']
        self.lose_native = False
        state = self.finish(self.assembly().round())
        self.assertTrue(state['resources_cleaned']); self.assertFalse(state['acceptance_passed'])
        self.assertEqual(first, state['first_error'])
        self.assertEqual([r['operation'] for r in self.requests].count('round-step'), 1)

    def test_restart_in_cleanup_reconstructs_native_transport_and_honors_delete_intents(self):
        driver = self.assembly().round()
        while driver.state['phase'] != 'cleanup': driver.step(); self.f.now += 1
        driver.step(); driver.step(); self.f.now += 10
        state = self.finish(self.assembly().round())
        self.assertTrue(state['resources_cleaned']); self.assertFalse(self.partial_calls)
        deleted = [r['role'] for r in self.requests if r['operation'] == 'task-delete']
        self.assertEqual(len(deleted), len(set(deleted)))

    def test_stop_at_each_initialization_stage_does_not_start_native_work(self):
        for count in range(len(STAGES) + 1):
            with self.subTest(count=count):
                self.f = BootstrapFixture(); self.prepare_control(); self.setup = Setup(self.f, self)
                driver = self.assembly().round()
                for _ in range(count): driver.step(); self.f.now += 1
                driver.step(stop=True); state = self.finish(driver)
                self.assertTrue(state['resources_cleaned']); self.assertFalse(state['acceptance_passed'])
                self.assertNotIn('round-step', [e[0] for e in self.f.events])

    def test_unresolved_create_does_not_become_zero_or_allow_deletion(self):
        self.f.fail = ('control-create', 'after'); self.f.resolved = False
        driver = self.assembly().round()
        for _ in range(10): driver.step(); self.f.now += 10
        self.assertEqual(driver.state['phase'], 'reconcile'); self.assertFalse(driver.state['resources_cleaned'])
        self.assertNotIn('control-delete', [e[0] for e in self.f.events])
        self.f.resolved = True
        self.assertTrue(self.finish(self.assembly().round())['resources_cleaned'])

    def test_ledger_collision_or_source_change_prevents_all_cleanup_calls(self):
        driver = self.assembly().round()
        while driver.state['phase'] != 'cleanup': driver.step(); self.f.now += 1
        original = copy.deepcopy(driver.state['cleanup_scope'])
        for kind in ('source', 'collision', 'third', 'control', 'fault'):
            scope = copy.deepcopy(original)
            if kind == 'source': scope['native']['roles']['source-a']['external_id'] = '789'
            if kind == 'collision': scope['native']['roles']['candidate-a2'] = scope['native']['roles']['candidate-a1']
            if kind == 'third': scope['native']['roles']['candidate-a3'] = scope['native']['roles']['candidate-a1']
            if kind == 'control': scope['initialization']['auxiliary']['control']['external_id'] = '901'
            if kind == 'fault': scope['initialization']['auxiliary']['faults'] = [dict(run_id=RUN, role='source-a', external_id='902')]
            with self.subTest(kind=kind), self.assertRaises(TransportError): self.assembly().cleanup(scope, None)
        self.assertNotIn('control-delete', [e[0] for e in self.f.events])

    def test_cleanup_error_retains_controller_and_does_not_repeat_dns_delete(self):
        self.f.fail_operation = 'dns-delete'; driver = self.assembly().round()
        for _ in range(40): driver.step(); self.f.now += 10
        self.assertFalse(driver.state['resources_cleaned'])
        self.assertNotIn('control-delete', [e[0] for e in self.f.events])
        self.assertEqual([e[0] for e in self.f.events].count('dns-delete'), 3)

    def test_evidence_failure_does_not_keep_cloud_resources_but_cannot_pass(self):
        self.f.export_ok = False
        state = self.finish(self.assembly().round())
        self.assertTrue(state['resources_cleaned']); self.assertFalse(state['acceptance_passed'])

    def test_grant_for_another_bundle_never_starts_native_work(self):
        assembly = self.assembly(); assembly.scope['bundle_sha256'] = 'f' * 64
        with self.assertRaisesRegex(TransportError, 'assembly_grant_changed'):
            assembly.native_port({'grant_sha256': self.grant_hash})
        self.assertFalse(self.requests)

    def test_control_poll_retains_original_intent_and_does_not_install_until_ready(self):
        assembly = self.assembly(); control = assembly.initialization.interfaces.control
        original = control.dispatch
        calls = []
        def poll(operation, request):
            calls.append(copy.deepcopy(request))
            if len(calls) == 1:
                return dict(original(operation, request), ready=False)
            return dict(request_sha256=digest(request), resolved=True, dispatched=True,
                        pin=copy.deepcopy(self.f.original_scope['control']), ready=True)
        control.dispatch = poll
        driver = assembly.round(); driver.step()
        self.assertEqual(assembly.initialization.state['index'], 0)
        self.assertNotIn('control-install', [e[0] for e in self.f.events])
        driver.step()
        self.assertEqual(calls[0], calls[1])
        self.assertEqual(assembly.initialization.state['intents']['control-create'], calls[0])
        self.assertEqual([e[0] for e in self.f.events].count('control-create'), 1)

    def test_private_fields_in_control_reply_are_rejected_before_journal_persistence(self):
        assembly = self.assembly(); control = assembly.initialization.interfaces.control
        original = control.dispatch
        def contaminated(operation, request):
            value = original(operation, request)
            value['pin']['password'] = 'synthetic-secret-do-not-save'
            return value
        control.dispatch = contaminated
        driver = assembly.round(); state = driver.step()
        self.assertEqual(state['phase'], 'reconcile')
        self.assertNotIn('synthetic-secret-do-not-save', str(self.f.journal))

    def test_cleanup_scope_change_after_resume_is_not_adopted(self):
        driver = self.assembly().round()
        while driver.state['phase'] != 'cleanup': driver.step(); self.f.now += 1
        driver.step()
        scope = copy.deepcopy(driver.state['cleanup_scope'])
        scope['initialization']['auxiliary']['records'][0]['value'] = '192.0.2.222'
        with self.assertRaisesRegex(TransportError, 'cleanup_assembly_resume_changed'):
            self.assembly().cleanup(scope, driver.state['cleanup'])

    def test_definite_control_rejection_closes_without_inventing_a_machine(self):
        assembly = self.assembly()
        def rejected(operation, request):
            if operation == 'control-create':
                raise TransportError('transport_control_create_rejected')
            return dict(request_sha256=digest(request), resolved=True, dispatched=True,
                        ready=False, pin=None, creation_rejected=True)
        assembly.initialization.interfaces.control.dispatch = rejected
        state = self.finish(assembly.round())
        self.assertTrue(state['resources_cleaned']); self.assertFalse(state['acceptance_passed'])
        self.assertNotIn('control-delete', [e[0] for e in self.f.events])

    def test_inconsistent_ready_or_rejection_receipt_cannot_advance_initialization(self):
        for kind in ('ready', 'rejected', 'binding'):
            with self.subTest(kind=kind):
                self.f = BootstrapFixture(); self.prepare_control(); self.setup = Setup(self.f, self)
                assembly = self.assembly(); control = assembly.initialization.interfaces.control
                original = control.dispatch
                def invalid(operation, request):
                    value = original(operation, request)
                    if kind == 'ready': value['resolved'] = False
                    if kind == 'rejected': value['creation_rejected'] = True
                    if kind == 'binding': value['request_sha256'] = 'a' * 64
                    return value
                control.dispatch = invalid
                state = assembly.round().step()
                self.assertEqual(state['phase'], 'reconcile')
                self.assertEqual(assembly.initialization.state['index'], 0)

    def test_host_entry_keeps_exclusive_journal_through_final_cleanup(self):
        locked = []
        assembly = self.assembly()
        @contextmanager
        def journal(directory, run_id):
            self.assertEqual(directory, self.root); self.assertEqual(run_id, RUN)
            locked.append(True)
            try: yield self.f.journal.get, self.f.save
            finally: locked.pop()
        def guarded(_operation):
            self.assertEqual(locked, [True])
        assembly.guard = guarded
        def construct(**options):
            self.assertEqual(locked, [True])
            self.assertEqual(options['load'], self.f.journal.get)
            return assembly
        with patch('member_assembly.round_journal', journal), patch('member_assembly.MemberRoundAssembly', construct), \
                patch('member_assembly.run', self.finish):
            state = run_assembled_round(self.root)
        self.assertTrue(state['resources_cleaned']); self.assertFalse(locked)


if __name__ == '__main__':
    unittest.main()
