"""Temporary-branch Linux verification. No installer body or live transport."""
import contextlib
import hashlib
import io
import json
import os
from pathlib import Path
import platform
import shutil
import subprocess
import sys
import tarfile
import tempfile
import unittest
from unittest.mock import patch


HERE = Path(__file__).resolve().parent
ROOT = HERE.parent.parent
BASE = 'aba1ce17a8a4304a14a515bc7ecdff6382fd8178'
MODULES = ['test_staging', 'test_bootstrap_diagnostics', 'test_diagnostic_capture', 'test_driver_journal',
           'test_install_only_runner', 'test_worker.WorkerTests', 'test_driver_linux', 'test_application_bundle']
WINDOWS_ONLY = {
    'test_driver_journal.WindowsSharingTests.test_real_sharing_lock_exhausts_with_numeric_evidence',
    'test_driver_journal.WindowsSharingTests.test_real_short_lock_releases_during_bounded_replacement',
    'test_driver_journal.WindowsSharingTests.test_real_observer_closes_promptly_without_corrupting_snapshots',
    'test_install_only_runner.DriverTests.test_real_progress_handle_lock_does_not_prevent_capture_and_cleanup',
}
FILES = ['application_bundle.py', 'application_bundle_ci.py', 'driver_journal.py', 'install_only_runner.py',
         'test_application_bundle.py', 'test_driver_linux.py', 'test_driver_journal.py', 'test_install_only_runner.py',
         'bootstrap_diagnostics.py', 'diagnostic_capture.py', 'diagnostic_round_runner.py', 'staging.py', 'worker.py',
         'test_staging.py', 'test_bootstrap_diagnostics.py', 'test_diagnostic_capture.py', 'test_worker.py']
DENIED = {'os.system', 'os.exec', 'os.posix_spawn', 'os.fork', 'os.forkpty', 'os.spawn', 'socket.__new__',
          'socket.connect', 'socket.bind', 'socket.getaddrinfo', 'socket.gethostbyname', 'socket.gethostbyaddr',
          'socket.getnameinfo'}
GIT = shutil.which('git')


def hashes():
    paths = [HERE / name for name in FILES] + [ROOT / '.gitattributes']
    return {path.relative_to(ROOT).as_posix(): hashlib.sha256(path.read_bytes()).hexdigest() for path in paths}


def cases(suite):
    for item in suite:
        if isinstance(item, unittest.TestSuite):
            yield from cases(item)
        else:
            yield item


class SafeResult(unittest.TestResult):
    def detail(self, test, error):
        import driver_journal
        return test.id(), driver_journal.safe_error(error[1], site='stage')

    def addError(self, test, error):
        self.errors.append(self.detail(test, error))

    def addFailure(self, test, error):
        self.failures.append(self.detail(test, error))

    def addSubTest(self, test, subtest, error):
        if error is not None:
            target = self.failures if issubclass(error[0], test.failureException) else self.errors
            target.append(self.detail(test, error))

    def addSkip(self, test, reason):
        self.skipped.append((test.id(), 'unexpected_skip'))


def prefix_code(path, name):
    with tarfile.open(path) as archive:
        raw = archive.extractfile(name).read()
    prefix = b''.join(raw.splitlines(keepends=True)[:2])
    if len(prefix.splitlines()) != 2 or not prefix.startswith(b'#!/usr/bin/env bash\n') and not prefix.startswith(b'#!/usr/bin/env bash\r\n'):
        raise RuntimeError('unexpected_public_prefix')
    environment = {key: value for key, value in os.environ.items() if key not in {'BASH_ENV', 'ENV', 'SHELLOPTS', 'BASHOPTS'}}
    result = subprocess.run(['/bin/bash', '--noprofile', '--norc', '-s'], input=prefix, capture_output=True,
                            env=environment, timeout=5)
    return {'exit_code': result.returncode, 'prefix_bytes': len(prefix), 'crlf_lines': prefix.count(b'\r\n'),
            'installer_body_executed': False}


