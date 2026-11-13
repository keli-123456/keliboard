"""Real iputils only inside the dedicated loopback-only Linux test namespace."""
import json
import os
from pathlib import Path
import subprocess
import sys
import time
import traceback
import unittest
from unittest.mock import patch

import entry_probe_diagnostics as probe


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
        return {'error_class': error[0].__name__,
                'frames': [{'file': Path(frame.filename).name, 'line': frame.lineno}
                           for frame in traceback.extract_tb(error[2])][-6:]}

    def addFailure(self, test, error):
        self.failures.append((test, self.detail(error)))

    def addError(self, test, error):
        self.errors.append((test, self.detail(error)))


def main():
    case = sys.argv[1] if len(sys.argv) == 2 else ''
    allowed = {'echo', 'no_reply', 'four_no_reply_targets', 'legacy_deadline_reference',
               'forced_timeout_reaps_real_ping', 'permission_denied_is_not_packet_loss'}
    if case not in allowed:
        raise ValueError('fixed_case_required')
    test = RealIputilsTests('test_' + case)
    result = SafeResult()
    unittest.TestSuite([test]).run(result)
    value = {'ok': result.wasSuccessful() and not result.skipped, 'case': case, 'tests': result.testsRun,
             'failures': len(result.failures), 'errors': len(result.errors), 'skipped': len(result.skipped),
             **getattr(test, 'summary', {})}
    if result.failures or result.errors:
        value['failed_tests'] = [str(t) for t, _ in result.failures + result.errors]
        value['failure_details'] = [detail for _, detail in result.failures + result.errors]
    print(json.dumps(value, allow_nan=False))
    return 0 if value['ok'] else 1


if __name__ == '__main__':
    raise SystemExit(main())
