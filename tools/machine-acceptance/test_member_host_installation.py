"""No cloud, DNS or SSH. Linux fixtures publish synthetic pinned packages."""
import base64
import ast
import copy
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import time
import unittest
from unittest.mock import patch

import member_bundle
from member_auxiliary import digest
from member_cli import RUN
from member_environment import environment
from member_host_installation import execute, validate
from member_install_bootstrap import BOOTSTRAP
import member_runtime_bundle as runtime
from member_transport import StrictControllerTransport, TransportError
from test_member_environment import settings


def scope(sha='a' * 64):
    start = int(time.time())
    return dict(run_id=RUN, bundle_sha256=sha, started_at=start, deadline_at=start + 7199)


def private_files():
    return {name: dict(base64=base64.b64encode(raw).decode(), sha256=hashlib.sha256(raw).hexdigest())
            for name, raw in [('credentials', b'{"synthetic_only":true}'),
                              ('bootstrap-authority', b'{"not_live_authority":true}')]}


class HostRequestTests(unittest.TestCase):
    def test_exact_operations_paths_and_nonprivileged_service(self):
        valid = dict(operation='configure', scope=scope(), settings=settings(), service=dict(uid=65534, gid=65534))
        self.assertEqual(validate(valid)[0], 'configure')
        for changed in (dict(valid, path='/etc/shadow'), dict(valid, service=dict(uid=0, gid=0)),
                        dict(valid, scope=dict(valid['scope'], deadline_at=True)), dict(valid, operation='shell')):
            with self.assertRaises(TransportError): validate(changed)
        valid = dict(operation='private', scope=scope(), files=private_files())
        validate(valid)
        valid['files']['credentials']['base64'] += 'not-base64'
        with self.assertRaises(Exception): validate(valid)

    def test_cold_loader_is_self_contained_and_fixed_path(self):
        compile(BOOTSTRAP, 'isolated-bootstrap', 'exec')
        self.assertIn("incoming/r35-adapters.tar.gz", BOOTSTRAP)
        self.assertNotIn('subprocess', BOOTSTRAP)
        self.assertNotIn('extractall', BOOTSTRAP)


class HostTransportTests(unittest.TestCase):
    def setUp(self):
        temp = tempfile.TemporaryDirectory(); self.addCleanup(temp.cleanup)
        self.root = Path(temp.name)
        for name in ('key', 'known'): (self.root / name).write_bytes(b'synthetic')
        self.saved, self.calls = {}, []
        self.rpc = StrictControllerTransport(run_id=RUN, host='192.0.2.20', identity_file=self.root / 'key',
            known_hosts=self.root / 'known', guard=lambda *_: None, load=self.saved.get,
            save=lambda k, v: self.saved.__setitem__(k, copy.deepcopy(v)), execute=self.invoke)
        self.scope = scope()
        self.result = dict(completed_operations=['publish'], uncertain_operations=[], private_files=[], stopped=False)
        network = patch('socket.socket', side_effect=AssertionError('offline only'))
        network.start(); self.addCleanup(network.stop)

    def invoke(self, command, raw, timeout):
        self.calls.append((command, raw, timeout))
        return json.dumps(dict(ok=True, request_sha256=digest(json.loads(raw)), result=self.result)).encode()

    def test_fixed_bootstrap_strict_ssh_and_no_mutation_replay(self):
        request = dict(operation='publish', scope=self.scope)
        self.rpc.installation_call(request)
        self.assertTrue(self.calls[0][0][-1].startswith('python3 -I -B -c '))
        for option in ('BatchMode=yes', 'IdentitiesOnly=yes', 'StrictHostKeyChecking=yes', 'UpdateHostKeys=no'):
            self.assertIn(option, self.calls[0][0])
        with self.assertRaisesRegex(TransportError, 'readback_required'): self.rpc.installation_call(request)
        self.assertEqual(len(self.calls), 1)

    def test_uncertain_reply_preserves_first_error_and_stop_denies_mutations(self):
        def failed(*args): raise TimeoutError('synthetic-secret-do-not-store')
        self.rpc.execute = failed
        with self.assertRaisesRegex(TransportError, 'uncertain'):
            self.rpc.installation_call(dict(operation='publish', scope=self.scope))
        first = copy.deepcopy(self.saved['transport-first-error'])
        with self.assertRaisesRegex(TransportError, 'uncertain'):
            self.rpc.installation_call(dict(operation='stop', scope=self.scope))
        self.rpc.execute = self.invoke
        with self.assertRaisesRegex(TransportError, 'stopped'):
            self.rpc.installation_call(dict(operation='configure', scope=self.scope, settings=settings(), service=dict(uid=65534, gid=65534)))
        self.assertEqual(first, self.saved['transport-first-error'])
        self.assertNotIn('synthetic-secret', json.dumps(self.saved))

    def test_bad_order_duplicate_pins_and_changed_keys_fail_closed(self):
        for result in (dict(self.result, completed_operations=['private']),
                       dict(self.result, uncertain_operations=['configure', 'private']),
                       dict(self.result, private_files=[dict(run_id=RUN, path='/etc/shadow', sha256='a' * 64)])):
            self.result = result
            with self.assertRaises(TransportError):
                self.rpc.installation_call(dict(operation='journal-read', scope=self.scope))
        before = len(self.calls)
        (self.root / 'known').write_bytes(b'changed')
        with self.assertRaisesRegex(TransportError, 'binding_changed'):
            self.rpc.installation_call(dict(operation='journal-read', scope=self.scope))
        self.assertEqual(before, len(self.calls))


