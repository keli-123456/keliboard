"""Exercise concrete stage routing through the existing whole-round fixture."""
import copy
import unittest
import subprocess
import sys
from pathlib import Path

from member_auxiliary import digest
from member_execution import MemberExecutionSetup
from member_initialization import MemberInitialization, STAGES
from member_transport import TransportError
from test_member_assembly import AssemblyTests


class ExecutionPort:
    def __init__(self, base, owner):
        self.base, self.owner = base, owner
        self.unknown = False
        self.reconciled = False

    def dispatch(self, stage, request, receipts):
        assert MemberExecutionSetup.OWNERS[stage] == self.owner
        return self.base.setup.invoke(stage, request, receipts)

    def reconcile(self, request):
        self.base.f.events.append(('reconcile-' + self.owner, {}, self.base.f.now))
        self.reconciled = True
        return dict(request_sha256=digest(request), resolved=not self.unknown and self.base.f.resolved)

    def snapshot(self):
        assert self.reconciled
        f = self.base.f
        if self.owner == 'installation':
            return dict(private_files=copy.deepcopy(f.original_scope['private_files']) if f.created_private else [], requests=[])
        if self.owner == 'sources':
            return dict(roles={r: f.roles[r] for r in f.created_roles if r.startswith('source-')}, faults=[], requests=[])
        if self.owner == 'dns':
            return dict(records=copy.deepcopy([p for p in f.original_scope['records'] if p['record_id'] in f.created_records]), requests=[])
        return dict(private_files=[], requests=[])


class ExecutionTests(unittest.TestCase):
    def setUp(self):
        self.base = AssemblyTests()
        self.base.setUp(); self.addCleanup(self.base.doCleanups)
        self.f = self.base.f
        self.ports = {owner: ExecutionPort(self.base, owner) for owner in ('installation', 'sources', 'dns', 'authority')}

    def setup(self):
        return MemberExecutionSetup(scope=self.f.setup_scope, **self.ports,
            load=self.f.journal.get, save=self.f.save, guard=lambda _: None)

    def round(self):
        from member_assembly import MemberRoundAssembly
        bindings = self.base.bindings(); bindings['setup'] = self.setup()
        return MemberRoundAssembly(**bindings).round()

    def test_whole_round_routes_all_setup_operations_and_native_cleanup(self):
        state = self.base.finish(self.round())
        self.assertTrue(state['acceptance_passed']); self.assertTrue(state['resources_cleaned'])
        for stage in STAGES[1:]: self.assertIsNotNone(self.f.journal['execution-' + stage]['reply'])
        ops = [e[0] for e in self.f.events]
        self.assertLess(ops.index('reconcile-authority'), ops.index('reconcile-sources'))
        self.assertLess(ops.index('private-remove'), ops.index('control-delete'))

    def test_all_stages_before_after_and_partial_failure_take_unified_cleanup(self):
        for stage in STAGES[1:]:
            for timing in ('before', 'after'):
                with self.subTest(stage=stage, timing=timing):
                    self.base.doCleanups(); self.setUp(); self.f.fail = (stage, timing)
                    result = self.base.finish(self.round())
                    self.assertTrue(result['resources_cleaned']); self.assertFalse(result['acceptance_passed'])
                    self.assertEqual(1, [e[0] for e in self.f.events].count(stage))
                    self.assertNotIn('private-error-token', str(self.f.journal))
        self.base.doCleanups(); self.setUp(); self.f.fail = ('sources-initialize', 'partial')
        self.assertTrue(self.base.finish(self.round())['resources_cleaned'])
        self.assertEqual(['source-a'], [e[1] for e in self.f.events if e[0] == 'task-delete'])

    def test_unknown_recovery_never_turns_into_empty_scope_or_creation_retry(self):
        self.f.fail = ('private-import', 'after'); self.ports['installation'].unknown = True
        driver = self.round()
        for _ in range(20): driver.step(); self.f.now += 10
        self.assertFalse(driver.state['resources_cleaned'])
        self.assertNotIn('execution-cleanup-scope', self.f.journal)
        self.ports['installation'].unknown = False
        self.assertTrue(self.base.finish(self.round())['resources_cleaned'])
        self.assertEqual(1, [e[0] for e in self.f.events].count('private-import'))

    def test_lost_mutation_reply_requires_readback_even_after_new_binding(self):
        driver = self.round(); driver.step()
        stage = 'control-install'
        receipts = self.f.journal['initialization']['receipts']
        request = dict(run_id=self.f.setup_scope['run_id'], bundle_sha256=self.f.setup_scope['bundle_sha256'],
                       stage=stage, predecessors_sha256=digest(receipts), request_id=digest([self.f.setup_scope, stage])[:32])
        self.f.fail = (stage, 'after')
        with self.assertRaises(TimeoutError): self.setup().dispatch(stage, request, receipts)
        with self.assertRaisesRegex(TransportError, 'readback_required'): self.setup().dispatch(stage, request, receipts)
        self.assertEqual(1, [e[0] for e in self.f.events].count(stage))

    def test_stopped_setup_cannot_resume_and_bad_bindings_are_rejected(self):
        setup = self.setup(); setup.persist('execution-stopped', {'binding': digest(self.f.setup_scope)})
        with self.assertRaisesRegex(TransportError, 'stopped'): setup.dispatch('control-install', {}, {})
        self.ports['installation'] = object()
        with self.assertRaisesRegex(TransportError, 'interface_missing'): self.setup()

    def test_private_reply_and_wrong_predecessors_do_not_enter_journal(self):
        self.f.secret_reply = True
        result = self.base.finish(self.round())
        self.assertFalse(result['acceptance_passed'])
        self.assertNotIn('must-not-persist', str(self.f.journal))

    def test_changed_recovery_receipts_do_not_replace_original_cleanup_scope(self):
        self.base.finish(self.round())
        prior = copy.deepcopy(self.f.journal['execution-cleanup-scope'])
        self.f.original_scope['records'][0]['value'] = '192.0.2.199'
        request = dict(scope=self.f.setup_scope, intents={}, receipts={}, pending=None)
        with self.assertRaisesRegex(TransportError, 'receipts_changed'): self.setup().reconcile(request)
        self.assertEqual(prior, self.f.journal['execution-cleanup-scope'])

    def test_cleanup_snapshots_cannot_export_private_fields(self):
        self.f.fail = ('private-import', 'after')
        original = self.ports['installation'].snapshot
        def unsafe():
            value = original()
            value['private_files'][0]['credential'] = 'synthetic-must-not-export'
            return value
        self.ports['installation'].snapshot = unsafe
        driver = self.round()
        for _ in range(20): driver.step(); self.f.now += 10
        self.assertFalse(driver.state['resources_cleaned'])
        self.assertNotIn('execution-cleanup-scope', self.f.journal)
        self.assertNotIn('synthetic-must-not-export', str(self.f.journal))
        self.ports['installation'].snapshot = original
        self.assertTrue(self.base.finish(self.round())['resources_cleaned'])

    def test_changed_original_request_is_refused_before_installation(self):
        setup = self.setup()
        with self.assertRaisesRegex(TransportError, 'request_changed'):
            setup.dispatch('control-install', {'run_id': 'different'}, {})
        self.assertEqual([], self.f.events)


from test_member_assembly_linux import MemberAssemblyLinuxTests, CHILD


class ExecutionLinuxTests(MemberAssemblyLinuxTests):
    def launch(self, directory, mode):
        return subprocess.Popen([sys.executable, '-u', '-B', '-c', CHILD, directory, mode, 'execution'],
            cwd=Path(__file__).parent, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)


if __name__ == '__main__': unittest.main()
