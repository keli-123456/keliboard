"""Concrete deferred setup; only provider and SSH byte boundaries are fake."""
import copy
import json
import unittest
from contextlib import contextmanager
from unittest.mock import patch

from member_auxiliary import digest
from member_cli import RUN
from member_connected_setup import MemberConnectedSetup
from member_control_transport import MemberControlTransport
from member_initialization import STAGES
from member_transport import TransportError
import test_member_host_setup as host_fixture


class ConnectedSetupTests(unittest.TestCase):
    def setUp(self):
        self.f = host_fixture.HostSetupTests('runTest'); self.f.setUp(); self.addCleanup(self.f.doCleanups)
        self.saved, self.scope = self.f.saved, self.f.scope
        self.provider_calls, self.ssh_hosts = [], []
        self.host = '9.9.9.10'
        self.connection_failure = False
        self.saved['setup-control-request'] = dict(run_id=RUN, bundle_sha256=self.scope['bundle_sha256'],
            stage='control-create', predecessors_sha256=digest({}), request_id=digest([self.scope, 'control-create'])[:32])
        php = self.f.root / 'php'; php.write_bytes(b'synthetic-not-executed')
        self.control = MemberControlTransport(directory=self.f.root.resolve(), scope=self.scope, php=php.resolve(),
            guard=lambda *_: None, load=self.saved.get, save=self.f.save, execute=self.provider)
        # Fake process boundary, but real owner/hash checks against a local copy.
        entry = self.f.root / 'member_operator_creation_entry.php'
        entry.write_bytes(self.control.entry.read_bytes())
        self.control.entry = entry.resolve()
        self.args = dict(self.f.arguments)
        self.args.pop('transport')
        self.args.update(control=self.control, identity_file=self.f.root / 'key', known_hosts=self.f.root / 'known',
            grant_file=self.f.root / 'grant.json', guard=lambda *_: None, ssh_guard=lambda *_: None, execute=self.ssh)
        self.port = MemberConnectedSetup(**self.args)
        self.receipts = copy.deepcopy(self.f.receipts)

    def provider(self, command, payload):
        outer = json.loads(payload)
        self.provider_calls.append(outer['operation'])
        self.assertEqual('control-connection', outer['operation'])
        if self.connection_failure: raise TimeoutError('private-provider-error')
        pin = self.saved['setup-control-pin']
        return json.dumps(dict(ok=True, request_sha256=digest(outer), result=dict(
            request_sha256=digest(outer['request']), resolved=True, dispatched=True, ready=True, pin=pin,
            connection=dict(control_sha256=digest(pin), host=self.host, port=22)))).encode()

    def ssh(self, command, payload, timeout):
        self.ssh_hosts.append(command[-2])
        self.assertIsNotNone(self.saved.get('connected-setup-binding'))
        self.assertEqual('root@' + self.host, command[-2])
        if command[-1].endswith('member_import_entry.php') and json.loads(payload)['operation'] == 'stop':
            self.f.calls.append('bootstrap-stop')
            return b'{"ok":true,"operation":"stop","stopped":true}'
        return self.f.execute(command, payload, timeout)

    def dispatch(self, stage):
        for previous in STAGES[:STAGES.index(stage)]:
            self.receipts.setdefault(previous, dict(evidence_sha256='0' * 64))
        request = dict(run_id=RUN, bundle_sha256=self.scope['bundle_sha256'], stage=stage,
            predecessors_sha256=digest(self.receipts), request_id=digest([self.scope, stage])[:32])
        reply = getattr(self.port, stage.replace('-', '_'))(request, self.receipts)
        self.receipts[stage] = reply['receipt']
        return reply

    def reconcile(self):
        request = dict(scope=self.scope, stopped=True)
        reply = self.port.reconcile(request)
        self.assertEqual(dict(request_sha256=digest(request), resolved=True), reply)
        return self.port.cleanup_scope(reply)

    def test_construction_has_no_io_and_real_installation_uses_bound_provider_address(self):
        self.assertIsNone(self.port.rpc)
        self.assertEqual([], self.provider_calls + self.f.calls)
        for stage in ('control-install', 'runtime-install', 'private-import'):
            self.dispatch(stage)
            self.port = MemberConnectedSetup(**self.args)
        self.assertEqual(['control-connection'], self.provider_calls)
        self.assertEqual({'root@9.9.9.10'}, set(self.ssh_hosts))
        self.assertEqual(['upload', 'cold-publish', 'cold-configure', 'app-initialize', 'app-service-smoke',
            'app-readback', 'cold-private', 'bootstrap-import'], self.f.calls)
        self.assertNotIn(self.f.credentials.decode(), json.dumps(self.saved))
        self.assertNotIn(self.args['settings']['password'], json.dumps(self.saved))

    def test_stopped_expired_recovery_uses_original_address_without_provider_access(self):
        for stage in ('control-install', 'runtime-install', 'private-import'): self.dispatch(stage)
        self.f.now = self.scope['deadline_at'] + 1
        self.connection_failure = True
        self.port = MemberConnectedSetup(**self.args)
        value = self.reconcile()
        self.assertEqual(3, len(value['auxiliary']['private_files']))
        self.assertEqual(['bootstrap-stop', 'cold-stop'], self.f.calls[-2:])
        self.assertEqual(['control-connection'], self.provider_calls)
        self.port = MemberConnectedSetup(**self.args)
        before = list(self.f.calls)
        self.assertEqual(value, self.reconcile())
        self.assertEqual(before, self.f.calls)
        self.receipts = {'control-create': self.receipts['control-create']}
        with self.assertRaisesRegex(TransportError, 'connected_stopped'): self.dispatch('control-install')
        self.assertEqual(['control-connection'], self.provider_calls)

    def test_before_connection_or_after_resolution_failure_cleanup_is_local_only(self):
        self.connection_failure = True
        with self.assertRaises(TimeoutError): self.dispatch('control-install')
        self.assertNotIn('connected-setup-binding', self.saved)
        value = self.reconcile()
        self.assertEqual({}, value['roles'])
        self.assertEqual([], value['auxiliary']['private_files'])
        self.assertEqual([], self.f.calls)
        self.assertNotIn('private-provider-error', json.dumps(self.saved))

    def test_no_control_or_no_initialization_never_resolves_a_host_during_cleanup(self):
        self.saved.pop('setup-control-pin')
        self.saved.pop('setup-control-request')
        value = self.reconcile()
        self.assertEqual({}, value['roles'])
        self.assertEqual([], self.provider_calls + self.f.calls)

    def test_unknown_remote_effect_or_unjournaled_grant_cannot_be_declared_empty(self):
        for marker in MemberConnectedSetup.REMOTE_MARKERS:
            with self.subTest(marker=marker):
                self.saved[marker] = dict(unknown=True)
                with self.assertRaisesRegex(TransportError, 'untracked_effect'): self.reconcile()
                self.saved.pop(marker)
                self.assertNotIn('connected-setup-cleanup', self.saved)
        self.args['grant_file'].write_bytes(b'unknown')
        with self.assertRaisesRegex(TransportError, 'untracked_effect'): self.reconcile()
        self.assertEqual([], self.provider_calls + self.f.calls)

    def test_changed_material_and_endpoint_never_rebind_on_recovery(self):
        self.dispatch('control-install')
        original = copy.deepcopy(self.saved['operator-control-connection'])
        self.saved['operator-control-connection']['host'] = '9.9.9.11'
        with self.assertRaisesRegex(TransportError, 'endpoint_changed'): self.reconcile()
        self.saved['operator-control-connection'] = original
        (self.f.root / 'known').write_bytes(b'changed-host-key')
        with self.assertRaisesRegex(TransportError, 'material_changed'): self.reconcile()
        self.assertEqual(['control-connection'], self.provider_calls)
        self.assertEqual(['upload', 'cold-publish'], self.f.calls)

    def test_expiry_invalid_requests_and_missing_control_fail_before_provider_io(self):
        self.f.now = self.scope['deadline_at'] - 1200
        with self.assertRaisesRegex(TransportError, 'expired_or_clock'): self.dispatch('control-install')
        self.f.now = self.scope['started_at']
        with self.assertRaisesRegex(TransportError, 'connected_stage'): self.port.control_install({}, self.receipts)
        self.saved.pop('setup-control-pin')
        with self.assertRaisesRegex(TransportError, 'control_missing'): self.dispatch('control-install')
        with self.assertRaisesRegex(TransportError, 'not_stopped'): self.port.components(recover=True)
        self.assertEqual([], self.provider_calls + self.f.calls)

    def test_lost_install_reply_keeps_first_error_and_recovery_does_not_reinstall(self):
        self.f.failure = 'cold-publish'
        with self.assertRaises(TransportError): self.dispatch('control-install')
        first = copy.deepcopy(self.saved['host-setup-first-error'])
        self.f.failure = None
        self.port = MemberConnectedSetup(**self.args)
        with self.assertRaisesRegex(TransportError, 'readback_required'): self.dispatch('control-install')
        self.reconcile()
        self.assertEqual(first, self.saved['host-setup-first-error'])
        self.assertEqual(1, self.f.calls.count('cold-publish'))
        self.assertEqual(['control-connection'], self.provider_calls)

    def test_crash_after_connection_binding_before_dispatch_needs_no_remote_stop(self):
        self.port.components()
        self.assertIn('connected-setup-binding', self.saved)
        self.assertNotIn('connected-setup-dispatched', self.saved)
        self.port = MemberConnectedSetup(**self.args)
        value = self.reconcile()
        self.assertEqual([], value['auxiliary']['private_files'])
        self.assertEqual([], self.f.calls)
        self.assertEqual(['control-connection'], self.provider_calls)

    def test_binding_save_failure_cannot_publish_or_install(self):
        def fail(key, value):
            if key == 'connected-setup-binding': raise OSError('journal unavailable')
            self.f.save(key, value)
        self.port = MemberConnectedSetup(**dict(self.args, save=fail))
        with self.assertRaises(OSError): self.dispatch('control-install')
        self.assertIn('operator-control-connection', self.saved)
        self.assertNotIn('connected-setup-binding', self.saved)
        self.port = MemberConnectedSetup(**self.args)
        self.reconcile()
        self.assertEqual([], self.f.calls)
        self.assertEqual(['control-connection'], self.provider_calls)

    def test_each_installation_reply_loss_recovers_without_rebinding_or_replay(self):
        cases = {'upload': 'control-install', 'cold-publish': 'control-install',
            'cold-configure': 'runtime-install', 'app-initialize': 'runtime-install',
            'app-service-smoke': 'runtime-install', 'app-readback': 'private-import',
            'cold-private': 'private-import', 'bootstrap-import': 'private-import'}
        stages = ('control-install', 'runtime-install', 'private-import')
        for operation, failed_stage in cases.items():
            with self.subTest(operation=operation):
                self.setUp()
                for stage in stages[:stages.index(failed_stage)]: self.dispatch(stage)
                self.f.failure = operation
                with self.assertRaises(TransportError): self.dispatch(failed_stage)
                first = copy.deepcopy(self.saved['host-setup-first-error'])
                calls = list(self.f.calls)
                self.port = MemberConnectedSetup(**self.args)
                with self.assertRaisesRegex(TransportError, 'readback_required'): self.dispatch(failed_stage)
                self.assertEqual(calls, self.f.calls)
                self.f.failure = None
                self.f.now = self.scope['deadline_at'] + 1
                self.connection_failure = True
                self.port = MemberConnectedSetup(**self.args)
                self.reconcile()
                self.assertEqual(first, self.saved['host-setup-first-error'])
                self.assertEqual(['control-connection'], self.provider_calls)
                self.assertEqual(1, self.f.calls.count(operation))
                self.assertNotIn('synthetic-secret-must-not-be-saved', json.dumps(self.saved))

    def test_expiry_during_connection_readback_does_not_bind_or_upload(self):
        original = self.control.execute
        def expire(command, payload):
            result = original(command, payload)
            self.f.now = self.scope['deadline_at'] - 1200
            return result
        self.control.execute = expire
        with self.assertRaisesRegex(TransportError, 'expired_or_clock'): self.dispatch('control-install')
        self.assertIn('operator-control-connection', self.saved)
        self.assertNotIn('connected-setup-binding', self.saved)
        self.reconcile()
        self.assertEqual([], self.f.calls)
        self.assertEqual(['control-connection'], self.provider_calls)

    def test_connected_host_entry_constructs_concrete_ports_inside_one_journal_lock(self):
        from member_assembly import run_connected_round
        creation_scope = dict(self.scope, task_id='11111111-1111-4111-8111-111111111111', account_id=1,
            bootstrap_sha256='a' * 64, baseline_ids=[], plan={})
        creation_path = self.control.directory / 'creation-scope.json'
        creation_path.write_text(json.dumps(creation_scope)); creation_path.chmod(0o600)
        active = []
        @contextmanager
        def journal(directory, run_id):
            self.assertEqual(directory, self.f.root)
            self.assertEqual(run_id, RUN)
            active.append(True)
            try: yield self.saved.get, self.f.save
            finally: active.pop()
        setup_inputs = {k: v for k, v in self.args.items()
            if k not in ('scope', 'control', 'load', 'save', 'guard', 'clock')}
        control_inputs = dict(directory=self.control.directory, php=self.control.php,
            guard=lambda *_: None, execute=self.provider)
        def assemble(**options):
            self.assertEqual(active, [True])
            self.assertIsInstance(options['setup'], MemberConnectedSetup)
            self.assertIsInstance(options['control'], MemberControlTransport)
            self.assertIs(options['setup'].control, options['control'])
            self.assertEqual(creation_scope, options['control'].scope)
            self.assertEqual(options['load'], self.saved.get)
            self.assertEqual(options['setup'].load, self.saved.get)
            self.assertEqual(options['control'].load, self.saved.get)
            self.assertIsNone(options['setup'].rpc)
            return self
        def drive(value):
            self.assertIs(value, self)
            self.assertEqual(active, [True])
            return {'fixture': 'entry-wiring-only'}
        with patch('member_assembly.round_journal', journal), \
                patch('member_assembly.MemberRoundAssembly', assemble), \
                patch('member_assembly.run', drive), patch.object(self, 'round', lambda: self, create=True):
            result = run_connected_round(self.f.root, control_inputs=control_inputs, setup_inputs=setup_inputs,
                scope=self.scope, guard=lambda *_: None, clock=lambda: self.f.now)
        self.assertEqual(result, {'fixture': 'entry-wiring-only'})
        self.assertEqual([], active + self.provider_calls + self.f.calls)
        creation_path.write_text(json.dumps(dict(creation_scope, bundle_sha256='0' * 64)))
        with patch('member_assembly.round_journal', journal), self.assertRaisesRegex(TransportError, 'creation_scope'):
            run_connected_round(self.f.root, control_inputs=control_inputs, setup_inputs=setup_inputs,
                scope=self.scope, guard=lambda *_: None)
        with self.assertRaisesRegex(TransportError, 'entry_bindings'):
            run_connected_round(self.f.root, control_inputs=dict(control_inputs, host=self.host), setup_inputs=setup_inputs)
        with self.assertRaisesRegex(TransportError, 'entry_bindings'):
            run_connected_round(self.f.root, control_inputs=control_inputs,
                setup_inputs=dict(setup_inputs, scope=self.scope))


if __name__ == '__main__': unittest.main()
