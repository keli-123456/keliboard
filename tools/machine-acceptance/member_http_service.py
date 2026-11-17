"""Scoped transient HTTP/TLS service. No ACME, packages or provider operations.

Certificate bytes are explicit inputs. A durable intent precedes the only start;
an ambiguous start is inspected, never replayed. systemd owns the whole cgroup.
"""
import base64
import hashlib
import http.client
import json
import os
from pathlib import Path
import re
import signal
import socket
import ssl
import stat
import subprocess
import time

from driver_journal import safe_error, unique_json
from member_auxiliary import digest
from member_cli import RUN
from member_host_installation import read_owned, validate as validate_install
from member_installation import MemberInstallation
from member_round import round_journal
from member_transport import require


ROOT = '/opt/keli-isolated'
HOST = 'control-' + RUN + '.dnscloudcloud.top'
FILES = {'certificate': 'private/http-certificate.pem', 'key': 'private/http-key.pem'}
ENV = dict(PATH='/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin', LANG='C.UTF-8', HOME='/root')
PROPERTIES = ('LoadState', 'ActiveState', 'SubState', 'MainPID', 'ControlGroup', 'Description', 'Transient')


def validate(request):
    require(isinstance(request, dict) and request.get('operation') in ('start', 'read', 'stop'),
            'transport_http_request')
    operation = request['operation']
    require(set(request) == ({'operation', 'scope', 'files'} if operation == 'start' else {'operation', 'scope'}),
            'transport_http_request')
    validate_install(dict(operation='journal-read', scope=request['scope']))
    if operation == 'start':
        require(isinstance(request['files'], dict) and set(request['files']) == set(FILES), 'transport_http_files')
        for pin in request['files'].values():
            require(isinstance(pin, dict) and set(pin) == {'sha256', 'base64'}
                    and isinstance(pin['sha256'], str) and re.fullmatch('[a-f0-9]{64}', pin['sha256'])
                    and isinstance(pin['base64'], str) and len(pin['base64']) <= 87384, 'transport_http_files')
            raw = base64.b64decode(pin['base64'], validate=True)
            require(0 < len(raw) <= 65536 and hashlib.sha256(raw).hexdigest() == pin['sha256'], 'transport_http_files')
    return operation, request['scope']


def unit(scope):
    return 'keli-r35-http-' + digest(scope)[:24] + '.service'


def command(args, timeout=20):
    result = subprocess.run(args, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                            timeout=timeout, env=ENV, cwd='/', check=False)
    require(len(result.stdout) <= 65536, 'transport_http_command_output')
    return result.returncode, result.stdout


def status(scope, invoke=command, *, allow_pending=False):
    code, raw = invoke(['systemctl', 'show', unit(scope), '--no-pager', '--all', '--property=' + ','.join(PROPERTIES)])
    lines = raw.decode('ascii').splitlines()
    pairs = [line.split('=', 1) for line in lines]
    require(all(len(p) == 2 for p in pairs) and len(pairs) == len(PROPERTIES), 'transport_http_unit_readback')
    value = dict(pairs)
    require(set(value) == set(PROPERTIES), 'transport_http_unit_readback')
    if value['LoadState'] == 'not-found':
        require(code in (0, 1) and value['ActiveState'] == 'inactive' and value['MainPID'] == '0'
                and value['ControlGroup'] == '', 'transport_http_unit_readback')
        return 'absent'
    require(code == 0 and value['LoadState'] == 'loaded' and value['Transient'] == 'yes'
            and value['Description'] == 'keli-r35-http:' + digest(scope)
            and value['ControlGroup'] in ('', '/system.slice/' + unit(scope)), 'transport_http_unit_owner')
    if value['ActiveState'] == 'active' and value['SubState'] == 'running' and re.fullmatch('[1-9][0-9]*', value['MainPID']):
        return 'running'
    if allow_pending and value['ActiveState'] in ('activating', 'deactivating', 'reloading'):
        return 'pending'
    require(value['ActiveState'] in ('inactive', 'failed') and value['MainPID'] == '0', 'transport_http_unit_pending')
    return 'stopped'


