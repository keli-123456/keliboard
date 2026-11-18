"""Real stage/strict-transport composition with synthetic SSH replies only."""
import base64
import copy
import hashlib
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

from member_auxiliary import digest
from member_cli import RUN
from member_environment import environment
from member_host_setup import MemberHostSetup
from member_http_service import ROOT, FILES, unit
from member_initialization import STAGES
from member_transport import StrictControllerTransport, TransportError
from test_member_environment import settings
from test_member_host_installation import scope
from test_member_http_service import files


class HostSetupTests(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory(); self.addCleanup(temporary.cleanup)
        self.root = Path(temporary.name)
        for name in ('key', 'known', 'bundle'): (self.root / name).write_bytes(('synthetic-' + name).encode())
        self.scope = scope(hashlib.sha256((self.root / 'bundle').read_bytes()).hexdigest())
        self.now = self.scope['started_at']
        self.saved, self.calls, self.guards = {}, [], []
        task = '11111111-1111-4111-8111-111111111111'; suffix = task.replace('-', '')
        self.saved['setup-control-pin'] = dict(run_id=RUN, external_id='1', task_id=task,
            label='keli-' + suffix, marker='keli-create-' + suffix, region='sg-sin-2', size='g6-standard-2',
            image='linode/debian12', provider_created_at='2026-11-18T01:00:00Z')
        self.saved['setup-control-request'] = dict(request_id='synthetic-control-request')
        self.completed, self.pins, self.http_pins = [], [], []
        self.app = dict(schema=1, run_id=RUN, bundle_sha256=self.scope['bundle_sha256'],
            environment_sha256=hashlib.sha256(environment(settings())).hexdigest(), machine_id_sha256='e' * 64,
            migrated=True, migration_count=160, migrations_sha256='f' * 64, tables=157)
        self.credentials = b'{"synthetic_credentials_only":"never-live"}'
        self.failure = None
        self.rpc = StrictControllerTransport(run_id=RUN, host='192.0.2.20', identity_file=self.root / 'key',
            known_hosts=self.root / 'known', guard=lambda *x: self.guards.append(x), load=self.saved.get,
            save=self.save, execute=self.execute)
        self.arguments = dict(scope=self.scope, transport=self.rpc, archive=self.root / 'bundle',
            settings=settings(), service=dict(uid=65534, gid=65534), credentials=self.credentials,
            import_permit=dict(scope=self.scope, credentials_sha256=hashlib.sha256(self.credentials).hexdigest(), authorized=True),
            tls_files=files(), load=self.saved.get, save=self.save, clock=lambda: self.now)
        self.port = MemberHostSetup(**self.arguments)
        self.receipts = {'control-create': dict(evidence_sha256=digest(self.saved['setup-control-pin']))}
        network = patch('socket.socket', side_effect=AssertionError('offline only'))
        network.start(); self.addCleanup(network.stop)

    def save(self, key, value):
        self.saved[key] = copy.deepcopy(value)

    def execute(self, command, payload, timeout):
        for flag in ('BatchMode=yes', 'IdentitiesOnly=yes', 'StrictHostKeyChecking=yes', 'UpdateHostKeys=no'):
            self.assertIn(flag, command)
        endpoint = command[-1]
        if endpoint.startswith('python3 -c '):
            operation = 'upload'
            result = dict(sha256=hashlib.sha256(payload).hexdigest(), bytes=len(payload), staged=True, executed=False)
        else:
            request = json.loads(payload)
            operation = request['operation']
            if endpoint.startswith('python3 -I -B -c '):
                operation = 'cold-' + operation
                name = request['operation']
                if name in ('publish', 'configure', 'private'):
                    self.completed.append(name)
                    material = {'app/.env': environment(request['settings'])} if name == 'configure' else (
                        {'private/' + n: base64.b64decode(v['base64']) for n, v in request['files'].items()} if name == 'private' else {})
                    self.pins.extend(dict(run_id=RUN, path=ROOT + '/' + n, sha256=hashlib.sha256(raw).hexdigest())
                                     for n, raw in material.items())
                    if name == 'private':
                        authority = json.loads(material['private/bootstrap-authority'])
                        self.assertEqual(self.app['machine_id_sha256'], authority['machine_id_sha256'])
                        self.assertEqual(self.scope['deadline_at'], authority['deadline_at'])
                result = dict(ok=True, request_sha256=digest(request), result=dict(completed_operations=list(self.completed),
                    uncertain_operations=[], private_files=self.pins, stopped=name == 'stop'))
            elif endpoint.endswith('member_application_entry.php'):
                operation = 'app-' + operation
                value = dict(ready=True, uid=65534, gid=65534, kernel='App\\Http\\Kernel', database_and_cache_reachable=True,
                             executable_paths_read_only=True) if request['operation'] == 'service-smoke' else self.app
                result = dict(request, ok=True, result=value)
            elif endpoint.endswith('member_import_entry.php'):
                operation = 'bootstrap-' + operation
                result = dict(ok=True, operation=request['operation'], scope=dict(run_id=RUN, synthetic=True))
            elif endpoint.endswith('member_http_service.py'):
                operation = 'http-' + operation
                if request['operation'] == 'start':
                    self.http_pins = [dict(run_id=RUN, path=ROOT + '/' + FILES[n], sha256=v['sha256'])
                                      for n, v in request['files'].items()]
                result = dict(ok=True, request_sha256=digest(request), result=dict(state='stopped' if request['operation'] == 'stop'
                    else 'running', unit=unit(self.scope), private_files=self.http_pins))
            else:
                raise AssertionError('unreviewed command')
        self.calls.append(operation)
        if self.failure == operation: raise TimeoutError('synthetic-secret-must-not-be-saved')
        return json.dumps(result).encode()

    def dispatch(self, stage):
        for name in STAGES[:STAGES.index(stage)]:
            self.receipts.setdefault(name, dict(evidence_sha256='0' * 64))
        request = dict(run_id=RUN, bundle_sha256=self.scope['bundle_sha256'], stage=stage,
            predecessors_sha256=digest(self.receipts), request_id=digest([self.scope, stage])[:32])
        result = self.port.dispatch(stage, request, self.receipts)
        self.receipts[stage] = result['receipt']
        return result

    def reconcile(self):
        request = dict(scope=self.scope, stopped=True)
        result = self.port.reconcile(request)
        self.assertEqual(dict(request_sha256=digest(request), resolved=True), result)
        return self.port.snapshot()

    def test_actual_fixed_interface_order_and_exact_private_cleanup_pins(self):
        for stage in self.port.STAGES: self.dispatch(stage)
        self.assertEqual(['upload', 'cold-publish', 'cold-configure', 'app-initialize', 'app-service-smoke',
            'app-readback', 'cold-private', 'bootstrap-import', 'http-start', 'http-read'], self.calls)
        snapshot = self.reconcile()
        self.assertEqual(['http-stop', 'cold-stop'], self.calls[-2:])
        self.assertEqual(5, len(snapshot['private_files']))
        self.assertEqual([], snapshot['requests'])
        before = list(self.calls)
        self.port = MemberHostSetup(**self.arguments)
        self.assertEqual(snapshot, self.reconcile())
        self.assertEqual(before, self.calls)
        for secret in ('synthetic-secret-must-not-be-saved', self.credentials.decode(), settings()['password'], settings()['redis_password']):
            self.assertNotIn(secret, json.dumps(self.saved))

    def test_every_suboperation_lost_reply_stops_without_reexecution(self):
        cases = {'upload': 'control-install', 'cold-publish': 'control-install', 'cold-configure': 'runtime-install',
            'app-initialize': 'runtime-install', 'app-service-smoke': 'runtime-install', 'app-readback': 'private-import',
            'cold-private': 'private-import', 'bootstrap-import': 'private-import', 'http-start': 'callback-ready',
            'http-read': 'callback-ready'}
        for failed, stage in cases.items():
            with self.subTest(operation=failed):
                self.setUp()
                self.failure = failed
                for previous in self.port.STAGES[:self.port.STAGES.index(stage)]: self.dispatch(previous)
                with self.assertRaises(TransportError): self.dispatch(stage)
                first = copy.deepcopy(self.saved['host-setup-first-error'])
                count = len(self.calls)
                self.port = MemberHostSetup(**self.arguments)
                with self.assertRaisesRegex(TransportError, 'readback_required'): self.dispatch(stage)
                self.assertEqual(count, len(self.calls))
                self.failure = None
                self.reconcile()
                with self.assertRaisesRegex(TransportError, 'stopped'): self.dispatch(stage)
                self.assertEqual(first, self.saved['host-setup-first-error'])
                self.assertEqual(1, self.calls.count(failed))

    def test_permission_clock_predecessor_and_material_drift_fail_before_dispatch(self):
        for permit in (None, dict(self.arguments['import_permit'], authorized=1),
                       dict(self.arguments['import_permit'], credentials_sha256='0' * 64)):
            with self.assertRaises(TransportError): MemberHostSetup(**dict(self.arguments, import_permit=permit))
        with self.assertRaisesRegex(TransportError, 'predecessor_missing'): self.dispatch('runtime-install')
        self.assertEqual([], self.calls)
        self.receipts = {'control-create': self.receipts['control-create']}
        self.now = self.scope['deadline_at'] - 1200
        with self.assertRaisesRegex(TransportError, 'expired_or_clock'): self.dispatch('control-install')
        self.assertEqual([], self.calls)

    def test_changed_binding_is_not_adopted_on_resume(self):
        self.dispatch('control-install')
        self.arguments['settings'] = dict(settings(), password='changed_' * 5)
        self.port = MemberHostSetup(**self.arguments)
        with self.assertRaisesRegex(TransportError, 'binding_changed'): self.dispatch('runtime-install')
        self.assertEqual(['upload', 'cold-publish'], self.calls)

    def test_stop_ack_loss_does_not_release_cleanup(self):
        for stage in self.port.STAGES: self.dispatch(stage)
        self.failure = 'http-stop'
        with self.assertRaises(TransportError): self.reconcile()
        with self.assertRaises(TransportError): self.port.snapshot()
        self.assertNotIn('cold-stop', self.calls)
        self.failure = None
        self.assertEqual(5, len(self.reconcile()['private_files']))

    def test_before_control_dispatch_and_untracked_remote_work(self):
        self.saved.clear()
        self.assertEqual(dict(private_files=[], requests=[]), self.reconcile())
        self.assertEqual([], self.calls)
        self.saved['http-binding'] = dict(untracked=True)
        with self.assertRaisesRegex(TransportError, 'control_unknown'): self.reconcile()


if __name__ == '__main__':
    unittest.main()
