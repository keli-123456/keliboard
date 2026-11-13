"""Pure, bounded runtime evidence projection; no launcher or execution authority."""
import ipaddress
import json
import os
from pathlib import Path
import re
import sys

IPUTILS_CASES = ('echo', 'no_reply', 'four_no_reply_targets', 'legacy_deadline_reference',
                 'forced_timeout_reaps_real_ping', 'permission_denied_is_not_packet_loss')
STAGES = frozenset({'unknown', 'initialization', 'topology', 'prerequisites', 'namespace_setup',
    'runtime', 'runtime_read', 'runtime_projection', 'socket_before_probe', 'socket_after_probe',
    'protocol', 'iputils', 'iputils_read', 'iputils_projection', 'case_execution', 'case_decode',
    'case_counter', 'ping_version', 'ping_copy', 'loopback_setup', 'firewall_setup',
    'firewall_rule', 'firewall_counter', 'cleanup', 'process_cleanup', 'namespace_cleanup',
    'firewall_cleanup', 'directory_cleanup', 'resource_summary', 'report_write', 'signal'})
STAGES |= frozenset({'topology_links', 'topology_routes', 'topology_firewall', 'identity', 'iputils_version',
    'workspace', 'iputils_copy', 'loopback_up', 'rule_read', 'rule_update', 'counter_read', 'case_setup',
    'child_launch', 'child_wait', 'child_output', 'child_result', 'case_children', 'case_result', 'rust_binary',
    'cleanup_child_kill', 'cleanup_child_wait', 'cleanup_child_close', 'cleanup_children',
    'cleanup_firewall_delete', 'cleanup_firewall_check', 'cleanup_directory', 'complete', 'main', 'namespace_command'})
STAGES |= frozenset({'child_arguments', 'child_import', 'child_test'})
REASONS = frozenset({'unclassified_failure', 'incomplete_runtime_evidence', 'runtime_report_counter_invalid',
    'runtime_build_report_invalid', 'six_iputils_cases_required', 'iputils_packet_count_invalid',
    'iputils_case_failed', 'iputils_report_invalid', 'iputils_cleanup_unverified', 'iputils_isolation_unverified',
    'iputils_child_failed', 'runtime_child_failed', 'report_missing_or_invalid', 'first_error_write_failed',
    'socket_table_invalid', 'socket_owner_changed', 'socket_owner_unavailable', 'socket_budget_exceeded',
    'non_loopback_bind_forbidden', 'listeners_still_bound', 'isolation_unverified',
    'namespace_tool_failed', 'namespace_tool_timeout', 'namespace_tool_launch_failed',
    'namespace_tool_output_oversized', 'child_json_invalid', 'child_report_invalid', 'child_exit_failed',
    'test_output_oversized', 'fresh_unshare_root_pid_one_required', 'clean_namespace_environment_required',
    'parent_namespace_required', 'independent_network_namespace_required', 'loopback_only_required',
    'fresh_empty_routes_required', 'fresh_empty_firewall_required', 'dedicated_counter_required',
    'dedicated_rule_changed', 'rule_handle_required', 'non_root_test_identity_required',
    'real_iputils_required', 'file_capabilities_not_allowed', 'iputils_copy_bytes_changed',
    'test_gid_conflicts_with_denial_group', 'scoped_rust_test_binary_required',
    'child_output_budget', 'child_timeout', 'child_cleanup_failed', 'namespace_inspection_failed',
    'external_route_forbidden', 'exported_socks_helpers_missing', 'non_loopback_config_forbidden',
    'fixture_server_failed', 'echo_length_invalid', 'tcp_echo_mismatch', 'udp_echo_mismatch',
    'non_loopback_udp_relay', 'build_info_failed', 'invalid_build_version', 'required_build_flags_missing',
    'version_mismatch', 'sing_box_version_mismatch', 'doctor_binary_identity_mismatch', 'node_early_exit',
    'node_readiness_timeout', 'client_early_exit', 'client_readiness_timeout', 'local_certificate_failed',
    'client_config_check_failed', 'runtime_early_exit', 'post_probe_doctor_failed', 'namespace_reaping_failed',
    'deadline_or_signal', 'worker_identity_mismatch', 'private_pid_namespace_required',
    'private_network_namespace_required', 'capability_free_no_new_privs_required',
    'nonroot_without_groups_required', 'temporary_cleanup_failed', 'oom_detected',
    'staged_hash_mismatch', 'unsafe_staged_file', 'invalid_staged_size', 'staged_binary_not_executable',
    'fixed_staging_root_required', 'script_not_in_staging_root', 'unsafe_staging_directory',
    'cgroup_v2_required', 'dedicated_cgroup_required', 'finite_cgroup_limits_required', 'cgroup_limits_too_large',
    'invalid_cgroup_pid', 'namespace_setup_root_required', 'private_tmp_mount_failed', 'loopback_up_failed',
    'namespace_report_missing_or_oversized', 'namespace_report_invalid', 'linux_root_entry_required',
    'fresh_report_path_required', 'dedicated_empty_service_required', 'staged_host_prerequisite_missing',
    'cgroup_limits_changed', 'dedicated_nonroot_identity_required', 'internal_namespace_arguments_required',
    'linux_only', 'namespace_exec_returned'})
