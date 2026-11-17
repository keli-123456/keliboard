"""No live backends: exercise bootstrap failures and independent partial teardown."""
import copy
import json
import unittest
from unittest.mock import patch

from member_auxiliary import MemberAuxiliary, digest
from member_auxiliary_bindings import MemberAuxiliaryBindings
from member_cli import RUN
from member_initialization import MemberInitialization, InitializedMemberPort, STAGES
from member_round import MemberRound
from member_transport import MemberCleanup, TransportError
from test_member_round import RoundFixture


class SetupInterfaces:
    """Synthetic external API receipts; never presented as a real host installer."""
    def __init__(self, fixture):
        self.f = fixture
        for stage in STAGES:
            setattr(self, stage.replace('-', '_'), lambda request, receipts, s=stage: self.invoke(s, request, receipts))

    def invoke(self, stage, request, receipts):
        f = self.f
        f.events.append((stage, request, f.now))
        assert list(receipts) == list(STAGES[:STAGES.index(stage)])
        if f.fail == (stage, 'before'):
            raise TimeoutError('private-error-token')
        if stage == 'control-create':
            f.created_control = True
            f.pins[digest(f.original_scope['control'])] = True
        if stage == 'private-import':
            f.created_private = True
            for pin in f.original_scope['private_files']: f.pins[digest(pin)] = True
        if stage == 'sources-initialize':
            if f.fail == (stage, 'partial'):
                f.created_roles.add('source-a'); f.present.add('source-a')
                raise TimeoutError('source-b-not-dispatched')
            f.created_roles.update(('source-a', 'source-b'))
            f.present.update(f.created_roles)
        if stage in ('control-dns', 'dns-initialize'):
            for pin in f.original_scope['records']:
                if (stage == 'control-dns') == pin['name'].startswith('control-'):
                    f.created_records.add(pin['record_id']); f.pins[digest(pin)] = True
            f.created_dns = bool(f.created_records)
        if f.fail == (stage, 'after'):
            raise TimeoutError('private-error-token')
        if stage == 'sources-initialize' and f.polls > 0:
            f.polls -= 1
            return {'request_sha256': digest(request), 'waiting': True}
        receipt = {'run_id': RUN, 'stage': stage, 'request_id': request['request_id'], 'evidence_sha256': 'e' * 64}
        if stage == 'native-grant': receipt['grant_sha256'] = 'c' * 64
        if f.secret_reply: receipt['credentials'] = 'must-not-persist'
        return {'request_sha256': 'changed' if f.changed_reply else digest(request), 'completed': True, 'receipt': receipt}

    def reconcile(self, request):
        self.f.events.append(('setup-reconcile', request, self.f.now))
        return {'request_sha256': digest(request), 'resolved': self.f.resolved}

    def cleanup_scope(self, _result):
        f = self.f
        scope = copy.deepcopy(f.original_scope)
        if not f.created_control: scope['control'] = None
        scope['records'] = [p for p in scope['records'] if p['record_id'] in f.created_records]
        if not f.created_private: scope['private_files'] = []
        scope['faults'] = []
        f.scope = scope
        return scope


