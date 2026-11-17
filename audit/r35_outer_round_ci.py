"""Temporary Linux CI coordinator; real journals and driver, no stage receipts."""
import os
from pathlib import Path
import signal
import time

from driver_journal import DriverJournal
from member_initialization import InitializedMemberPort, MemberInitialization
from member_round import MemberRound, round_journal


class DiskJournal:
    def __init__(self, directory, run_id):
        self.directory = Path(directory)
        self.directory.mkdir(mode=0o700)
        self.journal = DriverJournal(self.directory, run_id)

    def get(self, key, default=None):
        try:
            return self.journal.load(key + '.json')
        except FileNotFoundError:
            return default

    def __getitem__(self, key):
        value = self.get(key)
        if value is None:
            raise KeyError(key)
        return value

    def __setitem__(self, key, value):
        self.journal.save(key + '.json', value)

    def __contains__(self, key):
        return self.get(key) is not None


def step(scope, bindings, journal, cleanup_factory, *, crash=False):
    def unused(*_):
        raise AssertionError('native_dispatch_forbidden_in_prefix_case')

    with round_journal(journal.directory, scope['run_id']) as (load, save):
        initialization = MemberInitialization(scope=scope, interfaces=bindings,
            load=load, save=save, guard=lambda *_: None)
        port = InitializedMemberPort(initialization, unused)

        def invoke(operation, request):
            reply = port(operation, request)
            if crash:
                assert operation == 'bootstrap' and initialization.state['index'] == 6
                assert load('initialization')['receipts']['callback-ready'] == initialization.state['receipts']['callback-ready']
                assert load('round')['pending'] == request
                os.kill(os.getpid(), signal.SIGKILL)
            return reply

        driver = MemberRound(run_id=scope['run_id'], bundle_sha256=scope['bundle_sha256'],
            port=invoke, cleanup_factory=cleanup_factory, load=load, save=save,
            guard=lambda *_: None, restored=load('round'))
        if load('round') is None:
            driver.state['started_at'] = scope['started_at']
        return driver.step()


def kill_after_callback(scope, bindings, journal, cleanup_factory):
    pid = os.fork()
    if pid == 0:
        try:
            step(scope, bindings, journal, cleanup_factory, crash=True)
        finally:
            os._exit(83)
    waited = False
    try:
        deadline = time.monotonic() + 120
        while time.monotonic() < deadline:
            child, status = os.waitpid(pid, os.WNOHANG)
            if child:
                waited = True
                assert os.WIFSIGNALED(status) and os.WTERMSIG(status) == signal.SIGKILL
                return
            time.sleep(0.05)
        raise AssertionError('outer_crash_child_timeout')
    finally:
        if not waited:
            os.kill(pid, signal.SIGKILL)
            os.waitpid(pid, 0)
