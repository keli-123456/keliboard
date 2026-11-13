"""Real iputils only inside the dedicated loopback-only Linux test namespace."""
from contextlib import redirect_stderr, redirect_stdout
import json
import os
from pathlib import Path
import stat
import subprocess
import sys
import time
import traceback
import types
import unittest
from unittest.mock import patch

probe = None
CASES = {'echo', 'no_reply', 'four_no_reply_targets', 'legacy_deadline_reference',
         'forced_timeout_reaps_real_ping', 'permission_denied_is_not_packet_loss'}
FILES = {'test_entry_probe_iputils.py', 'entry_probe_diagnostics.py', 'bootstrap_diagnostics.py'}
ERROR_CLASSES = {'AssertionError', 'AttributeError', 'FileNotFoundError', 'ImportError', 'ModuleNotFoundError',
                 'OSError', 'PermissionError', 'RuntimeError', 'SyntaxError', 'TypeError', 'ValueError',
                 'KeyboardInterrupt', 'SystemExit', 'TimeoutExpired'}
MAX_OUTPUT = 262144


class DiscardOutput:
    def __init__(self):
        self.count = 0

    def write(self, value):
        self.count = min(MAX_OUTPUT + 1, self.count + len(value))
        return len(value)

    def flush(self):
        pass


def load_probe():
    # Explicit reads work in a traversable 0711 stage without directory listing.
    root = Path(__file__).resolve().parent
    previous = {name: sys.modules.get(name) for name in ('bootstrap_diagnostics', 'entry_probe_diagnostics')}
    loaded = {}
    try:
        for name in previous:
            path = root / (name + '.py')
            info = path.lstat()
            if not stat.S_ISREG(info.st_mode) or (os.name == 'posix' and info.st_mode & 0o022) or not 0 < info.st_size <= 1048576:
                raise PermissionError(13, 'unsafe_helper')
            module = types.ModuleType(name)
            module.__file__ = str(path)
            module.__package__ = ''
            sys.modules[name] = module
            exec(compile(path.read_bytes(), str(path), 'exec'), module.__dict__)
            loaded[name] = module
        return loaded['entry_probe_diagnostics']
    finally:
        for name, original in previous.items():
            if original is None:
                sys.modules.pop(name, None)
            else:
                sys.modules[name] = original


def failure_detail(error):
    root = Path(__file__).resolve().parent
    frames = [{'file': Path(frame.filename).name, 'line': frame.lineno}
              for frame in traceback.extract_tb(error[2])
              if Path(frame.filename).resolve() in {root / name for name in FILES}][-6:]
    exception = error[1]
    if isinstance(exception, SyntaxError) and exception.filename and Path(exception.filename).resolve() in {
            root / name for name in FILES} and type(exception.lineno) is int and 1 <= exception.lineno <= 100000:
        frames = (frames + [{'file': Path(exception.filename).name, 'line': exception.lineno}])[-6:]
    detail = {'error_class': error[0].__name__ if error[0].__name__ in ERROR_CLASSES else 'Exception',
              'frames': [frame for frame in frames if 1 <= frame['line'] <= 100000]}
    number = getattr(exception, 'errno', None)
    if type(number) is int and 0 <= number <= 65535:
        detail['errno'] = number
    return detail


@unittest.skipUnless(sys.platform == 'linux' and os.environ.get('KELI_IPUTILS_ISOLATED') == '1',
                     'requires the dedicated real-iputils namespace runner')
