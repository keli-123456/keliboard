"""Offline service ownership, bounded lifecycle and strict transport checks."""
import base64
import copy
import hashlib
import json
import os
from pathlib import Path
import tempfile
import time
import socket
import subprocess
import shutil
import unittest
from unittest.mock import patch

import member_http_service as http
from member_auxiliary import digest
from member_cli import RUN
from member_transport import StrictControllerTransport, TransportError
from test_member_host_installation import scope


def files():
    return {name: dict(base64=base64.b64encode(name.encode()).decode(), sha256=hashlib.sha256(name.encode()).hexdigest())
            for name in http.FILES}


def state(s, active=False, **changes):
    value = dict(LoadState='loaded' if active else 'not-found', ActiveState='active' if active else 'inactive',
        SubState='running' if active else 'dead', MainPID='123' if active else '0',
        ControlGroup='/system.slice/' + http.unit(s) if active else '',
        Description='keli-r35-http:' + digest(s) if active else http.unit(s), Transient='yes' if active else 'no')
    value.update(changes)
    return (0 if active else 1), ''.join(k + '=' + v + '\n' for k, v in value.items()).encode()


class HttpRequestTests(unittest.TestCase):
    def test_no_paths_commands_ports_or_root_identity_in_request(self):
        valid = dict(operation='start', scope=scope(), files=files())
        self.assertEqual('start', http.validate(valid)[0])
        for value in (dict(valid, port=80), dict(valid, command='echo'), dict(valid, path='/etc/ssl/key'),
                      dict(valid, scope=dict(valid['scope'], run_id='foreign')),
                      dict(valid, operation='read'), dict(valid, files={'key': files()['key']})):
            with self.assertRaises(TransportError): http.validate(value)

    def test_unit_readback_never_adopts_foreign_or_incomplete_state(self):
        s = scope()
        self.assertEqual('absent', http.status(s, lambda *_: state(s)))
        self.assertEqual('running', http.status(s, lambda *_: state(s, True)))
        for changed in (dict(Description='foreign'), dict(Transient='no'), dict(ControlGroup='/system.slice/ssh.service'),
                        dict(ActiveState='deactivating'), dict(MainPID='0')):
            with self.assertRaises(TransportError): http.status(s, lambda *_: state(s, True, **changed))
        with self.assertRaises(TransportError): http.status(s, lambda *_: (1, b''))
        with self.assertRaises(TransportError): http.status(s, lambda *_: (0, state(s)[1] + b'MainPID=0\n'))
        self.assertEqual('absent', http.status(s, lambda *_: (1, b'LoadState=not-found\nActiveState=inactive\nSubState=dead\n')))
        with self.assertRaises(TransportError):
            http.status(s, lambda *_: (1, b'LoadState=not-found\nActiveState=inactive\n'))

    def test_stop_requires_empty_cgroup_and_never_stops_foreign_unit(self):
        s = scope(); calls = []
        def invoke(args, *_):
            calls.append(args)
            if args[1] == 'stop': return 0, b''
            return state(s, len(calls) == 1)
        with patch.object(http, 'cgroup_empty', return_value=True): http.stop_unit(s, invoke)
        self.assertEqual(['systemctl', 'stop', http.unit(s)], calls[1])
        self.assertIn('--all', calls[0])
        with patch.object(http, 'cgroup_empty', return_value=False), self.assertRaises(TransportError):
            http.stop_unit(s, lambda *_: state(s))
        calls.clear()
        with self.assertRaises(TransportError):
            http.stop_unit(s, lambda args, *_: (calls.append(args) or state(s, True, Description='foreign')))
        self.assertEqual(1, len(calls))

    def test_stopping_an_owned_starting_unit_is_not_reported_as_ready(self):
        s = scope(); calls = []
        def invoke(args, *_):
            calls.append(args)
            if args[1] == 'stop': return 0, b''
            return state(s, True, ActiveState='activating', SubState='start') if len(calls) == 1 else state(s)
        with patch.object(http, 'cgroup_empty', return_value=True): http.stop_unit(s, invoke)
        self.assertEqual('stop', calls[1][1])


