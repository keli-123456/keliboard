"""Synthetic driver only: no frozen round imports, private material or transports."""
import copy
import errno
import hashlib
import io
import json
import os
from pathlib import Path
import socket
import subprocess
import tarfile
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import Mock, patch

import diagnostic_capture
import driver_journal
import install_only_runner as driver
import application_bundle
from test_diagnostic_capture import response, TASK
from test_driver_journal import SECRET, sharing_error, windows_handle


RUN = 'keli-accept-synthetic-local-io-01234567'
COMMIT = '1' * 40
BINARY = '2' * 64
INSTALLATION = '3' * 32


class SyntheticDriverCase(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory(prefix='keli-synthetic-install-io-')
        self.addCleanup(temporary.cleanup)
        self.base = Path(temporary.name).resolve()
        self.now = 1000
        self.events = []
        self.auth = {'run_id': RUN, 'user_approved': True, 'source_commit': COMMIT,
                     'scope': 'control_and_source_installation_only',
                     'roles': {'control': {'size': 'g6-standard-2', 'maximum_create_attempts': 1},
                               'source': {'size': 'g6-standard-4', 'maximum_create_attempts': 1}},
                     'peak_instance_count': 2, 'maximum_total_create_attempts': 2, 'maximum_campaign_seconds': 7200,
                     **dict.fromkeys(driver.FORBIDDEN_FLAGS, False)}
        for name in driver.REQUIRED:
            source = Path(driver.__file__).with_name(name)
            value = source.read_bytes() if name in {'driver_journal.py', 'install_only_runner.py', 'application_bundle.py', 'controller_readiness.py'} else b'# inert fixture\n'
            (self.base / name).write_bytes(value)
        self.write_bundle()
        self.write('authorization.json', self.auth)
        self.repin()
        self.campaign = SimpleNamespace(BASE=self.base, RUN=RUN, AUTH=self.auth,
                                        save=self.write, load=self.read, assert_scope=Mock(), assert_open=Mock(),
                                        create_control=Mock(side_effect=self.create_control),
                                        status=Mock(return_value={'ok': True, **dict.fromkeys(driver.READY, True)}))
        self.source = {'id': TASK, 'label': RUN + '-source', 'installation_id': INSTALLATION,
                       'status': 'running', 'bootstrap_state': 'succeeded', 'external_id': '102'}
        self.node = SimpleNamespace(task=Mock(return_value=self.source), execute=Mock(side_effect=self.probe),
                                    inspect=Mock(side_effect=self.inspect))
        owner = self
        class CleanupJournal:
            def __init__(self, base):
                self.base = base
            def path(self, name):
                if name == 'outside.json':
                    raise driver.Refused('original_path_guard')
                owner.events.append('cleanup:path')
                return self.base / name
            def save(self, *args, **kwargs):
                raise AssertionError('legacy writer must be replaced')
            def load(self, *args, **kwargs):
                raise AssertionError('legacy reader must be replaced')
        self.cleanup = SimpleNamespace(RUN=RUN, Journal=CleanupJournal, main=Mock(side_effect=self.clean))
        self.operator = SimpleNamespace(command=Mock(side_effect=self.command))
        self.finalizer = SimpleNamespace(finalize=Mock(side_effect=self.finalize))
        self.modules = {'campaign': self.campaign, 'read_only': SimpleNamespace(production_php=Mock(side_effect=self.readonly)),
                        'production_guard': SimpleNamespace(capture=Mock(side_effect=self.health)),
                        'controller': SimpleNamespace(control_ready=Mock(side_effect=self.control_ready)),
                        'live_operator': self.operator, 'node': self.node, 'diagnostic_capture': diagnostic_capture,
                        'cleanup': self.cleanup, 'finalize_local': self.finalizer}
        self.runner = driver.InstallRunner(run_id=RUN, application_commit=COMMIT, binary_sha256=BINARY,
                                           loader=self.modules.__getitem__, wall=lambda: self.now, sleep=self.sleep)
        for target in [(subprocess, 'Popen'), (socket, 'create_connection')]:
            blocked = patch.object(*target, side_effect=AssertionError('real transport forbidden'))
            blocked.start()
            self.addCleanup(blocked.stop)

    def write(self, name, value, exclusive=False):
        with (self.base / name).open('x' if exclusive else 'w', encoding='utf-8') as stream:
            json.dump(value, stream)

    def write_bundle(self, *, crlf=False):
        with tarfile.open(self.base / 'application.tar.gz', 'w:gz') as archive:
            for name in application_bundle.REQUIRED_SHELLS:
                raw = b'#!/usr/bin/env bash\nset -euo pipefail\n'
                if crlf:
                    raw = raw.replace(b'\n', b'\r\n')
                member = tarfile.TarInfo(name)
                member.size, member.mode = len(raw), 0o755
                archive.addfile(member, io.BytesIO(raw))

    def read(self, name):
        return json.loads((self.base / name).read_text(encoding='utf-8'))

    def repin(self):
        self.write('offline-result.json', {'ok': True, 'run_id': RUN, 'file_digests': {
            name: hashlib.sha256((self.base / name).read_bytes()).hexdigest() for name in driver.REQUIRED}})

    def sleep(self, seconds):
        self.now += seconds

    def readonly(self, code, **kwargs):
        return {'ok': True, 'closed_gates': dict.fromkeys(driver.GATES, True),
                'production_gates_closed': True, 'probe_scope_matches': True, 'cloud_inventory': {'complete': True},
                'dns_names': [{'record_count': 0}, {'record_count': 0}]}

    def health(self, when):
        self.campaign.save('production-' + when + '-health.json', {'ok': True})
        return {'ok': True}

    def create_control(self):
        self.events.append('create:control')
        self.campaign.save('control-intent.json', {'run_id': RUN}, exclusive=True)
        self.campaign.save('campaign-window.json', {'started_at': self.now})
        return {'ok': True, 'resource': {'id': 101, 'label': RUN + '-control'}}

    def control_ready(self):
        self.campaign.save('seed-result.json', {'node_id': 1})
        return {'ok': True, 'control_ready': True}

    def command(self, command, **kwargs):
        self.events.append('command:' + command)
        if command == 'source-queue':
            self.campaign.save('source-dispatch-intent.json', {'run_id': RUN}, exclusive=True)
        elif command == 'process':
            self.events.append('create:source')
            self.campaign.save('resource-ledger.json', {'run_id': RUN, 'synthetic': True})
        return {'ok': True, 'tasks': [copy.deepcopy(self.source)], 'agents': [{
            'installation_id': INSTALLATION, 'at': int(self.now), 'runtime': {
                'binary_sha256': BINARY, 'observation_session': '4' * 32,
                'configured_nodes': [{'id': 1, 'protocol': 'hysteria2', 'port': 24443}]}}]}

    def inspect(self, role):
        return {'ok': True, 'owner_matches': True, 'cloud_init_done': True, 'installation_id': INSTALLATION,
                'binary_sha256': BINARY, 'process_start_ticks': 1, 'resource_limits_enforced': True,
                'udp_listener_ipv4': True, 'public_udp_ingress_denied': True,
                'cgroup_memory_events': {'oom': 0, 'oom_kill': 0}, 'unit': {
                    'LoadState': 'loaded', 'ActiveState': 'active', 'SubState': 'running',
                    'MainPID': '123', 'NRestarts': '0', 'Result': 'success'}}

    def probe(self, role, code, timeout):
        self.events.append('probe')
        self.assertEqual((role, timeout), ('source', 10))
        value = response()
        value.update(run_id=RUN, task_id=TASK)
        value['bootstrap']['result'] = 'succeeded'
        value['execution'] = {script: {'state': 'observed', 'phase': 'complete', 'event': 'exit',
                                      'exit_code': 0, 'error_code': None, 'error_line': None, 'exit_line': 10}
                              for script in value['execution']}
        return value

    def clean(self):
        self.events.append('cleanup')
        journal = self.cleanup.Journal(self.base)
        journal.save('cleanup-result.json', {'ok': True, 'run_id': RUN}, exclusive=True)
        self.assertTrue(journal.load('cleanup-result.json')['ok'])
        return {'ok': True}

    def finalize(self, outcome):
        value = {'ok': outcome['ok'], 'verdict': 'passed' if outcome['ok'] else 'blocked_not_passed',
                 'cleanup_complete': self.read('cleanup-result.json')['ok']}
        self.campaign.save('final-result.json', value, exclusive=True)
        self.campaign.save('closed.json', {'run_id': RUN}, exclusive=True)
        return value

    def claimed_source(self):
        self.runner.claim()
        self.runner.started = self.now
        self.runner.phase = 'source_installation'
        for name in ['control-intent.json', 'resource-ledger.json']:
            self.campaign.save(name, {'run_id': RUN}, exclusive=True)
        return self.runner.journal

    def assert_private_absent(self, result):
        public = json.dumps(result) + ''.join(path.read_text(encoding='utf-8') for path in self.base.glob('*.json'))
        self.assertNotIn(SECRET, public)


class DriverTests(SyntheticDriverCase):
    def test_controller_without_mainland_echoes_still_installs_the_source_and_cleans_up(self):
        self.campaign.status.return_value.update(root_echoes=0, domestic_ping_status='failed')
        result = self.runner.run()
        self.assertTrue(result['ok'], result)
        self.modules['controller'].control_ready.assert_called_once()
        self.assertEqual(self.events.count('create:control'), 1)
        self.assertEqual(self.events.count('create:source'), 1)
        self.assertNotIn('phase_probe', self.runner.modules)
        self.node.execute.assert_called_once()
        self.cleanup.main.assert_called_once()

    def test_controller_pipeline_failure_cannot_dispatch_the_source(self):
        self.modules['controller'].control_ready.side_effect = None
        self.modules['controller'].control_ready.return_value = {'ok': True, 'control_ready': False, 'root_echoes': 4}
        result = self.runner.run()
        self.assertFalse(result['ok'])
        self.assertNotIn('create:source', self.events)
        self.node.execute.assert_not_called()
        self.cleanup.main.assert_called_once()

    def test_full_synthetic_installation_uses_new_io_and_exactly_once_stages(self):
        inputs = {name: (self.base / name).read_bytes() for name in ['authorization.json', 'offline-result.json']}
        result = self.runner.run()
        self.assertTrue(result['ok'], result)
        self.assertEqual([e for e in self.events if e.startswith('create:')], ['create:control', 'create:source'])
        self.assertEqual(self.events.count('probe'), 1)
        self.assertEqual(self.events.count('cleanup'), 1)
        self.assertLess(self.events.index('probe'), self.events.index('cleanup'))
        self.assertEqual(self.read('round-outcome.json')['agent_fresh_report_count'], 3)
        for name, original in inputs.items():
            self.assertEqual((self.base / name).read_bytes(), original)
        self.assertTrue((self.base / 'closed.json').is_file())
        with self.assertRaises(driver_journal.Rejected):
            self.runner.progress('closed')

    def test_transient_progress_sharing_error_does_not_repeat_provider_or_probe(self):
        replace = os.replace
        bind = self.runner.bind_journal
        failures = 0
        def windows_io():
            bind()
            self.runner.journal.windows = True
        def transient(source, target):
            nonlocal failures
            if Path(target).name == 'progress.json' and failures < 2:
                failures += 1
                raise sharing_error(32)
            return replace(source, target)
        with patch.object(self.runner, 'bind_journal', side_effect=windows_io), \
             patch.object(driver_journal.os, 'replace', side_effect=transient):
            result = self.runner.run()
        self.assertTrue(result['ok'], result)
        self.assertEqual(failures, 2)
        self.assertEqual(self.events.count('create:source'), 1)
        self.assertEqual(self.events.count('create:control'), 1)
        self.node.execute.assert_called_once()
        self.cleanup.main.assert_called_once()

    def test_permanent_progress_failure_cannot_skip_diagnostics_or_cleanup(self):
        self.claimed_source()
        error = PermissionError(errno.EACCES, SECRET)
        self.runner.failure(error, 'stage', 'progress')
        save = self.runner.journal.save
        def denied(name, value, exclusive=False):
            if name == 'progress.json':
                raise sharing_error()
            return save(name, value, exclusive)
        with patch.object(self.runner.journal, 'save', side_effect=denied):
            result = self.runner.finish()
        self.assertFalse(result['ok'])
        self.assertEqual(self.read('round-outcome.json')['phase'], 'source_installation')
        self.assertEqual(result['driver_failure']['errno'], errno.EACCES)
        self.assertIsNone(result['driver_failure']['winerror'])
        self.assertEqual(self.events.count('probe'), 1)
        self.cleanup.main.assert_called_once()
        self.assertTrue(self.read('bootstrap-diagnostics.json')['status'] == 'collected')
        self.assertTrue(result['failure_storage']['persisted'])
        self.assert_private_absent(result)

    def test_diagnostic_progress_failure_still_collects_once_and_records_numbers(self):
        io = self.claimed_source()
        self.runner.progress = Mock(side_effect=sharing_error(33))
        result = self.runner.capture_diagnostics()
        self.assertEqual(result['status'], 'collected')
        self.assertTrue(result['storage']['persisted'])
        self.assertEqual(self.runner.capture_diagnostics(), result)
        self.node.execute.assert_called_once()
        self.assertEqual(self.runner.first_failure['winerror'], 33)
        self.assertEqual(io.load(self.runner.outcome['failure_storage']['record'])['phase'], 'source_installation')
        self.assert_private_absent(result)

    def test_diagnostic_target_denied_uses_durable_fallback_not_recollection(self):
        io = self.claimed_source()
        save = io.save
        def denied(name, value, exclusive=False):
            if name == 'bootstrap-diagnostics.json':
                raise sharing_error()
            return save(name, value, exclusive)
        with patch.object(io, 'save', side_effect=denied):
            result = self.runner.capture_diagnostics()
            self.assertEqual(self.runner.capture_diagnostics(), result)
        self.assertTrue(result['storage']['persisted'] and result['storage']['fallback'])
        self.assertEqual(io.load(result['storage']['record'])['status'], 'collected')
        self.node.execute.assert_called_once()
        self.runner._diagnostic_result = None
        reread = self.runner.capture_diagnostics()
        self.assertEqual(reread['status'], 'collected')
        self.node.execute.assert_called_once()
        self.assert_private_absent(result)

    def test_all_diagnostic_outputs_denied_cannot_pass_or_repeat_probe(self):
        io = self.claimed_source()
        save = io.save
        def denied(name, value, exclusive=False):
            if name == 'bootstrap-diagnostics.json' or name.startswith(('diagnostic-result-', 'local-failure-')):
                raise sharing_error()
            return save(name, value, exclusive)
        with patch.object(io, 'save', side_effect=denied):
            result = self.runner.capture_diagnostics()
            final = self.runner.finish()
        self.assertFalse(result['storage']['persisted'])
        self.assertFalse(final['ok'])
        self.node.execute.assert_called_once()
        self.cleanup.main.assert_called_once()
        self.assert_private_absent(final)

    def test_total_filesystem_failure_preserves_first_numeric_error_in_memory(self):
        io = self.claimed_source()
        with patch.object(io, 'save', side_effect=OSError(errno.ENOSPC, SECRET)):
            self.runner.failure(sharing_error(32), 'stage', 'progress')
            result = self.runner.finish()
        self.assertFalse(result['ok'])
        self.assertFalse(result['failure_storage']['persisted'])
        self.assertEqual((result['driver_failure']['errno'], result['driver_failure']['winerror']), (13, 32))
        self.cleanup.main.assert_called_once()
        self.assert_private_absent(result)

    def test_diagnostic_intent_sync_failure_is_spent_and_never_probes(self):
        self.claimed_source()
        with patch.object(driver_journal.os, 'fsync', side_effect=OSError(errno.ENOSPC, SECRET)):
            result = self.runner.capture_diagnostics()
        self.assertEqual(result['status'], 'intent_unconfirmed')
        self.assertTrue((self.base / 'bootstrap-diagnostics-intent.json').exists())
        self.runner._diagnostic_result = None
        self.assertEqual(self.runner.capture_diagnostics()['status'], 'original_collection_only')
        self.node.execute.assert_not_called()

    def test_existing_intent_without_result_is_not_resampled(self):
        io = self.claimed_source()
        io.save('bootstrap-diagnostics-intent.json', {'run_id': RUN}, exclusive=True)
        self.assertEqual(self.runner.capture_diagnostics()['status'], 'original_collection_only')
        self.node.execute.assert_not_called()
        (self.base / 'resource-ledger.json').unlink()
        io.save('source-dispatch-intent.json', {'run_id': RUN}, exclusive=True)
        self.assertEqual(self.runner.capture_diagnostics()['status'], 'source_receipt_unavailable')
        self.node.execute.assert_not_called()

    def test_cached_diagnostics_must_have_exact_safe_schema(self):
        io = self.claimed_source()
        for change in [{'raw_log': SECRET}, {'task_id': 'not-a-uuid'}, {'run_id': 'foreign'}, {'evidence': {'raw_log': SECRET}}]:
            value = {'run_id': RUN, 'task_id': TASK, 'status': 'collected', 'evidence': self.probe('source', '', 10),
                     'raw_logs_exported': False, **change}
            io.save('bootstrap-diagnostics.json', value)
            self.assertEqual(self.runner.capture_diagnostics()['status'], 'prior_diagnostic_unreadable')
        self.node.execute.assert_not_called()

    def test_transport_error_is_numeric_only_and_cleanup_still_runs(self):
        self.claimed_source()
        self.node.execute.side_effect = sharing_error()
        result = self.runner.finish()
        self.assertFalse(result['ok'])
        self.assertEqual(self.read('bootstrap-diagnostics.json')['error']['winerror'], 5)
        self.cleanup.main.assert_called_once()
        self.assert_private_absent(result)

    def test_cleanup_error_does_not_mask_first_error_or_become_passed(self):
        self.claimed_source()
        self.runner.failure(sharing_error(32), 'stage')
        self.cleanup.main.side_effect = OSError(errno.ENOSPC, SECRET)
        result = self.runner.finish()
        self.assertFalse(result['ok'])
        self.assertFalse(result['final_checks']['cleanup'])
        self.assertEqual(result['driver_failure']['winerror'], 32)
        self.cleanup.main.assert_called_once()
        self.assert_private_absent(result)

    def test_outcome_save_failure_cannot_be_hidden_by_successful_finalizer(self):
        self.claimed_source()
        self.runner.outcome['ok'] = True
        save = self.runner.journal.save
        def denied(name, value, exclusive=False):
            if name == 'round-outcome.json':
                raise sharing_error()
            return save(name, value, exclusive)
        self.finalizer.finalize.side_effect = lambda outcome: {'ok': True, 'verdict': 'passed'}
        with patch.object(self.runner.journal, 'save', side_effect=denied):
            result = self.runner.finish()
        self.assertFalse(result['ok'])
        self.assertEqual(result['verdict'], 'blocked_not_passed')
        self.assertFalse(result['final_checks']['outcome_save'])

    def test_cleanup_adapter_preserves_its_existing_scope_guards(self):
        self.claimed_source()
        journal = self.runner.mod('cleanup').Journal(self.base)
        journal.save('cleanup-intent.json', {'ok': True}, exclusive=True)
        with self.assertRaises(FileExistsError):
            journal.save('cleanup-intent.json', {'ok': False}, exclusive=True)
        self.assertEqual(journal.load('missing.json', {'default': True}), {'default': True})
        with self.assertRaisesRegex(driver.Refused, 'original_path_guard'):
            journal.save('outside.json', {})
        journal.base = self.base.parent
        with self.assertRaisesRegex(driver.Refused, 'cleanup_journal_directory_changed'):
            journal.load('cleanup-intent.json')

    def test_fresh_scope_pins_and_stop_guards_refuse_before_any_dispatch(self):
        for name in ['round-intent.json', 'closed.json', 'control-intent.json', 'cleanup-intent.json', 'stop-request.json']:
            with self.subTest(marker=name):
                self.write(name, {})
                self.assertFalse(self.runner.run()['ok'])
                (self.base / name).unlink()
        (self.base / 'node.py').write_text('changed fixture')
        self.assertFalse(self.runner.run()['ok'])
        self.campaign.create_control.assert_not_called()
        self.operator.command.assert_not_called()
        self.cleanup.main.assert_not_called()
        self.assertEqual(self.events, [])

    def test_widened_scope_or_missing_loaded_tool_pin_never_dispatches(self):
        original = copy.deepcopy(self.auth)
        changes = [{flag: True} for flag in driver.FORBIDDEN_FLAGS]
        changes += [{'maximum_total_create_attempts': 3}, {'peak_instance_count': 3},
                    {'maximum_campaign_seconds': 7200.0}, {'user_approved': False}, {'source_commit': '0' * 40}]
        for change in changes:
            self.auth.clear(); self.auth.update(original, **change)
            with self.subTest(field=next(iter(change))):
                self.assertFalse(self.runner.run()['ok'])
        self.auth.clear(); self.auth.update(original)
        (self.base / 'driver_journal.py').write_text('changed fixture')
        self.repin()
        self.assertFalse(self.runner.run()['ok'])
        self.campaign.create_control.assert_not_called()
        self.operator.command.assert_not_called()

    def test_frozen_r7_identifier_has_no_resumable_constructor(self):
        for run_id in driver.CLOSED_ROUNDS:
            with self.assertRaisesRegex(driver.Refused, 'fresh_run_identity_required'):
                driver.InstallRunner(run_id=run_id, application_commit=COMMIT, binary_sha256=BINARY,
                                     loader=Mock(side_effect=AssertionError('imports forbidden')))

    def test_repinning_a_different_readiness_helper_does_not_change_the_loaded_policy(self):
        (self.base / 'controller_readiness.py').write_text('# different fixture policy\n')
        self.repin()
        self.assertFalse(self.runner.run()['ok'])
        self.assertFalse((self.base / 'round-intent.json').exists())
        self.campaign.create_control.assert_not_called()
        self.operator.command.assert_not_called()
        self.cleanup.main.assert_not_called()

    def test_matching_hashes_do_not_authorize_crlf_or_invalid_archive(self):
        self.write_bundle(crlf=True)
        self.repin()
        self.assertFalse(self.runner.run()['ok'])
        self.assertFalse((self.base / 'round-intent.json').exists())
        (self.base / 'application.tar.gz').write_bytes(b'not-an-archive')
        self.repin()
        self.assertFalse(self.runner.run()['ok'])
        self.campaign.create_control.assert_not_called()
        self.operator.command.assert_not_called()
        self.cleanup.main.assert_not_called()

    @unittest.skipUnless(os.name == 'nt', 'real Windows sharing semantics')
    def test_real_progress_handle_lock_does_not_prevent_capture_and_cleanup(self):
        io = self.claimed_source()
        self.runner.progress('source_installation')
        with windows_handle(self.base / 'progress.json'):
            result = self.runner.capture_diagnostics()
        self.assertTrue(result['storage']['persisted'])
        self.assertIn(self.runner.first_failure['winerror'], {5, 32, 33})
        self.assertEqual(io.load(self.runner.outcome['failure_storage']['record'])['error']['operation'], 'replace_snapshot')
        final = self.runner.finish()
        self.assertFalse(final['ok'])
        self.node.execute.assert_called_once()
        self.cleanup.main.assert_called_once()


if __name__ == '__main__':
    unittest.main()
