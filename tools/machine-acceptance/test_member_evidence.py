"""Stopped setup export, with no SSH, cloud, DNS or production credentials."""
import copy
import hashlib
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

from member_auxiliary import digest
from member_evidence import MemberSetupEvidenceArchive
from member_transport import StrictControllerTransport, TransportError


class SetupEvidenceTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(); self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name).resolve(strict=True)
        self.key, self.known = self.root / 'key', self.root / 'known'
        for path in (self.key, self.known): path.write_text('synthetic-fixture')
        self.scope = dict(run_id='keli-accept-offline-r35-fixture', started_at=1000, deadline_at=8200, bundle_sha256='a' * 64)
        self.identity = dict(grant_id='b' * 32, grant_sha256='c' * 64, deadline_at=8200)
        self.saved = {'remote-setup-identity': copy.deepcopy(self.identity),
            'bootstrap-configure': dict(request=dict(operation='configure'), completed=True, evidence_sha256='d' * 64)}
        self.calls = []
        self.transport = StrictControllerTransport(run_id=self.scope['run_id'], host='192.0.2.20',
            identity_file=self.key, known_hosts=self.known, guard=lambda *_: None, load=self.saved.get,
            save=lambda k, v: self.saved.__setitem__(k, copy.deepcopy(v)), execute=self.execute)
        self.body = dict(schema=1, kind='member-setup-evidence', **dict(self.scope, **self.identity),
            configuration_sha256='d' * 64, stopped=True, first_error_present=False, first_diagnostic=None,
            uncertain_request=None, uncertain_cleanup=None, cleanup_complete_not_asserted=True,
            sources=dict(resolved=True, cloud_absence_not_asserted=True, roles={r: dict(resolved=True,
                task_id=None, classification='no_queue_intent', resource=None, diagnostic=None) for r in ('source-a', 'source-b')}),
            dns=dict(resolved=True, retained_record_count=1, retained_records_sha256='e' * 64, diagnostic=None))
        self.reply_change = lambda reply: reply
        self.archive = self.make_archive()
        denial = patch('socket.socket', side_effect=AssertionError('offline only'))
        denial.start(); self.addCleanup(denial.stop)

    def make_archive(self):
        return MemberSetupEvidenceArchive(self.transport, self.root, scope=self.scope, identity=self.identity)

    def execute(self, command, payload, timeout):
        request = json.loads(payload); self.calls.append(request)
        self.assertEqual('evidence-export', request['operation'])
        self.assertEqual('php /opt/keli-isolated/runtime/member_setup_entry.php', command[-1])
        self.assertIn('StrictHostKeyChecking=yes', command)
        raw = json.dumps(self.body, sort_keys=True, separators=(',', ':'))
        reply = dict(schema=1, content=raw, sha256=hashlib.sha256(raw.encode()).hexdigest(),
            uncertain_request=self.body['uncertain_request'], uncertain_cleanup=self.body['uncertain_cleanup'])
        return json.dumps(dict(request, ok=True, result=self.reply_change(reply))).encode()

    def stop(self):
        self.saved['remote-setup-stopped'] = dict(binding=digest(self.scope))

    def test_requires_local_stop_and_pinned_configuration_before_ssh(self):
        with self.assertRaisesRegex(TransportError, 'stop_required'): self.archive.capture()
        self.assertEqual([], self.calls)
        self.stop()
        self.saved['bootstrap-configure']['evidence_sha256'] = '0' * 64
        with self.assertRaisesRegex(TransportError, 'binding_changed'): self.archive.capture()
        self.assertEqual([], self.calls)

    def test_preserves_first_snapshot_unknowns_and_immutable_local_readback(self):
        self.stop(); first = self.archive.capture()
        self.body['first_error_present'] = True
        self.body['first_diagnostic'] = {'class': 'RuntimeException', 'file': 'member_setup_controller.php', 'line': 123}
        uncertain = dict(sequence=1, request_id='f' * 32, completed=False)
        self.body['uncertain_request'] = uncertain
        self.body['uncertain_cleanup'] = dict(uncertain, sequence=2)
        second = self.make_archive().capture()
        self.assertNotEqual(first['sha256'], second['sha256'])
        self.assertFalse(self.archive.verify(first)['first_error_present'])
        self.assertEqual(uncertain, self.archive.verify(second)['uncertain_request'])
        self.assertEqual(first, self.archive.journal.load('setup-evidence-first.json'))
        self.assertEqual(second, self.archive.journal.load('setup-evidence-latest.json'))
        self.assertEqual(second, self.archive.capture())
        self.assertNotIn('setup-native-grant', self.saved)
        self.assertNotIn('setup-mutation', self.saved)
        self.assertFalse((self.root / 'evidence-first.json').exists())

    def test_refuses_binding_changes_payloads_and_false_cleanup_or_absence_claims(self):
        self.stop(); original = copy.deepcopy(self.body)
        changes = [('run_id', 'another'), ('grant_id', '0' * 32), ('bundle_sha256', '0' * 64),
            ('started_at', 2000), ('deadline_at', True), ('configuration_sha256', '0' * 64),
            ('stopped', False), ('cleanup_complete_not_asserted', False), ('schema', True),
            ('first_diagnostic', {'message': 'private'}), ('payload', 'private')]
        for field, value in changes:
            self.body = dict(copy.deepcopy(original), **{field: value})
            with self.subTest(field=field), self.assertRaises(TransportError): self.archive.capture()
        for section in ('sources', 'dns'):
            self.body = copy.deepcopy(original); self.body[section]['private_payload'] = 'secret'
            with self.assertRaises(TransportError): self.archive.capture()
        self.assertFalse((self.root / 'setup-evidence-latest.json').exists())

    def test_external_uncertainty_cannot_be_dropped_from_hashed_body(self):
        self.stop()
        self.reply_change = lambda reply: dict(reply, uncertain_request=dict(sequence=0, request_id='f' * 32, completed=False))
        with self.assertRaisesRegex(TransportError, 'uncertainty_changed'): self.archive.capture()
        self.assertFalse((self.root / 'setup-evidence-first.json').exists())

    def test_changed_digest_or_local_write_failure_never_reports_verified(self):
        self.stop()
        self.reply_change = lambda reply: dict(reply, sha256='0' * 64)
        with self.assertRaisesRegex(TransportError, 'digest_changed'): self.archive.capture()
        self.reply_change = lambda reply: reply
        with patch.object(self.archive.journal, 'save', side_effect=PermissionError('synthetic')):
            with self.assertRaises(PermissionError): self.archive.capture()
        self.assertFalse((self.root / 'setup-evidence-latest.json').exists())
        receipt = self.archive.capture()
        (self.root / ('setup-evidence-' + receipt['sha256'] + '.json')).write_text('{}')
        with self.assertRaisesRegex(TransportError, 'local_copy_changed'): self.archive.verify(receipt)

    def test_source_unknown_remains_explicit_without_a_native_grant(self):
        self.stop()
        diagnostic = {'class': 'RuntimeException', 'file': 'member_source_initialization.php', 'line': 42}
        self.body['sources']['resolved'] = False
        self.body['sources']['roles']['source-a'].update(resolved=False, classification='unresolved', diagnostic=diagnostic)
        self.body['dns'].update(resolved=False, retained_record_count=None, retained_records_sha256=None, diagnostic=diagnostic)
        receipt = self.archive.capture()
        body = self.archive.verify(receipt)
        self.assertFalse(body['sources']['resolved']); self.assertFalse(body['dns']['resolved'])
        self.assertTrue(body['sources']['cloud_absence_not_asserted'])
        self.assertTrue(body['cleanup_complete_not_asserted'])


if __name__ == '__main__':
    unittest.main()
