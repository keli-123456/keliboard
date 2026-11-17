"""Simulated provider, SSH and DNS; production transports cannot be constructed here."""
import copy
import unittest
from unittest.mock import patch

from member_auxiliary import MemberAuxiliary, digest
from member_round import MemberRound
from member_transport import MemberCleanup, TransportError

RUN = 'keli-accept-offline-r35-fixture'


class RoundFixture:
    def __init__(self):
        self.now = 1000
        self.journal, self.events, self.pins = {}, [], {}
        self.roles = {r: {'task_id': '00000000-0000-4000-8000-' + str(i).zfill(12),
                         'external_id': str(i + 900)}
                      for i, r in enumerate(('source-a', 'source-b', 'candidate-a1', 'candidate-a2'), 1)}
        self.present = {'source-a', 'source-b', 'candidate-a2'}
        self.scope = {'run_id': RUN, 'control': {'run_id': RUN, 'external_id': '800', 'marker': 'test-only'},
            'records': [{'run_id': RUN, 'record_id': str(5001 + i), 'name': prefix + RUN + '.dnscloudcloud.top',
                         'type': 'A', 'line': 'default', 'value': '192.0.2.' + str(10 + i), 'marker': 'fixture-owner'}
                        for i, prefix in enumerate(('control-', 'edge-a-', 'edge-b-'))],
            'faults': [{'run_id': RUN, 'role': 'source-a', 'external_id': '901', 'rule_sha256': 'a' * 64}],
            'private_files': [{'run_id': RUN, 'path': '/opt/keli-isolated/private/cloud-credential.json', 'sha256': 'b' * 64}],
            'requests': ['original-create-id']}
        for pin in [self.scope['control'], *self.scope['records'], *self.scope['private_files']]:
            self.pins[digest(pin)] = True
        self.export_ok, self.fail_operation, self.mismatch = True, None, False

    def save(self, key, value):
        self.journal[key] = copy.deepcopy(value)

    def io(self, operation, pin):
        self.events.append((operation, copy.deepcopy(pin), self.now))
        if operation == self.fail_operation:
            raise TimeoutError('must-not-export secret/path/token')
        reply = {'pin_sha256': 'changed' if self.mismatch else digest(pin)}
        if operation.endswith('-delete') or operation == 'private-remove':
            self.pins[digest(pin)] = False
        if operation.endswith('-inspect'):
            reply['status'] = 'owned' if self.pins.get(digest(pin), False) else 'absent'
        if operation == 'fault-inspect': reply['status'] = 'restored'
        if operation == 'intents-inspect': reply['resolved'] = True
        if operation == 'evidence-export-readback': reply['verified'] = self.export_ok
        return reply

    def aux(self):
        return MemberAuxiliary(scope=self.scope, io=self.io, load=self.journal.get, save=self.save, clock=lambda: self.now)

    def task(self, operation, role):
        self.events.append((operation, role, self.now))
        if operation == 'task-delete': self.present.discard(role)
        return dict(self.roles[role], absent=role not in self.present)

    def cleanup(self, scope, restored):
        assert scope == self.scope
        return MemberCleanup(run_id=RUN, roles=self.roles, call=self.task, auxiliary=self.aux(),
            save=lambda value: self.save('cleanup', value), clock=lambda: self.now, restored=restored)

    def port(self, op, request):
        self.events.append((op, copy.deepcopy(request), self.now))
        if op == self.fail_operation: raise TimeoutError('private failure')
        if op == 'reconcile': return {'run_id': RUN, 'resolved': True, 'cleanup_scope': self.scope}
        if op == 'bootstrap': return {'request': request, 'ready': True}
        return {'request': request, 'phase': 'finished', 'branches': dict.fromkeys(
            ('native_cleanup', 'cooldown', 'retry', 'b_unchanged', 'dns_cutover'), True)}

    def round(self, restored=None):
        return MemberRound(run_id=RUN, bundle_sha256='d' * 64, port=self.port, cleanup_factory=self.cleanup,
            load=self.journal.get, save=self.save, guard=lambda _: None, clock=lambda: self.now, restored=restored)


