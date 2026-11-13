"""Bounded control-local sampling; no transports or authorization on import."""
import hashlib
import ipaddress
import json
import math
import os
from pathlib import Path
import re
import time

WINDOW_SECONDS = 60
POLL_SECONDS = 5
MAX_GAP_SECONDS = 20
MAX_AGE_SECONDS = 30
MIN_SAMPLES = 3
MAX_SAMPLES = 24
STOP_FILES = ('closed.json', 'stop-request.json', 'cleanup-intent.json')
REPORT_KEYS = {'checked_at', 'reachable_targets', 'configured_targets', 'reported_targets', 'signal',
               'observation_session', 'revision', 'native_failures', 'native_threshold_reached',
               'native_context_key', 'policy_id', 'policy_revision', 'sequence_epoch'}


class Refused(RuntimeError):
    pass


def require(value, reason):
    if not value:
        raise Refused(reason)


def public_ipv4(value):
    try:
        address = ipaddress.IPv4Address(value)
        return isinstance(value, str) and str(address) == value and address.is_global and not address.is_multicast
    except (ValueError, TypeError):
        return False


def configuration(targets):
    require(isinstance(targets, list) and 1 <= len(targets) <= 4 and len(set(targets)) == len(targets)
            and all(public_ipv4(value) for value in targets), 'sampling_targets_invalid')
    value = {'schema': 1, 'enabled': True, 'interval_seconds': 5, 'timeout_seconds': 2,
             'targets': [{'id': hashlib.sha256(ip.encode()).hexdigest()[:16], 'ip': ip} for ip in targets]}
    value['revision'] = hashlib.sha256(json.dumps(value, separators=(',', ':')).encode()).hexdigest()
    return value


def validate_expected(expected):
    require(isinstance(expected, dict) and set(expected) == {'run_id', 'role', 'task_id', 'installation_id', 'source_ip',
            'binary_sha256', 'node_id', 'policy_id', 'probe_config'}, 'sampling_scope_invalid')
    require(isinstance(expected['run_id'], str) and re.fullmatch(r'keli-accept-[a-z0-9-]{8,80}', expected['run_id'])
            and expected['role'] in {'source', 'candidate'}
            and isinstance(expected['task_id'], str) and re.fullmatch(r'[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}', expected['task_id'])
            and isinstance(expected['installation_id'], str) and re.fullmatch(r'[a-f0-9]{32}', expected['installation_id'])
            and public_ipv4(expected['source_ip'])
            and isinstance(expected['binary_sha256'], str) and re.fullmatch(r'[a-f0-9]{64}', expected['binary_sha256'])
            and type(expected['node_id']) is int and expected['node_id'] > 0
            and type(expected['policy_id']) is int and expected['policy_id'] > 0, 'sampling_scope_invalid')
    config = expected['probe_config']
    require(isinstance(config, dict) and config == configuration([row['ip'] for row in config['targets']]),
            'sampling_configuration_invalid')


