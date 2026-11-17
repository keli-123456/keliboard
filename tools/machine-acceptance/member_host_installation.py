"""Fixed root publication operations, before the application CLI is available.

This does not install OS packages, launch HTTP, issue grants or contact providers.
The enclosing driver retains ownership of host creation and final host removal.
"""
import base64
import hashlib
import os
from pathlib import Path
import re
import stat
import time

from driver_journal import DriverJournal, safe_error
from member_application import configure
from member_auxiliary import digest
from member_cli import RUN
from member_environment import environment
from member_installation import MemberInstallation
from member_round import round_journal
from member_transport import require


ROOT = '/opt/keli-isolated'
OPERATIONS = ('publish', 'configure', 'private', 'journal-read', 'stop')
MUTATIONS = OPERATIONS[:3]


def validate(request):
    require(isinstance(request, dict) and request.get('operation') in OPERATIONS,
            'transport_host_install_request')
    operation = request['operation']
    fields = {'operation', 'scope'}
    if operation == 'configure': fields |= {'settings', 'service'}
    if operation == 'private': fields.add('files')
    require(set(request) == fields, 'transport_host_install_request')
    scope = request['scope']
    require(isinstance(scope, dict) and set(scope) == {'run_id', 'bundle_sha256', 'started_at', 'deadline_at'}
            and scope['run_id'] == RUN and isinstance(scope['bundle_sha256'], str)
            and re.fullmatch(r'[a-f0-9]{64}', scope['bundle_sha256'])
            and type(scope['started_at']) is int and scope['started_at'] > 0
            and type(scope['deadline_at']) is int and 1200 < scope['deadline_at'] - scope['started_at'] <= 7200,
            'transport_host_install_scope')
    if operation == 'configure':
        environment(request['settings'])
        service = request['service']
        require(isinstance(service, dict) and set(service) == {'uid', 'gid'}
                and all(type(v) is int and 0 < v < 2**31 for v in service.values()),
                'transport_host_install_service')
    if operation == 'private':
        require(isinstance(request['files'], dict)
                and set(request['files']) == {'credentials', 'bootstrap-authority'},
                'transport_host_install_private')
        for pin in request['files'].values():
            require(isinstance(pin, dict) and set(pin) == {'sha256', 'base64'}
                    and isinstance(pin['base64'], str) and len(pin['base64']) <= 87384
                    and isinstance(pin['sha256'], str) and re.fullmatch(r'[a-f0-9]{64}', pin['sha256']),
                    'transport_host_install_private')
            raw = base64.b64decode(pin['base64'], validate=True)
            require(0 < len(raw) <= 65536 and hashlib.sha256(raw).hexdigest() == pin['sha256'],
                    'transport_host_install_private')
    return operation, scope


def read_owned(path, mode, maximum=65536):
    require(path.resolve(strict=True) == path, 'transport_host_install_path')
    before = path.lstat()
    require(stat.S_ISREG(before.st_mode) and before.st_uid == 0 and before.st_nlink == 1
            and stat.S_IMODE(before.st_mode) == mode and before.st_size <= maximum,
            'transport_host_install_file')
    descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    with os.fdopen(descriptor, 'rb') as stream:
        opened = os.fstat(stream.fileno())
        require((opened.st_dev, opened.st_ino) == (before.st_dev, before.st_ino), 'transport_host_install_file')
        raw = stream.read(maximum + 1)
    after = path.lstat()
    require(len(raw) == before.st_size and (after.st_dev, after.st_ino, after.st_mtime_ns)
            == (before.st_dev, before.st_ino, before.st_mtime_ns), 'transport_host_install_file')
    return raw