class AuxiliaryTests(unittest.TestCase):
    def setUp(self):
        self.f = RoundFixture()
        p = patch('socket.socket', side_effect=AssertionError('network forbidden')); p.start(); self.addCleanup(p.stop)

    def finish(self, driver):
        for _ in range(30):
            state = driver.step()
            if state['phase'] == 'closed': return state
            self.f.now += 10
        self.fail('round did not close')

    def test_full_entry_cleans_all_roles_then_dns_and_private_control_last(self):
        state = self.finish(self.f.round())
        self.assertTrue(state['acceptance_passed']); self.assertTrue(state['resources_cleaned'])
        self.assertFalse(any(self.f.pins.values())); self.assertEqual(self.f.present, set())
        ops = [e[0] for e in self.f.events]
        self.assertLess(ops.index('fault-restore'), ops.index('task-delete'))
        self.assertLess(ops.index('private-remove'), ops.index('control-delete'))
        self.assertEqual(ops.count('control-delete'), 1)

    def test_export_failure_fails_acceptance_but_still_deletes_resources(self):
        self.f.export_ok = False
        state = self.finish(self.f.round())
        self.assertTrue(state['resources_cleaned']); self.assertFalse(state['acceptance_passed'])
        self.assertEqual(state['cleanup']['first_error']['reason'], 'cleanup_evidence_unconfirmed')

    def test_native_diagnostic_branches_are_not_all_required_success_conditions(self):
        original = self.f.port

        def port(operation, request):
            reply = original(operation, request)
            if operation == 'native-step':
                # These native observations stay false when final teardown is host-owned.
                reply['branches'].update(retry_exhausted=False, final_cleanup=False)
            return reply

        self.f.port = port
        state = self.finish(self.f.round())
        self.assertTrue(state['resources_cleaned'])
        self.assertTrue(state['acceptance_passed'])
        self.assertFalse(state['branches']['retry_exhausted'])
        self.assertFalse(state['branches']['final_cleanup'])
        self.assertEqual(state['cleanup']['phase'], 'closed')

    def test_required_native_branch_must_be_true_even_after_complete_cleanup(self):
        for branch in ('native_cleanup', 'cooldown', 'retry', 'b_unchanged', 'dns_cutover'):
            for value in (None, False, 1, 'true'):
                with self.subTest(branch=branch, value=value):
                    self.f = RoundFixture()
                    original = self.f.port

                    def port(operation, request):
                        reply = original(operation, request)
                        if operation == 'native-step':
                            reply['branches'][branch] = value
                        return reply

                    self.f.port = port
                    state = self.finish(self.f.round())
                    self.assertTrue(state['resources_cleaned'])
                    self.assertFalse(state['acceptance_passed'])

    def test_lost_bootstrap_response_reconciles_never_recreates(self):
        self.f.fail_operation = 'bootstrap'
        driver = self.f.round(); driver.step()
        first = driver.state['first_error']
        driver = self.f.round(copy.deepcopy(driver.state))
        state = self.finish(driver)
        self.assertEqual(first, state['first_error']); self.assertFalse(state['acceptance_passed'])
        self.assertEqual([e[0] for e in self.f.events].count('bootstrap'), 1)
        pending = next(e[1]['pending'] for e in self.f.events if e[0] == 'reconcile')
        self.assertEqual(pending['operation'], 'bootstrap')
        self.assertNotIn('private failure', str(self.f.journal))

    def test_stop_and_execution_deadline_never_start_another_native_step(self):
        for expired in (False, True):
            with self.subTest(expired=expired):
                self.f = RoundFixture(); driver = self.f.round(); driver.step()
                if expired: self.f.now += 6000
                driver.step(stop=not expired)
                state = self.finish(driver)
                self.assertFalse(state['acceptance_passed'])
                self.assertNotIn('native-step', [e[0] for e in self.f.events])

    def test_resume_pending_step_stops_instead_of_redispatch(self):
        driver = self.f.round(); driver.step()
        driver.state['pending'] = {'operation': 'native-step', 'request_id': 'old'}
        state = self.finish(self.f.round(driver.state))
        self.assertEqual(state['first_error'], 'round_uncertain_dispatch')
        self.assertNotIn('native-step', [e[0] for e in self.f.events])

    def test_dns_lost_delete_response_is_inspected_without_repeat(self):
        aux = self.f.aux(); self.f.fail_operation = 'dns-delete'
        result = aux('dns-and-private-cleanup'); self.assertFalse(result['dns_absent_twice'])
        self.f.fail_operation = None; self.f.now += 10
        aux = self.f.aux(); result = aux('dns-and-private-cleanup')
        self.assertFalse(result['dns_absent_twice'])
        self.assertEqual([e[0] for e in self.f.events].count('dns-delete'), 3)
        self.assertNotIn('private-remove', [e[0] for e in self.f.events])

    def test_wrong_identity_unknown_and_failed_reads_do_not_free_controller(self):
        for failure in ('dns-inspect', 'intents-inspect'):
            self.f = RoundFixture(); self.f.fail_operation = failure
            driver = self.f.round()
            for _ in range(15): driver.step(); self.f.now += 10
            self.assertFalse(driver.state['resources_cleaned'])
            self.assertNotIn('control-delete', [e[0] for e in self.f.events])
        self.f = RoundFixture(); self.f.mismatch = True
        with self.assertRaises(TransportError): self.f.aux()('restore-faults')

    def test_404_checks_use_completion_time_and_reset_after_failed_read(self):
        aux = self.f.aux(); pin = self.f.scope['records'][0]
        self.f.pins[digest(pin)] = False
        original = self.f.io
        def slow(op, pin):
            self.f.now += 20
            return original(op, pin)
        aux.io = slow
        self.assertFalse(aux.absent('dns', pin))
        self.f.now += 9
        self.assertFalse(aux.absent('dns', pin))
        self.f.now += 1
        self.assertTrue(aux.absent('dns', pin))

    def test_scope_changes_never_reuse_cleanup_journal(self):
        self.f.aux().persist(); self.f.scope['control']['external_id'] = '700'
        with self.assertRaisesRegex(TransportError, 'resume_changed'): self.f.aux()

    def test_auxiliary_does_not_accept_other_domains_paths_or_fault_on_b(self):
        for kind in ('domain', 'path', 'fault'):
            self.f = RoundFixture()
            if kind == 'domain': self.f.scope['records'][0]['name'] = 'existing.example.com'
            if kind == 'path': self.f.scope['private_files'][0]['path'] = '/root/.ssh/authorized_keys'
            if kind == 'fault': self.f.scope['faults'][0]['role'] = 'source-b'
            with self.assertRaises(TransportError): self.f.aux()

    def test_evidence_export_exception_does_not_block_dns_teardown(self):
        self.f.fail_operation = 'evidence-export-readback'
        state = self.finish(self.f.round())
        self.assertTrue(state['resources_cleaned']); self.assertFalse(state['acceptance_passed'])
        self.assertNotIn('must-not-export', str(self.f.journal))


if __name__ == '__main__':
    unittest.main()
