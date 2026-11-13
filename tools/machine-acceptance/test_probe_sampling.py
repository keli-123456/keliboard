"""Deterministic simulated SSH/Agent/Observer clocks; never import a live round."""
import copy
import json
from pathlib import Path
import socket
import subprocess
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import Mock, patch

import probe_sampling as sampling

RUN = 'keli-accept-synthetic-sampling-01234567'
TASK = '11111111-1111-4111-8111-111111111111'
INSTALLATION = '2' * 32
BINARY = '3' * 64
SESSION = '4' * 32
SECRET = 'NEVER_EXPORT_PRIVATE_SSH_EXCEPTION_OR_RAW_LOG'
TARGETS = ['39.156.70.37', '111.33.167.71', '123.150.76.218', '157.255.219.143']


def expected(role='source'):
    return {'run_id': RUN, 'role': role, 'task_id': TASK, 'installation_id': INSTALLATION,
            'source_ip': '45.79.10.20', 'binary_sha256': BINARY, 'node_id': 1, 'policy_id': 1,
            'probe_config': sampling.configuration(TARGETS)}


def packet(at, *, role='source', signal='failed', failures=3, reached=True):
    scope = expected(role)
    ids = scope['probe_config']['targets']
    targets = [{'id': row['id'], 'status': 'unreachable', 'source_ip': scope['source_ip']} for row in ids]
    if signal == 'healthy':
        targets = [dict(targets[0], status='reachable')]
        failures, reached = 0, False
    elif signal == 'unknown':
        targets[0]['status'] = 'error'
    task = {'id': TASK, 'label': RUN + '-' + role, 'installation_id': INSTALLATION, 'bootstrap_state': 'succeeded',
            'status': 'waiting_ipv4_probe', 'public_ips': [scope['source_ip']]}
    status = {'ok': True, 'tasks': [task], 'probe_config': scope['probe_config'], 'agents': [{
        'installation_id': INSTALLATION, 'at': at, 'runtime': {'binary_sha256': BINARY,
        'observation_session': SESSION, 'configured_nodes': [{'id': 1, 'protocol': 'hysteria2', 'port': 24443}]},
        'probe_report': {'state': 'ready', 'checked_at': at, 'revision': scope['probe_config']['revision'], 'targets': targets}}]}
    observation = {'installation_id': INSTALLATION, 'state': signal, 'reason': 'ipv4_all_failed', 'checked_at': at,
                   'valid_until': at + 30, 'policy_id': 1, 'policy_revision': 1, 'consecutive_failures': failures,
                   'threshold_reached': reached, 'baseline_at': None}
    current = {'collecting': True, 'evidence_current': signal != 'unknown', 'observed_failures': failures,
               'threshold_reached': reached, 'probe': {'context_key': '5' * 64, 'checked_at': at, 'state': signal,
               'installation_id': INSTALLATION, 'ipv4': [scope['source_ip']], 'valid_until': at + 30}}
    observed = {'ok': True, 'observation': observation, 'history': {'available': True, 'current': current}}
    return {'status': status, 'observation': observed if role == 'source' else None}


class Clock:
    def __init__(self):
        self.value = 1000.0

    def now(self):
        return self.value

    def sleep(self, seconds):
        self.value += seconds