def sample(packet, expected, now):
    require(isinstance(packet, dict) and set(packet) == {'status', 'observation'}, 'sampling_packet_invalid')
    status = packet['status']
    require(isinstance(status, dict) and status.get('ok') is True and status.get('probe_config') == expected['probe_config'],
            'baseline_configured_target_set_changed')
    tasks = status.get('tasks')
    require(isinstance(tasks, list) and len(tasks) <= 2, 'sampling_tasks_invalid')
    tasks = [row for row in tasks if row.get('id') == expected['task_id']]
    require(len(tasks) == 1 and tasks[0].get('installation_id') == expected['installation_id']
            and tasks[0].get('label') == expected['run_id'] + '-' + expected['role']
            and tasks[0].get('bootstrap_state') == 'succeeded'
            and tasks[0].get('status') in {'awaiting_acceptance', 'waiting_ipv4_probe', 'accepted'}
            and [ip for ip in tasks[0].get('public_ips', []) if isinstance(ip, str) and ':' not in ip] == [expected['source_ip']],
            'baseline_task_changed')
    agents = status.get('agents')
    require(isinstance(agents, list) and len(agents) <= 2, 'baseline_agent_identity_missing')
    agents = [row for row in agents if row.get('installation_id') == expected['installation_id']]
    require(len(agents) == 1, 'baseline_agent_identity_missing')
    row = agents[0]
    runtime, report = row.get('runtime', {}), row.get('probe_report', {})
    require(type(row.get('at')) is int and 0 <= now - row['at'] <= MAX_AGE_SECONDS
            and runtime.get('binary_sha256') == expected['binary_sha256']
            and isinstance(runtime.get('observation_session'), str)
            and re.fullmatch(r'[a-f0-9]{32}', runtime['observation_session'])
            and runtime.get('configured_nodes') == [{'id': expected['node_id'], 'protocol': 'hysteria2', 'port': 24443}],
            'baseline_runtime_changed')
    require(isinstance(report, dict) and report.get('state') == 'ready' and type(report.get('checked_at')) is int
            and 0 <= now - report['checked_at'] <= MAX_AGE_SECONDS
            and report.get('revision') == expected['probe_config']['revision'], 'baseline_probe_unavailable')
    targets = report.get('targets')
    ids = {target['id'] for target in expected['probe_config']['targets']}
    require(isinstance(targets, list) and 1 <= len(targets) <= len(ids)
            and all(isinstance(target, dict) for target in targets)
            and len({target.get('id') for target in targets}) == len(targets)
            and {target.get('id') for target in targets} <= ids
            and all(target.get('status') in {'reachable', 'unreachable', 'error'}
                    and target.get('source_ip') == expected['source_ip'] for target in targets),
            'baseline_no_reachable_target_or_source_mismatch')
    healthy = any(target['status'] == 'reachable' for target in targets)
    signal = 'healthy' if healthy else ('failed' if len(targets) == len(ids)
             and all(target['status'] == 'unreachable' for target in targets) else 'unknown')
    failures, reached, context_key, policy_id, policy_revision = None, False, None, None, None
    if expected['role'] == 'source':
        observed = packet['observation']
        require(isinstance(observed, dict) and observed.get('ok') is True, 'source_observation_required')
        observation = observed.get('observation', {})
        history = observed.get('history', {})
        current = history.get('current', {})
        require(isinstance(observation, dict) and isinstance(current, dict) and history.get('available') is True
                and current.get('collecting') is True, 'source_observation_required')
        probe = current.get('probe') or {}
        # A report racing between the two local CLI reads is not a matched sample.
        if observation.get('checked_at') != report['checked_at'] or current.get('evidence_current') is not True:
            return None
        require(probe.get('checked_at') == report['checked_at']
                and observation.get('installation_id') == probe.get('installation_id') == expected['installation_id']
                and probe.get('ipv4') == [expected['source_ip']]
                and observation.get('state') == probe.get('state') == signal
                and isinstance(probe.get('context_key'), str) and re.fullmatch(r'[a-f0-9]{64}', probe['context_key'])
                and type(probe.get('valid_until')) is int and probe['valid_until'] > now,
                'source_observation_binding_changed')
        failures, reached = observation.get('consecutive_failures'), observation.get('threshold_reached')
        policy_id, policy_revision, context_key = observation.get('policy_id'), observation.get('policy_revision'), probe['context_key']
        require(type(failures) is int and failures >= 0 and type(reached) is bool
                and type(current.get('observed_failures')) is int and current['observed_failures'] == failures
                and current.get('threshold_reached') is reached
                and type(policy_id) is int and policy_id == expected['policy_id']
                and type(policy_revision) is int and policy_revision > 0,
                'source_observation_invalid')
        require(signal != 'healthy' or failures == 0 and reached is False, 'source_observation_invalid')
    else:
        require(packet['observation'] is None, 'candidate_observation_not_allowed')
        require(signal == 'healthy', 'candidate_no_reachable_target')
    return {'checked_at': report['checked_at'], 'reachable_targets': sum(target['status'] == 'reachable' for target in targets),
            'configured_targets': len(ids), 'reported_targets': len(targets), 'signal': signal,
            'observation_session': runtime['observation_session'], 'revision': report['revision'],
            'native_failures': failures, 'native_threshold_reached': reached, 'native_context_key': context_key,
            'policy_id': policy_id, 'policy_revision': policy_revision}


