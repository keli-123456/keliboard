"""Synthetic regression only: never creates processes, sockets, or namespaces."""
import contextlib
import copy
import json
from pathlib import Path
import socket
import subprocess
import sys
import unittest
from unittest.mock import Mock, mock_open, patch

import runtime_diagnostics as d

SECRET = 'SYNTHETIC_PRIVATE_CONTENT_NEVER_EXPORT'


def isolation():
    return {'independent_network_namespace': True, 'private_pid_namespace': True, 'only_loopback': True,
            'child_non_root': True, 'no_new_privileges': True, 'external_routes': 0, 'child_capabilities': 0}


def full_iputils():
    return {'ok': True, 'first_error': None, 'secondary_errors': [], 'cases': [dict(case=name, ok=True, tests=1, skipped=0, failures=0, errors=0,
            kernel_echo_requests=n, expected_packets=n, packet_counter_matches=True, no_remaining_children=True)
            for name, n in zip(d.IPUTILS_CASES, (1, 1, 4, 3, 1, 0))],
            'cleanup': dict(temporary_directory_removed=True, owned_firewall_removed=True, no_remaining_children=True),
            'isolation': isolation()}


def full_runtime():
    return {'ok': True, 'doctor_listener': True, 'post_probe_doctor': True, 'tcp_exact_echo': True,
        'no_remaining_children': True, 'listeners_closed': True, 'tcp_attempts': 1, 'udp_attempts': 1,
        'udp_exact_datagrams': 2, 'protocol_retries': 0, 'children_reaped': 8, 'stderr_error_lines': 0,
        'leftover_processes_killed': 0,
        'socket_checks': [{'ok': True, 'phase': phase, 'checked_sockets': 4,
                           'private_outbound_udp': [], 'violations': []} for phase in ('before_probe', 'after_probe')],
        'build': {'version': '1.2.3', 'embedded_core': True, 'hy2_ech': True, 'hy2_brutal': True,
                  'allocator': 'jemalloc', 'sing_box_version': '1.14.4'}}


def row(protocol='udp', family=4, local='0.0.0.0', port=45000, remote='0.0.0.0', peer=0, inode=42, state='07'):
    return {'protocol': protocol, 'family': family, 'local_address': local, 'local_port': port,
            'remote_address': remote, 'remote_port': peer, 'inode': inode, 'state': state}


class MockOnly(unittest.TestCase):
    def setUp(self):
        self.stack = contextlib.ExitStack()
        self.addCleanup(self.stack.close)
        for name in ('Popen', 'run', 'call', 'check_call', 'check_output'):
            self.stack.enter_context(patch.object(subprocess, name, side_effect=AssertionError('dispatch forbidden')))
        for name in ('socket', 'create_connection', 'getaddrinfo'):
            self.stack.enter_context(patch.object(socket, name, side_effect=AssertionError('network forbidden')))