class BootstrapFixture(RoundFixture):
    def __init__(self):
        super().__init__()
        def rewrite(value):
            if isinstance(value, str): return value.replace('keli-accept-offline-r35-fixture', RUN)
            if isinstance(value, dict): return {k: rewrite(v) for k, v in value.items()}
            if isinstance(value, list): return [rewrite(v) for v in value]
            return value
        self.original_scope = rewrite(self.scope)
        self.scope = copy.deepcopy(self.original_scope)
        self.pins, self.present, self.created_roles = {}, set(), set()
        self.created_control = self.created_dns = self.created_private = False
        self.created_records = set()
        self.fail, self.polls, self.resolved = None, 0, True
        self.changed_reply = self.secret_reply = False
        self.interfaces = SetupInterfaces(self)
        self.setup_scope = {'run_id': RUN, 'bundle_sha256': 'd' * 64, 'started_at': self.now, 'deadline_at': self.now + 7200}

    def save(self, key, value):
        self.journal[key] = json.loads(json.dumps(value, allow_nan=False))

    def initialization(self):
        return MemberInitialization(scope=self.setup_scope, interfaces=self.interfaces,
            load=self.journal.get, save=self.save, guard=lambda _: None, clock=lambda: self.now)

    def native_factory(self, receipt):
        assert receipt['grant_sha256'] == 'c' * 64
        def port(operation, request):
            self.events.append((operation, request, self.now))
            if operation == 'native-step':
                self.created_roles.update(('candidate-a1', 'candidate-a2'))
                self.present.add('candidate-a2')
                # Final cleanup pins follow the journaled DNS switch receipt, not
                # the initial A value or an arbitrary provider-observed address.
                record = self.original_scope['records'][1]
                self.pins.pop(digest(record))
                record['value'] = '192.0.2.55'
                self.pins[digest(record)] = True
                return {'request': request, 'phase': 'finished', 'branches': dict.fromkeys(
                    ('native_cleanup', 'cooldown', 'retry', 'b_unchanged', 'dns_cutover'), True)}
            assert operation == 'reconcile'
            return {'run_id': RUN, 'resolved': True, 'cleanup_scope': {'run_id': RUN,
                'roles': {role: self.roles[role] for role in self.created_roles}}}
        return port

    def cleanup(self, scope, restored):
        assert scope['initialization'] == self.scope
        auxiliary = MemberAuxiliary(scope=self.scope, io=self.io, load=self.journal.get, save=self.save, clock=lambda: self.now)
        return MemberCleanup(run_id=RUN, roles={r: self.roles[r] for r in self.created_roles}, call=self.task,
            auxiliary=auxiliary, save=lambda s: self.save('cleanup', s), clock=lambda: self.now, restored=restored)

    def round(self, restored=None):
        port = InitializedMemberPort(self.initialization(), self.native_factory)
        return MemberRound(run_id=RUN, bundle_sha256='d' * 64, port=port, cleanup_factory=self.cleanup,
            load=self.journal.get, save=self.save, guard=lambda _: None, clock=lambda: self.now, restored=restored)


