import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch

import cli_error_capture
import replacement_diagnostics as diagnostics

HERE = Path(__file__).resolve().parent
PHP = os.environ.get('PHP_BINARY') or shutil.which('php')
RUN = 'keli-accept-20990101-offline-replacement'
SECRET = 'NEVER_EXPORT_PRIVATE_DATA'


def diagnostic(guard='native_source'):
    names = diagnostics.CHECKS[guard]
    return {'schema': 1, 'guard': guard, 'checks': {name: name != names[0] for name in names}, 'failed_fields': [names[0]]}


class DiagnosticTests(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory(prefix='keli-native-first-error-')
        self.addCleanup(temporary.cleanup); self.base = Path(temporary.name)

        def save(name, value, exclusive=False):
            with (self.base / name).open('x' if exclusive else 'w') as stream: json.dump(value, stream)
            (self.base / name).chmod(0o600)

        self.campaign = SimpleNamespace(RUN=RUN, BASE=self.base, save=save,
            load=lambda name: json.loads((self.base / name).read_text()))

    def result(self, value=None):
        return {'ok': True, 'command': 'native-advance', 'run': {'status': 'needs_review', 'members': []},
                'tasks': [{'status': 'failed', 'reason': 'replacement_evidence_changed', 'replacement_diagnostic': value or diagnostic()}]}

    def test_closed_rejection_in_successful_cli_result_is_exported_and_read_back(self):
        result = cli_error_capture.capture_result(self.campaign, self.result())
        self.assertTrue(result['ok'], 'CLI success is not acceptance success')
        self.assertEqual(result['tasks'][0]['status'], 'failed')
        self.assertTrue(result['replacement_diagnostic_preservation']['independent_local_readback_verified'])
        stored = self.campaign.load(diagnostics.REPORT)
        self.assertEqual(stored['diagnostic'], diagnostic())

    def test_all_guards_are_fixed_bool_fields_and_cannot_carry_raw_context(self):
        for guard in diagnostics.CHECKS:
            self.assertEqual(diagnostics.project(diagnostic(guard)), diagnostic(guard))
        for mode in ['guard', 'unknown', 'number', 'failed', 'schema']:
            value = diagnostic()
            if mode == 'guard': value['guard'] = SECRET
            if mode == 'unknown': value['checks'][SECRET] = False
            if mode == 'number': value['checks']['evidence_current'] = 0
            if mode == 'failed': value['failed_fields'] = [SECRET]
            if mode == 'schema': value['schema'] = True
            result = diagnostics.capture_result(self.campaign, self.result(value))
            self.assertFalse(result['replacement_diagnostic_preservation']['ok'])
            self.assertNotIn(SECRET, json.dumps(result))
        self.assertFalse((self.base / diagnostics.REPORT).exists())

    def test_original_first_error_is_not_replaced_by_a_later_guard_failure(self):
        first = self.result(); diagnostics.capture_result(self.campaign, first)
        before = (self.base / diagnostics.REPORT).read_bytes()
        result = diagnostics.capture_result(self.campaign, self.result(diagnostic('replacement_decision')))
        self.assertTrue(result['replacement_diagnostic_preservation']['original_first_error_retained'])
        self.assertEqual((self.base / diagnostics.REPORT).read_bytes(), before)

    def test_storage_failure_or_closed_round_preserves_original_failure_without_claiming_readback(self):
        result = self.result()
        with patch.object(self.campaign, 'save', side_effect=PermissionError(SECRET)):
            safe = diagnostics.capture_result(self.campaign, result)
        self.assertFalse(safe['replacement_diagnostic_preservation']['ok'])
        self.assertEqual(safe['tasks'][0]['reason'], 'replacement_evidence_changed')
        self.assertNotIn(SECRET, json.dumps(safe))
        (self.base / 'closed.json').write_text('{}')
        self.assertFalse(diagnostics.capture_result(self.campaign, result)['replacement_diagnostic_preservation']['ok'])
        self.assertFalse((self.base / diagnostics.REPORT).exists())

    def test_missing_diagnostic_is_not_a_verified_first_error(self):
        result = self.result(); del result['tasks'][0]['replacement_diagnostic']
        safe = diagnostics.capture_result(self.campaign, result)
        self.assertEqual(safe['replacement_diagnostic_preservation'], {'ok': False, 'reason': 'replacement_diagnostic_missing'})

    def test_bad_task_diagnostic_cannot_leak_a_second_unprocessed_member_diagnostic(self):
        result = self.result({'raw': SECRET})
        result['run']['members'] = [{'reason': 'replacement_evidence_changed', 'replacement_diagnostic': {'raw': SECRET}}]
        safe = diagnostics.capture_result(self.campaign, result)
        self.assertNotIn(SECRET, json.dumps(safe))
        self.assertFalse(safe['replacement_diagnostic_preservation']['ok'])

    @unittest.skipUnless(PHP, 'local PHP required')
    def test_php_producer_and_python_projection_agree_on_every_guard(self):
        probe = HERE.parents[1] / 'tests/Fixtures/MachineAcceptance/replacement_diagnostic_probe.php'
        result = subprocess.run([PHP, str(probe)], capture_output=True, timeout=20)
        self.assertEqual(result.returncode, 0, result.stderr[:300])
        value = json.loads(result.stdout)
        self.assertEqual(set(value), set(diagnostics.CHECKS))
        for guard in diagnostics.CHECKS:
            self.assertEqual(diagnostics.project(value[guard]), diagnostic(guard))
        self.assertNotIn(SECRET, result.stdout.decode())


if __name__ == '__main__':
    unittest.main()
