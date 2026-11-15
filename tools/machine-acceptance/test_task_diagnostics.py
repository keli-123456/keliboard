import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import Mock

from driver_journal import DriverJournal
import task_diagnostics as diagnostic


RUN = 'keli-accept-20990101-task-offline'
ID = '10000000-0000-4000-8000-000000000001'
SECRET = 'private-sentinel-never-export'
PHP = os.environ.get('PHP_BINARY') or shutil.which('php')
ROOT = Path(__file__).resolve().parents[2]


def fields():
    return {'schema': 1, 'phase': 'admission', 'category': 'domain_rejection',
        'error_class': 'App\\Services\\ServerMachine\\Cloud\\CloudException', 'cause': 'isolated_manual_cli_required',
        'source_known': True, 'file': 'live_support.php', 'line': 115, 'severity': None}


def row():
    return {'id': ID, 'label': RUN+'-candidate', 'status': 'needs_review', 'reason': 'internal_failure',
        'task_diagnostic': fields(), 'task_diagnostic_state': 'verified'}


class TaskDiagnosticTests(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory(prefix='keli-task-first-error-')
        self.addCleanup(temporary.cleanup)
        self.base = Path(temporary.name).resolve()
        self.journal = DriverJournal(self.base, RUN)
        self.ctx = SimpleNamespace(BASE=self.base, RUN=RUN, save=self.journal.save, load=self.journal.load)

    def capture(self, task=None):
        return diagnostic.capture_result(self.ctx, {'ok': True, 'command': 'process', 'tasks': [row() if task is None else task]})

    def test_nested_ok_response_still_preserves_task_failure_and_independent_readback(self):
        value = self.capture()
        self.assertTrue(value['ok'])
        saved = value['task_diagnostic_preservation']
        self.assertTrue(saved['ok'])
        self.assertTrue(saved['records'][0]['independent_local_readback_verified'])
        self.assertEqual(self.journal.load('task-first-error-'+ID+'.json')['diagnostic'], fields())

    def test_different_later_error_cannot_overwrite_original_even_during_cleanup(self):
        self.capture()
        path = self.base/('task-first-error-'+ID+'.json')
        first = path.read_bytes()
        later = row(); later['task_diagnostic']['cause'] = 'internal_failure'
        value = self.capture(later)
        self.assertTrue(value['task_diagnostic_preservation']['records'][0]['original_first_error_retained'])
        self.assertEqual(path.read_bytes(), first)
        self.journal.save('cleanup-controller-export.json', {'controller': {'tasks': [later]}})
        self.assertFalse(diagnostic.audit_preservation(self.ctx)['ok'])
        self.assertEqual(path.read_bytes(), first)

    def test_storage_denial_does_not_replace_task_state_or_claim_preservation(self):
        self.ctx.save = Mock(side_effect=PermissionError(SECRET))
        value = self.capture()
        self.assertFalse(value['task_diagnostic_preservation']['ok'])
        self.assertEqual(value['tasks'][0]['status'], 'needs_review')
        self.assertEqual(value['tasks'][0]['reason'], 'internal_failure')
        self.assertEqual(value['tasks'][0]['task_diagnostic'], fields())
        self.assertNotIn(SECRET, json.dumps(value))
        self.assertFalse(list(self.base.glob('task-first-error-*.json')))

    def test_missing_invalid_foreign_or_secret_diagnostics_cannot_be_saved(self):
        cases = [dict(row(), task_diagnostic=None), dict(row(), task_diagnostic_state='invalid'),
            dict(row(), id='foreign'), dict(row(), label='foreign-candidate')]
        for change in [{'schema': True}, {'file': '/root/'+SECRET}, {'line': True}, {'line': -1},
                       {'error_class': SECRET}, {'cause': SECRET}, {'phase': SECRET}, {'message': SECRET}, {'trace': []}]:
            case = row(); case['task_diagnostic'].update(change); cases.append(case)
        for case in cases:
            value = self.capture(case)
            self.assertFalse(value['task_diagnostic_preservation']['ok'])
            self.assertNotIn(SECRET, json.dumps(value))
        self.assertFalse(list(self.base.glob('task-first-error-*.json')))

    def test_readback_mismatch_cannot_pass_final_audit(self):
        self.capture()
        self.journal.save('resource-ledger.json', {'tasks': [row()]})
        self.assertTrue(diagnostic.audit_preservation(self.ctx)['ok'])
        self.ctx.load = Mock(side_effect=lambda name: {'tasks': [row()]} if name=='resource-ledger.json' else {})
        self.assertFalse(diagnostic.audit_preservation(self.ctx)['ok'])

    def test_unconfirmed_capture_without_task_rows_is_still_a_failed_final_audit(self):
        self.journal.save('latest-process.json', {'ok': True, 'tasks': [], 'task_diagnostic_preservation': {'ok': False}})
        self.assertFalse(diagnostic.audit_preservation(self.ctx)['ok'])

    def test_failed_cleanup_readback_cannot_be_hidden_by_valid_original_task_evidence(self):
        self.capture()
        self.journal.save('resource-ledger.json', {'tasks': [row()]})
        self.journal.save('cleanup-result.json', {'collection': {'task_diagnostic_preservation': {'ok': False}}})
        self.assertFalse(diagnostic.audit_preservation(self.ctx)['ok'])

    def test_summary_or_orphan_first_error_without_matching_task_cannot_claim_verified(self):
        self.journal.save('task-error-export-readback.json', {'ok': True, 'records': []})
        self.assertFalse(diagnostic.audit_preservation(self.ctx)['ok'])
        (self.base/'task-error-export-readback.json').unlink()
        self.capture()
        self.assertFalse(diagnostic.audit_preservation(self.ctx)['ok'])
    def test_missing_terminal_diagnostic_is_not_treated_as_successful_evidence_collection(self):
        bad = row(); bad.pop('task_diagnostic'); bad.pop('task_diagnostic_state')
        self.journal.save('resource-ledger.json', {'tasks': [bad]})
        self.assertFalse(diagnostic.audit_preservation(self.ctx)['ok'])

    def test_closed_round_forbids_capture_but_existing_evidence_can_be_audited_read_only(self):
        self.capture()
        self.journal.save('resource-ledger.json', {'tasks': [row()]})
        self.journal.save('closed.json', {'run_id': RUN})
        self.assertFalse(self.capture()['task_diagnostic_preservation']['ok'])
        self.ctx.save = Mock(side_effect=AssertionError('audit_must_not_write'))
        self.assertTrue(diagnostic.audit_preservation(self.ctx)['ok'])
        self.ctx.save.assert_not_called()

    @unittest.skipUnless(os.name=='posix', 'real file ownership and links require Linux')
    def test_linked_first_error_is_not_read_or_overwritten(self):
        other = self.base/'private.json'; other.write_text(json.dumps({'message':SECRET}))
        target = self.base/('task-first-error-'+ID+'.json'); target.symlink_to(other)
        value = self.capture()
        self.assertFalse(value['task_diagnostic_preservation']['ok'])
        self.assertNotIn(SECRET,json.dumps(value))

    @unittest.skipUnless(PHP and (ROOT/'vendor/autoload.php').is_file(), 'local PHP and locked vendor required')
    def test_php_python_whitelists_and_negative_projections_are_identical(self):
        cases = [fields()]
        for change in [{'schema': True}, {'phase': []}, {'error_class': SECRET}, {'line': True}, {'cause': SECRET},
                       {'severity': 'warning'}, {'file': '/root/'+SECRET}, {'source_known': False}, {'raw_log': SECRET}]:
            cases.append(dict(fields(), **change))
        expected = []
        for value in cases:
            try: diagnostic.project(value); expected.append(True)
            except ValueError: expected.append(False)
        process = subprocess.run([PHP,str(ROOT/'tests/Fixtures/MachineAcceptance/task_diagnostic_projection.php')],
            input=json.dumps(cases).encode(), capture_output=True, timeout=20)
        self.assertEqual(process.returncode,0,process.stderr[:500])
        value=json.loads(process.stdout)
        self.assertEqual(set(value['classes']),diagnostic.CLASSES)
        self.assertEqual(set(value['files']),diagnostic.FILES)
        self.assertEqual(set(value['phases']),diagnostic.PHASES)
        self.assertEqual(set(value['causes']),diagnostic.CAUSES)
        self.assertEqual(value['accepted'],expected)


if __name__=='__main__':
    unittest.main()
