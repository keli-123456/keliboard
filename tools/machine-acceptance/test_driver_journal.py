import contextlib
import ctypes
import errno
import json
import os
from pathlib import Path
import tempfile
import threading
import time
import unittest
from unittest.mock import patch

import driver_journal as journal


RUN = 'keli-local-io-synthetic-01234567'
SECRET = 'PRIVATE_TOKEN_AND_USER_TEXT_MUST_NOT_BE_RECORDED'


def sharing_error(code=5):
    error = PermissionError(errno.EACCES, SECRET, 'C:/private/' + SECRET)
    error.winerror = code
    return error


@contextlib.contextmanager
def windows_handle(path, share=0x1 | 0x2):
    from ctypes import wintypes
    kernel = ctypes.WinDLL('kernel32', use_last_error=True)
    create, close = kernel.CreateFileW, kernel.CloseHandle
    create.argtypes = [wintypes.LPCWSTR, wintypes.DWORD, wintypes.DWORD, ctypes.c_void_p,
                       wintypes.DWORD, wintypes.DWORD, wintypes.HANDLE]
    create.restype = wintypes.HANDLE
    close.argtypes, close.restype = [wintypes.HANDLE], wintypes.BOOL
    handle = create(str(path), 0x80000000, share, None, 3, 0x80, None)
    if handle == ctypes.c_void_p(-1).value:
        raise ctypes.WinError(ctypes.get_last_error())
    closed = False
    def release():
        nonlocal closed
        if not closed:
            if not close(handle):
                raise ctypes.WinError(ctypes.get_last_error())
            closed = True
    try:
        yield release
    finally:
        release()


