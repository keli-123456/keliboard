"""Explicit setup bindings for the R35 assembly; no default live transports."""
import copy
import re

from member_auxiliary import MemberAuxiliary, digest
from member_cli import RUN
from member_initialization import STAGES
from member_transport import require


class MemberExecutionSetup:
    """Bind reviewed installation and service APIs, retaining uncertain operations.

    Each port implements dispatch(stage, request, predecessors), reconcile(request)
    and snapshot(). Snapshots are exact durable cleanup receipts, not provider lists.
    Native grants must be revoked before any final resource snapshot is collected.
    """
    OWNERS = {'control-install': 'installation', 'runtime-install': 'installation',
              'private-import': 'installation', 'sources-initialize': 'sources',
              'dns-initialize': 'dns', 'native-grant': 'authority', 'native-ready': 'authority'}

    def __init__(self, *, scope, installation, sources, dns, authority, load, save, guard):
        self.scope = copy.deepcopy(scope)
        require(scope.get('run_id') == RUN, 'transport_execution_run_changed')
        self.ports = dict(installation=installation, sources=sources, dns=dns, authority=authority)
        self.load, self.save, self.guard = load, save, guard
        for port in self.ports.values():
            require(all(callable(getattr(port, m, None)) for m in ('dispatch', 'reconcile', 'snapshot')),
                    'transport_execution_interface_missing')
        for stage in self.OWNERS:
            setattr(self, stage.replace('-', '_'),
                    lambda request, receipts, stage=stage: self.dispatch(stage, request, receipts))

    def persist(self, key, value):
        self.save(key, copy.deepcopy(value))
        require(self.load(key) == value, 'transport_execution_journal_unconfirmed')

    def dispatch(self, stage, request, receipts):
        require(self.load('execution-stopped') is None, 'transport_execution_stopped')
        require(stage in self.OWNERS and request == dict(run_id=RUN,
                bundle_sha256=self.scope['bundle_sha256'], stage=stage,
                predecessors_sha256=digest(receipts), request_id=digest([self.scope, stage])[:32]),
                'transport_execution_request_changed')
        require(list(receipts) == list(STAGES[:STAGES.index(stage)]), 'transport_execution_predecessors_changed')
        key = 'execution-' + stage
        prior = self.load(key)
        require(prior is None or prior['request'] == request, 'transport_execution_intent_changed')
        if prior is not None:
            require(prior.get('reply') is not None, 'transport_execution_readback_required')
            if prior['reply'].get('completed') is True:
                return copy.deepcopy(prior['reply'])
            require(stage in ('sources-initialize', 'native-ready'), 'transport_execution_replay_refused')
        self.guard(stage)
        self.persist(key, {'request': request, 'reply': None})
        # Only the projected reply is journaled. Raw API logs or secret data never are.
        reply = self.ports[self.OWNERS[stage]].dispatch(stage, copy.deepcopy(request), copy.deepcopy(receipts))
        require(isinstance(reply, dict) and reply.get('request_sha256') == digest(request),
                'transport_execution_reply_changed')
        if reply.get('waiting') is True:
            require(stage in ('sources-initialize', 'native-ready')
                    and reply == {'request_sha256': digest(request), 'waiting': True},
                    'transport_execution_wait_invalid')
        else:
            require(set(reply) == {'request_sha256', 'completed', 'receipt'} and reply['completed'] is True,
                    'transport_execution_reply_invalid')
            receipt = reply['receipt']
            keys = {'run_id', 'stage', 'request_id', 'evidence_sha256'}
            if stage == 'native-grant': keys.add('grant_sha256')
            require(isinstance(receipt, dict) and set(receipt) == keys
                    and all(receipt.get(k) == request[k] for k in ('run_id', 'stage', 'request_id'))
                    and all(isinstance(receipt.get(k), str) and re.fullmatch(r'[a-f0-9]{64}', receipt[k])
                            for k in keys if k.endswith('sha256')), 'transport_execution_receipt_invalid')
        self.persist(key, {'request': request, 'reply': reply})
        return copy.deepcopy(reply)

    def reconcile(self, request):
        require(request.get('scope') == self.scope, 'cleanup_execution_scope_changed')
        self.persist('execution-stopped', {'binding': digest(self.scope)})
        self.guard('initialization-reconcile')
        # Stop/revoke authority first, including a grant whose creation reply was lost.
        for owner in ('authority', 'sources', 'dns', 'installation'):
            reply = self.ports[owner].reconcile(copy.deepcopy(request))
            require(reply == {'request_sha256': digest(request), 'resolved': True},
                    'cleanup_execution_unresolved')
        scope = self.collect_scope()
        prior = self.load('execution-cleanup-scope')
        require(prior is None or prior == scope, 'cleanup_execution_receipts_changed')
        self.persist('execution-cleanup-scope', scope)
        return {'request_sha256': digest(request), 'resolved': True}

    def collect_scope(self):
        pieces = {name: port.snapshot() for name, port in self.ports.items()}
        roles, auxiliary = {}, dict(run_id=RUN, control=None, records=[], faults=[], private_files=[], requests=[])
        allowed = {'installation': {'private_files', 'requests'}, 'sources': {'roles', 'faults', 'requests'},
                   'dns': {'records', 'requests'}, 'authority': {'private_files', 'requests'}}
        for owner, part in pieces.items():
            require(isinstance(part, dict) and set(part) == allowed[owner], 'cleanup_execution_snapshot_invalid')
            for key, values in part.items():
                if key == 'roles':
                    require(isinstance(values, dict) and set(values) <= {'source-a', 'source-b'},
                            'cleanup_execution_roles_invalid')
                    roles = copy.deepcopy(values)
                else:
                    require(isinstance(values, list), 'cleanup_execution_snapshot_invalid')
                    auxiliary[key].extend(copy.deepcopy(values))
        for key in ('records', 'faults', 'private_files', 'requests'):
            require(len({digest(v) for v in auxiliary[key]}) == len(auxiliary[key]),
                    'cleanup_execution_duplicate_pin')
        for pin in roles.values():
            require(isinstance(pin, dict) and set(pin) == {'task_id', 'external_id'}
                    and isinstance(pin['task_id'], str) and re.fullmatch(r'[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}', pin['task_id'])
                    and isinstance(pin['external_id'], str) and re.fullmatch(r'[1-9][0-9]{0,15}', pin['external_id']),
                    'cleanup_execution_role_pin_invalid')
        for kind, keys in [('private_files', {'run_id', 'path', 'sha256'}),
                           ('faults', {'run_id', 'role', 'external_id', 'rule_sha256'})]:
            require(all(isinstance(pin, dict) and set(pin) == keys for pin in auxiliary[kind]),
                    'cleanup_execution_pin_invalid')
        require(len(auxiliary['requests']) <= 64 and all(isinstance(r, str)
                and re.fullmatch(r'[a-zA-Z0-9_-]{1,64}', r) for r in auxiliary['requests']),
                'cleanup_execution_requests_invalid')
        MemberAuxiliary(scope=auxiliary, io=None, load=lambda _: None, save=lambda *_: None)
        return dict(run_id=RUN, roles=roles, auxiliary=auxiliary)

    def cleanup_scope(self, _result):
        scope = self.load('execution-cleanup-scope')
        require(self.load('execution-stopped') == {'binding': digest(self.scope)} and scope is not None,
                'cleanup_execution_not_reconciled')
        return copy.deepcopy(scope)
