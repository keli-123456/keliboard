"""Read-only native evidence export with immutable host copies and local readback.

A verified copy is not a cleanup receipt or permission to delete the controller.
No credential, process launcher, cloud/DNS client or private-file removal lives here.
"""
import hashlib
import re
import copy

from driver_journal import DriverJournal, unique_json
from member_cli import BoundControllerTransport
from member_transport import StrictControllerTransport, require
from member_auxiliary import digest


class MemberEvidenceArchive:
    prefix = 'evidence'

    def __init__(self, transport, directory):
        require(isinstance(transport, BoundControllerTransport), 'evidence_bound_transport_required')
        self.transport = transport
        self.journal = DriverJournal(directory, transport.run_id)

    @staticmethod
    def _envelope(value):
        require(isinstance(value, dict) and set(value) == {'schema', 'sha256', 'content'}
                and type(value['schema']) is int and value['schema'] == 1
                and isinstance(value['sha256'], str) and re.fullmatch('[a-f0-9]{64}', value['sha256'])
                and isinstance(value['content'], str), 'evidence_envelope_invalid')
        raw = value['content'].encode('utf-8')
        require(len(raw) <= 65536 and hashlib.sha256(raw).hexdigest() == value['sha256'],
                'evidence_digest_changed')
        body = unique_json(raw)
        require(isinstance(body, dict), 'evidence_binding_changed')
        return body

    def _validate(self, value):
        body = self._envelope(value)
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

    def _read(self):
        return self.transport.call('round-evidence')

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
        value = self._read()
        self._validate(value)
        name = self.prefix + '-' + value['sha256'] + '.json'
        self._store(name, value)
        # Read the on-disk bytes through the journal's bounded, no-link reader.
        self._validate(self.journal.load(name))
        pointer = {'run_id': self.transport.run_id, 'sha256': value['sha256'], 'verified': True}
        try:
            self.journal.load(self.prefix + '-first.json')
        except FileNotFoundError:
            self._store(self.prefix + '-first.json', pointer)
        first = self.journal.load(self.prefix + '-first.json')
        self.verify(first)
        self.journal.save(self.prefix + '-latest.json', pointer)
        require(self.journal.load(self.prefix + '-latest.json') == pointer, 'evidence_pointer_readback_failed')
        self.verify(pointer)
        return pointer

    def verify(self, receipt):
        require(isinstance(receipt, dict) and set(receipt) == {'run_id', 'sha256', 'verified'}
                and receipt['run_id'] == self.transport.run_id and receipt['verified'] is True
                and isinstance(receipt['sha256'], str) and re.fullmatch('[a-f0-9]{64}', receipt['sha256']),
                'evidence_receipt_invalid')
        value = self.journal.load(self.prefix + '-' + receipt['sha256'] + '.json')
        require(value.get('sha256') == receipt['sha256'], 'evidence_local_copy_changed')
        return self._validate(value)