class ErrorEvidenceTests(MockOnly):
    def test_namespace_diagnostic_schema_is_not_lost_in_projection(self):
        import iputils_namespace as ns
        self.assertLessEqual(ns.STAGES, d.STAGES)
        self.assertLessEqual(ns.REASONS, d.REASONS)
        self.assertLessEqual(ns.ERROR_CLASSES, d.CLASSES)

    def test_bootstrap_error_survives_child_namespace_and_outer_projection(self):
        import iputils_namespace as ns
        value = {'case': 'echo', 'ok': False, 'tests': 0, 'errors': 1, 'failures': 0, 'skipped': 0,
            'expected_packets': 0, 'measurements': [],
            'first_error': {'stage': 'child_import', 'reason': 'child_import_failed', 'error_class': 'ModuleNotFoundError',
                'frames': [{'file': 'test_entry_probe_iputils.py', 'line': 1}], 'message': SECRET}}
        child, invalid = ns.child_result(json.dumps(value).encode(), 'echo', 1)
        self.assertIsNone(invalid)
        namespace = ns.new_report()
        namespace['cases'].append(child)
        ns.record_child_failure(namespace, child, 1)
        ns.record_error(namespace, ns.NamespaceFailure('child_cleanup_failed', stage='cleanup_child_close'), 'cleanup_child_close')
        projected = d.project_iputils(namespace)
        outer = {}
        d.inherit_errors(outer, projected, 'iputils')
        self.assertFalse(projected['ok'])
        self.assertEqual(outer['first_error']['reason'], 'child_import_failed')
        self.assertEqual(outer['first_error']['error_class'], 'ModuleNotFoundError')
        self.assertEqual(outer['first_error']['exit_code'], 1)
        self.assertEqual(outer['first_error']['frames'], [{'file': 'test_entry_probe_iputils.py', 'line': 1}])
        self.assertNotIn(SECRET, json.dumps(outer))

    def test_duplicate_or_nonfinite_json_is_not_accepted_as_report(self):
        for raw in ('{"ok":true,"ok":false}', '{"value":NaN}', '[]'):
            with self.subTest(raw=raw), self.assertRaises(d.DiagnosticError):
                d.load_object(raw)

    def test_cleanup_and_projection_never_replace_first_error(self):
        value = {}
        d.remember_error(value, {'reason': 'non_loopback_bind_forbidden', 'error_class': 'Failure'}, 'socket_after_probe')
        original = copy.deepcopy(value['first_error'])
        d.remember_error(value, {'reason': 'six_iputils_cases_required'}, 'iputils_projection')
        d.remember_error(value, OSError(SECRET), 'directory_cleanup')
        self.assertEqual(value['first_error'], original)
        self.assertEqual(value['error_code'], 'non_loopback_bind_forbidden')
        self.assertEqual(len(value['secondary_errors']), 2)
        self.assertNotIn(SECRET, json.dumps(value))

    def test_error_fields_are_allowlisted_and_bounded(self):
        value = {'stage': SECRET, 'reason': SECRET, 'error_class': SECRET, 'exit_code': -9,
                 'errno': 13, 'timed_out': True, 'command': SECRET, 'raw_log': SECRET,
                 'frames': [{'file': '/private/' + SECRET, 'line': 20},
                            {'file': 'iputils_namespace.py', 'line': 160}]}
        safe = d.project_error(value, 'iputils')
        self.assertEqual(safe['reason'], 'unclassified_failure')
        self.assertEqual(safe['stage'], 'iputils')
        self.assertEqual(safe['exit_code'], -9)
        self.assertEqual(safe['frames'], [{'file': 'iputils_namespace.py', 'line': 160}])
        self.assertNotIn(SECRET, json.dumps(safe))

    def test_malformed_error_values_do_not_mask_primary(self):
        safe = d.project_error({'stage': [], 'reason': {}, 'error_class': [], 'frames': [{'file': [], 'line': 1}]})
        self.assertEqual(safe['reason'], 'unclassified_failure')
        self.assertEqual(safe['error_class'], 'OtherError')
        self.assertEqual(safe['frames'], [])

    def test_secondary_error_budget_keeps_first(self):
        value = {}
        d.remember_error(value, {'reason': 'child_timeout'}, 'runtime')
        for _ in range(20):
            d.remember_error(value, {'reason': 'child_cleanup_failed'}, 'cleanup')
        self.assertEqual(value['first_error']['reason'], 'child_timeout')
        self.assertEqual(len(value['secondary_errors']), d.MAX_ERRORS)
        self.assertEqual(value['secondary_errors_dropped'], 12)

    def test_missing_cases_preserves_upstream_tool_error(self):
        raw = {'ok': False, 'reason': 'namespace_tool_failed', 'error_class': 'RuntimeError', 'exit_code': 2,
               'error_frames': [{'file': 'iputils_namespace.py', 'line': 37}], 'raw_log': SECRET}
        safe = d.project_iputils(raw)
        self.assertFalse(safe['ok'])
        self.assertEqual(safe['first_error']['reason'], 'namespace_tool_failed')
        self.assertEqual(safe['first_error']['exit_code'], 2)
        self.assertIn('six_iputils_cases_required', safe['validation_errors'])
        self.assertNotIn(SECRET, json.dumps(safe))

    def test_partial_cases_retained_without_inventing_success(self):
        raw = full_iputils()
        raw.update(ok=False, cases=raw['cases'][:2], current_case='four_no_reply_targets',
                   first_error={'stage': 'case_decode', 'reason': 'child_json_invalid', 'error_class': 'JSONDecodeError', 'exit_code': 1})
        safe = d.project_iputils(raw)
        self.assertEqual(len(safe['cases']), 2)
        self.assertTrue(all(item['ok'] for item in safe['cases']))
        self.assertFalse(safe['cases_complete'])
        self.assertEqual(safe['first_error']['stage'], 'case_decode')
        self.assertEqual(safe['error_code'], 'child_json_invalid')
        self.assertEqual(safe['current_case'], 'four_no_reply_targets')

    def test_six_case_success_needs_cleanup_isolation_and_exact_counters(self):
        self.assertTrue(d.project_iputils(full_iputils())['ok'])
        for change in ('skip', 'count', 'cleanup', 'isolation', 'bool_count', 'upstream_error'):
            raw = full_iputils()
            if change == 'skip': raw['cases'][0]['skipped'] = 1
            elif change == 'count': raw['cases'][0]['kernel_echo_requests'] = 2
            elif change == 'cleanup': raw['cleanup']['owned_firewall_removed'] = False
            elif change == 'isolation': raw['isolation']['external_routes'] = 1
            elif change == 'bool_count': raw['cases'][0]['kernel_echo_requests'] = True
            else: raw['first_error'] = {'reason': 'namespace_tool_failed'}
            with self.subTest(change=change):
                self.assertFalse(d.project_iputils(raw)['ok'])

    def test_bad_or_reordered_cases_and_unknown_reports_are_not_success(self):
        for raw in (None, [], {'ok': True, 'cases': {}}, {'ok': True, 'cases': [None]}):
            self.assertFalse(d.project_iputils(raw)['ok'])
        raw = full_iputils()
        raw['cases'].reverse()
        self.assertFalse(d.project_iputils(raw)['ok'])


