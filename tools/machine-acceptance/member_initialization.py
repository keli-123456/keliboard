"""Incremental R35 initialization, with durable receipts before native authority.

An installed controller is not a completed bootstrap. This port orders the
reviewed setup interfaces and retains partial work for independent teardown.
It never discovers credentials, imports historical campaigns or grants itself
permission. Construction and imports have no external side effects.
"""
import copy
import time

from member_auxiliary import digest
from member_cli import RUN
from member_transport import require, reason


STAGES = ('control-create', 'control-install', 'runtime-install', 'private-import',
          'control-dns', 'callback-ready', 'sources-initialize', 'dns-initialize',
          'native-grant', 'native-ready')
# Source initialization is a durable, idempotent service state machine, not a
# repeated raw create call. Each role owns its own persisted native queue intent.
POLLING = {'control-create', 'sources-initialize', 'native-ready'}


class MemberInitialization:
    """Host bootstrap binding. `interfaces` has explicit setup/readback methods.

    Each interface receives only its original request and verified predecessor
    receipts. Replies have an exact request digest. A lost mutation reply forces
    reconciliation; there is no retry of a create/install/import operation.
    """
    def __init__(self, *, scope, interfaces, load, save, guard, clock=time.time):
        require(set(scope) == {'run_id', 'bundle_sha256', 'started_at', 'deadline_at'},
                'transport_initialization_scope_invalid')
        require(scope['run_id'] == RUN and isinstance(scope['bundle_sha256'], str)
                and len(scope['bundle_sha256']) == 64
                and all(c in '0123456789abcdef' for c in scope['bundle_sha256']),
                'transport_initialization_scope_invalid')
        require(type(scope['started_at']) is int and type(scope['deadline_at']) is int
                and scope['started_at'] > 0 and 1200 < scope['deadline_at'] - scope['started_at'] <= 7200,
                'transport_initialization_window_invalid')
        self.scope = copy.deepcopy(scope)
        self.interfaces, self.load, self.save, self.guard, self.clock = interfaces, load, save, guard, clock
        for name in (*[s.replace('-', '_') for s in STAGES], 'reconcile', 'cleanup_scope'):
            require(callable(getattr(interfaces, name, None)), 'transport_initialization_interface_missing')
        self.state = copy.deepcopy(load('initialization')) or {
            'binding': digest(scope), 'stages': list(STAGES), 'index': 0, 'receipts': {}, 'intents': {}, 'pending': None,
            'last_at': scope['started_at'], 'stopped': False, 'first_error': None}
        require(self.state.get('binding') == digest(scope), 'transport_initialization_scope_changed')
        require(self.state.get('stages') == list(STAGES), 'transport_initialization_topology_changed')

    def persist(self):
        self.save('initialization', copy.deepcopy(self.state))

    def stop(self, code):
        self.state['stopped'] = True
        self.state['first_error'] = self.state['first_error'] or code
        self.persist()

    def step(self):
        require(not self.state['stopped'], 'transport_initialization_stopped')
        now = int(self.clock())
        try:
            require(now >= self.state['last_at'], 'transport_initialization_clock_rollback')
            require(now < self.scope['deadline_at'] - 1200, 'transport_initialization_expired')
            self.state['last_at'] = now
            require(self.state['pending'] is None, 'transport_initialization_readback_required')
            if self.state['index'] == len(STAGES):
                return {'ready': True}
            stage = STAGES[self.state['index']]
            self.guard(stage)
            request = {'run_id': RUN, 'bundle_sha256': self.scope['bundle_sha256'], 'stage': stage,
                       'predecessors_sha256': digest(self.state['receipts'])}
            # Stable per-stage identity survives a host crash, unlike an ephemeral RPC UUID.
            request['request_id'] = digest([self.scope, stage])[:32]
            self.state['pending'] = request
            if stage not in self.state['intents']:
                self.state['intents'][stage] = copy.deepcopy(request)
            else:
                require(stage in POLLING and self.state['intents'][stage] == request,
                        'transport_initialization_replay_refused')
            self.persist()
            result = getattr(self.interfaces, stage.replace('-', '_'))(
                copy.deepcopy(request), copy.deepcopy(self.state['receipts']))
            require(isinstance(result, dict) and result.get('request_sha256') == digest(request),
                    'transport_initialization_reply_changed')
            if result.get('waiting') is True:
                require(stage in POLLING and set(result) == {'request_sha256', 'waiting'},
                        'transport_initialization_poll_invalid')
                self.state['pending'] = None
                self.persist()
                return {'ready': False, 'waiting': True, 'stage': stage}
            require(result.get('completed') is True and isinstance(result.get('receipt'), dict),
                    'transport_initialization_receipt_missing')
            receipt = result['receipt']
            # API implementations project IDs and hashes, never raw settings, tokens or logs.
            require(receipt.get('run_id') == RUN and receipt.get('stage') == stage
                    and receipt.get('request_id') == request['request_id'],
                    'transport_initialization_receipt_changed')
            require(set(receipt) <= {'run_id', 'stage', 'request_id', 'evidence_sha256', 'grant_sha256'}
                    and isinstance(receipt.get('evidence_sha256'), str)
                    and len(receipt['evidence_sha256']) == 64
                    and all(c in '0123456789abcdef' for c in receipt['evidence_sha256']),
                    'transport_initialization_receipt_not_projected')
            if stage == 'native-grant':
                require(isinstance(receipt.get('grant_sha256'), str) and len(receipt['grant_sha256']) == 64
                        and all(c in '0123456789abcdef' for c in receipt['grant_sha256']),
                        'transport_initialization_grant_missing')
            self.state['receipts'][stage] = copy.deepcopy(receipt)
            self.state['pending'] = None
            self.state['index'] += 1
            self.persist()
            return {'ready': self.state['index'] == len(STAGES), 'waiting': True, 'stage': stage}
        except Exception as error:
            self.stop(reason(error, 'transport_initialization_failed'))
            raise

    def reconcile(self):
        # Stop is durable before calling any remote readback. Reconciliation may
        # complete a receipt, but cannot resume bootstrap or issue native work.
        self.state['stopped'] = True
        self.persist()
        self.guard('initialization-reconcile')
        request = {'scope': self.scope, 'intents': self.state['intents'],
                   'receipts': self.state['receipts'], 'pending': self.state['pending']}
        result = self.interfaces.reconcile(copy.deepcopy(request))
        require(isinstance(result, dict) and result.get('request_sha256') == digest(request)
                and result.get('resolved') is True, 'transport_initialization_unresolved')
        scope = self.interfaces.cleanup_scope(copy.deepcopy(result))
        require(isinstance(scope, dict) and scope.get('run_id') == RUN,
                'cleanup_initialization_scope_changed')
        self.save('initialization-reconciled', {'binding': self.state['binding'], 'cleanup_scope': scope})
        return scope


