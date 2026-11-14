"""Allowlisted source-CLI exception projection and exclusive local first-error readback."""
import os
from pathlib import Path
import stat
REPORT = 'cli-error-first-error.json'
REASONS = {'cli_exception', 'source_fixture_ownership_invalid', 'single_source_ipv4_required',
           'source_fixture_cloud_missing', 'source_fixture_not_failed', 'source_fixture_reports_missing',
           'source_fixture_original_identity_changed', 'exact_source_reference_required',
           'source_fixture_registration_changed', 'source_fixture_failure_not_current'}
FILES = {'cli_diagnostics.php', 'source_fixture.php', 'source_fixture_evidence.php', 'live_cli.php',
         'app/Services/ServerMachine/Cloud/MachineFailoverObserver.php',
         'app/Services/ServerMachine/Cloud/MachineCloudHealth.php',
         'app/Services/ServerMachine/Cloud/MachineProvisionProviders.php',
         'app/Services/ServerMachine/Cloud/LinodeProvisionClient.php',
         'app/Services/ServerMachine/MachineAddressInventory.php'}
CLASSES = {'ErrorException', 'RuntimeException', 'LogicException', 'TypeError', 'Error', 'JsonException', 'other',
           'KeliSourceFixtureEvidenceRejected', 'Illuminate\\Database\\Eloquent\\ModelNotFoundException',
           'App\\Services\\ServerMachine\\Cloud\\CloudException'}
SCOPE_FIELDS = ('expected_run_id', 'expected_machine_id', 'expected_account_id', 'expected_node_id',
                'expected_ipv4', 'expected_binary', 'expected_version', 'no_health_claim')
EVIDENCE_FIELDS = ('installation_id', 'task_label', 'task_status', 'bootstrap_state', 'machine_id', 'account_id',
    'dispatched_at', 'external_id', 'provider_created_at', 'cloud_status', 'task_ipv4', 'cloud_ipv4',
    'runtime_binary', 'runtime_version', 'runtime_session', 'runtime_nodes', 'agent_at_type', 'agent_at_future',
    'agent_at_fresh', 'agent_after_dispatch', 'evidence_current', 'threshold_reached', 'observed_failures',
    'probe_state', 'probe_installation', 'probe_ipv4', 'probe_at_type', 'probe_at_future', 'probe_at_fresh', 'probe_valid_until')


class Refused(ValueError):
    pass


def require(ok):
    if not ok:
        raise Refused('cli_diagnostic_invalid')


def project(value):
    require(isinstance(value, dict) and set(value) == {'schema', 'category', 'error_class', 'source_known', 'file', 'line', 'severity'}
            and type(value['schema']) is int and value['schema'] == 1
            and value['error_class'] in CLASSES and value['category'] in
            {'php_warning', 'php_type_error', 'php_error', 'domain_rejection', 'unexpected_exception'}
            and type(value['source_known']) is bool)
    if value['source_known']:
        require(isinstance(value['file'], str) and value['file'] in FILES
                and type(value['line']) is int and 0 < value['line'] <= 1000000)
    else:
        require(value['file'] is None and value['line'] is None)
    require(value['severity'] in {'warning', 'notice', 'deprecated', 'other'} if value['category'] == 'php_warning'
            else value['severity'] is None)
    expected = {'ErrorException': 'php_warning', 'TypeError': 'php_type_error', 'Error': 'php_error',
                'LogicException': 'unexpected_exception', 'JsonException': 'unexpected_exception'}
    if value['error_class'] != 'other':
        require(value['category'] == expected.get(value['error_class'], 'domain_rejection'))
    return dict(value)


def fields(value):
    require(isinstance(value, dict) and set(value) == {'schema', 'ok', 'reason', 'failed_fields', 'checks'}
            and type(value['schema']) is int and value['schema'] == 1 and value['ok'] is False
            and value['reason'] in {'source_fixture_scope_invalid', 'source_fixture_evidence_invalid'})
    names = SCOPE_FIELDS if value['reason'] == 'source_fixture_scope_invalid' else EVIDENCE_FIELDS
    checks = value['checks']
    require(isinstance(checks, dict) and set(checks) == set(names) and all(type(item) is bool for item in checks.values()))
    failed = [name for name in names if not checks[name]]
    require(bool(failed) and value['failed_fields'] == failed)
    return {'schema': 1, 'ok': False, 'reason': value['reason'], 'failed_fields': failed,
            'checks': {name: checks[name] for name in names}}


def preserve(campaign, diagnostic):
    value = {'schema': 1, 'run_id': campaign.RUN, 'phase': 'adopt-source', 'diagnostic': project(diagnostic)}
    base = Path(campaign.BASE)
    require(not os.path.lexists(base / 'closed.json'))
    path = base / REPORT
    def regular():
        info = path.lstat()
        require(stat.S_ISREG(info.st_mode) and info.st_nlink == 1 and 0 < info.st_size <= 16384)
        if os.name == 'posix':
            require(info.st_uid == os.geteuid() and not info.st_mode & 0o077)
    if os.path.lexists(path):
        regular()
    try:
        campaign.save(REPORT, value, exclusive=True)
    except FileExistsError:
        regular()
        require(campaign.load(REPORT) == value)
    regular()
    require(campaign.load(REPORT) == value)
    return {'ok': True, 'independent_local_readback_verified': True}


def capture_result(campaign, result):
    if not isinstance(result, dict):
        return result
    if 'cli_diagnostic' not in result:
        if result.get('command') == 'adopt-source' and result.get('ok') is False:
            return {'ok': False, 'command': 'adopt-source',
                    'error_class': result['error_class'] if isinstance(result.get('error_class'), str)
                        and result['error_class'] in CLASSES else 'other',
                    'reason': result['reason'] if isinstance(result.get('reason'), str)
                        and result['reason'] in REASONS else 'cli_exception',
                    'cli_diagnostic_preservation': {'ok': False, 'reason': 'cli_diagnostic_missing'}}
        return result
    safe = {'ok': False, 'command': 'adopt-source', 'error_class': 'other', 'reason': 'cli_exception'}
    try:
        require(result.get('ok') is False and result.get('command') == 'adopt-source'
                and type(result.get('cli_diagnostic_recorded')) is bool)
        diagnostic = project(result['cli_diagnostic'])
        require(result.get('error_class') == diagnostic['error_class'])
        safe.update(error_class=diagnostic['error_class'], cli_diagnostic=diagnostic,
                    cli_diagnostic_recorded=result['cli_diagnostic_recorded'])
        if isinstance(result.get('reason'), str) and result['reason'] in REASONS:
            safe['reason'] = result['reason']
        if diagnostic['error_class'] == 'KeliSourceFixtureEvidenceRejected':
            rejected = fields(result.get('source_fixture_evidence'))
            reason = rejected['reason'] + '_' + rejected['failed_fields'][0]
            require(result.get('reason') == reason and type(result.get('source_fixture_controller_journal_recorded')) is bool)
            safe.update(reason=reason, source_fixture_evidence=rejected,
                        source_fixture_controller_journal_recorded=result['source_fixture_controller_journal_recorded'])
        safe['cli_diagnostic_preservation'] = preserve(campaign, diagnostic)
    except Exception:
        safe['cli_diagnostic_preservation'] = {'ok': False, 'reason': 'cli_diagnostic_unconfirmed'}
    return safe
