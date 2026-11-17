"""Offline service ownership, bounded lifecycle and strict transport checks."""
import base64
import copy
import hashlib
import json
import os
from pathlib import Path
import tempfile
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

    def test_stop_requires_empty_cgroup_and_never_stops_foreign_unit(self):
        s = scope(); calls = []
        def invoke(args, *_):
            calls.append(args)
            if args[1] == 'stop': return 0, b''
            return state(s, len(calls) == 1)
        with patch.object(http, 'cgroup_empty', return_value=True): http.stop_unit(s, invoke)
        self.assertEqual(['systemctl', 'stop', http.unit(s)], calls[1])
        with patch.object(http, 'cgroup_empty', return_value=False), self.assertRaises(TransportError):
            http.stop_unit(s, lambda *_: state(s))
        calls.clear()
        with self.assertRaises(TransportError):
            http.stop_unit(s, lambda args, *_: (calls.append(args) or state(s, True, Description='foreign')))
        self.assertEqual(1, len(calls))


class HttpTransportTests(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory(); self.addCleanup(temporary.cleanup)
        root = Path(temporary.name)
        for name in ('key', 'known'): (root / name).write_bytes(b'synthetic')
        self.s = scope(); self.saved = {}; self.calls = []
        binding = dict(scope=self.s, host='192.0.2.20', identity_sha256=hashlib.sha256(b'synthetic').hexdigest(),
                       known_hosts_sha256=hashlib.sha256(b'synthetic').hexdigest())
        self.saved.update({'host-installation-binding': binding, 'application-initialize': dict(completed=True)})
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


if __name__ == '__main__':
    unittest.main()
