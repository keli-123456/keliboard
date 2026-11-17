"""Temporary CI-only full application verification; no real cloud/SSH/DNS."""
import base64
import ast
import hashlib
import http.client
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import io
import json
import os
from pathlib import Path
import shutil
import socket
import ssl
import subprocess
import sys
import tarfile
import tempfile
import threading
import time
from unittest.mock import patch

REPO = Path(__file__).resolve().parents[1]
TOOLS = REPO / 'tools/machine-acceptance'
ROOT = Path('/opt/keli-isolated')
ART = REPO / 'artifacts'
SUMMARY = dict(status='running', cloud_ssh_dns='simulated', live_ready=False,
    node_fixture_synthetic_not_executed=True, full_application_and_vendor=True,
    production_changed=False, r34_executed=False, cloud_instances_created=0)
ENV = dict(PATH='/usr/local/bin:/usr/bin:/bin', HOME='/nonexistent', LANG='C.UTF-8',
    GITHUB_ACTIONS='true', KELI_MEMBER_FULL_APPLICATION='1', PYTHONDONTWRITEBYTECODE='1')
server = None
php_server = None
hold_process = None
work = None
opt_stat = None
http_scope = None
http_invoke = None


def command(args, *, timeout=90, **kwargs):
    return subprocess.run(args, check=True, capture_output=True, timeout=timeout, **kwargs)


def php(mode, *, uid=0, extra=None, ok=True):
    prefix = [] if uid == 0 else ['setpriv', '--reuid=65534', '--regid=65534', '--clear-groups',
        '--no-new-privs', '--bounding-set=-all']
    p = subprocess.run(prefix + ['php', '-d', 'display_errors=0', '-d', 'log_errors=0',
        str(ROOT / 'app/tests/Fixtures/member_full_application.php'), mode],
        cwd=ROOT / 'app', env=dict(ENV, **(extra or {})), capture_output=True, timeout=180)
    try: value = json.loads(p.stdout)
    except Exception: raise AssertionError('non_json_full_application_result') from None
    if ok and p.returncode != 0: raise AssertionError(dict(stage=mode, **value))
    if not ok: assert p.returncode != 0
    return value


def setup_rpc(operation, *, ok=True):
    scope_path = ROOT / 'member-setup-grant.json'
    scope = json.loads(scope_path.read_bytes())
    request = dict(schema=1, run_id=scope['run_id'], grant_id=scope['grant_id'],
        grant_sha256=hashlib.sha256(scope_path.read_bytes()).hexdigest(), deadline_at=scope['deadline_at'],
        request_id=os.urandom(16).hex(), sequence=1000, operation=operation)
    p = subprocess.run(['php', '-d', 'display_errors=0', '-d', 'log_errors=0',
        str(ROOT / 'runtime/member_setup_entry.php')], input=json.dumps(request).encode(),
        cwd=ROOT / 'app', env=ENV, capture_output=True, timeout=30)
    value = json.loads(p.stdout)
    assert (p.returncode == 0) == ok and value['ok'] == ok, value
    if ok: assert all(value.get(k) == v for k, v in request.items())
    return value.get('result', value)


def import_rpc(operation, *, uid=0, ok=True, extra=None):
    prefix = [] if uid == 0 else ['setpriv', '--reuid=65534', '--regid=65534', '--clear-groups', '--no-new-privs']
    p = subprocess.run(prefix + ['php', '-d', 'display_errors=0', '-d', 'log_errors=0',
        str(ROOT / 'runtime/member_import_entry.php')], input=json.dumps(dict(operation=operation)).encode(),
        cwd=ROOT / 'app', env=dict(ENV, **(extra or {})), capture_output=True, timeout=90)
    value = json.loads(p.stdout)
    assert (p.returncode == 0) == ok and value['ok'] == ok, value
    for secret in (b'synthetic-no-live-token', b'synthetic-secret'):
        assert secret not in p.stdout + p.stderr
    return value


def import_denied(reason, *, uid=0):
    value = import_rpc('import', uid=uid, ok=False)
    assert value['reason'] == reason, value
    return value


assert sys.platform == 'linux' and os.geteuid() == 0
assert not ROOT.exists() and not ROOT.is_symlink()
ART.mkdir(exist_ok=True)
command(['ip', 'link', 'set', 'lo', 'up'])
assert command(['ip', 'route']).stdout.strip() == command(['ip', '-6', 'route']).stdout.strip() == b''
os.environ.update(GIT_CONFIG_COUNT='1', GIT_CONFIG_KEY_0='safe.directory', GIT_CONFIG_VALUE_0=str(REPO))
sha = command(['git', 'rev-parse', 'HEAD'], cwd=REPO).stdout.decode().strip()
SUMMARY['tested_commit'] = sha
sys.path.insert(0, str(TOOLS))
import member_bundle
import member_runtime_bundle as runtime
from member_installation import MemberInstallation
from member_application import configure
from member_cli import RUN

