import json
import copy
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import Mock

import cli_error_capture as cli


SECRET = 'private-sentinel-never-export'
PHP = os.environ.get('PHP_BINARY') or shutil.which('php')
ROOT = Path(__file__).resolve().parents[2]


def diagnostic():
    return {'schema': 1, 'category': 'php_warning', 'error_class': 'ErrorException', 'source_known': True,
            'file': 'source_fixture.php', 'line': 18, 'severity': 'warning'}


def response():
    return {'ok': False, 'command': 'adopt-source', 'error_class': 'ErrorException', 'reason': 'cli_exception',
            'cli_diagnostic': diagnostic(), 'cli_diagnostic_recorded': True}


def dns_response():
    error_class = 'App\\Services\\ServerMachine\\Dns\\DnsException'
    return {'ok': False, 'command': 'initial-dns', 'error_class': error_class, 'reason': 'provider_rejected',
            'cli_diagnostic': dict(diagnostic(), category='domain_rejection', error_class=error_class,
                file='app/Services/ServerMachine/Dns/DnsProviderClient.php', severity=None),
            'cli_diagnostic_recorded': False,
            'dns_provider_diagnostic': {'schema': 1, 'provider': 'aliyun', 'action': 'UpdateDomainRecordRemark',
                                        'http_status': 400, 'code': 'InvalidRemark.TooLong'}}


def source_response():
    value = response()
    checks = dict.fromkeys((*cli.EVIDENCE_FIELDS, 'observation_window_complete'), True)
    for key in ('threshold_reached', 'observed_failures', 'observation_window_complete'):
        checks[key] = False
    value.update(error_class='KeliSourceFixtureEvidenceRejected', reason='source_fixture_evidence_invalid_threshold_reached',
        source_fixture_controller_journal_recorded=True,
        source_fixture_evidence={'schema': 2, 'ok': False, 'reason': 'source_fixture_evidence_invalid',
            'checks': checks, 'failed_fields': ['threshold_reached', 'observed_failures', 'observation_window_complete'],
            'registration': {'observed_failures': 2, 'checked_at': 2000000000, 'evidence_current': True,
                             'threshold_reached': False, 'same_context': True},
            'observation_window': {'schema': 1, 'budget_seconds': 60, 'poll_seconds': 5, 'required_failures': 3,
                'elapsed_ms': 60000, 'stop_reason': 'deadline', 'attempts': [{
                    'elapsed_ms': 55000, 'collected_failures': 2, 'observed_failures': 2,
                    'collected_checked_at': 2000000000, 'checked_at': 2000000000,
                    'evidence_current': True, 'threshold_reached': False, 'collection_pending': False,
                    'probe_state': 'failed', 'same_context': True, 'same_sample': True}]}})
    value['cli_diagnostic'].update(category='domain_rejection', error_class=value['error_class'], severity=None)
    return value


