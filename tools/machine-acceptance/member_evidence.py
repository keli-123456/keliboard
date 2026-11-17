"""Read-only native evidence export with immutable host copies and local readback.

A verified copy is not a cleanup receipt or permission to delete the controller.
No credential, process launcher, cloud/DNS client or private-file removal lives here.
"""
import hashlib
import re

from driver_journal import DriverJournal, unique_json
from member_cli import BoundControllerTransport
from member_transport import require


class MemberEvidenceArchive:
    def __init__(self, transport, directory):
        require(isinstance(transport, BoundControllerTransport), 'evidence_bound_transport_required')
        self.transport = transport
        self.journal = DriverJournal(directory, transport.run_id)

    def _validate(self, value):
        require(isinstance(value, dict) and set(value) == {'schema', 'sha256', 'content'}
                and type(value['schema']) is int and value['schema'] == 1
                and isinstance(value['sha256'], str) and re.fullmatch('[a-f0-9]{64}', value['sha256'])
                and isinstance(value['content'], str), 'evidence_envelope_invalid')
        raw = value['content'].encode('utf-8')
        require(len(raw) <= 65536 and hashlib.sha256(raw).hexdigest() == value['sha256'],
                'evidence_digest_changed')
        body = unique_json(raw)
        expected = self.transport.identity_binding
        require(set(body) == {'schema', *expected, 'scope_sha256', 'stopped', 'reconciled',
                'reconcile_diagnostic', 'tasks', 'driver', 'setup', 'cleanup_complete_not_asserted'}
                and type(body['schema']) is int and body['schema'] == 1
                and all(type(body[k]) is type(v) and body[k] == v for k, v in expected.items())
                and body['stopped'] is True and body['cleanup_complete_not_asserted'] is True
                and type(body['reconciled']) is bool
                and isinstance(body['scope_sha256'], str) and re.fullmatch('[a-f0-9]{64}', body['scope_sha256'])
                and all(isinstance(body[k], dict) for k in ('tasks', 'driver', 'setup')),
                'evidence_binding_changed')
        return body

    def _store(self, name, value):
        try:
            old = self.journal.load(name)
        except FileNotFoundError:
            try:
                self.journal.save(name, value, exclusive=True)
            except FileExistsError:
                pass
            old = self.journal.load(name)
        require(old == value, 'evidence_local_copy_changed')

    def capture(self):
        value = self.transport.call('round-evidence')
        self._validate(value)
        name = 'evidence-' + value['sha256'] + '.json'
        self._store(name, value)
        # Read the on-disk bytes through the journal's bounded, no-link reader.
        self._validate(self.journal.load(name))
        pointer = {'run_id': self.transport.run_id, 'sha256': value['sha256'], 'verified': True}
        try:
            self.journal.load('evidence-first.json')
        except FileNotFoundError:
            self._store('evidence-first.json', pointer)
        first = self.journal.load('evidence-first.json')
        self.verify(first)
        self.journal.save('evidence-latest.json', pointer)
        require(self.journal.load('evidence-latest.json') == pointer, 'evidence_pointer_readback_failed')
        self.verify(pointer)
        return pointer

    def verify(self, receipt):
        require(isinstance(receipt, dict) and set(receipt) == {'run_id', 'sha256', 'verified'}
                and receipt['run_id'] == self.transport.run_id and receipt['verified'] is True
                and isinstance(receipt['sha256'], str) and re.fullmatch('[a-f0-9]{64}', receipt['sha256']),
                'evidence_receipt_invalid')
        value = self.journal.load('evidence-' + receipt['sha256'] + '.json')
        require(value.get('sha256') == receipt['sha256'], 'evidence_local_copy_changed')
        return self._validate(value)
