"""Local JSON IPC regression; no child, socket, service, cloud or live authority.

Runtime/control callbacks are isolated here. The authority-free exported-harness
integration separately checks this boundary against the actual public helpers.
"""
import copy
import json
from pathlib import Path
import tempfile
from types import SimpleNamespace
import unittest

from driver_journal import DriverJournal, Rejected
import runtime_diagnostics as d
import runtime_report_projection as p
from test_runtime_diagnostics import MockOnly, SECRET, full_iputils, full_runtime


RUN = 'keli-projection-offline-20261113'


def harness():
    return SimpleNamespace(diagnostics=lambda: d, safe_iputils=d.project_iputils,
        safe_runtime=d.project_runtime,
        NODE_SHA256='1' * 64, SING_SHA256='2' * 64, LIMITATIONS=['Synthetic local JSON IPC only.'])


def report(raw=None):
    h = harness()
    return {'schema': 1, 'run_id': RUN, 'ok': True,
        'node_sha256': h.NODE_SHA256, 'sing_box_sha256': h.SING_SHA256,
        'resources': dict(cpu_usage_usec=1000, oom_kills=0, rss_samples=3, rss_sum_sampled_max_bytes=1000000),
        'cgroup_limits': dict(cpu_quota_us=25000, cpu_period_us=100000, memory_max_bytes=448 * 1024 * 1024),
        'runtime': d.project_runtime(full_runtime()), 'iputils': d.project_iputils(full_iputils() if raw is None else raw),
        'staged_inputs_verified': True, 'temporary_files_removed': True,
        'network_scope': 'fresh_private_loopback_only', 'supervisor_stderr_error_lines': 0}


class PersistedProjectionTests(MockOnly):
    def roundtrip(self, value):
        with tempfile.TemporaryDirectory(prefix='keli-projection-offline-') as directory:
            journal = DriverJournal(Path(directory).resolve(), RUN)
            journal.save('runtime-result.json', value, exclusive=True)
            saved = journal.load('runtime-result.json')
            first = p.project_report('runtime-result.json', saved, RUN, harness(), None)
            journal.save('collector-result.json', first, exclusive=True)
            second = p.project_report('runtime-result.json', journal.load('collector-result.json'), RUN, harness(), None)
            journal.save('cleanup-aggregate.json', second, exclusive=True)
            final = journal.load('cleanup-aggregate.json')
            self.assertEqual(first, final)
            return first, final

    def test_raw_namespace_to_supervisor_disk_collector_and_cleanup_stays_success(self):
        value = report()
        before = copy.deepcopy(value)
        first, final = self.roundtrip(value)
        self.assertTrue(first['ok'])
        self.assertTrue(final['ok'])
        self.assertEqual(final, first)
        self.assertEqual(final['iputils']['cleanup'], full_iputils()['cleanup'])
        self.assertEqual(final['iputils']['isolation'], full_iputils()['isolation'])
        self.assertTrue(all(row['packet_counter_matches'] and row['no_remaining_children'] for row in final['iputils']['cases']))
        self.assertEqual(value, before)

    def test_control_readmission_rechecks_persisted_proofs(self):
        first, final = self.roundtrip(report())
        self.assertTrue(d.project_iputils(first['iputils'])['ok'])
        self.assertTrue(d.project_iputils(final['iputils'])['ok'])
        final['iputils']['cleanup']['owned_firewall_removed'] = False
        self.assertFalse(d.project_iputils(final['iputils'])['ok'])

    def test_lost_proof_fields_fail_despite_cached_success_flags(self):
        for key in ['cleanup', 'isolation']:
            value = report()
            value['iputils'].pop(key)
            value['iputils'].update(ok=True, already_projected=True, cleanup_verified=True, isolation_verified=True)
            first, final = self.roundtrip(value)
            self.assertFalse(first['ok'])
            self.assertFalse(final['ok'])
            self.assertEqual(first['first_error'], final['first_error'])
        for key in ['packet_counter_matches', 'no_remaining_children']:
            value = report()
            value['iputils']['cases'][0].pop(key)
            self.assertFalse(self.roundtrip(value)[1]['ok'])

    def test_first_error_survives_persistence_reprojection_and_later_cleanup_error(self):
        raw = full_iputils()
        raw.update(ok=False, first_error={'stage': 'child_import', 'reason': 'child_import_failed',
            'error_class': 'PermissionError', 'errno': 13, 'message': SECRET,
            'frames': [{'file': 'test_entry_probe_iputils.py', 'line': 203}]})
        value = report(raw)
        d.inherit_projected_errors(value, value['iputils'], 'iputils')
        d.remember_projected_error(value, OSError(SECRET), 'directory_cleanup')
        first, final = self.roundtrip(value)
        self.assertFalse(final['ok'])
        self.assertEqual(final['first_error'], first['first_error'])
        self.assertEqual(final['first_error']['reason'], 'child_import_failed')
        self.assertEqual(final['first_error']['errno'], 13)
        self.assertNotIn(SECRET, json.dumps(final))

    def test_malformed_final_report_can_preserve_checkpoint_but_not_restore_success(self):
        with tempfile.TemporaryDirectory(prefix='keli-projection-checkpoint-') as directory:
            root = Path(directory).resolve()
            journal = DriverJournal(root, RUN)
            error = {'reason': 'child_timeout', 'stage': 'child_wait', 'error_class': 'TimeoutExpired', 'exit_code': -9}
            journal.save('runtime-first-error.json', {'schema': 1, 'run_id': RUN, 'first_error': error}, exclusive=True)
            (root / 'runtime-result.json').write_text('{truncated', encoding='ascii')
            with self.assertRaises((ValueError, Rejected)):
                journal.load('runtime-result.json')
            checkpoint = p.project_report('runtime-first-error.json', journal.load('runtime-first-error.json'), RUN, harness(), None)
            value = report()
            value.update(ok=False, first_error=checkpoint['first_error'])
            value['iputils'].pop('isolation')
            _, final = self.roundtrip(value)
            self.assertFalse(final['ok'])
            self.assertEqual(final['first_error']['reason'], 'child_timeout')
            self.assertEqual(final['first_error']['exit_code'], -9)

    def test_zero_or_other_case_invalid_packet_pairs_never_pass(self):
        for index, count in [(0, 0), (1, 0), (2, 1), (3, 0), (4, 0), (5, 1)]:
            raw = full_iputils()
            raw['cases'][index].update(kernel_echo_requests=count, expected_packets=count)
            with self.subTest(case=raw['cases'][index]['case']):
                _, final = self.roundtrip(report(raw))
                self.assertFalse(final['ok'])
                self.assertEqual(final['first_error']['reason'], 'iputils_packet_count_invalid')

    def test_missing_or_oom_resources_never_turn_into_success(self):
        for field, replacement in [('rss_samples', 0), ('oom_kills', 1), ('cpu_usage_usec', None)]:
            value = report()
            value['resources'][field] = replacement
            self.assertFalse(self.roundtrip(value)[1]['ok'])
        value = report()
        value.pop('cgroup_limits')
        self.assertFalse(self.roundtrip(value)[1]['ok'])

    def test_missing_stderr_counter_and_truncated_diagnostics_never_pass(self):
        value = report()
        value.pop('supervisor_stderr_error_lines')
        self.assertFalse(self.roundtrip(value)[1]['ok'])
        for bad in [True, None, 1, [], 'true']:
            value = report()
            value['secondary_errors_truncated'] = bad
            self.assertFalse(self.roundtrip(value)[1]['ok'])
        value = report()
        value['runtime']['first_error'] = []
        self.assertFalse(self.roundtrip(value)[1]['ok'])

    def test_private_fields_never_cross_collector_or_cleanup_boundary(self):
        raw = full_iputils()
        raw['cleanup']['raw_log'] = SECRET
        raw['isolation']['user_ip'] = SECRET
        raw['cases'][0]['environment'] = {'token': SECRET}
        value = report(raw)
        value.update(raw_output=SECRET, configuration={'private_key': SECRET})
        first, final = self.roundtrip(value)
        self.assertTrue(final['ok'])
        self.assertNotIn(SECRET, json.dumps(first))
        self.assertNotIn(SECRET, json.dumps(final))

    def test_invalid_header_unknown_name_or_foreign_identity_fail_closed(self):
        for key, bad in [('run_id', 'foreign'), ('schema', True), ('node_sha256', '3' * 64)]:
            value = report()
            value[key] = bad
            self.assertFalse(self.roundtrip(value)[1]['ok'])
        for bad in [None, [], {}, True]:
            first = p.project_report('runtime-result.json', bad, RUN, harness(), None)
            final = self.roundtrip(first)[1]
            self.assertEqual(first, final)
            self.assertFalse(final['ok'])
        safe = p.project_report('private-config.json', {'token': SECRET}, RUN, harness(), None)
        self.assertFalse(safe['ok'])
        self.assertNotIn(SECRET, json.dumps(safe))


