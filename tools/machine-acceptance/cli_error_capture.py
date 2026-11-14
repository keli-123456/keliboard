"""Allowlisted source-CLI exception projection and exclusive local first-error readback."""
import os
import re
from pathlib import Path
import stat
import replacement_diagnostics
REPORT = 'cli-error-first-error.json'
SOURCE_REPORT = 'source-fixture-first-error.json'
REASONS = {'cli_exception', 'source_fixture_ownership_invalid', 'single_source_ipv4_required',
           'source_fixture_cloud_missing', 'source_fixture_not_failed', 'source_fixture_reports_missing',
           'source_fixture_original_identity_changed', 'exact_source_reference_required',
           'source_fixture_registration_changed', 'source_fixture_failure_not_current', 'provider_rejected', 'provider_forbidden',
           'provider_unavailable', 'provider_rate_limited', 'dns_outcome_unknown', 'dns_ownership_changed',
           'dns_inventory_incomplete', 'dns_credential_changed'}
FILES = {'cli_diagnostics.php', 'source_fixture.php', 'source_fixture_evidence.php', 'live_cli.php',
         'app/Services/ServerMachine/Cloud/MachineFailoverObserver.php',
         'app/Services/ServerMachine/Cloud/MachineCloudHealth.php',
         'app/Services/ServerMachine/Cloud/MachineProvisionProviders.php',
         'app/Services/ServerMachine/Cloud/LinodeProvisionClient.php',
         'app/Services/ServerMachine/MachineAddressInventory.php',
         'app/Services/ServerMachine/Dns/DnsProviderClient.php',
         'app/Services/ServerMachine/Dns/MachineDnsRecordSetProvider.php'}
CLASSES = {'ErrorException', 'RuntimeException', 'LogicException', 'TypeError', 'Error', 'JsonException', 'other',
           'KeliSourceFixtureEvidenceRejected', 'Illuminate\\Database\\Eloquent\\ModelNotFoundException',
           'App\\Services\\ServerMachine\\Cloud\\CloudException',
           'App\\Services\\ServerMachine\\Dns\\DnsException', 'App\\Services\\ServerMachine\\Dns\\DnsRequestFailure'}
DNS_ACTIONS = {'DescribeDomains', 'DescribeDomainRecords', 'DescribeDomainRecordInfo', 'DescribeSubDomainRecords',
               'AddDomainRecord', 'UpdateDomainRecord', 'UpdateDomainRecordRemark', 'DeleteDomainRecord', 'other'}
DNS_CODES = {'InvalidRemark', 'InvalidRemark.TooLong', 'InvalidRemark.Length', 'InvalidParameter', 'InvalidParameter.Remark',
             'InvalidParameterValue', 'InvalidParameter.Length', 'InvalidRecordId.NotFound', 'InvalidRR.NoExist',
             'InvalidDomainName.NoExist', 'DomainRecordDuplicate', 'DomainRecordNotBelongToUser', 'Forbidden',
             'Forbidden.RAM', 'Forbidden.RAMPolicy', 'InvalidAccessKeyId.NotFound', 'SignatureDoesNotMatch',
             'SignatureNonceUsed', 'TimestampExpired', 'Throttling', 'Throttling.User', 'InternalError', 'ServiceUnavailable', 'other'}
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
    require(isinstance(value, dict))
    extended = value.get('schema') == 2
    require(set(value) == ({'schema', 'ok', 'reason', 'failed_fields', 'checks', 'observation_window', 'registration'} if extended
                          else {'schema', 'ok', 'reason', 'failed_fields', 'checks'})
            and type(value['schema']) is int and value['schema'] in (1, 2) and value['ok'] is False
            and value['reason'] in {'source_fixture_scope_invalid', 'source_fixture_evidence_invalid'})
    names = SCOPE_FIELDS if value['reason'] == 'source_fixture_scope_invalid' else EVIDENCE_FIELDS
    if extended:
        require(value['reason'] == 'source_fixture_evidence_invalid')
        window = observation_window(value['observation_window'])
        names = (*names, 'observation_window_complete')
    checks = value['checks']
    require(isinstance(checks, dict) and set(checks) == set(names) and all(type(item) is bool for item in checks.values()))
    failed = [name for name in names if not checks[name]]
    require(bool(failed) and value['failed_fields'] == failed)
    result = {'schema': value['schema'], 'ok': False, 'reason': value['reason'], 'failed_fields': failed,
              'checks': {name: checks[name] for name in names}}
    if extended:
        registration = value['registration']
        require(isinstance(registration, dict) and set(registration) == {'observed_failures', 'checked_at',
                'evidence_current', 'threshold_reached', 'same_context'}
                and all(type(registration[key]) is bool for key in ('evidence_current', 'threshold_reached', 'same_context')))
        for key in ('observed_failures', 'checked_at'):
            require(registration[key] is None or type(registration[key]) is int
                    and 0 <= registration[key] <= (1000000 if key == 'observed_failures' else 9007199254740991))
        last = window['attempts'][-1] if window['attempts'] else {}
        complete = window['stop_reason'] == 'threshold_reached' and all(registration[key] for key in
                ('evidence_current', 'threshold_reached', 'same_context')) \
            and registration['observed_failures'] == last.get('observed_failures') \
            and registration['checked_at'] == last.get('checked_at')
        require(checks['observation_window_complete'] == complete)
        result['observation_window'] = window
        result['registration'] = dict(registration)
    return result