class RepeatedIputilsProjectionTests(MockOnly):
    def cycle(self, value, repeat=4):
        first = d.project_iputils(value)
        current = json.loads(json.dumps(first, allow_nan=False))
        for _ in range(repeat):
            current = d.project_iputils(current)
            self.assertEqual(current, first)
            self.assertNotIn(SECRET, json.dumps(current, allow_nan=False))
        return first

    def test_success_keeps_minimum_proofs_and_is_idempotent(self):
        raw = full_iputils()
        before = copy.deepcopy(raw)
        value = self.cycle(raw)
        self.assertTrue(value['ok'])
        self.assertEqual(value['reported_case_count'], 6)
        self.assertEqual(value['cleanup'], raw['cleanup'])
        self.assertEqual(value['isolation'], raw['isolation'])
        self.assertTrue(all(row['packet_counter_matches'] and row['no_remaining_children'] for row in value['cases']))
        self.assertEqual(raw, before)

    def test_real_producer_null_first_error_is_no_failure_but_alias_errors_still_veto(self):
        value = full_iputils()
        self.assertIsNone(value['first_error'])
        self.assertTrue(self.cycle(value)['ok'])
        value['error_code'] = 'child_timeout'
        self.assertFalse(self.cycle(value)['ok'])

    def test_every_boolean_proof_remains_required_after_serialization(self):
        for target, keys in [('case', ('packet_counter_matches', 'no_remaining_children')),
                             ('cleanup', d.IPUTILS_CLEANUP_FLAGS), ('isolation', d.ISOLATION_FLAGS)]:
            for key in keys:
                for invalid in [None, False, 0, 1, 'true', [], {}]:
                    value = full_iputils()
                    field = value['cases'][0] if target == 'case' else value[target]
                    field[key] = invalid
                    with self.subTest(target=target, key=key, invalid=type(invalid).__name__):
                        self.assertFalse(self.cycle(value)['ok'])
                value = full_iputils()
                field = value['cases'][0] if target == 'case' else value[target]
                del field[key]
                self.assertFalse(self.cycle(value)['ok'])

    def test_numeric_isolation_proofs_do_not_accept_boolean_zero(self):
        for key in ['external_routes', 'child_capabilities']:
            for invalid in [True, False, None, '0', 1, -1, 0.0, [], {}]:
                value = full_iputils()
                value['isolation'][key] = invalid
                with self.subTest(key=key, invalid=type(invalid).__name__):
                    self.assertFalse(self.cycle(value)['ok'])

    def test_counter_mismatch_missing_counts_and_skips_stay_failure(self):
        for key in ['tests', 'skipped', 'errors', 'failures', 'kernel_echo_requests', 'expected_packets']:
            for invalid in [True, None, '0', 0.0, -1, 5]:
                value = full_iputils()
                value['cases'][0][key] = invalid
                with self.subTest(key=key, invalid=type(invalid).__name__):
                    self.assertFalse(self.cycle(value)['ok'])
        value = full_iputils()
        value['cases'][0]['kernel_echo_requests'] = 2
        self.assertFalse(self.cycle(value)['ok'])

    def test_matching_packet_counters_must_also_match_the_case_budget(self):
        for index, case in enumerate(d.IPUTILS_CASES):
            for invalid in range(5):
                if invalid in d.IPUTILS_PACKET_BUDGETS[case]:
                    continue
                value = full_iputils()
                value['cases'][index].update(kernel_echo_requests=invalid, expected_packets=invalid)
                with self.subTest(case=case, count=invalid):
                    projected = self.cycle(value)
                    self.assertFalse(projected['ok'])
                    self.assertEqual(projected['first_error']['reason'], 'iputils_packet_count_invalid')
        for count in range(1, 5):
            value = full_iputils()
            value['cases'][3].update(kernel_echo_requests=count, expected_packets=count)
            self.assertTrue(self.cycle(value)['ok'])
        value = full_iputils()
        value['cases'][0]['skipped'] = 1
        self.assertFalse(self.cycle(value)['ok'])

    def test_cached_success_and_already_projected_markers_cannot_replace_proof(self):
        value = full_iputils()
        for row in value['cases']:
            row.pop('packet_counter_matches')
            row.pop('no_remaining_children')
        value.pop('cleanup')
        value.pop('isolation')
        value.update(cleanup_verified=True, isolation_verified=True, cases_complete=True,
                     already_projected=True, projection_schema=1)
        projected = self.cycle(value)
        self.assertFalse(projected['ok'])
        self.assertFalse(projected['cleanup_verified'])
        self.assertFalse(projected['isolation_verified'])

    def test_filtering_extra_foreign_cases_never_repairs_completeness(self):
        value = full_iputils()
        value['cases'].extend([{'case': SECRET, 'ok': True}, {'case': 'rust_echo', 'ok': True}])
        projected = self.cycle(value)
        self.assertEqual(len(projected['cases']), 6)
        self.assertEqual(projected['reported_case_count'], 8)
        self.assertFalse(projected['cases_complete'])
        self.assertFalse(projected['ok'])

    def test_malformed_original_count_cannot_be_normalized_into_completeness(self):
        for number in [None, True, '6', [], {}, -1, 5, 7]:
            value = full_iputils()
            value['reported_case_count'] = number
            with self.subTest(number=type(number).__name__):
                self.assertFalse(self.cycle(value)['ok'])

    def test_nonobjects_bad_cases_and_reordered_cases_are_stable_failures(self):
        for value in [None, [], True, {}, {'ok': True, 'cases': {}}, {'ok': True, 'cases': [None]}]:
            self.assertFalse(self.cycle(value)['ok'])
        value = full_iputils()
        value['cases'].reverse()
        self.assertFalse(self.cycle(value)['ok'])

    def test_first_error_and_bounded_secondary_history_survive_reprojection(self):
        value = full_iputils()
        value.update(ok=False, first_error={'reason': 'child_import_failed', 'stage': 'child_import',
            'error_class': 'PermissionError', 'errno': 13, 'message': SECRET,
            'frames': [{'file': 'test_entry_probe_iputils.py', 'line': 203}]},
            secondary_errors=[{'reason': 'child_cleanup_failed', 'stage': 'process_cleanup'}])
        value['cleanup']['temporary_directory_removed'] = False
        projected = self.cycle(value)
        self.assertEqual(projected['first_error']['reason'], 'child_import_failed')
        self.assertEqual(projected['first_error']['errno'], 13)
        self.assertTrue(any(item['reason'] == 'child_cleanup_failed' for item in projected['secondary_errors']))
        self.assertLessEqual(len(projected['secondary_errors']), d.MAX_ERRORS)

    def test_duplicate_error_summaries_are_not_new_error_occurrences(self):
        value = full_iputils()
        error = {'reason': 'child_timeout', 'stage': 'child_wait', 'error_class': 'TimeoutExpired'}
        value.update(ok=False, first_error=error, secondary_errors=[error, error])
        value['cases'][0].update(ok=False, first_error=error, secondary_errors=[error])
        projected = self.cycle(value)
        self.assertEqual(projected['first_error']['reason'], 'child_timeout')
        self.assertNotIn(error, projected.get('secondary_errors', []))

    def test_malformed_diagnostics_never_allow_success(self):
        for bad in [[], True, SECRET]:
            value = full_iputils()
            value['first_error'] = bad
            self.assertFalse(self.cycle(value)['ok'])
            value = full_iputils()
            value['cases'][0]['first_error'] = bad
            self.assertFalse(self.cycle(value)['ok'])
        for bad in [None, {}, [None], [SECRET]]:
            value = full_iputils()
            value['secondary_errors'] = bad
            self.assertFalse(self.cycle(value)['ok'])

    def test_error_list_is_bounded_without_replacing_first(self):
        value = full_iputils()
        value.update(ok=False, first_error={'reason': 'child_timeout', 'stage': 'child_wait'},
            secondary_errors=[{'reason': 'child_cleanup_failed', 'stage': 'process_cleanup',
                               'frames': [{'file': 'iputils_namespace.py', 'line': n}]} for n in range(1, 31)])
        projected = self.cycle(value)
        self.assertEqual(projected['first_error']['reason'], 'child_timeout')
        self.assertEqual(len(projected['secondary_errors']), d.MAX_ERRORS)
        self.assertTrue(projected['secondary_errors_truncated'])

    def test_truncated_or_mistyped_error_metadata_cannot_claim_clean_success(self):
        for invalid in [True, None, 0, 1, 'true', [], {}]:
            for target in ['report', 'case']:
                value = full_iputils()
                field = value if target == 'report' else value['cases'][0]
                field['secondary_errors_truncated'] = invalid
                self.assertFalse(self.cycle(value)['ok'])
        value = full_iputils()
        value['secondary_errors_truncated'] = False
        self.assertTrue(self.cycle(value)['ok'])

    def test_dropped_error_counts_are_typed_retained_and_cannot_heal(self):
        for dropped in [1, 8, None, True, -1, '1', [], {}]:
            value = full_iputils()
            value['secondary_errors_dropped'] = dropped
            projected = self.cycle(value)
            self.assertFalse(projected['ok'])
            self.assertIn('secondary_errors_dropped', projected)

    def test_private_fields_in_proof_maps_and_case_rows_are_removed(self):
        value = full_iputils()
        value.update(token=SECRET, stdout=SECRET, configuration={'private_key': SECRET})
        value['cleanup']['command'] = SECRET
        value['isolation']['source_ip'] = SECRET
        value['cases'][0].update(raw_log=SECRET, user=SECRET)
        projected = self.cycle(value)
        self.assertTrue(projected['ok'])
        self.assertNotIn(SECRET, json.dumps(projected))


