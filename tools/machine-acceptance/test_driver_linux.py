"""Real POSIX denials in disposable files; every provider/transport stays fake."""
import errno
import json
import os
import stat
import unittest
from unittest.mock import patch

import driver_journal as journal
from test_driver_journal import JournalCase, RUN
from test_install_only_runner import SyntheticDriverCase


@unittest.skipUnless(os.name == 'posix', 'actual POSIX denial requires Linux')
class LinuxJournalTests(JournalCase):
    def setUp(self):
        super().setUp()
        self.assertNotEqual(os.geteuid(), 0, 'non-root runner required for kernel permission denials')

    def test_real_readonly_directory_preserves_snapshot_and_does_not_retry(self):
        self.io.save('progress.json', {'n': 0})
        self.base.chmod(0o500)
        try:
            with patch.object(self.io, 'sleep') as slept, self.assertRaises(journal.JournalIOError) as failed:
                self.io.save('progress.json', {'n': 1})
        finally:
            self.base.chmod(0o700)
        slept.assert_not_called()
        detail = failed.exception.summary
        self.assertEqual((detail['errno'], detail['winerror']), (errno.EACCES, None))
        self.assertEqual(detail['operation'], 'create_temporary')
        self.assertEqual(detail['attempts'], 1)
        self.assertEqual(self.io.load('progress.json'), {'n': 0})
        self.assertFalse(list(self.base.glob('.journal-*')))
        report = self.io.failure_record(failed.exception, site='stage', phase='source_installation')
        self.assertTrue(report['persisted'])
        self.assertNotIn(str(self.base), json.dumps(self.io.load(report['record'])))

    def test_real_unreadable_record_is_unknown_with_durable_numeric_error(self):
        self.io.save('progress.json', {'n': 0})
        target = self.base / 'progress.json'
        target.chmod(0)
        try:
            with self.assertRaises(PermissionError) as failed:
                self.io.load('progress.json')
        finally:
            target.chmod(0o600)
        report = self.io.failure_record(failed.exception, site='journal', logical_record='progress')
        saved = self.io.load(report['record'])
        self.assertEqual(saved['error']['errno'], errno.EACCES)
        self.assertEqual(saved['error']['error_class'], 'PermissionError')
        self.assertNotIn(str(self.base), json.dumps(saved))
        self.assertEqual(self.io.load('progress.json'), {'n': 0})

    def test_private_modes_and_existing_diagnostic_use_unique_persisted_fallback(self):
        previous = os.umask(0)
        try:
            self.io.save('bootstrap-diagnostics.json', {'run_id': RUN, 'reserved': True}, exclusive=True)
            self.io.save('progress.json', {'n': 0})
            report = self.io.diagnostic_result({'run_id': RUN, 'status': 'unavailable'})
        finally:
            os.umask(previous)
        self.assertTrue(report['persisted'] and report['fallback'])
        self.assertEqual(self.io.load('bootstrap-diagnostics.json'), {'run_id': RUN, 'reserved': True})
        self.assertEqual(self.io.load(report['record'])['status'], 'unavailable')
        for path in self.base.glob('*.json'):
            self.assertEqual(stat.S_IMODE(path.stat().st_mode), 0o600)


@unittest.skipUnless(os.name == 'posix', 'actual POSIX denial requires Linux')
class LinuxDriverTests(SyntheticDriverCase):
    def setUp(self):
        super().setUp()
        self.assertNotEqual(os.geteuid(), 0, 'non-root runner required for kernel permission denials')

    def real_source_progress_denial(self, command, **kwargs):
        value = self.command(command, **kwargs)
        if command == 'process':
            self.base.chmod(0o500)
            try:
                self.runner.journal.save('progress.json', {'phase': 'source_installation'})
            finally:
                self.base.chmod(0o700)
        return value

    def test_kernel_denial_keeps_first_error_and_persists_diagnostics_before_cleanup(self):
        self.operator.command.side_effect = self.real_source_progress_denial
        result = self.runner.run()
        self.assertFalse(result['ok'])
        self.assertEqual(result['driver_failure']['errno'], errno.EACCES)
        self.assertEqual(result['driver_failure']['site'], 'stage')
        self.assertEqual(result['driver_failure']['operation'], 'create_temporary')
        self.assertTrue(result['failure_storage']['persisted'])
        self.assertTrue(result['diagnostic_storage']['persisted'])
        first = self.read(result['failure_storage']['record'])
        self.assertEqual(first['phase'], 'source_installation')
        self.assertEqual(self.read('round-outcome.json')['failure'], first['error'])
        self.assertEqual(self.read('bootstrap-diagnostics.json')['status'], 'collected')
        self.assertEqual(self.events.count('create:source'), 1)
        self.assertEqual(self.events.count('create:control'), 1)
        self.node.execute.assert_called_once()
        self.cleanup.main.assert_called_once()
        self.assertLess(self.events.index('probe'), self.events.index('cleanup'))
        self.assertTrue(result['cleanup_complete'])
        self.assertNotIn(str(self.base), json.dumps(result))
        self.assert_private_absent(result)

    def test_second_kernel_denial_during_cleanup_never_masks_original_error(self):
        self.operator.command.side_effect = self.real_source_progress_denial
        def denied_cleanup():
            self.base.chmod(0o500)
            try:
                return self.clean()
            finally:
                self.base.chmod(0o700)
        self.cleanup.main.side_effect = denied_cleanup
        result = self.runner.run()
        self.assertFalse(result['ok'])
        self.assertFalse(result['final_checks']['cleanup'])
        self.assertEqual(result['driver_failure']['site'], 'stage')
        self.assertEqual(result['driver_failure']['errno'], errno.EACCES)
        saved = self.read('round-outcome.json')
        self.assertEqual(saved['phase'], 'source_installation')
        self.assertTrue(any(row['site'] == 'cleanup' and row['error']['errno'] == errno.EACCES
                            for row in saved['local_errors']))
        self.cleanup.main.assert_called_once()
        self.node.execute.assert_called_once()
        self.assertTrue(result['diagnostic_storage']['persisted'])
        self.assertFalse((self.base / 'cleanup-result.json').exists())
        self.assert_private_absent(result)


if __name__ == '__main__':
    unittest.main()