def full_repository_check():
    import application_bundle as bundle
    with tempfile.TemporaryDirectory(prefix='keli-public-archive-') as temporary:
        output = Path(temporary).resolve()
        output.chmod(0o700)
        legacy = output / 'legacy.tar.gz'
        process = subprocess.run([GIT, *bundle.GIT_OPTIONS, '-c', 'core.autocrlf=true', '-c', 'core.eol=crlf',
                                  '-C', str(ROOT), 'archive', '--format=tar.gz',
                                  '--output=' + str(legacy), BASE], capture_output=True, timeout=90)
        if process.returncode:
            raise RuntimeError('legacy_public_export_failed')
        try:
            bundle.inspect_archive(legacy)
        except bundle.BundleError as error:
            rejected = str(error) == 'bundle_shell_cr_refused'
        else:
            rejected = False
        builds = []
        for name, eol, autocrlf in [('windows', 'crlf', 'true'), ('linux', 'lf', 'false')]:
            environment = {'GIT_CONFIG_COUNT': '2', 'GIT_CONFIG_KEY_0': 'core.autocrlf', 'GIT_CONFIG_VALUE_0': autocrlf,
                           'GIT_CONFIG_KEY_1': 'core.eol', 'GIT_CONFIG_VALUE_1': eol}
            with patch.dict(os.environ, environment):
                builds.append(bundle.build_archive(ROOT, BASE, output / (name + '.tar.gz')))
        current = bundle.build_archive(ROOT, os.environ['GITHUB_SHA'], output / 'current.tar.gz')
        codes = [prefix_code(output / (name + '.tar.gz'), bundle.REQUIRED_SHELLS[1])
                 for name in ['legacy', 'windows', 'linux', 'current']]
        good = rejected and builds[0]['archive'] == builds[1]['archive'] and [row['exit_code'] for row in codes] == [2, 0, 0, 0]
        return {'ok': good, 'public_base_commit': BASE, 'legacy_archive_rejected': rejected,
                'host_profiles_same_archive': builds[0]['archive'] == builds[1]['archive'],
                'canonical_archive': builds[0]['archive'], 'current_archive': current['archive'],
                'shells': current['shells'], 'bash_prefixes': dict(zip(['legacy', 'canonical_windows', 'canonical_linux', 'current'], codes)),
                'temporary_artifacts_removed_on_exit': True}


def main():
    value = {'schema': 1, 'ok': False, 'scope': 'offline_application_archive_linux', 'commit': os.environ.get('GITHUB_SHA'),
             'python': platform.python_version(), 'platform': sys.platform, 'uid': os.geteuid(),
             'forbidden_attempts': {}, 'allowed_local_processes': {}, 'cloud_ssh_dns': 'synthetic_only',
             'installer_body_executed': False, 'live_round_loaded': False}
    before = hashes()
    value['source_digests'] = before
    if sys.platform != 'linux' or value['uid'] == 0:
        value['reason'] = 'nonroot_linux_required'
    else:
        def guard(event, arguments):
            allowed = False
            if event == 'subprocess.Popen':
                args = arguments[1]
                if isinstance(args, (list, tuple)) and args:
                    if args[0] == GIT and not any(str(arg).startswith('--remote') for arg in args):
                        allowed = any(op in args for op in ['init', 'add', 'update-index', 'commit', 'rev-parse', 'config', 'cat-file', 'archive'])
                        kind = 'local_git'
                    elif list(args) == ['/bin/bash', '--noprofile', '--norc', '-s']:
                        allowed, kind = True, 'two_line_bash_prefix'
                if allowed:
                    value['allowed_local_processes'][kind] = value['allowed_local_processes'].get(kind, 0) + 1
            if event in DENIED or event == 'subprocess.Popen' and not allowed:
                value['forbidden_attempts'][event] = value['forbidden_attempts'].get(event, 0) + 1
                raise RuntimeError('external_dispatch_forbidden')
        sys.addaudithook(guard)
        captured = io.StringIO()
        try:
            with contextlib.redirect_stdout(captured), contextlib.redirect_stderr(captured):
                all_cases = list(cases(unittest.defaultTestLoader.loadTestsFromNames(MODULES)))
                excluded = sorted(case.id() for case in all_cases if case.id() in WINDOWS_ONLY)
                result = SafeResult()
                unittest.TestSuite(case for case in all_cases if case.id() not in WINDOWS_ONLY).run(result)
                artifact = full_repository_check()
            value.update(tests_run=result.testsRun, failures=result.failures, errors=result.errors, skipped=result.skipped,
                         windows_native_excluded=excluded, archive_verification=artifact, source_unchanged=hashes() == before,
                         captured_output_characters=len(captured.getvalue()))
            value['ok'] = (result.wasSuccessful() and not result.skipped and len(excluded) == 4 and result.testsRun == 139
                           and artifact['ok'] and value['source_unchanged'] and not value['forbidden_attempts'])
        except BaseException as error:
            import driver_journal
            value.update(reason='offline_ci_unavailable', error=driver_journal.safe_error(error, site='stage'))
    destination = ROOT / 'artifacts' / 'application-bundle-ci.json'
    destination.parent.mkdir(exist_ok=True)
    destination.write_text(json.dumps(value, ensure_ascii=True, indent=2) + '\n', encoding='utf-8')
    print(json.dumps({key: value.get(key) for key in ['ok', 'tests_run', 'reason', 'forbidden_attempts']}))
    return 0 if value['ok'] else 1


if __name__ == '__main__':
    raise SystemExit(main())