class RealIputilsTests(unittest.TestCase):
    def setUp(self):
        self.assertNotEqual(os.geteuid(), 0)
        interfaces = {line.split(':', 1)[0].strip() for line in Path('/proc/self/net/dev').read_text().splitlines()[2:] if ':' in line}
        self.assertEqual(interfaces, {'lo'})
        status = dict(line.split(':', 1) for line in Path('/proc/self/status').read_text().splitlines() if ':' in line)
        self.assertEqual(status['NoNewPrivs'].strip(), '1')
        for key in ['CapInh', 'CapPrm', 'CapEff', 'CapBnd', 'CapAmb']:
            self.assertEqual(int(status[key].strip(), 16), 0)
        self.assertEqual(Path('/proc/net/route').read_text().strip().splitlines(),
                         [Path('/proc/net/route').read_text().strip().splitlines()[0]])
        self.children = []
        self.real_popen = subprocess.Popen
        self.addCleanup(self.reap)
        self.summary = {'expected_packets': 0, 'measurements': []}
        self.ping = Path(os.environ['KELI_IPUTILS_BINARY']).resolve()
        self.assertTrue(str(self.ping).startswith('/tmp/keli-iputils-namespace-'))
        self.assertEqual(self.ping.stat().st_uid, 0)
        self.assertEqual(self.ping.stat().st_mode & 0o222, 0)
        self.assertNotIn('security.capability', os.listxattr(self.ping))

    def reap(self):
        for child in self.children:
            if child.poll() is None:
                child.kill()
                child.wait(timeout=2)
            if child.stdout is not None and not child.stdout.closed:
                child.stdout.close()

    def measure(self, target='127.0.0.2', *, timeout=4, legacy=False):
        # The production collector still requires public addresses. This tests its pure argv builder.
        args = probe.ping_arguments(str(self.ping), target, '127.0.0.1', 2)
        if legacy:
            args[-2:-2] = ['-w', '3']
        def launch(*a, **kw):
            try:
                child = self.real_popen(*a, **kw)
            except OSError as error:
                self.summary['launch_error'] = {'error_class': type(error).__name__, 'errno': error.errno}
                raise
            self.children.append(child)
            return child
        start = time.monotonic()
        with patch.object(probe.subprocess, 'Popen', side_effect=launch):
            result = probe.command(args, timeout)
        row = probe.classify_ping(result, target)
        self.summary['measurements'].append(row)
        self.assertLess(time.monotonic() - start, 5)
        self.assertTrue(self.children, 'real iputils child did not start')
        self.assertIsNotNone(self.children[-1].returncode)
        self.assertTrue(self.children[-1].stdout.closed)
        with self.assertRaises(ChildProcessError):
            os.waitpid(self.children[-1].pid, os.WNOHANG)
        return row

    def test_echo(self):
        row = self.measure()
        self.assertEqual((row['classification'], row['sent'], row['received'], row['exit_code']), ('echo_reply', 1, 1, 0))
        self.summary['expected_packets'] = 1

    def test_no_reply(self):
        row = self.measure()
        self.assertEqual((row['classification'], row['sent'], row['received'], row['exit_code']), ('no_echo_by_deadline', 1, 0, 1))
        self.summary['expected_packets'] = 1

    def test_four_no_reply_targets(self):
        for target in ['127.0.0.2', '127.0.0.3', '127.0.0.4', '127.0.0.5']:
            row = self.measure(target)
            self.assertEqual((row['classification'], row['sent'], row['received']), ('no_echo_by_deadline', 1, 0))
        self.summary['expected_packets'] = 4

    def test_legacy_deadline_reference(self):
        row = self.measure(legacy=True)
        self.assertEqual(row['exit_code'], 1)
        self.assertIsNotNone(row['sent'])
        self.assertGreaterEqual(row['sent'], 1)
        self.assertLessEqual(row['sent'], 4)
        self.assertEqual(row['received'], 0)
        self.assertEqual(row['classification'], 'packet_budget_exceeded' if row['sent'] > 1 else 'no_echo_by_deadline')
        self.summary['expected_packets'] = row['sent']
        self.summary['legacy_extra_packets_reproduced'] = row['sent'] > 1

    def test_forced_timeout_reaps_real_ping(self):
        row = self.measure(timeout=0.2)
        self.assertEqual((row['state'], row['classification'], row['sent'], row['received']), ('timeout', 'unavailable', None, None))
        self.assertLess(self.children[-1].returncode, 0)
        self.summary['expected_packets'] = 1

    def test_permission_denied_is_not_packet_loss(self):
        row = self.measure()
        self.assertEqual(row['classification'], 'execution_error')
        self.assertIn('permission_denied', row['error_categories'])
        self.assertIsNone(row['received'])
        self.summary['expected_packets'] = 0


class SafeResult(unittest.TestResult):
    def detail(self, error):
        return failure_detail(error)

    def addFailure(self, test, error):
        self.failures.append((test, self.detail(error)))

    def addError(self, test, error):
        self.errors.append((test, self.detail(error)))


def main(argv=None):
    global probe
    arguments = sys.argv[1:] if argv is None else argv
    case = arguments[0] if len(arguments) == 1 and arguments[0] in CASES else None
    value = {'ok': False, 'case': case, 'tests': 0, 'failures': 0, 'errors': 0, 'skipped': 0,
             'expected_packets': 0, 'measurements': []}
    stdout, stderr = DiscardOutput(), DiscardOutput()
    previous = probe
    phase = 'child_arguments'
    try:
        with redirect_stdout(stdout), redirect_stderr(stderr):
            if case is None:
                raise ValueError('fixed_case_required')
            phase = 'child_import'
            probe = load_probe()
            phase = 'child_test'
            test = RealIputilsTests('test_' + case)
            result = SafeResult()
            unittest.TestSuite([test]).run(result)
            value.update(ok=result.wasSuccessful() and not result.skipped, tests=result.testsRun,
                failures=len(result.failures), errors=len(result.errors), skipped=len(result.skipped),
                **getattr(test, 'summary', {}))
            details = [detail for _, detail in result.failures + result.errors]
            if details:
                value['failure_details'] = details
                value['first_error'] = dict(stage=phase, reason='child_case_failed', **details[0])
    except (Exception, KeyboardInterrupt, SystemExit):
        detail = failure_detail(sys.exc_info())
        reason = {'child_arguments': 'child_arguments_invalid', 'child_import': 'child_import_failed',
                  'child_test': 'child_case_failed'}[phase]
        value.update(ok=False, errors=max(1, value['errors']),
                     first_error=dict(stage=phase, reason=reason, **detail))
    finally:
        probe = previous
    if stdout.count or stderr.count:
        detail = {'stage': 'child_output', 'reason': 'child_output_contaminated', 'error_class': 'RuntimeError', 'frames': []}
        if 'first_error' in value:
            value['secondary_errors'] = [detail]
        else:
            value['first_error'] = detail
        value['ok'] = False
    print(json.dumps(value, allow_nan=False))
    return 0 if value['ok'] else 1


if __name__ == '__main__':
    raise SystemExit(main())
