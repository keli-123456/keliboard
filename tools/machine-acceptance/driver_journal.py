"""Bounded local JSON I/O for an explicitly approved, separately prepared driver.

This module has no transport, process launcher, authorization writer or live entry.
Only atomic replacement may retry; exclusive intent creation never does.
"""
import ctypes
import json
import os
from pathlib import Path
import re
import stat
import tempfile
import time
import uuid


MAX_BYTES = 2 * 1024 * 1024
REPLACE_ATTEMPTS = 8
REPLACE_SECONDS = 0.5
IMMUTABLE_INPUTS = {'authorization.json', 'offline-result.json', 'bundle-manifest.json', 'proposal.json'}
KINDS = {'progress', 'diagnostics', 'ledger', 'intent', 'outcome', 'other'}
SITES = {'stage', 'progress', 'diagnostics', 'cleanup', 'finalize', 'journal'}
PHASES = {'claim', 'production_metadata', 'production_health_before', 'provider_readonly_preflight', 'control_create',
          'control_boot', 'control_pipeline', 'source_queue', 'source_installation', 'source_agent_reports',
          'source_identity', 'bounded_source_diagnostics', 'cleanup', 'closed', 'installation_completed', 'unknown'}
OPERATIONS = {'open_intent', 'write_intent', 'sync_intent', 'create_temporary', 'write_temporary',
              'sync_temporary', 'replace_snapshot', 'sync_directory', 'read_record', 'unknown'}


class Rejected(RuntimeError):
    pass


class JournalIOError(OSError):
    def __init__(self, summary):
        self.summary = dict(summary)
        super().__init__('local_journal_io_failed')


def require(condition, code):
    if not condition:
        raise Rejected(code)


def kind(name):
    if name == 'progress.json':
        return 'progress'
    if 'diagnostic' in name:
        return 'diagnostics'
    if name == 'resource-ledger.json':
        return 'ledger'
    if 'intent' in name:
        return 'intent'
    if name in {'round-outcome.json', 'final-result.json'}:
        return 'outcome'
    return 'other'


def safe_error(error, *, site='journal', logical_record='other', operation='unknown'):
    """Never stringify exceptions, paths, commands, arguments or frame locals."""
    if isinstance(error, JournalIOError):
        value = dict(error.summary)
        value['site'] = site if site in SITES else 'journal'
        return value
    name = type(error).__name__
    value = {'error_class': name if re.fullmatch(r'[A-Za-z][A-Za-z0-9_]{0,63}', name) else 'Exception',
             'errno': None, 'winerror': None, 'site': site if site in SITES else 'journal',
             'logical_record': logical_record if logical_record in KINDS else 'other',
             'operation': operation if operation in OPERATIONS else 'unknown', 'callsites': []}
    for field, maximum in [('errno', 2**31 - 1), ('winerror', 65535)]:
        number = getattr(error, field, None)
        if type(number) is int and 0 <= number <= maximum:
            value[field] = number
    allowed = {str(Path(__file__).resolve().with_name(name)): name.removesuffix('.py')
               for name in ['driver_journal.py', 'install_only_runner.py', 'campaign.py', 'live_operator.py']}
    frame = error.__traceback__
    for _ in range(32):
        if frame is None:
            break
        code = frame.tb_frame.f_code
        module = allowed.get(os.path.abspath(code.co_filename))
        if module and re.fullmatch(r'[A-Za-z_][A-Za-z0-9_]{0,63}', code.co_name) and 1 <= frame.tb_lineno <= 999999:
            value['callsites'].append({'module': module, 'function': code.co_name, 'line': frame.tb_lineno})
        frame = frame.tb_next
    value['callsites'] = value['callsites'][-8:]
    return value


def unique_json(raw):
    def pairs(items):
        value = {}
        for key, item in items:
            require(key not in value, 'duplicate_journal_key')
            value[key] = item
        return value
    def invalid(_):
        raise Rejected('nonfinite_journal_number')
    value = json.loads(raw, object_pairs_hook=pairs, parse_constant=invalid)
    require(isinstance(value, dict), 'journal_object_required')
    return value


