import copy
import hashlib
import json
from pathlib import Path
import subprocess
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch

import cleanup_tasks as cleanup

RUN = 'keli-accept-20990101-offline-cleanup'
TASK = '11111111-1111-4111-8111-111111111111'
SECRET = 'NEVER_EXPORT_PRIVATE_DATA'


def task():
    row = {'id': TASK, 'label': RUN + '-candidate', 'marker': 'keli-create-' + 'a' * 24,
           'status': 'failed', 'external_id': None, 'provider_created_at': None, 'dispatched_at': None}
    row['dispatch_evidence'] = {'schema': 1, 'task_sha256': hashlib.sha256(json.dumps(
        [row[key] for key in cleanup.FIELDS], separators=(',', ':')).encode()).hexdigest(),
        'checks': dict.fromkeys(cleanup.CHECKS, True)}
    return row


class ClassificationTests(unittest.TestCase):
    def test_terminal_native_candidate_requires_both_boundary_proof_and_complete_empty_inventory(self):
        value = cleanup.classify(RUN, task(), [])
        self.assertEqual(value['classification'], 'not_dispatched')
        self.assertTrue(value['skip_cloud_reconcile'])
        for key in cleanup.CHECKS:
            row = task(); row['dispatch_evidence']['checks'][key] = False
            self.assertFalse(cleanup.classify(RUN, row, [])['skip_cloud_reconcile'], key)

    def test_missing_null_fields_or_no_id_alone_are_not_a_cleanup_authorization(self):
        for key in (*cleanup.FIELDS, 'dispatch_evidence'):
            row = task(); del row[key]
            self.assertFalse(cleanup.classify(RUN, row, [])['skip_cloud_reconcile'], key)
        for key, value in [('status', 'queued'), ('label', RUN + '-source'), ('marker', 'foreign'),
                           ('external_id', '123'), ('provider_created_at', '2099-01-01T00:00:00'), ('dispatched_at', 1)]:
            row = task(); row[key] = value
            self.assertFalse(cleanup.classify(RUN, row, [])['skip_cloud_reconcile'], key)

    def test_changed_proof_partial_inventory_and_ambiguous_inventory_never_skip(self):
        for change in ['hash', 'schema', 'unknown', 'nonboolean', 'missing']:
            row = task(); proof = row['dispatch_evidence']
            if change == 'hash': proof['task_sha256'] = 'b' * 64
            if change == 'schema': proof['schema'] = True
            if change == 'unknown': proof['raw'] = SECRET
            if change == 'nonboolean': proof['checks']['task_terminal'] = 1
            if change == 'missing': del proof['checks']['native_binding']
            self.assertFalse(cleanup.classify(RUN, row, [])['skip_cloud_reconcile'])
        row = task()
        for inventory in [None, {}, [{}], [{'id': 1, 'label': 'other', 'tags': []}] * 2,
                          [{'id': 1, 'label': row['label'], 'tags': []}],
                          [{'id': 1, 'label': 'other', 'tags': [row['marker']]}]]:
            self.assertFalse(cleanup.classify(RUN, row, inventory)['skip_cloud_reconcile'])

    def test_post_boundary_and_lost_response_still_require_original_reconciliation(self):
        row = task(); row['dispatched_at'] = 123456
        value = cleanup.classify(RUN, row, [])
        self.assertEqual(value['classification'], 'dispatched'); self.assertFalse(value['skip_cloud_reconcile'])


