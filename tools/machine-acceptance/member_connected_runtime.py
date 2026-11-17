"""Native execution and stopped recovery on the exact installed controller.

Provider cleanup credentials/permits remain explicit operator inputs. This
binding neither acquires them nor promotes a setup grant to native authority.
"""
import copy

from member_auxiliary import digest
from member_cli import BoundControllerTransport, RUN
from member_connected_setup import MemberConnectedSetup
from member_evidence import MemberEvidenceArchive, MemberSetupEvidenceArchive
from member_initialization import STAGES
from member_transport import require


class MemberConnectedRuntime:
    def __init__(self, setup, evidence_directory):
        require(isinstance(setup, MemberConnectedSetup), 'transport_connected_setup_required')
        self.setup, self.directory = setup, evidence_directory
        self.load, self.save = setup.load, setup.save

    def stopped(self):
        require(self.load('connected-setup-stopped') == dict(binding=digest(self.setup.scope))
                and self.load('connected-setup-cleanup') is not None,
                'cleanup_connected_runtime_not_stopped')
        value = self.load('initialization-reconciled')
        require(isinstance(value, dict) and value.get('binding') == digest(self.setup.scope),
                'cleanup_connected_runtime_unreconciled')
        return value['cleanup_scope']

    def native_transport(self, receipt):
        state = self.load('initialization')
        require(isinstance(state, dict) and state.get('binding') == digest(self.setup.scope)
                and state.get('index') == len(STAGES) and state.get('stages') == list(STAGES)
                and state.get('receipts', {}).get('native-grant') == receipt,
                'transport_connected_native_receipt')
        rpc = self.setup.bound_transport()
        bound = BoundControllerTransport(grant_file=self.setup.grant, grant_sha256=receipt['grant_sha256'],
            run_id=RUN, host=rpc.host, identity_file=rpc.identity, known_hosts=rpc.known,
            guard=rpc.guard, load=self.load, save=self.save, execute=rpc.execute, clock=self.setup.clock)
        require(all(bound.grant[k] == self.setup.scope[k]
                    for k in ('run_id', 'bundle_sha256', 'started_at', 'deadline_at')),
                'transport_connected_native_scope')
        return bound

    def task_call(self, operation, role, pin):
        scope = self.stopped()
        require(operation in ('task-inspect', 'task-delete') and role in ('source-a', 'source-b')
                and scope['roles'].get(role) == pin, 'cleanup_connected_task_pin')
        state = self.load('initialization')
        require(state.get('index', len(STAGES)) < len(STAGES), 'cleanup_connected_native_required')
        rpc = self.setup.bound_transport()
        identity = copy.deepcopy(self.load('remote-setup-identity'))
        if operation == 'task-delete':
            key = 'connected-source-delete-' + role
            require(self.load(key) is None, 'cleanup_connected_task_readback_required')
            self.setup.persist(key, dict(pin_sha256=digest(pin)))
        name = 'source-' + ('inspect-' if operation == 'task-inspect' else 'delete-') + role[-1]
        reply = rpc.setup_call(name, identity)
        require(isinstance(reply, dict) and type(reply.get('complete')) is bool,
                'cleanup_connected_task_unconfirmed')
        if operation == 'task-inspect':
            require(reply.get('classification') in ('present', 'exact_absence')
                    and all(reply.get(k) == v for k, v in pin.items()), 'cleanup_connected_task_unconfirmed')
            return dict(pin, absent=reply['classification'] == 'exact_absence')
        require(reply.get('classification') in ('delete_dispatched', 'delete_readback_required', 'exact_absence'),
                'cleanup_connected_task_unconfirmed')
        if reply['classification'] == 'exact_absence':
            require(all(reply.get(k) == v for k, v in pin.items()), 'cleanup_connected_task_unconfirmed')
        return dict(pin, dispatched=reply['classification'] == 'delete_dispatched')

    def reconcile(self, requests):
        scope = self.stopped()
        require(requests == scope['auxiliary']['requests'], 'cleanup_connected_requests_changed')
        request = self.load('setup-control-request')
        require(requests == ([] if request is None else [request['request_id']]),
                'cleanup_connected_requests_unknown')
        if request is not None:
            reply = self.setup.control.dispatch('control-readback', request)
            require(reply['resolved'] is True and reply['pin'] == scope['auxiliary']['control']
                    and (reply['pin'] is not None or reply['dispatched'] is False
                         or reply.get('creation_rejected') is True), 'cleanup_connected_control_unconfirmed')
        return dict(requests_sha256=digest(requests), resolved=True)

    def export(self):
        self.stopped()
        state = self.load('initialization')
        if state.get('index') == len(STAGES):
            archive = MemberEvidenceArchive(self.native_transport(state['receipts']['native-grant']), self.directory)
        else:
            archive = MemberSetupEvidenceArchive(self.setup.bound_transport(), self.directory,
                scope=self.setup.scope, identity=self.load('remote-setup-identity'))
        previous = self.load('connected-runtime-evidence')
        if previous is not None:
            archive.verify(previous)
            return copy.deepcopy(previous)
        receipt = archive.capture()
        archive.verify(receipt)
        self.setup.persist('connected-runtime-evidence', receipt)
        return receipt

    def controller(self, method, pin):
        scope = self.stopped()['auxiliary']
        group = 'faults' if method in ('restore_fault', 'inspect_fault') else 'private_files'
        require(method in ('restore_fault', 'inspect_fault', 'remove_private', 'inspect_private')
                and pin in scope[group], 'cleanup_connected_controller_pin')
        operation = getattr(self.setup.bound_transport(), method, None)
        require(callable(operation), 'cleanup_connected_controller_operation_unavailable')
        return operation(copy.deepcopy(pin))

    def restore_fault(self, pin): return self.controller('restore_fault', pin)
    def inspect_fault(self, pin): return self.controller('inspect_fault', pin)
    def remove_private(self, pin): return self.controller('remove_private', pin)
    def inspect_private(self, pin): return self.controller('inspect_private', pin)
