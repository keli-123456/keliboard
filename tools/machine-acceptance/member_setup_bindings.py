"""Connect control creation receipts to the staged host/bootstrap interfaces.

`control.dispatch` is the MemberControlCreation PHP boundary, not a raw HTTP
POST. Its advance operation persists once and only reconciles on later polls.
The remaining setup methods are the pinned installer/source/DNS interfaces.
"""
import copy
import re

from member_auxiliary import digest
from member_cli import RUN
from member_initialization import STAGES
from member_transport import require


class MemberSetupBindings:
    def __init__(self, *, setup, control, load, save):
        self.setup, self.control, self.load, self.save = setup, control, load, save
        for stage in STAGES[1:]:
            name = stage.replace('-', '_')
            method = getattr(setup, name, None)
            require(callable(method), 'transport_initialization_interface_missing')
            setattr(self, name, method)

    def control_create(self, request, _receipts):
        prior = self.load('setup-control-request')
        require(prior is None or prior == request, 'transport_control_request_changed')
        self.save('setup-control-request', copy.deepcopy(request))
        require(self.load('setup-control-request') == request, 'transport_control_request_unsaved')
        result = self.control.dispatch('control-create', copy.deepcopy(request))
        pin = self.check(result, request)
        if result.get('ready') is not True:
            return {'request_sha256': digest(request), 'waiting': True}
        require(pin is not None, 'transport_control_receipt_missing')
        return {'request_sha256': digest(request), 'completed': True, 'receipt': {
            'run_id': RUN, 'stage': 'control-create', 'request_id': request['request_id'],
            'evidence_sha256': digest(pin)}}

    def check(self, result, request):
        require(isinstance(result, dict) and result.get('request_sha256') == digest(request)
                and set(result) <= {'request_sha256', 'resolved', 'dispatched', 'ready', 'pin', 'creation_rejected'}
                and type(result.get('resolved')) is bool and type(result.get('dispatched')) is bool
                and type(result.get('ready')) is bool and type(result.get('creation_rejected', False)) is bool,
                'transport_control_reply_changed')
        pin = result.get('pin')
        if pin is not None:
            require(isinstance(pin, dict) and set(pin) == {'run_id', 'external_id', 'task_id', 'label',
                    'marker', 'region', 'size', 'image', 'provider_created_at'}
                    and pin['run_id'] == RUN and all(isinstance(v, str) for v in pin.values())
                    and re.fullmatch(r'[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}', pin['task_id'])
                    and re.fullmatch(r'[1-9][0-9]{0,15}', pin['external_id']), 'transport_control_pin_changed')
            suffix = pin['task_id'].replace('-', '')
            require(pin['label'] == 'keli-' + suffix and pin['marker'] == 'keli-create-' + suffix
                    and pin['region'] == 'sg-sin-2' and pin['size'] == 'g6-standard-2'
                    and pin['image'] == 'linode/debian12'
                    and re.fullmatch(r'\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ?', pin['provider_created_at']),
                    'transport_control_pin_changed')
        prior = self.load('setup-control-pin')
        require(prior is None or prior == pin, 'transport_control_pin_changed')
        require(not result['ready'] or result['resolved'] and result['dispatched'] and pin is not None,
                'transport_control_reply_changed')
        require(not result.get('creation_rejected') or result['resolved'] and result['dispatched']
                and pin is None and not result['ready'], 'transport_control_reply_changed')
        if pin is not None:
            require(result['dispatched'] is True, 'transport_control_dispatch_changed')
            self.save('setup-control-pin', copy.deepcopy(pin))
            require(self.load('setup-control-pin') == pin, 'transport_control_pin_unsaved')
        return pin

    def reconcile(self, request):
        # Host setup must first stop/reconcile any source or grant work. Even if
        # that fails, read back the original control request for retained evidence.
        error, result = None, None
        try:
            result = self.setup.reconcile(copy.deepcopy(request))
        except Exception as failure:
            error = failure
        original = self.load('setup-control-request')
        if original is not None:
            reply = self.control.dispatch('control-readback', copy.deepcopy(original))
            pin = self.check(reply, original)
            require(reply['resolved'] is True and (pin is not None or reply['dispatched'] is False
                    or reply.get('creation_rejected') is True and reply['ready'] is False),
                    'transport_control_unresolved')
        else:
            require(self.load('setup-control-pin') is None, 'transport_control_request_missing')
            require(not request['intents'].get('control-create') or request['pending'] is not None,
                    'transport_control_request_missing')
        if error is not None:
            raise error
        return result

    def cleanup_scope(self, result):
        scope = copy.deepcopy(self.setup.cleanup_scope(result))
        require(isinstance(scope, dict) and isinstance(scope.get('auxiliary'), dict),
                'cleanup_assembly_initialization_invalid')
        control = self.load('setup-control-pin')
        require(scope['auxiliary'].get('control') in (None, control), 'cleanup_assembly_control_changed')
        scope['auxiliary']['control'] = control
        request = self.load('setup-control-request')
        if request is not None:
            requests = scope['auxiliary']['requests']
            if request['request_id'] not in requests:
                requests.append(request['request_id'])
        return scope
