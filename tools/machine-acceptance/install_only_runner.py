"""Explicit two-role driver template; no default scope, approval or live CLI."""
import hashlib
import importlib
import os
from pathlib import Path
import re
import time
import uuid
from driver_journal import DriverJournal, Rejected, safe_error

READY = ('owner_matches', 'bootstrap_ready', 'cloud_init_done', 'php_ready', 'docker_ready',
         'firewall_ready', 'ssh_key_only', 'public_http_80_closed')
GATES = ('creation', 'runner', 'replacement', 'rotation', 'dns', 'verification')
TERMINAL = {'failed', 'needs_review', 'cancelled', 'stopped', 'rejected', 'retained', 'retry_ready'}
REQUIRED = {'authorization.json', 'campaign.py', 'read_only.py', 'discover.php', 'preflight.php', 'install_runner.py',
            'cleanup.py', 'teardown_control.py', 'live_support.php', 'fixture_payload.php', 'seed_live.php', 'live_cli.php',
            'runtime_install.py', 'application.tar.gz', 'vendor.tar.gz', 'bootstrap_diagnostics.py', 'diagnostic_capture.py',
            'node.py', 'controller.py', 'live_operator.py', 'staging_control.py', 'certificate.py', 'certificate_dns.php',
            'control_setup.py', 'control_transfer.py', 'control_run.py', 'production_guard.py', 'finalize_local.py',
            'http_smoke.php', 'test_router.php', 'staging.py', 'worker.py', 'install_only_runner.py', 'driver_journal.py'}
CLOSED_ROUNDS = {'keli-accept-20261112-r7-8c3a3d2c', 'keli-accept-20261112-r6-f7e61713'}
FORBIDDEN_FLAGS = ('production_deployment', 'production_database_writes', 'production_queue_changes',
                   'production_automatic_creation', 'production_automatic_rotation', 'production_dns_switch',
                   'tanzhen_access', 'business_machine_5_access', 'fault_injection', 'candidate_creation',
                   'forwarding_acceptance', 'dns_switch', 'automatic_credential_rotation')


class Refused(RuntimeError):
    pass


def require(value, code):
    if not value:
        raise Refused(code)


def installation_observation(task, value, now, expected_node_id, *, run_id, binary_sha256):
    require(isinstance(task, dict) and task.get('label') == run_id + '-source', 'source_task_required')
    require(str(uuid.UUID(task['id'])) == task['id'] and re.fullmatch(r'[a-f0-9]{32}', task.get('installation_id', '')),
            'source_task_identity_required')
    require(isinstance(value, dict) and value.get('ok') is True, 'agent_aggregate_unavailable')
    agents = value.get('agents')
    require(isinstance(agents, list) and len(agents) <= 1, 'single_agent_required')
    if not agents:
        return None
    agent = agents[0]
    runtime = agent.get('runtime', {})
    require(agent.get('installation_id') == task['installation_id'], 'agent_installation_changed')
    at = agent.get('at')
    require(type(at) is int and -3 <= now - at <= 30, 'agent_report_stale')
    require(runtime.get('binary_sha256') == binary_sha256 and isinstance(runtime.get('observation_session'), str)
            and re.fullmatch(r'[a-f0-9]{32}', runtime['observation_session']) is not None,
            'agent_binary_or_session_unverified')
    expected = [{'id': expected_node_id, 'protocol': 'hysteria2', 'port': 24443}]
    require(type(expected_node_id) is int and expected_node_id > 0 and runtime.get('configured_nodes') == expected,
            'agent_node_binding_missing')
    return {'at': at, 'installation_id': agent['installation_id'], 'binary_sha256': binary_sha256,
            'observation_session': runtime['observation_session'], 'configured_nodes': expected}


