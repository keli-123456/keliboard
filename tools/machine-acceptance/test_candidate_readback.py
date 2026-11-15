"""Existing-task native command routing, with HTTP faked and SQLite in memory only."""
import json
import os
from pathlib import Path
import shutil
import subprocess
from types import SimpleNamespace
import unittest
from unittest.mock import Mock

import candidate_readback as readback


ROOT = Path(__file__).resolve().parents[2]
PHP = os.environ.get('PHP_BINARY') or shutil.which('php')
RUN = 'keli-accept-20990101-candidate-offline'
TASK = '10000000-0000-4000-8000-000000000001'


class CandidateRoutingTests(unittest.TestCase):
    def driver(self):
        return SimpleNamespace(campaign=SimpleNamespace(RUN=RUN), tasks={'candidate': {'id': TASK,
            'label': RUN+'-candidate', 'external_id': '123', 'dispatched_at': 2000000000,
            'provider_created_at': '2033-05-18T03:33:20Z'}}, command=Mock(return_value={
                'ok': True, 'candidate_process_only': True, 'candidate_task_id': TASK,
                'native_phase_advanced': False, 'dns_cutover_dispatched': False, 'tasks': []}))

    def test_only_explicit_native_reference_is_sent_without_create_or_dns_advance(self):
        driver = self.driver()
        result = readback.process(driver, TASK)
        self.assertTrue(result['candidate_process_only'])
        driver.command.assert_called_once_with('native-advance', TASK)

    def test_completed_install_can_enter_bounded_sampling_even_if_ipv4_is_not_yet_reachable(self):
        for status in ['awaiting_acceptance', 'waiting_ipv4_probe']:
            self.assertTrue(readback.ready_for_sampling({'status':status,'bootstrap_state':'succeeded'}))
        for status in ['waiting_provider','waiting_initialization','waiting_agent','needs_review','failed']:
            self.assertFalse(readback.ready_for_sampling({'status':status,'bootstrap_state':'succeeded'}))
        self.assertFalse(readback.ready_for_sampling({'status':'waiting_ipv4_probe','bootstrap_state':None}))
        self.assertFalse(readback.ready_for_sampling({'status':'awaiting_acceptance','bootstrap_state':'failed'}))
        self.assertFalse(readback.ready_for_sampling({'status':[],'bootstrap_state':'succeeded'}))

    def test_missing_original_identity_never_dispatches_a_command(self):
        for changes in [{'external_id': None}, {'dispatched_at': None}, {'provider_created_at': None},
                        {'id': 'foreign'}, {'label': RUN+'-source'}, {'external_id':True}, {'dispatched_at':True},
                        {'provider_created_at':True}]:
            driver = self.driver()
            driver.tasks['candidate'].update(changes)
            with self.assertRaises(readback.Refused): readback.process(driver, TASK)
            driver.command.assert_not_called()
        for task_id in ['private-sentinel', None, 1, True]:
            driver = self.driver()
            with self.assertRaises(readback.Refused): readback.process(driver, task_id)
            driver.command.assert_not_called()

    def test_fallback_to_full_advance_or_missing_proof_is_refused(self):
        for changes in [{'candidate_process_only': False}, {'candidate_task_id': 'foreign'},
                        {'native_phase_advanced': True}, {'dns_cutover_dispatched': True}]:
            driver = self.driver()
            driver.command.return_value.update(changes)
            with self.assertRaises(readback.Refused): readback.process(driver, TASK)
            driver.command.assert_called_once()


@unittest.skipUnless(PHP and (ROOT/'vendor/autoload.php').is_file(), 'local PHP and locked vendor required')
class CandidateDatabaseTests(unittest.TestCase):
    def probe(self, mode):
        result = subprocess.run([PHP, str(ROOT/'tests/Fixtures/MachineAcceptance/candidate_readback_probe.php'), mode],
            capture_output=True, timeout=30)
        self.assertEqual(result.returncode, 0, (result.stdout+result.stderr)[:1000])
        value = json.loads(result.stdout)
        self.assertTrue(value['ok'])
        self.assertEqual(value['create_posts_total'], 1)
        self.assertEqual(value['dns_writes'], 0)
        self.assertEqual(value['verifications'], 0)
        self.assertEqual(value['runtime_rows'], 0)
        self.assertEqual(value['execution_status'], 'running')
        if mode != 'forbidden-op':
            self.assertLessEqual(set(value['operations_after_route']), {'native_task', 'context'})
        return value

    def test_legacy_source_mode_reproduces_masked_internal_failure_before_candidate_read(self):
        value = self.probe('legacy')
        self.assertEqual(value['task_status'], 'needs_review')
        self.assertEqual(value['task_reason'], 'internal_failure')
        self.assertEqual(value['task_diagnostic']['cause'], 'isolated_manual_cli_required')
        self.assertEqual(value['task_diagnostic']['phase'], 'admission')
        self.assertEqual(value['http_reads_after_route'], 0)

    def test_fixed_route_waits_for_bootstrap_without_entering_native_advance(self):
        value = self.probe('fixed')
        self.assertEqual(value['task_status'], 'waiting_initialization')
        self.assertTrue(value['result']['candidate_process_only'])
        self.assertEqual(value['http_reads_after_route'], 1)
        self.assertIsNone(value['task_diagnostic'])

    def test_user_callback_and_fresh_reports_continue_only_to_awaiting_acceptance(self):
        value = self.probe('ready')
        self.assertEqual(value['task_status'], 'awaiting_acceptance')
        self.assertTrue(value['result']['callback_consumed'])
        self.assertFalse(value['result']['native_phase_advanced'])
        self.assertFalse(value['result']['dns_cutover_dispatched'])
        self.assertEqual(value['http_reads_after_route'], 1)

    def test_foreign_stopped_unreceipted_or_gate_denied_tasks_have_no_side_effect(self):
        for mode in ['missing-reference','source-mode','foreign-run','foreign-policy','foreign-admin',
                     'no-provider-id','no-dispatch','stopped','denied','forbidden-op']:
            with self.subTest(mode=mode):
                value = self.probe(mode)
                self.assertIsNotNone(value['caught'])
                self.assertEqual(value['http_reads_after_route'], 0)
                self.assertEqual(value['callback_calls'], 0)
                self.assertEqual(value['task_status'], 'waiting_initialization')


if __name__ == '__main__':
    unittest.main()
