"""Linux process controls around the real bound port, with synthetic SSH bytes."""
import json
from pathlib import Path
import select
import signal
import subprocess
import sys
import unittest


@unittest.skipUnless(sys.platform == 'linux', 'real Linux required')
class MemberBoundLinuxTests(unittest.TestCase):
    def test_sigterm_stops_bound_port_then_completes_exact_cleanup(self):
        code = '''
import json
from member_round import run
from test_member_cli import MemberCliTests
t=MemberCliTests(); t.setUp()
original=t.trans.execute
def execute(command,payload,timeout):
    r=json.loads(payload)
    if r['operation']=='round-step':
        print('waiting',flush=True)
        t.requests.append(r)
        return json.dumps(dict(r,ok=True,result={'phase':'running'})).encode()
    return original(command,payload,timeout)
t.trans.execute=execute
def sleep(_):
    import time
    t.f.now+=10
    time.sleep(.1)
try:
    state=run(t.driver(),sleep=sleep)
    print(json.dumps({'closed':state['phase']=='closed','clean':state['resources_cleaned'],
      'passed':state['acceptance_passed'],'first':state['first_error'],
      'steps':sum(r['operation']=='round-step' for r in t.requests),
      'stop':any(r['operation']=='round-stop' for r in t.requests),
      'controller_deletes':sum(e[0]=='control-delete' for e in t.f.events)}),flush=True)
finally: t.doCleanups()
'''
        with subprocess.Popen([sys.executable, '-u', '-c', code], cwd=Path(__file__).parent,
                              stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True) as p:
            try:
                ready, _, _ = select.select([p.stdout], [], [], 15)
                self.assertTrue(ready); self.assertEqual(p.stdout.readline().strip(), 'waiting')
                p.send_signal(signal.SIGTERM)
                out, err = p.communicate(timeout=20)
                self.assertEqual(p.returncode, 0, err)
                result = json.loads(out.strip().splitlines()[-1])
                self.assertEqual(result, dict(closed=True, clean=True, passed=False,
                    first='round_stop_requested', steps=1, stop=True, controller_deletes=1))
            finally:
                if p.poll() is None: p.kill(); p.wait()


if __name__ == '__main__': unittest.main()