class CliCaptureTests(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory(prefix='keli-cli-first-error-')
        self.addCleanup(temporary.cleanup)
        self.base = Path(temporary.name).resolve()
        self.ctx = SimpleNamespace(BASE=self.base, RUN='keli-accept-20990101-offline-runtime',
                                   save=Mock(side_effect=self.save), load=Mock(side_effect=self.load))

    def save(self, name, value, exclusive=False):
        path = self.base / name
        with path.open('x' if exclusive else 'w', encoding='utf-8') as stream:
            json.dump(value, stream)
        path.chmod(0o600)

    def load(self, name):
        return json.loads((self.base / name).read_text(encoding='utf-8'))

    def test_exact_safe_exception_is_preserved_and_independently_read_back(self):
        got = cli.capture_result(self.ctx, response())
        self.assertEqual(got['cli_diagnostic'], diagnostic())
        self.assertTrue(got['cli_diagnostic_preservation']['independent_local_readback_verified'])
        self.assertEqual(self.load(cli.REPORT)['diagnostic'], diagnostic())
        self.assertEqual(self.ctx.load.call_count, 1)

    def test_all_public_locations_are_accepted_but_arbitrary_paths_are_not(self):
        for file in cli.FILES:
            self.assertEqual(cli.project(dict(diagnostic(), file=file))['file'], file)
        for file in ['/root/' + SECRET, '../source_fixture.php', 'private-cli-error.log', 'C:\\private\\source_fixture.php']:
            with self.assertRaises(cli.Refused):
                cli.project(dict(diagnostic(), file=file))
        self.assertIsNone(cli.project(dict(diagnostic(), source_known=False, file=None, line=None))['line'])

    def test_malformed_and_secret_diagnostic_fields_cannot_be_saved(self):
        for changes in [{'schema': True}, {'line': True}, {'line': 1000001}, {'source_known': 1},
                        {'category': SECRET}, {'error_class': SECRET}, {'severity': None}, {'raw_log': SECRET},
                        {'file': 'private-cli-error.log'}, {'source_known': False}, {'trace': [SECRET]}]:
            with self.subTest(changes=list(changes)):
                value = response()
                value['cli_diagnostic'].update(changes)
                result = cli.capture_result(self.ctx, value)
                self.assertFalse(result['cli_diagnostic_preservation']['ok'])
                self.assertNotIn(SECRET, json.dumps(result))
                self.ctx.save.assert_not_called()

    def test_outer_exception_text_args_and_stack_are_dropped(self):
        value = dict(response(), message=SECRET, trace=[SECRET], raw_log=SECRET, path=SECRET)
        value['reason'] = SECRET
        result = cli.capture_result(self.ctx, value)
        self.assertTrue(result['cli_diagnostic_preservation']['ok'])
        self.assertNotIn(SECRET, json.dumps(result))
        self.assertNotIn(SECRET, (self.base / cli.REPORT).read_text())

    def test_storage_denial_keeps_original_exception_and_safe_reason(self):
        self.ctx.save.side_effect = PermissionError(SECRET)
        value = response()
        value.update(error_class='RuntimeException', reason='source_fixture_ownership_invalid')
        value['cli_diagnostic'].update(category='domain_rejection', error_class='RuntimeException', severity=None)
        result = cli.capture_result(self.ctx, value)
        self.assertEqual(result['reason'], value['reason'])
        self.assertEqual(result['error_class'], 'RuntimeException')
        self.assertFalse(result['cli_diagnostic_preservation']['ok'])
        self.assertNotIn(SECRET, json.dumps(result))

    def test_readback_mismatch_cannot_claim_durable_evidence(self):
        self.ctx.load.side_effect = lambda name: {'private': SECRET}
        result = cli.capture_result(self.ctx, response())
        self.assertFalse(result['cli_diagnostic_preservation']['ok'])
        self.assertNotIn(SECRET, json.dumps(result))

    def test_identical_reobservation_does_not_replace_first_error(self):
        first = cli.capture_result(self.ctx, response())
        raw = (self.base / cli.REPORT).read_bytes()
        second = cli.capture_result(self.ctx, response())
        self.assertTrue(first['cli_diagnostic_preservation']['ok'])
        self.assertTrue(second['cli_diagnostic_preservation']['ok'])
        self.assertEqual(raw, (self.base / cli.REPORT).read_bytes())

    def test_later_error_and_closed_round_cannot_overwrite_original(self):
        cli.capture_result(self.ctx, response())
        raw = (self.base / cli.REPORT).read_bytes()
        value = response()
        value['cli_diagnostic']['line'] += 1
        self.assertFalse(cli.capture_result(self.ctx, value)['cli_diagnostic_preservation']['ok'])
        self.assertEqual(raw, (self.base / cli.REPORT).read_bytes())
        (self.base / 'closed.json').write_text('{}')
        self.assertFalse(cli.capture_result(self.ctx, response())['cli_diagnostic_preservation']['ok'])
        self.assertEqual(raw, (self.base / cli.REPORT).read_bytes())

    def test_field_rejection_remains_exact_and_safe(self):
        value = response()
        checks = dict.fromkeys(cli.EVIDENCE_FIELDS, True)
        checks['runtime_nodes'] = False
        value.update(error_class='KeliSourceFixtureEvidenceRejected', reason='source_fixture_evidence_invalid_runtime_nodes',
            source_fixture_controller_journal_recorded=False,
            source_fixture_evidence={'schema': 1, 'ok': False, 'reason': 'source_fixture_evidence_invalid',
                                     'checks': checks, 'failed_fields': ['runtime_nodes']})
        value['cli_diagnostic'].update(category='domain_rejection', error_class=value['error_class'], severity=None)
        result = cli.capture_result(self.ctx, value)
        self.assertEqual(result['reason'], value['reason'])
        self.assertEqual(result['source_fixture_evidence'], value['source_fixture_evidence'])
        self.assertTrue(result['cli_diagnostic_preservation']['ok'])
        value['source_fixture_evidence']['checks'][SECRET] = False
        bad = cli.capture_result(self.ctx, value)
        self.assertFalse(bad['cli_diagnostic_preservation']['ok'])
        self.assertNotIn(SECRET, json.dumps(bad))

    def test_source_window_counts_are_preserved_with_the_exclusive_first_error(self):
        value = source_response()
        result = cli.capture_result(self.ctx, value)
        self.assertTrue(result['cli_diagnostic_preservation']['ok'])
        expected = value['source_fixture_evidence']
        self.assertEqual(result['source_fixture_evidence'], expected)
        self.assertEqual(self.load(cli.SOURCE_REPORT)['diagnostic'], expected)
        self.assertEqual(set(self.load(cli.REPORT)), {'schema', 'run_id', 'phase', 'diagnostic'})
        original = (self.base / cli.SOURCE_REPORT).read_bytes()
        value['source_fixture_evidence']['observation_window']['attempts'][0]['observed_failures'] = 1
        self.assertFalse(cli.capture_result(self.ctx, value)['cli_diagnostic_preservation']['ok'])
        self.assertEqual(original, (self.base / cli.SOURCE_REPORT).read_bytes())

    def test_source_journal_preserves_nested_schema_and_reads_legacy_without_rewriting_it(self):
        source = source_response()['source_fixture_evidence']
        owner = {'schema': 1, 'run_id': self.ctx.RUN, 'application_commit': 'c' * 40}
        self.assertEqual(cli.source_journal(dict(owner, diagnostic=source), self.ctx.RUN, 'c' * 40), source)
        legacy = {'schema': 1, 'ok': False, 'reason': source['reason'],
                  'failed_fields': ['threshold_reached', 'observed_failures'],
                  'checks': {key: value for key, value in source['checks'].items() if key in cli.EVIDENCE_FIELDS}}
        self.assertEqual(cli.source_journal(dict(legacy, **owner), self.ctx.RUN, 'c' * 40), legacy)
        for packet in [dict(owner, diagnostic=source, raw=SECRET), dict(owner, diagnostic=source, run_id='other'),
                       dict(owner, diagnostic=source, application_commit='0' * 40),
                       dict(source, run_id=self.ctx.RUN, application_commit='c' * 40, schema=1)]:
            with self.assertRaises(cli.Refused):
                cli.source_journal(packet, self.ctx.RUN, 'c' * 40)

    def test_source_window_is_bounded_typed_and_cannot_export_private_fields(self):
        for changes in [{'schema': True}, {'budget_seconds': 120}, {'poll_seconds': 1}, {'required_failures': 2},
                        {'required_failures': 11}, {'elapsed_ms': 60001}, {'elapsed_ms': True},
                        {'stop_reason': SECRET}, {'private_log': SECRET}, {'attempts': [SECRET]},
                        {'attempts': source_response()['source_fixture_evidence']['observation_window']['attempts'] * 13}]:
            with self.subTest(changes=list(changes)):
                value = source_response()
                value['source_fixture_evidence']['observation_window'].update(changes)
                bad = cli.capture_result(self.ctx, value)
                self.assertFalse(bad['cli_diagnostic_preservation']['ok'])
                self.assertNotIn(SECRET, json.dumps(bad))
                self.ctx.save.assert_not_called()
        for changes in [{'observed_failures': '3'}, {'observed_failures': True}, {'observed_failures': 1000001},
                        {'collected_checked_at': SECRET}, {'elapsed_ms': -1}, {'evidence_current': 1},
                        {'probe_state': [SECRET]}, {'source_ip': SECRET}, {'context_key': SECRET}]:
            value = source_response()
            value['source_fixture_evidence']['observation_window']['attempts'][0].update(changes)
            with self.assertRaises(cli.Refused):
                cli.fields(value['source_fixture_evidence'])

    def test_window_success_cannot_be_claimed_with_missing_or_mismatched_counts(self):
        value = source_response()['source_fixture_evidence']['observation_window']
        value.update(stop_reason='threshold_reached', elapsed_ms=25000)
        value['attempts'][0].update(elapsed_ms=25000, observed_failures=3, collected_failures=3, threshold_reached=True)
        self.assertEqual(cli.observation_window(value), value)
        for changes in [{'same_sample': False}, {'same_context': False}, {'evidence_current': False},
                        {'collection_pending': True}, {'observed_failures': 2}, {'collected_failures': 2},
                        {'probe_state': 'healthy'}, {'collected_checked_at': 1}, {'checked_at': None}]:
            bad = copy.deepcopy(value)
            bad['attempts'][0].update(changes)
            with self.assertRaises(cli.Refused):
                cli.observation_window(bad)
        for changes in [{'attempts': []}, {'elapsed_ms': 60000}]:
            with self.assertRaises(cli.Refused):
                cli.observation_window(dict(value, **changes))

    @unittest.skipUnless(PHP and (ROOT / 'vendor/autoload.php').is_file(), 'local PHP and locked vendor required')
    def test_actual_sqlite_window_failure_crosses_php_python_and_first_error_readback(self):
        result = subprocess.run([PHP, str(ROOT / 'tests/Fixtures/MachineAcceptance/source_fixture_registration.php'),
                                 'window-timeout'], capture_output=True, timeout=30)
        self.assertEqual(result.returncode, 0, (result.stdout + result.stderr)[:1000])
        value = json.loads(result.stdout)
        self.assertTrue(value['ok'])
        captured = cli.capture_result(self.ctx, value['captured_failure'])
        self.assertTrue(captured['cli_diagnostic_preservation']['ok'])
        window = self.load(cli.SOURCE_REPORT)['diagnostic']['observation_window']
        self.assertEqual(window['stop_reason'], 'deadline')
        self.assertEqual(window['required_failures'], 3)
        self.assertLessEqual(len(window['attempts']), 12)
        self.assertEqual(window['attempts'][0]['observed_failures'], 1)
        exported = cli.source_journal(value['controller_source_journal'],
            'keli-accept-20990101-source-offline', 'c' * 40)
        self.assertEqual(exported, self.load(cli.SOURCE_REPORT)['diagnostic'])

    @unittest.skipUnless(PHP, 'local PHP required')
    def test_php_and_python_source_count_schemas_agree_on_negative_fields(self):
        source = source_response()['source_fixture_evidence']
        cases = [{'kind': 'fields', 'value': source}, {'kind': 'window', 'value': source['observation_window']}]
        for changes in [{'schema': True}, {'schema': 1}, {'registration': None}, {'raw': SECRET}]:
            cases.append({'kind': 'fields', 'value': dict(source, **changes)})
        for key, changes in [('registration', {'observed_failures': True}),
                             ('registration', {'observed_failures': -1}),
                             ('registration', {'raw': SECRET}),
                             ('registration', {'same_context': 1}),
                             ('observation_window', {'required_failures': 2}),
                             ('observation_window', {'budget_seconds': True}),
                             ('observation_window', {'elapsed_ms': -1}),
                             ('observation_window', {'stop_reason': SECRET})]:
            value = copy.deepcopy(source)
            value[key].update(changes)
            cases.append({'kind': 'fields', 'value': value})
        for changes in [{'elapsed_ms': -1}, {'observed_failures': True}, {'collected_failures': 1000001},
                        {'checked_at': SECRET}, {'same_context': 1}, {'probe_state': [SECRET]}, {'raw': SECRET}]:
            value = copy.deepcopy(source['observation_window'])
            value['attempts'][0].update(changes)
            cases.append({'kind': 'window', 'value': value})
        expected = []
        for case in cases:
            try:
                (cli.fields if case['kind'] == 'fields' else cli.observation_window)(case['value'])
                expected.append(True)
            except cli.Refused:
                expected.append(False)
        process = subprocess.run([PHP, str(ROOT / 'tests/Fixtures/MachineAcceptance/source_diagnostic_projection.php')],
                                 input=json.dumps(cases).encode(), capture_output=True, timeout=20)
        self.assertEqual(process.returncode, 0, process.stderr[:300])
        self.assertEqual(json.loads(process.stdout), expected)

    def test_missing_diagnostic_is_not_fabricated_or_turned_into_success(self):
        value = {'ok': False, 'error_class': 'ErrorException'}
        self.assertEqual(cli.capture_result(self.ctx, value), value)
        self.ctx.save.assert_not_called()
        value.update(command='adopt-source', message=SECRET)
        result = cli.capture_result(self.ctx, value)
        self.assertEqual(result['cli_diagnostic_preservation'], {'ok': False, 'reason': 'cli_diagnostic_missing'})
        self.assertNotIn('cli_diagnostic', result)
        self.assertNotIn(SECRET, json.dumps(result))

    def test_initial_dns_has_its_own_phase_and_independently_preserved_provider_context(self):
        result = cli.capture_result(self.ctx, dict(dns_response(), raw_body=SECRET, trace=[SECRET]))
        self.assertTrue(result['cli_diagnostic_preservation']['ok'])
        stored = self.load(cli.REPORT)
        self.assertEqual(stored['phase'], 'initial-dns')
        self.assertEqual(stored['dns_provider_diagnostic'], dns_response()['dns_provider_diagnostic'])
        self.assertEqual(result['reason'], 'provider_rejected')
        self.assertNotIn(SECRET, json.dumps(result))
        self.assertNotIn(SECRET, json.dumps(stored))

    def test_dns_provider_projection_rejects_extra_fields_secrets_and_type_confusion(self):
        for changes in [{'schema': True}, {'provider': SECRET}, {'action': SECRET}, {'http_status': True},
                        {'http_status': 99}, {'http_status': 600}, {'code': [SECRET]}, {'code': SECRET},
                        {'message': SECRET}, {'request_id': SECRET}, {'parameters': [SECRET]}]:
            with self.subTest(changes=list(changes)):
                value = dns_response()
                value['dns_provider_diagnostic'].update(changes)
                result = cli.capture_result(self.ctx, value)
                self.assertFalse(result['cli_diagnostic_preservation']['ok'])
                self.assertNotIn('dns_provider_diagnostic', result)
                self.assertNotIn(SECRET, json.dumps(result))
                self.ctx.save.assert_not_called()

    def test_dns_context_cannot_be_attached_to_a_source_failure_or_unrelated_exception(self):
        for value in [dict(dns_response(), command='adopt-source'),
                      dict(response(), command='initial-dns', dns_provider_diagnostic=dns_response()['dns_provider_diagnostic'])]:
            result = cli.capture_result(self.ctx, value)
            self.assertFalse(result['cli_diagnostic_preservation']['ok'])
            self.assertNotIn('dns_provider_diagnostic', result)
        self.ctx.save.assert_not_called()

    def test_missing_dns_context_is_not_invented_and_later_context_cannot_replace_first_error(self):
        value = dns_response()
        result = cli.capture_result(self.ctx, value)
        self.assertTrue(result['cli_diagnostic_preservation']['ok'])
        original = (self.base / cli.REPORT).read_bytes()
        value['dns_provider_diagnostic']['action'] = 'DescribeDomainRecordInfo'
        self.assertFalse(cli.capture_result(self.ctx, value)['cli_diagnostic_preservation']['ok'])
        self.assertEqual(original, (self.base / cli.REPORT).read_bytes())
        (self.base / cli.REPORT).unlink()
        del value['dns_provider_diagnostic']
        self.assertNotIn('dns_provider_diagnostic', cli.capture_result(self.ctx, value))
        (self.base / cli.REPORT).unlink()
        del value['cli_diagnostic']
        result = cli.capture_result(self.ctx, value)
        self.assertEqual(result['command'], 'initial-dns')
        self.assertEqual(result['cli_diagnostic_preservation']['reason'], 'cli_diagnostic_missing')
        self.assertFalse((self.base / cli.REPORT).exists())

    @unittest.skipUnless(PHP and (ROOT / 'vendor/autoload.php').is_file(), 'local PHP and locked vendor required')
    def test_actual_php_projection_and_python_allowlists_are_in_sync(self):
        result = subprocess.run([PHP, str(ROOT / 'tests/Fixtures/MachineAcceptance/dns_diagnostic_probe.php')],
                                capture_output=True, timeout=20)
        self.assertEqual(result.returncode, 0, result.stderr[:200])
        value = json.loads(result.stdout)
        self.assertEqual(set(value['actions']) | {'other'}, cli.DNS_ACTIONS)
        self.assertEqual(set(value['codes']) | {'other'}, cli.DNS_CODES)
        captured = cli.capture_result(self.ctx, value['response'])
        self.assertTrue(captured['cli_diagnostic_preservation']['ok'])
        self.assertEqual(captured['dns_provider_diagnostic']['action'], 'UpdateDomainRecordRemark')

    @unittest.skipUnless(os.name == 'posix', 'real link refusal requires Linux')
    def test_symlink_or_hardlink_first_error_refuses_without_reading_target(self):
        other = self.base / 'private.json'
        other.write_text(json.dumps({'secret': SECRET}))
        path = self.base / cli.REPORT
        for link in [lambda: path.symlink_to(other), lambda: os.link(other, path)]:
            link()
            result = cli.capture_result(self.ctx, response())
            self.assertFalse(result['cli_diagnostic_preservation']['ok'])
            self.ctx.load.assert_not_called()
            self.assertNotIn(SECRET, json.dumps(result))
            path.unlink()


if __name__ == '__main__':
    unittest.main()
