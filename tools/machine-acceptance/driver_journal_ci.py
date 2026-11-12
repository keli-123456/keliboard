"""Linux-only synthetic CI entry. No transport, credentials or live-run loader."""
import contextlib
import hashlib
import io
import json
import os
from pathlib import Path
import platform
import re
import sys
import unittest


HERE = Path(__file__).resolve().parent
ROOT = HERE.parent.parent
MODULES = ['test_staging', 'test_bootstrap_diagnostics', 'test_diagnostic_capture',
           'test_driver_journal', 'test_install_only_runner', 'test_worker.WorkerTests', 'test_driver_linux']
WINDOWS_ONLY = {
    'test_driver_journal.WindowsSharingTests.test_real_sharing_lock_exhausts_with_numeric_evidence',
    'test_driver_journal.WindowsSharingTests.test_real_short_lock_releases_during_bounded_replacement',
    'test_driver_journal.WindowsSharingTests.test_real_observer_closes_promptly_without_corrupting_snapshots',
    'test_install_only_runner.DriverTests.test_real_progress_handle_lock_does_not_prevent_capture_and_cleanup',
}
FILES = ['driver_journal.py', 'install_only_runner.py', 'driver_journal_ci.py', 'test_driver_linux.py',
         'test_driver_journal.py', 'test_install_only_runner.py', 'bootstrap_diagnostics.py', 'diagnostic_capture.py',
         'diagnostic_round_runner.py', 'staging.py', 'worker.py', 'test_staging.py',
         'test_bootstrap_diagnostics.py', 'test_diagnostic_capture.py', 'test_worker.py']
DENIED = {'subprocess.Popen', 'os.system', 'os.exec', 'os.posix_spawn', 'os.fork', 'os.forkpty',
          'socket.__new__', 'socket.connect', 'socket.bind', 'socket.getaddrinfo', 'socket.gethostbyname',
          'socket.gethostbyaddr', 'socket.getnameinfo', 'os.spawn'}


class Blocked(RuntimeError):
    pass


def hashes():
    return {name: hashlib.sha256((HERE / name).read_bytes()).hexdigest() for name in FILES}


def cases(suite):
    for value in suite:
        if isinstance(value, unittest.TestSuite):
            yield from cases(value)
        else:
            yield value


def test_name(test):
    test = getattr(test, 'test_case', test)
    parts = [type(test).__module__, type(test).__name__, getattr(test, '_testMethodName', 'unknown')]
    return '.'.join(part if isinstance(part, str) and re.fullmatch(r'[A-Za-z_][A-Za-z0-9_]{0,125}', part)
                    else 'unknown' for part in parts)


class SafeResult(unittest.TestResult):
    def detail(self, test, error):
        import driver_journal
        return test_name(test), driver_journal.safe_error(error[1], site='stage')

    def addError(self, test, error):
        self.errors.append(self.detail(test, error))

    def addFailure(self, test, error):
        self.failures.append(self.detail(test, error))

    def addSubTest(self, test, subtest, error):
        if error is not None:
            target = self.failures if issubclass(error[0], test.failureException) else self.errors
            target.append(self.detail(test, error))

    def addSkip(self, test, reason):
        self.skipped.append((test_name(test), 'unexpected_skip'))


def main():
    value = {'schema': 1, 'ok': False, 'scope': 'linux_synthetic_driver_io_only',
             'commit': os.environ.get('GITHUB_SHA'), 'python': platform.python_version(),
             'platform': sys.platform, 'uid': os.geteuid() if hasattr(os, 'geteuid') else None,
             'run_attempt': os.environ.get('GITHUB_RUN_ATTEMPT'), 'external_attempts': {},
             'cloud_ssh_dns': 'synthetic_only', 'live_round_loaded': False}
    before = hashes()
    value['source_digests'] = before
    if sys.platform != 'linux' or value['uid'] in {None, 0}:
        value['reason'] = 'nonroot_linux_required'
    else:
        def guard(event, arguments):
            if event in DENIED:
                value['external_attempts'][event] = value['external_attempts'].get(event, 0) + 1
                raise Blocked('external_dispatch_forbidden')
        sys.addaudithook(guard)
        sys.path.insert(0, str(HERE))
        output = io.StringIO()
        try:
            with contextlib.redirect_stdout(output), contextlib.redirect_stderr(output):
                all_cases = list(cases(unittest.defaultTestLoader.loadTestsFromNames(MODULES)))
                excluded = sorted(case.id() for case in all_cases if case.id() in WINDOWS_ONLY)
                suite = unittest.TestSuite(case for case in all_cases if case.id() not in WINDOWS_ONLY)
                result = SafeResult()
                suite.run(result)
            value.update(tests_run=result.testsRun, failures=result.failures, errors=result.errors,
                         skipped=result.skipped, windows_native_excluded=excluded,
                         captured_output_characters=len(output.getvalue()), source_unchanged=hashes() == before)
            value['ok'] = (result.wasSuccessful() and not result.skipped and len(excluded) == 4
                           and result.testsRun == 122 and not value['external_attempts'] and value['source_unchanged'])
        except BaseException as error:
            import driver_journal
            value.update(reason='synthetic_ci_unavailable', error=driver_journal.safe_error(error, site='stage'))
    destination = ROOT / 'artifacts' / 'driver-journal-ci.json'
    destination.parent.mkdir(exist_ok=True)
    destination.write_text(json.dumps(value, ensure_ascii=True, indent=2) + '\n', encoding='utf-8')
    print(json.dumps({key: value.get(key) for key in ['ok', 'platform', 'uid', 'tests_run', 'reason', 'external_attempts']}))
    return 0 if value['ok'] else 1


if __name__ == '__main__':
    raise SystemExit(main())
