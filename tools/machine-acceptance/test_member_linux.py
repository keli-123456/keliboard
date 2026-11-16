"""Linux-only real process/signal/permissions checks. No credentials or real transports."""
import json
import os
from pathlib import Path
import signal
import subprocess
import sys
import tempfile
import time
import unittest


@unittest.skipUnless(sys.platform == 'linux', 'real Linux required')
class MemberLinuxTests(unittest.TestCase):
    def test_durable_round_lock_excludes_other_worker_and_releases_after_exit(self):
        from member_round import round_journal
        with tempfile.TemporaryDirectory() as directory:
            with round_journal(directory, 'keli-accept-offline-r35-fixture') as (load, save):
                self.assertIsNone(load('round')); save('round', {'phase': 'native', 'pending': 'original-id'})
                code = "from member_round import round_journal; import sys;\nwith round_journal(sys.argv[1], 'keli-accept-offline-r35-fixture'): pass"
                process = subprocess.run([sys.executable, '-c', code, directory], cwd=Path(__file__).parent,
                    capture_output=True, text=True, timeout=10)
                self.assertNotEqual(process.returncode, 0)
                self.assertIn('transport_round_already_running', process.stderr)
            with round_journal(directory, 'keli-accept-offline-r35-fixture') as (load, save):
                self.assertEqual(load('round')['pending'], 'original-id')

    def test_sigterm_runs_round_cleanup_without_a_second_native_dispatch(self):
        script = '''
import json,sys
from member_round import run
from test_member_round import RoundFixture
f=RoundFixture()
original=f.port
def port(op,request):
    if op == 'native-step':
        print('waiting',flush=True)
        return {'request':request,'phase':'running'}
    return original(op,request)
f.port=port
def sleep(_):
    import time
    f.now+=10
    time.sleep(.05)
result=run(f.round(),sleep=sleep)
print(json.dumps({'closed':result['phase']=='closed','clean':result['resources_cleaned'],
    'passed':result['acceptance_passed'],'first':result['first_error'],
    'control_deletes':sum(e[0]=='control-delete' for e in f.events)}),flush=True)
'''
        with subprocess.Popen([sys.executable, '-u', '-c', script], cwd=Path(__file__).parent,
                              stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True) as process:
            try:
                import select
                ready, _, _ = select.select([process.stdout], [], [], 10)
                self.assertTrue(ready, 'child did not reach native polling')
                self.assertEqual(process.stdout.readline().strip(), 'waiting')
                process.send_signal(signal.SIGTERM)
                out, err = process.communicate(timeout=10)
                self.assertEqual(process.returncode, 0, err)
                result = json.loads(out.strip().splitlines()[-1])
                self.assertEqual(result, {'closed': True, 'clean': True, 'passed': False,
                    'first': 'round_stop_requested', 'control_deletes': 1})
            finally:
                if process.poll() is None: process.kill(); process.wait()

    def test_private_directory_permissions_and_denied_journal_do_not_delete(self):
        from test_member_round import RoundFixture
        f = RoundFixture(); aux = f.aux()
        def deny(*_): raise PermissionError('do not export path')
        aux.save = deny
        with self.assertRaises(PermissionError): aux.absent('dns', f.scope['records'][0])
        self.assertNotIn('dns-delete', [e[0] for e in f.events])
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory); root.chmod(0o700)
            file = root / 'journal'; file.write_text('{}'); file.chmod(0o600)
            self.assertEqual(root.stat().st_mode & 0o777, 0o700)
            self.assertEqual(file.stat().st_mode & 0o777, 0o600)

    def test_unarmed_linux_php_entry_refuses_execution_before_transport(self):
        process = subprocess.run(['php', str(Path(__file__).with_name('member_transport_entry.php'))],
            input='{}', text=True, capture_output=True, timeout=10)
        self.assertNotEqual(process.returncode, 0)
        result = json.loads(process.stdout)
        self.assertFalse(result['ok'])