REASONS |= frozenset({'namespace_tool_unavailable', 'namespace_tool_output_invalid', 'child_launch_failed',
    'child_wait_failed', 'child_output_invalid', 'child_result_invalid', 'child_result_incomplete',
    'child_case_failed', 'child_exit_nonzero', 'child_signaled', 'packet_counter_mismatch', 'remaining_children',
    'cases_incomplete', 'cleanup_incomplete', 'report_write_failed', 'signal_received', 'interrupted', 'operation_failed'})
REASONS |= frozenset({'child_arguments_invalid', 'child_import_failed', 'child_output_contaminated',
                     'child_permission_denied', 'child_interpreter_failed'})
CLASSES = frozenset({'OSError', 'FileNotFoundError', 'PermissionError', 'ProcessLookupError', 'TimeoutError',
    'ConnectionError', 'ConnectionResetError', 'ConnectionRefusedError', 'BrokenPipeError', 'ValueError',
    'TypeError', 'KeyError', 'IndexError', 'RuntimeError', 'JSONDecodeError', 'AttributeError', 'ImportError',
    'ModuleNotFoundError', 'SyntaxError', 'AssertionError', 'InterruptedError', 'KeyboardInterrupt',
    'SystemExit', 'TimeoutExpired', 'SSLError', 'Failure', 'DiagnosticError', 'NamespaceFailure', 'OtherError'})
CLASSES |= frozenset({'Exception', 'CalledProcessError', 'ChildProcessError', 'FileExistsError', 'IsADirectoryError',
    'MemoryError', 'NotADirectoryError', 'OverflowError', 'RecursionError', 'SubprocessError', 'UnicodeDecodeError', 'UnicodeError'})
FRAME_FILES = frozenset({'remote_validate.py', 'runtime_diagnostics.py', 'iputils_namespace.py',
    'test_entry_probe_iputils.py', 'entry_probe_diagnostics.py', 'bootstrap_diagnostics.py', 'subprocess.py'})
MAX_ERRORS = 8


class DiagnosticError(RuntimeError):
    def __init__(self, code):
        self.code = code
        super().__init__(code)


def require(condition, code):
    if not condition:
        raise DiagnosticError(code)


def count(value, maximum=100000):
    return value if type(value) is int and 0 <= value <= maximum else None


def load_object(raw):
    def pairs(items):
        result = {}
        for key, value in items:
            require(key not in result, 'report_missing_or_invalid')
            result[key] = value
        return result
    def invalid(value):
        raise DiagnosticError('report_missing_or_invalid')
    value = json.loads(raw, object_pairs_hook=pairs, parse_constant=invalid)
    require(isinstance(value, dict), 'report_missing_or_invalid')
    return value