def observation_window(value):
    require(isinstance(value, dict) and set(value) == {'schema', 'budget_seconds', 'poll_seconds',
            'required_failures', 'elapsed_ms', 'stop_reason', 'attempts'}
            and type(value['schema']) is int and value['schema'] == 1
            and type(value['budget_seconds']) is int and value['budget_seconds'] == 60
            and type(value['poll_seconds']) is int and value['poll_seconds'] == 5
            and type(value['required_failures']) is int and 3 <= value['required_failures'] <= 10
            and type(value['elapsed_ms']) is int and 0 <= value['elapsed_ms'] <= 60000
            and isinstance(value['stop_reason'], str)
            and value['stop_reason'] in {'threshold_reached', 'deadline', 'probe_not_failed', 'clock_invalid'}
            and isinstance(value['attempts'], list) and len(value['attempts']) <= 12)
    previous = -1
    for row in value['attempts']:
        require(isinstance(row, dict) and set(row) == {'elapsed_ms', 'collected_failures', 'observed_failures',
                'collected_checked_at', 'checked_at', 'evidence_current', 'threshold_reached', 'collection_pending',
                'probe_state', 'same_context', 'same_sample'}
                and type(row['elapsed_ms']) is int and 0 <= row['elapsed_ms'] <= value['elapsed_ms']
                and row['elapsed_ms'] >= previous
                and isinstance(row['probe_state'], str) and row['probe_state'] in {'healthy', 'failed', 'unknown'}
                and all(type(row[key]) is bool for key in ('evidence_current', 'threshold_reached',
                        'collection_pending', 'same_context', 'same_sample')))
        for key in ('collected_failures', 'observed_failures', 'collected_checked_at', 'checked_at'):
            require(row[key] is None or type(row[key]) is int
                    and 0 <= row[key] <= (1000000 if key.endswith('_failures') else 9007199254740991))
        previous = row['elapsed_ms']
    if value['stop_reason'] == 'threshold_reached':
        last = value['attempts'][-1] if value['attempts'] else None
        require(last is not None and all(last[key] is True for key in
                ('evidence_current', 'threshold_reached', 'same_context', 'same_sample'))
                and last['collection_pending'] is False and last['probe_state'] == 'failed'
                and last['checked_at'] is not None and last['checked_at'] == last['collected_checked_at']
                and type(last['observed_failures']) is int and last['observed_failures'] >= value['required_failures']
                and last['observed_failures'] == last['collected_failures'] and value['elapsed_ms'] < 60000)
    return {**value, 'attempts': [dict(row) for row in value['attempts']]}


def source_journal(value, run_id, application_commit):
    require(isinstance(run_id, str) and re.fullmatch(r'keli-accept-[a-z0-9-]{8,100}', run_id)
            and isinstance(application_commit, str) and re.fullmatch(r'[a-f0-9]{40}', application_commit)
            and isinstance(value, dict) and value.get('run_id') == run_id
            and value.get('application_commit') == application_commit
            and type(value.get('schema')) is int and value['schema'] == 1)
    owner = {'schema', 'run_id', 'application_commit'}
    if set(value) == owner | {'diagnostic'}:
        return fields(value['diagnostic'])
    require(set(value) == owner | {'ok', 'reason', 'failed_fields', 'checks'})
    return fields({key: value[key] for key in ('schema', 'ok', 'reason', 'failed_fields', 'checks')})