class RuntimeProjectionTests(MockOnly):
    def cycle(self, value):
        first = d.project_runtime(value)
        current = first
        for _ in range(4):
            current = d.project_runtime(json.loads(json.dumps(current)))
            self.assertEqual(current, first)
            self.assertNotIn(SECRET, json.dumps(current))
        return first

    def test_complete_runtime_and_null_diagnostics_are_idempotent_success(self):
        value = full_runtime()
        value.update(first_error=None, secondary_errors=[])
        self.assertTrue(self.cycle(value)['ok'])

    def test_malformed_or_dropped_runtime_diagnostics_never_pass(self):
        for key, bad in [('first_error', []), ('first_error', True), ('secondary_errors', None),
                         ('secondary_errors', [None]), ('secondary_errors_truncated', True),
                         ('secondary_errors_dropped', 1), ('error_code', 'child_timeout')]:
            value = full_runtime()
            value[key] = bad
            self.assertFalse(self.cycle(value)['ok'])

    def test_private_fields_are_removed_and_first_error_is_preserved(self):
        value = full_runtime()
        value.update(ok=False, stdout=SECRET, configuration={'token': SECRET},
                     first_error={'reason': 'child_import_failed', 'stage': 'child_import',
                                  'error_class': 'PermissionError', 'errno': 13, 'message': SECRET})
        self.assertEqual(self.cycle(value)['first_error']['errno'], 13)

    def test_invalid_counters_build_and_socket_evidence_stay_failed(self):
        for key, bad in [('tcp_attempts', 0), ('udp_attempts', 2), ('udp_exact_datagrams', True),
                         ('protocol_retries', 1), ('build', {}), ('build', []),
                         ('socket_checks', []), ('socket_checks', [None]), ('listeners_closed', 1)]:
            value = full_runtime()
            value[key] = bad
            self.assertFalse(self.cycle(value)['ok'])
        for bad in [None, [], {}, True]:
            self.assertFalse(self.cycle(bad)['ok'])

