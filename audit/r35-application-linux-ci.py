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
    source_tests = subprocess.run(['php', 'vendor/phpunit/phpunit/phpunit',
        'tests/Unit/Services/MachineFailoverNativeBridgeTest.php', '--filter', 'test_member_initialization_'],
        cwd=REPO, capture_output=True, text=True, timeout=120)
    (ART / 'source-phpunit.log').write_text(source_tests.stdout + source_tests.stderr)
    assert source_tests.returncode == 0, 'source_phpunit_regression_failed'
    SUMMARY['source_phpunit_passed'] = True
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
    control_dns = php('setup-dns-control')
    assert control_dns['ready'] and control_dns['record_count'] == 1
    assert control_dns['provider_create'] == control_dns['provider_claim'] == 1
    preparation = php('setup-prepare')
    assert preparation['native_task_queued'] and preparation['b_not_queued']
    assert preparation['exception_restores_capability_and_bus'] and preparation['provider_writes'] == 0
    dispatched = php('setup-process')
    assert dispatched['native_dispatch'] and dispatched['provider_writes_simulated'] == 1
    assert php('setup-process-repeat')['provider_writes_simulated'] == 0
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
                'X-Keli-Instance-Id': self.headers.get('X-Keli-Instance-Id', ''),
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
                runtime=dict(binary_sha256=('e' if bad_hash else 'd') * 64,
                    observation=dict(schema=1, session=('a' if role == 'source-a' else 'b') * 32,
                        pid=42, started_at=pin['dispatched_at'], uptime_seconds=max(1, int(time.time()) - pin['dispatched_at'])),
                    node_statuses=[dict(node_id=pin['node_id'], protocol='hysteria2', server_port=24443, status='configured')]))
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
            value = php('setup-advance')
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
    waiting = php('setup-advance')
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
                php('setup-advance')
                assert setup_rpc('status')['sources'][role]['fresh_reports'] == i + 1
            else: assert result['reason'] == 'registered' and state['registered'] and state['status'] == 'accepted'

    register('source-a')
    waiting = php('setup-advance')
    assert waiting['reason'] == 'account_cooldown' and waiting['role'] == 'source-b'
    assert not setup_rpc('status')['sources']['source-b']['queued']
    assert waiting['retry_at'] == pins['source-a']['created_at'] + 300
    cooldown_started = time.monotonic()
    while time.time() < waiting['retry_at'] + 1:
        time.sleep(min(10, waiting['retry_at'] + 1 - time.time()))
    SUMMARY['source_serial_cooldown_wait_seconds'] = int(time.monotonic() - cooldown_started)
    assert php('setup-advance')['reason'] == 'prepare'
    assert php('setup-advance')['reason'] == 'process'
    assert php('setup-callback-pins')['roles'] == 2
    pins['source-b'] = json.loads((ROOT / 'private/setup-fixture/source-b-callback.json').read_bytes())
    assert pins['source-b']['created_at'] - pins['source-a']['created_at'] >= 300
    assert callback(role='source-b') == 200
    register('source-b')
    assert php('setup-advance')['ready']
    edge_dns = php('setup-dns-edges')
    assert edge_dns['ready'] and edge_dns['record_count'] == 2
    assert edge_dns['provider_create'] == edge_dns['provider_claim'] == 3
    assert php('setup-dns-readback')['record_count'] == 3
    registered = setup_rpc('status')
    assert all(row['registered'] and row['status'] == 'accepted' for row in registered['sources'].values())
    assert not registered['health_or_forwarding_claimed'] and registered['first_error'] is None
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
    assert php('setup-stop-busy')['drain_pending']
    assert callback() == 403
    hold_process.terminate(); hold_process.wait(timeout=5)
    assert hold_process.returncode != 0
    hold_process = None
    assert setup_rpc('stop')['stopped']
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
