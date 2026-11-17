import copy
import json
from pathlib import Path
import shutil
import tempfile
import unittest

from member_auxiliary import digest
from member_cli import RUN
from member_control_transport import MemberControlTransport
from member_transport import TransportError


class ControlTransportTests(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory(); self.addCleanup(temporary.cleanup)
        self.root = Path(temporary.name).resolve()
        self.php = self.root / 'php'; self.php.write_bytes(b'synthetic-executable')
        self.scope = dict(run_id=RUN, bundle_sha256='a' * 64, started_at=100, deadline_at=7300)
        self.journal, self.calls = {}, []
        self.transport = MemberControlTransport(directory=self.root, scope=self.scope, php=self.php,
            guard=lambda *_: None, load=self.journal.get, save=lambda k, v: self.journal.__setitem__(k, copy.deepcopy(v)),
            execute=self.execute)
        entry = self.root / self.transport.entry.name; shutil.copyfile(self.transport.entry, entry); entry.chmod(0o600)
        self.transport.entry = entry
        self.request = dict(run_id=RUN, bundle_sha256='a' * 64, stage='control-create',
            predecessors_sha256=digest({}), request_id=digest([self.scope, 'control-create'])[:32])

    def execute(self, command, payload):
        self.calls.append(command)
        value = json.loads(payload)
        return json.dumps(dict(ok=True, request_sha256=digest(value), result=dict(request_sha256=digest(value['request']),
            resolved=True, dispatched=False, ready=False, pin=None))).encode()

    def test_fixed_local_entry_preserves_original_request_and_no_secret_arguments(self):
        result = self.transport.dispatch('control-create', self.request)
        self.assertFalse(result['dispatched'])
        self.assertEqual('member_operator_creation_entry.php', Path(self.calls[0][-2]).name)
        self.assertEqual(str(self.root), self.calls[0][-1])
        self.assertNotIn('ssh', self.calls[0])
        self.assertEqual(result, self.transport.dispatch('control-readback', self.request))

    def test_first_transport_error_is_preserved_and_readback_still_available(self):
        def fail(*_): raise TimeoutError('private-request-not-retained')
        self.transport.execute = fail
        with self.assertRaises(TimeoutError): self.transport.dispatch('control-create', self.request)
        first = copy.deepcopy(self.journal['operator-creation-first-error'])
        self.transport.execute = self.execute
        self.assertTrue(self.transport.dispatch('control-readback', self.request)['resolved'])
        self.assertEqual(first, self.journal['operator-creation-first-error'])
        self.assertNotIn('private-request-not-retained', json.dumps(self.journal))

    def test_changed_request_executable_or_secret_reply_are_denied(self):
        with self.assertRaises(TransportError): self.transport.dispatch('control-delete', self.request)
        with self.assertRaises(TransportError): self.transport.dispatch('control-create', dict(self.request, request_id='0' * 32))
        self.transport.dispatch('control-readback', self.request)
        self.php.write_bytes(b'changed')
        with self.assertRaisesRegex(TransportError, 'binding_changed'): self.transport.dispatch('control-readback', self.request)
        self.assertEqual(1, len(self.calls))


if __name__ == '__main__': unittest.main()