try:
    tests = subprocess.run(['python3', '-B', '-m', 'unittest', '-v', 'test_member_environment',
        'test_member_installation_linux.InstallationServiceUserTests', 'test_member_execution.ExecutionTests',
        'test_member_assembly', 'test_member_initialization', 'test_member_cli', 'test_member_round',
        'test_member_transport', 'test_member_remote_setup', 'test_member_private_cleanup', 'test_member_bundle', 'test_member_runtime_bundle',
        'test_member_host_installation', 'test_member_http_service'],
        cwd=TOOLS, capture_output=True, text=True, timeout=240)
    (ART / 'python.log').write_text(tests.stdout + tests.stderr)
    assert tests.returncode == 0, 'python_regression_failed'
    SUMMARY['python_passed'] = True
    php_tests = subprocess.run(['php', 'vendor/phpunit/phpunit/phpunit',
        'tests/Unit/Services/MachineMemberDnsInitializationTest.php',
        'tests/Unit/Services/MachineMemberSourceConfigurationTest.php',
        'tests/Unit/Services/MachineMemberPrerequisiteImportTest.php',
        'tests/Unit/Services/MachineMemberBootstrapControllerTest.php',
        'tests/Unit/Services/MachineMemberApplicationInstallTest.php',
        'tests/Unit/Services/MachineMemberCallbackRequestTest.php',
        'tests/Unit/Services/MachineMemberSetupAuthorityTest.php',
        'tests/Unit/Services/MachineProviderGroupsTest.php',
        'tests/Unit/Services/MachineMemberProviderBindingsTest.php',
        'tests/Unit/Services/MachineDnsAliyunCompatibilityTest.php'],
        cwd=REPO, capture_output=True, text=True, timeout=120)
    (ART / 'dns-phpunit.log').write_text(php_tests.stdout + php_tests.stderr)
    assert php_tests.returncode == 0, 'dns_phpunit_regression_failed'
    SUMMARY['dns_phpunit_passed'] = True
    source_tests = subprocess.run(['php', 'vendor/phpunit/phpunit/phpunit',
        'tests/Unit/Services/MachineFailoverNativeBridgeTest.php', '--filter', 'test_member_initialization_'],
        cwd=REPO, capture_output=True, text=True, timeout=120)
    (ART / 'source-phpunit.log').write_text(source_tests.stdout + source_tests.stderr)
    assert source_tests.returncode == 0, 'source_phpunit_regression_failed'
    SUMMARY['source_phpunit_passed'] = True
    cli_tests = subprocess.run(['php', 'vendor/phpunit/phpunit/phpunit',
        'tests/Unit/Services/MachineFailoverNativeBridgeTest.php', '--filter', 'test_member_(cli|candidate|native_dns|driver|evidence|transport)'],
        cwd=REPO, capture_output=True, text=True, timeout=120)
    (ART / 'cli-phpunit.log').write_text(cli_tests.stdout + cli_tests.stderr)
    assert cli_tests.returncode == 0, 'cli_phpunit_regression_failed'
    SUMMARY['native_cli_binding_lifetime_linux_tested'] = True
    SUMMARY['candidate_callback_lineage_sqlite_component_tested'] = True
    SUMMARY['candidate_callback_http_full_application_tested'] = False
    os.umask(0o077)
    # Hosted runners make /opt writable by the runner user. Match a fresh
    # controller's root-owned parent only in this disposable CI VM, then restore.
    assert Path('/opt').resolve() == Path('/opt') and Path('/opt').is_dir()
    opt_stat = Path('/opt').stat()
    os.chown('/opt', 0, 0); os.chmod('/opt', 0o755)
    ROOT.mkdir(mode=0o711)
    (ROOT / '.r35-application-fixture').write_text(sha)
    work = Path(tempfile.mkdtemp(prefix='r35-app-fixture-'))
    owned_repo = work / 'source'
    owned_repo.mkdir()
    shutil.copytree(REPO / '.git', owned_repo / '.git')
    for name in member_bundle.FILES:
        target = owned_repo / name
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(REPO / name, target)
    assert command(['git', 'rev-parse', 'HEAD'], cwd=owned_repo).stdout.decode().strip() == sha
    shutil.copytree(REPO / 'vendor', owned_repo / 'vendor')
    application = work / 'application.tar.gz'
    node = work / 'synthetic-node.tar.gz'
    binary = b'\x7fELF\x02\x01' + b'\0' * 12 + b'\x3e\x00' + b'synthetic-not-executed'
    with tarfile.open(node, 'w:gz') as archive:
        info = tarfile.TarInfo(runtime.NODE_MEMBER); info.size = len(binary)
        archive.addfile(info, io.BytesIO(binary))
    # Only fixture pins change. The cold child executes the actual verifier and
    # installer from this hash-locked synthetic runtime, without parent mocks.
    runtime_source = owned_repo / 'tools/machine-acceptance/member_runtime_bundle.py'
    tree = ast.parse(runtime_source.read_text())
    fixture_hashes = dict(NODE_ARCHIVE_SHA=hashlib.sha256(node.read_bytes()).hexdigest(),
                          NODE_SHA=hashlib.sha256(binary).hexdigest())
    for item in tree.body:
        if isinstance(item, ast.Assign) and len(item.targets) == 1 and isinstance(item.targets[0], ast.Name):
            if item.targets[0].id in fixture_hashes:
                item.value = ast.Constant(fixture_hashes[item.targets[0].id])
    runtime_source.write_text(ast.unparse(tree) + '\n')
    member_bundle.freeze(owned_repo, sha, application, RUN)
    with patch.object(runtime, 'NODE_ARCHIVE_SHA', hashlib.sha256(node.read_bytes()).hexdigest()), \
         patch.object(runtime, 'NODE_SHA', hashlib.sha256(binary).hexdigest()):
        bundle = work / 'runtime.tar.gz'
        frozen = runtime.assemble(application, owned_repo / 'vendor', node, bundle)
    from member_transport import StrictControllerTransport, TransportError
    from member_install_bootstrap import BOOTSTRAP
    install_root = work / 'installation-host'; install_root.mkdir(mode=0o700)
    for name in ('key', 'known'):
        (install_root / name).write_bytes(b'synthetic-installation-host'); (install_root / name).chmod(0o600)
    install_scope = dict(run_id=RUN, bundle_sha256=frozen['archive_sha256'], started_at=int(time.time()))
    install_scope['deadline_at'] = install_scope['started_at'] + 7199
    install_journal = {}
    import member_http_service as http_service
    def http_invoke(args, timeout=20):
        if args[0] == 'systemd-run':
            namespace = os.environ['KELI_HTTP_NETNS']
            assert __import__('re').fullmatch(r'/proc/[1-9][0-9]*/ns/net', namespace)
            args = [args[0], '--property=NetworkNamespacePath=' + namespace, *args[1:]]
        code, output = http_service.command(args, timeout)
        if args[:2] == ['systemctl', 'show']:
            SUMMARY['systemd_readback'] = dict(code=code, fields=[line.split('=', 1)[0] for line in output.decode('ascii').splitlines()])
        return code, output
    def installation_ssh(args, payload, timeout):
        import shlex
        assert args[-2] == 'root@9.9.9.10' and 'StrictHostKeyChecking=yes' in args
        argv = shlex.split(args[-1])
        if argv == ['python3', '-B', '/opt/keli-isolated/runtime/member_http_service.py']:
            request = json.loads(payload)
            result = http_service.execute(request, invoke=http_invoke)
            return json.dumps(dict(ok=True, request_sha256=digest(request), result=result)).encode()
        expected = ['php', '/opt/keli-isolated/runtime/member_application_entry.php']
        prefix = ['setpriv', '--reuid=65534', '--regid=65534', '--clear-groups', '--no-new-privs', '--bounding-set=-all']
        upload = (argv[:2] == ['python3', '-c'] and len(argv) == 4
                  and argv[-1] == frozen['archive_sha256'] and '/opt/keli-isolated/incoming' in argv[2])
        assert upload or argv in (expected, prefix + expected, ['python3', '-I', '-B', '-c', BOOTSTRAP])
        p = subprocess.run(argv, input=payload, cwd=ROOT, env=ENV, capture_output=True, timeout=timeout)
        assert p.returncode == 0, json.loads(p.stdout)
        return p.stdout
    install_transport = StrictControllerTransport(run_id=RUN, host='9.9.9.10', identity_file=install_root / 'key',
        known_hosts=install_root / 'known', guard=lambda *_: None, load=install_journal.get,
        save=lambda k, v: install_journal.__setitem__(k, v), execute=installation_ssh)
    (ROOT / 'incoming').mkdir(mode=0o700)
    assert install_transport.upload(bundle, frozen['archive_sha256'])['staged'] is True
    publication = install_transport.installation_call(dict(operation='publish', scope=install_scope))
    assert publication['completed_operations'] == ['publish'] and not publication['uncertain_operations']
    # Exercise the actual cold-boot CLI and journal permissions before the real
    # isolated application configuration is published.
    entry_scratch = [ROOT / 'app' / name for name in ('.env', 'bootstrap/cache/packages.php',
        'bootstrap/cache/services.php', 'storage/logs/laravel.log')]
    assert not any(path.exists() or path.is_symlink() for path in entry_scratch)
    entry_tests = subprocess.run(['python3', '-B', '-m', 'unittest', '-v', 'test_member_cli_entry_linux'],
        cwd=TOOLS, env=dict(ENV, KELI_MEMBER_CLI_ENTRY='1'), capture_output=True, text=True, timeout=120)
    (ART / 'native-entry-linux.log').write_text(entry_tests.stdout + entry_tests.stderr)
    (ART / 'native-entry-linux.log').chmod(0o644)
    assert entry_tests.returncode == 0, 'native_entry_linux_regression_failed'
    native_journal = ROOT / 'private/member-journal'
    assert native_journal.resolve() == native_journal
    for path in native_journal.iterdir():
        assert path.is_file() and not path.is_symlink() and path.stat().st_nlink == 1
        path.unlink()
    native_journal.rmdir()
    for path in entry_scratch:
        if path.exists() or path.is_symlink():
            assert path.resolve() == path and path.is_file() and path.stat().st_uid == 0 and path.stat().st_nlink == 1
            path.unlink()
    SUMMARY['native_fixed_entry_permissions_passed'] = True
    settings = dict(app_key=base64.b64encode(b'x' * 32).decode(), database='keli_acceptance_application',
        username='keli_application', password='s' * 32, mysql_port=13306,
        redis_password='r' * 32, redis_port=16379)
    configuration = install_transport.installation_call(dict(operation='configure', scope=install_scope,
        settings=settings, service=dict(uid=65534, gid=65534)))
    assert configuration['completed_operations'] == ['publish', 'configure']
    SUMMARY['cold_host_publication'] = dict(actual_fresh_python_subprocess=True, actual_upload_bytes=True,
        actual_runtime_and_environment=True, no_preinstalled_application_cli=True,
        original_stage_intents_retained=True, node_fixture_hashes_only_modified=True,
        os_packages_and_tls_daemons_not_installed=True, whole_host_installer=False)
    SUMMARY['application_files'] = frozen['files']
    SUMMARY['composer_lock_sha256'] = frozen['composer_lock_sha256']
    SUMMARY['migration'] = install_transport.application_call('initialize', install_scope)
    assert install_transport.application_call('readback', install_scope) == SUMMARY['migration']
    smoke = install_transport.application_call('service-smoke', install_scope, dict(uid=65534, gid=65534))
    assert smoke['database_and_cache_reachable'] and smoke['executable_paths_read_only']
    SUMMARY['application_install_entry'] = dict(actual_root_migration=True, actual_uid=smoke['uid'],
        actual_mysql_redis=True, fixed_ssh_command_simulated_locally=True, no_cloud_or_dns=True)
    assert php('initialize-readback') == SUMMARY['migration']
    rejected = php('migrate', ok=False)
    assert rejected['reason'] == 'member_application_initialization_intent_exists'
    denied = php('initialize-readback', uid=65534, ok=False)
    assert denied['reason'] == 'member_application_installer_root_required'
    receipt_path = ROOT / 'private/application-migration-result.json'
    saved_receipt = ROOT / 'private/application-migration-saved.json'
    receipt_path.rename(saved_receipt)
    try:
        assert php('initialize-readback', ok=False)['reason'] == 'member_application_installation_readback_required'
        assert php('migrate', ok=False)['reason'] == 'member_application_initialization_intent_exists'
    finally: saved_receipt.rename(receipt_path)
    assert php('initialize-readback') == SUMMARY['migration']
    SUMMARY['migration_replay_and_missing_completion_refused'] = True
    originals = [ROOT / ('private/application-migration-' + name + '.json') for name in ('intent', 'result')]
    for path in originals:
        assert path.stat().st_mode & 0o777 == 0o600
        path.rename(path.with_suffix('.saved'))
    try:
        denied = php('migrate', ok=False)
        assert denied['reason'] == 'member_application_database_not_empty'
        first_error = ROOT / 'private/application-migration-first-error.json'
        first_bytes = first_error.read_bytes()
        diagnostic = json.loads(first_bytes)
        assert set(diagnostic) == {'class', 'file', 'line'} and diagnostic['class'] == 'RuntimeException'
        assert not originals[1].exists()
        assert php('migrate', ok=False)['reason'] == 'member_application_initialization_intent_exists'
        assert first_error.read_bytes() == first_bytes
        assert first_error.stat().st_mode & 0o777 == 0o600
    finally:
        for name in ('intent', 'result', 'first-error'):
            path = ROOT / ('private/application-migration-' + name + '.json')
            if path.exists(): path.unlink()
        for path in originals: path.with_suffix('.saved').rename(path)
    assert php('initialize-readback') == SUMMARY['migration']
    SUMMARY['existing_schema_rejected_first_error_retained'] = True
    if '--install-entry-only' in sys.argv:
        # Stop drains the same root lock as initialization; an in-flight installer
        # must not return a success acknowledgement after this stop appears.
        hold_process = subprocess.Popen(['php', str(ROOT / 'app/tests/Fixtures/member_full_application.php'), 'installation-drain'],
            cwd=ROOT / 'app', env=ENV, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        marker = ROOT / 'private/installation-drain-ready'
        for _ in range(100):
            if marker.exists(): break
            assert hold_process.poll() is None; time.sleep(.05)
        else: raise AssertionError('installation_drain_not_ready')
        stopped = import_rpc('stop', ok=False)
        assert stopped['reason'] == 'member_import_busy', stopped
        output, errors = hold_process.communicate(timeout=10)
        assert hold_process.returncode == 1 and json.loads(output)['reason'] == 'member_import_expired_or_stopped'
        hold_process = None
        assert import_rpc('stop')['stopped'] is True
        assert install_transport.application_call('readback', install_scope) == SUMMARY['migration']
        SUMMARY['application_install_entry']['stop_drains_shared_lock'] = True
        SUMMARY['application_install_entry']['no_success_after_stop'] = True
        SUMMARY['application_install_entry']['readback_after_stop'] = True
        SUMMARY['install_entry_only'] = True
        SUMMARY['whole_host_installer_verified'] = False
        SUMMARY['status'] = 'passed'
        raise SystemExit(0)
    installer = MemberInstallation(ROOT)
    credentials = json.dumps(dict(schema=1, run_id=RUN, linode=dict(api_token='synthetic-no-live-token'),
        aliyun=dict(access_key_id='synthetic-id', access_key_secret='synthetic-secret'), baseline_ids=[])).encode()
    app_identity = json.loads((ROOT / 'application.json').read_bytes())
    started = install_scope['started_at']
    authority = dict(schema=1, run_id=RUN, operation='private-import', authorized=True,
        bundle_sha256=frozen['archive_sha256'], machine_id_sha256=app_identity['machine_id_sha256'],
        started_at=started, deadline_at=started + 7199, credentials_sha256=hashlib.sha256(credentials).hexdigest())
    raw_authority = json.dumps(authority).encode()
    private_publication = install_transport.installation_call(dict(operation='private', scope=install_scope,
        files={name: dict(base64=base64.b64encode(raw).decode(), sha256=hashlib.sha256(raw).hexdigest())
               for name, raw in [('credentials', credentials), ('bootstrap-authority', raw_authority)]}))
    assert private_publication['completed_operations'] == ['publish', 'configure', 'private']
    assert len(private_publication['private_files']) == 3
    SUMMARY['cold_host_publication']['private_files_through_actual_entry'] = True
    authority_path = ROOT / 'private/bootstrap-authority'
    import_denied('member_import_root_cli_required', uid=65534)
    credentials_path = ROOT / 'private/credentials'
    credentials_path.chmod(0o644)
    try: import_denied('member_import_file_invalid')
    finally: credentials_path.chmod(0o600)
    import_lock = ROOT / 'private/member-import/lock'
    assert import_lock.stat().st_mode & 0o777 == 0o600
    import_lock.chmod(0o644)
    try:
        import_denied('member_import_busy')
        assert import_lock.stat().st_mode & 0o777 == 0o644
    finally: import_lock.chmod(0o600)
    import fcntl
    with import_lock.open('r+b') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        import_denied('member_import_busy')
    authority_path.write_bytes(json.dumps(dict(authority, bundle_sha256='0' * 64)).encode())
    try: import_denied('member_import_authority_invalid')
    finally: authority_path.write_bytes(raw_authority)
    expiry_start = int(time.time())
    authority_path.write_bytes(json.dumps(dict(authority, started_at=expiry_start, deadline_at=expiry_start + 1202)).encode())
    elapsed_start = time.monotonic(); time.sleep(2.2)
    try:
        import_denied('member_import_expired_or_stopped')
        assert time.monotonic() - elapsed_start >= 2.2
    finally: authority_path.write_bytes(raw_authority)
    assert not (ROOT / 'private/member-import/intent.json').exists()
    failures = php('import-failures')
    assert failures['rollback_phases'] == 5 and failures['rows'] == [0] * 5
    imported = import_rpc('import')
    assert import_rpc('import') == imported
    assert import_rpc('readback')['scope'] == imported['scope']
    assert php('import-inspect')['credential_storage_encrypted']
    assert php('import-service-read', uid=65534)['published_files_readable_not_writable']
    result_path = ROOT / 'private/member-import/result.json'
    result_backup = result_path.with_suffix('.saved')
    result_path.rename(result_backup)
    try: import_denied('member_import_result_unconfirmed')
    finally: result_backup.rename(result_path)
    SUMMARY['prerequisite_import'] = dict(actual_fixed_cli=True, actual_credential_services=True,
        actual_release_distribution_service=True, encrypted_mysql_credentials=True,
        bundle_machine_and_private_material_bound=True, root_only=True,
        new_lock_acl_narrowed_only_on_creation=True, existing_lock_permissions_not_repaired=True,
        concurrent_cli_import_refused=True,
        real_elapsed_dispatch_expiry=True, service_user_reads_release_not_private_material=True,
        rollback_phases=5, uncertain_replay_refused=True, first_error_retained=True,
        stop_denies_import_allows_readback=False, default_release_unset=True,
        credential_values_synthetic=True, node_bytes_synthetic_not_executed=True,
        production_or_cloud_calls=False, whole_round_ports_composed=False)
    assert php('configuration-prepare')['configuration_rows'] == [0] * 7
    failures = php('configuration-failures')
    assert failures['rollback_phases'] == 7 and failures['configuration_rows'] == [0] * 7
    assert failures['first_error_safe_and_retained'] and failures['uncertain_replay_refused']
    configured = php('configuration-create')
    assert configured['configuration_rows'] == [2, 2, 2, 1, 2, 2, 2] and configured['provider_gets'] == 4
    assert configured['provider_writes'] == 0 and not configured['tasks_or_health_fabricated']
    for mode in ('configuration-read', 'configuration-repeat', 'configuration-recovery', 'configuration-drift'):
        result = php(mode)
        assert result['configuration_sha256'] == configured['configuration_sha256']
        assert result['provider_gets'] == 0 and result['configuration_rows'] == configured['configuration_rows']
        assert result['closed_gates_unchanged']
    read_configuration = import_rpc('configuration-read')['configuration']
    assert read_configuration['service_sha256'] == configured['configuration_sha256']
    repeated_configuration = import_rpc('configure')['configuration']
    assert read_configuration == repeated_configuration
    assert not repeated_configuration['cloud_or_dns_writes']
    assert import_rpc('configure', uid=65534, ok=False)['reason'] == 'member_import_root_cli_required'
    SUMMARY['source_configuration'] = dict(actual_application_services=True, real_mysql_transactions=True,
        real_redis_locks=True, provider_reads_simulated=True, provider_writes=0,
        prerequisite_credentials_and_node_bytes_synthetic=True, concrete_import_authority_tested=True,
        machines=2, nodes=2, policies=2, services=1, members=2, dns_sets=2, dns_slots=2,
        failure_rollback_phases=7, uncertain_replay_refused=True, first_error_safe_and_retained=True,
        process_restart_readback=True, duplicate_creation_refused=True,
        recovery_does_not_reselect_credentials=True, lost_result_prevents_replay=True,
        existing_configuration_without_intent_not_adopted=True,
        node_policy_credential_drift_refused=True, stop_blocks_mutation_allows_readback=False,
        tasks_or_health_fabricated=False, persistent_gates_changed=False)
    SUMMARY['setup_issue'] = php('setup-issue')
    assert SUMMARY['setup_issue']['issued'] and SUMMARY['setup_issue']['reissue_refused']
    assert SUMMARY['setup_issue']['actual_bootstrap_controller']
    assert SUMMARY['setup_issue']['original_window_inherited']
    assert SUMMARY['setup_issue']['lost_result_reconciled_without_reissue']
    actual_grant = json.loads((ROOT / 'member-setup-grant.json').read_bytes())
    assert actual_grant['started_at'] == authority['started_at'] and actual_grant['deadline_at'] == authority['deadline_at']
    actual_setup = import_rpc('setup-read')['setup']
    assert actual_setup['grant_id'] == actual_grant['grant_id'] and actual_setup['deadline_at'] == authority['deadline_at']
    assert php('setup-service-denied', uid=65534)['root_operations_denied']
    assert php('setup-drift')['policy_and_release_drift_refused']
    control_dns = php('setup-dns-control')
    assert control_dns['ready'] and control_dns['record_count'] == 1
    assert control_dns['provider_create'] == control_dns['provider_claim'] == 1
    preparation = php('setup-prepare')
    assert preparation['native_task_queued'] and preparation['b_not_queued']
    assert preparation['exception_restores_capability_and_bus'] and preparation['provider_writes'] == 0
    dispatched = php('setup-process')
    assert dispatched['native_dispatch'] and dispatched['provider_writes_simulated'] == 1
    assert php('setup-process-repeat')['provider_writes_simulated'] == 0
    # Bind the remaining source/DNS/grant stages to the actual controller. Early
    # fixture setup above deliberately exercises fault injection before this handoff.
    from driver_journal import DriverJournal
    from member_transport import StrictControllerTransport, TransportError
    from member_remote_setup import MemberRemoteSetup
    from member_auxiliary import digest
    host_root = work / 'native-host'; host_root.mkdir(mode=0o700)
    key = host_root / 'key'; known = host_root / 'known_hosts'
    key.write_bytes(b'synthetic-ssh-key'); known.write_bytes(b'synthetic-known-host')
    key.chmod(0o600); known.chmod(0o600)
    (host_root / 'journal').mkdir(mode=0o700)
    host_journal = DriverJournal(host_root / 'journal', RUN)
    def host_load(name):
        try: return host_journal.load(name + '.json')
        except FileNotFoundError: return None
    def host_save(name, value): host_journal.save(name + '.json', value)
    setup_last = {}
    def setup_ssh(args, payload, timeout):
        for option in ('BatchMode=yes', 'IdentitiesOnly=yes', 'StrictHostKeyChecking=yes', 'UpdateHostKeys=no'):
            assert option in args
        assert args[-2] == 'root@9.9.9.10'
        if args[-1] == 'php /opt/keli-isolated/runtime/member_import_entry.php':
            argv = [str(ROOT / 'runtime/member_import_entry.php')]
        else:
            assert args[-1] == 'php /opt/keli-isolated/runtime/member_setup_entry.php'
            argv = [str(ROOT / 'app/tests/Fixtures/member_full_application.php'), 'setup-host-rpc']
        p = subprocess.run(['php', '-d', 'display_errors=0', '-d', 'log_errors=0', *argv], input=payload,
            cwd=ROOT / 'app', env=ENV, capture_output=True, timeout=timeout)
        value = json.loads(p.stdout)
        assert p.returncode == 0, dict(stage='setup_host_rpc', **value)
        setup_last.clear(); setup_last.update(value)
        return p.stdout
    transport_args = dict(run_id=RUN, host='9.9.9.10', identity_file=key, known_hosts=known,
        guard=lambda *_: None, load=host_load, save=host_save, execute=setup_ssh)
    bootstrap_transport = StrictControllerTransport(**transport_args)
    assert bootstrap_transport.bootstrap_call('import')['verified']
    setup_identity = bootstrap_transport.bootstrap_call('setup-issue', actual_grant['control'])['setup']
    host_save('remote-setup-identity', setup_identity)
    # Retain the real preceding fixture RPC receipt when crossing into the host
    # port; never alter the remote sequence or synthesize a completed mutation.
    latest_rpc = json.loads((ROOT / 'private/member-setup-journal/rpc-mutation.json').read_bytes())
    prior_rpc = json.loads((ROOT / ('private/member-setup-journal/rpc-' + latest_rpc['request_id'] + '.json')).read_bytes())
    assert latest_rpc['completed'] and prior_rpc['reply']['ok']
    host_save('setup-mutation', dict(request=prior_rpc['request'], completed=True, reply=prior_rpc['reply']))
    host_scope = {key: actual_grant[key] for key in ('run_id', 'bundle_sha256', 'started_at', 'deadline_at')}
    remote_setup = MemberRemoteSetup(scope=host_scope, transport=bootstrap_transport,
        grant_file=host_root / 'native-grant.json', load=host_load, save=host_save)
    setup_port_receipts = {}
    def host_stage(stage, owner):
        predecessors = dict(full_application_predecessors=True)
        request = dict(run_id=RUN, bundle_sha256=host_scope['bundle_sha256'], stage=stage,
            predecessors_sha256=digest(predecessors), request_id=digest([host_scope, stage])[:32])
        reply = remote_setup.port(owner).dispatch(stage, request, predecessors)
        if reply.get('completed'): setup_port_receipts[stage] = reply['receipt']
        # Diagnostic phase detail is the real RPC result, not a substitute receipt.
        value = dict(setup_last['result']); value.pop('grant_base64', None)
        value.pop('uncertain_request', None); value.pop('uncertain_cleanup', None)
        return value
    assert php('setup-callback-pins')['roles'] == 1
    pins = {'source-a': json.loads((ROOT / 'private/setup-fixture/source-a-callback.json').read_bytes())}
    observed = php('inspect', uid=65534)
    assert observed['booted'] and observed['kernel'] == 'App\\Http\\Kernel'
    assert observed['uid'] == 65534 and observed['states'] == [None]
    SUMMARY['service_boot'] = observed
    for settings_override in ({'DB_HOST': '203.0.113.1'}, {'APP_DEBUG': 'true'}, {'CACHE_PREFIX': 'outside'},
        {'APP_CONFIG_CACHE': '/tmp/untrusted.php'}, {'APP_BASE_PATH': '/tmp/foreign-app'}, {'APP_ENV': 'production'}):
        rejected = php('inspect', uid=65534, extra=settings_override, ok=False)
        assert rejected['reason'].startswith('member_application_')
    SUMMARY['foreign_configuration_rejected'] = 6

    domain = 'control-' + RUN + '.dnscloudcloud.top'
    certificate, tls_key = work / 'certificate.pem', work / 'key.pem'
    command(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1',
        '-subj', '/CN=' + domain, '-addext', 'subjectAltName=DNS:' + domain,
        '-keyout', str(tls_key), '-out', str(certificate)])

    http_scope = install_scope
    tls_files = {name: dict(sha256=hashlib.sha256(path.read_bytes()).hexdigest(),
        base64=base64.b64encode(path.read_bytes()).decode()) for name, path in [('certificate', certificate), ('key', tls_key)]}
    started_http = install_transport.http_call(dict(operation='start', scope=http_scope, files=tls_files))
    assert started_http['state'] == 'running' and len(started_http['private_files']) == 2
    assert install_transport.http_call(dict(operation='read', scope=http_scope)) == started_http
    class ServiceAddress:
        server_port = 443
    server = ServiceAddress()
    SUMMARY['managed_http_service'] = dict(real_systemd=True, real_nginx=True, real_php=True,
        transient_unit=started_http['unit'], nonroot_php=True, real_tls=True, externally_routed=False,
        app_smoke_real_mysql_redis=True, namespace_property_test_only=True)
    client_tls = ssl.create_default_context(cafile=str(certificate))
    class Client(http.client.HTTPSConnection):
        def connect(self):
            self.sock = client_tls.wrap_socket(socket.create_connection(('127.0.0.1', self.port), 10), server_hostname=domain)

    def callback(secret=None, state='succeeded', task=None, installation=None, role='source-a'):
        callback_pin = pins[role]
        c = Client(domain, server.server_port, timeout=15)
        c.request('POST', '/api/v2/server/machine/provision/result', json.dumps({
            'task_id': task or callback_pin['task_id'], 'installation_id': installation or callback_pin['installation_id'], 'state': state}),
            {'Content-Type': 'application/json', 'Authorization': 'Bearer ' + (secret or callback_pin['secret'])})
        r = c.getresponse(); body = r.read(65536); status = r.status; c.close()
        assert callback_pin['secret'].encode() not in body
        if status == 500: raise AssertionError(json.loads(body))
        return status

    assert callback(task='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa') == 403
    assert callback(installation='a' * 32) == 403
    statuses = [callback('0' * 64), callback(), callback(), callback(state='failed')]
    assert statuses == [401, 200, 200, 409], statuses
    SUMMARY['tls_full_kernel_callback_statuses'] = statuses
    observed = php('inspect', uid=65534)
    assert observed['states'] == ['succeeded'] and observed['callback_steps'] == 1
    SUMMARY['idempotent_callback_b_not_queued'] = True

    if '--agent-http-only' in sys.argv:
        from urllib.parse import urlencode
        pin = pins['source-a']
        def agent(path, method='GET', body=None, overrides=None, installation=None):
            query = dict(machine_id=pin['machine_id'], node_id=pin['node_id'],
                         node_type='v2node', token=pin['token'])
            query.update(overrides or {})
            if path.startswith('/api/v2/server/machine/'):
                query = {}
                body = dict(machine_id=pin['machine_id'], token=pin['token']) if body is None else body
            c = Client(domain, server.server_port, timeout=15)
            c.request(method, path + ('?' + urlencode(query) if query else ''),
                body=None if body is None else json.dumps(body), headers={'Content-Type': 'application/json',
                    'X-Keli-Instance-Id': installation or pin['installation_id']})
            reply = c.getresponse(); raw = reply.read(65536); code = reply.status; c.close()
            assert pin['token'].encode() not in raw
            return code, raw
        checks = {}
        for path, method, body in [
            ('/api/v2/server/machine/nodes', 'POST', None),
            ('/api/v2/server/config', 'GET', None),
            ('/api/v1/server/UniProxy/user', 'GET', None),
            ('/api/v1/server/UniProxy/user_delta', 'GET', None),
            ('/api/v1/server/UniProxy/alivelist', 'GET', None),
            ('/api/v2/server/handshake', 'POST', None),
            ('/api/v2/server/report', 'POST', dict(alive={}, online={}))]:
            status, raw = agent(path, method, body)
            assert status == 200, dict(path=path, status=status)
            checks[path] = status
        assert agent('/api/v2/server/config', overrides=dict(node_id=pin['node_id'] + 1))[0] == 403
        assert agent('/api/v2/server/config', installation='f' * 32)[0] == 403
        assert agent('/api/v2/server/config', overrides=dict(token='wrong'))[0] >= 400
        assert agent('/api/v2/admin/config')[0] == 403
        assert agent('/api/v2/server/report', 'POST', dict(machine_id=pin['machine_id'] + 1))[0] == 403
        assert agent('/api/v2/server/%63onfig')[0] == 403
        def release_request(suffix, query=None):
            path = '/api/v2/server/machine/' + suffix
            if query: path += '?' + urlencode(query)
            c = Client(domain, server.server_port, timeout=15)
            c.request('GET', path)
            reply = c.getresponse(); raw = reply.read(65536); code = reply.status; c.close()
            assert pin['token'].encode() not in raw
            return code, raw
        release_query = dict(machine_id=pin['machine_id'], machine_token=pin['token'])
        script_path = 'kelinode-rs/install.sh'
        latest_path = 'releases/kelinode-rs/linux-x86_64/latest'
        manifest_path = 'releases/kelinode-rs/v0.1.387/linux-x86_64/manifest.json'
        archive_path = 'releases/kelinode-rs/v0.1.387/linux-x86_64/archive.tar.gz'
        code, script = release_request(script_path)
        assert code == 200 and script == (ROOT / 'app/resources/server-machine/kelinode-rs-install.sh').read_bytes()
        code, latest = release_request(latest_path, release_query)
        assert code == 200 and json.loads(latest)['latest_version'] == 'v0.1.387'
        code, manifest = release_request(manifest_path, release_query)
        assert code == 200 and json.loads(manifest)['binary_sha256'] == frozen['node_sha256']
        code, archive = release_request(archive_path, release_query)
        assert code == 200 and hashlib.sha256(archive).hexdigest() == hashlib.sha256((ROOT / 'node/release.tar.gz').read_bytes()).hexdigest()
        assert release_request(latest_path, dict(release_query, machine_token='wrong'))[0] == 403
        assert release_request(latest_path, dict(release_query, machine_id=999999))[0] == 403
        assert release_request(manifest_path.replace('v0.1.387', 'v0.1.388'), release_query)[0] == 403
        published = list((ROOT / 'app/storage/app/kelinode-rs/releases').rglob('*.tar.gz'))
        assert len(published) == 1
        original = published[0].read_bytes()
        published[0].write_bytes(b'changed-fixture-package')
        try: assert release_request(archive_path, release_query)[0] == 403
        finally: published[0].write_bytes(original)
        bootstrap_transport.bootstrap_call('stop')
        def denied_or_stopped(call):
            try: assert call() == 403
            except (ConnectionError, OSError, http.client.HTTPException): pass
        denied_or_stopped(lambda: agent('/api/v2/server/config')[0])
        denied_or_stopped(lambda: agent('/api/v2/server/machine/nodes', 'POST')[0])
        denied_or_stopped(callback)
        denied_or_stopped(lambda: release_request(script_path)[0])
        denied_or_stopped(lambda: release_request(archive_path, release_query)[0])
        SUMMARY['agent_http_admission'] = dict(status='passed', real_kernel=True, real_tls=True,
            actual_service_uid=65534, allowed=checks, identity_conflicts_denied=True,
            original_token_authentication_retained=True, stopped_requests_denied=True,
            agent_process_executed=False, pinned_release_delivery_tested=True, full_round=False,
            installer_and_archive_exact_bytes=True, foreign_version_machine_and_token_denied=True,
            changed_published_package_denied=True, node_bytes_synthetic=True)
        stopped_http = install_transport.http_call(dict(operation='stop', scope=http_scope))
        assert stopped_http['state'] == 'stopped'
        http_service.assert_stopped(ROOT, http_invoke)
        assert http_service.cgroup_empty(http_scope)
        for port in (443, 7001):
            try:
                with socket.create_connection(('127.0.0.1', port), .2): raise AssertionError('service_port_remained')
            except ConnectionRefusedError: pass
        SUMMARY['managed_http_service']['stopped_and_ports_closed'] = True
        SUMMARY['managed_http_service']['cgroup_empty'] = True
        SUMMARY['status'] = 'passed'
        raise SystemExit(0)

    probe_config = None
    def report(role, *, node=False, wrapped_node=False, bad_hash=False, token=None, installation=None, node_id=None):
        pin = pins[role]
        body = dict(machine_id=pin['machine_id'], token=token or pin['token'])
        stats = dict(cpu=1, mem=dict(total=8192, used=128), swap=dict(total=0, used=0), disk=dict(total=16384, used=256))
        if node:
            body.update(node_id=node_id or pin['node_id'], node_type='v2node')
            if wrapped_node: body['status'] = stats
            else: body.update(stats)
            endpoint = '/api/v2/server/status'
        else:
            body['status'] = dict(stats, version='v0.1.387', ip=dict(public_ipv4=pin['ip']),
                runtime=dict(binary_sha256=('e' * 64 if bad_hash else frozen['node_sha256']),
                    observation=dict(schema=1, session=('a' if role == 'source-a' else 'b') * 32,
                        pid=42, started_at=pin['dispatched_at'], uptime_seconds=max(1, int(time.time()) - pin['dispatched_at'])),
                    node_statuses=[dict(node_id=pin['node_id'], protocol='hysteria2', server_port=24443, status='configured')]))
            if probe_config is not None:
                body['status']['entry_probe'] = dict(schema=1, revision=probe_config['revision'],
                    checked_at=int(time.time()), state='ready', targets=[dict(id=t['id'], source_ip=pin['ip'],
                        status='unreachable' if role == 'source-a' or ('--retry-flow' in sys.argv and role == 'candidate-a1')
                            else 'reachable') for t in probe_config['targets']])
            endpoint = '/api/v2/server/machine/status'
        c = Client(domain, server.server_port, timeout=15)
        c.request('POST', endpoint, json.dumps(body), {'Content-Type': 'application/json',
            'X-Keli-Instance-Id': installation or pin['installation_id']})
        r = c.getresponse(); raw = r.read(65536); status = r.status; c.close()
        assert pin['token'].encode() not in raw
        return status

    def advance_due():
        deadline = time.monotonic() + 70
        while True:
            value = host_stage('sources-initialize', 'sources')
            if value.get('reason') != 'native_wait': return value
            assert time.monotonic() < deadline, value
            assert value['retry_at'] > time.time() - 1, value
            time.sleep(max(.1, min(10, value['retry_at'] + 1 - time.time())))

    assert report('source-a', token='incorrect') == 401
    assert report('source-a', installation='f' * 32) == 403
    assert report('source-a', node=True, node_id=pins['source-a']['node_id'] + 1) == 403
    time.sleep(1.1)
    assert report('source-a') == 200
    assert advance_due()['reason'] == 'process'
    assert setup_rpc('status')['sources']['source-a']['status'] == 'waiting_node_reports'
    assert report('source-a', node=True, wrapped_node=True) == 422
    waiting_status = setup_rpc('status')['sources']['source-a']
    assert waiting_status['status'] == 'waiting_node_reports'
    waiting = host_stage('sources-initialize', 'sources')
    assert waiting['reason'] == 'native_wait' and waiting['retry_at'] == waiting_status['next_check_at'], waiting
    node_status = report('source-a', node=True)
    assert node_status == 200, dict(status=node_status, phase='source_node_status')
    assert report('source-a', bad_hash=True) == 200
    assert advance_due()['reason'] == 'process'
    observed = advance_due()
    assert observed['reason'] == 'fresh_reports', observed
    assert setup_rpc('status')['sources']['source-a']['fresh_reports'] == 0

    def register(role):
        for i in range(3):
            time.sleep(1.1)
            assert report(role) == report(role, node=True) == 200
            # Processing a dispatched task and observing its reports are separate native steps.
            result = advance_due()
            if result.get('reason') == 'process': result = advance_due()
            state = setup_rpc('status')['sources'][role]
            assert state['fresh_reports'] == i + 1, state
            if i < 2:
                assert result['reason'] == 'fresh_reports' and not state['registered']
                host_stage('sources-initialize', 'sources')
                assert setup_rpc('status')['sources'][role]['fresh_reports'] == i + 1
            else: assert result['reason'] == 'registered' and state['registered'] and state['status'] == 'accepted'

    register('source-a')
    waiting = host_stage('sources-initialize', 'sources')
    assert waiting['reason'] == 'account_cooldown' and waiting['role'] == 'source-b'
    assert not setup_rpc('status')['sources']['source-b']['queued']
    assert waiting['retry_at'] == pins['source-a']['created_at'] + 300
    cooldown_started = time.monotonic()
    while time.time() < waiting['retry_at'] + 1:
        time.sleep(min(10, waiting['retry_at'] + 1 - time.time()))
    SUMMARY['source_serial_cooldown_wait_seconds'] = int(time.monotonic() - cooldown_started)
    assert host_stage('sources-initialize', 'sources')['reason'] == 'prepare'
    assert host_stage('sources-initialize', 'sources')['reason'] == 'process'
    assert php('setup-callback-pins')['roles'] == 2
    pins['source-b'] = json.loads((ROOT / 'private/setup-fixture/source-b-callback.json').read_bytes())
    assert pins['source-b']['created_at'] - pins['source-a']['created_at'] >= 300
    assert callback(role='source-b') == 200
    register('source-b')
    assert host_stage('sources-initialize', 'sources')['ready']
    edge_dns = host_stage('dns-initialize', 'dns')
    assert edge_dns['ready'] and len(edge_dns['records']) == 2
    assert php('setup-dns-readback')['record_count'] == 3
    registered = setup_rpc('status')
    assert all(row['registered'] and row['status'] == 'accepted' for row in registered['sources'].values())
    assert not registered['health_or_forwarding_claimed'] and registered['first_error'] is None
    probe_config = php('setup-probe-configuration')
    assert probe_config['enabled'] and len(probe_config['targets']) == 4
    grant = host_stage('native-grant', 'authority')
    assert grant['phase'] == 'setup_cooldown' and not grant['ready']
    assert not (ROOT / 'private/member-runtime-scope.json').exists()
    second_cooldown_start = time.monotonic()
    while time.time() < grant['next_check_at'] + 1:
        assert report('source-a') == report('source-a', node=True) == 200
        assert report('source-b') == report('source-b', node=True) == 200
        time.sleep(min(5, max(.1, grant['next_check_at'] + 1 - time.time())))
    for i in range(3):
        assert report('source-a') == report('source-a', node=True) == 200
        assert report('source-b') == report('source-b', node=True) == 200
        grant = host_stage('native-grant', 'authority')
        if i < 2:
            assert grant == dict(ready=False, phase='source_failure_observation'), grant
            assert not (ROOT / 'private/member-runtime-scope.json').exists()
            time.sleep(5.1)
    assert grant['ready'], grant
    assert host_stage('native-ready', 'authority')['ready']
    assert remote_setup.grant_file.read_bytes() == (ROOT / 'private/member-runtime-scope.json').read_bytes()
    SUMMARY['concrete_host_setup_ports'] = dict(actual_root_rpc=True, partial_fixture_setup_before_handoff=True,
        stages=list(setup_port_receipts), private_grant_exact_bytes=True, cloud_ssh_dns_simulated=True,
        whole_host_installer_not_verified=True)
    assert json.loads((ROOT / 'private/setup-fixture/catalog-reads.json').read_bytes())['count'] == 3
    native_path = ROOT / 'private/member-runtime-scope.json'
    native_bytes = native_path.read_bytes()
    cap_path = ROOT / 'member-candidate-callbacks.json'
    cap_bytes = cap_path.read_bytes()
    native_scope = json.loads(native_bytes)
    assert native_scope['started_at'] == json.loads((ROOT / 'member-setup-grant.json').read_bytes())['started_at']
    assert native_path.stat().st_mode & 0o777 == 0o600
    assert cap_path.stat().st_mode & 0o777 == 0o644
    confirmed_readback = dict(grant, uncertain_request=None, uncertain_cleanup=None)
    for _ in range(2):
        assert setup_rpc('native-read') == confirmed_readback
        assert php('setup-native-read') == confirmed_readback
        assert native_path.read_bytes() == native_bytes and cap_path.read_bytes() == cap_bytes
    request = dict(schema=2, **grant['identity'], request_id=os.urandom(16).hex(), sequence=1,
        role='control', operation='round-ready')
    native_reply = command(['php', '-d', 'display_errors=0', '-d', 'log_errors=0',
        str(ROOT / 'runtime/member_transport_entry.php')], input=json.dumps(request).encode(), cwd=ROOT / 'app', env=ENV)
    assert json.loads(native_reply.stdout)['result'] == dict(ready=True, automatic_switches_changed=False)
    cap_backup = ROOT / 'private/candidate-callback-capability.saved'
    cap_path.rename(cap_backup)
    try:
        incomplete = subprocess.run(['php', '-d', 'display_errors=0', '-d', 'log_errors=0',
            str(ROOT / 'runtime/member_transport_entry.php')], input=json.dumps(request).encode(),
            cwd=ROOT / 'app', env=ENV, capture_output=True, timeout=30)
        assert incomplete.returncode != 0 and not json.loads(incomplete.stdout)['ok']
        recovery = command(['php', '-d', 'display_errors=0', '-d', 'log_errors=0',
            str(ROOT / 'runtime/member_transport_entry.php')], input=json.dumps(dict(request, operation='round-status')).encode(),
            cwd=ROOT / 'app', env=ENV)
        assert json.loads(recovery.stdout)['result']['resolved']
        # Readback completes only the identical derivative, never resets the native grant.
        assert setup_rpc('native-read') == confirmed_readback
        assert cap_path.read_bytes() == cap_backup.read_bytes() and native_path.read_bytes() == native_bytes
    finally:
        if not cap_path.exists(): cap_backup.rename(cap_path)
        else: cap_backup.unlink()
    denied_private = subprocess.run(['setpriv', '--reuid=65534', '--regid=65534', '--clear-groups',
        '--no-new-privs', 'test', '-r', str(native_path)], capture_output=True)
    assert denied_private.returncode == 1
    SUMMARY['native_grant_handoff'] = dict(actual_observer_and_cli=True, actual_setup_rpc_and_native_ready=True,
        failure_samples_from_http_synthetic_reports=3, source_b_not_observed=True,
        second_cooldown_real_wait_seconds=int(time.monotonic() - second_cooldown_start),
        original_window_inherited=True, lost_ack_readback_same_grant=True, no_provider_writes_or_candidate_tasks=True,
        expired_editor_catalog_refreshed_without_policy_rewrite=True,
        private_grant_service_user_denied=True, candidate_derivative_public=True,
        incomplete_callback_publication_blocks_native_admission=True,
        incomplete_callback_publication_preserves_recovery_readback=True,
        candidate_http_handoff_tested=False)
    if '--candidate-callbacks' in sys.argv or '--retry-flow' in sys.argv:
        # Host bootstrap receipts bind the unmodified controller-issued grant.
        # SSH is replaced at its byte boundary; the root PHP controllers are real.
        from driver_journal import DriverJournal
        from member_transport import StrictControllerTransport, TransportError
        from member_cli import BoundControllerTransport, MemberControllerPort
        crash_pending = True
        def local_ssh(command_args, payload, timeout):
            global hold_process, crash_pending
            for option in ('BatchMode=yes', 'IdentitiesOnly=yes', 'StrictHostKeyChecking=yes', 'UpdateHostKeys=no'):
                assert option in command_args
            assert command_args[-2] == 'root@9.9.9.10'
            request_body = json.loads(payload)
            remote = command_args[-1]
            if remote == 'python3 -B /opt/keli-isolated/runtime/member_private_cleanup.py':
                p = subprocess.run(['python3', '-B', str(ROOT / 'runtime/member_private_cleanup.py')],
                    input=payload, cwd=ROOT, env=ENV, capture_output=True, timeout=timeout)
                assert p.returncode == 0, 'private_cleanup_entry_rejected'
                if request_body['operation'] == 'remove' and not SUMMARY.get('private_ack_lost'):
                    SUMMARY['private_ack_lost'] = True
                    raise TransportError('transport_remote_failed')
                return p.stdout
            if remote == 'php /opt/keli-isolated/runtime/member_import_entry.php':
                argv = [str(ROOT / 'runtime/member_import_entry.php')]
            elif remote == 'php /opt/keli-isolated/runtime/member_setup_entry.php':
                argv = [str(ROOT / 'runtime/member_setup_entry.php')]
            else:
                assert remote == 'php /opt/keli-isolated/runtime/member_transport_entry.php'
                mode = 'setup-flow-rpc' if '--retry-flow' in sys.argv else 'setup-native-rpc'
                if mode == 'setup-flow-rpc' and SUMMARY.get('native_retry_teardown_started'):
                    mode = 'setup-retry-cleanup-rpc'
                if mode == 'setup-native-rpc' and crash_pending and request_body['operation'] == 'task-delete' and request_body['role'] == 'candidate-a1':
                    mode = 'setup-native-rpc-crash'; crash_pending = False
                argv = [str(ROOT / 'app/tests/Fixtures/member_full_application.php'), mode]
                if mode == 'setup-native-rpc-crash':
                    hold_process = subprocess.Popen(['php', '-d', 'display_errors=0', '-d', 'log_errors=0', *argv],
                        cwd=ROOT / 'app', env=ENV, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
                    hold_process.stdin.write(payload); hold_process.stdin.close()
                    marker = ROOT / 'private/setup-fixture/native-cleanup-crash-boundary.json'
                    for _ in range(200):
                        if marker.exists(): break
                        assert hold_process.poll() is None; time.sleep(.1)
                    else: raise AssertionError('native_candidate_delete_boundary_not_reached')
                    hold_process.kill(); hold_process.wait(timeout=5)
                    assert hold_process.returncode == -9
                    hold_process.stdout.close(); hold_process.stderr.close(); hold_process = None
                    raise TransportError('transport_remote_failed')
            p = subprocess.run(['php', '-d', 'display_errors=0', '-d', 'log_errors=0', *argv], input=payload,
                cwd=ROOT / 'app', env=ENV, capture_output=True, timeout=timeout)
            if p.returncode != 0:
                value = json.loads(p.stdout)
                SUMMARY['native_rpc_last_error'] = {k: value.get(k) for k in ('reason', 'class', 'file', 'line')}
                raise TransportError('transport_remote_failed')
            return p.stdout
        transport_args = dict(run_id=RUN, host='9.9.9.10', identity_file=key, known_hosts=known,
            guard=lambda *_: None, load=host_load, save=host_save, execute=local_ssh)
        bootstrap_transport.execute = local_ssh
        assert bootstrap_transport.setup_call('native-read', actual_setup) == confirmed_readback
        bound = BoundControllerTransport(grant_file=remote_setup.grant_file,
            grant_sha256=hashlib.sha256(native_bytes).hexdigest(), **transport_args)
        assert bound.identity == bootstrap_transport.identity and bound.known == bootstrap_transport.known
        assert hashlib.sha256(bound.identity.read_bytes()).hexdigest() == host_load('bootstrap-binding')['identity_sha256']
        assert bound.identity != tls_key
        if '--retry-flow' in sys.argv:
            from r35_retry_flow_ci import run_retry_flow, run_retry_teardown, run_private_teardown
            run_retry_flow(bound=bound, php=php, report=report, callback=callback, pins=pins,
                           root=ROOT, artifacts=ART, summary=SUMMARY)
            run_retry_teardown(bound=bound, rebind=lambda: BoundControllerTransport(grant_file=remote_setup.grant_file,
                grant_sha256=hashlib.sha256(native_bytes).hexdigest(), **transport_args),
                php=php, callback=callback, report=report, save=host_save, load=host_load,
                work=work, artifacts=ART, summary=SUMMARY)
            cleanup_request = dict(scope=host_scope)
            for owner in ('authority', 'sources', 'dns'):
                assert remote_setup.port(owner).reconcile(cleanup_request)['resolved']
            snapshots = {owner: remote_setup.port(owner).snapshot() for owner in ('authority', 'sources', 'dns')}
            assert len(snapshots['sources']['roles']) == 2 and len(snapshots['dns']['records']) == 3
            assert snapshots['authority']['private_files'] == [dict(run_id=RUN,
                path='/opt/keli-isolated/private/member-runtime-scope.json', sha256=hashlib.sha256(native_bytes).hexdigest())]
            SUMMARY['concrete_host_setup_ports']['stopped_resource_receipts_after_native_cleanup'] = True
            run_private_teardown(transport=bootstrap_transport, root=ROOT, summary=SUMMARY,
                                 artifacts=ART, load=host_load, save=host_save)
            SUMMARY['status'] = 'passed'
            raise SystemExit(0)
        started_native = php('setup-candidate-start')
        assert started_native['task_count'] == 2 and started_native['attempt_count'] == 0, started_native
        assert php('setup-candidate-start') == started_native
        created = php('setup-candidate-step')
        assert created['task_count'] == 3 and created['attempt_count'] == 1, created
        assert created['provider_writes_simulated'] == 1 and created['task_status'] == 'waiting_initialization', created
        assert php('setup-candidate-step')['provider_writes_simulated'] == 0
        assert php('setup-candidate-pins')['roles'] == 1
        pins['candidate-a1'] = json.loads((ROOT / 'private/setup-fixture/candidate-a1-callback.json').read_bytes())
        assert pins['candidate-a1']['machine_id'] == pins['source-a']['machine_id']
        assert pins['candidate-a1']['installation_id'] != pins['source-a']['installation_id']
        assert callback(role='candidate-a1', installation=pins['source-a']['installation_id']) == 403
        assert callback(role='candidate-a1', secret='0' * 64) == 401
        cap_path.rename(cap_backup)
        try:
            assert callback(role='candidate-a1') == 403
            assert report('candidate-a1') == 403
        finally: cap_backup.rename(cap_path)
        candidate_statuses = [callback(role='candidate-a1'), callback(role='candidate-a1'),
                              callback(role='candidate-a1', state='failed')]
        assert candidate_statuses == [200, 200, 409], candidate_statuses
        receipt = php('setup-candidate-inspect')
        assert receipt['callback_state'] == 'succeeded' and receipt['callback_steps'] == 1, receipt
        assert not receipt['health']['ready']
        assert report('candidate-a1', token='incorrect') == 401
        assert report('candidate-a1', installation='e' * 32) == 403
        assert report('candidate-a1', node=True, node_id=pins['source-b']['node_id']) == 403
        time.sleep(1.1)
        assert report('candidate-a1') == 200
        without_node = php('setup-candidate-inspect')
        assert without_node['health'] == dict(agent='fresh', nodes='waiting', ready=False), without_node
        after_machine = php('setup-candidate-step')
        assert after_machine['task_status'] == 'waiting_node_reports', after_machine
        times = []
        for _ in range(3):
            time.sleep(1.1)
            assert report('candidate-a1') == report('candidate-a1', node=True) == 200
            received = php('setup-candidate-inspect')
            assert received['health'] == dict(agent='fresh', nodes='fresh', ready=True), received
            assert received['task_count'] == 3 and received['attempt_count'] == 1
            times.append(received['report_at'])
        assert times == sorted(set(times)) and len(times) == 3
        assert native_path.read_bytes() == native_bytes and cap_path.read_bytes() == cap_bytes
        # The actual fixed CLI must revoke admission even while the native RPC
        # journal is busy, but must not acknowledge before callbacks/RPC drain.
        native_scope = json.loads(native_bytes)
        stop_request = dict(schema=2, run_id=RUN, grant_id=native_scope['grant_id'],
            grant_sha256=hashlib.sha256(native_bytes).hexdigest(), bundle_sha256=native_scope['bundle_sha256'],
            request_id='a' * 32, sequence=0, operation='round-stop', role='control')
        def fixed_native(request, ok):
            process = subprocess.run(['php', '-d', 'display_errors=0', '-d', 'log_errors=0',
                str(ROOT / 'runtime/member_transport_entry.php')], input=json.dumps(request).encode(),
                cwd=ROOT / 'app', env=ENV, capture_output=True, timeout=20)
            reply = json.loads(process.stdout)
            assert (process.returncode == 0) == ok, reply
            assert reply['ok'] is ok, reply
            return reply
        hold_process = subprocess.Popen(['php', '-d', 'display_errors=0', '-d', 'log_errors=0',
            str(ROOT / 'app/tests/Fixtures/member_full_application.php'), 'setup-candidate-hold'],
            cwd=ROOT / 'app', env=ENV, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        for _ in range(100):
            if (ROOT / 'private/setup-fixture/candidate-holding.json').exists(): break
            assert hold_process.poll() is None; time.sleep(.1)
        else: raise AssertionError('candidate_callback_lock_not_ready')
        with (ROOT / 'private/member-journal/rpc.lock').open('a+b') as native_lock:
            os.chmod(native_lock.name, 0o600)
            fcntl.flock(native_lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            for change in ({'grant_id': 'e' * 32}, {'grant_sha256': 'e' * 64}, {'confirmed': True}):
                fixed_native(dict(stop_request, **change), ok=False)
                assert not (ROOT / 'member-setup-stop.json').exists()
            pending_stop = fixed_native(stop_request, ok=False)
            assert pending_stop['diagnostic']['file'] == 'member_setup_authority.php', pending_stop
            assert (ROOT / 'member-setup-stop.json').exists()
            assert not (ROOT / 'private/member-journal/round-stop.json').exists()
            assert callback(role='candidate-a1') == report('candidate-a1') == 403
            hold_process.terminate(); hold_process.wait(timeout=5)
            assert hold_process.returncode != 0; hold_process = None
            busy_stop = fixed_native(stop_request, ok=False)
            assert busy_stop['diagnostic']['code'] == 'isolated_rpc_busy', busy_stop
            assert not (ROOT / 'private/member-journal/round-stop.json').exists()
        stopped_fixed = fixed_native(stop_request, ok=True)
        assert stopped_fixed['result'] == dict(stopped=True)
        assert fixed_native(stop_request, ok=True) == stopped_fixed
        SUMMARY['native_stop_drain'] = dict(actual_fixed_cli=True, actual_inflight_candidate_callback=True,
            actual_exclusive_native_rpc_lock=True, invalid_stop_does_not_revoke=True,
            public_stop_precedes_rpc_lock=True, new_callbacks_and_reports_denied=True,
            acknowledgement_waits_for_callback_and_rpc_drain=True, sigterm_releases_callback_lock=True,
            exact_stop_retry_is_idempotent=True, runtime_or_grant_not_rewritten=True)
        port = MemberControllerPort(bound)
        scope = port('reconcile', dict(run_id=RUN))['cleanup_scope']
        assert set(scope['roles']) == {'source-a', 'source-b', 'candidate-a1'}
        assert scope['roles']['candidate-a1']['external_id'] == '100003'
        assert callback(role='candidate-a1') == report('candidate-a1') == report('candidate-a1', node=True) == 403
        assert php('setup-candidate-inspect') == received
        stopped_step = php('setup-candidate-step', ok=False)
        assert not stopped_step['ok']
        # Auxiliary DNS/material teardown is deliberately left pending in this
        # component; never manufacture a whole-round cleanup receipt.
        def auxiliary(operation):
            if operation == 'restore-faults': return dict(restored=True)  # No fault was injected.
            assert operation == 'dns-and-private-cleanup'
            return dict(pending=True)
        cleanup = port.cleanup_factory(auxiliary, lambda value: host_save('cleanup', value))(scope, None)
        first_cleanup = cleanup.step()
        first_error = json.loads(json.dumps(first_cleanup['first_error']))
        assert first_error and first_cleanup['phase'] == 'tasks' and not crash_pending
        pending_native = json.loads((ROOT / 'private/member-journal/rpc-mutation.json').read_bytes())
        assert pending_native['completed'] is False
        # Restart both host adapter and cleanup from durable state; never repeat
        # the candidate DELETE whose response was interrupted by actual SIGKILL.
        bound = BoundControllerTransport(grant_file=native_path,
            grant_sha256=hashlib.sha256(native_bytes).hexdigest(), **transport_args)
        port = MemberControllerPort(bound)
        cleanup = port.cleanup_factory(auxiliary, lambda value: host_save('cleanup', value))(scope, host_load('cleanup'))
        cleanup_started = time.monotonic()
        for _ in range(8):
            current = cleanup.step()
            assert current['first_error'] == first_error
            if current['phase'] == 'auxiliary': break
            time.sleep(10.1)
        else: raise AssertionError('native_task_cleanup_not_confirmed')
        assert time.monotonic() - cleanup_started >= 10
        assert all(len(reads) == 2 and reads[1] - reads[0] >= 10 for reads in current['reads'].values())
        summary = php('setup-native-rpc-summary')
        assert summary == dict(task_history_unchanged=True, tasks=3, attempts=1,
            provider_rows=0, delete_calls=3, dns_unchanged=True), summary
        dns_first = bound.call('round-dns-cleanup')
        assert not dns_first['complete'] and set(dns_first['records']) == {'edge-a', 'edge-b', 'control'}, dns_first
        assert all(v['complete'] is False for v in dns_first['records'].values()), dns_first
        assert dns_first['records']['edge-a']['diagnostic']['class'] == 'ConnectionException', dns_first
        assert dns_first['records']['edge-b'] == dns_first['records']['control'] == dict(complete=False)
        dns_first_error_path = ROOT / 'private/member-setup-journal/first-error.json'
        dns_first_error = dns_first_error_path.read_bytes()
        dns_absent = bound.call('round-dns-cleanup')
        assert not dns_absent['complete'], dns_absent
        dns_absence_start = time.monotonic()
        time.sleep(10.1)
        bound = BoundControllerTransport(grant_file=native_path,
            grant_sha256=hashlib.sha256(native_bytes).hexdigest(), **transport_args)
        dns_final = bound.call('round-dns-cleanup')
        assert time.monotonic() - dns_absence_start >= 10 and dns_final['complete'], dns_final
        assert all(v == dict(complete=True) for v in dns_final['records'].values()), dns_final
        assert bound.call('round-dns-cleanup') == dns_final
        assert dns_first_error_path.read_bytes() == dns_first_error
        dns_summary = php('setup-native-rpc-dns-summary')
        assert dns_summary == dict(task_history_unchanged=True, records_remaining=0,
            delete_calls=3, create_calls=3, claim_calls=3), dns_summary
        from member_evidence import MemberEvidenceArchive
        evidence_directory = work / 'host-evidence'
        evidence_directory.mkdir(mode=0o700)
        def evidence_ssh(command, payload, timeout):
            assert command[-1] == 'php /opt/keli-isolated/runtime/member_transport_entry.php'
            request = json.loads(payload)
            assert request['operation'] == 'round-evidence' and timeout == 45
            return json.dumps(fixed_native(request, ok=True)).encode()
        evidence_transport = BoundControllerTransport(grant_file=native_path,
            grant_sha256=hashlib.sha256(native_bytes).hexdigest(),
            **dict(transport_args, execute=evidence_ssh))
        archive = MemberEvidenceArchive(evidence_transport, evidence_directory)
        exported = archive.capture()
        body = archive.verify(exported)
        assert body['stopped'] and body['reconciled'] and body['cleanup_complete_not_asserted'], body
        assert set(body['tasks']) == {'source-a', 'source-b', 'candidate-a1'}
        assert all(t['binding_current'] for t in body['tasks'].values()), body
        assert body['setup']['available'] and body['setup']['first_error_present'], body
        assert body['setup']['first_diagnostic']['class'] == 'ConnectionException', body
        archive = MemberEvidenceArchive(evidence_transport, evidence_directory)
        assert archive.capture() == exported and archive.verify(exported) == body
        raw_evidence = (evidence_directory / ('evidence-' + exported['sha256'] + '.json')).read_bytes()
        assert all(secret.encode() not in raw_evidence for secret in ('s' * 32, 'r' * 32))
        assert not any(key in raw_evidence for key in (b'api_token', b'access_key_secret', b'callback_hash', b'"payload"'))
        assert all(p.stat().st_mode & 0o777 == 0o600 for p in evidence_directory.iterdir())
        evidence_artifact = ART / 'native-evidence.json'
        evidence_artifact.write_bytes(raw_evidence); evidence_artifact.chmod(0o644)
        assert evidence_artifact.read_bytes() == raw_evidence
        assert php('setup-native-rpc-dns-summary') == dns_summary
        assert php('setup-native-rpc-summary') == dict(summary, dns_unchanged=False)
        assert dns_first_error_path.read_bytes() == dns_first_error
        SUMMARY['native_evidence_export'] = dict(actual_fixed_cli=True, actual_mysql_and_setup_journal=True,
            stopped_only=True, host_byte_transport_simulated=True, cloud_or_dns_requests=0,
            local_atomic_journal_and_readback=True, restart_same_digest=True, files_mode_0600=True,
            setup_first_error_preserved=True, task_history_preserved=True, payloads_and_credentials_excluded=True,
            evidence_digest=exported['sha256'], cleanup_completion_not_claimed=True,
            private_removal_and_controller_deletion_pending=True)
        SUMMARY['native_dns_cleanup_handoff'] = dict(actual_shared_root_rpc_and_setup_journal=True,
            actual_host_bound_transport=True, ssh_byte_transport_simulated=True,
            original_dns_create_receipts_preserved=True, no_native_dns_cutover_in_this_component=True,
            exact_signed_provider_deletes_simulated=3, host_restart_retains_selected_pins=True,
            lost_delete_ack_reconciled_and_peer_cleanup_continues=True, setup_first_error_retained=True,
            two_absence_reads_at_least_ten_seconds_apart=True, duplicate_cleanup_no_repeat_delete=True,
            task_history_retained=True, persistent_gates_changed=False,
            native_a2_cutover_cleanup_unit_tested=True, native_a2_cutover_full_application_tested=False,
            private_cleanup_pending=True, controller_delete_not_claimed=True)
        assert native_path.read_bytes() == native_bytes and cap_path.read_bytes() == cap_bytes
        assert callback(role='candidate-a1') == report('candidate-a1') == 403
        SUMMARY['native_task_cleanup_handoff'] = dict(actual_shared_root_rpc_controller=True,
            actual_host_bound_transport_and_cleanup=True, ssh_byte_transport_simulated=True,
            bootstrap_and_setup_receipts_from_actual_controllers=True, remote_grant_not_rewritten=True,
            scope_from_actual_native_lineage=True, actual_sigkill_after_simulated_candidate_delete=True,
            pending_native_rpc_and_exact_delete_intents_retained=True, host_resume_no_duplicate_delete=True,
            source_delete_lost_ack_reconciled=True, exact_deletes_simulated=3,
            task_history_preserved=3, native_attempts_preserved=1, first_error_retained=True,
            real_separated_absence_reads_per_role=2, minimum_absence_spacing_seconds=10,
            no_fault_injected=True, dns_cleanup_tested=True, private_cleanup_pending=True, controller_delete_not_claimed=True,
            a1_retry_cooldown_a2_not_tested=True)
        assert php('setup-queues')['no_redis_queued_delayed_or_reserved_jobs']
        SUMMARY['candidate_http_handoff'] = dict(actual_native_start_and_advance=True,
            actual_mysql_task_count=3, actual_native_attempt_count=1, candidate_provider_create_simulated=1,
            duplicate_start_and_step_no_additional_create=True, callback_statuses=candidate_statuses,
            actual_tls_and_service_uid=True, actual_kernel_callback_and_report_routes=True,
            callback_requires_original_native_lineage=True, missing_callback_capability_denies=True,
            bad_installation_token_and_node_denied=True, missing_node_report_not_ready=True,
            three_distinct_http_receive_times=True, report_payloads_synthetic=True,
            native_readiness_dns_advance_not_exercised=True, b_and_dns_unchanged=True,
            stop_denies_candidate_callback_report_and_advance=True, immutable_grants_retained=True,
            queue_jobs_did_not_escape=True, native_candidate_task_cleanup_tested=True,
            native_dns_cleanup_handoff_tested=True, private_cleanup_handoff_pending=True)
        SUMMARY['native_grant_handoff']['candidate_http_handoff_tested'] = True
        SUMMARY['candidate_callback_http_full_application_tested'] = True
        SUMMARY['native_grant_handoff']['stop_revokes_both_native_and_callback_authorities'] = True
        SUMMARY['status'] = 'passed'
        # This component keeps task history intact; the enclosing isolated fixture
        # owns teardown. It does not claim native candidate/final cleanup coverage.
        raise SystemExit(0)
    hold_process = subprocess.Popen(['php', '-d', 'display_errors=0', '-d', 'log_errors=0',
        str(ROOT / 'app/tests/Fixtures/member_full_application.php'), 'setup-controller-hold'],
        cwd=ROOT / 'app', env=ENV, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    for _ in range(100):
        if (ROOT / 'private/setup-fixture/controller-holding.json').exists(): break
        assert hold_process.poll() is None; time.sleep(.1)
    else: raise AssertionError('controller_lock_not_ready')
    assert not setup_rpc('status', ok=False)['ok']
    hold_process.kill(); hold_process.wait(timeout=5); hold_process = None
    assert setup_rpc('status') == registered
    assert php('setup-controller-first-error')['first_error_safe_and_retained']
    SUMMARY['dual_sources'] = dict(actual_setup_controller=True, actual_status_and_stop_cli=True,
        native_tasks_queued_dispatched_registered=2, provider_creates_simulated=2,
        actual_machine_and_node_http_routes=True, reports_are_synthetic_client_input=True,
        fresh_samples_per_role=3, no_node_report_blocks_registration=True,
        wrong_binary_blocks_registration=True, repeated_observation_does_not_add_samples=True,
        bad_token_installation_and_node_refused=True, account_cooldown_real_seconds=300,
        second_source_not_queued_during_cooldown=True, first_source_stays_registered=True,
        exclusive_controller_lock=True, sigkill_releases_controller_lock=True,
        restart_reads_same_registered_state=True, missing_intent_retains_safe_first_error=True,
        no_health_or_forwarding_claimed=True, node_binary_installation_tested=False)
    hold_process = subprocess.Popen(['php', '-d', 'display_errors=0', '-d', 'log_errors=0',
        str(ROOT / 'app/tests/Fixtures/member_full_application.php'), 'setup-hold'],
        cwd=ROOT / 'app', env=ENV, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    for _ in range(100):
        if (ROOT / 'private/setup-fixture/holding.json').exists(): break
        assert hold_process.poll() is None
        time.sleep(.1)
    else: raise AssertionError('inflight_setup_not_ready')
    with import_lock.open('r+b') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        stopping = php('setup-stop-busy')
        assert stopping['drain_pending'] and stopping['bootstrap_stop']
        assert callback() == 403
    hold_process.terminate(); hold_process.wait(timeout=5)
    assert hold_process.returncode != 0
    hold_process = None
    assert setup_rpc('stop')['stopped']
    assert not setup_rpc('native-read', ok=False)['ok']
    stopped = subprocess.run(['php', '-d', 'display_errors=0', '-d', 'log_errors=0',
        str(ROOT / 'runtime/member_transport_entry.php')], input=json.dumps(request).encode(),
        cwd=ROOT / 'app', env=ENV, capture_output=True, timeout=30)
    assert stopped.returncode != 0 and not json.loads(stopped.stdout)['ok']
    assert native_path.read_bytes() == native_bytes and cap_path.read_bytes() == cap_bytes
    SUMMARY['native_grant_handoff']['stop_revokes_both_native_and_callback_authorities'] = True
    assert php('setup-stop')['database_not_required']
    assert php('setup-stop')['stopped']
    assert php('setup-stopped-readback')['readback_allowed']
    hold_process = subprocess.Popen(['php', '-d', 'display_errors=0', '-d', 'log_errors=0',
        str(ROOT / 'app/tests/Fixtures/member_full_application.php'), 'setup-cleanup-crash'],
        cwd=ROOT / 'app', env=ENV, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    fixture_root = ROOT / 'private/setup-fixture'
    for _ in range(200):
        if (fixture_root / 'cleanup-crash-boundary.json').exists(): break
        assert hold_process.poll() is None; time.sleep(.1)
    else: raise AssertionError('delete_commit_crash_boundary_not_reached')
    crash_request = json.loads((fixture_root / 'cleanup-crash-request.json').read_bytes())['request']
    journal = ROOT / 'private/member-setup-journal'
    uncertain = json.loads((journal / 'rpc-cleanup.json').read_bytes())
    assert uncertain == dict(sequence=crash_request['sequence'], request_id=crash_request['request_id'], completed=False)
    assert 'reply' not in json.loads((journal / ('rpc-' + crash_request['request_id'] + '.json')).read_bytes())
    assert json.loads((fixture_root / 'cleanup-delete-count.json').read_bytes())['value'] == 2
    assert not json.loads((fixture_root / 'provider-rows.json').read_bytes())
    provider_dns = json.loads((fixture_root / 'setup-dns-provider.json').read_bytes())
    assert provider_dns['delete'] == 3 and not provider_dns['rows']
    hold_process.kill(); hold_process.wait(timeout=5)
    assert hold_process.returncode == -9
    hold_process = None
    recovered = setup_rpc('status')
    assert recovered['uncertain_cleanup'] == uncertain
    assert recovered['first_error'] == json.loads((fixture_root / 'cleanup-crash-request.json').read_bytes())['first_error']
    assert not setup_rpc('advance', ok=False)['ok']
    first_cleanup = php('setup-cleanup-resume')
    assert not first_cleanup['complete'] and first_cleanup['delete_calls'] == 2
    assert all(v['classification'] == 'exact_absence' and not v['complete'] for v in first_cleanup['sources'].values())
    assert not setup_rpc('status')['uncertain_cleanup']
    failed_read = php('setup-cleanup-read-error')
    assert not failed_read['complete'] and failed_read['sources']['source-b']['classification'] == 'cleanup_unconfirmed'
    time.sleep(10.1)
    resumed_cleanup = php('setup-cleanup')
    assert resumed_cleanup['sources']['source-a']['complete'] and not resumed_cleanup['sources']['source-b']['complete']
    cleanup_absence_at = time.monotonic()
    time.sleep(10.1)
    completed_cleanup = php('setup-cleanup')
    assert time.monotonic() - cleanup_absence_at >= 10 and completed_cleanup['complete']
    assert completed_cleanup['delete_calls'] == 2 and completed_cleanup['task_history_unchanged']
    assert completed_cleanup['dns']['complete'] and completed_cleanup['dns_delete_calls'] == 3
    stopped_dns = php('setup-dns-readback')
    assert stopped_dns['record_count'] == 3 and not any(stopped_dns['ready'].values())
    assert stopped_dns['provider_delete'] == 3
    SUMMARY['setup_bound_dns'] = dict(actual_setup_authority_and_rpc=True,
        control_identity_and_public_ipv4_read_from_mock_provider=True,
        control_dns_before_source_tasks=True, edge_dns_after_both_registered=True,
        edge_markers_from_native_record_sets=True, signed_aliyun_requests_simulated=True,
        creates=3, claims=3, deletes=3, duplicate_rpc_does_not_repeat_writes=True,
        stop_denies_dns_mutations=True, stopped_readback_and_cleanup_allowed=True,
        real_absence_spacing_at_least_seconds=10, execution_gate_restored=True,
        whole_round_ports_composed=False)
    SUMMARY['setup_source_cleanup'] = dict(actual_setup_rpc=True, native_cleanup_adapter=True,
        exact_provider_deletes_simulated=2, lost_delete_ack_reconciled_without_repeat=True,
        peer_cleanup_continues_after_first_role_error=True, real_absence_spacing_at_least_seconds=10,
        intervening_503_resets_consecutive_proof=True, duplicate_rpc_cached=True,
        first_error_and_task_history_retained=True, advance_stays_revoked=True,
        native_execution_grant_required=False, live_cloud_calls=False)
    SUMMARY['setup_rpc_crash'] = dict(actual_linux_sigkill=True, kill_after_mock_provider_delete_commit=True,
        actual_rpc_pending_journal=True, no_success_reply_before_kill=True,
        restart_status_exposes_uncertain_cleanup=True, identical_cleanup_request_resumed=True,
        source_and_dns_deletes_not_repeated=True, fresh_absence_confirmation_required=True,
        first_error_and_task_history_unchanged=True, new_mutation_stays_denied=True,
        cloud_side_effects_simulated=True, mutation_phase_crash_tested=False)
    assert php('setup-queues')['no_redis_queued_delayed_or_reserved_jobs']
    assert callback() == 403
    with import_lock.open('r+b') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        assert import_rpc('stop', ok=False)['reason'] == 'member_import_busy'
        assert (ROOT / 'private/import-stopped').exists()
    assert import_rpc('stop', extra={'DB_PORT': '1', 'REDIS_PORT': '1'})['stopped']
    import_denied('member_import_expired_or_stopped')
    assert import_rpc('readback')['scope'] == imported['scope']
    assert import_rpc('configure', ok=False)['reason'] == 'member_import_expired_or_stopped'
    assert import_rpc('configuration-read')['configuration'] == read_configuration
    assert import_rpc('setup-read')['setup'] == actual_setup
    assert php('configuration-stop')['configuration_sha256'] == configured['configuration_sha256']
    SUMMARY['prerequisite_import']['stop_denies_import_allows_readback'] = True
    SUMMARY['source_configuration']['stop_blocks_mutation_allows_readback'] = True
    SUMMARY['bootstrap_entry'] = dict(actual_fixed_cli_readback_and_replay=True,
        actual_configuration_and_grant_controller=True, scope_from_actual_import_not_request=True,
        original_started_and_deadline_inherited=True, caller_cannot_extend_window=True,
        same_grant_reused_no_reissue=True, lost_result_reconciled_by_existing_grant=True,
        changed_control_refused=True, stop_marker_precedes_lock_drain=True,
        callback_revoked_before_bootstrap_lock_drain=True,
        stop_does_not_boot_database_or_cache=True, stopped_readbacks_allowed=True,
        whole_round_installation_and_native_ports_composed=False)
    SUMMARY['setup_authority'] = dict(actual_capability=True, real_mysql_redis_and_service_uid=True,
        native_source_queue_and_dispatch=True, provider_creates_simulated=2, duplicate_create_prevented=True,
        root_only_issue_and_revoke=True, bad_task_and_installation_refused=True,
        policy_and_release_drift_refused=True, persistent_gates_changed=False,
        exception_restores_capability_and_bus=True, callback_uses_actual_front_controller=True,
        stop_blocks_new_callbacks_before_inflight_drained=True, sigterm_releases_inflight_lock=True,
        revoke_without_database=True, stopped_readback_allowed=True,
        redis_queues_delayed_reserved_and_failed_jobs_empty=True,
        expiry_boundary_unit_tested=True, real_elapsed_expiry_tested=False,
        b_bootstrap_tested=False, both_sources_registered=True, no_health_verdict_fabricated=True)
    prepared = php('dns-prepare')
    assert prepared['prepared'] and prepared['encrypted_credential'] and prepared['redis_counter'] == 1, prepared
    assert php('dns-control')['ready']
    lost = php('dns-edge-a-lost')
    assert lost['provider_failure'] and lost['create_calls'] == 2 and lost['claim_calls'] == 2
    readback = php('dns-edge-a-read')
    resumed = php('dns-edge-a')
    assert readback['ready'] and resumed['ready'] and resumed['create_calls'] == 2 and resumed['claim_calls'] == 2
    denied = php('dns-edge-b-reject')
    assert denied['provider_failure'] and denied['create_calls'] == 3 and denied['claim_calls'] == 3
    assert php('dns-stop')['stopped']
    assert php('dns-control', ok=False)['reason'] == 'fixture_dns_stopped'
    first = php('dns-cleanup')
    assert first['absent'] == 3 and first['delete_calls'] == 3
    absent_at = time.monotonic()
    time.sleep(10.1)
    second = php('dns-cleanup')
    assert time.monotonic() - absent_at >= 10 and second['absent'] == 3
    assert second['delete_calls'] == 3 and second['create_calls'] == 3 and second['claim_calls'] == 3
    assert second['redis_counter'] == 10 and second['closed_gates_unchanged']
    SUMMARY['dns_initialization'] = dict(full_application=True, encrypted_mysql_credential=True,
        real_redis=True, redis_read_type=prepared['redis_read_type'],
        durable_journal_across_php_processes=True, create_calls=3, claim_calls=3,
        delete_calls=3, claim_ack_loss_reconciled_without_replay=True,
        unclaimed_record_cleaned_by_exact_create_ack=True, stopped_creation_denied=True,
        two_absence_reads_at_least_ten_seconds_apart=True, persistent_gates_changed=False,
        signed_provider_requests_simulated=True, concrete_setup_authority_tested=False)
    SUMMARY['status'] = 'passed'
except SystemExit as error:
    if error.code != 0 or SUMMARY['status'] != 'passed':
        SUMMARY['status'] = 'failed'
        raise
except BaseException as error:
    SUMMARY['status'] = 'failed'; SUMMARY['failure_class'] = type(error).__name__
    if isinstance(error, AssertionError): SUMMARY['failure_check'] = str(error)[:600]
    raise
finally:
    if hold_process is not None:
        hold_process.terminate()
        try: hold_process.wait(timeout=5)
        except subprocess.TimeoutExpired: hold_process.kill(); hold_process.wait(timeout=5)
    http_clean = True
    if http_scope is not None:
        try:
            stopped = http_service.execute(dict(operation='stop', scope=http_scope), invoke=http_invoke)
            assert stopped['state'] == 'stopped'
            http_service.assert_stopped(ROOT, http_invoke)
        except BaseException as failure:
            http_clean = False
            SUMMARY['http_cleanup_failure'] = type(failure).__name__
            SUMMARY['status'] = 'failed'
    if php_server is not None:
        php_server.terminate()
        try: php_server.wait(timeout=5)
        except subprocess.TimeoutExpired: php_server.kill(); php_server.wait(timeout=5)
    if ROOT.exists() and http_clean:
        assert ROOT.resolve() == ROOT and (ROOT / '.r35-application-fixture').read_text() == sha
        shutil.rmtree(ROOT)
    if opt_stat is not None:
        os.chown('/opt', opt_stat.st_uid, opt_stat.st_gid); os.chmod('/opt', opt_stat.st_mode & 0o7777)
    if work is not None:
        assert work.resolve() == work and work.parent == Path(tempfile.gettempdir()) and work.name.startswith('r35-app-fixture-')
        shutil.rmtree(work)
    SUMMARY['fixed_fixture_removed'] = not ROOT.exists()
    if 'native_evidence_export' in SUMMARY:
        retained = json.loads((ART / 'native-evidence.json').read_bytes())
        assert hashlib.sha256(retained['content'].encode()).hexdigest() == retained['sha256'] == SUMMARY['native_evidence_export']['evidence_digest']
        SUMMARY['native_evidence_export']['artifact_readback_after_controller_fixture_removed'] = not ROOT.exists()
    SUMMARY['external_routes_after'] = bool(command(['ip', 'route']).stdout.strip())
    SUMMARY['external_ipv6_routes_after'] = bool(command(['ip', '-6', 'route']).stdout.strip())
    path = ART / 'application-linux-summary.json'
    path.write_text(json.dumps(SUMMARY, indent=2)); path.chmod(0o644)
