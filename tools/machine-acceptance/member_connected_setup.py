"""Bind real setup ports only after the created control identity is known.

This composition does not acquire host keys, bootstrap OS packages or issue TLS
certificates. Those reviewed inputs remain explicit. Recovery reuses the sealed
endpoint, never another provider discovery or new creation permission.
"""
import copy
import hashlib
from pathlib import Path
import time

from member_auxiliary import digest
from member_cli import RUN
from member_control_transport import MemberControlTransport
from member_execution import MemberExecutionSetup
from member_host_installation import validate
from member_host_setup import MemberHostSetup
from member_http_service import validate as validate_http
from member_initialization import STAGES
from member_remote_setup import MemberRemoteSetup
from member_transport import StrictControllerTransport, require
from staging import read_file


class MemberConnectedSetup:
    REMOTE_MARKERS = tuple(dict.fromkeys((*MemberRemoteSetup.DISPATCH_KEYS,
        'upload-intent', 'host-setup-binding', 'host-installation-binding',
        'application-binding', 'http-binding', 'connected-setup-dispatched',
        *('execution-' + stage for stage in STAGES[1:]))))

    def __init__(self, *, scope, control, archive, identity_file, known_hosts, grant_file,
                 settings, service, credentials, import_permit, tls_files, load, save,
                 guard, ssh_guard, execute=None, clock=time.time):
        validate(dict(operation='configure', scope=scope, settings=settings, service=service))
        validate_http(dict(operation='start', scope=scope, files=tls_files))
        require(isinstance(control, MemberControlTransport)
                and all(control.scope.get(k) == scope[k] for k in scope), 'transport_connected_control_scope')
        require(isinstance(credentials, bytes) and 0 < len(credentials) <= 65536,
                'transport_connected_credentials')
        expected = dict(scope=scope, credentials_sha256=hashlib.sha256(credentials).hexdigest(), authorized=True)
        require(isinstance(import_permit, dict) and import_permit == expected
                and import_permit['authorized'] is True, 'transport_connected_import_permission')
        self.scope, self.control = copy.deepcopy(scope), control
        self.archive = Path(archive).absolute()
        self.identity, self.known, self.grant = [Path(p).absolute() for p in (identity_file, known_hosts, grant_file)]
        self.parameters = dict(scope=copy.deepcopy(scope), archive=self.archive, settings=copy.deepcopy(settings),
            service=copy.deepcopy(service), credentials=credentials, import_permit=copy.deepcopy(import_permit),
            tls_files=copy.deepcopy(tls_files), load=load, save=save, clock=clock)
        self.load, self.save, self.guard, self.ssh_guard = load, save, guard, ssh_guard
        self.execute, self.clock = execute, clock
        self.rpc = self.host = self.remote = self.execution = None
        for stage in STAGES[1:]:
            setattr(self, stage.replace('-', '_'),
                lambda request, receipts, stage=stage: self.dispatch(stage, request, receipts))

    def persist(self, key, value):
        self.save(key, copy.deepcopy(value))
        require(self.load(key) == value, 'transport_connected_journal')

    def material(self):
        pin = self.load('setup-control-pin')
        require(isinstance(pin, dict) and pin.get('run_id') == RUN, 'transport_connected_control_missing')
        p = self.parameters
        return dict(scope=self.scope, control_sha256=digest(pin), archive=str(self.archive),
            identity=str(self.identity), known_hosts=str(self.known), grant_file=str(self.grant),
            identity_sha256=read_file(self.identity, 1048576)[0]['sha256'],
            known_hosts_sha256=read_file(self.known, 1048576)[0]['sha256'],
            settings_sha256=digest(p['settings']), service=p['service'],
            credentials_sha256=hashlib.sha256(p['credentials']).hexdigest(),
            import_permit_sha256=digest(p['import_permit']), tls_sha256=digest(p['tls_files']))

    def active(self):
        require(self.load('connected-setup-stopped') is None, 'transport_connected_stopped')
        now = int(self.clock())
        require(self.scope['started_at'] <= now < self.scope['deadline_at'] - 1200
                and now >= (self.load('connected-setup-clock') or {}).get('at', self.scope['started_at']),
                'transport_connected_expired_or_clock')
        self.persist('connected-setup-clock', dict(at=now))

    def components(self, *, recover=False):
        if recover:
            require(self.load('connected-setup-stopped') == dict(binding=digest(self.scope)),
                    'cleanup_connected_not_stopped')
        else:
            self.active()
        material = self.material()
        bound = self.load('connected-setup-binding')
        if bound is None:
            require(not recover, 'cleanup_connected_binding_missing')
            require(all(self.load(k) is None for k in self.REMOTE_MARKERS), 'transport_connected_untracked_effect')
            request = self.load('setup-control-request')
            require(isinstance(request, dict), 'transport_connected_request_missing')
            rpc = self.control.strict_transport(request, identity_file=self.identity, known_hosts=self.known,
                guard=self.ssh_guard, execute=self.execute)
            self.active()
            require(self.material() == material, 'transport_connected_material_changed')
            connection = self.load('operator-control-connection')
            require(connection == dict(control_sha256=material['control_sha256'], host=rpc.host, port=22),
                    'transport_connected_endpoint_changed')
            bound = dict(material=material, connection=connection)
            self.persist('connected-setup-binding', bound)
        else:
            require(isinstance(bound, dict) and set(bound) == {'material', 'connection'}
                    and bound['material'] == material, 'transport_connected_material_changed')
            connection = bound['connection']
            require(connection == self.load('operator-control-connection')
                    and connection.get('control_sha256') == material['control_sha256']
                    and connection.get('port') == 22, 'transport_connected_endpoint_changed')
            rpc = StrictControllerTransport(run_id=RUN, host=connection['host'], identity_file=self.identity,
                known_hosts=self.known, guard=self.ssh_guard, load=self.load, save=self.save, execute=self.execute)
        # Constructing these ports is side-effect free; bind before any delegation.
        self.rpc = rpc
        self.host = MemberHostSetup(transport=rpc, **self.parameters)
        self.remote = MemberRemoteSetup(scope=self.scope, transport=rpc, grant_file=self.grant,
            load=self.load, save=self.save)
        self.execution = MemberExecutionSetup(scope=self.scope, installation=self.host,
            sources=self.remote.port('sources'), dns=self.remote.port('dns'), authority=self.remote.port('authority'),
            load=self.load, save=self.save, guard=self.guard)
        return self.execution

    def dispatch(self, stage, request, receipts):
        require(stage in STAGES[1:] and request == dict(run_id=RUN, bundle_sha256=self.scope['bundle_sha256'],
            stage=stage, predecessors_sha256=digest(receipts), request_id=digest([self.scope, stage])[:32])
            and list(receipts) == list(STAGES[:STAGES.index(stage)]), 'transport_connected_stage')
        self.guard(stage)
        execution = self.components()
        self.persist('connected-setup-dispatched', dict(binding=digest(self.load('connected-setup-binding'))))
        return execution.dispatch(stage, request, receipts)

    def reconcile(self, request):
        require(request.get('scope') == self.scope, 'cleanup_connected_scope')
        seal = dict(binding=digest(self.scope))
        require(self.load('connected-setup-stopped') in (None, seal), 'cleanup_connected_scope')
        self.persist('connected-setup-stopped', seal)
        self.guard('initialization-reconcile')
        prior = self.load('connected-setup-cleanup')
        if prior is not None and self.load('connected-setup-binding') is not None:
            # Remote stop and exact resource snapshots were already confirmed.
            # Controller-last deletion must not make replay depend on more SSH.
            execution = self.components(recover=True)
            require(execution.cleanup_scope(None) == prior, 'cleanup_connected_receipts_changed')
            return dict(request_sha256=digest(request), resolved=True)
        if self.load('connected-setup-binding') is None:
            require(all(self.load(k) is None for k in self.REMOTE_MARKERS)
                    and not self.grant.exists() and not self.grant.is_symlink(), 'cleanup_connected_untracked_effect')
            scope = MemberExecutionSetup.scope_from_snapshots(dict(
                installation=dict(private_files=[], requests=[]), sources=dict(roles={}, faults=[], requests=[]),
                dns=dict(records=[], requests=[]), authority=dict(private_files=[], requests=[])))
            self.persist('connected-setup-never-dispatched', seal)
        else:
            execution = self.components(recover=True)
            reply = execution.reconcile(request)
            require(reply == dict(request_sha256=digest(request), resolved=True), 'cleanup_connected_unresolved')
            scope = execution.cleanup_scope(reply)
        old = self.load('connected-setup-cleanup')
        require(old is None or old == scope, 'cleanup_connected_receipts_changed')
        self.persist('connected-setup-cleanup', scope)
        return dict(request_sha256=digest(request), resolved=True)

    def cleanup_scope(self, _result):
        require(self.load('connected-setup-stopped') == dict(binding=digest(self.scope)), 'cleanup_connected_not_stopped')
        value = self.load('connected-setup-cleanup')
        require(isinstance(value, dict), 'cleanup_connected_unresolved')
        return copy.deepcopy(value)