class ControlProjectionMetadataTests(MockOnly):
    def control(self):
        flags = tuple('case_' + str(n) for n in range(5))
        c = SimpleNamespace(FLAGS=flags, PHASES={'complete'},
            safe_error=lambda value, diagnostic: diagnostic.project_error(value, 'runtime_read'),
            checked=lambda value, case, diagnostic: {'case': case, 'ok': True})
        value = report()
        for name in ['runtime', 'iputils', 'node_sha256', 'sing_box_sha256']:
            value.pop(name)
        value.update(expected=5, completed=5, skipped=0, protocol_retries=0,
                     controls=[{'case': case, 'ok': True} for case in flags],
                     namespace_reaped=True, fixture_files_removed=True, phase='complete')
        return c, value

    def cycle(self, c, value):
        first = p.project_report('control-result.json', value, RUN, harness(), c)
        result = first
        for _ in range(4):
            result = p.project_report('control-result.json', json.loads(json.dumps(result)), RUN, harness(), c)
            self.assertEqual(result, first)
        return first

    def test_null_first_error_is_valid_but_alias_and_malformed_metadata_fail(self):
        c, value = self.control()
        value['first_error'] = None
        self.assertTrue(self.cycle(c, value)['ok'])
        for changes in [{'error_code': 'child_timeout'}, {'first_error': []},
                        {'secondary_errors': None}, {'secondary_errors': [None]},
                        {'secondary_errors_truncated': True}, {'secondary_errors_truncated': 1},
                        {'secondary_errors_dropped': 1}, {'secondary_errors_dropped': True}]:
            changed = copy.deepcopy(value)
            changed.update(changes)
            self.assertFalse(self.cycle(c, changed)['ok'])

    def test_secondary_error_deduplication_does_not_erase_failure_or_first_error(self):
        c, value = self.control()
        error = {'reason': 'child_timeout', 'stage': 'child_wait'}
        value.update(ok=False, first_error=error, secondary_errors=[error] * 4)
        result = self.cycle(c, value)
        self.assertFalse(result['ok'])
        self.assertEqual(result['first_error']['reason'], 'child_timeout')
        self.assertEqual(result['secondary_errors'], [])


if __name__ == '__main__':
    unittest.main()