def cgroup_empty(scope):
    path = Path('/sys/fs/cgroup/system.slice') / unit(scope) / 'cgroup.events'
    if not os.path.lexists(path.parent): return True
    require(path.resolve(strict=True) == path, 'transport_http_cgroup_changed')
    data = dict(line.split() for line in path.read_text('ascii').splitlines())
    require(data.get('populated') in ('0', '1'), 'transport_http_cgroup_readback')
    return data['populated'] == '0'


def stop_unit(scope, invoke=command):
    before = status(scope, invoke, allow_pending=True)
    if before != 'absent':
        require(invoke(['systemctl', 'stop', unit(scope)], 30)[0] == 0, 'transport_http_stop_failed')
    require(status(scope, invoke) in ('absent', 'stopped') and cgroup_empty(scope), 'transport_http_stop_unconfirmed')


def nginx_config(uid, gid):
    import grp
    import pwd
    user, group = pwd.getpwuid(uid), grp.getgrgid(gid)
    require(uid > 0 and gid > 0 and user.pw_gid == gid
            and all(re.fullmatch('[a-z_][a-z0-9_-]*', name) for name in (user.pw_name, group.gr_name)),
            'transport_http_service_identity')
    return (f'user {user.pw_name} {group.gr_name};\n'
        'worker_processes 1;\ndaemon off;\nerror_log /dev/null;\n'
        f'pid {ROOT}/private/http-service/nginx.pid;\nevents {{ worker_connections 64; }}\n'
        'http { access_log off; server_tokens off; client_max_body_size 8k;\n'
        'client_body_timeout 10s; client_header_timeout 10s; send_timeout 10s;\n'
        'server { listen 443 ssl;\n'
        f'server_name {HOST};\nssl_certificate {ROOT}/{FILES["certificate"]};\n'
        f'ssl_certificate_key {ROOT}/{FILES["key"]};\n'
        'ssl_protocols TLSv1.2 TLSv1.3; ssl_session_tickets off;\n'
        f'if ($host != "{HOST}") {{ return 403; }}\n'
        'location / { proxy_pass http://127.0.0.1:7001; proxy_set_header X-Forwarded-Proto https;\n'
        f'proxy_set_header Host {HOST};\n'
        'proxy_set_header X-Forwarded-For ""; proxy_set_header Forwarded "";\n'
        'proxy_request_buffering off; proxy_buffering off; proxy_connect_timeout 3s; proxy_read_timeout 30s;\n'
        '} } }\n').encode('ascii')


def service_smoke(scope, uid, gid):
    request = dict(schema=1, **scope, operation='service-smoke', request_id=digest([scope, 'service-smoke'])[:32])
    result = subprocess.run(['setpriv', '--reuid=' + str(uid), '--regid=' + str(gid), '--clear-groups',
        '--no-new-privs', '--bounding-set=-all', 'php', ROOT + '/runtime/member_application_entry.php'],
        input=json.dumps(request).encode(), stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
        timeout=45, cwd='/', env=ENV, check=False)
    require(result.returncode == 0 and len(result.stdout) <= 65536, 'transport_http_application_smoke')
    reply = unique_json(result.stdout)
    require(reply == dict(request, ok=True, result=dict(ready=True, uid=uid, gid=gid, kernel='App\\Http\\Kernel',
        database_and_cache_reachable=True, executable_paths_read_only=True)), 'transport_http_application_smoke')


def tls_probe(root):
    certificate = root / FILES['certificate']
    context = ssl.create_default_context()
    context.load_verify_locations(cafile=str(certificate))
    context.verify_flags |= ssl.VERIFY_X509_PARTIAL_CHAIN
    with socket.create_connection(('127.0.0.1', 443), timeout=3) as plain:
        with context.wrap_socket(plain, server_hostname=HOST) as stream:
            leaf = read_owned(certificate, 0o600).split(b'-----END CERTIFICATE-----', 1)[0] + b'-----END CERTIFICATE-----\n'
            require(stream.getpeercert(binary_form=True) == ssl.PEM_cert_to_DER_cert(leaf.decode('ascii')),
                    'transport_http_certificate_changed')
            stream.sendall(('GET /_keli_r35_reject HTTP/1.1\r\nHost: ' + HOST + '\r\nConnection: close\r\n\r\n').encode())
            response = http.client.HTTPResponse(stream)
            response.begin(); body = response.read(8193)
            if response.status == 502: raise ConnectionError('transport_http_upstream_starting')
            require(response.status == 403 and body == b'{"message":"isolated_callback_denied"}',
                    'transport_http_callback_probe')