class HttpTransportTests(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory(); self.addCleanup(temporary.cleanup)
        root = Path(temporary.name)
        for name in ('key', 'known'): (root / name).write_bytes(b'synthetic')
        self.s = scope(); self.saved = {}; self.calls = []
        binding = dict(scope=self.s, host='192.0.2.20', identity_sha256=hashlib.sha256(b'synthetic').hexdigest(),
                       known_hosts_sha256=hashlib.sha256(b'synthetic').hexdigest())
        self.saved.update({'host-installation-binding': binding, 'application-binding': binding,
                           'application-initialize': dict(completed=True)})
        self.result = dict(state='running', unit=http.unit(self.s), private_files=[
            dict(run_id=RUN, path=http.ROOT + '/' + path, sha256=files()[name]['sha256']) for name, path in http.FILES.items()])
        def invoke(command, payload, timeout):
            self.calls.append((command, payload))
            return json.dumps(dict(ok=True, request_sha256=digest(json.loads(payload)), result=self.result)).encode()
        self.rpc = StrictControllerTransport(run_id=RUN, host=binding['host'], identity_file=root / 'key',
            known_hosts=root / 'known', guard=lambda *_: None, load=self.saved.get,
            save=lambda k, v: self.saved.__setitem__(k, copy.deepcopy(v)), execute=invoke)

    def test_fixed_endpoint_once_only_start_and_exact_pins(self):
        request = dict(operation='start', scope=self.s, files=files())
        self.rpc.http_call(request)
        self.assertEqual('python3 -B /opt/keli-isolated/runtime/member_http_service.py', self.calls[0][0][-1])
        for flag in ('BatchMode=yes', 'IdentitiesOnly=yes', 'StrictHostKeyChecking=yes', 'UpdateHostKeys=no'):
            self.assertIn(flag, self.calls[0][0])
        with self.assertRaises(TransportError): self.rpc.http_call(request)
        self.assertEqual(1, len(self.calls))
        self.result['private_files'][0]['path'] = '/etc/shadow'
        with self.assertRaises(TransportError): self.rpc.http_call(dict(operation='read', scope=self.s))

    def test_lost_start_or_stop_reply_cannot_restart_and_keeps_first_error(self):
        def failed(*_): raise TimeoutError('must-not-appear-secret')
        self.rpc.execute = failed
        with self.assertRaises(TransportError): self.rpc.http_call(dict(operation='start', scope=self.s, files=files()))
        first = copy.deepcopy(self.saved['transport-first-error'])
        with self.assertRaises(TransportError): self.rpc.http_call(dict(operation='stop', scope=self.s))
        with self.assertRaises(TransportError): self.rpc.http_call(dict(operation='start', scope=self.s, files=files()))
        self.assertEqual(first, self.saved['transport-first-error'])
        self.assertNotIn('must-not-appear-secret', json.dumps(self.saved))


@unittest.skipUnless(os.name == 'posix' and os.geteuid() == 0, 'root Linux lifecycle required')
class HttpLifecycleLinuxTests(unittest.TestCase):
    def setUp(self):
        t = tempfile.TemporaryDirectory(); self.addCleanup(t.cleanup)
        self.root = Path(t.name) / 'controller'; self.root.mkdir(mode=0o711)
        self.s = scope(); self.calls = []
        def invoke(args, *_):
            self.calls.append(args)
            return state(self.s) if args[:2] == ['systemctl', 'show'] else (0, b'')
        self.invoke = invoke

    def call(self, operation, **kwargs):
        return http.execute(dict(operation=operation, scope=self.s, **kwargs), root=self.root, invoke=self.invoke)

    def test_early_stop_is_durable_wrong_scope_does_not_poison_marker(self):
        with patch.object(http, 'cgroup_empty', return_value=True):
            self.assertEqual('stopped', self.call('stop')['state'])
            self.assertEqual('stopped', self.call('read')['state'])
            http.assert_stopped(self.root, self.invoke)
        original = (self.root / 'private/http-service/stop').read_bytes()
        self.s = dict(self.s, bundle_sha256='b' * 64)
        with self.assertRaises(TransportError): self.call('stop')
        self.assertEqual(original, (self.root / 'private/http-service/stop').read_bytes())
        with self.assertRaises(TransportError): self.call('start', files=files())

    def test_expiry_before_start_has_no_process_or_secret_side_effect(self):
        with self.assertRaisesRegex(TransportError, 'expired'):
            http.execute(dict(operation='start', scope=self.s, files=files()), root=self.root,
                invoke=self.invoke, clock=lambda: self.s['deadline_at'])
        self.assertEqual([], self.calls)
        self.assertTrue(all(not (self.root / p).exists() for p in http.FILES.values()))
        first = (self.root / 'private/http-service/first-error.json').read_bytes()
        with patch.object(http, 'cgroup_empty', return_value=False), self.assertRaises(TransportError): self.call('stop')
        self.assertEqual(first, (self.root / 'private/http-service/first-error.json').read_bytes())


@unittest.skipUnless(os.name == 'posix' and os.geteuid() == 0 and os.environ.get('KELI_HTTP_NETNS'),
                     'disposable Linux systemd and route-free namespace required')
class HttpSystemdLinuxTests(unittest.TestCase):
    """Real supervisor/nginx/PHP lifecycle with a synthetic denial-only router.

The separate full-application fixture verifies MySQL/Redis and the real kernel.
Only systemd's network namespace property is injected by this test adapter.
"""
    def setUp(self):
        import member_bundle
        self.root = Path(http.ROOT)
        self.assertFalse(os.path.lexists(self.root))
        self.opt = Path('/opt').stat()
        os.chown('/opt', 0, 0); os.chmod('/opt', 0o755)
        self.root.mkdir(mode=0o711)
        fixture_commit = os.environ['KELI_HTTP_FIXTURE_COMMIT']
        self.assertRegex(fixture_commit, r'^[a-f0-9]{40}$')
        (self.root / '.r35-application-fixture').write_text(fixture_commit)
        self.s = scope(hashlib.sha256(self.id().encode()).hexdigest())
        self.s['started_at'] = int(time.time()) - 1800
        self.s['deadline_at'] = int(time.time()) + 1250
        (self.root / 'private').mkdir(mode=0o700)
        runtime = self.root / 'runtime'; runtime.mkdir(mode=0o755)
        for name in member_bundle.RUNTIME:
            if name.endswith('.py'):
                target = runtime / name
                shutil.copyfile(Path(__file__).with_name(name), target); target.chmod(0o644)
        router = runtime / 'member_callback_entry.php'
        router.write_text('<?php if (posix_geteuid() === 0) { http_response_code(500); exit; } '
            'http_response_code(403); echo \'{"message":"isolated_callback_denied"}\';')
        router.chmod(0o644)
        app = dict(run_id=RUN, bundle_sha256=self.s['bundle_sha256'], uid=65534, gid=65534,
            machine_id_sha256=hashlib.sha256(Path('/etc/machine-id').read_bytes()).hexdigest())
        (self.root / 'application.json').write_text(json.dumps(app)); (self.root / 'application.json').chmod(0o644)
        manifest = {'files': {'runtime/' + p.name: dict(mode=0o644, sha256=hashlib.sha256(p.read_bytes()).hexdigest())
                             for p in runtime.iterdir()}}
        publication = self.root / 'private/runtime-publication.json'
        publication.write_text(json.dumps(dict(archive_sha256=self.s['bundle_sha256'], manifest=manifest)))
        publication.chmod(0o600)
        self.addCleanup(self.clean)
        cert, key = self.root / 'fixture-cert', self.root / 'fixture-key'
        subprocess.run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1',
            '-subj', '/CN=' + http.HOST, '-addext', 'subjectAltName=DNS:' + http.HOST,
            '-keyout', str(key), '-out', str(cert)], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        self.files = {n: dict(sha256=hashlib.sha256(p.read_bytes()).hexdigest(), base64=base64.b64encode(p.read_bytes()).decode())
                      for n, p in [('certificate', cert), ('key', key)]}

    def invoke(self, args, timeout=20):
        if args[0] == 'systemd-run':
            namespace = os.environ['KELI_HTTP_NETNS']
            self.assertRegex(namespace, r'^/proc/[1-9][0-9]*/ns/net$')
            args = [args[0], '--property=NetworkNamespacePath=' + namespace, *args[1:]]
        code, raw = http.command(args, timeout)
        if args[:2] == ['systemctl', 'show'] and b'LoadState=' not in raw:
            diagnostic = subprocess.run(args, capture_output=True, timeout=timeout, env=http.ENV)
            print(json.dumps(dict(systemd_probe_code=code, output_bytes=len(raw),
                no_manager=b'not been booted' in diagnostic.stderr,
                bus_failure=b'connect to bus' in diagnostic.stderr)))
        return code, raw

    def call(self, operation, **kwargs):
        return http.execute(dict(operation=operation, scope=self.s, **kwargs), invoke=self.invoke, smoke=lambda *_: None)

    def clean(self):
        try:
            self.call('stop')
            http.assert_stopped(self.root, self.invoke)
            self.closed()
            self.assertEqual(self.root.resolve(), self.root)
            shutil.rmtree(self.root)
        finally:
            os.chown('/opt', self.opt.st_uid, self.opt.st_gid); os.chmod('/opt', self.opt.st_mode & 0o7777)

    def closed(self):
        self.assertTrue(http.cgroup_empty(self.s))
        for port in (443, 7001):
            with self.assertRaises(ConnectionRefusedError):
                with socket.create_connection(('127.0.0.1', port), .2): pass

    def test_real_stop_denies_restart_and_closes_both_listeners(self):
        self.assertEqual('running', self.call('start', files=self.files)['state'])
        self.assertEqual('running', self.call('read')['state'])
        with self.assertRaises(Exception): http.assert_stopped(self.root, self.invoke)
        self.assertEqual('stopped', self.call('stop')['state'])
        self.closed()
        with self.assertRaises(TransportError): self.call('start', files=self.files)

    def test_real_supervisor_sigkill_reaps_php_and_nginx_cgroup(self):
        self.call('start', files=self.files)
        self.assertEqual(0, self.invoke(['systemctl', 'kill', '--kill-whom=main', '--signal=KILL', http.unit(self.s)])[0])
        limit = time.monotonic() + 15
        while not http.cgroup_empty(self.s) and time.monotonic() < limit: time.sleep(.2)
        self.closed()
        self.call('stop')

    def test_real_deadline_without_client_stop_reaps_all_children(self):
        self.s['deadline_at'] = int(time.time()) + 1220
        started = time.monotonic()
        self.call('start', files=self.files)
        while not http.cgroup_empty(self.s) and time.monotonic() - started < 32: time.sleep(.2)
        self.closed()
        self.assertGreaterEqual(time.monotonic() - started, 17)
        with self.assertRaises(TransportError): self.call('start', files=self.files)


if __name__ == '__main__':
    unittest.main()
