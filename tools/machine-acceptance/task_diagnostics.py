"""Safe per-task first failures, independent local readback and read-only final audit."""
import os
from pathlib import Path
import re
import stat
import uuid


PHASES = {'admission', 'credentials', 'preflight', 'dispatch', 'create', 'reconcile', 'readback', 'initialization', 'reports', 'ipv4_probe'}
CLASSES = {'ErrorException', 'RuntimeException', 'LogicException', 'TypeError', 'Error', 'JsonException',
    'Illuminate\\Database\\QueryException', 'Illuminate\\Database\\Eloquent\\ModelNotFoundException',
    'Illuminate\\Contracts\\Cache\\LockTimeoutException', 'App\\Services\\ServerMachine\\Cloud\\CloudException',
    'App\\Services\\ServerMachine\\Cloud\\CloudRequestFailure', 'other'}
FILES = {'live_support.php', 'live_cli.php', *('app/Services/ServerMachine/Cloud/' + name + '.php' for name in [
    'MachineProvisionService', 'MachineProvisionDiagnostic', 'MachineFailoverNativeBridge', 'MachineFailoverExecutionGate',
    'MachineFailoverRunner', 'MachineCloudCredentialHealth', 'MachineProvisionProviders', 'LinodeClient',
    'LinodeProvisionClient', 'JsonProvisionClient', 'MachineCloudHealth']), 'app/Services/ServerMachine/MachineAddressInventory.php'}
CAUSES = {'other', 'internal_failure', 'provisioning_disabled', 'permission_revoked', 'test_machine_not_allowed',
    'account_changed', 'source_changed', 'panel_origin_changed', 'identity_changed', 'readback_identity_required',
    'provider_invalid', 'provider_rejected', 'provider_forbidden', 'provider_unavailable', 'provider_rate_limited',
    'outcome_uncertain', 'reconcile_incomplete', 'replacement_evidence_changed', 'replacement_evidence_pending',
    'native_lineage_changed', 'native_context_changed', 'native_intent_expired', 'native_stop_requested', 'native_lease_lost',
    'orchestration_disabled', 'credential_health_blocked', 'bootstrap_too_large', 'isolated_manual_cli_required',
    'isolated_operation_not_authorized', 'isolated_scope_changed', 'isolated_source_cli_required', 'isolated_source_stopped',
    'isolated_manual_stopped', 'isolated_scope_expired'}
KEYS = {'schema', 'phase', 'category', 'error_class', 'cause', 'source_known', 'file', 'line', 'severity'}


def require(condition):
    if not condition:
        raise ValueError('task_diagnostic_unconfirmed')


def project(value):
    require(isinstance(value, dict) and set(value) == KEYS and type(value['schema']) is int and value['schema'] == 1)
    require(isinstance(value['phase'], str) and value['phase'] in PHASES
        and isinstance(value['category'], str) and value['category'] in
            {'php_warning', 'php_type_error', 'php_error', 'domain_rejection', 'unexpected_exception'}
        and isinstance(value['error_class'], str) and value['error_class'] in CLASSES
        and isinstance(value['cause'], str) and value['cause'] in CAUSES
        and type(value['source_known']) is bool)
    require(value['severity'] is None or isinstance(value['severity'], str) and value['severity'] in {'warning', 'notice', 'deprecated', 'other'})
    require((value['category'] == 'php_warning') == (value['severity'] is not None))
    if value['source_known']:
        require(isinstance(value['file'], str) and value['file'] in FILES and type(value['line']) is int and 1 <= value['line'] <= 1000000)
    else:
        require(value['file'] is None and value['line'] is None)
    return dict(value)


def identity(run_id, row):
    require(isinstance(run_id, str) and re.fullmatch(r'keli-accept-[a-z0-9-]{8,100}', run_id)
        and isinstance(row, dict) and isinstance(row.get('id'), str) and str(uuid.UUID(row['id'])) == row['id'])
    role = next((role for role in ('source', 'candidate') if row.get('label') == run_id + '-' + role), None)
    require(role is not None)
    return role, 'task-first-error-' + row['id'] + '.json'