def execute(request, *, root=ROOT, invoke=command, clock=time.time, probe=tls_probe, smoke=service_smoke):
    operation, scope = validate(request)
    require(os.name == 'posix' and os.geteuid() == 0, 'transport_http_root_required')
    installation = MemberInstallation(root); root = installation.root
    private = installation.private_directory(); directory = private / 'http-service'
    directory.mkdir(mode=0o700, exist_ok=True)
    info = directory.lstat()
    require(directory.resolve(strict=True) == directory and info.st_uid == 0
            and stat.S_ISDIR(info.st_mode) and stat.S_IMODE(info.st_mode) == 0o700, 'transport_http_journal')
    binding = dict(scope=scope, machine_id_sha256=hashlib.sha256(read_owned(Path('/etc/machine-id'),
        stat.S_IMODE(Path('/etc/machine-id').stat().st_mode))).hexdigest())
    from driver_journal import DriverJournal
    journal = DriverJournal(directory, RUN)
    try: prior = journal.load('binding.json')
    except FileNotFoundError: prior = None
    require(prior in (None, binding), 'transport_http_binding_changed')
    stop = directory / 'stop'
    if operation == 'stop' and not os.path.lexists(stop):
        installation.exclusive(stop, (digest(scope) + '\n').encode())
    with round_journal(directory, RUN) as (load, save):
        require(load('binding') in (None, binding), 'transport_http_binding_changed')
        if load('binding') is None: save('binding', binding)
        require(load('binding') == binding, 'transport_http_journal')

        def persist(key, value):
            save(key, value); require(load(key) == value, 'transport_http_journal')

        def guard():
            now = int(clock())
            require(scope['started_at'] <= now < scope['deadline_at'] - 1200
                    and now >= (load('clock') or {}).get('at', scope['started_at'])
                    and not os.path.lexists(stop) and not os.path.lexists(private / 'import-stopped'),
                    'transport_http_stopped_or_expired')
            persist('clock', dict(at=now))

        def pins():
            intent = load('start-intent')
            if intent is None: return []
            require(isinstance(intent, dict) and set(intent) == {'request_sha256', 'files'}
                    and isinstance(intent['files'], dict) and set(intent['files']) == set(FILES)
                    and all(isinstance(v, str) and re.fullmatch('[a-f0-9]{64}', v)
                            for v in [intent['request_sha256'], *intent['files'].values()]), 'transport_http_journal')
            result = []
            for name, relative in FILES.items():
                path = root / relative
                if not os.path.lexists(path):
                    require(load('started') is None or load('stopped') is not None, 'transport_http_private_missing')
                    continue
                sha = hashlib.sha256(read_owned(path, 0o600)).hexdigest()
                require(sha == intent['files'][name], 'transport_http_private_changed')
                result.append(dict(run_id=RUN, path=ROOT + '/' + relative, sha256=sha))
            return result

        try:
            if operation == 'stop':
                require(read_owned(stop, 0o600) == (digest(scope) + '\n').encode(), 'transport_http_stop_changed')
                stop_unit(scope, invoke)
                persist('stopped', dict(scope_sha256=digest(scope), cgroup_empty=True))
                return dict(state='stopped', unit=unit(scope), private_files=pins())
            if operation == 'read':
                state = status(scope, invoke)
                if load('stopped') is not None:
                    require(state in ('absent', 'stopped') and cgroup_empty(scope), 'transport_http_stop_unconfirmed')
                    state = 'stopped'
                elif state == 'running':
                    guard(); probe(root)
                return dict(state=state, unit=unit(scope), private_files=pins())
            guard()
            require(load('start-intent') is None, 'transport_http_readback_required')
            require(status(scope, invoke) == 'absent', 'transport_http_unit_exists')
            require(all(not os.path.lexists(root / path) for path in FILES.values())
                    and not os.path.lexists(directory / 'nginx.conf'), 'transport_http_target_exists')
            application = unique_json(read_owned(root / 'application.json', 0o644))
            require(application.get('run_id') == RUN and application.get('bundle_sha256') == scope['bundle_sha256']
                    and application.get('machine_id_sha256') == binding['machine_id_sha256'], 'transport_http_application_changed')
            uid, gid = application['uid'], application['gid']
            require(type(uid) is int and type(gid) is int and uid > 0 and gid > 0, 'transport_http_service_identity')
            publication = unique_json(read_owned(private / 'runtime-publication.json', 0o600, 16 * 1024 * 1024))
            require(publication['archive_sha256'] == scope['bundle_sha256']
                    and 'runtime/member_http_service.py' in publication['manifest']['files'], 'transport_http_runtime_changed')
            for name, pin in publication['manifest']['files'].items():
                if name.startswith('runtime/'):
                    require(hashlib.sha256(read_owned(root / name, pin['mode'] & 0o755)).hexdigest() == pin['sha256'],
                            'transport_http_runtime_changed')
            smoke(scope, uid, gid)
            persist('start-intent', dict(request_sha256=digest(request), files={n: p['sha256'] for n, p in request['files'].items()}))
            for name, pin in request['files'].items():
                installation.exclusive(root / FILES[name], base64.b64decode(pin['base64'], validate=True))
            ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER).load_cert_chain(str(root / FILES['certificate']), str(root / FILES['key']))
            require(invoke(['openssl', 'x509', '-in', str(root / FILES['certificate']), '-noout', '-checkhost', HOST])[0] == 0,
                    'transport_http_certificate_host')
            installation.exclusive(directory / 'nginx.conf', nginx_config(uid, gid))
            require(invoke(['nginx', '-t', '-q', '-c', str(directory / 'nginx.conf'), '-p', str(directory) + '/'])[0] == 0,
                    'transport_http_nginx_config')
            guard()
            arguments = ['systemd-run', '--quiet', '--collect', '--unit=' + unit(scope),
                '--description=keli-r35-http:' + digest(scope), '--property=Type=exec', '--property=Slice=system.slice',
                '--property=KillMode=control-group', '--property=Restart=no', '--property=TimeoutStopSec=10s',
                '--property=RuntimeMaxSec=' + str(scope['deadline_at'] - int(clock()) - 1200),
                '--property=MemoryMax=805306368', '--property=CPUQuota=75%', '--property=TasksMax=64',
                '--property=StandardOutput=null', '--property=StandardError=null', '--property=UMask=0077',
                '/usr/bin/python3', '-B', ROOT + '/runtime/member_http_service.py', 'serve', digest(scope)]
            require(invoke(arguments, 30)[0] == 0, 'transport_http_start_uncertain')
            deadline = time.monotonic() + 15
            while True:
                guard()
                require(status(scope, invoke) == 'running', 'transport_http_start_unconfirmed')
                try: probe(root); break
                except (OSError, ssl.SSLError):
                    require(time.monotonic() < deadline, 'transport_http_tls_not_ready'); time.sleep(0.2)
            persist('started', dict(scope_sha256=digest(scope), uid=uid, gid=gid))
            return dict(state='running', unit=unit(scope), private_files=pins())
        except BaseException as error:
            if load('first-error') is None: persist('first-error', dict(operation=operation, diagnostic=safe_error(error)))
            if operation == 'start' and load('start-intent') is not None:
                if not os.path.lexists(stop): installation.exclusive(stop, (digest(scope) + '\n').encode())
                try:
                    stop_unit(scope, invoke)
                    persist('stopped', dict(scope_sha256=digest(scope), cgroup_empty=True))
                except BaseException:
                    pass  # The original failure survives; explicit stop/readback must resolve cleanup.
            raise


