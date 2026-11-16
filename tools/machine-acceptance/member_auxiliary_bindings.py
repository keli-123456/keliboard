"""Bind auxiliary teardown to exact provider, controller and local evidence APIs.

Provider dispatch is MemberProviderBindings' four-operation surface. Controller
methods are separately pinned SSH calls; no shell, host or path is chosen here.
"""
import copy

from member_auxiliary import digest
from member_transport import require


class MemberAuxiliaryBindings:
    def __init__(self, *, scope, provider, controller, reconcile, export, load, save):
        self.scope = copy.deepcopy(scope)
        self.provider, self.controller = provider, controller
        self.reconcile, self.export, self.load, self.save = reconcile, export, load, save
        self.binding = digest(scope)
        self.pins = {'dns': {digest(p) for p in scope['records']},
                     'fault': {digest(p) for p in scope['faults']},
                     'private': {digest(p) for p in scope['private_files']},
                     'control': {digest(scope['control'])} if scope['control'] is not None else set()}

    def __call__(self, operation, pin):
        require(pin.get('run_id') == self.scope['run_id'], 'cleanup_binding_run_changed')
        if operation in ('control-inspect', 'control-delete', 'dns-inspect', 'dns-delete'):
            require(digest(pin) in self.pins[operation.split('-')[0]], 'cleanup_binding_pin_changed')
            # Never turn a provider exception or a missing dictionary into absence.
            result = self.provider.dispatch(operation, copy.deepcopy(pin))
            require(isinstance(result, dict) and result.get('pin_sha256') == digest(pin)
                    and result.get('status') in ('owned', 'absent'), 'cleanup_binding_provider_unconfirmed')
            return result
        methods = {'fault-restore': 'restore_fault', 'fault-inspect': 'inspect_fault',
                   'private-remove': 'remove_private', 'private-inspect': 'inspect_private'}
        if operation in methods:
            require(digest(pin) in self.pins[operation.split('-')[0]], 'cleanup_binding_pin_changed')
            result = getattr(self.controller, methods[operation])(copy.deepcopy(pin))
            require(isinstance(result, dict) and result.get('pin_sha256') == digest(pin),
                    'cleanup_binding_controller_unconfirmed')
            return result
        if operation == 'intents-inspect':
            require(pin == {'run_id': self.scope['run_id'], 'requests': self.scope['requests']},
                    'cleanup_binding_requests_changed')
            result = self.reconcile(copy.deepcopy(self.scope['requests']))
            require(isinstance(result, dict) and result.get('requests_sha256') == digest(self.scope['requests'])
                    and result.get('resolved') is True, 'cleanup_binding_requests_unresolved')
            return {'pin_sha256': digest(pin), 'resolved': True}
        require(operation == 'evidence-export-readback' and pin == {'run_id': self.scope['run_id']},
                'cleanup_binding_operation_invalid')
        evidence = self.export()
        require(isinstance(evidence, dict) and evidence.get('run_id') == self.scope['run_id']
                and set(evidence) == {'run_id', 'sha256', 'verified'}, 'cleanup_binding_evidence_invalid')
        require(evidence['verified'] is True and isinstance(evidence['sha256'], str)
                and len(evidence['sha256']) == 64 and all(c in '0123456789abcdef' for c in evidence['sha256']),
                'cleanup_binding_evidence_invalid')
        value = {'scope_sha256': self.binding, 'evidence_sha256': evidence['sha256']}
        key = 'auxiliary-evidence-readback'
        previous = self.load(key)
        require(previous is None or previous == value, 'cleanup_binding_evidence_changed')
        self.save(key, value)
        require(self.load(key) == value, 'cleanup_binding_evidence_readback_failed')
        return {'pin_sha256': digest(pin), 'verified': True}
