import json
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
