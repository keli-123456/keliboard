"""Actual Linux locks, disk journals and signals around the mocked R35 assembly."""
import json
import os
from pathlib import Path
import signal
import subprocess
import sys
import tempfile
import unittest


CHILD = r'''
import json, os, signal, sys, tempfile, time
from pathlib import Path
from driver_journal import DriverJournal
from member_cli import RUN
import member_assembly
from test_member_assembly import AssemblyTests

root, mode = Path(sys.argv[1]), sys.argv[2]
(root / 'children').mkdir(mode=0o700, exist_ok=True)
tempfile.tempdir = str(root / 'children')
journal = DriverJournal(root, RUN)
class Store:
    def get(self, key):
        try: return journal.load(key + '.json')
        except FileNotFoundError: return None
    def save(self, key, value): journal.save(key + '.json', value)
store = Store()
t = AssemblyTests(); t.setUp(); f = t.f
f.journal, f.save = store, store.save
fields = ('now', 'original_scope', 'scope', 'pins', 'events', 'created_control', 'created_dns', 'created_private')
previous = store.get('simulated-remote')
if previous:
    for name in fields: setattr(f, name, previous[name])
    f.present = set(previous['present']); f.created_roles = set(previous['created_roles'])
    t.requests = previous['requests']; t.stopped = previous['stopped']
if mode == 'partial': f.fail = ('sources-initialize', 'partial')

def persist_remote():
    value = {name: getattr(f, name) for name in fields}
    value.update(present=sorted(f.present), created_roles=sorted(f.created_roles),
                 requests=t.requests, stopped=t.stopped)
    store.save('simulated-remote', value)

original_invoke = t.setup.invoke
def invoke(*args):
    try: return original_invoke(*args)
    finally: persist_remote()
t.setup.invoke = invoke
original_io = f.io
def io(*args):
    try: return original_io(*args)
    finally: persist_remote()
f.io = io
original_execute = t.execute
paused = False
def execute(command, payload, timeout):
    global paused
    request = json.loads(payload)
    if mode == 'term' and request['operation'] == 'round-step':
        t.requests.append(request)
        persist_remote()
        print('checkpoint', flush=True)
        signal.pause()
        return json.dumps(dict(request, ok=True, result={'phase': 'running'})).encode()
    result = original_execute(command, payload, timeout)
    persist_remote()
    operation = 'round-step' if mode == 'crash-native' else 'task-delete'
    if mode in ('crash-native', 'crash-delete') and not paused and request['operation'] == operation:
        paused = True
        print('checkpoint', flush=True)
        signal.pause()
    return result
t.execute = execute
original_run = member_assembly.run
def tick(_):
    f.now += 10
    time.sleep(.01)
member_assembly.run = lambda driver: original_run(driver, sleep=tick, cleanup_seconds=20)
bindings = t.bindings()
del bindings['load']; del bindings['save']
entry = member_assembly.run_assembled_round
if len(sys.argv) > 3 and sys.argv[3] == 'execution':
    from test_member_execution import ExecutionPort
    del bindings['setup']
    bindings['setup_ports'] = {name: ExecutionPort(t, name) for name in ('installation', 'sources', 'dns', 'authority')}
    entry = member_assembly.run_execution_round
try:
    state = entry(root, **bindings)
    persist_remote()
    deletes = [r['role'] for r in t.requests if r['operation'] == 'task-delete']
    print(json.dumps(dict(closed=state['phase'] == 'closed', clean=state['resources_cleaned'],
        passed=state['acceptance_passed'], first=state['first_error'],
        native_steps=sum(r['operation'] == 'round-step' for r in t.requests),
        creates=sum(e[0] == 'control-create' for e in f.events),
        control_deletes=sum(e[0] == 'control-delete' for e in f.events),
        task_deletes=deletes, sources=sorted(r for r in f.created_roles if r.startswith('source-')),
        no_resources=not f.present and not any(f.pins.values()),
        private_journals=all(p.stat().st_mode & 0o077 == 0 for p in root.glob('*.json')))), flush=True)
finally:
    t.doCleanups()
'''


