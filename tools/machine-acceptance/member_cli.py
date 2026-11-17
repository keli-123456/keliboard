"""Bind a prepared R35 controller to the host round, without issuing a grant.

Cloud setup, credential transfer and auxiliary cleanup remain separately pinned
preflight adapters. This module has no cloud-create, DNS or bootstrap command.
"""
import copy
import hashlib
import json
import time
import uuid
from pathlib import Path

from driver_journal import unique_json
from member_transport import (StrictControllerTransport, MemberCleanup, TransportError,
                              require, ROLES, OPERATIONS, REMOTE_ENTRY, LIMIT)

RUN = 'keli-accept-20261116-r35-f4940b41'
ROUND_OPS = ('round-ready', 'round-step', 'round-status', 'round-stop', 'round-dns-cleanup', 'round-evidence')
RECOVERY = ('round-stop', 'round-status', 'round-dns-cleanup', 'round-evidence', 'task-inspect', 'task-delete')


class BoundControllerTransport(StrictControllerTransport):
    def __init__(self, *, grant_file, grant_sha256, clock=time.time, **kwargs):
        super().__init__(**kwargs)
        self.grant_file = Path(grant_file).absolute()
        self.grant_sha256, self.clock = grant_sha256, clock
        self.grant = self._read_grant()
        g = self.grant
        require(g.get('schema') == 2 and g.get('execution_authorized') is True
                and g.get('isolated') is True and self.run_id == g.get('run_id') == RUN,
                'transport_grant_required')
        import re
        for key, size in [('grant_id', 32), ('bundle_sha256', 64), ('controller_machine_id_sha256', 64)]:
            require(isinstance(g.get(key), str) and re.fullmatch('[a-f0-9]{%d}' % size, g[key]),
                    'transport_grant_identity')
        require(type(g.get('started_at')) is int and type(g.get('deadline_at')) is int
                and g['started_at'] > 0 and 1200 < g['deadline_at'] - g['started_at'] <= 7200,
                'transport_grant_window')
        self.identity_binding = dict(run_id=RUN, grant_id=g['grant_id'], grant_sha256=grant_sha256,
                                     bundle_sha256=g['bundle_sha256'])
        self.setup_binding = None
        host_keys = {'controller_ipv4', 'identity_sha256', 'known_hosts_sha256'}
        if host_keys & set(g):
            require(host_keys <= set(g) and g['controller_ipv4'] == self.host, 'transport_controller_changed')
            self.ssh_binding = {key: g[key] for key in host_keys}
        else:
            # Preserve the remote grant byte-for-byte. SSH pins belong to the
            # host's prior bootstrap/setup receipts, not a rewritten authority.
            self.setup_binding = self._setup_binding()
            bootstrap = self.setup_binding['bootstrap-binding']
            self.ssh_binding = dict(controller_ipv4=bootstrap['host'],
                identity_sha256=bootstrap['identity_sha256'], known_hosts_sha256=bootstrap['known_hosts_sha256'])

    def _setup_binding(self):
        import re
        receipts = {key: copy.deepcopy(self.load(key)) for key in
                    ('bootstrap-binding', 'setup-binding', 'setup-native-grant')}
        bootstrap, setup, native = (receipts[key] for key in receipts)
        require(isinstance(bootstrap, dict) and set(bootstrap) ==
                {'run_id', 'host', 'identity_sha256', 'known_hosts_sha256'}
                and bootstrap['run_id'] == self.run_id and bootstrap['host'] == self.host
                and all(isinstance(bootstrap[key], str) and re.fullmatch('[a-f0-9]{64}', bootstrap[key])
                        for key in ('identity_sha256', 'known_hosts_sha256')), 'transport_setup_binding_required')
        require(isinstance(setup, dict) and set(setup) ==
                {'run_id', 'host', 'grant_id', 'grant_sha256', 'deadline_at'}
                and setup['run_id'] == self.run_id and setup['host'] == self.host
                and type(setup['deadline_at']) is int and setup['deadline_at'] == self.grant['deadline_at']
                and all(isinstance(setup[key], str) and re.fullmatch('[a-f0-9]{%d}' % size, setup[key])
                        for key, size in [('grant_id', 32), ('grant_sha256', 64)]), 'transport_setup_binding_required')
        require(isinstance(native, dict) and set(native) == {'ready', 'identity', 'deadline_at', 'cloud_or_dns_writes'}
                and native['ready'] is True and native['cloud_or_dns_writes'] is False
                and type(native['deadline_at']) is int and native['deadline_at'] == self.grant['deadline_at']
                and native['identity'] == self.identity_binding, 'transport_native_grant_changed')
        return receipts

    def _read_grant(self):
        from staging import read_file
        _, raw, _ = read_file(self.grant_file, 65536, collect=True)
        require(hashlib.sha256(raw).hexdigest() == self.grant_sha256, 'transport_grant_changed')
        value = unique_json(raw)
        require(isinstance(value, dict), 'transport_grant_required')
        return value

    def call(self, operation, role='control', request_id=None):
        require(operation in ROUND_OPS + OPERATIONS and role in ('control',) + ROLES,
                'transport_operation_invalid')
        require((role == 'control') == (operation in ROUND_OPS)
                and (not operation.startswith('source-') or role.startswith('source-')), 'transport_role_invalid')
        require(self._read_grant() == self.grant, 'transport_grant_changed')
        if self.setup_binding is not None:
            require(self._setup_binding() == self.setup_binding, 'transport_setup_binding_changed')
        now = int(self.clock())
        previous = self.load('bound-clock')
        require(now >= self.grant['started_at'] and (previous is None or now >= previous['at']),
                'transport_clock_rollback')
        self.save('bound-clock', {'at': now})
        if operation not in RECOVERY:
            require(now < self.grant['deadline_at'] - 1200 and self.load('bound-stop') is None,
                    'transport_authority_expired_or_stopped')
            require(self.load('bound-uncertain') is None, 'transport_mutation_reconcile_required')
            active = self.load('bound-active')
            require(active is None or active.get('completed') is True, 'transport_mutation_reconcile_required')
        if operation in ('task-delete', 'round-dns-cleanup', 'round-evidence'):
            require(self.load('bound-stop') is not None, 'transport_cleanup_requires_stop')
        self.guard(operation, role)
        for path, key in [(self.identity, 'identity_sha256'), (self.known, 'known_hosts_sha256')]:
            from staging import read_file
            _, raw, _ = read_file(path, 1048576, collect=True)
            require(hashlib.sha256(raw).hexdigest() == self.ssh_binding.get(key), 'transport_ssh_material_changed')
        if operation == 'round-stop':
            self.save('bound-stop', {'stopped': True})
        mutation = operation in ('round-step', 'source-register', 'task-delete', 'round-dns-cleanup')
        request_id = request_id or uuid.uuid4().hex
        import re
        require(re.fullmatch('[a-f0-9]{32}', request_id), 'transport_request_invalid')
        key = 'bound-request-' + request_id
        require(self.load(key) is None, 'transport_mutation_reconcile_required')
        if operation in ('source-register', 'task-delete'):
            once = 'bound-once-' + operation + '-' + role
            require(self.load(once) is None, 'transport_mutation_reconcile_required')
            self.save(once, {'request_id': request_id})
        sequence = (self.load('bound-sequence') or {'value': 0})['value'] + 1
        self.save('bound-sequence', {'value': sequence})
        request = dict(self.identity_binding, schema=2, request_id=request_id, sequence=sequence,
                       operation=operation, role=role)
        self.save(key, {'request': request})
        if mutation and operation not in ('task-delete', 'round-dns-cleanup'):
            self.save('bound-active', {'request_id': request_id, 'completed': False})
        try:
            raw = self.execute(self._command('php ' + REMOTE_ENTRY),
                               json.dumps(request, separators=(',', ':')).encode(), 45)
            require(isinstance(raw, bytes) and len(raw) <= LIMIT, 'transport_response_too_large')
            reply = unique_json(raw)
            require(isinstance(reply, dict) and all(type(reply.get(k)) is type(v) and reply[k] == v
                    for k, v in request.items()), 'transport_response_binding_changed')
            require(reply.get('ok') is True and isinstance(reply.get('result'), dict), 'transport_remote_rejected')
            self.save(key, {'request': request, 'reply': reply})
            if mutation and operation not in ('task-delete', 'round-dns-cleanup'):
                self.save('bound-active', {'request_id': request_id, 'completed': True})
            return reply['result']
        except Exception as error:
            if mutation:
                self.save('bound-uncertain', {'request_id': request_id, 'operation': operation, 'role': role})
            self._first_error(operation, role, error)
            if isinstance(error, TransportError):
                raise
            raise TransportError('transport_invalid_response') from None

    def upload(self, *_args, **_kwargs):
        raise TransportError('transport_bound_bootstrap_not_allowed')