def dns_diagnostic(value):
    require(isinstance(value, dict) and set(value) == {'schema', 'provider', 'action', 'http_status', 'code'}
            and type(value['schema']) is int and value['schema'] == 1 and value['provider'] == 'aliyun'
            and isinstance(value['action'], str) and value['action'] in DNS_ACTIONS
            and (value['http_status'] is None or type(value['http_status']) is int and 100 <= value['http_status'] <= 599)
            and (value['code'] is None or isinstance(value['code'], str) and value['code'] in DNS_CODES))
    return dict(value)


def preserve(campaign, diagnostic, command='adopt-source', dns=None, source=None):
    require(command in {'adopt-source', 'initial-dns'})
    value = {'schema': 1, 'run_id': campaign.RUN, 'phase': command, 'diagnostic': project(diagnostic)}
    if dns is not None:
        require(command == 'initial-dns')
        value['dns_provider_diagnostic'] = dns_diagnostic(dns)
    source_value = None
    if source is not None:
        require(command == 'adopt-source')
        source_value = {'schema': 1, 'run_id': campaign.RUN, 'phase': command, 'diagnostic': fields(source)}
    preserve_first(campaign, REPORT, value)
    if source_value is not None:
        preserve_first(campaign, SOURCE_REPORT, source_value)
    return {'ok': True, 'independent_local_readback_verified': True}


def preserve_first(campaign, name, value):
    require(name in (REPORT, SOURCE_REPORT))
    base = Path(campaign.BASE)
    require(not os.path.lexists(base / 'closed.json'))
    path = base / name
    def regular():
        info = path.lstat()
        require(stat.S_ISREG(info.st_mode) and info.st_nlink == 1 and 0 < info.st_size <= 16384)
        if os.name == 'posix':
            require(info.st_uid == os.geteuid() and not info.st_mode & 0o077)
    if os.path.lexists(path):
        regular()
    try:
        campaign.save(name, value, exclusive=True)
    except FileExistsError:
        regular()
        require(campaign.load(name) == value)
    regular()
    require(campaign.load(name) == value)


def capture_result(campaign, result):
    result = replacement_diagnostics.capture_result(campaign, result)
    if not isinstance(result, dict):
        return result
    command = result.get('command')
    if 'cli_diagnostic' not in result:
        if command in ('adopt-source', 'initial-dns') and result.get('ok') is False:
            return {'ok': False, 'command': command,
                    'error_class': result['error_class'] if isinstance(result.get('error_class'), str)
                        and result['error_class'] in CLASSES else 'other',
                    'reason': result['reason'] if isinstance(result.get('reason'), str)
                        and result['reason'] in REASONS else 'cli_exception',
                    'cli_diagnostic_preservation': {'ok': False, 'reason': 'cli_diagnostic_missing'}}
        return result
    safe = {'ok': False, 'command': command if command in ('adopt-source', 'initial-dns') else 'other',
            'error_class': 'other', 'reason': 'cli_exception'}
    try:
        require(result.get('ok') is False and command in ('adopt-source', 'initial-dns')
                and type(result.get('cli_diagnostic_recorded')) is bool)
        diagnostic = project(result['cli_diagnostic'])
        require(result.get('error_class') == diagnostic['error_class'])
        safe.update(error_class=diagnostic['error_class'], cli_diagnostic=diagnostic,
                    cli_diagnostic_recorded=result['cli_diagnostic_recorded'])
        if isinstance(result.get('reason'), str) and result['reason'] in REASONS:
            safe['reason'] = result['reason']
        rejected = None
        if diagnostic['error_class'] == 'KeliSourceFixtureEvidenceRejected':
            rejected = fields(result.get('source_fixture_evidence'))
            reason = rejected['reason'] + '_' + rejected['failed_fields'][0]
            require(result.get('reason') == reason and type(result.get('source_fixture_controller_journal_recorded')) is bool)
            safe.update(reason=reason, source_fixture_evidence=rejected,
                        source_fixture_controller_journal_recorded=result['source_fixture_controller_journal_recorded'])
        dns = dns_diagnostic(result['dns_provider_diagnostic']) if 'dns_provider_diagnostic' in result else None
        if dns is not None:
            require(command == 'initial-dns' and diagnostic['error_class'] in
                    {'App\\Services\\ServerMachine\\Dns\\DnsException', 'App\\Services\\ServerMachine\\Dns\\DnsRequestFailure'})
            safe['dns_provider_diagnostic'] = dns
        safe['cli_diagnostic_preservation'] = preserve(campaign, diagnostic, command, dns, rejected)
    except Exception:
        safe['cli_diagnostic_preservation'] = {'ok': False, 'reason': 'cli_diagnostic_unconfirmed'}
    return safe