class InstallRunner:
    def __init__(self, *, run_id, application_commit, binary_sha256, loader=importlib.import_module,
                 wall=time.time, sleep=time.sleep):
        require(isinstance(run_id, str) and re.fullmatch(r'keli-accept-[a-z0-9-]{8,80}', run_id)
                and run_id not in CLOSED_ROUNDS, 'fresh_run_identity_required')
        require(all(isinstance(value, str) and re.fullmatch(pattern, value)
                    for value, pattern in [(application_commit, r'[a-f0-9]{40}'), (binary_sha256, r'[a-f0-9]{64}')]),
                'explicit_source_and_binary_pins_required')
        self.run_id, self.application_commit, self.binary_sha256 = run_id, application_commit, binary_sha256
        self.loader, self.wall, self.sleep = loader, wall, sleep
        self.modules = {}
        self.phase = 'claim'
        self.started = None
        self.journal = None
        self._diagnostic_result = None
        self.first_failure = None
        self.outcome = {'ok': False, 'run_id': self.run_id, 'scope': 'control_and_source_installation_only',
                        'fault_injected': False, 'candidate_created': False, 'forwarding_tested': False, 'dns_switched': False}

    def mod(self, name):
        if name not in self.modules:
            module = self.loader(name)
            if name == 'cleanup' and self.journal is not None and hasattr(module, 'Journal'):
                require(getattr(module, 'RUN', None) == self.run_id, 'cleanup_run_identity_changed')
                owner = self.journal
                def save(instance, record, value, exclusive=False):
                    require(instance.base == owner.base, 'cleanup_journal_directory_changed')
                    instance.path(record)
                    return owner.save(record, value, exclusive)
                def load(instance, record, default=None):
                    require(instance.base == owner.base, 'cleanup_journal_directory_changed')
                    path = instance.path(record)
                    return owner.load(record) if os.path.lexists(path) else default
                module.Journal.save, module.Journal.load = save, load
            self.modules[name] = module
        return self.modules[name]

    @property
    def campaign(self):
        return self.mod('campaign')

    def progress(self, phase):
        self.phase = phase
        self.campaign.save('progress.json', {'run_id': self.run_id, 'phase': phase, 'at': int(self.wall())})

    def failure(self, error, site, logical_record='other', *, fatal=True):
        detail = safe_error(error, site=site, logical_record=logical_record)
        persisted = {'persisted': False, 'record': None, 'error': detail}
        if self.journal is not None:
            persisted = self.journal.failure_record(error, site=site, logical_record=logical_record, phase=self.phase)
        if self.first_failure is None and fatal:
            self.first_failure = detail
            self.outcome.update(ok=False, phase=self.phase, reason='stage_exception', error_class=detail['error_class'],
                                failure=detail, failure_storage={'persisted': persisted['persisted'], 'record': persisted['record']})
        elif fatal:
            self.outcome['ok'] = False
        self.outcome.setdefault('local_errors', []).append({'site': site, 'error': detail,
                                                          'persisted': persisted['persisted'], 'record': persisted['record']})
        self.outcome['local_errors'] = self.outcome['local_errors'][-16:]
        return persisted

    def progress_before_cleanup(self, phase):
        try:
            self.progress(phase)
        except Exception as error:
            self.failure(error, 'progress', 'progress')

    def bind_journal(self):
        campaign = self.campaign
        owner = DriverJournal(campaign.BASE, self.run_id)
        require(not os.path.lexists(owner.base / 'closed.json'), 'closed_round_write_refused')
        def save(record, value, exclusive=False):
            return owner.save(record, value, exclusive)
        campaign.save, campaign.load = save, owner.load
        self.journal = owner

    def check(self):
        self.campaign.assert_open()
        if self.started is not None:
            require(self.wall() < self.started + 5400, 'stage_deadline_reached')

    def pause(self):
        self.check()
        self.sleep(5)
        self.check()

    def call(self, phase, function, *args, **kwargs):
        self.check()
        self.progress(phase)
        value = function(*args, **kwargs)
        self.check()
        require(isinstance(value, dict), 'aggregate_required')
        if value.get('ok') is not True:
            safe = {key: value[key] for key in ('phase', 'stage', 'reason', 'error_class', 'exit', 'ssh_exit')
                    if type(value.get(key)) is int or isinstance(value.get(key), str) and re.fullmatch(r'[A-Za-z0-9_.:-]{1,99}', value[key])}
            self.outcome.setdefault('failure_evidence', {})[phase] = safe
            raise Refused('stage_failed:' + phase)
        return value

    def claim(self):
        self.campaign.assert_scope()
        auth = self.campaign.AUTH
        require(self.campaign.RUN == self.run_id and auth.get('source_commit') == self.application_commit
                and auth.get('run_id') == self.run_id and auth.get('user_approved') is True
                and auth.get('scope') == 'control_and_source_installation_only', 'new_scope_required')
        require(auth.get('roles') == {'control': {'size': 'g6-standard-2', 'maximum_create_attempts': 1},
                                     'source': {'size': 'g6-standard-4', 'maximum_create_attempts': 1}}
                and type(auth.get('peak_instance_count')) is int and auth['peak_instance_count'] == 2
                and type(auth.get('maximum_total_create_attempts')) is int and auth['maximum_total_create_attempts'] == 2
                and type(auth.get('maximum_campaign_seconds')) is int and auth['maximum_campaign_seconds'] == 7200
                and all(auth.get(key) is False for key in FORBIDDEN_FLAGS), 'two_role_disabled_scope_required')
        require(not any((self.campaign.BASE / name).exists() for name in
                        ['round-intent.json', 'closed.json', 'control-intent.json', 'cleanup-intent.json', 'stop-request.json']),
                'fresh_round_required')
        offline = self.campaign.load('offline-result.json')
        require(offline.get('ok') is True and offline.get('run_id') == self.run_id, 'offline_checks_required')
        require(isinstance(offline.get('file_digests'), dict) and REQUIRED <= set(offline['file_digests'])
                and len(offline['file_digests']) <= 128, 'complete_frozen_bundle_required')
        for name, pin in offline['file_digests'].items():
            path = self.campaign.BASE / name
            require(path.is_file() and not path.is_symlink() and path.name == name
                    and hashlib.sha256(path.read_bytes()).hexdigest() == pin, 'frozen_file_changed')
        for name in ('driver_journal.py', 'install_only_runner.py'):
            require(hashlib.sha256(Path(__file__).with_name(name).read_bytes()).hexdigest()
                    == offline['file_digests'][name], 'loaded_local_io_tool_changed')
        self.bind_journal()
        self.campaign.save('round-intent.json', {'run_id': self.run_id, 'source_commit': self.application_commit, 'approved_roles': ['control', 'source'],
                           'maximum_total_create_attempts': 2, 'restart_allowed': False, 'at': int(self.wall())}, exclusive=True)

    def preflight(self):
        read = self.mod('read_only')
        value = self.call('production_metadata', read.production_php, (self.campaign.BASE / 'discover.php').read_text())
        self.campaign.save('production-before.json', value)
        require(all(value.get('closed_gates', {}).get(key) is True for key in GATES), 'production_gates_open')
        self.call('production_health_before', self.mod('production_guard').capture, 'before')
        ready = self.call('provider_readonly_preflight', read.production_php, (self.campaign.BASE / 'preflight.php').read_text(), timeout=180)
        self.campaign.save('preflight-result.json', ready)
        require(ready.get('production_gates_closed') is True and ready.get('probe_scope_matches') is True
                and ready.get('cloud_inventory', {}).get('complete') is True
                and len(ready.get('dns_names', [])) == 2 and all(row.get('record_count') == 0 for row in ready['dns_names']),
                'live_preflight_incomplete')
        self.outcome['readonly_preflight_passed'] = True

    def control(self):
        value = self.call('control_create', self.campaign.create_control)
        self.started = self.campaign.load('campaign-window.json')['started_at']
        require(isinstance(value.get('resource'), dict) and value['resource'].get('label') == self.run_id + '-control', 'control_create_not_confirmed')
        self.outcome['control_resource_id'] = value['resource']['id']
        deadline = min(self.started + 5400, self.wall() + 1080)
        while self.wall() < deadline:
            self.progress('control_boot')
            state = self.campaign.status()
            if state.get('ok') is True and all(state.get(key) is True for key in READY):
                break
            self.pause()
        else:
            raise Refused('control_boot_timeout')
        prepared = self.call('control_pipeline', self.mod('controller').control_ready)
        require(prepared.get('control_ready') is True, 'control_pipeline_incomplete')
        self.outcome['control_installation_passed'] = True

    def source_task(self, value):
        tasks = value.get('tasks', [])
        require(isinstance(tasks, list) and len(tasks) == 1 and tasks[0].get('label') == self.run_id + '-source', 'single_source_task_required')
        row = tasks[0]
        if hasattr(self, 'source_id'):
            require(row.get('id') == self.source_id and row.get('installation_id') == self.installation_id, 'original_source_task_changed')
        else:
            self.source_id, self.installation_id = row['id'], row['installation_id']
        require(row.get('status') not in TERMINAL, 'source_installation_terminal')
        if row.get('external_id'):
            require(int(row['external_id']) != self.outcome['control_resource_id'], 'source_is_control')
            self.outcome['source_resource_id'] = int(row['external_id'])
        return row

    def source(self):
        operator = self.mod('live_operator')
        source = self.source_task(self.call('source_queue', operator.command, 'source-queue'))
        self.outcome['source_task_id'] = source['id']
        deadline = min(self.started + 5400, self.wall() + 1080)
        while self.wall() < deadline:
            source = self.source_task(self.call('source_installation', operator.command, 'process', reference=source['id']))
            if source.get('bootstrap_state') == 'succeeded':
                break
            require(source.get('bootstrap_state') != 'failed', 'source_bootstrap_failed')
            self.pause()
        else:
            raise Refused('source_installation_timeout')
        reports = []
        deadline = min(self.started + 5400, self.wall() + 120)
        session = None
        while len(reports) < 3 and self.wall() < deadline:
            value = self.call('source_agent_reports', operator.command, 'status')
            current = self.source_task(value)
            require(current.get('bootstrap_state') == 'succeeded', 'source_bootstrap_state_changed')
            report = installation_observation(current, value, int(self.wall()), self.campaign.load('seed-result.json')['node_id'],
                                              run_id=self.run_id, binary_sha256=self.binary_sha256)
            if report:
                if session is None:
                    session = report['observation_session']
                require(report['observation_session'] == session, 'source_process_restarted')
                if not reports or report['at'] > reports[-1]['at']:
                    if reports:
                        require(report['at'] - reports[-1]['at'] <= 30, 'agent_reports_not_consecutive')
                    reports.append(report)
            if len(reports) < 3:
                self.pause()
        require(len(reports) == 3, 'three_fresh_agent_reports_required')
        self.campaign.save('source-agent-reports.json', {'run_id': self.run_id, 'task_id': source['id'], 'reports': reports}, exclusive=True)
        node = self.call('source_identity', self.mod('node').inspect, 'source')
        unit = node.get('unit', {})
        require(node.get('owner_matches') is True and node.get('cloud_init_done') is True
                and node.get('installation_id') == self.installation_id and node.get('binary_sha256') == self.binary_sha256
                and type(node.get('process_start_ticks')) is int and node['process_start_ticks'] > 0
                and unit.get('LoadState') == 'loaded' and unit.get('ActiveState') == 'active' and unit.get('SubState') == 'running'
                and int(unit.get('MainPID', 0)) > 0 and int(unit.get('NRestarts', -1)) == 0
                and unit.get('Result') == 'success' and node.get('resource_limits_enforced') is True,
                'source_process_or_limits_not_verified')
        require((node.get('udp_listener_ipv4') is True or node.get('udp_listener_ipv6') is True)
                and node.get('public_udp_ingress_denied') is True, 'source_listener_or_isolation_not_verified')
        require(node.get('cgroup_memory_events', {}).get('oom') == 0 and node['cgroup_memory_events'].get('oom_kill') == 0,
                'source_oom')
        self.campaign.save('node-source-baseline.json', node, exclusive=True)
        evidence = self.capture_diagnostics()
        require(evidence.get('status') == 'collected' and evidence.get('storage', {}).get('persisted') is True,
                'structured_install_diagnostics_required')
        wire = evidence['evidence']
        require(wire['bootstrap']['result'] == 'succeeded'
                and all(entry.get('state') == 'observed' and entry.get('exit_code') == 0 for entry in wire['execution'].values()),
                'three_install_execution_success_records_required')
        self.outcome.update(source_installation_passed=True, agent_fresh_report_count=3,
                            source_binary_sha256=self.binary_sha256, source_process_restart_count=0,
                            installer_execution_verified=True, source_resource_limits_verified=True)

    def capture_diagnostics(self):
        if self._diagnostic_result is not None:
            return self._diagnostic_result
        require(self.journal is not None, 'local_journal_required')
        name = 'bootstrap-diagnostics.json'
        paths = [self.campaign.BASE / name] + sorted(self.campaign.BASE.glob('diagnostic-result-*.json'))
        present = [path for path in paths if os.path.lexists(path)]
        if present:
            require(len(present) <= 2, 'diagnostic_records_ambiguous')
            for path in reversed(present):
                try:
                    result = self.journal.load(path.name)
                    require(set(result) == {'run_id', 'task_id', 'status', 'evidence', 'raw_logs_exported'}
                            and result['run_id'] == self.run_id and result['raw_logs_exported'] is False
                            and result['status'] in {'collected', 'response_unavailable'}
                            and str(uuid.UUID(result['task_id'])) == result['task_id']
                            and result['task_id'] == getattr(self, 'source_id', result['task_id']),
                            'diagnostic_record_invalid')
                    evidence = self.mod('diagnostic_capture').validate_record(result['evidence'], self.run_id,
                                                                             result['task_id'], 'source')
                    require((result['status'] == 'collected' and evidence is not None)
                            or (result['status'] == 'response_unavailable' and result['evidence'] is None),
                            'diagnostic_evidence_invalid')
                    self._diagnostic_result = dict(result, storage={'persisted': True, 'record': path.name,
                                                                  'fallback': path.name != name})
                    return self._diagnostic_result
                except (OSError, ValueError, TypeError, AttributeError, Refused, Rejected):
                    continue
            return {'status': 'prior_diagnostic_unreadable', 'storage': {'persisted': False}}
        if not os.path.lexists(self.campaign.BASE / 'resource-ledger.json'):
            unknown = os.path.lexists(self.campaign.BASE / 'source-dispatch-intent.json')
            return {'status': 'source_receipt_unavailable' if unknown else 'source_not_dispatched'}
        intent = self.campaign.BASE / 'bootstrap-diagnostics-intent.json'
        if intent.exists():
            return {'status': 'original_collection_only', 'storage': {'persisted': False}}
        if self.started is not None and self.wall() + 70 >= self.started + 7200:
            return {'status': 'cleanup_reserve_required'}
        try:
            self.campaign.save(intent.name, {'run_id': self.run_id, 'attempt_limit': 1, 'maximum_seconds': 10, 'read_only': True}, exclusive=True)
        except Exception as error:
            self.failure(error, 'diagnostics', 'intent')
            self._diagnostic_result = {'status': 'intent_unconfirmed', 'storage': {'persisted': False}}
            return self._diagnostic_result
        self.progress_before_cleanup('bounded_source_diagnostics')
        try:
            capture = self.mod('diagnostic_capture')
            node = self.mod('node')
            task = node.task('source')
            wire = node.execute('source', capture.render_probe(self.run_id, task['id'], 'source'), timeout=10)
            safe = capture.validate_record(wire, self.run_id, task['id'], 'source')
            result = {'run_id': self.run_id, 'task_id': task['id'], 'status': 'collected' if safe else 'response_unavailable',
                      'evidence': safe, 'raw_logs_exported': False}
        except Exception as error:
            self.failure(error, 'diagnostics', 'diagnostics')
            result = {'run_id': self.run_id, 'status': 'unavailable', 'raw_logs_exported': False,
                      'error': safe_error(error, site='diagnostics', logical_record='diagnostics')}
        result['storage'] = self.journal.diagnostic_result(result)
        self._diagnostic_result = result
        self.outcome['diagnostic_storage'] = dict(result['storage'])
        if not result['storage']['persisted']:
            self.outcome['ok'] = False
        return result

    def finish(self):
        checks = {}
        if self.started is None and (self.campaign.BASE / 'campaign-window.json').exists():
            try:
                self.started = self.campaign.load('campaign-window.json')['started_at']
            except Exception as error:
                self.failure(error, 'journal')
        def attempt(name, function):
            try:
                value = function()
                checks[name] = isinstance(value, dict) and value.get('ok') is True
                return value
            except BaseException as error:
                checks[name] = False
                self.failure(error, 'cleanup' if name == 'cleanup' else 'finalize')
                return {'ok': False, 'reason': 'final_check_unavailable', 'error': safe_error(error, site='finalize')}
        try:
            self.capture_diagnostics()
        except BaseException as error:
            self.failure(error, 'diagnostics', 'diagnostics')
        self.progress_before_cleanup('cleanup')
        dispatched = False
        for name in ['control-intent.json', 'source-dispatch-intent.json']:
            try:
                (self.campaign.BASE / name).lstat()
                dispatched = True
            except FileNotFoundError:
                continue
            except OSError as error:
                self.failure(error, 'cleanup', 'intent')
                dispatched = True  # Unreadable dispatch evidence is never proof of zero resources.
        if dispatched:
            attempt('cleanup', lambda: self.mod('cleanup').main())
        else:
            attempt('cleanup', lambda: (self.campaign.save('cleanup-result.json', {
                'ok': True, 'run_id': self.run_id, 'not_required_no_dispatch': True,
                'cloud_create_attempts': 0, 'dns_create_attempts': 0}) or {'ok': True}))
        if (self.campaign.BASE / 'production-before-health.json').exists():
            attempt('production_health', lambda: self.mod('production_guard').capture('after'))
        def gates_after():
            result = self.mod('read_only').production_php((self.campaign.BASE / 'discover.php').read_text())
            self.campaign.save('production-after.json', result)
            require(result.get('ok') is True and all(result.get('closed_gates', {}).get(key) is True for key in GATES), 'production_guard_changed')
            return result
        attempt('production_gates', gates_after)
        if self.started is not None and self.wall() > self.started + 7200:
            checks['campaign_deadline'] = False
        self.outcome['final_checks'] = checks
        self.outcome['ok'] = self.outcome.get('ok') is True and self.first_failure is None and all(checks.values())
        self.outcome['local_io_events'] = list(self.journal.events) if self.journal is not None else []
        saved = attempt('outcome_save', lambda: (self.campaign.save('round-outcome.json', self.outcome) or {'ok': True}))
        if saved.get('ok') is not True:
            self.outcome['ok'] = False
        final = attempt('finalize', lambda: self.mod('finalize_local').finalize(self.outcome))
        if final.get('ok') is True and self.outcome.get('ok') is not True:
            final = dict(final, ok=False, verdict='blocked_not_passed')
        return dict(final, driver_failure=self.first_failure,
                    failure_storage=self.outcome.get('failure_storage'), diagnostic_storage=self.outcome.get('diagnostic_storage'),
                    final_checks=dict(checks))

    def run(self):
        try:
            self.claim()
        except Exception as error:
            return {'ok': False, 'reason': 'new_round_claim_refused', 'cleanup_dispatched': False,
                    'error': safe_error(error, site='journal')}
        try:
            self.preflight()
            self.control()
            self.source()
            self.outcome.update(ok=self.first_failure is None, verdict_limit='installation_and_reporting_only')
            if self.first_failure is None:
                self.outcome['phase'] = 'installation_completed'
        except BaseException as error:
            self.failure(error, 'stage')
        finally:
            result = self.finish()
        return result
