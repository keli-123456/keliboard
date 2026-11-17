"""Concrete installation port for an already bootstrapped isolated controller.

OS packages, pinned SSH identity and approved TLS material are prerequisites.
This port neither creates hosts nor obtains credentials or certificates. Import
permission is an explicit input, not inferred from constructing a transport.
"""
import base64
import copy
import hashlib
import json
from pathlib import Path
import time

from driver_journal import safe_error
from member_auxiliary import digest
from member_cli import RUN
from member_execution import MemberExecutionSetup
from member_host_installation import validate
from member_http_service import validate as validate_http
from member_initialization import STAGES
from member_transport import StrictControllerTransport, require
from staging import read_file


class MemberHostSetup:
    STAGES = ('control-install', 'runtime-install', 'private-import', 'callback-ready')

    def __init__(self, *, scope, transport, archive, settings, service, credentials,
                 import_permit, tls_files, load, save, clock=time.time):
        validate(dict(operation='configure', scope=scope, settings=settings, service=service))
        validate_http(dict(operation='start', scope=scope, files=tls_files))
        require(isinstance(transport, StrictControllerTransport) and transport.run_id == RUN,
                'transport_host_setup_transport')
        require(isinstance(credentials, bytes) and 0 < len(credentials) <= 65536,
                'transport_host_setup_material')
        expected = dict(scope=scope, credentials_sha256=hashlib.sha256(credentials).hexdigest(), authorized=True)
        require(isinstance(import_permit, dict) and import_permit == expected
                and import_permit['authorized'] is True, 'transport_host_setup_import_permission')
        self.scope, self.settings, self.service = copy.deepcopy(scope), copy.deepcopy(settings), copy.deepcopy(service)
        self.rpc, self.archive, self.credentials = transport, Path(archive).absolute(), credentials
        self.permit, self.tls_files = copy.deepcopy(import_permit), copy.deepcopy(tls_files)
        self.load, self.save, self.clock = load, save, clock

    def persist(self, key, value):
        self.save(key, copy.deepcopy(value))
        require(self.load(key) == value, 'transport_host_setup_journal')

    def bind(self):
        control = self.load('setup-control-pin')
        require(isinstance(control, dict) and control.get('run_id') == RUN, 'transport_host_setup_control')
        binding = dict(scope=self.scope, control_sha256=digest(control), host=self.rpc.host,
            settings_sha256=digest(self.settings), service=self.service, permit_sha256=digest(self.permit),
            tls_sha256={k: v['sha256'] for k, v in self.tls_files.items()})
        for path, key in ((self.rpc.identity, 'identity_sha256'), (self.rpc.known, 'known_hosts_sha256')):
            binding[key] = read_file(path, 1048576)[0]['sha256']
        old = self.load('host-setup-binding')
        require(old is None or old == binding, 'transport_host_setup_binding_changed')
        if old is None: self.persist('host-setup-binding', binding)
        return binding

    def guard(self):
        now = int(self.clock())
        require(self.load('host-setup-stop-intent') is None and self.load('host-setup-stopped') is None,
                'transport_host_setup_stopped')
        require(self.scope['started_at'] <= now < self.scope['deadline_at'] - 1200
                and now >= (self.load('host-setup-clock') or {}).get('at', self.scope['started_at']),
                'transport_host_setup_expired_or_clock')
        self.persist('host-setup-clock', dict(at=now))

    def installation(self, operation, **fields):
        self.guard()
        return self.rpc.installation_call(dict(operation=operation, scope=self.scope, **fields))

    def dispatch(self, stage, request, receipts):
        require(stage in self.STAGES and request == dict(run_id=RUN, bundle_sha256=self.scope['bundle_sha256'],
            stage=stage, predecessors_sha256=digest(receipts), request_id=digest([self.scope, stage])[:32])
            and list(receipts) == list(STAGES[:STAGES.index(stage)]), 'transport_host_setup_request')
        self.bind()
        require(receipts['control-create'].get('evidence_sha256') == digest(self.load('setup-control-pin')),
                'transport_host_setup_control')
        self.guard()
        key = 'host-setup-' + stage
        prior = self.load(key)
        if prior is not None:
            require(prior.get('request_sha256') == digest(request) and prior.get('reply') is not None,
                    'transport_host_setup_readback_required')
            return copy.deepcopy(prior['reply'])
        for previous in self.STAGES[:self.STAGES.index(stage)]:
            require(((self.load('host-setup-' + previous) or {}).get('reply') or {}).get('completed') is True,
                    'transport_host_setup_predecessor_missing')
        self.persist(key, dict(request_sha256=digest(request), reply=None))
        try:
            if stage == 'control-install':
                self.rpc.upload(self.archive, self.scope['bundle_sha256'])
                evidence = self.installation('publish')
            elif stage == 'runtime-install':
                configured = self.installation('configure', settings=self.settings, service=self.service)
                self.guard()
                application = self.rpc.application_call('initialize', self.scope)
                self.persist('host-setup-application', application)
                self.guard()
                smoke = self.rpc.application_call('service-smoke', self.scope, self.service)
                evidence = dict(configured=configured, application=application, service=smoke)
            elif stage == 'private-import':
                application = self.rpc.application_call('readback', self.scope)
                require(self.load('host-setup-application') == application, 'transport_host_setup_application_changed')
                # Bind the caller's explicit import permit to the identity returned
                # by the installed application. This is not a native/cloud grant.
                authority = dict(schema=1, operation='private-import', authorized=True, **self.scope,
                    machine_id_sha256=application['machine_id_sha256'], credentials_sha256=self.permit['credentials_sha256'])
                material = {'credentials': self.credentials,
                            'bootstrap-authority': json.dumps(authority, sort_keys=True, separators=(',', ':')).encode()}
                files = {name: dict(sha256=hashlib.sha256(raw).hexdigest(), base64=base64.b64encode(raw).decode())
                         for name, raw in material.items()}
                published = self.installation('private', files=files)
                self.guard()
                evidence = dict(publication=published, imported=self.rpc.bootstrap_call('import'))
            else:
                started = self.rpc.http_call(dict(operation='start', scope=self.scope, files=self.tls_files))
                self.guard()
                evidence = self.rpc.http_call(dict(operation='read', scope=self.scope))
                require(evidence == started and evidence['state'] == 'running', 'transport_host_setup_http_changed')
            self.guard()
            receipt = {k: request[k] for k in ('run_id', 'stage', 'request_id')}
            reply = dict(request_sha256=digest(request), completed=True,
                         receipt=dict(receipt, evidence_sha256=digest(evidence)))
            self.persist(key, dict(request_sha256=digest(request), reply=reply))
            return copy.deepcopy(reply)
        except BaseException as error:
            if self.load('host-setup-first-error') is None:
                self.persist('host-setup-first-error', dict(stage=stage, diagnostic=safe_error(error)))
            raise

    def reconcile(self, request):
        require(request.get('scope') == self.scope, 'cleanup_host_setup_scope')
        # Before control creation there can be no upload or installation binding.
        if self.load('setup-control-pin') is None:
            require(all(self.load(k) is None for k in ('host-setup-binding', 'upload-intent',
                'host-installation-binding', 'application-binding', 'http-binding', 'bootstrap-binding')),
                'cleanup_host_setup_control_unknown')
            binding = dict(scope=self.scope, never_dispatched=True)
        else:
            binding = self.bind()
        seal = dict(binding=digest(binding))
        self.persist('host-setup-stop-intent', seal)
        old = self.load('host-setup-stopped')
        if old is None:
            pins = []
            http = any(self.load(k) is not None for k in ('http-binding', 'http-start', 'http-stop'))
            if http:
                pins.extend(self.rpc.http_call(dict(operation='stop', scope=self.scope))['private_files'])
            cold = self.load('host-installation-binding') is not None
            if cold:
                stopped = self.rpc.installation_call(dict(operation='stop', scope=self.scope))
                require(stopped['stopped'] is True, 'cleanup_host_setup_not_stopped')
                pins.extend(stopped['private_files'])
            else:
                # Upload is fixed, exclusive and unarmed; it cannot execute code.
                # Any application/RPC intent without cold publication is unknown.
                require(not http and all(self.load(k) is None for k in (
                    'application-binding', 'application-initialize', 'bootstrap-binding',
                    'host-installation-publish', 'host-installation-configure', 'host-installation-private')),
                    'cleanup_host_setup_publication_unknown')
            require(len({p['path'] for p in pins}) == len(pins), 'cleanup_host_setup_duplicate_pin')
            parts = dict(installation=dict(private_files=pins, requests=[]),
                sources=dict(roles={}, faults=[], requests=[]), dns=dict(records=[], requests=[]),
                authority=dict(private_files=[], requests=[]))
            MemberExecutionSetup.scope_from_snapshots(parts)
            self.persist('host-setup-resources', parts['installation'])
            self.persist('host-setup-stopped', seal)
        else:
            require(old == seal, 'cleanup_host_setup_binding_changed')
        self.snapshot()
        return dict(request_sha256=digest(request), resolved=True)

    def snapshot(self):
        require(self.load('host-setup-stopped') is not None
                and self.load('host-setup-stopped') == self.load('host-setup-stop-intent'),
                'cleanup_host_setup_not_stopped')
        value = self.load('host-setup-resources')
        require(isinstance(value, dict) and set(value) == {'private_files', 'requests'}, 'cleanup_host_setup_unresolved')
        return copy.deepcopy(value)