class Window:
    def __init__(self, expected, *, clock=time.monotonic, wall=time.time, sleep=time.sleep):
        validate_expected(expected)
        self.expected, self.clock, self.wall, self.sleep = expected, clock, wall, sleep
        self.reports = []
        self.epoch = 0

    def run(self, fetch, check, seconds=WINDOW_SECONDS):
        require(type(seconds) is int and 10 <= seconds <= WINDOW_SECONDS, 'sampling_budget_invalid')
        started, started_wall = self.clock(), self.wall()
        result = {'ok': False, 'run_id': self.expected['run_id'], 'role': self.expected['role'],
                  'task_id': self.expected['task_id'], 'reports': self.reports}
        try:
            while True:
                check()
                tick = self.clock()
                require(math.isfinite(tick) and started <= tick < started + seconds
                        and started_wall - 3 <= self.wall() < started_wall + seconds, 'sampling_window_expired')
                packet = fetch(started + seconds - tick)
                check()
                require(self.clock() < started + seconds and self.wall() < started_wall + seconds, 'sampling_window_expired')
                row = sample(packet, self.expected, self.wall())
                if row is None:
                    self.epoch += 1
                else:
                    row['sequence_epoch'] = self.epoch
                    prior = self.reports[-1] if self.reports else None
                    if prior:
                        require(all(row[key] == prior[key] for key in ('observation_session', 'native_context_key',
                                'policy_id', 'policy_revision')), 'baseline_context_changed')
                        gap = row['checked_at'] - prior['checked_at']
                        require(gap >= 0, 'baseline_report_regressed')
                        require(gap <= MAX_GAP_SECONDS, 'baseline_report_gap')
                        if gap == 0:
                            require(all(row[key] == prior[key] for key in REPORT_KEYS - {'sequence_epoch'}),
                                    'baseline_duplicate_report_changed')
                    if prior is None or row['checked_at'] >= prior['checked_at'] + POLL_SECONDS:
                        require(len(self.reports) < MAX_SAMPLES, 'sampling_sample_limit')
                        self.reports.append(row)
                    last = self.reports[-MIN_SAMPLES:]
                    qualified = len(last) == MIN_SAMPLES and last[-1]['checked_at'] - last[0]['checked_at'] >= 10 \
                        and all(item['sequence_epoch'] == self.epoch for item in last)
                    qualified = qualified and (all(item['signal'] == 'healthy' for item in last)
                        or self.expected['role'] == 'source' and all(item['signal'] == 'failed' for item in last)
                        and row['native_failures'] >= MIN_SAMPLES and row['native_threshold_reached'] is True)
                    if qualified:
                        result.update(ok=True, signal=row['signal'])
                        break
                # Do not add another five seconds after an already slow read.
                remaining = min(started + seconds, tick + POLL_SECONDS) - self.clock()
                if remaining > 0:
                    self.sleep(remaining)
        except Refused as error:
            result['reason'] = str(error)
        except Exception as error:
            result.update(reason='sampling_transport_failed', error_class=type(error).__name__)
        result['elapsed_seconds'] = round(self.clock() - started, 3)
        return result


def render(expected):
    validate_expected(expected)
    source = Path(__file__).read_text(encoding='utf-8')
    suffix = r'''
import subprocess
expected = EXPECTED
def fetch(remaining):
    def cli(name):
        left = deadline - time.monotonic()
        require(left > 0, 'sampling_window_expired')
        process = subprocess.run(['php','/opt/keli-isolated/live_cli.php',name], cwd='/opt/keli-isolated',
                                 capture_output=True, timeout=min(10,left))
        require(process.returncode == 0 and len(process.stdout) <= 262144, 'sampling_cli_failed')
        value = json.loads(process.stdout)
        require(isinstance(value,dict) and value.get('ok') is True, 'sampling_cli_failed')
        return value
    observed = cli('observe') if expected['role'] == 'source' else None
    return {'observation':observed,'status':cli('status')}
def check():
    for base in (Path('/root/keli-acceptance-bundle'),Path('/opt/keli-isolated')):
        require(not any(os.path.lexists(base/name) for name in STOP_FILES),'sampling_stop_requested')
deadline = time.monotonic() + WINDOW_SECONDS
print(json.dumps(Window(expected).run(fetch,check)),flush=True)
'''
    return "__name__='keli_isolated_probe_sampling'\n" + source + '\n' + suffix.replace('EXPECTED', repr(expected))