class InitializationTests(unittest.TestCase):
    def setUp(self):
        self.f = BootstrapFixture()
        for name in ('socket.socket', 'subprocess.Popen'):
            p = patch(name, side_effect=AssertionError('external transport forbidden')); p.start(); self.addCleanup(p.stop)

    def finish(self, driver):
        for _ in range(80):
            state = driver.step()
            if state['phase'] == 'closed': return state
            self.f.now += 10
        self.fail(str(state))

    def test_normal_staged_initialization_then_native_and_control_last_cleanup(self):
        self.f.polls = 2
        state = self.finish(self.f.round())
        self.assertTrue(state['acceptance_passed']); self.assertFalse(self.f.present)
        self.assertFalse(any(self.f.pins.values()))
        ops = [e[0] for e in self.f.events]
        self.assertEqual(ops.count('sources-initialize'), 3)
        self.assertLess(ops.index('native-grant'), ops.index('native-step'))
        self.assertLess(ops.index('control-dns'), ops.index('callback-ready'))
        self.assertLess(ops.index('callback-ready'), ops.index('sources-initialize'))
        self.assertLess(ops.index('sources-initialize'), ops.index('dns-initialize'))
        self.assertLess(ops.index('task-delete'), ops.index('control-delete'))
        self.assertLess(ops.index('private-remove'), ops.index('control-delete'))
        for stage in set(STAGES) - {'sources-initialize'}: self.assertEqual(ops.count(stage), 1)

    def test_old_stage_topology_cannot_resume_at_a_different_phase(self):
        initial = self.f.initialization(); initial.step()
        self.f.journal['initialization'].pop('stages')
        with self.assertRaisesRegex(TransportError, 'topology_changed'): self.f.initialization()
        self.assertEqual(1, len(self.f.events))

    def test_every_stage_failure_before_and_after_effect_cleans_only_original_receipts(self):
        for stage in STAGES:
            for timing in ('before', 'after'):
                with self.subTest(stage=stage, timing=timing):
                    self.f = BootstrapFixture(); self.f.fail = (stage, timing)
                    state = self.finish(self.f.round())
                    self.assertTrue(state['resources_cleaned']); self.assertFalse(state['acceptance_passed'])
                    self.assertFalse(any(self.f.pins.values())); self.assertFalse(self.f.present)
                    ops = [e[0] for e in self.f.events]
                    self.assertEqual(ops.count(stage), 1); self.assertNotIn('native-step', ops)
                    self.assertNotIn('private-error-token', str(self.f.journal))
                    self.assertEqual('round_operation_failed', state['first_error'])

    def test_stop_at_each_phase_uses_partial_cleanup_without_finishing_bootstrap(self):
        for count in range(len(STAGES) + 1):
            with self.subTest(count=count):
                self.f = BootstrapFixture(); driver = self.f.round()
                for _ in range(count): driver.step(); self.f.now += 1
                driver.step(stop=True)
                result = self.finish(driver)
                self.assertTrue(result['resources_cleaned']); self.assertFalse(result['acceptance_passed'])
                self.assertNotIn('native-step', [e[0] for e in self.f.events])

    def test_failure_between_a_and_b_cleans_only_a(self):
        self.f.fail = ('sources-initialize', 'partial')
        result = self.finish(self.f.round())
        self.assertTrue(result['resources_cleaned']); self.assertFalse(result['acceptance_passed'])
        self.assertEqual(['source-a'], [e[1] for e in self.f.events if e[0] == 'task-delete'])

    def test_crash_after_persisted_create_intent_only_reconciles(self):
        initialization = self.f.initialization()
        initialization.state['pending'] = {'stage': 'control-create'}
        initialization.state['intents']['control-create'] = {'request_id': 'original'}
        initialization.persist()
        result = self.finish(self.f.round())
        self.assertFalse(result['acceptance_passed']); self.assertTrue(result['resources_cleaned'])
        self.assertNotIn('control-create', [e[0] for e in self.f.events])

    def test_unknown_create_outcome_never_means_no_resources(self):
        self.f.fail = ('control-create', 'after'); self.f.resolved = False
        driver = self.f.round()
        for _ in range(15): driver.step(); self.f.now += 10
        self.assertFalse(driver.state['resources_cleaned'])
        self.assertEqual('reconcile', driver.state['phase'])
        self.assertNotIn('control-delete', [e[0] for e in self.f.events])
        self.f.resolved = True
        result = self.finish(self.f.round(copy.deepcopy(driver.state)))
        self.assertTrue(result['resources_cleaned']); self.assertFalse(result['acceptance_passed'])
        self.assertEqual([e[0] for e in self.f.events].count('control-create'), 1)

    def test_reply_binding_and_secret_projection_fail_closed(self):
        for field in ('secret_reply', 'changed_reply'):
            self.f = BootstrapFixture(); setattr(self.f, field, True)
            result = self.finish(self.f.round())
            self.assertFalse(result['acceptance_passed']); self.assertTrue(result['resources_cleaned'])
            self.assertNotIn('must-not-persist', str(self.f.journal))

    def test_scope_and_clock_change_refuse_initialization(self):
        setup = self.f.initialization(); setup.step()
        self.f.setup_scope['bundle_sha256'] = 'a' * 64
        with self.assertRaisesRegex(TransportError, 'scope_changed'): self.f.initialization()
        self.f.now -= 1
        with self.assertRaisesRegex(TransportError, 'clock_rollback'): setup.step()
        self.assertTrue(setup.state['stopped'])

    def test_write_failure_does_not_dispatch_create(self):
        setup = self.f.initialization()
        setup.save = lambda *_: (_ for _ in ()).throw(PermissionError('local private path'))
        with self.assertRaises(PermissionError): setup.step()
        self.assertEqual(self.f.events, [])

    def test_missing_interface_is_rejected_before_any_create(self):
        self.f.interfaces.native_grant = None
        with self.assertRaisesRegex(TransportError, 'interface_missing'): self.f.initialization()
        self.assertEqual(self.f.events, [])

    def test_evidence_export_failure_still_cleans_resources_and_fails_verdict(self):
        self.f.export_ok = False
        result = self.finish(self.f.round())
        self.assertFalse(result['acceptance_passed']); self.assertTrue(result['resources_cleaned'])

    def test_empty_control_pin_cannot_hide_unresolved_request(self):
        scope = copy.deepcopy(self.f.original_scope); scope['control'] = None
        aux = MemberAuxiliary(scope=scope, io=lambda op, pin: {'pin_sha256': digest(pin), 'resolved': False},
            load=self.f.journal.get, save=self.f.save)
        with self.assertRaisesRegex(TransportError, 'control_create_unresolved'): aux('control-delete-or-inspect')

    def test_expiry_during_polling_never_installs_or_grants_again(self):
        self.f.polls = 999
        driver = self.f.round()
        for _ in range(7): driver.step()
        self.f.now += 6000
        result = self.finish(driver)
        self.assertTrue(result['resources_cleaned']); self.assertFalse(result['acceptance_passed'])
        self.assertNotIn('native-grant', [e[0] for e in self.f.events])