class MemberControllerPort:
    """Actual host/CLI composition. Inject execute only at SSH's byte boundary."""
    def __init__(self, transport):
        require(isinstance(transport, BoundControllerTransport), 'transport_bound_controller_required')
        self.transport = transport

    def __call__(self, operation, request):
        require(request.get('run_id') == self.transport.run_id, 'transport_round_binding_changed')
        if operation in ('bootstrap', 'native-step'):
            result = self.transport.call('round-ready' if operation == 'bootstrap' else 'round-step',
                                         request_id=request['request_id'])
            return dict(result, request=copy.deepcopy(request))
        require(operation == 'reconcile', 'transport_operation_invalid')
        self.transport.call('round-stop')
        result = self.transport.call('round-status')
        require(result.get('run_id') == self.transport.run_id and result.get('resolved') is True
                and result.get('stopped') is True and isinstance(result.get('roles'), dict)
                and {'source-a', 'source-b'} <= set(result['roles']), 'transport_round_reconciliation_required')
        return dict(run_id=self.transport.run_id, resolved=True,
                    cleanup_scope={'run_id': self.transport.run_id, 'roles': result['roles']})

    def native_auxiliary(self, auxiliary):
        """Use authenticated post-cutover DNS receipts before private teardown.

        The delegate must not also delete the initialization DNS pins. Its other
        responsibilities (faults, evidence, private material, control) are unchanged.
        """
        key = 'native-dns-cleanup-confirmed'
        expected = dict(identity=self.transport.identity_binding, complete=True,
                        records=['control', 'edge-a', 'edge-b'])

        def call(operation):
            previous = self.transport.load(key)
            require(previous is None or (isinstance(previous, dict) and previous.get('complete') is True
                    and previous == expected), 'cleanup_native_dns_receipt_changed')
            if operation == 'dns-and-private-cleanup' and previous is None:
                result = self.transport.call('round-dns-cleanup')
                require(isinstance(result, dict) and set(result) == {'complete', 'records'}
                        and type(result['complete']) is bool and isinstance(result['records'], dict)
                        and set(result['records']) == set(expected['records']), 'cleanup_native_dns_unconfirmed')
                records = list(result['records'].values())
                require(all(isinstance(r, dict) and type(r.get('complete')) is bool for r in records)
                        and result['complete'] is all(r['complete'] for r in records),
                        'cleanup_native_dns_unconfirmed')
                if not result['complete']:
                    return {'pending': True}
                require(all(r == {'complete': True} for r in records), 'cleanup_native_dns_unconfirmed')
                self.transport.save(key, copy.deepcopy(expected))
                require(self.transport.load(key) == expected, 'cleanup_native_dns_readback_failed')
            if operation == 'control-delete-or-inspect':
                require(previous == expected, 'cleanup_native_dns_unconfirmed')
            return auxiliary(operation)

        return call

    def cleanup_factory(self, auxiliary, save, clock=time.time, *, native_dns=False):
        if native_dns:
            auxiliary = self.native_auxiliary(auxiliary)
        def build(scope, restored):
            require(scope.get('run_id') == self.transport.run_id, 'transport_round_binding_changed')
            return MemberCleanup(run_id=scope['run_id'], roles=scope['roles'], call=self.transport.call,
                                 auxiliary=auxiliary, save=save, clock=clock, restored=restored)
        return build
