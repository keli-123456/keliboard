"""R35 composition: partial bootstrap and stopped native work share one teardown.

All infrastructure is injected. Importing or constructing this module neither
obtains credentials nor enables execution. Setup reconciliation must return its
durable, exact resource receipts, including partially completed operations.
"""
import copy
import re
import time

from member_auxiliary import MemberAuxiliary
from member_auxiliary_bindings import MemberAuxiliaryBindings
from member_cli import RUN, BoundControllerTransport, MemberControllerPort
from member_initialization import MemberInitialization, InitializedMemberPort
from member_round import MemberRound, round_journal, run
from member_setup_bindings import MemberSetupBindings
from member_transport import MemberCleanup, require


class MemberRoundAssembly:
    def __init__(self, *, scope, setup, control, native_transport, provider, controller,
                 task_call, reconcile, export, load, save, guard, clock=time.time):
        self.scope = copy.deepcopy(scope)
        self.load, self.save, self.guard, self.clock = load, save, guard, clock
        self.provider, self.controller = provider, controller
        self.task_call, self.reconcile, self.export = task_call, reconcile, export
        self.transport_factory = native_transport
        self.native = None
        bindings = MemberSetupBindings(setup=setup, control=control, load=load, save=save)
        self.initialization = MemberInitialization(scope=scope, interfaces=bindings,
            load=load, save=save, guard=guard, clock=clock)
        self.port = InitializedMemberPort(self.initialization, self.native_port)

    def native_port(self, receipt):
        if self.native is None:
            transport = self.transport_factory(copy.deepcopy(receipt))
            require(isinstance(transport, BoundControllerTransport), 'transport_bound_controller_required')
            require(transport.run_id == RUN and transport.grant_sha256 == receipt['grant_sha256']
                    and transport.grant['bundle_sha256'] == self.scope['bundle_sha256']
                    and transport.grant['started_at'] == self.scope['started_at']
                    and transport.grant['deadline_at'] == self.scope['deadline_at'],
                    'transport_assembly_grant_changed')
            self.native = MemberControllerPort(transport)
        return self.native

    def cleanup_scope(self, combined):
        require(isinstance(combined, dict) and set(combined) == {'run_id', 'initialization', 'native'}
                and combined['run_id'] == RUN, 'cleanup_assembly_scope_invalid')
        initial, native = combined['initialization'], combined['native']
        require(isinstance(initial, dict) and set(initial) == {'run_id', 'roles', 'auxiliary'}
                and initial['run_id'] == RUN and isinstance(initial['roles'], dict),
                'cleanup_assembly_initialization_invalid')
        roles = copy.deepcopy(initial['roles'])
        require(set(roles) <= {'source-a', 'source-b'}, 'cleanup_assembly_initial_roles_invalid')
        if native is not None:
            require(isinstance(native, dict) and set(native) == {'run_id', 'roles'}
                    and native['run_id'] == RUN and isinstance(native['roles'], dict)
                    and {'source-a', 'source-b'} <= set(native['roles'])
                    and set(roles) == {'source-a', 'source-b'}, 'cleanup_assembly_native_invalid')
            for role, pin in native['roles'].items():
                require(role not in roles or roles[role] == pin, 'cleanup_assembly_source_changed')
                roles[role] = copy.deepcopy(pin)
        require(set(roles) <= {'source-a', 'source-b', 'candidate-a1', 'candidate-a2'}
                and ('candidate-a2' not in roles or 'candidate-a1' in roles),
                'cleanup_assembly_roles_invalid')
        ids, tasks = set(), set()
        for pin in roles.values():
            require(isinstance(pin, dict) and set(pin) == {'task_id', 'external_id'}
                    and isinstance(pin['task_id'], str)
                    and re.fullmatch(r'[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}', pin['task_id'])
                    and isinstance(pin['external_id'], str)
                    and re.fullmatch(r'[1-9][0-9]{0,15}', pin['external_id']), 'cleanup_assembly_role_invalid')
            require(pin['task_id'] not in tasks and pin['external_id'] not in ids,
                    'cleanup_assembly_role_collision')
            ids.add(pin['external_id']); tasks.add(pin['task_id'])
        auxiliary = copy.deepcopy(initial['auxiliary'])
        require(isinstance(auxiliary, dict) and auxiliary.get('run_id') == RUN,
                'cleanup_assembly_auxiliary_invalid')
        control = auxiliary.get('control')
        require(control is None or control.get('external_id') not in ids and control.get('task_id') not in tasks,
                'cleanup_assembly_control_collision')
        for fault in auxiliary.get('faults', []):
            require(fault.get('role') in roles
                    and roles[fault['role']]['external_id'] == fault.get('external_id'),
                    'cleanup_assembly_fault_changed')
        result = {'run_id': RUN, 'roles': roles, 'auxiliary': auxiliary}
        prior = self.load('assembly-cleanup-scope')
        require(prior is None or prior == result, 'cleanup_assembly_resume_changed')
        return result

    def cleanup(self, combined, restored):
        scope = self.cleanup_scope(combined)
        if combined['native'] is not None:
            self.native_port(self.initialization.state['receipts']['native-grant'])
        auxiliary_scope = copy.deepcopy(scope['auxiliary'])
        if self.native is not None:
            # Native DNS may now hold A2. Only the original signed publication
            # journal can resolve that pin; never reuse a mutable setup snapshot.
            auxiliary_scope['records'] = []
        bindings = MemberAuxiliaryBindings(scope=auxiliary_scope, provider=self.provider,
            controller=self.controller, reconcile=self.reconcile, export=self.export,
            load=self.load, save=self.save)

        def io(operation, pin):
            self.guard('cleanup-' + operation)
            return bindings(operation, pin)

        auxiliary = MemberAuxiliary(scope=auxiliary_scope, io=io, load=self.load,
                                    save=self.save, clock=self.clock)
        if self.native is not None:
            auxiliary = self.native.native_auxiliary(auxiliary)
        # Validate the complete ledger before persisting it or sending a delete.
        def call(operation, role):
            self.guard('cleanup-' + operation)
            if self.native is not None:
                return self.native.transport.call(operation, role)
            # Partial bootstrap has no native grant. This bound setup channel
            # receives the exact task pin, never a caller-selected host or ID.
            return self.task_call(operation, role, copy.deepcopy(scope['roles'][role]))

        cleanup = MemberCleanup(run_id=RUN, roles=scope['roles'], call=call, auxiliary=auxiliary,
            save=lambda value: self.save('cleanup', value), clock=self.clock,
            restored=self.load('cleanup') or restored)
        self.save('assembly-cleanup-scope', copy.deepcopy(scope))
        require(self.load('assembly-cleanup-scope') == scope, 'cleanup_assembly_readback_failed')
        return cleanup

    def round(self):
        restored = self.load('round')
        driver = MemberRound(run_id=RUN, bundle_sha256=self.scope['bundle_sha256'], port=self.port,
            cleanup_factory=self.cleanup, load=self.load, save=self.save, guard=self.guard, clock=self.clock,
            restored=restored)
        if restored is None:
            driver.state['started_at'] = self.scope['started_at']
        return driver


def run_assembled_round(directory, **bindings):
    """Explicit Linux host entry; hold the existing exclusive lock through cleanup.

    There are deliberately no default credentials, transports, installers or
    permissions. The caller must supply the approved, pinned interface bindings.
    """
    with round_journal(directory, RUN) as (load, save):
        assembly = MemberRoundAssembly(load=load, save=save, **bindings)
        return run(assembly.round())


def run_execution_round(directory, *, setup_ports, **bindings):
    """Compose the explicit setup APIs and native work under the same journal lock."""
    from member_execution import MemberExecutionSetup
    require(set(setup_ports) == {'installation', 'sources', 'dns', 'authority'}
            and 'setup' not in bindings, 'transport_execution_bindings_invalid')
    with round_journal(directory, RUN) as (load, save):
        setup = MemberExecutionSetup(scope=bindings['scope'], load=load, save=save,
                                     guard=bindings['guard'], **setup_ports)
        assembly = MemberRoundAssembly(setup=setup, load=load, save=save, **bindings)
        return run(assembly.round())
