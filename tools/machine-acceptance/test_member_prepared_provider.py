"""Local-only cleanup material preparation; no credentials or provider access."""
import copy
import hashlib
import json
from pathlib import Path
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch

from member_auxiliary import digest
from member_cli import RUN
from member_prepared_provider import MemberPreparedProvider, immutable_file
from member_transport import TransportError


class PreparedProviderTests(unittest.TestCase):
    def setUp(self):
        temp = tempfile.TemporaryDirectory(); self.addCleanup(temp.cleanup)
        self.root = Path(temp.name).resolve(); self.root.chmod(0o700)
        self.machine = self.root / 'machine'; self.machine.write_bytes(b'synthetic-machine')
        self.php = self.root / 'php'; self.php.write_bytes(b'synthetic-php')
        self.scope = dict(run_id=RUN, bundle_sha256='a' * 64, started_at=1000, deadline_at=8200)
        task = '11111111-1111-4111-8111-111111111111'; short = task.replace('-', '')
        self.control = dict(run_id=RUN, external_id='100', task_id=task, label='keli-' + short,
            marker='keli-create-' + short, region='sg-sin-2', size='g6-standard-2', image='linode/debian12',
            provider_created_at='2026-11-18T00:00:00')
        self.dns = dict(run_id=RUN, record_id='200', name='control-' + RUN + '.dnscloudcloud.top',
            type='A', line='default', value='203.0.113.1', marker='synthetic-ownership')
        self.cleanup = dict(run_id=RUN, roles={}, auxiliary=dict(run_id=RUN, control=self.control,
            records=[self.dns], faults=[], private_files=[], requests=[]))
        self.saved, self.calls, self.stopped = {'setup-control-pin': copy.deepcopy(self.control)}, [], False
        self.credentials = json.dumps(dict(baseline_ids=['99'], linode=dict(api_token='synthetic-only'),
            aliyun=dict(access_key_id='synthetic-id', access_key_secret='synthetic-key'))).encode()
        self.approval = dict(schema=1, operation='prepare-provider-cleanup', authorized=True, scope=self.scope,
            credentials_sha256=hashlib.sha256(self.credentials).hexdigest(),
            operator_machine_sha256=hashlib.sha256(self.machine.read_bytes()).hexdigest(), baseline_ids=['99'])
        self.write('provider-credentials.json', self.credentials)
        self.write('cleanup-parent.json', json.dumps(self.approval).encode())
        def persist(k, v): self.saved[k] = copy.deepcopy(v)
        def stopped():
            if not self.stopped: raise TransportError('cleanup_connected_runtime_not_stopped')
            return copy.deepcopy(self.cleanup)
        self.runtime = SimpleNamespace(load=self.saved.get, save=persist, stopped=stopped,
            setup=SimpleNamespace(scope=self.scope, persist=persist))

    def write(self, name, raw):
        path = self.root / name; path.write_bytes(raw); path.chmod(0o600)

    def provider(self):
        return MemberPreparedProvider(runtime=self.runtime, directory_path=self.root, php=self.php,
            machine_file=self.machine, guard=lambda *_: None, execute=self.execute)

    def execute(self, args, payload):
        request = json.loads(payload); self.calls.append(request)
        return json.dumps(dict(ok=True, request_sha256=digest(request),
            result=dict(pin_sha256=digest(request['pin']), status='absent'))).encode()

    def test_constructor_only_seals_existing_approval_and_stopped_preparation_narrows_scope(self):
        provider = self.provider()
        self.assertFalse(provider.prepared.exists()); self.assertEqual([], self.calls)
        with self.assertRaisesRegex(TransportError, 'not_stopped'): provider.prepare()
        self.stopped = True
        transport = provider.prepare()
        self.assertEqual([self.control, self.dns], transport.scope['pins'])
        self.assertEqual(['99'], transport.scope['baseline_ids'])
        permit = json.loads((provider.prepared / 'provider-permit.json').read_bytes())
        self.assertEqual(digest(transport.scope), permit['scope_sha256'])
        self.assertEqual(self.credentials, (provider.prepared / 'provider-credentials.json').read_bytes())
        self.assertEqual([], self.calls)
        provider.dispatch('control-inspect', self.control)
        self.assertEqual(1, len(self.calls))

    def test_missing_parent_or_scope_creds_machine_changes_fail_before_provider(self):
        provider = self.provider(); self.stopped = True
        for field in ('scope', 'credentials_sha256', 'operator_machine_sha256', 'authorized', 'operation'):
            wrong = dict(self.approval); wrong[field] = False
            self.write('cleanup-parent.json', json.dumps(wrong).encode())
            with self.subTest(field=field), self.assertRaisesRegex(TransportError, 'approval'): provider.prepare()
        self.write('cleanup-parent.json', json.dumps(self.approval).encode())
        self.machine.write_bytes(b'changed')
        with self.assertRaisesRegex(TransportError, 'approval'): provider.prepare()
        self.assertEqual([], self.calls)

    def test_baseline_foreign_control_and_dns_names_never_gain_cleanup_authority(self):
        provider = self.provider(); self.stopped = True
        original = copy.deepcopy(self.cleanup)
        for change in ('baseline', 'control', 'dns'):
            self.cleanup = copy.deepcopy(original)
            if change == 'baseline':
                self.cleanup['auxiliary']['control']['external_id'] = '99'
                self.saved['setup-control-pin'] = self.cleanup['auxiliary']['control']
            elif change == 'control': self.cleanup['auxiliary']['control']['external_id'] = '101'
            else: self.cleanup['auxiliary']['records'][0]['name'] = 'production.example'
            with self.subTest(change=change), self.assertRaises(TransportError): provider.prepare()
        self.assertEqual([], self.calls)
        self.assertFalse(provider.prepared.exists())

    def test_preparation_interruption_resumes_identical_files_without_new_authority(self):
        provider = self.provider(); self.stopped = True
        def interrupt(path, raw):
            if path.name == 'provider-permit.json': raise TimeoutError('synthetic interrupt')
            immutable_file(path, raw)
        with patch('member_prepared_provider.immutable_file', side_effect=interrupt):
            with self.assertRaises(TimeoutError): provider.prepare()
        first = (provider.prepared / 'provider-scope.json').read_bytes()
        resumed = self.provider(); resumed.prepare()
        self.assertEqual(first, (provider.prepared / 'provider-scope.json').read_bytes())
        self.assertEqual([], list(provider.prepared.glob('.prepare-*')))
        self.assertEqual([], self.calls)
        (provider.prepared / 'provider-permit.json').write_bytes(b'corrupt')
        with self.assertRaisesRegex(TransportError, 'file_changed'): resumed.prepare()
        self.assertEqual(b'corrupt', (provider.prepared / 'provider-permit.json').read_bytes())

    def test_scope_drift_and_operation_expansion_are_rejected(self):
        provider = self.provider(); self.stopped = True; provider.prepare()
        self.cleanup['auxiliary']['records'][0]['record_id'] = '201'
        with self.assertRaisesRegex(TransportError, 'scope_changed'): provider.prepare()
        for op in ('create', 'dns-update', 'control-create'):
            with self.assertRaisesRegex(TransportError, 'operation'): provider.dispatch(op, self.control)
        self.assertEqual([], self.calls)

    def test_lost_provider_delete_is_not_replayed_on_new_instance(self):
        provider = self.provider(); self.stopped = True
        def lose(*_): raise TimeoutError('synthetic-secret')
        provider.execute = lose
        with self.assertRaises(TimeoutError): provider.dispatch('control-delete', self.control)
        with self.assertRaisesRegex(TransportError, 'readback_required'):
            self.provider().dispatch('control-delete', self.control)
        self.provider().dispatch('control-inspect', self.control)
        self.assertEqual(['control-inspect'], [r['operation'] for r in self.calls])
        self.assertNotIn('synthetic-secret', json.dumps(self.saved))

    def test_no_secret_in_journal_and_empty_cleanup_needs_no_provider_access(self):
        provider = self.provider(); self.stopped = True
        self.cleanup['auxiliary'].update(control=None, records=[])
        self.saved['setup-control-pin'] = None
        self.assertEqual([], provider.prepare().scope['pins'])
        with self.assertRaisesRegex(TransportError, 'operator_pin'): provider.dispatch('control-delete', self.control)
        self.assertNotIn('synthetic-only', json.dumps(self.saved)); self.assertEqual([], self.calls)


if __name__ == '__main__': unittest.main()