def project_error(value, stage='unknown'):
    if not isinstance(value, dict):
        error = value
        frames, tb = [], getattr(error, '__traceback__', None)
        for _ in range(32):
            if tb is None:
                break
            name = Path(tb.tb_frame.f_code.co_filename).name
            if name in FRAME_FILES:
                frames.append({'file': name, 'line': tb.tb_lineno})
            tb = tb.tb_next
        code = getattr(error, 'code', None)
        value = {'error_class': type(error).__name__, 'reason': getattr(error, 'reason', code),
                 'stage': getattr(error, 'stage', None), 'frames': frames,
                 'exit_code': code if type(code) is int else None,
                 'errno': getattr(error, 'errno', None), 'timed_out': getattr(error, 'timed_out', None)}
    reason = value.get('reason', value.get('error_code'))
    candidate_stage = value.get('stage')
    result = {'stage': candidate_stage if isinstance(candidate_stage, str) and candidate_stage in STAGES else stage if stage in STAGES else 'unknown',
              'reason': reason if isinstance(reason, str) and reason in REASONS else 'unclassified_failure'}
    name = value.get('error_class')
    if name is not None:
        result['error_class'] = name if isinstance(name, str) and name in CLASSES else 'OtherError'
    for key in ('exit_code', 'errno'):
        number = value.get(key)
        if type(number) is int and (-255 <= number <= 255 if key == 'exit_code' else 0 <= number <= 65535):
            result[key] = number
    if type(value.get('timed_out')) is bool:
        result['timed_out'] = value['timed_out']
    if value.get('case') in IPUTILS_CASES:
        result['case'] = value['case']
    result['frames'] = []
    frames = value.get('frames', value.get('error_frames', []))
    if isinstance(frames, list):
        for frame in frames[-6:]:
            if isinstance(frame, dict) and isinstance(frame.get('file'), str) and frame['file'] in FRAME_FILES and type(frame.get('line')) is int and 1 <= frame['line'] <= 100000:
                result['frames'].append({'file': frame['file'], 'line': frame['line']})
    return result


def remember_error(report, error, stage='unknown'):
    """First error is immutable in the report; later cleanup failures stay secondary."""
    item = project_error(error, stage)
    report['ok'] = False
    if not isinstance(report.get('first_error'), dict):
        report['first_error'] = item
    else:
        report['first_error'] = project_error(report['first_error'])
        extra = report.setdefault('secondary_errors', [])
        if len(extra) < MAX_ERRORS:
            extra.append(item)
        else:
            report['secondary_errors_dropped'] = (count(report.get('secondary_errors_dropped')) or 0) + 1
    first = report['first_error']
    report['error_code'] = first['reason']
    if 'error_class' in first:
        report['error_class'] = first['error_class']
    return item


def inherit_errors(report, source, stage):
    if not isinstance(source, dict):
        return
    primary = source.get('first_error')
    if isinstance(primary, dict):
        remember_error(report, primary, stage)
    elif any(key in source for key in ('error_code', 'reason', 'error_class')):
        remember_error(report, source, stage)
    secondary = source.get('secondary_errors', [])
    if isinstance(secondary, list):
        for item in secondary[:MAX_ERRORS]:
            if isinstance(item, dict):
                remember_error(report, item, stage)


def isolation_verified(value):
    return isinstance(value, dict) and all(value.get(key) is True for key in (
        'independent_network_namespace', 'private_pid_namespace', 'only_loopback', 'child_non_root',
        'no_new_privileges')) and type(value.get('external_routes')) is int and value['external_routes'] == 0 \
        and type(value.get('child_capabilities')) is int and value['child_capabilities'] == 0