def read_first(context, name, expected):
    path = Path(context.BASE) / name
    info = path.lstat()
    require(stat.S_ISREG(info.st_mode) and info.st_nlink == 1 and 0 < info.st_size <= 16384)
    if os.name == 'posix':
        require(info.st_uid == os.geteuid() and not info.st_mode & 0o077)
    value = context.load(name)
    require(isinstance(value, dict) and set(value) == set(expected) and type(value.get('schema')) is int
        and all(value.get(key) == expected[key] for key in ['schema', 'run_id', 'task_id', 'role']))
    project(value['diagnostic'])
    return value


def preserve(context, row):
    role, name = identity(context.RUN, row)
    value = {'schema': 1, 'run_id': context.RUN, 'task_id': row['id'], 'role': role, 'diagnostic': project(row['task_diagnostic'])}
    require(not os.path.lexists(Path(context.BASE) / 'closed.json'))
    if not os.path.lexists(Path(context.BASE) / name):
        try:
            context.save(name, value, exclusive=True)
        except FileExistsError:
            pass
    first = read_first(context, name, value)
    return {'ok': True, 'task_id': row['id'], 'role': role, 'independent_local_readback_verified': True,
        'original_first_error_retained': first != value, 'diagnostic': first['diagnostic']}


def capture_result(context, result):
    if not isinstance(result, dict) or 'tasks' not in result:
        return result
    safe = dict(result)
    rows = result['tasks']
    if not isinstance(rows, list) or len(rows) > 2 or any(not isinstance(row, dict) for row in rows):
        safe['tasks'] = []
        safe['task_diagnostic_preservation'] = {'ok': False, 'reason': 'task_diagnostic_unconfirmed', 'records': []}
        return safe
    projected = []
    safe['tasks'] = projected
    records = []
    ok = True
    seen = set()
    for original in rows:
        row = dict(original)
        diagnostic = row.pop('task_diagnostic', None)
        state = row.pop('task_diagnostic_state', 'absent')
        projected.append(row)
        needed = diagnostic is not None or state != 'absent' or row.get('reason') == 'internal_failure'
        if not needed:
            continue
        try:
            identity(context.RUN, row)
            require(row['id'] not in seen)
            seen.add(row['id'])
            require(state == 'verified' and diagnostic is not None)
            row.update(task_diagnostic=project(diagnostic), task_diagnostic_state='verified')
            records.append(preserve(context, row))
        except Exception:
            ok = False
            row['task_diagnostic_state'] = 'unconfirmed'
    if records or not ok:
        safe['task_diagnostic_preservation'] = {'ok': ok, 'records': records}
        if not ok:
            safe['task_diagnostic_preservation']['reason'] = 'task_diagnostic_unconfirmed'
    return safe


def audit_preservation(context):
    """No write, cloud request or authority change, including after the round is closed."""
    required = False
    checked = 0
    try:
        required = any(Path(context.BASE).glob('task-first-error-*.json'))
        for name in ['resource-ledger.json', 'latest-process.json', 'latest-native-advance.json', 'cleanup-controller-export.json',
                     'cleanup-result.json', 'task-error-export-readback.json']:
            if not (context.BASE / name).exists():
                continue
            value = context.load(name)
            if value.get('task_diagnostic_preservation', {}).get('ok') is False:
                required = True
                raise ValueError('task_diagnostic_unconfirmed')
            if value.get('collection', {}).get('task_diagnostic_preservation', {}).get('ok') is False:
                required = True
                raise ValueError('task_diagnostic_unconfirmed')
            if name == 'task-error-export-readback.json':
                required = True
                require(value.get('ok') is True)
            rows = value.get('controller', value).get('tasks', [])
            require(isinstance(rows, list) and len(rows) <= 2)
            for row in rows:
                require(isinstance(row, dict))
                if row.get('task_diagnostic') is None and row.get('task_diagnostic_state', 'absent') == 'absent' and row.get('reason') != 'internal_failure':
                    continue
                required = True
                role, report = identity(context.RUN, row)
                diagnostic = project(row.get('task_diagnostic'))
                require(row.get('task_diagnostic_state') == 'verified')
                expected = {'schema': 1, 'run_id': context.RUN, 'task_id': row['id'], 'role': role, 'diagnostic': diagnostic}
                first = read_first(context, report, expected)
                require(first['diagnostic'] == diagnostic)
                checked += 1
        require(not required or checked > 0)
        return {'ok': True, 'required': required, 'verified_task_records': checked}
    except Exception:
        return {'ok': False, 'required': required, 'reason': 'task_diagnostic_preservation_unconfirmed'}
