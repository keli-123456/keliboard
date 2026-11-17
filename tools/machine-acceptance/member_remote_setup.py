"""Host setup ports backed by the fixed root controller RPC, not stage receipts.

Installation must publish and import the verified runtime first. This binding
does not obtain credentials, create a controller, install packages or enable a
persistent setting. Unknown effects remain unresolved for stopped recovery.
"""
import base64
import copy
import hashlib
import os
from pathlib import Path
import stat

from member_auxiliary import digest
from member_cli import RUN
from member_execution import MemberExecutionSetup
from member_transport import StrictControllerTransport, require
from staging import read_file


class MemberRemoteSetup:
    def __init__(self, *, scope, transport, grant_file, load, save):
        require(scope.get('run_id') == RUN and isinstance(transport, StrictControllerTransport)
                and transport.run_id == RUN, 'transport_remote_setup_binding')
        self.scope, self.rpc = copy.deepcopy(scope), transport
        self.grant_file = Path(grant_file).absolute()
        self.load, self.save = load, save

    def persist(self, key, value):
        self.save(key, copy.deepcopy(value))
        require(self.load(key) == value, 'transport_remote_setup_journal')

    def identity(self, *, recover=False):
        identity = self.load('remote-setup-identity')
        if identity is None and recover and self.load('bootstrap-setup-issue') is not None:
            identity = self.rpc.bootstrap_call('setup-read')['setup']
            self.persist('remote-setup-identity', identity)
        require(isinstance(identity, dict) and identity.get('deadline_at') == self.scope['deadline_at'],
                'transport_remote_setup_identity')
        return copy.deepcopy(identity)

    def initialize(self):
        require((self.load('bootstrap-import') or {}).get('completed') is True,
                'transport_remote_setup_import_required')
        if self.load('bootstrap-configure') is None:
            self.rpc.bootstrap_call('configure')
        else:
            self.rpc.bootstrap_call('configuration-read')
        if self.load('bootstrap-setup-issue') is None:
            pin = self.load('setup-control-pin')
            require(isinstance(pin, dict), 'transport_remote_setup_control_required')
            result = self.rpc.bootstrap_call('setup-issue', pin)
        else:
            result = self.rpc.bootstrap_call('setup-read')
        identity = result['setup']
        require(identity['deadline_at'] == self.scope['deadline_at'], 'transport_remote_setup_identity')
        old = self.load('remote-setup-identity')
        require(old is None or old == identity, 'transport_remote_setup_identity')
        self.persist('remote-setup-identity', identity)

    def grant(self, result):
        pin = result['identity']
        require(pin['bundle_sha256'] == self.scope['bundle_sha256'], 'transport_remote_setup_bundle_changed')
        exported = self.rpc.setup_call('native-export', self.identity())
        require(exported['identity'] == pin, 'transport_remote_setup_grant_changed')
        raw = base64.b64decode(exported['grant_base64'], validate=True)
        require(hashlib.sha256(raw).hexdigest() == pin['grant_sha256'], 'transport_remote_setup_grant_changed')
        parent = self.grant_file.parent
        require(parent.resolve(strict=True) == parent and parent.is_dir(), 'transport_remote_setup_private_directory')
        if os.name == 'posix':
            info = parent.stat()
            require(info.st_uid == os.geteuid() and stat.S_IMODE(info.st_mode) == 0o700,
                    'transport_remote_setup_private_directory')
        if not self.grant_file.exists() and not self.grant_file.is_symlink():
            # No replacement after a partial write: retain it and require recovery.
            fd = os.open(self.grant_file, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
            with os.fdopen(fd, 'wb') as stream:
                if os.name == 'posix': os.fchmod(stream.fileno(), 0o600)
                require(stream.write(raw) == len(raw), 'transport_remote_setup_grant_unconfirmed')
                stream.flush(); os.fsync(stream.fileno())
        _, observed, _ = read_file(self.grant_file, 65536, collect=True, private=True)
        require(observed == raw, 'transport_remote_setup_grant_changed')
        self.persist('remote-native-grant', pin)
        return pin['grant_sha256']

    def dispatch(self, owner, stage, request, receipts):
        require(MemberExecutionSetup.OWNERS.get(stage) == owner and owner != 'installation'
                and request.get('run_id') == RUN and request.get('stage') == stage
                and request.get('bundle_sha256') == self.scope['bundle_sha256']
                and request.get('predecessors_sha256') == digest(receipts)
                and request.get('request_id') == digest([self.scope, stage])[:32], 'transport_remote_setup_request')
        require(self.load('remote-setup-stopped') is None, 'transport_remote_setup_stopped')
        if stage == 'control-dns': self.initialize()
        operation = {'control-dns': 'dns-control', 'sources-initialize': 'advance',
                     'dns-initialize': 'dns-edges', 'native-grant': 'native-issue', 'native-ready': 'native-read'}[stage]
        result = self.rpc.setup_call(operation, self.identity())
        if result.get('ready') is False:
            require(stage in ('sources-initialize', 'native-grant') and
                    (result.get('waiting') is True or operation == 'native-issue'), 'transport_remote_setup_not_ready')
            return dict(request_sha256=digest(request), waiting=True)
        require(result.get('ready') is True, 'transport_remote_setup_not_ready')
        evidence = copy.deepcopy(result)
        receipt = dict(run_id=RUN, stage=stage, request_id=request['request_id'])
        if stage == 'native-grant': receipt['grant_sha256'] = self.grant(result)
        if stage == 'native-ready':
            require(self.load('remote-native-grant') == result['identity'], 'transport_remote_setup_grant_changed')
            _, raw, _ = read_file(self.grant_file, 65536, collect=True, private=True)
            require(hashlib.sha256(raw).hexdigest() == result['identity']['grant_sha256'], 'transport_remote_setup_grant_changed')
        receipt['evidence_sha256'] = digest(evidence)
        return dict(request_sha256=digest(request), completed=True, receipt=receipt)

    def reconcile(self, owner, request):
        require(request.get('scope') == self.scope, 'cleanup_remote_setup_scope')
        if owner == 'authority':
            self.persist('remote-setup-stop-intent', dict(binding=digest(self.scope)))
            self.rpc.bootstrap_call('stop')
            self.persist('remote-setup-stopped', dict(binding=digest(self.scope)))
        require(self.load('remote-setup-stopped') == dict(binding=digest(self.scope)), 'cleanup_remote_setup_stop_required')
        if self.load('bootstrap-setup-issue') is not None:
            result = self.rpc.setup_call('resources', self.identity(recover=True))
            keys = {'resolved', 'roles', 'records', 'private_files'}
            require(set(result) in (keys, keys | {'uncertain_request', 'uncertain_cleanup'})
                    and result.get('resolved') is True, 'cleanup_remote_setup_unresolved')
            resource = {key: copy.deepcopy(result[key]) for key in keys}
        else:
            require(self.load('setup-binding') is None and self.load('remote-setup-identity') is None,
                    'cleanup_remote_setup_identity_missing')
            resource = dict(resolved=True, roles={}, records=[], private_files=[])
        self.validate_resources(resource)
        old = self.load('remote-setup-resources')
        require(old is None or old == resource, 'cleanup_remote_setup_resources_changed')
        self.persist('remote-setup-resources', resource)
        return dict(request_sha256=digest(request), resolved=True)

    def validate_resources(self, resource):
        # Reuse the exact assembly validators, including source UUIDs and DNS ownership.
        parts = {'installation': dict(private_files=[], requests=[]),
                 'sources': dict(roles=resource['roles'], faults=[], requests=[]),
                 'dns': dict(records=resource['records'], requests=[]),
                 'authority': dict(private_files=resource['private_files'], requests=[])}
        MemberExecutionSetup.scope_from_snapshots(parts)

    def snapshot(self, owner):
        require(self.load('remote-setup-stopped') == dict(binding=digest(self.scope)), 'cleanup_remote_setup_stop_required')
        value = self.load('remote-setup-resources')
        require(isinstance(value, dict), 'cleanup_remote_setup_unresolved')
        if owner == 'sources': return dict(roles=copy.deepcopy(value['roles']), faults=[], requests=[])
        if owner == 'dns': return dict(records=copy.deepcopy(value['records']), requests=[])
        require(owner == 'authority', 'cleanup_remote_setup_owner')
        return dict(private_files=copy.deepcopy(value['private_files']), requests=[])

    def port(self, owner):
        require(owner in ('sources', 'dns', 'authority'), 'transport_remote_setup_owner')
        binding = self
        class Port:
            def dispatch(self, stage, request, receipts): return binding.dispatch(owner, stage, request, receipts)
            def reconcile(self, request): return binding.reconcile(owner, request)
            def snapshot(self): return binding.snapshot(owner)
        return Port()