class SocketScopeTests(MockOnly):
    def scope(self, rows, **changes):
        options = dict(listeners={('udp', 24443), ('tcp', 24444)}, owners={'node': {42}},
                       isolation=isolation(), phase='after_probe', ephemeral_range=(32768, 60999))
        options.update(changes)
        return d.socket_scope(rows, **options)

    def test_owned_ephemeral_udp_allowed_only_after_probe_in_private_namespace(self):
        safe = self.scope([row()])
        self.assertTrue(safe['ok'])
        self.assertEqual(safe['private_outbound_udp'][0]['owner'], 'node')
        self.assertEqual(safe['private_outbound_udp'][0]['inode'], 42)
        self.assertFalse(self.scope([row()], phase='before_probe')['ok'])

    def test_wildcard_known_listener_is_never_an_outbound_exception(self):
        self.assertFalse(self.scope([row(port=24443)])['ok'])
        self.assertFalse(self.scope([row(port=45000)], listeners={('udp', 45000)})['ok'])

    def test_udp_exception_does_not_apply_to_tcp(self):
        self.assertFalse(self.scope([row(protocol='tcp', state='0A')])['ok'])

    def test_no_unknown_shared_or_reused_socket_ownership(self):
        for owners in ({}, {'node': {43}}, {'node': {42}, 'client': {42}}):
            self.assertFalse(self.scope([row()], owners=owners)['ok'])

    def test_no_external_or_unverified_namespace_exception(self):
        for key, value in [('only_loopback', False), ('external_routes', 1), ('private_pid_namespace', False),
                           ('independent_network_namespace', False), ('child_capabilities', 1),
                           ('child_non_root', False), ('no_new_privileges', False), ('external_routes', False)]:
            proof = isolation()
            proof[key] = value
            with self.subTest(key=key):
                self.assertFalse(self.scope([row()], isolation=proof)['ok'])

    def test_public_multicast_or_non_ephemeral_udp_rejected(self):
        for sample in (row(local='192.0.2.1'), row(local='224.0.0.1'), row(port=2000),
                       row(remote='192.0.2.1', peer=53, state='01'), row(remote='127.0.0.1', peer=53, state='07')):
            self.assertFalse(self.scope([sample])['ok'])

    def test_connected_loopback_and_ipv6_private_udp(self):
        self.assertTrue(self.scope([row(remote='127.0.0.1', peer=45001, state='01')])['ok'])
        self.assertTrue(self.scope([row(family=6, local='::', remote='::')])['ok'])
        self.assertFalse(self.scope([row(family=6, local='::', remote='2001:db8::1', peer=53, state='01')])['ok'])

    def test_loopback_protocol_listeners_and_timewait_are_not_wildcard_listeners(self):
        values = [row(local='127.0.0.1', port=24443), row(protocol='tcp', local='127.0.0.1', state='0A', port=24444),
                  row(protocol='tcp', state='06', inode=0)]
        safe = self.scope(values, phase='before_probe')
        self.assertTrue(safe['ok'])
        self.assertEqual(safe['checked_sockets'], 2)

    def test_closed_check_covers_fixed_listeners_and_ephemeral_inodes(self):
        d.closed_sockets([], listeners={('udp', 24443)}, tracked_inodes={42})
        for sample in (row(port=24443), row(inode=42)):
            with self.assertRaises(d.DiagnosticError):
                d.closed_sockets([sample], listeners={('udp', 24443)}, tracked_inodes={42})

    def test_socket_table_decoder_tracks_inode_state_and_both_families(self):
        header = 'sl local_address rem_address st tx_queue rx_queue tr tm retr uid timeout inode\n'
        record = '0: 0100007F:5F7B 00000000:0000 07 00000000:00000000 00:00000000 00000000 61113 0 42\n'
        data = d.parse_socket_table(header + record, 'udp')[0]
        self.assertEqual((data['local_address'], data['inode'], data['local_port']), ('127.0.0.1', 42, 24443))
        ipv6 = record.replace('0100007F', '00000000000000000000000001000000').replace('00000000:0000', '00000000000000000000000000000000:0000')
        self.assertEqual(d.parse_socket_table(header + ipv6, 'udp6')[0]['local_address'], '::1')

    def test_malformed_socket_rows_fail_closed(self):
        for raw in ('header\ntruncated\n', 'header\n0: nope\n'):
            with self.assertRaises(d.DiagnosticError):
                d.parse_socket_table(raw, 'udp')
        with self.assertRaises(d.DiagnosticError):
            self.scope([row()], ephemeral_range=(0, 65535))

    def test_process_owner_identity_rechecked_after_fd_snapshot(self):
        fake_fd = Mock()
        fake_fd.name = '7'
        with patch.object(d, 'process_start_ticks', side_effect=[99, 100]), \
                patch.object(d.Path, 'iterdir', return_value=[fake_fd]), patch.object(d.os, 'readlink', return_value='socket:[42]'):
            with self.assertRaisesRegex(d.DiagnosticError, 'socket_owner_changed'):
                d.process_socket_inodes(20, 99)

    def test_fd_race_does_not_invent_socket_ownership(self):
        with patch.object(d, 'process_start_ticks', return_value=99), \
                patch.object(d.Path, 'iterdir', return_value=[Path('synthetic-fd')]), \
                patch.object(d.os, 'readlink', side_effect=FileNotFoundError):
            self.assertEqual(d.process_socket_inodes(20, 99), set())


if __name__ == '__main__':
    unittest.main()