@unittest.skipUnless(sys.platform == 'linux', 'real Linux required')
class MemberAssemblyLinuxTests(unittest.TestCase):
    def launch(self, directory, mode):
        return subprocess.Popen([sys.executable, '-u', '-B', '-c', CHILD, directory, mode],
            cwd=Path(__file__).parent, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)

    def checkpoint(self, child):
        import select
        ready, _, _ = select.select([child.stdout], [], [], 15)
        self.assertTrue(ready, 'assembly never reached checkpoint')
        self.assertEqual(child.stdout.readline().strip(), 'checkpoint')

    def finish(self, child):
        out, err = child.communicate(timeout=30)
        self.assertEqual(child.returncode, 0, err)
        result = json.loads(out.strip().splitlines()[-1])
        self.assertTrue(result['closed']); self.assertTrue(result['clean'])
        self.assertTrue(result['no_resources']); self.assertTrue(result['private_journals'])
        self.assertEqual(result['creates'], 1); self.assertEqual(result['control_deletes'], 1)
        self.assertEqual(len(result['task_deletes']), len(set(result['task_deletes'])))
        return result

    @staticmethod
    def reap(child):
        if child.poll() is None:
            child.kill()
        child.communicate(timeout=10)

    def test_native_crash_holds_lock_then_restart_reads_original_intents(self):
        with tempfile.TemporaryDirectory() as directory:
            child = self.launch(directory, 'crash-native')
            try:
                self.checkpoint(child)
                contender = subprocess.run([sys.executable, '-B', '-c',
                    'from member_assembly import run_assembled_round; import sys; run_assembled_round(sys.argv[1])',
                    directory], cwd=Path(__file__).parent, capture_output=True, text=True, timeout=10)
                self.assertNotEqual(contender.returncode, 0)
                self.assertIn('transport_round_already_running', contender.stderr)
                child.kill(); child.communicate(timeout=10)
            finally: self.reap(child)
            resumed = self.launch(directory, 'resume')
            try:
                result = self.finish(resumed)
                self.assertFalse(result['passed'])
                self.assertEqual(result['first'], 'round_uncertain_dispatch')
                self.assertEqual(result['native_steps'], 1)
            finally: self.reap(resumed)

    def test_crash_after_delete_before_ack_never_repeats_task_delete(self):
        with tempfile.TemporaryDirectory() as directory:
            child = self.launch(directory, 'crash-delete')
            try:
                self.checkpoint(child)
                child.kill(); child.communicate(timeout=10)
            finally: self.reap(child)
            resumed = self.launch(directory, 'resume')
            try:
                result = self.finish(resumed)
                self.assertEqual(result['native_steps'], 1)
                self.assertEqual(result['task_deletes'].count('candidate-a2'), 1)
                self.assertEqual(result['task_deletes'].count('source-a'), 1)
                self.assertEqual(result['task_deletes'].count('source-b'), 1)
            finally: self.reap(resumed)

    def test_sigterm_during_native_wait_stops_and_cleans_whole_assembly(self):
        with tempfile.TemporaryDirectory() as directory:
            child = self.launch(directory, 'term')
            try:
                self.checkpoint(child); child.send_signal(signal.SIGTERM)
                result = self.finish(child)
                self.assertFalse(result['passed']); self.assertEqual(result['native_steps'], 1)
                self.assertEqual(result['first'], 'round_stop_requested')
            finally: self.reap(child)

    def test_partial_source_initialization_cleans_only_existing_source(self):
        with tempfile.TemporaryDirectory() as directory:
            child = self.launch(directory, 'partial')
            try:
                result = self.finish(child)
                self.assertFalse(result['passed']); self.assertEqual(result['native_steps'], 0)
                self.assertEqual(result['sources'], ['source-a'])
            finally: self.reap(child)

    def test_real_permission_denial_blocks_entry_before_any_interface(self):
        from member_assembly import run_assembled_round
        if os.geteuid() == 0:
            self.skipTest('unprivileged Linux required for real permission denial')
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory); root.chmod(0o500)
            try:
                with self.assertRaises((PermissionError, RuntimeError)):
                    run_assembled_round(root)
                self.assertFalse((root / 'round.json').exists())
            finally: root.chmod(0o700)


if __name__ == '__main__':
    unittest.main()