def execute(request, *, root=ROOT, clock=time.time):
    operation, scope = validate(request)
    require(os.name == 'posix' and os.geteuid() == 0, 'transport_host_install_root')
    installation = MemberInstallation(root)
    root = installation.root
    private = installation.private_directory()
    directory = private / 'host-installation'
    directory.mkdir(mode=0o700, exist_ok=True)
    info = directory.lstat()
    require(directory.resolve(strict=True) == directory and info.st_uid == 0
            and stat.S_ISDIR(info.st_mode) and stat.S_IMODE(info.st_mode) == 0o700,
            'transport_host_install_journal')
    machine = Path('/etc/machine-id')
    mode = stat.S_IMODE(machine.lstat().st_mode)
    require(mode in (0o444, 0o644), 'transport_host_install_machine')
    binding = dict(scope=scope, machine_id_sha256=hashlib.sha256(read_owned(machine, mode)).hexdigest())
    journal = DriverJournal(directory, RUN)
    try: existing = journal.load('binding.json')
    except FileNotFoundError: existing = None
    require(existing is None or existing == binding, 'transport_host_install_binding')

    def stopped():
        return os.path.lexists(private / 'import-stopped')

    # Stop is visible before lock acquisition so a running publication cannot
    # acknowledge success while the stop caller waits for its lock to drain.
    if operation == 'stop' and existing is not None:
        if not stopped(): installation.exclusive(private / 'import-stopped', (RUN + '\n').encode())
        require(read_owned(private / 'import-stopped', 0o600) == (RUN + '\n').encode(),
                'transport_host_install_stop')

    with round_journal(directory, RUN) as (load, save):
        require(load('binding') in (None, binding), 'transport_host_install_binding')
        if load('binding') is None: save('binding', binding)
        require(load('binding') == binding, 'transport_host_install_journal')

        def persist(key, value):
            save(key, value)
            require(load(key) == value, 'transport_host_install_journal')

        def guard():
            now = int(clock())
            require(now >= scope['started_at'] and now >= (load('clock') or {}).get('at', scope['started_at']),
                    'transport_host_install_clock')
            require(now < scope['deadline_at'] - 1200 and not stopped(), 'transport_host_install_stopped_or_expired')
            persist('clock', dict(at=now))

        def snapshot():
            completed, uncertain, pins = [], [], []
            if stopped():
                require(read_owned(private / 'import-stopped', 0o600) == (RUN + '\n').encode(),
                        'transport_host_install_stop')
            for name in MUTATIONS:
                intent, result = load(name + '-intent'), load(name + '-result')
                require(result is None or intent is not None, 'transport_host_install_journal')
                if intent is None: continue
                require(isinstance(intent, dict) and set(intent) == {'request_sha256', 'files'}
                        and isinstance(intent['files'], dict) and isinstance(intent['request_sha256'], str)
                        and re.fullmatch('[a-f0-9]{64}', intent['request_sha256']), 'transport_host_install_journal')
                require(result is None or isinstance(result, dict) and set(result) == {'evidence_sha256'}
                        and isinstance(result['evidence_sha256'], str) and re.fullmatch('[a-f0-9]{64}', result['evidence_sha256']),
                        'transport_host_install_journal')
                (completed if result is not None else uncertain).append(name)
                allowed = {'configure': {'app/.env': 0o640},
                           'private': {'private/credentials': 0o600, 'private/bootstrap-authority': 0o600},
                           'publish': {}}[name]
                require(set(intent['files']) == set(allowed), 'transport_host_install_journal')
                for relative, expected in intent['files'].items():
                    require(isinstance(expected, str) and re.fullmatch('[a-f0-9]{64}', expected),
                            'transport_host_install_journal')
                    path = root / relative
                    if not os.path.lexists(path):
                        require(result is None, 'transport_host_install_private_missing')
                        continue
                    raw = read_owned(path, allowed[relative])
                    require(hashlib.sha256(raw).hexdigest() == expected, 'transport_host_install_private_changed')
                    pins.append(dict(run_id=RUN, path=ROOT + '/' + relative, sha256=expected))
            return dict(completed_operations=completed, uncertain_operations=uncertain,
                        private_files=pins, stopped=stopped())

        try:
            if operation == 'stop':
                if not stopped(): installation.exclusive(private / 'import-stopped', (RUN + '\n').encode())
                require(read_owned(private / 'import-stopped', 0o600) == (RUN + '\n').encode(),
                        'transport_host_install_stop')
                return snapshot()
            if operation == 'journal-read': return snapshot()
            guard()
            require(load(operation + '-intent') is None, 'transport_host_install_readback_required')
            if operation != 'publish':
                require(load('publish-result') is not None, 'transport_host_install_publication_required')
            if operation == 'private':
                require(load('configure-result') is not None, 'transport_host_install_configuration_required')
            files = ({'app/.env': hashlib.sha256(environment(request['settings'])).hexdigest()}
                     if operation == 'configure' else
                     {'private/' + name: pin['sha256'] for name, pin in request['files'].items()}
                     if operation == 'private' else {})
            # Persist only hashes, never the settings or encoded credential body.
            persist(operation + '-intent', dict(request_sha256=digest(request), files=files))
            if operation == 'publish':
                import member_runtime_bundle
                manifest = member_runtime_bundle.verify(root / 'incoming/r35-adapters.tar.gz', scope['bundle_sha256'])
                require(manifest.get('run_id') == RUN, 'transport_host_install_package_run')
                result = installation.publish_runtime(root / 'incoming/r35-adapters.tar.gz', scope['bundle_sha256'])
                require(result['complete'] is True, 'transport_host_install_publication_required')
            elif operation == 'configure':
                result = configure(root, scope['bundle_sha256'], request['settings'], **request['service'])
            else:
                result = {name: installation.private(name, base64.b64decode(pin['base64'], validate=True), pin['sha256'])
                          for name, pin in request['files'].items()}
            guard()
            persist(operation + '-result', dict(evidence_sha256=digest(result)))
            return snapshot()
        except BaseException as error:
            if load('first-error') is None:
                persist('first-error', dict(operation=operation, diagnostic=safe_error(error)))
            raise
