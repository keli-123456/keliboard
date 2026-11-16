"""R35 host-side round entry. No live backend, credential reader or auto-arming CLI.

The controller owns native A1 cleanup/cooldown/A2 logic; this entry owns the
round deadline, stop, uncertain handoffs and final independent teardown.
"""
import copy
import hashlib
import json
import signal
import time
import os
import stat
from contextlib import contextmanager
from pathlib import Path

from member_auxiliary import digest
from member_transport import TransportError, require
from driver_journal import DriverJournal


@contextmanager
def round_journal(directory, run_id):
    """One Linux host driver per durable journal, including after process restart."""
    require(os.name == 'posix', 'transport_round_linux_required')
    import fcntl
    directory = Path(directory).absolute()
    journal = DriverJournal(directory, run_id)
    require(directory.stat().st_mode & 0o077 == 0, 'transport_round_private_directory_required')
    fd = os.open(directory / 'round.lock', os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW | os.O_NONBLOCK, 0o600)
    try:
        info = os.fstat(fd)
        require(stat.S_ISREG(info.st_mode) and info.st_nlink == 1 and info.st_mode & 0o077 == 0,
                'transport_round_lock_invalid')
        try:
            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise TransportError('transport_round_already_running') from None
        def load(key):
            try: return journal.load(key + '.json')
            except FileNotFoundError: return None
        yield load, lambda key, value: journal.save(key + '.json', value)
    finally:
        os.close(fd)


class MemberRound:
    def __init__(self, *, run_id, bundle_sha256, port, cleanup_factory, load, save, guard,
                 clock=time.time, restored=None):
        self.run_id, self.port, self.cleanup_factory = run_id, port, cleanup_factory
        self.load, self.save, self.guard, self.clock = load, save, guard, clock
        binding = digest({'run_id': run_id, 'bundle_sha256': bundle_sha256})
        self.state = copy.deepcopy(restored) if restored is not None else {
            'binding': binding, 'phase': 'bootstrap', 'started_at': int(clock()), 'last_at': int(clock()),
            'sequence': 0, 'pending': None, 'first_error': None, 'branches': {}, 'cleanup': None,
            'resources_cleaned': False, 'acceptance_passed': False}
        require(self.state.get('binding') == binding, 'transport_round_binding_changed')
        self.cleanup = None
        self.busy = False

    def persist(self):
        self.save('round', copy.deepcopy(self.state))

    def fail(self, code):
        self.state['first_error'] = self.state['first_error'] or code
        self.state['phase'] = 'reconcile'

    def dispatch(self, operation):
        self.guard(operation)
        self.state['sequence'] += 1
        request = {'run_id': self.run_id, 'operation': operation, 'sequence': self.state['sequence']}
        request['request_id'] = hashlib.sha256(json.dumps(request, sort_keys=True).encode()).hexdigest()[:32]
        self.state['pending'] = request
        self.persist()
        receipt = self.port(operation, copy.deepcopy(request))
        require(isinstance(receipt, dict) and receipt.get('request') == request,
                'transport_round_response_changed')
        self.state['pending'] = None
        return receipt

    def step(self, stop=False):
        require(not self.busy, 'transport_round_reentered')
        if self.state['phase'] == 'closed':
            return copy.deepcopy(self.state)
        self.busy = True
        try:
            now = int(self.clock())
            if now < self.state['last_at']:
                self.fail('round_clock_rollback')
            self.state['last_at'] = now
            if stop and self.state['phase'] not in ('reconcile', 'cleanup'):
                self.fail('round_stop_requested')
            if now - self.state['started_at'] >= 6000 and self.state['phase'] not in ('reconcile', 'cleanup'):
                self.fail('round_execution_deadline')
            if self.state['phase'] in ('bootstrap', 'native') and self.state['pending'] is not None:
                self.fail('round_uncertain_dispatch')
            if self.state['phase'] == 'bootstrap':
                result = self.dispatch('bootstrap')
                require(result.get('ready') is True, 'transport_round_bootstrap_failed')
                self.state['phase'] = 'native'
            elif self.state['phase'] == 'native':
                result = self.dispatch('native-step')
                require(result.get('phase') in ('running', 'finished', 'failed'), 'transport_round_native_invalid')
                if result['phase'] != 'running':
                    self.state['branches'] = result.get('branches', {})
                    if result['phase'] == 'failed':
                        self.fail('round_native_failed')
                    else:
                        self.state['phase'] = 'reconcile'
            elif self.state['phase'] == 'reconcile':
                # Read back original intent IDs even when the mutation response was lost.
                # This operation never dispatches creates, callbacks, DNS writes or native steps.
                self.guard('reconcile')
                result = self.port('reconcile', {'run_id': self.run_id, 'pending': self.state['pending']})
                require(result.get('run_id') == self.run_id and result.get('resolved') is True,
                        'transport_round_reconciliation_required')
                self.state['cleanup_scope'] = result['cleanup_scope']
                self.state['phase'] = 'cleanup'
                self.persist()
            elif self.state['phase'] == 'cleanup':
                self.guard('cleanup')
                if self.cleanup is None:
                    self.cleanup = self.cleanup_factory(self.state['cleanup_scope'], self.state['cleanup'])
                status = self.cleanup.step()
                self.state['cleanup'] = copy.deepcopy(status)
                if status['phase'] == 'closed':
                    self.state['resources_cleaned'] = True
                    keys = ('native_cleanup', 'cooldown', 'retry', 'b_unchanged', 'dns_cutover')
                    self.state['acceptance_passed'] = (self.state['first_error'] is None
                        and status.get('first_error') is None
                        and status.get('evidence_saved_and_read_back') is True
                        and all(self.state['branches'].get(k) is True for k in keys))
                    self.state['phase'] = 'closed'
        except Exception as error:
            code = str(error) if isinstance(error, TransportError) else 'round_operation_failed'
            # Provider messages, credentials and arbitrary paths never enter the round journal.
            if not code.startswith(('transport_', 'cleanup_')):
                code = 'round_operation_failed'
            self.state['first_error'] = self.state['first_error'] or code
            if self.state['phase'] not in ('cleanup', 'reconcile'):
                self.state['phase'] = 'reconcile'
        finally:
            self.busy = False
            try:
                self.persist()
            except Exception:
                self.state['first_error'] = self.state['first_error'] or 'round_journal_unavailable'
                self.state['acceptance_passed'] = False
                if self.state['phase'] not in ('cleanup', 'reconcile', 'closed'):
                    self.state['phase'] = 'reconcile'
        return copy.deepcopy(self.state)


def run(round_driver, *, sleep=time.sleep, cleanup_seconds=1200):
    """SIGTERM requests teardown, never a second bootstrap or native dispatch."""
    stopped = False
    def stop(_signal, _frame):
        nonlocal stopped
        stopped = True
    previous = {s: signal.signal(s, stop) for s in (signal.SIGTERM, signal.SIGINT)}
    cleanup_started = None
    try:
        while True:
            state = round_driver.step(stop=stopped)
            if state['phase'] == 'closed':
                return state
            if state['phase'] in ('cleanup', 'reconcile'):
                cleanup_started = cleanup_started if cleanup_started is not None else time.monotonic()
                if time.monotonic() - cleanup_started >= cleanup_seconds:
                    return dict(state, cleanup_pending=True, acceptance_passed=False)
            sleep(1)
    finally:
        for s, handler in previous.items():
            signal.signal(s, handler)