def serve(binding_sha):
    from driver_journal import DriverJournal
    installation = MemberInstallation(ROOT); root = installation.root
    directory = root / 'private/http-service'
    journal = DriverJournal(directory, RUN)
    binding = journal.load('binding.json'); scope = binding['scope']
    require(re.fullmatch('[a-f0-9]{64}', binding_sha) and digest(scope) == binding_sha, 'transport_http_binding_changed')
    validate(dict(operation='read', scope=scope))
    application = unique_json(read_owned(root / 'application.json', 0o644))
    require(binding['machine_id_sha256'] == application['machine_id_sha256']
            == hashlib.sha256(Path('/etc/machine-id').read_bytes()).hexdigest()
            and application['bundle_sha256'] == scope['bundle_sha256']
            and journal.load('start-intent.json') is not None, 'transport_http_binding_changed')
    cancelled = False

    def cancel(*_):
        nonlocal cancelled
        cancelled = True

    signal.signal(signal.SIGTERM, cancel); signal.signal(signal.SIGINT, cancel)
    children = []
    deadline = time.monotonic() + max(0, scope['deadline_at'] - 1200 - time.time())

    def allowed():
        return not cancelled and time.monotonic() < deadline and scope['started_at'] <= time.time() < scope['deadline_at'] - 1200 \
            and not os.path.lexists(directory / 'stop') and not os.path.lexists(root / 'private/import-stopped')

    try:
        require(allowed(), 'transport_http_stopped_or_expired')
        commands = [
            ['setpriv', '--reuid=' + str(application['uid']), '--regid=' + str(application['gid']), '--clear-groups',
             '--no-new-privs', '--bounding-set=-all', 'php', '-d', 'display_errors=0', '-d', 'log_errors=0',
             '-S', '127.0.0.1:7001', ROOT + '/runtime/member_callback_entry.php'],
            ['nginx', '-c', str(directory / 'nginx.conf'), '-p', str(directory) + '/']]
        for args in commands:
            require(allowed(), 'transport_http_stopped_or_expired')
            children.append(subprocess.Popen(args, stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL,
                stderr=subprocess.DEVNULL, cwd='/', env=ENV))
        while allowed():
            require(all(child.poll() is None for child in children), 'transport_http_child_exited')
            time.sleep(0.2)
    finally:
        for child in children:
            if child.poll() is None: child.terminate()
        for child in children:
            try: child.wait(timeout=4)
            except subprocess.TimeoutExpired: child.kill(); child.wait(timeout=4)