class AdapterTests(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory(prefix='keli-cleanup-classifier-')
        self.addCleanup(temporary.cleanup); self.base = Path(temporary.name)
        self.rows = [task()]; self.deleted = []; self.reconciled = []
        outer = self

        class Journal:
            def path(self, name): return outer.base / name
            def save(self, name, value, exclusive=False):
                with self.path(name).open('x' if exclusive else 'w') as stream: json.dump(value, stream)
                self.path(name).chmod(0o600)
            def load(self, name): return json.loads(self.path(name).read_text())

        class Cleanup:
            def __init__(self):
                self.controller = {'tasks': copy.deepcopy(outer.rows)}
                self.cloud = []; self.store = Journal()
            def tasks(self):
                return [{key: row[key] for key in cleanup.FIELDS if row[key] is not None} for row in self.controller['tasks']]
            def receipts_cloud(self, inventory=None):
                for row in self.tasks(): outer.reconciled.append(row['id'])
                return []
            def collect(self):
                self.receipts_cloud([])
                return {'ok': True, 'controller_ready': True}

        def require(value, code):
            if not value: raise ValueError(code)

        def project(value):
            return {'tasks': [{key: row[key] for key in cleanup.FIELDS} for row in value['tasks']]}

        self.module = SimpleNamespace(Cleanup=Cleanup, RUN=RUN, require=require, controller_evidence=project)
        cleanup.install(self.module)
        blocked = patch.object(subprocess, 'Popen', side_effect=AssertionError('subprocess forbidden'))
        blocked.start(); self.addCleanup(blocked.stop)

    def test_installed_collector_keeps_proof_and_skips_only_verified_nonexistent_candidate(self):
        value = self.module.controller_evidence({'tasks': self.rows})
        self.assertEqual(value['tasks'][0]['dispatch_evidence'], self.rows[0]['dispatch_evidence'])
        instance = self.module.Cleanup(); instance.receipts_cloud([])
        self.assertEqual(self.reconciled, [])
        proof = instance.store.load(cleanup.REPORT)
        self.assertEqual(proof['records'][0]['classification'], 'not_dispatched')
        self.assertEqual(proof['provider_mutations'], 0)
        self.assertEqual(len(instance.tasks()), 1, 'Do not hide the task from audits or other cleanup phases')

    def test_old_or_ambiguous_export_continues_through_original_guard(self):
        instance = self.module.Cleanup(); del instance.controller['tasks'][0]['dispatch_evidence']
        instance.receipts_cloud([]); self.assertEqual(self.reconciled, [TASK])
        instance = self.module.Cleanup(); instance.cloud = [{'role': 'candidate', 'id': 99}]
        instance.receipts_cloud([]); self.assertEqual(self.reconciled, [TASK, TASK])

    def test_proof_storage_failure_or_closed_round_prevents_skipping(self):
        instance = self.module.Cleanup()
        with patch.object(instance.store, 'save', side_effect=PermissionError(SECRET)):
            with self.assertRaises(PermissionError): instance.receipts_cloud([])
        self.assertEqual(self.reconciled, [])
        (self.base / 'closed.json').write_text('{}')
        with self.assertRaisesRegex(ValueError, 'new_round_required'): instance.receipts_cloud([])
        self.assertEqual(self.reconciled, [])

    def test_projections_never_export_unknown_raw_fields(self):
        self.rows[0]['dispatch_evidence']['raw'] = SECRET
        self.rows[0]['replacement_diagnostic'] = {'raw': SECRET}
        value = self.module.controller_evidence({'tasks': self.rows})
        self.assertNotIn(SECRET, json.dumps(value))
        self.assertNotIn('dispatch_evidence', value['tasks'][0])

    def test_fresh_binding_requires_actual_remote_snapshot_call(self):
        (self.base / 'cleanup.py').write_text('class Cleanup:\n def tasks(self): pass\n def receipts_cloud(self,inventory=None): pass\n def collect(self): pass\ndef controller_evidence(value): return value\n')
        (self.base / 'live_cli.php').write_text('<?php $out = \\App\\Services\\ServerMachine\\Cloud\\MachineProvisionTaskEvidence::snapshot($task);')
        (self.base / 'teardown_control.py').write_text("COLLECT_PHP = DB_GUARD + r'\\App\\Services\\ServerMachine\\Cloud\\MachineProvisionTaskEvidence::snapshot($t)'\n")
        cleanup.validate_bindings(self.base)
        (self.base / 'teardown_control.py').write_text("COLLECT_PHP = DB_GUARD + r'legacy task subset'\n")
        with self.assertRaisesRegex(ValueError, 'task_evidence_binding_required'): cleanup.validate_bindings(self.base)

    def test_conflicting_controller_proofs_cannot_select_the_convenient_snapshot(self):
        instance = self.module.Cleanup()
        conflicting = copy.deepcopy(instance.controller['tasks'][0])
        conflicting['dispatch_evidence']['checks']['native_binding'] = False
        instance.controller['tasks'].append(conflicting)
        instance.receipts_cloud([])
        self.assertIn(TASK, self.reconciled)
        self.assertTrue(all(row['classification'] == 'needs_review' for row in instance.store.load(cleanup.REPORT)['records']))

    def test_cleanup_exports_safe_first_error_even_when_original_cli_response_was_lost(self):
        instance = self.module.Cleanup()
        names = cleanup.replacement_diagnostics.CHECKS['native_source']
        diagnostic = {'schema': 1, 'guard': 'native_source', 'checks': {name: name != 'context_matches' for name in names},
                      'failed_fields': ['context_matches']}
        instance.controller['tasks'][0]['replacement_diagnostic'] = diagnostic
        result = instance.collect()
        self.assertTrue(result['ok']); self.assertTrue(result['controller_ready'])
        self.assertTrue(result['replacement_diagnostic_preservation']['independent_local_readback_verified'])
        stored = instance.store.load(cleanup.replacement_diagnostics.REPORT)
        self.assertEqual(stored['diagnostic'], diagnostic)
        self.assertEqual(self.reconciled, [])

    def test_cleanup_preserves_task_first_error_before_private_erasure_when_cli_result_was_lost(self):
        instance = self.module.Cleanup()
        diagnostic = {'schema': 1, 'phase': 'admission', 'category': 'domain_rejection',
            'error_class': 'App\\Services\\ServerMachine\\Cloud\\CloudException', 'cause': 'isolated_manual_cli_required',
            'source_known': True, 'file': 'live_support.php', 'line': 115, 'severity': None}
        instance.controller['tasks'][0].update(reason='internal_failure', task_diagnostic=diagnostic, task_diagnostic_state='verified')
        result = instance.collect()
        self.assertTrue(result['ok'])
        preserved = result['task_diagnostic_preservation']
        self.assertTrue(preserved['ok'])
        self.assertTrue(preserved['records'][0]['independent_local_readback_verified'])
        self.assertEqual(instance.store.load('task-first-error-'+TASK+'.json')['diagnostic'], diagnostic)
        self.assertEqual(instance.store.load('task-error-export-readback.json'), preserved)
        self.assertEqual(self.reconciled, [])

    def test_invalid_task_diagnostic_does_not_prevent_original_cleanup_collection(self):
        instance = self.module.Cleanup()
        instance.controller['tasks'][0].update(reason='internal_failure', task_diagnostic_state='invalid')
        with patch.object(instance.store, 'save', wraps=instance.store.save) as save:
            result = instance.collect()
        self.assertTrue(result['ok'])
        self.assertFalse(result['task_diagnostic_preservation']['ok'])
        self.assertEqual(self.reconciled, [])
        self.assertTrue(save.called)

    def test_projection_does_not_lose_an_internal_failure_when_no_task_diagnostic_was_saved(self):
        raw = copy.deepcopy(self.rows)
        raw[0].update(reason='internal_failure', task_diagnostic=None, task_diagnostic_state='absent')
        safe = self.module.controller_evidence({'tasks': raw})
        self.assertEqual(safe['tasks'][0]['reason'], 'internal_failure')
        instance = self.module.Cleanup(); instance.controller = safe
        result = instance.collect()
        self.assertTrue(result['ok'])
        self.assertFalse(result['task_diagnostic_preservation']['ok'])

    def test_task_first_error_write_denial_does_not_postpone_original_resource_cleanup(self):
        instance = self.module.Cleanup()
        instance.controller['tasks'][0].update(reason='internal_failure', task_diagnostic_state='verified',
            task_diagnostic={'schema': 1, 'phase': 'admission', 'category': 'domain_rejection',
                'error_class': 'App\\Services\\ServerMachine\\Cloud\\CloudException', 'cause': 'isolated_manual_cli_required',
                'source_known': False, 'file': None, 'line': None, 'severity': None})
        original = instance.store.save
        def denied(name, value, exclusive=False):
            if name.startswith('task-first-error-'): raise PermissionError(SECRET)
            return original(name, value, exclusive)
        with patch.object(instance.store, 'save', side_effect=denied):
            result = instance.collect()
        self.assertTrue(result['ok'])
        self.assertFalse(result['task_diagnostic_preservation']['ok'])
        self.assertNotIn(SECRET, json.dumps(result))
        self.assertFalse(list(self.base.glob('task-first-error-*.json')))


if __name__ == '__main__':
    unittest.main()
