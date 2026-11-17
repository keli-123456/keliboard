"""Temporary CI-only full application verification; no real cloud/SSH/DNS."""
import base64
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
    if ok and p.returncode != 0: raise AssertionError(value)
    if not ok: assert p.returncode != 0
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
        'test_member_transport', 'test_member_bundle', 'test_member_runtime_bundle'],
        cwd=TOOLS, capture_output=True, text=True, timeout=240)
    (ART / 'python.log').write_text(tests.stdout + tests.stderr)
    assert tests.returncode == 0, 'python_regression_failed'
    SUMMARY['python_passed'] = True
    php_tests = subprocess.run(['php', 'vendor/phpunit/phpunit/phpunit',
        'tests/Unit/Services/MachineMemberDnsInitializationTest.php',
        'tests/Unit/Services/MachineMemberSourceConfigurationTest.php',
        'tests/Unit/Services/MachineMemberSetupAuthorityTest.php',
        'tests/Unit/Services/MachineMemberProviderBindingsTest.php',
        'tests/Unit/Services/MachineDnsAliyunCompatibilityTest.php'],
        cwd=REPO, capture_output=True, text=True, timeout=120)
    (ART / 'dns-phpunit.log').write_text(php_tests.stdout + php_tests.stderr)
    assert php_tests.returncode == 0, 'dns_phpunit_regression_failed'
    SUMMARY['dns_phpunit_passed'] = True
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
    member_bundle.freeze(owned_repo, sha, application, RUN)
    node = work / 'synthetic-node.tar.gz'
    binary = b'\x7fELF\x02\x01' + b'\0' * 12 + b'\x3e\x00' + b'synthetic-not-executed'
    with tarfile.open(node, 'w:gz') as archive:
        info = tarfile.TarInfo(runtime.NODE_MEMBER); info.size = len(binary)
        archive.addfile(info, io.BytesIO(binary))
    with patch.object(runtime, 'NODE_ARCHIVE_SHA', hashlib.sha256(node.read_bytes()).hexdigest()), \
         patch.object(runtime, 'NODE_SHA', hashlib.sha256(binary).hexdigest()):
        bundle = work / 'runtime.tar.gz'
        frozen = runtime.assemble(application, owned_repo / 'vendor', node, bundle)
        installed = MemberInstallation(ROOT).publish_runtime(bundle, frozen['archive_sha256'])
        assert installed['complete']
    settings = dict(app_key=base64.b64encode(b'x' * 32).decode(), database='keli_acceptance_application',
        username='keli_application', password='s' * 32, mysql_port=13306,
        redis_password='r' * 32, redis_port=16379)
    configure(ROOT, frozen['archive_sha256'], settings, uid=65534, gid=65534)
    SUMMARY['application_files'] = frozen['files']
    SUMMARY['composer_lock_sha256'] = frozen['composer_lock_sha256']
    SUMMARY['migration'] = php('migrate')
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
    SUMMARY['source_configuration'] = dict(actual_application_services=True, real_mysql_transactions=True,
        real_redis_locks=True, provider_reads_simulated=True, provider_writes=0,
        prerequisite_credentials_and_release_synthetic=True, concrete_import_authority_tested=False,
        machines=2, nodes=2, policies=2, services=1, members=2, dns_sets=2, dns_slots=2,
        failure_rollback_phases=7, uncertain_replay_refused=True, first_error_safe_and_retained=True,
        process_restart_readback=True, duplicate_creation_refused=True,
        recovery_does_not_reselect_credentials=True, lost_result_prevents_replay=True,
        existing_configuration_without_intent_not_adopted=True,
        node_policy_credential_drift_refused=True, stop_blocks_mutation_allows_readback=True,
        tasks_or_health_fabricated=False, persistent_gates_changed=False)
    SUMMARY['setup_issue'] = php('setup-issue')
    assert SUMMARY['setup_issue']['issued'] and SUMMARY['setup_issue']['reissue_refused']
    assert php('configuration-stop')['configuration_sha256'] == configured['configuration_sha256']
    assert php('setup-service-denied', uid=65534)['root_operations_denied']
    assert php('setup-drift')['policy_and_release_drift_refused']
    preparation = php('setup-prepare')
    assert preparation['native_task_queued'] and preparation['duplicate_preparation_idempotent']
    assert preparation['exception_restores_capability_and_bus'] and preparation['provider_writes'] == 0
    dispatched = php('setup-process')
    assert dispatched['native_dispatch'] and dispatched['provider_writes_simulated'] == 1
    assert php('setup-process-repeat')['provider_writes_simulated'] == 0
    callback_pin = json.loads((ROOT / 'private/setup-fixture/callback.json').read_bytes())
    observed = php('inspect', uid=65534)
    assert observed['booted'] and observed['kernel'] == 'App\\Http\\Kernel'
    assert observed['uid'] == 65534 and observed['states'] == [None]
    SUMMARY['service_boot'] = observed
    for settings_override in ({'DB_HOST': '203.0.113.1'}, {'APP_DEBUG': 'true'}, {'CACHE_PREFIX': 'outside'},
        {'APP_CONFIG_CACHE': '/tmp/untrusted.php'}, {'APP_BASE_PATH': '/tmp/foreign-app'}, {'APP_ENV': 'production'}):
        rejected = php('inspect', uid=65534, extra=settings_override, ok=False)
        assert rejected['reason'].startswith('member_application_')
    SUMMARY['foreign_configuration_rejected'] = 6

    with socket.socket() as port_socket:
        port_socket.bind(('127.0.0.1', 0)); http_port = port_socket.getsockname()[1]
    php_server = subprocess.Popen(['setpriv', '--reuid=65534', '--regid=65534', '--clear-groups',
        '--no-new-privs', '--bounding-set=-all', 'php', '-d', 'display_errors=0', '-d', 'log_errors=0',
        '-S', '127.0.0.1:' + str(http_port), str(ROOT / 'runtime/member_callback_entry.php')],
        cwd=ROOT / 'app', env=ENV, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    for _ in range(100):
        try:
            with socket.create_connection(('127.0.0.1', http_port), .2): break
        except OSError:
            assert php_server.poll() is None; time.sleep(.1)
    else: raise AssertionError('http_fixture_not_ready')
    domain = 'control-' + RUN + '.dnscloudcloud.top'
    certificate, key = work / 'certificate.pem', work / 'key.pem'
    command(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1',
        '-subj', '/CN=' + domain, '-addext', 'subjectAltName=DNS:' + domain,
        '-keyout', str(key), '-out', str(certificate)])

    class Proxy(BaseHTTPRequestHandler):
        def do_POST(self):
            size = int(self.headers.get('Content-Length', '0'))
            assert 0 <= size <= 4096
            c = http.client.HTTPConnection('127.0.0.1', http_port, timeout=10)
            c.request('POST', self.path, body=self.rfile.read(size), headers={
                'Content-Type': 'application/json', 'Authorization': self.headers.get('Authorization', ''),
                'Host': domain, 'X-Forwarded-Proto': 'https'})
            r = c.getresponse(); body = r.read(65536)
            self.send_response(r.status); self.send_header('Content-Type', 'application/json')
            self.send_header('Content-Length', str(len(body))); self.end_headers(); self.wfile.write(body); c.close()
        def log_message(self, *_): pass

    server = ThreadingHTTPServer(('127.0.0.1', 0), Proxy)
    tls = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER); tls.load_cert_chain(certificate, key)
    server.socket = tls.wrap_socket(server.socket, server_side=True)
    threading.Thread(target=server.serve_forever, daemon=True).start()
    client_tls = ssl.create_default_context(cafile=str(certificate))
    class Client(http.client.HTTPSConnection):
        def connect(self):
            self.sock = client_tls.wrap_socket(socket.create_connection(('127.0.0.1', self.port), 10), server_hostname=domain)

    def callback(secret=None, state='succeeded', task=None, installation=None):
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
    hold_process = subprocess.Popen(['php', '-d', 'display_errors=0', '-d', 'log_errors=0',
        str(ROOT / 'app/tests/Fixtures/member_full_application.php'), 'setup-hold'],
        cwd=ROOT / 'app', env=ENV, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    for _ in range(100):
        if (ROOT / 'private/setup-fixture/holding.json').exists(): break
        assert hold_process.poll() is None
        time.sleep(.1)
    else: raise AssertionError('inflight_setup_not_ready')
    assert php('setup-stop-busy')['drain_pending']
    assert callback() == 403
    hold_process.terminate(); hold_process.wait(timeout=5)
    assert hold_process.returncode != 0
    hold_process = None
    assert php('setup-stop')['database_not_required']
    assert php('setup-stop')['stopped']
    assert php('setup-stopped-readback')['readback_allowed']
    assert callback() == 403
    SUMMARY['setup_authority'] = dict(actual_capability=True, real_mysql_redis_and_service_uid=True,
        native_source_queue_and_dispatch=True, provider_creates_simulated=1, duplicate_create_prevented=True,
        root_only_issue_and_revoke=True, bad_task_and_installation_refused=True,
        policy_and_release_drift_refused=True, persistent_gates_changed=False,
        exception_restores_capability_and_bus=True, callback_uses_actual_front_controller=True,
        stop_blocks_new_callbacks_before_inflight_drained=True, sigterm_releases_inflight_lock=True,
        revoke_without_database=True, stopped_readback_allowed=True,
        expiry_boundary_unit_tested=True, real_elapsed_expiry_tested=False,
        b_bootstrap_tested=False, b_not_queued=True, no_health_verdict_fabricated=True)
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
except BaseException as error:
    SUMMARY['status'] = 'failed'; SUMMARY['failure_class'] = type(error).__name__
    if isinstance(error, AssertionError): SUMMARY['failure_check'] = str(error)[:600]
    raise
finally:
    if hold_process is not None:
        hold_process.terminate()
        try: hold_process.wait(timeout=5)
        except subprocess.TimeoutExpired: hold_process.kill(); hold_process.wait(timeout=5)
    if server is not None: server.shutdown(); server.server_close()
    if php_server is not None:
        php_server.terminate()
        try: php_server.wait(timeout=5)
        except subprocess.TimeoutExpired: php_server.kill(); php_server.wait(timeout=5)
    if ROOT.exists():
        assert ROOT.resolve() == ROOT and (ROOT / '.r35-application-fixture').read_text() == sha
        shutil.rmtree(ROOT)
    if opt_stat is not None:
        os.chown('/opt', opt_stat.st_uid, opt_stat.st_gid); os.chmod('/opt', opt_stat.st_mode & 0o7777)
    if work is not None:
        assert work.resolve() == work and work.parent == Path(tempfile.gettempdir()) and work.name.startswith('r35-app-fixture-')
        shutil.rmtree(work)
    SUMMARY['fixed_fixture_removed'] = not ROOT.exists()
    SUMMARY['external_routes_after'] = bool(command(['ip', 'route']).stdout.strip())
    SUMMARY['external_ipv6_routes_after'] = bool(command(['ip', '-6', 'route']).stdout.strip())
    path = ART / 'application-linux-summary.json'
    path.write_text(json.dumps(SUMMARY, indent=2)); path.chmod(0o644)