class JournalCase(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory(prefix='keli-driver-journal-')
        self.addCleanup(temporary.cleanup)
        self.base = Path(temporary.name).resolve()
        self.io = journal.DriverJournal(self.base, RUN)


class JournalTests(JournalCase):
    def test_roundtrip_and_snapshot_replacement(self):
        self.io.save('progress.json', {'n': 1})
        self.io.save('progress.json', {'n': 2})
        self.assertEqual(self.io.load('progress.json'), {'n': 2})
        self.assertFalse(list(self.base.glob('.journal-*')))

    def test_unique_temporary_names_do_not_share_fixed_tmp(self):
        names = []
        replace = os.replace
        def track(source, target):
            names.append(Path(source).name)
            replace(source, target)
        with patch.object(journal.os, 'replace', side_effect=track):
            for n in range(3):
                self.io.save('progress.json', {'n': n})
        self.assertEqual(len(set(names)), 3)
        self.assertTrue(all(name.startswith('.journal-') for name in names))

    def test_exclusive_intent_is_once_and_not_overwritten(self):
        self.io.save('source-dispatch-intent.json', {'attempt': 1}, exclusive=True)
        with self.assertRaises(FileExistsError):
            self.io.save('source-dispatch-intent.json', {'attempt': 2}, exclusive=True)
        self.assertEqual(self.io.load('source-dispatch-intent.json'), {'attempt': 1})

    def test_partial_intent_after_sync_failure_is_spent(self):
        with patch.object(journal.os, 'fsync', side_effect=OSError(errno.ENOSPC, SECRET)):
            with self.assertRaises(journal.JournalIOError) as failed:
                self.io.save('source-dispatch-intent.json', {'attempt': 1}, exclusive=True)
        self.assertEqual(failed.exception.summary['operation'], 'sync_intent')
        self.assertTrue((self.base / 'source-dispatch-intent.json').exists())
        with self.assertRaises(FileExistsError):
            self.io.save('source-dispatch-intent.json', {'attempt': 2}, exclusive=True)

    def test_exclusive_open_denied_never_retries(self):
        self.io.windows = True
        with patch.object(journal.os, 'open', side_effect=sharing_error()) as opened, \
             patch.object(self.io, 'sleep') as slept:
            with self.assertRaises(journal.JournalIOError) as failed:
                self.io.save('source-dispatch-intent.json', {'attempt': 1}, exclusive=True)
        self.assertEqual(opened.call_count, 1)
        slept.assert_not_called()
        self.assertEqual(failed.exception.summary['operation'], 'open_intent')

    def test_transient_windows_replace_retries_only_rename(self):
        self.io.windows = True
        self.io.save('progress.json', {'n': 0})
        replace = os.replace
        attempts = 0
        def locked_then_free(source, target):
            nonlocal attempts
            attempts += 1
            if attempts < 3:
                raise sharing_error(32)
            replace(source, target)
        with patch.object(journal.os, 'replace', side_effect=locked_then_free), \
             patch.object(journal.os, 'fsync', wraps=os.fsync) as synced:
            self.io.save('progress.json', {'n': 1})
        self.assertEqual(attempts, 3)
        self.assertEqual(synced.call_count, 1 if os.name == 'nt' else 2)
        self.assertTrue(self.io.events[-1]['recovered'])
        self.assertEqual(self.io.load('progress.json'), {'n': 1})

    def test_permanent_windows_lock_is_bounded_and_old_snapshot_survives(self):
        self.io.save('progress.json', {'n': 0})
        self.io.windows = True
        with patch.object(journal.os, 'replace', side_effect=sharing_error(5)) as replaced, \
             patch.object(self.io, 'sleep'):
            with self.assertRaises(journal.JournalIOError) as failed:
                self.io.save('progress.json', {'n': 1})
        detail = failed.exception.summary
        self.assertLessEqual(replaced.call_count, journal.REPLACE_ATTEMPTS)
        self.assertEqual((detail['errno'], detail['winerror']), (errno.EACCES, 5))
        self.assertEqual(detail['operation'], 'replace_snapshot')
        self.assertTrue(detail['temporary_file_removed'])
        self.assertTrue(any(frame['module'] == 'driver_journal' and type(frame['line']) is int
                            for frame in detail['callsites']))
        self.assertEqual(self.io.load('progress.json'), {'n': 0})
        self.assertNotIn(SECRET, json.dumps(detail) + str(failed.exception))

    def test_nonsharing_errors_and_posix_denials_do_not_retry(self):
        for windows, error in [(False, PermissionError(errno.EACCES, SECRET)),
                               (True, OSError(errno.ENOSPC, SECRET)),
                               (True, PermissionError(errno.EACCES, SECRET))]:
            self.io.windows = windows
            with self.subTest(windows=windows, code=error.errno), \
                 patch.object(journal.os, 'replace', side_effect=error) as replaced, \
                 patch.object(self.io, 'sleep') as slept:
                with self.assertRaises(journal.JournalIOError):
                    self.io.save('progress.json', {'n': 1})
                replaced.assert_called_once()
                slept.assert_not_called()

    def test_closed_round_is_readonly_and_authority_inputs_cannot_be_written(self):
        self.io.save('progress.json', {'n': 0})
        for name in journal.IMMUTABLE_INPUTS:
            with self.subTest(name=name), self.assertRaises(journal.Rejected):
                self.io.save(name, {'user_approved': True})
        self.io.save('closed.json', {'closed': True}, exclusive=True)
        with self.assertRaises(journal.Rejected):
            self.io.save('progress.json', {'n': 1})
        self.assertEqual(self.io.load('progress.json'), {'n': 0})

    def test_unsafe_names_nonobjects_nan_and_oversize_fail_before_write(self):
        for name in ['../escape.json', '/outside.json', 'C:outside.json', 'sub/a.json', 'data.json:stream', '.env']:
            with self.subTest(name=name), self.assertRaises(journal.Rejected):
                self.io.save(name, {'a': 1})
        for value in [[], {'x': float('nan')}, {'x': 'a' * journal.MAX_BYTES}]:
            with self.subTest(kind=type(value).__name__), self.assertRaises((journal.Rejected, ValueError)):
                self.io.save('progress.json', value)
        self.assertEqual(list(self.base.iterdir()), [])

    def test_duplicate_keys_and_nonfinite_input_are_not_accepted(self):
        for raw in ['{"a":1,"a":2}', '{"a":NaN}', '[]']:
            (self.base / 'progress.json').write_text(raw)
            with self.subTest(raw=raw), self.assertRaises(journal.Rejected):
                self.io.load('progress.json')

    def test_hardlinked_target_and_changed_directory_are_refused(self):
        self.io.save('progress.json', {'n': 0})
        os.link(self.base / 'progress.json', self.base / 'other.json')
        with self.assertRaises(journal.Rejected):
            self.io.save('progress.json', {'n': 1})
        with self.assertRaises(journal.Rejected):
            self.io.load('progress.json')
        self.io.identity = (-1, -1)
        with self.assertRaises(journal.Rejected):
            self.io.save('new.json', {'n': 1})

    def test_safe_error_omits_exception_message_path_and_user_fields(self):
        detail = journal.safe_error(sharing_error(), site='stage', logical_record='progress')
        text = json.dumps(detail)
        self.assertNotIn(SECRET, text)
        self.assertNotIn('C:/private', text)
        self.assertEqual(detail['error_class'], 'PermissionError')
        self.assertEqual((detail['errno'], detail['winerror']), (13, 5))
        error = sharing_error(); error.errno = True; error.winerror = -1
        detail = journal.safe_error(error, site=SECRET, logical_record=SECRET)
        self.assertIsNone(detail['errno']); self.assertIsNone(detail['winerror'])
        self.assertEqual((detail['site'], detail['logical_record']), ('journal', 'other'))

    def test_failure_record_is_exclusive_sanitized_and_has_no_raw_details(self):
        result = self.io.failure_record(sharing_error(), site='stage', logical_record='progress', phase='source_installation')
        self.assertTrue(result['persisted'])
        saved = self.io.load(result['record'])
        self.assertEqual(saved['error']['winerror'], 5)
        self.assertFalse(saved['raw_details_exported'])
        self.assertEqual(saved['phase'], 'source_installation')
        self.assertNotIn(SECRET, json.dumps(saved))
        result = self.io.failure_record(sharing_error(), site='stage', phase=SECRET)
        self.assertEqual(self.io.load(result['record'])['phase'], 'unknown')

    def test_diagnostic_write_failure_falls_back_without_another_probe(self):
        save = self.io.save
        def denied(name, value, exclusive=False):
            if name == 'bootstrap-diagnostics.json':
                raise sharing_error()
            return save(name, value, exclusive)
        result = {'run_id': RUN, 'status': 'collected', 'synthetic': True}
        with patch.object(self.io, 'save', side_effect=denied):
            receipt = self.io.diagnostic_result(result)
        self.assertTrue(receipt['persisted'] and receipt['fallback'])
        self.assertEqual(self.io.load(receipt['record']), result)

    def test_total_write_failure_is_reported_in_memory_not_as_persisted(self):
        with patch.object(self.io, 'save', side_effect=sharing_error()):
            detail = self.io.failure_record(sharing_error(), site='stage')
            result = self.io.diagnostic_result({'run_id': RUN, 'status': 'unavailable'})
        self.assertFalse(detail['persisted'])
        self.assertFalse(result['persisted'])
        self.assertNotIn(SECRET, json.dumps([detail, result]))


@unittest.skipUnless(os.name == 'nt', 'real Windows sharing semantics')
class WindowsSharingTests(JournalCase):
    def test_real_sharing_lock_exhausts_with_numeric_evidence(self):
        self.io.save('progress.json', {'n': 0})
        started = time.monotonic()
        with windows_handle(self.base / 'progress.json'):
            with self.assertRaises(journal.JournalIOError) as failed:
                self.io.save('progress.json', {'n': 1})
        self.assertIn(failed.exception.summary['winerror'], {5, 32, 33})
        self.assertLess(time.monotonic() - started, 2)
        self.assertEqual(self.io.load('progress.json'), {'n': 0})

    def test_real_short_lock_releases_during_bounded_replacement(self):
        self.io.save('progress.json', {'n': 0})
        with windows_handle(self.base / 'progress.json') as release:
            timer = threading.Timer(0.07, release)
            timer.start()
            try:
                self.io.save('progress.json', {'n': 1})
            finally:
                timer.join()
        self.assertEqual(self.io.load('progress.json'), {'n': 1})
        self.assertTrue(self.io.events[-1]['recovered'])

    def test_real_observer_closes_promptly_without_corrupting_snapshots(self):
        self.io.save('progress.json', {'n': 0})
        failures = []
        observed = threading.Event()
        finished = threading.Event()
        def read():
            while not finished.is_set():
                try:
                    value = self.io.load('progress.json')
                    if type(value.get('n')) is not int:
                        failures.append('partial_snapshot')
                    observed.set()
                except OSError:
                    pass  # A temporarily unavailable read is not a successful observation.
                except BaseException:
                    failures.append('invalid_snapshot')
        reader = threading.Thread(target=read)
        reader.start()
        completed = 0
        try:
            self.assertTrue(observed.wait(timeout=2))
            for n in range(1, 51):
                try:
                    self.io.save('progress.json', {'n': n})
                except journal.JournalIOError as error:
                    # A continuous reader can legitimately exhaust the bounded retry window.
                    self.assertEqual(error.summary['operation'], 'replace_snapshot')
                    self.assertIn(error.summary['winerror'], {5, 32, 33})
                    self.assertFalse(error.summary['committed'])
                    self.assertTrue(error.summary['temporary_file_removed'])
                    break
                completed = n
        finally:
            finished.set()
            reader.join(timeout=2)
        self.assertFalse(reader.is_alive())
        self.assertEqual(failures, [])
        self.assertEqual(self.io.load('progress.json'), {'n': completed})
        self.io.save('progress.json', {'n': 51})
        self.assertEqual(self.io.load('progress.json'), {'n': 51})


if __name__ == '__main__':
    unittest.main()