class SamplingTests(unittest.TestCase):
    def setUp(self):
        self.clock = Clock()
        self.check = Mock()
        self.scope = expected()
        for target in [(socket, 'create_connection'), (socket, 'socket'), (subprocess, 'Popen')]:
            blocked = patch.object(*target, side_effect=AssertionError('real transport forbidden'))
            blocked.start()
            self.addCleanup(blocked.stop)

    def run_window(self, fetch, role='source'):
        return sampling.Window(expected(role), clock=self.clock.now, wall=self.clock.now, sleep=self.clock.sleep).run(fetch, self.check)

    def feed(self, *, role='source', signal='failed', cost=0.2, mutation=None):
        def fetch(remaining):
            self.assertGreater(remaining, 0)
            self.clock.sleep(cost)
            at = 1000 + int((self.clock.now() - 1000) // 11) * 11
            row = packet(at, role=role, signal=signal, failures=int((at - 1000) / 11) + 1,
                         reached=at >= 1022)
            if mutation:
                mutation(row)
            return row
        return fetch

    def test_r14_22_second_gap_is_still_rejected_without_threshold_change(self):
        rows = iter([packet(1000), packet(1011), packet(1033)])
        def fetch(remaining):
            row = next(rows)
            self.clock.value = row['status']['agents'][0]['probe_report']['checked_at']
            return row
        result = self.run_window(fetch)
        self.assertFalse(result['ok'])
        self.assertEqual(result['reason'], 'baseline_report_gap')
        self.assertEqual([row['checked_at'] for row in result['reports']], [1000, 1011])
        self.assertEqual(sampling.MAX_GAP_SECONDS, 20)

    def test_single_control_local_window_keeps_all_failed_rounds_despite_slow_outer_ssh(self):
        self.clock.sleep(12)
        result = self.run_window(self.feed())
        self.clock.sleep(12)
        self.assertTrue(result['ok'])
        self.assertEqual([row['checked_at'] for row in result['reports']], [1011, 1022, 1033])
        self.assertTrue(all(row['signal'] == 'failed' for row in result['reports']))
        sampling.validate_result(result, self.scope, self.clock.now())

    def test_read_cost_does_not_get_an_additional_five_second_sleep(self):
        result = self.run_window(self.feed(cost=7))
        self.assertTrue(result['ok'])
        self.assertEqual([row['checked_at'] for row in result['reports']], [1000, 1011, 1022])
        self.assertLess(result['elapsed_seconds'], 30)

    def test_any_one_configured_reachable_target_is_healthy_for_source_and_candidate(self):
        for role in ('source', 'candidate'):
            self.clock.value = 1000
            result = self.run_window(self.feed(role=role, signal='healthy'), role)
            self.assertTrue(result['ok'])
            self.assertEqual(result['signal'], 'healthy')
            self.assertEqual([row['reachable_targets'] for row in result['reports']], [1, 1, 1])

    def test_candidate_all_failed_remains_rejected(self):
        result = self.run_window(self.feed(role='candidate'), 'candidate')
        self.assertFalse(result['ok'])
        self.assertEqual(result['reason'], 'candidate_no_reachable_target')

    def test_three_statuses_do_not_substitute_for_real_observer_threshold(self):
        result = self.run_window(self.feed(mutation=lambda p: p['observation']['observation'].__setitem__('threshold_reached', False)))
        self.assertFalse(result['ok'])

    def test_native_counter_without_threshold_cannot_qualify(self):
        def mutate(p):
            p['observation']['observation']['threshold_reached'] = False
            p['observation']['history']['current']['threshold_reached'] = False
        result = self.run_window(self.feed(mutation=mutate))
        self.assertFalse(result['ok'])
        self.assertEqual(result['reason'], 'sampling_window_expired')

    def test_same_timestamp_is_not_counted_more_than_once(self):
        result = self.run_window(lambda remaining: packet(1000))
        self.assertFalse(result['ok'])
        self.assertEqual(len(result['reports']), 1)

    def test_changed_duplicate_cannot_increment_native_failures(self):
        count = 0
        def fetch(remaining):
            nonlocal count
            count += 1
            return packet(1000, failures=count)
        result = self.run_window(fetch)
        self.assertFalse(result['ok'])
        self.assertEqual(result['reason'], 'baseline_duplicate_report_changed')

    def test_racing_status_and_observer_reads_do_not_count_as_a_matched_round(self):
        result = self.run_window(self.feed(mutation=lambda p: p['observation']['observation'].__setitem__('checked_at', 999)))
        self.assertFalse(result['ok'])
        self.assertEqual(result['reports'], [])

    def test_unmatched_or_unknown_read_resets_streak_without_erasing_earlier_evidence(self):
        def mutate(p):
            if 1004 <= self.clock.now() < 1006:
                p['observation']['history']['current']['evidence_current'] = False
        result = self.run_window(self.feed(mutation=mutate))
        self.assertTrue(result['ok'])
        self.assertEqual([row['checked_at'] for row in result['reports']], [1000, 1011, 1022, 1033])
        self.assertNotEqual(result['reports'][0]['sequence_epoch'], result['reports'][1]['sequence_epoch'])
        self.assertEqual(len({row['sequence_epoch'] for row in result['reports'][-3:]}), 1)
        sampling.validate_result(result, self.scope, self.clock.now())

    def test_unknown_incomplete_and_error_rounds_never_establish_failed_threshold(self):
        for kind in ('unknown', 'incomplete'):
            self.clock.value = 1000
            def mutate(p):
                if kind == 'incomplete':
                    p['status']['agents'][0]['probe_report']['targets'].pop()
            result = self.run_window(self.feed(signal='unknown', mutation=mutate))
            self.assertFalse(result['ok'])

    def test_source_identity_policy_session_address_and_config_changes_refuse(self):
        changes = [
            lambda p: p['status']['tasks'][0].__setitem__('installation_id', 'f' * 32),
            lambda p: p['status']['tasks'][0].__setitem__('status', 'failed'),
            lambda p: p['status']['tasks'][0]['public_ips'].append('45.79.10.21'),
            lambda p: p['status']['agents'][0]['runtime'].__setitem__('binary_sha256', 'f' * 64),
            lambda p: p['status']['agents'][0]['runtime'].__setitem__('configured_nodes', []),
            lambda p: p['status']['agents'][0]['probe_report']['targets'][0].__setitem__('source_ip', '45.79.10.21'),
            lambda p: p['status']['agents'][0]['probe_report'].__setitem__('revision', 'f' * 64),
            lambda p: p['observation']['observation'].__setitem__('policy_id', 2),
        ]
        for mutate in changes:
            with self.subTest(change=changes.index(mutate)):
                self.clock.value = 1000
                self.assertFalse(self.run_window(self.feed(mutation=mutate))['ok'])
        for field in ('observation_session', 'policy_revision', 'native_context_key'):
            self.clock.value = 1000
            def mutate(p):
                if self.clock.now() < 1011:
                    return
                if field == 'observation_session':
                    p['status']['agents'][0]['runtime'][field] = 'f' * 32
                elif field == 'policy_revision':
                    p['observation']['observation'][field] = 2
                else:
                    p['observation']['history']['current']['probe']['context_key'] = 'f' * 64
            result = self.run_window(self.feed(mutation=mutate))
            self.assertEqual(result['reason'], 'baseline_context_changed')

    def test_stale_future_regressing_samples_are_rejected(self):
        for at in (900, 1001):
            with self.subTest(at=at):
                self.clock.value = 1000
                self.assertFalse(self.run_window(lambda remaining: packet(at))['ok'])
        rows = iter([packet(1000), packet(999)])
        result = self.run_window(lambda remaining: next(rows))
        self.assertEqual(result['reason'], 'baseline_report_regressed')

    def test_stop_timeout_interrupt_and_transport_exception_preserve_only_safe_reason(self):
        for error in (TimeoutError(SECRET), OSError(SECRET)):
            result = self.run_window(Mock(side_effect=error))
            self.assertEqual(result['reason'], 'sampling_transport_failed')
            self.assertNotIn(SECRET, json.dumps(result))
        self.check.side_effect = sampling.Refused('sampling_stop_requested')
        fetch = Mock()
        self.assertEqual(self.run_window(fetch)['reason'], 'sampling_stop_requested')
        fetch.assert_not_called()
        self.check.side_effect = KeyboardInterrupt()
        with self.assertRaises(KeyboardInterrupt):
            self.run_window(fetch)

    def test_parent_rejects_raw_fields_forged_success_and_changed_native_binding(self):
        result = self.run_window(self.feed())
        self.assertTrue(result['ok'])
        sampling.validate_result(result, self.scope, self.clock.now())
        changes = [lambda r: r.__setitem__('raw_log', SECRET), lambda r: r.__setitem__('run_id', 'foreign'),
                   lambda r: r.__setitem__('elapsed_seconds', float('nan')),
                   lambda r: r['reports'][-1].__setitem__('native_threshold_reached', False),
                   lambda r: r['reports'][-1].__setitem__('policy_id', 2),
                   lambda r: r['reports'][-1].__setitem__('observation_session', 'f' * 32),
                   lambda r: r['reports'][-1].__setitem__('checked_at', 1100),
                   lambda r: r['reports'][-1].__setitem__('native_failures', True),
                   lambda r: r['reports'].pop()]
        for mutate in changes:
            changed = copy.deepcopy(result)
            mutate(changed)
            with self.assertRaises(sampling.Refused):
                sampling.validate_result(changed, self.scope, self.clock.now())

    def test_rendered_sampler_executes_only_existing_observe_and_status_locally(self):
        calls = []
        def cli(args, **kwargs):
            self.assertEqual(args[:2], ['php', '/opt/keli-isolated/live_cli.php'])
            self.assertEqual(kwargs['cwd'], '/opt/keli-isolated')
            self.assertLessEqual(kwargs['timeout'], 10)
            calls.append(args[2])
            self.clock.sleep(0.2)
            at = 1000 + int((self.clock.now() - 1000) // 11) * 11
            p = packet(at, failures=int((at - 1000) / 11) + 1, reached=at >= 1022)
            value = p['observation'] if args[2] == 'observe' else p['status']
            return subprocess.CompletedProcess(args, 0, json.dumps(value).encode(), b'')
        import io
        with patch('time.monotonic', self.clock.now), patch('time.time', self.clock.now), patch('time.sleep', self.clock.sleep), \
                patch.object(subprocess, 'run', side_effect=cli), patch('sys.stdout', new_callable=io.StringIO) as output:
            exec(compile(sampling.render(self.scope), '<synthetic-control-window>', 'exec'), {})
        value = json.loads(output.getvalue())
        self.assertTrue(value['ok'])
        self.assertEqual(set(calls), {'observe', 'status'})
        sampling.validate_result(value, self.scope, self.clock.now())

    def test_rendered_cli_exit_signal_timeout_and_invalid_json_are_not_replayed(self):
        import io
        cases = [subprocess.CompletedProcess([], -15, b'', SECRET.encode()),
                 subprocess.CompletedProcess([], 1, b'{"ok":true}', SECRET.encode()),
                 subprocess.CompletedProcess([], 0, SECRET.encode(), b''),
                 subprocess.TimeoutExpired(['php'], 1, output=SECRET.encode())]
        for value in cases:
            with self.subTest(case=type(value).__name__):
                self.clock.value = 1000
                side_effect = value if isinstance(value, Exception) else None
                with patch('time.monotonic', self.clock.now), patch('time.time', self.clock.now), patch('time.sleep', self.clock.sleep), \
                        patch.object(subprocess, 'run', return_value=value, side_effect=side_effect) as command, \
                        patch('sys.stdout', new_callable=io.StringIO) as output:
                    exec(compile(sampling.render(self.scope), '<synthetic-control-window>', 'exec'), {})
                result = json.loads(output.getvalue())
                self.assertFalse(result['ok'])
                command.assert_called_once()
                self.assertNotIn(SECRET, output.getvalue())
                sampling.validate_result(result, self.scope, self.clock.now())


class DriverAdapterTests(unittest.TestCase):
    def setUp(self):
        self.clock = Clock()
        self.scope = expected()
        p = SamplingTests()
        p.clock = self.clock
        p.scope = self.scope
        p.check = Mock()
        self.window = p.run_window(p.feed())
        self.base = Path(self.enterContext(tempfile.TemporaryDirectory(prefix='keli-local-sampling-')))
        self.saved = {}
        self.events = []
        self.inspection = {'installation_id': INSTALLATION, 'unit': {'MainPID': '123'}, 'process_start_ticks': 456,
                           'configuration_sha256': '6' * 64, 'binary_sha256': BINARY}
        campaign = SimpleNamespace(RUN=RUN, AUTH={'node_binary_sha256': BINARY, 'probe_ipv4_targets': TARGETS},
            BASE=self.base, load=Mock(return_value={'node_id': 1, 'policy_id': 1}), save=Mock(side_effect=self.save),
            ssh_control=Mock(return_value=self.window), firewall_bindings=Mock(return_value={'ok': True}))
        self.phase = SimpleNamespace(collect=Mock(return_value={'ok': False, 'status': 'collected', 'root_echoes': 0,
                                                               'packet_budget_exceeded': False}))
        self.driver = SimpleNamespace(check=Mock(), reserve=Mock(), campaign=campaign,
            tasks={'source': packet(1000)['status']['tasks'][0]}, clock=self.clock.now, wall=self.clock.now,
            inspect_node=Mock(return_value=self.inspection), call=self.call,
            module=lambda name: self.phase if name == 'phase_probe' else SimpleNamespace(), outcome={})
        for target in [(socket, 'create_connection'), (subprocess, 'Popen')]:
            blocked = patch.object(*target, side_effect=AssertionError('real transport forbidden'))
            blocked.start()
            self.addCleanup(blocked.stop)

    def save(self, name, value, exclusive=False):
        self.assertTrue(exclusive)
        self.assertNotIn(name, self.saved)
        self.saved[name] = copy.deepcopy(value)

    def call(self, phase, function, *args, require_ok=True, **kwargs):
        self.events.append(phase)
        self.driver.phase = phase
        return function(*args, **kwargs)

    def test_one_ssh_window_two_node_inspections_and_unchanged_diagnostic_budget(self):
        sampling.baseline(self.driver, 'source')
        self.driver.inspect_node.assert_has_calls([unittest.mock.call('source'), unittest.mock.call('source')])
        self.assertEqual(self.driver.inspect_node.call_count, 2)
        self.driver.campaign.ssh_control.assert_called_once()
        self.assertEqual(self.driver.campaign.ssh_control.call_args.kwargs['timeout'], 70)
        self.assertTrue(self.saved['source-health-window.json']['ok'])
        self.assertEqual(self.driver.outcome['source_baseline']['signal'], 'failed')
        self.phase.collect.assert_called_once()

    def test_changed_pid_start_ticks_or_config_after_window_is_not_accepted(self):
        for field, value in (('process_start_ticks', 789), ('configuration_sha256', 'f' * 64), ('configuration_sha256', None)):
            self.saved.clear()
            self.driver.inspect_node.side_effect = [self.inspection, dict(self.inspection, **{field: value})]
            with self.assertRaises(sampling.Refused):
                sampling.baseline(self.driver, 'source')
            self.assertFalse(self.saved['source-health-window.json']['ok'])
        self.saved.clear()
        changed_pid = copy.deepcopy(self.inspection)
        changed_pid['unit']['MainPID'] = '789'
        self.driver.inspect_node.side_effect = [self.inspection, changed_pid]
        with self.assertRaisesRegex(sampling.Refused, 'baseline_context_changed'):
            sampling.baseline(self.driver, 'source')

    def test_sampling_first_error_survives_diagnostic_and_evidence_write_failure(self):
        self.driver.campaign.ssh_control.return_value = dict(self.window, ok=False, reason='baseline_report_gap')
        self.phase.collect.side_effect = OSError(SECRET)
        self.driver.campaign.save.side_effect = PermissionError(SECRET)
        with self.assertRaisesRegex(sampling.Refused, '^baseline_report_gap$'):
            sampling.baseline(self.driver, 'source')
        self.assertEqual(self.driver.outcome['baseline_evidence_storage_error'], 'PermissionError')
        self.assertEqual(self.driver.phase, 'source_sampling_window')

    def test_failed_diagnostic_does_not_replace_failed_sampling_and_interrupt_propagates(self):
        self.driver.campaign.ssh_control.side_effect = KeyboardInterrupt()
        with self.assertRaises(KeyboardInterrupt):
            sampling.baseline(self.driver, 'source')
        self.assertEqual(self.saved['source-health-window.json']['reason'], 'baseline_exception')
        self.phase.collect.assert_called_once()


if __name__ == '__main__':
    unittest.main()
