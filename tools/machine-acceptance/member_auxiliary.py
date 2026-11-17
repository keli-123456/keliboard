"""Exact-pin auxiliary teardown. I/O is injected; no credential or network loader."""
import copy
import hashlib
import json
import re
import time

from member_transport import TransportError, require, reason


def digest(value):
    return hashlib.sha256(json.dumps(value, sort_keys=True, separators=(',', ':')).encode()).hexdigest()


class MemberAuxiliary:
    """Provider/SSH adapters return typed receipts, not raw logs or error messages.

    A pin is persisted before dispatch. An uncertain DELETE is inspected only; it
    is never repeated. A 404 qualifies only with the same resource pin echoed.
    """
    def __init__(self, *, scope, io, load, save, clock=time.time):
        self.scope = copy.deepcopy(scope)
        self.io, self.load, self.save, self.clock = io, load, save, clock
        require(re.fullmatch(r'keli-accept-[a-z0-9-]{8,80}', scope.get('run_id', '')), 'cleanup_run_invalid')
        require(set(scope) == {'run_id', 'control', 'records', 'faults', 'private_files', 'requests'},
                'cleanup_scope_invalid')
        require(len(scope['records']) <= 4 and len(scope['faults']) <= 2, 'cleanup_scope_invalid')
        for pin in ([scope['control']] if scope['control'] is not None else []) + scope['records'] + scope['faults'] + scope['private_files']:
            require(isinstance(pin, dict) and pin.get('run_id') == scope['run_id'], 'cleanup_pin_invalid')
        require(len({r['record_id'] for r in scope['records']}) == len(scope['records']), 'cleanup_record_reused')
        for pin in scope['records']:
            require(set(pin) == {'run_id', 'record_id', 'name', 'type', 'line', 'value', 'marker'},
                    'cleanup_dns_pin_invalid')
            require(pin['type'] in ('A', 'TXT') and pin['line'] == 'default'
                    and pin['name'] in {
                        'control-' + scope['run_id'] + '.dnscloudcloud.top',
                        '_acme-challenge.control-' + scope['run_id'] + '.dnscloudcloud.top',
                        'edge-a-' + scope['run_id'] + '.dnscloudcloud.top',
                        'edge-b-' + scope['run_id'] + '.dnscloudcloud.top'}, 'cleanup_dns_name_invalid')
        require(all(p.get('role') in ('source-a', 'candidate-a1') for p in scope['faults']), 'cleanup_fault_role_invalid')
        for pin in scope['private_files']:
            from member_private_cleanup import PRIVATE_PATHS, validate_pin
            require(pin.get('path') in PRIVATE_PATHS, 'cleanup_private_path_invalid')
            validate_pin(pin)
        binding = digest(scope)
        state = load('auxiliary')
        self.state = copy.deepcopy(state) if state is not None else {
            'binding': binding, 'intents': [], 'reads': {}, 'export_attempted': False,
            'evidence_saved_and_read_back': False, 'first_error': None}
        require(self.state.get('binding') == binding, 'cleanup_resume_changed')

    def persist(self):
        self.save('auxiliary', copy.deepcopy(self.state))

    def request(self, operation, pin):
        binding = digest(pin)
        result = self.io(operation, copy.deepcopy(pin))
        require(isinstance(result, dict) and result.get('pin_sha256') == binding,
                'cleanup_auxiliary_binding_changed')
        return result

    def once(self, operation, pin):
        key = operation + ':' + digest(pin)
        if key in self.state['intents']:
            return
        self.state['intents'].append(key)
        self.persist()
        self.request(operation, pin)

    def absent(self, kind, pin):
        key = kind + ':' + digest(pin)
        reads = self.state['reads'].setdefault(key, [])
        now = int(self.clock())
        if reads and now < reads[-1]:
            reads.clear()
            raise TransportError('cleanup_clock_rollback')
        if reads and now - reads[-1] < 10:
            return len(reads) == 2
        try:
            receipt = self.request(kind + '-inspect', pin)
            observed = int(self.clock())
            require(observed >= now, 'cleanup_clock_rollback')
            if receipt.get('status') == 'absent':
                reads[:] = (reads + [observed])[-2:]
            elif receipt.get('status') == 'owned':
                reads.clear()
                self.once(kind + '-delete', pin)
            else:
                raise TransportError('cleanup_auxiliary_absence_unknown')
        except Exception:
            reads.clear()
            raise
        return len(reads) == 2

    def __call__(self, operation):
        try:
            if operation == 'restore-faults':
                for pin in self.scope['faults']:
                    # Restoring the same pinned fault is idempotent, unlike cloud deletion.
                    self.request('fault-restore', pin)
                    require(self.request('fault-inspect', pin).get('status') == 'restored',
                            'cleanup_restore_unconfirmed')
                return {'restored': True}
            if operation == 'dns-and-private-cleanup':
                if not self.state['export_attempted']:
                    self.state['export_attempted'] = True
                    self.persist()
                    try:
                        receipt = self.request('evidence-export-readback', {'run_id': self.scope['run_id']})
                        self.state['evidence_saved_and_read_back'] = receipt.get('verified') is True
                    except Exception:
                        self.state['first_error'] = self.state['first_error'] or 'cleanup_evidence_unconfirmed'
                # Keep going if one record fails, without mistaking failure for absence.
                absent = []
                for pin in self.scope['records']:
                    try:
                        absent.append(self.absent('dns', pin))
                    except Exception as error:
                        absent.append(False)
                        self.state['first_error'] = self.state['first_error'] or reason(error, 'cleanup_dns_unconfirmed')
                reconciled = self.request('intents-inspect', {'run_id': self.scope['run_id'],
                    'requests': self.scope['requests']}).get('resolved') is True
                removed = True
                # Credentials remain usable until every provider operation has been reconciled.
                if all(absent) and reconciled:
                    for pin in self.scope['private_files']:
                        self.request('private-remove', pin)
                        removed &= self.request('private-inspect', pin).get('status') == 'absent'
                else:
                    removed = False
                return {'dns_absent_twice': all(absent), 'intents_reconciled': reconciled,
                        'private_materials_removed': removed,
                        'pending': self.state['first_error'] is None and (not all(absent) or not removed),
                        'evidence_saved_and_read_back': self.state['evidence_saved_and_read_back']}
            if operation == 'control-delete-or-inspect':
                if self.scope['control'] is None:
                    require(self.request('intents-inspect', {'run_id': self.scope['run_id'],
                            'requests': self.scope['requests']}).get('resolved') is True,
                            'cleanup_control_create_unresolved')
                    return {'exact_identity': True, 'absent': True}
                self.absent('control', self.scope['control'])
                receipt = self.request('control-inspect', self.scope['control'])
                require(receipt.get('status') in ('owned', 'absent'), 'cleanup_control_identity_unknown')
                return {'exact_identity': True, 'absent': receipt['status'] == 'absent'}
            raise TransportError('cleanup_operation_invalid')
        finally:
            self.persist()