def project_iputils(value):
    out = {'ok': False, 'cases': [], 'cases_complete': False, 'cleanup_verified': False,
           'isolation_verified': False, 'rust_test_executed': False, 'validation_errors': []}
    if not isinstance(value, dict):
        remember_error(out, {'reason': 'iputils_report_invalid'}, 'iputils_projection')
        return out
    inherit_errors(out, value, 'iputils')
    if value.get('current_case') in IPUTILS_CASES:
        out['current_case'] = value['current_case']
    rows = value.get('cases', [])
    out['cases_complete'] = isinstance(rows, list) and [row.get('case') if isinstance(row, dict) else None for row in rows] == list(IPUTILS_CASES)
    if not out['cases_complete']:
        out['validation_errors'].append('six_iputils_cases_required')
    for row in rows[:8] if isinstance(rows, list) else []:
        if not isinstance(row, dict) or row.get('case') not in IPUTILS_CASES:
            continue
        summary = {'case': row['case'], 'ok': False}
        for key in ('tests', 'skipped', 'errors', 'failures', 'kernel_echo_requests', 'expected_packets'):
            summary[key] = count(row.get(key), 4)
        summary['ok'] = (row.get('ok') is True and summary['tests'] == 1 and summary['skipped'] == 0
            and summary['errors'] == 0 and summary['failures'] == 0
            and summary['kernel_echo_requests'] is not None and summary['expected_packets'] is not None
            and summary['kernel_echo_requests'] == summary['expected_packets']
            and row.get('packet_counter_matches') is True and row.get('no_remaining_children') is True)
        inherit_errors(summary, row, 'case_execution')
        if not summary['ok'] and 'first_error' not in summary:
            remember_error(summary, {'reason': 'iputils_case_failed', 'case': row['case']}, 'case_execution')
        out['cases'].append(summary)
    cleanup = value.get('cleanup', {})
    out['cleanup_verified'] = isinstance(cleanup, dict) and all(cleanup.get(key) is True for key in (
        'temporary_directory_removed', 'owned_firewall_removed', 'no_remaining_children'))
    out['isolation_verified'] = isolation_verified(value.get('isolation'))
    if not out['cleanup_verified']:
        out['validation_errors'].append('iputils_cleanup_unverified')
    if not out['isolation_verified']:
        out['validation_errors'].append('iputils_isolation_unverified')
    for row in out['cases']:
        if not row['ok']:
            inherit_errors(out, row, 'case_execution')
    for reason in out['validation_errors']:
        remember_error(out, {'reason': reason}, 'iputils_projection')
    if value.get('ok') is not True and 'first_error' not in out:
        remember_error(out, {'reason': 'iputils_child_failed'}, 'iputils')
    out['ok'] = (value.get('ok') is True and out['cases_complete'] and all(row['ok'] for row in out['cases'])
                 and out['cleanup_verified'] and out['isolation_verified'] and 'first_error' not in out)
    return out


def _endpoint(raw, family):
    address, port = raw.split(':')
    require(len(address) == (8 if family == 4 else 32) and re.fullmatch('[0-9A-Fa-f]+', address)
            and re.fullmatch('[0-9A-Fa-f]{4}', port), 'socket_table_invalid')
    packed = b''.join(int(address[i:i + 8], 16).to_bytes(4, sys.byteorder) for i in range(0, len(address), 8))
    return str(ipaddress.ip_address(packed)), int(port, 16)


def parse_socket_table(raw, table):
    require(table in {'tcp', 'tcp6', 'udp', 'udp6'} and isinstance(raw, str) and len(raw) <= 1048576, 'socket_table_invalid')
    result = []
    for line in raw.splitlines()[1:]:
        fields = line.split()
        require(len(fields) >= 10 and re.fullmatch('[0-9A-Fa-f]{2}', fields[3]) and fields[9].isdigit(), 'socket_table_invalid')
        family = 6 if table.endswith('6') else 4
        local, port = _endpoint(fields[1], family)
        remote, peer = _endpoint(fields[2], family)
        result.append({'family': family, 'protocol': table[:3], 'state': fields[3].upper(),
                       'local_address': local, 'local_port': port, 'remote_address': remote,
                       'remote_port': peer, 'inode': int(fields[9])})
        require(len(result) <= 512, 'socket_budget_exceeded')
    return result


def read_socket_tables(proc=Path('/proc')):
    rows = []
    for table in ('tcp', 'tcp6', 'udp', 'udp6'):
        try:
            with (proc / 'net' / table).open('r', encoding='ascii') as stream:
                raw = stream.read(1048577)
        except FileNotFoundError:
            require(table.endswith('6'), 'socket_table_invalid')
            continue
        rows.extend(parse_socket_table(raw, table))
    require(len(rows) <= 512, 'socket_budget_exceeded')
    return rows


def process_start_ticks(pid, proc=Path('/proc')):
    require(type(pid) is int and pid > 1, 'socket_owner_unavailable')
    raw = (proc / str(pid) / 'stat').read_text(encoding='ascii')
    tail = raw.rsplit(')', 1)[1].split()
    require(len(tail) >= 20 and tail[19].isdigit(), 'socket_owner_unavailable')
    return int(tail[19])


def process_socket_inodes(pid, start_ticks, proc=Path('/proc')):
    require(process_start_ticks(pid, proc) == start_ticks, 'socket_owner_changed')
    entries = list((proc / str(pid) / 'fd').iterdir())
    require(len(entries) <= 1024, 'socket_budget_exceeded')
    result = set()
    for entry in entries:
        try:
            link = os.readlink(entry)
        except FileNotFoundError:
            continue
        match = re.fullmatch(r'socket:\[([1-9][0-9]*)\]', link)
        if match:
            result.add(int(match.group(1)))
    require(process_start_ticks(pid, proc) == start_ticks, 'socket_owner_changed')
    return result