def _reader(path):
    if os.name != 'nt':
        return os.open(path, os.O_RDONLY | getattr(os, 'O_NOFOLLOW', 0) | getattr(os, 'O_NONBLOCK', 0))
    import msvcrt
    from ctypes import wintypes
    kernel = ctypes.WinDLL('kernel32', use_last_error=True)
    create = kernel.CreateFileW
    create.argtypes = [wintypes.LPCWSTR, wintypes.DWORD, wintypes.DWORD, ctypes.c_void_p,
                       wintypes.DWORD, wintypes.DWORD, wintypes.HANDLE]
    create.restype = wintypes.HANDLE
    close = kernel.CloseHandle
    close.argtypes, close.restype = [wintypes.HANDLE], wintypes.BOOL
    # Request rename sharing; prompt close plus bounded writer retries handle contention.
    handle = create(str(path), 0x80000000, 0x1 | 0x2 | 0x4, None, 3, 0x80 | 0x00200000, None)
    if handle == ctypes.c_void_p(-1).value:
        raise ctypes.WinError(ctypes.get_last_error())
    try:
        return msvcrt.open_osfhandle(handle, os.O_RDONLY | os.O_BINARY)
    except BaseException:
        close(handle)
        raise


class DriverJournal:
    def __init__(self, base, run_id, *, clock=time.monotonic, sleep=time.sleep):
        self.base = Path(base).absolute()
        if os.name == 'nt':
            require(re.fullmatch(r'[A-Za-z]:', self.base.drive) is not None, 'local_volume_required')
        require(isinstance(run_id, str) and re.fullmatch(r'[a-z0-9][a-z0-9-]{7,99}', run_id), 'journal_run_id_invalid')
        self.run_id, self.clock, self.sleep = run_id, clock, sleep
        self.events = []
        self.windows = os.name == 'nt'
        self.identity = None
        self._directory()

    def _directory(self):
        require(self.base.is_dir() and not self.base.is_symlink() and self.base.resolve(strict=True) == self.base,
                'journal_directory_invalid')
        info = self.base.lstat()
        require(not getattr(info, 'st_file_attributes', 0) & 0x400, 'journal_reparse_point_refused')
        identity = (info.st_dev, info.st_ino)
        require(self.identity is None or self.identity == identity, 'journal_directory_changed')
        self.identity = identity

    def _path(self, name, *, writing=False):
        self._directory()
        require(isinstance(name, str) and re.fullmatch(r'[a-z0-9][a-z0-9_.-]{0,114}\.json', name), 'journal_name_invalid')
        if writing:
            require(name not in IMMUTABLE_INPUTS, 'authority_or_frozen_input_write_refused')
            require(not os.path.lexists(self.base / 'closed.json'), 'closed_round_write_refused')
        path = self.base / name
        if os.path.lexists(path):
            info = path.lstat()
            require(stat.S_ISREG(info.st_mode) and not getattr(info, 'st_file_attributes', 0) & 0x400
                    and info.st_nlink == 1, 'journal_link_or_special_file_refused')
        return path

    def _event(self, value):
        self.events.append(dict(value))
        self.events[:] = self.events[-64:]

    def _sync_directory(self):
        if os.name == 'posix':
            descriptor = os.open(self.base, os.O_RDONLY | getattr(os, 'O_DIRECTORY', 0))
            try:
                os.fsync(descriptor)
            finally:
                os.close(descriptor)

    def load(self, name):
        path = self._path(name)
        descriptor = _reader(path)
        with os.fdopen(descriptor, 'rb') as stream:
            info = os.fstat(stream.fileno())
            require(stat.S_ISREG(info.st_mode) and info.st_nlink == 1
                    and not getattr(info, 'st_file_attributes', 0) & 0x400 and info.st_size <= MAX_BYTES,
                    'journal_read_invalid')
            raw = stream.read(MAX_BYTES + 1)
            require(len(raw) <= MAX_BYTES, 'journal_read_too_large')
        return unique_json(raw)

    def _replace(self, temporary, target, name):
        started = self.clock()
        last = None
        for attempt in range(1, REPLACE_ATTEMPTS + 1):
            elapsed = self.clock() - started
            if last is not None and elapsed >= REPLACE_SECONDS:
                last.update(attempts=attempt - 1, elapsed_ms=max(0, int(elapsed * 1000)))
                raise JournalIOError(last) from None
            self._path(name, writing=True)
            try:
                os.replace(temporary, target)
                if attempt > 1:
                    self._event({'operation': 'replace_snapshot', 'logical_record': kind(name),
                                 'attempts': attempt, 'recovered': True})
                return
            except OSError as error:
                elapsed = self.clock() - started
                transient = self.windows and isinstance(error, PermissionError) and getattr(error, 'winerror', None) in {5, 32, 33}
                last = safe_error(error, operation='replace_snapshot', logical_record=kind(name))
                last['recovered'] = False
                if not transient or attempt == REPLACE_ATTEMPTS or elapsed >= REPLACE_SECONDS:
                    detail = dict(last)
                    detail.update(attempts=attempt, elapsed_ms=max(0, int(elapsed * 1000)), recovered=False)
                    raise JournalIOError(detail) from None
                self.sleep(min(0.02 * 2**(attempt - 1), 0.1, max(0, REPLACE_SECONDS - elapsed)))

    def save(self, name, value, exclusive=False):
        target = self._path(name, writing=True)
        require(isinstance(value, dict) and type(exclusive) is bool, 'journal_object_and_mode_required')
        raw = (json.dumps(value, ensure_ascii=True, allow_nan=False, indent=2) + '\n').encode()
        require(len(raw) <= MAX_BYTES, 'journal_record_too_large')
        operation = 'open_intent' if exclusive else 'create_temporary'
        temporary = None
        committed = False
        failure = None
        try:
            if exclusive:
                descriptor = os.open(target, os.O_WRONLY | os.O_CREAT | os.O_EXCL | getattr(os, 'O_BINARY', 0), 0o600)
            else:
                descriptor, temporary_name = tempfile.mkstemp(prefix='.journal-', suffix='.tmp', dir=self.base)
                temporary = Path(temporary_name)
            with os.fdopen(descriptor, 'wb') as stream:
                operation = 'write_intent' if exclusive else 'write_temporary'
                stream.write(raw)
                stream.flush()
                operation = 'sync_intent' if exclusive else 'sync_temporary'
                os.fsync(stream.fileno())
            if not exclusive:
                operation = 'replace_snapshot'
                self._replace(temporary, target, name)
            committed = True
            operation = 'sync_directory'
            self._sync_directory()
        except FileExistsError as error:
            # An existing or partially written intent is spent, never replayed.
            if exclusive:
                raise
            failure = safe_error(error, operation=operation, logical_record=kind(name))
            failure.update(attempts=1, committed=committed, recovered=False)
        except OSError as error:
            failure = safe_error(error, operation=operation, logical_record=kind(name))
            failure.setdefault('attempts', 1)
            failure.update(committed=committed, recovered=False)
        finally:
            removed = True
            if temporary is not None:
                try:
                    temporary.unlink(missing_ok=True)
                except OSError:
                    removed = False
            if failure is not None:
                failure['temporary_file_removed'] = removed
        if failure is not None:
            self._event(failure)
            raise JournalIOError(failure) from None

    def failure_record(self, error, *, site, logical_record='other', phase='unknown'):
        detail = safe_error(error, site=site, logical_record=logical_record)
        record = {'schema': 1, 'run_id': self.run_id, 'phase': phase if phase in PHASES else 'unknown',
                  'error': detail, 'raw_details_exported': False}
        name = 'local-failure-' + uuid.uuid4().hex + '.json'
        try:
            self.save(name, record, exclusive=True)
        except (OSError, Rejected):
            return {'persisted': False, 'record': None, **record}
        return {'persisted': True, 'record': name, **record}

    def diagnostic_result(self, result):
        require(isinstance(result, dict) and result.get('run_id') == self.run_id, 'diagnostic_run_mismatch')
        try:
            self.save('bootstrap-diagnostics.json', result, exclusive=True)
            return {'persisted': True, 'record': 'bootstrap-diagnostics.json', 'fallback': False}
        except (OSError, Rejected) as error:
            error_record = self.failure_record(error, site='diagnostics', logical_record='diagnostics')
        name = 'diagnostic-result-' + uuid.uuid4().hex + '.json'
        try:
            self.save(name, result, exclusive=True)
        except (OSError, Rejected):
            return {'persisted': False, 'record': None, 'fallback': True, 'error_record': error_record}
        return {'persisted': True, 'record': name, 'fallback': True, 'error_record': error_record}