def assert_stopped(root=ROOT, invoke=command):
    """Private teardown cannot remove a TLS key while its service can still run."""
    from driver_journal import DriverJournal
    root = Path(root); directory = root / 'private/http-service'
    if not os.path.lexists(directory): return
    journal = DriverJournal(directory, RUN)
    binding = journal.load('binding.json'); scope = binding['scope']
    validate(dict(operation='read', scope=scope))
    require(binding['machine_id_sha256'] == hashlib.sha256(Path('/etc/machine-id').read_bytes()).hexdigest()
            and read_owned(directory / 'stop', 0o600) == (digest(scope) + '\n').encode()
            and journal.load('stopped.json') == dict(scope_sha256=digest(scope), cgroup_empty=True),
            'cleanup_http_not_stopped')
    require(status(scope, invoke) in ('absent', 'stopped') and cgroup_empty(scope), 'cleanup_http_not_stopped')


def main():
    import sys
    try:
        if len(sys.argv) == 3 and sys.argv[1] == 'serve':
            serve(sys.argv[2]); return 0
        require(len(sys.argv) == 1, 'transport_http_request')
        raw = sys.stdin.buffer.read(262145)
        require(len(raw) <= 262144, 'transport_http_request')
        request = unique_json(raw)
        print(json.dumps(dict(ok=True, request_sha256=digest(request), result=execute(request)), sort_keys=True))
        return 0
    except BaseException:
        print('{"ok":false,"code":"transport_http_unconfirmed"}')
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