class AuxiliaryBindingTests(unittest.TestCase):
    def setUp(self):
        self.f = BootstrapFixture()
        class Provider:
            def dispatch(_, op, pin):
                reply = self.f.io(op, pin)
                if op.endswith('-delete'): reply['status'] = 'owned'
                return reply
        class Controller:
            def restore_fault(_, pin): return self.f.io('fault-restore', pin)
            def inspect_fault(_, pin): return self.f.io('fault-inspect', pin)
            def remove_private(_, pin): return self.f.io('private-remove', pin)
            def inspect_private(_, pin): return self.f.io('private-inspect', pin)
        self.io = MemberAuxiliaryBindings(scope=self.f.scope, provider=Provider(), controller=Controller(),
            reconcile=lambda requests: {'requests_sha256': digest(requests), 'resolved': self.f.resolved},
            export=lambda: {'run_id': RUN, 'sha256': 'e' * 64, 'verified': True}, load=self.f.journal.get, save=self.f.save)

    def test_provider_and_controller_dispatch_exact_pins(self):
        pins = [('control-inspect', self.f.scope['control']), ('dns-delete', self.f.scope['records'][0]),
            ('fault-inspect', self.f.scope['faults'][0]), ('private-remove', self.f.scope['private_files'][0])]
        for op, pin in pins: self.assertEqual(digest(pin), self.io(op, pin)['pin_sha256'])
        self.assertEqual([p[0] for p in pins], [e[0] for e in self.f.events])

    def test_other_pin_or_uncertain_outcome_cannot_confirm_cleanup(self):
        pin = dict(self.f.scope['control'], external_id='999')
        with self.assertRaisesRegex(TransportError, 'pin_changed'): self.io('control-delete', pin)
        self.f.resolved = False
        with self.assertRaisesRegex(TransportError, 'unresolved'):
            self.io('intents-inspect', {'run_id': RUN, 'requests': self.f.scope['requests']})
        self.assertEqual(self.f.events, [])

    def test_export_requires_local_readback_and_stable_hash(self):
        self.assertTrue(self.io('evidence-export-readback', {'run_id': RUN})['verified'])
        self.io.export = lambda: {'run_id': RUN, 'sha256': 'f' * 64, 'verified': True}
        with self.assertRaisesRegex(TransportError, 'evidence_changed'):
            self.io('evidence-export-readback', {'run_id': RUN})
        self.io.export = lambda: {'run_id': RUN, 'sha256': 'e' * 64, 'verified': True}
        self.io.load = lambda _: None
        with self.assertRaisesRegex(TransportError, 'readback_failed'):
            self.io('evidence-export-readback', {'run_id': RUN})


if __name__ == '__main__':
    unittest.main()