class InitializedMemberPort:
    """Use with MemberRound, before or after a native CLI grant becomes available.

    `native_factory` consumes the verified native-grant receipt and constructs
    the existing MemberControllerPort. It is never called for a partial setup.
    """
    def __init__(self, initialization, native_factory):
        self.initialization, self.native_factory = initialization, native_factory
        self.native = None

    def native_port(self):
        require(self.initialization.state['index'] == len(STAGES), 'transport_initialization_incomplete')
        if self.native is None:
            self.native = self.native_factory(copy.deepcopy(self.initialization.state['receipts']['native-grant']))
        return self.native

    def __call__(self, operation, request):
        require(request.get('run_id') == RUN, 'transport_round_binding_changed')
        if operation == 'bootstrap':
            return dict(self.initialization.step(), request=copy.deepcopy(request))
        if operation == 'native-step':
            return self.native_port()(operation, request)
        require(operation == 'reconcile', 'transport_operation_invalid')
        # A grant may have been written even when its reply was lost. The setup
        # readback must revoke/stop it before declaring resources resolved.
        if self.initialization.state['index'] == len(STAGES):
            native = self.native_port()(operation, request)
            # Freeze native mutations before collecting final DNS/resource receipts.
            partial = self.initialization.reconcile()
            return dict(native, cleanup_scope={'run_id': RUN, 'initialization': partial,
                                               'native': native['cleanup_scope']})
        partial = self.initialization.reconcile()
        return {'run_id': RUN, 'resolved': True,
                'cleanup_scope': {'run_id': RUN, 'initialization': partial, 'native': None}}
