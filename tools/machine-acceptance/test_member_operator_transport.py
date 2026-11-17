"""Operator-local exact cleanup transport with no cloud, SSH or DNS I/O."""
import copy
import json
from pathlib import Path
import tempfile
import unittest

from member_auxiliary import digest
from member_cli import RUN
from member_operator_transport import MemberOperatorProviderTransport
from member_transport import TransportError


class OperatorTransportTests(unittest.TestCase):
    def setUp(self):
        temp = tempfile.TemporaryDirectory(); self.addCleanup(temp.cleanup)
        self.root = Path(temp.name).resolve()
        self.php = self.root / 'php'; self.php.write_bytes(b'synthetic-php')
        self.saved, self.calls = {}, []
        self.pin = dict(run_id=RUN, external_id='800')
        self.scope = dict(run_id=RUN, bundle_sha256='a' * 64, pins=[self.pin], baseline_ids=[], dns_receipts=[])
        self.provider = MemberOperatorProviderTransport(directory=self.root, scope=self.scope, php=self.php,
            guard=lambda *_: None, load=self.saved.get, save=lambda k, v: self.saved.__setitem__(k, copy.deepcopy(v)),
            execute=self.execute)

    def execute(self, command, payload):
        self.calls.append(command)
        request = json.loads(payload)
        return json.dumps(dict(ok=True, request_sha256=digest(request),
            result=dict(pin_sha256=digest(request['pin']), status='absent'))).encode()

    def test_fixed_local_entry_no_ssh_or_credential_arguments(self):
        self.assertEqual('absent', self.provider.dispatch('control-inspect', self.pin)['status'])
        command = self.calls[0]
        self.assertEqual(str(self.php), command[0])
        self.assertEqual('member_operator_entry.php', Path(command[-2]).name)
        self.assertEqual(str(self.root), command[-1])
        self.assertNotIn('ssh', command)
        self.assertNotIn('credentials', ' '.join(command))

    def test_lost_reply_does_not_reissue_delete_and_first_error_is_safe(self):
        def timeout(*_): raise TimeoutError('synthetic-private-value')
        self.provider.execute = timeout
        with self.assertRaises(TimeoutError): self.provider.dispatch('control-delete', self.pin)
        first = copy.deepcopy(self.saved['operator-provider-first-error'])
        self.provider.execute = self.execute
        with self.assertRaisesRegex(TransportError, 'readback_required'): self.provider.dispatch('control-delete', self.pin)
        self.assertEqual('absent', self.provider.dispatch('control-inspect', self.pin)['status'])
        self.assertEqual(first, self.saved['operator-provider-first-error'])
        self.assertNotIn('synthetic-private-value', json.dumps(self.saved))

    def test_unknown_reply_changed_executable_scope_and_unlisted_operation_are_refused(self):
        self.provider.execute = lambda *_: b'{"ok":true,"result":{}}'
        with self.assertRaises(TransportError): self.provider.dispatch('control-inspect', self.pin)
        self.php.write_bytes(b'changed-php')
        with self.assertRaisesRegex(TransportError, 'binding_changed'): self.provider.dispatch('control-inspect', self.pin)
        for operation in ('create', 'control-create', 'dns-update'):
            with self.assertRaisesRegex(TransportError, 'operator_pin'): self.provider.dispatch(operation, self.pin)
        with self.assertRaisesRegex(TransportError, 'operator_pin'):
            self.provider.dispatch('control-delete', dict(self.pin, external_id='999'))


if __name__ == '__main__':
    unittest.main()