class MemberSetupEvidenceArchive(MemberEvidenceArchive):
    """Stopped setup evidence before a native grant exists; never an absence proof."""
    prefix = 'setup-evidence'

    def __init__(self, transport, directory, *, scope, identity):
        require(isinstance(transport, StrictControllerTransport), 'evidence_setup_transport_required')
        require(isinstance(scope, dict) and set(scope) == {'run_id', 'bundle_sha256', 'started_at', 'deadline_at'}
                and scope['run_id'] == transport.run_id and self._hash(scope['bundle_sha256'])
                and all(type(scope[k]) is int for k in ('started_at', 'deadline_at'))
                and 0 < scope['started_at'] < scope['deadline_at'] <= scope['started_at'] + 7200,
                'evidence_setup_scope_invalid')
        require(isinstance(identity, dict) and set(identity) == {'grant_id', 'grant_sha256', 'deadline_at'}
                and self._hash(identity['grant_id'], 32) and self._hash(identity['grant_sha256'])
                and type(identity['deadline_at']) is int and identity['deadline_at'] == scope['deadline_at']
                and transport.load('remote-setup-identity') == identity, 'evidence_setup_identity_changed')
        self.transport, self.scope, self.identity = transport, copy.deepcopy(scope), copy.deepcopy(identity)
        self.configuration = copy.deepcopy(transport.load('bootstrap-configure'))
        require(isinstance(self.configuration, dict)
                and set(self.configuration) == {'request', 'completed', 'evidence_sha256'}
                and self.configuration['request'] == {'operation': 'configure'}
                and self.configuration['completed'] is True and self._hash(self.configuration['evidence_sha256']),
                'evidence_setup_configuration_missing')
        self.journal = DriverJournal(directory, transport.run_id)

    @staticmethod
    def _hash(value, length=64):
        return isinstance(value, str) and re.fullmatch('[a-f0-9]{%d}' % length, value) is not None

    @staticmethod
    def _diagnostic(value):
        return value is None or (isinstance(value, dict) and set(value) == {'class', 'file', 'line'}
            and isinstance(value['class'], str) and re.fullmatch('[A-Za-z]{1,40}', value['class']) is not None
            and ((value['file'] is None and value['line'] is None)
                 or (isinstance(value['file'], str) and re.fullmatch('member_[a-z_]{1,60}\\.php', value['file']) is not None
                     and type(value['line']) is int and 0 < value['line'] <= 1000000)))

    @classmethod
    def _uncertain(cls, value):
        return value is None or (isinstance(value, dict) and set(value) == {'sequence', 'request_id', 'completed'}
            and type(value['sequence']) is int and value['sequence'] >= 0
            and cls._hash(value['request_id'], 32) and value['completed'] is False)

    def _read(self):
        require(self.transport.load('remote-setup-stopped') == {'binding': digest(self.scope)}, 'evidence_setup_stop_required')
        require(self.transport.load('remote-setup-identity') == self.identity
                and self.transport.load('bootstrap-configure') == self.configuration, 'evidence_setup_binding_changed')
        value = self.transport.setup_call('evidence-export', self.identity)
        metadata = {'uncertain_request', 'uncertain_cleanup'}
        require(isinstance(value, dict) and set(value) == {'schema', 'sha256', 'content', *metadata}, 'evidence_setup_reply_invalid')
        envelope = {k: v for k, v in value.items() if k not in metadata}
        body = self._validate(envelope)
        require(all(value[k] == body[k] for k in metadata), 'evidence_setup_uncertainty_changed')
        return envelope

    def _validate(self, value):
        body = self._envelope(value)
        expected = dict(self.scope, **self.identity)
        require(set(body) == {'schema', 'kind', *expected, 'configuration_sha256', 'stopped', 'sources', 'dns',
                'first_error_present', 'first_diagnostic', 'uncertain_request', 'uncertain_cleanup', 'cleanup_complete_not_asserted'}
                and type(body['schema']) is int and body['schema'] == 1 and body['kind'] == 'member-setup-evidence'
                and all(type(body[k]) is type(v) and body[k] == v for k, v in expected.items())
                and body['configuration_sha256'] == self.configuration['evidence_sha256']
                and body['stopped'] is True and body['cleanup_complete_not_asserted'] is True
                and type(body['first_error_present']) is bool and self._diagnostic(body['first_diagnostic'])
                and body['first_error_present'] is (body['first_diagnostic'] is not None)
                and all(self._uncertain(body[k]) for k in ('uncertain_request', 'uncertain_cleanup')),
                'evidence_setup_binding_changed')
        sources, dns = body['sources'], body['dns']
        require(isinstance(sources, dict) and set(sources) == {'resolved', 'roles', 'cloud_absence_not_asserted'}
                and type(sources['resolved']) is bool and sources['cloud_absence_not_asserted'] is True
                and isinstance(sources['roles'], dict) and set(sources['roles']) == {'source-a', 'source-b'},
                'evidence_setup_sources_invalid')
        for role in sources['roles'].values():
            require(isinstance(role, dict) and set(role) == {'resolved', 'task_id', 'classification', 'resource', 'diagnostic'}
                    and type(role['resolved']) is bool and self._diagnostic(role['diagnostic']), 'evidence_setup_sources_invalid')
            kind, task, resource = role['classification'], role['task_id'], role['resource']
            require(kind in ('unresolved', 'no_queue_intent', 'not_dispatched', 'retained_create_receipt')
                    and role['resolved'] is (kind != 'unresolved')
                    and (role['diagnostic'] is not None) is (kind == 'unresolved')
                    and ((kind in ('unresolved', 'no_queue_intent') and task is None)
                         or (kind in ('not_dispatched', 'retained_create_receipt') and isinstance(task, str)
                             and re.fullmatch('[a-f0-9-]{36}', task)))
                    and ((kind != 'retained_create_receipt' and resource is None)
                         or (kind == 'retained_create_receipt' and isinstance(resource, dict)
                             and set(resource) == {'task_id', 'external_id'} and resource['task_id'] == task
                             and isinstance(resource['external_id'], str) and re.fullmatch('[1-9][0-9]{0,15}', resource['external_id']))),
                    'evidence_setup_sources_invalid')
        require(sources['resolved'] is all(r['resolved'] for r in sources['roles'].values()), 'evidence_setup_sources_invalid')
        require(isinstance(dns, dict) and set(dns) == {'resolved', 'retained_record_count', 'retained_records_sha256', 'diagnostic'}
                and type(dns['resolved']) is bool and self._diagnostic(dns['diagnostic'])
                and ((dns['resolved'] and type(dns['retained_record_count']) is int and 0 <= dns['retained_record_count'] <= 3
                      and self._hash(dns['retained_records_sha256']) and dns['diagnostic'] is None)
                     or (dns['resolved'] is False and dns['retained_record_count'] is None
                         and dns['retained_records_sha256'] is None and dns['diagnostic'] is not None)),
                'evidence_setup_dns_invalid')
        return body