def validate_result(value, expected, now):
    require(isinstance(value, dict) and set(value) <= {'ok', 'run_id', 'role', 'task_id', 'reports', 'signal',
            'reason', 'error_class', 'elapsed_seconds'} and value.get('run_id') == expected['run_id']
            and value.get('role') == expected['role'] and value.get('task_id') == expected['task_id']
            and type(value.get('ok')) is bool, 'sampling_response_invalid')
    elapsed = value.get('elapsed_seconds')
    require(type(elapsed) in (int, float) and math.isfinite(elapsed) and 0 <= elapsed <= WINDOW_SECONDS + 1,
            'sampling_response_invalid')
    reports = value.get('reports')
    require(isinstance(reports, list) and len(reports) <= MAX_SAMPLES, 'sampling_response_invalid')
    for index, row in enumerate(reports):
        require(isinstance(row, dict) and set(row) == REPORT_KEYS and type(row.get('checked_at')) is int
                and row.get('signal') in {'healthy', 'failed', 'unknown'}
                and type(row.get('reachable_targets')) is int and type(row.get('reported_targets')) is int
                and type(row.get('configured_targets')) is int
                and row['configured_targets'] == len(expected['probe_config']['targets'])
                and 0 <= row['reachable_targets'] <= row['reported_targets'] <= row['configured_targets']
                and row.get('revision') == expected['probe_config']['revision']
                and type(row.get('sequence_epoch')) is int and 0 <= row['sequence_epoch'] <= MAX_SAMPLES
                and isinstance(row.get('observation_session'), str) and re.fullmatch(r'[a-f0-9]{32}', row['observation_session'])
                and type(row.get('native_threshold_reached')) is bool
                and (all(row[key] is None for key in ('native_failures', 'native_context_key', 'policy_id', 'policy_revision'))
                     if expected['role'] == 'candidate' else type(row['native_failures']) is int and row['native_failures'] >= 0
                     and type(row['policy_id']) is int and row['policy_id'] == expected['policy_id']
                     and type(row['policy_revision']) is int and row['policy_revision'] > 0
                     and isinstance(row['native_context_key'], str) and re.fullmatch(r'[a-f0-9]{64}', row['native_context_key'])),
                'sampling_response_invalid')
        require(row['signal'] != 'healthy' or row['reachable_targets'] >= 1, 'sampling_response_invalid')
        require(row['signal'] != 'failed' or row['reachable_targets'] == 0 and row['reported_targets'] == row['configured_targets'],
                'sampling_response_invalid')
        if index:
            require(POLL_SECONDS <= row['checked_at'] - reports[index - 1]['checked_at'] <= MAX_GAP_SECONDS
                    and row['sequence_epoch'] >= reports[index - 1]['sequence_epoch']
                    and all(row[key] == reports[index - 1][key] for key in ('observation_session', 'native_context_key',
                            'policy_id', 'policy_revision')), 'sampling_response_invalid')
    for name in ('reason', 'error_class'):
        require(name not in value or isinstance(value[name], str) and re.fullmatch(r'[A-Za-z_][A-Za-z_0-9]{0,99}', value[name]),
                'sampling_response_invalid')
    if value['ok']:
        last = reports[-MIN_SAMPLES:]
        require(len(last) == MIN_SAMPLES and last[-1]['checked_at'] - last[0]['checked_at'] >= 10
                and 0 <= now - last[-1]['checked_at'] <= MAX_AGE_SECONDS
                and value.get('signal') in {'healthy', 'failed'}
                and all(row['signal'] == value['signal'] for row in last)
                and len({row['sequence_epoch'] for row in last}) == 1
                and (value['signal'] == 'healthy' or expected['role'] == 'source'
                     and last[-1]['native_failures'] >= MIN_SAMPLES and last[-1]['native_threshold_reached'] is True),
                'sampling_response_invalid')
    return json.loads(json.dumps(value, allow_nan=False))


