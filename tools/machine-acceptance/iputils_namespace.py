"""Kernel regression runner, refusing execution outside a fresh unshare PID/network namespace."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import signal
import subprocess
import sys
import tempfile
import traceback

HERE = Path(__file__).resolve().parent
TABLE = 'keli_iputils_regression'
COUNTER = 'echo_requests'
ALLOWED_ENV = {'PATH', 'LANG', 'LC_ALL', 'PYTHONDONTWRITEBYTECODE', 'KELI_IPUTILS_NAMESPACE', 'HOME'}
MAX_OUTPUT = 262144
MAX_FRAMES = 6
MAX_SECONDARY_ERRORS = 8
FRAME_FILES = {'iputils_namespace.py', 'test_entry_probe_iputils.py', 'entry_probe_diagnostics.py', 'bootstrap_diagnostics.py'}
CHILD_STAGES = {'child_arguments', 'child_import', 'child_test', 'child_output', 'child_launch'}
CHILD_REASONS = {'child_arguments_invalid', 'child_import_failed', 'child_case_failed',
                 'child_output_contaminated', 'child_permission_denied', 'child_interpreter_failed'}
STAGES = {
    'topology', 'topology_links', 'topology_routes', 'topology_firewall', 'identity', 'iputils_version',
    'workspace', 'iputils_copy', 'loopback_up', 'firewall_setup', 'rule_read', 'rule_update', 'counter_read',
    'case_setup', 'child_launch', 'child_wait', 'child_output', 'child_result', 'case_counter', 'case_children',
    'case_result', 'rust_binary', 'cleanup_child_kill', 'cleanup_child_wait', 'cleanup_child_close',
    'cleanup_children', 'cleanup_firewall_delete', 'cleanup_firewall_check', 'cleanup_directory',
    'report_write', 'signal', 'complete', 'main', 'namespace_command', 'unknown',
} | CHILD_STAGES
REASONS = {
    'fresh_unshare_root_pid_one_required', 'clean_namespace_environment_required', 'parent_namespace_required',
    'independent_network_namespace_required', 'loopback_only_required', 'fresh_empty_routes_required',
    'fresh_empty_firewall_required', 'dedicated_counter_required', 'dedicated_rule_changed', 'rule_handle_required',
    'non_root_test_identity_required', 'real_iputils_required', 'file_capabilities_not_allowed',
    'iputils_copy_bytes_changed', 'test_gid_conflicts_with_denial_group', 'scoped_rust_test_binary_required',
    'namespace_tool_failed', 'namespace_tool_timeout', 'namespace_tool_unavailable', 'namespace_tool_output_invalid',
    'test_output_oversized', 'child_launch_failed', 'child_wait_failed', 'child_timeout', 'child_cleanup_failed',
    'child_output_invalid', 'child_result_invalid', 'child_result_incomplete', 'child_case_failed',
    'child_exit_nonzero', 'child_signaled', 'packet_counter_mismatch', 'remaining_children', 'cases_incomplete',
    'cleanup_incomplete', 'report_write_failed', 'signal_received', 'interrupted', 'operation_failed',
} | CHILD_REASONS
ERROR_CLASSES = {
    'Exception', 'AssertionError', 'AttributeError', 'BrokenPipeError', 'CalledProcessError', 'ChildProcessError',
    'FileExistsError', 'FileNotFoundError', 'IndexError', 'InterruptedError', 'IsADirectoryError', 'JSONDecodeError',
    'KeyError', 'KeyboardInterrupt', 'MemoryError', 'NotADirectoryError', 'OSError', 'OverflowError',
    'PermissionError', 'ProcessLookupError', 'RecursionError', 'RuntimeError', 'SubprocessError', 'SystemExit',
    'TimeoutError', 'TimeoutExpired', 'TypeError', 'UnicodeDecodeError', 'UnicodeError', 'ValueError',
    'ImportError', 'ModuleNotFoundError', 'SyntaxError',
}
HANDLED_ERRORS = (Exception, KeyboardInterrupt, SystemExit)
CASES = (('echo', 'echo'), ('no_reply', 'drop'), ('four_no_reply_targets', 'drop'),
         ('legacy_deadline_reference', 'drop'), ('forced_timeout_reaps_real_ping', 'drop'),
         ('permission_denied_is_not_packet_loss', 'permission'))
MEASUREMENT_STATES = {'observed', 'missing', 'unavailable', 'invalid', 'timeout', 'oversized', 'budget_exhausted'}
CLASSIFICATIONS = {'echo_reply', 'no_echo_by_deadline', 'icmp_error', 'execution_error', 'packet_budget_exceeded', 'unavailable'}
ERROR_CATEGORIES = {'permission_denied', 'source_bind_error', 'local_route_error', 'icmp_prohibited',
                    'icmp_host_unreachable', 'icmp_network_unreachable', 'icmp_port_unreachable',
                    'icmp_protocol_unreachable', 'icmp_fragmentation_needed', 'icmp_ttl_exceeded',
                    'icmp_parameter_problem', 'icmp_redirect'}


def allowed(value, choices, fallback):
    return value if type(value) is str and value in choices else fallback


def exit_code(value):
    return value if type(value) is int and -255 <= value <= 255 else None


def safe_frames(frames):
    if not isinstance(frames, list):
        return []
    return [{'file': frame['file'], 'line': frame['line']} for frame in frames
            if isinstance(frame, dict) and type(frame.get('file')) is str and frame['file'] in FRAME_FILES
            and type(frame.get('line')) is int and 1 <= frame['line'] <= 100000][-MAX_FRAMES:]


def traceback_frames(tb):
    return safe_frames([{'file': Path(frame.filename).name, 'line': frame.lineno}
                        for frame in traceback.extract_tb(tb)
                        if Path(frame.filename).resolve() in {HERE / name for name in FRAME_FILES}])


class NamespaceFailure(RuntimeError):
    def __init__(self, reason, *, stage=None, error=None, code=None, timed_out=None):
        self.reason = allowed(reason, REASONS, 'operation_failed')
        super().__init__(self.reason)
        self.stage = stage
        self.error_class = allowed(type(error).__name__, ERROR_CLASSES, 'Exception') if error is not None else 'RuntimeError'
        self.frames = traceback_frames(error.__traceback__) if error is not None else None
        self.code = exit_code(code)
        self.timed_out = timed_out if type(timed_out) is bool else None
        number = getattr(error, 'errno', None)
        self.errno = number if type(number) is int and 0 <= number <= 65535 else None
        self.secondary_errors = []


class Report(dict):
    # An in-process callback is deliberately not a serializable evidence field.
    checkpoint = None


def error_entry(error, stage, reason='operation_failed'):
    if isinstance(error, NamespaceFailure):
        entry = {'stage': allowed(error.stage or stage, STAGES, 'unknown'), 'reason': error.reason,
                 'error_class': error.error_class,
                 'frames': error.frames if error.frames is not None else traceback_frames(error.__traceback__)}
        if error.code is not None:
            entry['exit_code'] = error.code
        if error.timed_out is not None:
            entry['timed_out'] = error.timed_out
        if error.errno is not None:
            entry['errno'] = error.errno
        return entry
    return {'stage': allowed(stage, STAGES, 'unknown'),
            'reason': 'interrupted' if isinstance(error, (KeyboardInterrupt, SystemExit)) else allowed(reason, REASONS, 'operation_failed'),
            'error_class': allowed(type(error).__name__, ERROR_CLASSES, 'Exception'),
            'frames': traceback_frames(error.__traceback__)}


def record_entry(value, entry):
    if value['first_error'] is None:
        value['first_error'] = entry
        value['reason'] = entry['reason']
        value['error_class'] = entry['error_class']
        value['error_frames'] = entry['frames']
        callback = getattr(value, 'checkpoint', None)
        if callback is not None:
            try:
                value['first_error_persisted'] = callback() is True
            except HANDLED_ERRORS as error:
                value['first_error_persisted'] = False
                record_error(value, error, 'report_write', 'report_write_failed')
    elif len(value['secondary_errors']) < MAX_SECONDARY_ERRORS:
        value['secondary_errors'].append(entry)
    value['ok'] = False


def record_error(value, error, stage, reason='operation_failed'):
    record_entry(value, error_entry(error, stage, reason))
    if isinstance(error, NamespaceFailure):
        for entry in error.secondary_errors[:MAX_SECONDARY_ERRORS]:
            record_entry(value, entry)


def new_report():
    return Report({'schema': 1, 'ok': False, 'cases': [], 'first_error': None, 'secondary_errors': [],
            'raw_output_omitted': True,
            'isolation': {'independent_network_namespace': False, 'private_pid_namespace': False,
                          'only_loopback': False, 'external_routes': None, 'child_non_root': False,
                          'child_capabilities': None, 'no_new_privileges': False},
            'cleanup': {'temporary_directory_removed': False, 'owned_firewall_removed': False,
                        'no_remaining_children': False}})


def require(value, reason, *, stage=None):
    if not value:
        raise NamespaceFailure(reason, stage=stage)


def command(args, timeout=5, body=None, *, stage='namespace_command'):
    try:
        value = subprocess.run(args, input=body, capture_output=True, timeout=timeout,
                               env={'PATH': '/usr/sbin:/usr/bin:/sbin:/bin', 'LANG': 'C', 'LC_ALL': 'C'})
    except subprocess.TimeoutExpired as error:
        raise NamespaceFailure('namespace_tool_timeout', stage=stage, error=error, timed_out=True) from None
    except OSError as error:
        raise NamespaceFailure('namespace_tool_unavailable', stage=stage, error=error) from None
    except HANDLED_ERRORS as error:
        if isinstance(error, NamespaceFailure):
            raise
        reason = 'interrupted' if isinstance(error, (KeyboardInterrupt, SystemExit)) else 'namespace_tool_failed'
        raise NamespaceFailure(reason, stage=stage, error=error) from None
    if type(value.returncode) is not int or value.returncode != 0:
        raise NamespaceFailure('namespace_tool_failed', stage=stage, code=value.returncode, timed_out=False)
    return value.stdout


def command_json(args, *, stage):
    raw = command(args, stage=stage)
    try:
        if not isinstance(raw, bytes) or len(raw) > MAX_OUTPUT:
            raise ValueError()
        return json.loads(raw, object_pairs_hook=unique_object, parse_constant=invalid_constant)
    except (ValueError, TypeError, RecursionError) as error:
        raise NamespaceFailure('namespace_tool_output_invalid', stage=stage, error=error) from None


def topology(parent):
    require(sys.platform == 'linux' and os.geteuid() == 0 and os.getpid() == 1, 'fresh_unshare_root_pid_one_required')
    require(os.environ.get('KELI_IPUTILS_NAMESPACE') == '1' and set(os.environ) <= ALLOWED_ENV, 'clean_namespace_environment_required')
    require(re.fullmatch(r'net:\[[0-9]+\]', parent), 'parent_namespace_required')
    own = os.readlink('/proc/self/ns/net')
    require(own != parent and own == os.readlink('/proc/1/ns/net'), 'independent_network_namespace_required')
    links = command_json(['/usr/sbin/ip', '-j', 'link', 'show'], stage='topology_links')
    require(isinstance(links, list) and all(isinstance(row, dict) for row in links)
            and {row.get('ifname') for row in links} == {'lo'}, 'loopback_only_required', stage='topology_links')
    require(command_json(['/usr/sbin/ip', '-j', 'route', 'show', 'table', 'all'], stage='topology_routes') == [],
            'fresh_empty_routes_required', stage='topology_routes')
    ruleset = command_json(['/usr/sbin/nft', '-j', 'list', 'ruleset'], stage='topology_firewall')
    require(isinstance(ruleset, dict) and set(ruleset) <= {'nftables'} and isinstance(ruleset.get('nftables', []), list)
            and all(isinstance(row, dict) and set(row) == {'metainfo'} for row in ruleset.get('nftables', [])),
            'fresh_empty_firewall_required', stage='topology_firewall')
    return own


def counter():
    value = command_json(['/usr/sbin/nft', '-j', 'list', 'counter', 'inet', TABLE, COUNTER], stage='counter_read')
    require(isinstance(value, dict) and isinstance(value.get('nftables'), list)
            and all(isinstance(row, dict) for row in value['nftables']), 'dedicated_counter_required', stage='counter_read')
    rows = [r['counter'] for r in value['nftables'] if 'counter' in r]
    require(len(rows) == 1 and isinstance(rows[0], dict) and rows[0].get('name') == COUNTER
            and type(rows[0].get('packets')) is int and 0 <= rows[0]['packets'] < 2**64,
            'dedicated_counter_required', stage='counter_read')
    return rows[0]['packets']


def rule(mode):
    value = command_json(['/usr/sbin/nft', '-j', '-a', 'list', 'chain', 'inet', TABLE, 'output'], stage='rule_read')
    require(isinstance(value, dict) and isinstance(value.get('nftables'), list)
            and all(isinstance(row, dict) for row in value['nftables']), 'dedicated_rule_changed', stage='rule_read')
    rows = [r['rule'] for r in value['nftables'] if 'rule' in r]
    require(len(rows) <= 1 and all(isinstance(row, dict) for row in rows), 'dedicated_rule_changed', stage='rule_read')
    batch = ''
    if rows:
        require(type(rows[0].get('handle')) is int, 'rule_handle_required', stage='rule_read')
        batch = 'delete rule inet ' + TABLE + ' output handle ' + str(rows[0]['handle']) + '\n'
    batch += 'add rule inet ' + TABLE + ' output ip daddr {127.0.0.2,127.0.0.3,127.0.0.4,127.0.0.5} icmp type echo-request counter name ' + COUNTER + (' drop\n' if mode == 'drop' else ' accept\n')
    command(['/usr/sbin/nft', '-f', '-'], body=batch.encode(), stage='rule_update')


def run_child(args, uid, gid, home, mode, timeout, ping, *, observe=None, on_failure=None):
    env = {'PATH': '/usr/bin:/bin', 'LANG': 'C', 'LC_ALL': 'C', 'HOME': str(home),
           'PYTHONDONTWRITEBYTECODE': '1', 'KELI_IPUTILS_ISOLATED': '1', 'KELI_IPUTILS_MODE': mode,
           'KELI_IPUTILS_BINARY': str(ping)}
    try:
        process = subprocess.Popen(['/usr/bin/setpriv', '--reuid', str(uid), '--regid', str(gid), '--clear-groups',
                                    '--inh-caps=-all', '--ambient-caps=-all', '--bounding-set=-all', '--no-new-privs',
                                    *args], cwd=home, env=env, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                                   start_new_session=True)
    except OSError as error:
        raise NamespaceFailure('child_launch_failed', stage='child_launch', error=error) from None
    failure = None
    result = None
    stage = 'child_wait'
    try:
        raw, stderr = process.communicate(timeout=timeout)
        if len(raw) > MAX_OUTPUT or isinstance(stderr, bytes) and len(stderr) > MAX_OUTPUT:
            raise NamespaceFailure('test_output_oversized', stage='child_output', code=process.returncode)
        result = process.returncode, raw
        contamination = child_stderr_failure(stderr, process.returncode) if isinstance(stderr, bytes) and stderr else None
        if contamination is not None:
            try:
                payload = json.loads(raw, object_pairs_hook=unique_object, parse_constant=invalid_constant)
                structured = isinstance(payload, dict) and safe_child_error(payload.get('first_error'), process.returncode)
            except (ValueError, TypeError, RecursionError):
                structured = None
            if not structured:
                raise contamination
        if observe is not None:
            stage = 'child_result'
            observe(*result)
        if contamination is not None:
            raise contamination
    except subprocess.TimeoutExpired as error:
        failure = NamespaceFailure('child_timeout', stage='child_wait', error=error, timed_out=True)
    except HANDLED_ERRORS as error:
        reason = 'interrupted' if isinstance(error, (KeyboardInterrupt, SystemExit)) else 'child_wait_failed' if stage == 'child_wait' else 'operation_failed'
        failure = error if isinstance(error, NamespaceFailure) else NamespaceFailure(reason, stage=stage, error=error)

    if failure is not None and on_failure is not None:
        try:
            on_failure(failure)
        except HANDLED_ERRORS as error:
            failure.secondary_errors.append(error_entry(error, 'report_write', 'report_write_failed'))

    # Reap and close independently; none of these may replace the communicate failure.
    def kill_if_running():
        if process.poll() is None:
            os.killpg(process.pid, signal.SIGKILL)

    def close_stdout():
        if process.stdout is not None:
            process.stdout.close()

    def close_stderr():
        if process.stderr is not None:
            process.stderr.close()

    for stage, action in [('cleanup_child_kill', kill_if_running),
                          ('cleanup_child_wait', lambda: process.wait(timeout=2)),
                          ('cleanup_child_close', close_stdout), ('cleanup_child_close', close_stderr)]:
        try:
            action()
        except HANDLED_ERRORS as error:
            detail = error if isinstance(error, NamespaceFailure) else NamespaceFailure('child_cleanup_failed', stage=stage, error=error)
            if failure is None:
                if observe is None and result is not None and result[0] != 0:
                    failure = NamespaceFailure('child_signaled' if type(result[0]) is int and result[0] < 0 else 'child_exit_nonzero',
                                               stage='child_wait', code=result[0])
                    failure.secondary_errors.append(error_entry(detail, stage))
                else:
                    failure = detail
            else:
                failure.secondary_errors.append(error_entry(detail, stage))
    if failure is not None:
        if failure.code is None:
            failure.code = exit_code(process.returncode)
        raise failure from None
    return result


def no_remaining_children():
    # In this private PID namespace only our PID 1 is permitted to remain.
    return [int(p.name) for p in Path('/proc').iterdir() if p.name.isdigit() and p.name != '1']


def unique_object(pairs):
    value = {}
    for key, item in pairs:
        if key in value:
            raise ValueError()
        value[key] = item
    return value


def invalid_constant(value):
    raise ValueError()


def safe_child_error(value, code):
    if not isinstance(value, dict) or type(value.get('stage')) is not str or value['stage'] not in CHILD_STAGES \
            or type(value.get('reason')) is not str or value['reason'] not in CHILD_REASONS:
        return None
    result = {'stage': value['stage'], 'reason': value['reason'],
              'error_class': allowed(value.get('error_class'), ERROR_CLASSES, 'Exception'),
              'frames': safe_frames(value.get('frames'))}
    if exit_code(code) is not None:
        result['exit_code'] = code
    if type(value.get('errno')) is int and 0 <= value['errno'] <= 65535:
        result['errno'] = value['errno']
    return result


def child_stderr_failure(raw, code):
    # Parse fixed classes/locations only; never retain traceback text or messages.
    error = NamespaceFailure('child_output_contaminated', stage='child_output', code=code)
    text = raw.decode('utf-8', errors='replace')
    classes = re.findall(r'^(ModuleNotFoundError|ImportError|SyntaxError|PermissionError|FileNotFoundError):', text, re.M)
    if classes:
        name = classes[-1]
        error.reason = 'child_import_failed' if name in {'ModuleNotFoundError', 'ImportError', 'SyntaxError'} else 'child_permission_denied' if name == 'PermissionError' else 'child_interpreter_failed'
        error.stage = 'child_import' if error.reason == 'child_import_failed' else 'child_launch'
        error.error_class = name
    elif re.search(r'^(?:/usr/bin/)?python3: can.t open file .*\[Errno (?:2|13)\]', text, re.M):
        error.reason, error.stage = 'child_interpreter_failed', 'child_launch'
        denied = '[Errno 13]' in text
        error.error_class, error.errno = ('PermissionError', 13) if denied else ('FileNotFoundError', 2)
    elif re.search(r'^setpriv: .*: Permission denied\s*$', text, re.M):
        error.reason, error.stage, error.error_class, error.errno = 'child_permission_denied', 'child_launch', 'PermissionError', 13
    frames = []
    for filename, line in re.findall(r'^  File "([^"\r\n]{1,512})", line ([0-9]{1,6})(?:,.*)?$', text, re.M):
        try:
            path = Path(filename)
            if path.is_absolute() and path.parent == HERE and path.name in FRAME_FILES:
                frames.append({'file': path.name, 'line': int(line)})
        except (ValueError, OSError):
            pass
    error.frames = safe_frames(frames)
    return error


def safe_measurement(row):
    if not isinstance(row, dict):
        return None
    result = {}
    for key, choices in [('state', MEASUREMENT_STATES), ('classification', CLASSIFICATIONS)]:
        if type(row.get(key)) is not str or row[key] not in choices:
            return None
        result[key] = row[key]
    if 'exit_code' not in row or (row['exit_code'] is not None and exit_code(row['exit_code']) is None):
        return None
    result['exit_code'] = row['exit_code']
    for key in ['sent', 'received', 'reported_errors']:
        if key not in row or (row[key] is not None and not (type(row[key]) is int and 0 <= row[key] < 2**32)):
            return None
        result[key] = row[key]
    categories = row.get('error_categories')
    if not isinstance(categories, list) or len(categories) > len(ERROR_CATEGORIES) or any(
            type(item) is not str or item not in ERROR_CATEGORIES for item in categories):
        return None
    result['error_categories'] = categories
    return result


def child_result(raw, name, code):
    try:
        if not isinstance(raw, bytes) or len(raw) > MAX_OUTPUT:
            raise ValueError()
        value = json.loads(raw, object_pairs_hook=unique_object, parse_constant=invalid_constant)
    except (ValueError, TypeError, RecursionError) as error:
        raise NamespaceFailure('child_output_invalid', stage='child_output', error=error, code=code) from None
    result = {'case': name, 'ok': False, 'raw_output_retained': False}
    if exit_code(code) is not None:
        result['exit_code'] = code
    if not isinstance(value, dict):
        return result, 'child_result_invalid'
    reason = None
    if value.get('case') != name or type(value.get('ok')) is not bool:
        reason = 'child_result_invalid'
    for key in ['tests', 'failures', 'errors', 'skipped', 'expected_packets']:
        if key not in value:
            reason = reason or 'child_result_incomplete'
        elif type(value[key]) is int and 0 <= value[key] <= (4 if key == 'expected_packets' else 1):
            result[key] = value[key]
        else:
            reason = reason or 'child_result_invalid'
    measurements = value.get('measurements')
    if not isinstance(measurements, list) or len(measurements) > 4:
        reason = reason or ('child_result_incomplete' if measurements is None else 'child_result_invalid')
    else:
        result['measurements'] = []
        for row in measurements:
            safe = safe_measurement(row)
            if safe is None:
                reason = reason or 'child_result_invalid'
            else:
                result['measurements'].append(safe)
        if value.get('ok') is True and len(result['measurements']) != (4 if name == 'four_no_reply_targets' else 1):
            reason = reason or 'child_result_incomplete'
    if type(value.get('legacy_extra_packets_reproduced')) is bool:
        result['legacy_extra_packets_reproduced'] = value['legacy_extra_packets_reproduced']
    details = value.get('failure_details')
    if isinstance(details, list):
        result['failure_details'] = [{'error_class': allowed(detail.get('error_class'), ERROR_CLASSES, 'Exception'),
                                      'frames': safe_frames(detail.get('frames'))}
                                     for detail in details[:MAX_SECONDARY_ERRORS] if isinstance(detail, dict)]
    launch = value.get('launch_error')
    if isinstance(launch, dict):
        result['launch_error'] = {'error_class': allowed(launch.get('error_class'), ERROR_CLASSES, 'Exception')}
        if type(launch.get('errno')) is int and 0 <= launch['errno'] <= 4095:
            result['launch_error']['errno'] = launch['errno']
    if 'first_error' in value:
        first = safe_child_error(value['first_error'], code)
        if first is None:
            reason = reason or 'child_result_invalid'
        else:
            result['first_error'] = first
    secondary = value.get('secondary_errors', [])
    if not isinstance(secondary, list) or len(secondary) > MAX_SECONDARY_ERRORS:
        reason = reason or 'child_result_invalid'
    else:
        result['secondary_errors'] = []
        for entry in secondary:
            safe = safe_child_error(entry, code)
            if safe is None:
                reason = reason or 'child_result_invalid'
            else:
                result['secondary_errors'].append(safe)
    result['ok'] = (reason is None and 'first_error' not in result and not result.get('secondary_errors')
                    and value.get('ok') is True and result.get('tests') == 1
                    and all(result.get(key) == 0 for key in ['failures', 'errors', 'skipped']))
    return result, reason


def record_child_failure(value, observed, code):
    if isinstance(observed.get('first_error'), dict):
        record_entry(value, observed['first_error'])
        for entry in observed.get('secondary_errors', [])[:MAX_SECONDARY_ERRORS]:
            record_entry(value, entry)
        return
    details = observed.get('failure_details', [])
    reason = 'child_signaled' if type(code) is int and code < 0 else 'child_exit_nonzero' if code != 0 else 'child_case_failed'
    if details:
        reason = 'child_case_failed'
    entry = {'stage': 'child_result', 'reason': reason,
             'error_class': details[0]['error_class'] if details else 'RuntimeError',
             'frames': details[0]['frames'] if details else []}
    if exit_code(code) is not None:
        entry['exit_code'] = code
    record_entry(value, entry)


def in_namespace():
    return os.getpid() == 1 and os.environ.get('KELI_IPUTILS_NAMESPACE') == '1'


def checkpoint_error(options, value):
    if not in_namespace() or value['first_error'] is None:
        return False
    output = Path(options.output).absolute()
    require(not any(os.path.lexists(path / 'closed.json') for path in (output.parent, *output.parent.parents)), 'report_write_failed')
    path = output.with_name(output.stem + '.first-error.json')
    record = {'schema': 1, 'first_error': value['first_error']}
    try:
        descriptor = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | getattr(os, 'O_NOFOLLOW', 0), 0o600)
    except FileExistsError:
        require(not path.is_symlink() and path.is_file() and path.stat().st_size <= 16384, 'report_write_failed')
        require(json.loads(path.read_text(encoding='utf-8')) == record, 'report_write_failed')
        return True
    with os.fdopen(descriptor, 'w', encoding='utf-8') as stream:
        json.dump(record, stream, allow_nan=False)
        stream.write('\n')
        stream.flush()
        os.fsync(stream.fileno())
    return True


def write_report(options, value):
    if not in_namespace():
        return
    try:
        output = Path(options.output).resolve()
        with output.open('x', encoding='utf-8') as stream:
            json.dump(value, stream, indent=2, allow_nan=False)
            stream.write('\n')
    except HANDLED_ERRORS as error:
        record_error(value, error, 'report_write', 'report_write_failed')


def run(options, *, report=None, persist=True, checkpoint=None):
    value = new_report() if report is None else report
    if isinstance(value, Report):
        value.checkpoint = (lambda: checkpoint_error(options, value)) if (persist if checkpoint is None else checkpoint) else None
    own = None
    root = None
    table_attempted = False
    stage = 'topology'
    expected = [name for name, _ in CASES] + (['rust_echo', 'rust_drop'] if options.rust_test else [])
    try:
        own = topology(options.parent_netns)
        value['isolation'].update(independent_network_namespace=True, private_pid_namespace=True,
                                  only_loopback=True, external_routes=0)
        value['namespace_ref_sha256'] = hashlib.sha256(own.encode()).hexdigest()
        stage = 'identity'
        require(options.uid > 0 and options.gid > 0, 'non_root_test_identity_required')
        value['isolation'].update(child_non_root=True, child_capabilities=0, no_new_privileges=True)
        stage = 'iputils_version'
        version = command(['/usr/bin/ping', '-V'], stage=stage).decode(errors='replace')
        require('iputils' in version, 'real_iputils_required')
        value['iputils_version_sha256'] = hashlib.sha256(version.encode()).hexdigest()
        stage = 'workspace'
        root = Path(tempfile.mkdtemp(prefix='keli-iputils-namespace-'))
        root.chmod(0o755)
        stage = 'iputils_copy'
        # Copy bytes, not file capabilities: zero-capability children must not gain CAP_NET_RAW.
        ping = root / 'iputils-ping'
        shutil.copyfile('/usr/bin/ping', ping)
        ping.chmod(0o555)
        require('security.capability' not in os.listxattr(ping), 'file_capabilities_not_allowed')
        system_pin = hashlib.sha256(Path('/usr/bin/ping').read_bytes()).hexdigest()
        require(hashlib.sha256(ping.read_bytes()).hexdigest() == system_pin, 'iputils_copy_bytes_changed')
        value['iputils_binary_sha256'] = system_pin
        value['capability_free_copy_matches_system_binary'] = True
        command(['/usr/sbin/ip', 'link', 'set', 'lo', 'up'], stage='loopback_up')
        table_attempted = True
        command(['/usr/sbin/nft', '-f', '-'], body=('add table inet ' + TABLE + '\nadd counter inet ' + TABLE + ' ' + COUNTER
            + '\nadd chain inet ' + TABLE + ' output { type filter hook output priority -50; policy accept; }\n').encode(), stage='firewall_setup')
        for name, mode in CASES:
            value['current_case'] = name
            observed = {'case': name, 'ok': False, 'raw_output_retained': False}
            value['cases'].append(observed)
            stage = 'case_setup'
            Path('/proc/sys/net/ipv4/ping_group_range').write_text(str(options.gid) + ' ' + str(options.gid) if mode != 'permission' else '65534 65534')
            require(options.gid != 65534, 'test_gid_conflicts_with_denial_group')
            rule('drop' if mode == 'drop' else 'echo')
            home = root / name
            home.mkdir(mode=0o700)
            os.chown(home, options.uid, options.gid)
            stage = 'counter_read'
            before = counter()
            stage = 'child_launch'

            def observe(code, raw):
                nonlocal child_ok
                if exit_code(code) is not None:
                    observed['exit_code'] = code
                safe, invalid = child_result(raw, name, code)
                observed.update(safe)
                child_ok = observed['ok']
                observed['ok'] = False
                if observed.get('first_error') or observed.get('failure_details') or any(observed.get(key, 0) > 0 for key in ['failures', 'errors', 'skipped']):
                    record_child_failure(value, observed, code)
                if invalid:
                    raise NamespaceFailure(invalid, stage='child_result', code=code)
                if (code != 0 or not child_ok) and not (observed.get('first_error') or observed.get('failure_details') or any(
                        observed.get(key, 0) > 0 for key in ['failures', 'errors', 'skipped'])):
                    record_child_failure(value, observed, code)

            child_ok = False
            code, _ = run_child(['/usr/bin/python3', '-I', '-B', str(HERE / 'test_entry_probe_iputils.py'), name],
                                options.uid, options.gid, home, mode, 16, ping, observe=observe,
                                on_failure=lambda error: record_error(value, error, 'child_wait'))
            stage = 'case_counter'
            delta = counter() - before
            observed.update(kernel_echo_requests=delta, packet_counter_matches=delta == observed.get('expected_packets'))
            if not observed['packet_counter_matches']:
                record_error(value, NamespaceFailure('packet_counter_mismatch', code=code), 'case_counter')
            stage = 'case_children'
            observed['no_remaining_children'] = not no_remaining_children()
            if not observed['no_remaining_children']:
                record_error(value, NamespaceFailure('remaining_children', code=code), stage)
            observed['ok'] = code == 0 and child_ok and observed['packet_counter_matches'] and observed['no_remaining_children']
        if options.rust_test:
            stage = 'rust_binary'
            binary = Path(options.rust_test).resolve()
            require(binary.is_file() and os.access(binary, os.X_OK) and binary.name.startswith('keli_entry_probe_isolated_tests-'), 'scoped_rust_test_binary_required')
            value['rust_binary_sha256'] = hashlib.sha256(binary.read_bytes()).hexdigest()
            for mode in ['echo', 'drop']:
                value['current_case'] = 'rust_' + mode
                observed = {'case': 'rust_' + mode, 'ok': False, 'expected_packets': 1, 'raw_output_retained': False}
                value['cases'].append(observed)
                stage = 'case_setup'
                Path('/proc/sys/net/ipv4/ping_group_range').write_text(str(options.gid) + ' ' + str(options.gid))
                rule(mode)
                home = root / ('rust-' + mode)
                home.mkdir(mode=0o700)
                os.chown(home, options.uid, options.gid)
                stage = 'counter_read'
                before = counter()
                stage = 'child_launch'

                def observe_rust(code, raw):
                    observed['exit_success'] = code == 0
                    if exit_code(code) is not None:
                        observed['exit_code'] = code
                    if code != 0:
                        record_child_failure(value, observed, code)

                code, _ = run_child([str(binary), '--ignored', '--exact', 'entry_probe::tests::real_iputils_in_isolated_namespace',
                                    '--test-threads=1'], options.uid, options.gid, home, mode, 8, ping, observe=observe_rust,
                                    on_failure=lambda error: record_error(value, error, 'child_wait'))
                stage = 'case_counter'
                delta = counter() - before
                observed.update(kernel_echo_requests=delta, packet_counter_matches=delta == 1)
                if delta != 1:
                    record_error(value, NamespaceFailure('packet_counter_mismatch', code=code), stage)
                stage = 'case_children'
                observed['no_remaining_children'] = not no_remaining_children()
                if not observed['no_remaining_children']:
                    record_error(value, NamespaceFailure('remaining_children', code=code), stage)
                observed['ok'] = code == 0 and delta == 1 and observed['no_remaining_children']
    except HANDLED_ERRORS as error:
        record_error(value, error, stage)

    def clean_children():
        if own is not None:
            value['cleanup']['no_remaining_children'] = not no_remaining_children()

    def clean_firewall():
        if not table_attempted:
            value['cleanup']['owned_firewall_removed'] = True
            return
        command(['/usr/sbin/nft', 'delete', 'table', 'inet', TABLE], stage='cleanup_firewall_delete')
        check = command_json(['/usr/sbin/nft', '-j', 'list', 'ruleset'], stage='cleanup_firewall_check')
        require(isinstance(check, dict) and set(check) <= {'nftables'} and isinstance(check.get('nftables', []), list)
                and all(isinstance(row, dict) and len(row) == 1 and set(row) <= {'metainfo', 'table', 'chain', 'rule', 'counter'}
                        and all(isinstance(item, dict) for item in row.values()) for row in check.get('nftables', [])),
                'cleanup_incomplete', stage='cleanup_firewall_check')
        value['cleanup']['owned_firewall_removed'] = not any(
            row.get('table', {}).get('name') == TABLE for row in check.get('nftables', []))

    def clean_directory():
        if root is not None:
            shutil.rmtree(root)
        value['cleanup']['temporary_directory_removed'] = root is None or not root.exists()

    for stage, action, flag in [('cleanup_children', clean_children, 'no_remaining_children'),
                                 ('cleanup_firewall_delete', clean_firewall, 'owned_firewall_removed'),
                                 ('cleanup_directory', clean_directory, 'temporary_directory_removed')]:
        try:
            action()
            if own is not None and value['cleanup'][flag] is not True:
                record_error(value, NamespaceFailure('cleanup_incomplete'), stage)
        except HANDLED_ERRORS as error:
            record_error(value, error, stage)
    complete = [row['case'] for row in value['cases']] == expected and all(row['ok'] is True for row in value['cases'])
    if not complete and value['first_error'] is None:
        record_error(value, NamespaceFailure('cases_incomplete'), 'complete')
    value['ok'] = complete and value['first_error'] is None and all(flag is True for flag in value['cleanup'].values())
    if persist:
        write_report(options, value)
    return value


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--uid', type=int, required=True)
    parser.add_argument('--gid', type=int, required=True)
    parser.add_argument('--parent-netns', required=True)
    parser.add_argument('--output', required=True)
    parser.add_argument('--rust-test')
    options = parser.parse_args()
    value = new_report()
    previous_handlers = []

    def interrupted(number, frame):
        raise NamespaceFailure('signal_received', stage='signal', code=-number)

    try:
        if in_namespace():
            for name in ['SIGTERM', 'SIGINT', 'SIGHUP']:
                number = getattr(signal, name, None)
                if number is not None:
                    previous_handlers.append((number, signal.signal(number, interrupted)))
        run(options, report=value, persist=False, checkpoint=True)
    except HANDLED_ERRORS as error:
        record_error(value, error, 'main')
    finally:
        for number, handler in reversed(previous_handlers):
            try:
                signal.signal(number, handler)
            except HANDLED_ERRORS as error:
                record_error(value, error, 'signal')
    write_report(options, value)
    print(json.dumps(value, allow_nan=False))
    return 0 if value['ok'] else 1


if __name__ == '__main__':
    raise SystemExit(main())