def socket_scope(rows, *, listeners, owners, isolation, phase, ephemeral_range):
    require(isinstance(rows, list) and len(rows) <= 512, 'socket_budget_exceeded')
    require(phase in {'before_probe', 'after_probe'}, 'socket_table_invalid')
    require(type(ephemeral_range) is tuple and len(ephemeral_range) == 2
            and all(type(x) is int for x in ephemeral_range) and 1024 <= ephemeral_range[0] <= ephemeral_range[1] <= 65535,
            'socket_table_invalid')
    require(isinstance(listeners, set) and all(isinstance(x, tuple) and len(x) == 2
            and x[0] in {'tcp', 'udp'} and type(x[1]) is int and 1 <= x[1] <= 65535 for x in listeners), 'socket_table_invalid')
    require(isinstance(owners, dict) and set(owners) <= {'node', 'client'} and all(
        isinstance(ids, set) and all(type(n) is int and n > 0 for n in ids) for ids in owners.values()), 'socket_owner_unavailable')
    summary = {'ok': True, 'phase': phase, 'checked_sockets': 0, 'private_outbound_udp': [], 'violations': []}
    for row in rows:
        protocol, port = row['protocol'], row['local_port']
        if not port or protocol == 'tcp' and row['state'] != '0A':
            continue
        summary['checked_sockets'] += 1
        local, remote = ipaddress.ip_address(row['local_address']), ipaddress.ip_address(row['remote_address'])
        roles = sorted(role for role, ids in owners.items() if row['inode'] in ids)
        if local.is_loopback and (remote.is_loopback or remote.is_unspecified):
            continue
        permitted = (phase == 'after_probe' and protocol == 'udp' and row['state'] in {'01', '07'}
            and local.is_unspecified and ((row['state'] == '01' and remote.is_loopback and 1 <= row['remote_port'] <= 65535)
                or (row['state'] == '07' and remote.is_unspecified and row['remote_port'] == 0))
            and (protocol, port) not in listeners and ephemeral_range[0] <= port <= ephemeral_range[1]
            and len(roles) == 1 and isolation_verified(isolation))
        item = {'family': row['family'], 'protocol': protocol, 'state': row['state'], 'port': port,
                'inode': row['inode'], 'owner': roles[0] if len(roles) == 1 else 'unknown',
                'address_class': 'wildcard' if local.is_unspecified else 'non_loopback'}
        summary['private_outbound_udp' if permitted else 'violations'].append(item)
    summary['ok'] = not summary['violations']
    return summary


def project_socket_check(value):
    require(isinstance(value, dict) and isinstance(value.get('phase'), str) and value['phase'] in {'before_probe', 'after_probe'}
            and type(value.get('ok')) is bool and count(value.get('checked_sockets'), 512) is not None,
            'socket_table_invalid')
    out = {key: value[key] for key in ('ok', 'phase', 'checked_sockets')}
    for group in ('private_outbound_udp', 'violations'):
        entries = value.get(group)
        require(isinstance(entries, list) and len(entries) <= 512, 'socket_table_invalid')
        out[group] = []
        for item in entries:
            require(isinstance(item, dict) and item.get('family') in (4, 6)
                and item.get('protocol') in ('tcp', 'udp') and isinstance(item.get('state'), str)
                and re.fullmatch('[0-9A-F]{2}', item['state']) and type(item.get('port')) is int
                and 1 <= item['port'] <= 65535 and count(item.get('inode'), 2**63 - 1) is not None
                and item.get('owner') in ('node', 'client', 'unknown')
                and item.get('address_class') in ('wildcard', 'non_loopback'), 'socket_table_invalid')
            out[group].append({key: item[key] for key in ('family', 'protocol', 'state', 'port', 'inode', 'owner', 'address_class')})
    require(out['ok'] == (not out['violations']), 'socket_table_invalid')
    return out


def closed_sockets(rows, *, listeners, tracked_inodes):
    for row in rows:
        listening = row['protocol'] == 'udp' or row['state'] == '0A'
        require(not (listening and (row['protocol'], row['local_port']) in listeners)
                and row['inode'] not in tracked_inodes, 'listeners_still_bound')