def baseline(driver, role):
    """RoundRunner.baseline replacement, only for a newly approved/pinned bundle."""
    driver.check()
    require(role in {'source', 'candidate'}, 'explicit_role_required')
    driver.reserve(2 * 55 + WINDOW_SECONDS + 10 + 22)
    task = driver.tasks[role]
    addresses = [ip for ip in task['public_ips'] if public_ipv4(ip)]
    require(len(addresses) == 1, 'one_pinned_node_ipv4_required')
    expected = {'run_id': driver.campaign.RUN, 'role': role, 'task_id': task['id'],
                'installation_id': task['installation_id'], 'source_ip': addresses[0],
                'binary_sha256': driver.campaign.AUTH['node_binary_sha256'],
                'node_id': driver.campaign.load('seed-result.json')['node_id'],
                'policy_id': driver.campaign.load('seed-result.json')['policy_id'],
                'probe_config': configuration(driver.campaign.AUTH['probe_ipv4_targets'])}
    validate_expected(expected)
    evidence = {'ok': False, 'run_id': expected['run_id'], 'role': role, 'task_id': task['id'], 'reports': []}
    first_error, failure_phase = None, None
    started = driver.clock()
    try:
        before = driver.inspect_node(role)
        raw = driver.call(role + '_sampling_window', driver.campaign.ssh_control, render(expected),
                          timeout=WINDOW_SECONDS + 10, require_ok=False)
        evidence = validate_result(raw, expected, driver.wall())
        require(evidence['ok'], evidence.get('reason', 'sampling_window_failed'))
        after = driver.inspect_node(role)
        def context(value):
            require(isinstance(value.get('configuration_sha256'), str) and re.fullmatch(r'[a-f0-9]{64}', value['configuration_sha256'])
                    and value.get('binary_sha256') == expected['binary_sha256']
                    and type(value.get('process_start_ticks')) is int and value['process_start_ticks'] > 0,
                    'baseline_configuration_missing')
            return (value['installation_id'], value['unit']['MainPID'], value['process_start_ticks'],
                    value['configuration_sha256'], value['binary_sha256'])
        require(context(before) == context(after) and before['installation_id'] == expected['installation_id'], 'baseline_context_changed')
        require(0 <= driver.wall() - evidence['reports'][-1]['checked_at'] <= MAX_AGE_SECONDS, 'baseline_probe_unavailable')
        evidence['context'] = {'installation_id': after['installation_id'], 'pid': after['unit']['MainPID'],
                               'start_ticks': after['process_start_ticks'], 'configuration_sha256': after['configuration_sha256'],
                               'source': expected['source_ip']}
    except BaseException as error:
        first_error = error
        failure_phase = getattr(driver, 'phase', role + '_sampling_window')
        evidence.update(ok=False, error_class=type(error).__name__,
                        reason=str(error) if isinstance(error, Refused) else 'baseline_exception')
    finally:
        evidence['total_elapsed_seconds'] = round(driver.clock() - started, 3)
        try:
            driver.campaign.save(role + '-health-window.json', evidence, exclusive=True)
        except BaseException as error:
            driver.outcome['baseline_evidence_storage_error'] = type(error).__name__
            if first_error is None:
                first_error = error
                failure_phase = getattr(driver, 'phase', role + '_sampling_window')
    try:
        driver.call(role + '_firewall_bindings', driver.campaign.firewall_bindings, role)
        diagnostic = driver.call(role + '_baseline_diagnostics', driver.module('phase_probe').collect,
                                 driver.campaign, driver.module('node'), role + '_baseline',
                                 clock=driver.clock, wall=driver.wall, require_ok=False)
        require(diagnostic.get('packet_budget_exceeded') is not True, 'packet_budget_exceeded')
    except BaseException as error:
        if first_error is None:
            first_error = error
            failure_phase = getattr(driver, 'phase', role + '_baseline_diagnostics')
    if first_error is not None:
        driver.phase = failure_phase
        raise first_error
    driver.outcome[role + '_baseline'] = {'healthy_samples': sum(row['signal'] == 'healthy' for row in evidence['reports']),
        'sample_count': len(evidence['reports']), 'window_seconds': evidence['total_elapsed_seconds'],
        'signal': evidence['signal'], 'root_echoes': diagnostic.get('root_echoes'), 'diagnostic_status': diagnostic.get('status')}