@unittest.skipUnless(os.name == 'posix' and os.geteuid() == 0, 'root Linux publication required')
class HostPublicationLinuxTests(unittest.TestCase):
    def setUp(self):
        from test_member_runtime_bundle import RuntimeBundleTests
        self.fixture = RuntimeBundleTests(); self.fixture.setUp(); self.addCleanup(self.fixture.doCleanups)
        args = self.fixture.runtime_fixture()
        application = self.fixture.output / 'host-application.tar.gz'
        member_bundle.freeze(self.fixture.repo, self.fixture.commit, application, RUN)
        args = (application, *args[1:])
        self.archive = args[-1]; self.frozen = runtime.assemble(*args)
        self.root = self.fixture.root / 'controller'; self.root.mkdir(mode=0o711)
        incoming = self.root / 'incoming'; incoming.mkdir(mode=0o700)
        shutil.copyfile(self.archive, incoming / 'r35-adapters.tar.gz')
        (incoming / 'r35-adapters.tar.gz').chmod(0o600)
        self.scope = scope(self.frozen['archive_sha256'])

    def call(self, operation, **kwargs):
        return execute(dict(operation=operation, scope=self.scope, **kwargs), root=self.root)

    def configure(self):
        return self.call('configure', settings=settings(), service=dict(uid=65534, gid=65534))

    def test_actual_publication_configuration_private_files_and_stop(self):
        self.assertEqual(['publish'], self.call('publish')['completed_operations'])
        self.assertEqual(['publish', 'configure'], self.configure()['completed_operations'])
        result = self.call('private', files=private_files())
        self.assertEqual(3, len(result['private_files']))
        self.assertEqual(['publish', 'configure', 'private'], result['completed_operations'])
        self.assertEqual([], result['uncertain_operations'])
        self.assertEqual(self.call('journal-read'), result)
        self.assertTrue(self.call('stop')['stopped'])
        with self.assertRaisesRegex(TransportError, 'stopped_or_expired'): self.call('publish')
        text = ''.join(p.read_text() for p in (self.root / 'private/host-installation').glob('*.json'))
        self.assertNotIn(settings()['password'], text)
        self.assertNotIn('synthetic_only', text)

    def test_crash_after_configuration_write_is_readonly_and_not_reinstalled(self):
        self.call('publish')
        from member_host_installation import configure
        def crash(*args, **kwargs):
            configure(*args, **kwargs)
            raise PermissionError('synthetic-private-error')
        with patch('member_host_installation.configure', side_effect=crash), self.assertRaises(PermissionError):
            self.configure()
        result = self.call('journal-read')
        self.assertEqual(['configure'], result['uncertain_operations'])
        self.assertEqual(1, len(result['private_files']))
        with self.assertRaisesRegex(TransportError, 'readback_required'): self.configure()
        self.assertTrue(self.call('stop')['stopped'])
        first = json.loads((self.root / 'private/host-installation/first-error.json').read_bytes())
        self.assertEqual('PermissionError', first['diagnostic']['error_class'])
        self.assertNotIn('synthetic-private-error', json.dumps(first))

    def test_private_partial_write_and_changed_hash_never_become_complete(self):
        self.call('publish'); self.configure()
        from member_installation import MemberInstallation
        original = MemberInstallation.private
        def fail_second(installer, name, *args):
            if name == 'bootstrap-authority': raise OSError('fixture')
            return original(installer, name, *args)
        with patch.object(MemberInstallation, 'private', fail_second), self.assertRaises(OSError):
            self.call('private', files=private_files())
        result = self.call('stop')
        self.assertEqual(['private'], result['uncertain_operations'])
        self.assertEqual(2, len(result['private_files']))
        (self.root / 'private/credentials').write_bytes(b'changed')
        with self.assertRaisesRegex(TransportError, 'private_changed'): self.call('journal-read')

    def test_expiry_stop_during_publication_and_changed_scope(self):
        with patch('member_host_installation.time.time', return_value=self.scope['deadline_at']):
            with self.assertRaisesRegex(TransportError, 'expired'):
                execute(dict(operation='publish', scope=self.scope), root=self.root,
                        clock=lambda: self.scope['deadline_at'])
        self.assertFalse((self.root / 'app').exists())
        changed = dict(self.scope, bundle_sha256='0' * 64)
        with self.assertRaisesRegex(TransportError, 'binding'):
            execute(dict(operation='stop', scope=changed), root=self.root)
        from member_installation import MemberInstallation
        original = MemberInstallation.publish_runtime
        def stop_during(installer, *args):
            result = original(installer, *args)
            (self.root / 'private/import-stopped').write_bytes((RUN + '\n').encode())
            (self.root / 'private/import-stopped').chmod(0o600)
            return result
        with patch.object(MemberInstallation, 'publish_runtime', stop_during), self.assertRaisesRegex(TransportError, 'expired'):
            self.call('publish')
        self.assertEqual(['publish'], self.call('journal-read')['uncertain_operations'])

    @unittest.skipUnless(os.environ.get('KELI_MEMBER_HOST_ENTRY') == '1', 'explicit cold installer fixture required')
    def test_real_cold_subprocess_publishes_package_and_private_files_without_php(self):
        fixed = Path('/opt/keli-isolated')
        self.assertFalse(os.path.lexists(fixed))
        parent = fixed.parent; previous = parent.stat()
        self.assertEqual(parent.resolve(), parent)
        os.chown(parent, 0, 0); parent.chmod(0o755)
        self.addCleanup(os.chmod, parent, previous.st_mode & 0o777)
        self.addCleanup(os.chown, parent, previous.st_uid, previous.st_gid)
        fixed.mkdir(mode=0o711)
        marker = fixed / '.r35-host-fixture'; marker.write_text(self.scope['bundle_sha256'])
        def cleanup():
            self.assertEqual(fixed.resolve(), fixed)
            self.assertEqual(marker.read_text(), self.scope['bundle_sha256'])
            shutil.rmtree(fixed)
        self.addCleanup(cleanup)
        # Only the synthetic fixture's module constants are changed. Actual
        # verifier and installer code runs in a fresh isolated Python process.
        tools = Path(__file__).resolve().parent
        for name in member_bundle.RUNTIME:
            target = self.fixture.repo / 'tools/machine-acceptance' / name
            raw = (tools / name).read_text()
            if name == 'member_runtime_bundle.py':
                tree = ast.parse(raw)
                for node in tree.body:
                    if isinstance(node, ast.Assign) and len(node.targets) == 1 and isinstance(node.targets[0], ast.Name):
                        key = node.targets[0].id
                        if key in ('NODE_ARCHIVE_SHA', 'NODE_SHA'): node.value = ast.Constant(getattr(runtime, key))
                raw = ast.unparse(tree)
            target.write_text(raw, newline='\n')
        application = self.fixture.output / 'cold-application.tar.gz'
        member_bundle.freeze(self.fixture.repo, self.fixture.commit, application, RUN)
        bundle = self.fixture.output / 'cold-runtime.tar.gz'
        frozen = runtime.assemble(application, self.fixture.root / 'vendor', self.fixture.root / 'node.tar.gz', bundle)
        incoming = fixed / 'incoming'; incoming.mkdir(mode=0o700)
        staged = incoming / 'r35-adapters.tar.gz'; shutil.copyfile(bundle, staged); staged.chmod(0o600)
        bound = scope(frozen['archive_sha256'])
        def cold(operation, **kwargs):
            request = dict(operation=operation, scope=bound, **kwargs)
            process = subprocess.run(['python3', '-I', '-B', '-c', BOOTSTRAP],
                input=json.dumps(request).encode(), capture_output=True, timeout=45,
                env=dict(PATH=os.environ['PATH'], HOME='/root', LANG='C.UTF-8'))
            return process, json.loads(process.stdout)
        for operation, kwargs in [('publish', {}), ('configure', dict(settings=settings(), service=dict(uid=65534, gid=65534))),
                                  ('private', dict(files=private_files()))]:
            process, reply = cold(operation, **kwargs)
            self.assertEqual(0, process.returncode, reply)
            self.assertIn(operation, reply['result']['completed_operations'])
            self.assertNotIn(settings()['password'], process.stdout.decode())
        process, reply = cold('stop')
        self.assertEqual(0, process.returncode, reply); self.assertTrue(reply['result']['stopped'])
        self.assertEqual(3, len(reply['result']['private_files']))
        process, reply = cold('configure', settings=settings(), service=dict(uid=65534, gid=65534))
        self.assertNotEqual(0, process.returncode)
        self.assertFalse(reply['ok'])
        staged.write_bytes(b'changed')
        process, reply = cold('journal-read')
        self.assertNotEqual(0, process.returncode); self.assertFalse(reply['ok'])
        self.assertEqual([], list(fixed.glob('.r35-installer-*')))


if __name__ == '__main__': unittest.main()
