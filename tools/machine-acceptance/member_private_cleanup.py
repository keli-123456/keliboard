"""Root-local, exact-file teardown after bootstrap and native work have stopped.

No recursive deletion, service control or database access. Removal receipts stay
outside the private files being removed, so lost replies can be inspected safely.
"""
from contextlib import contextmanager
import hashlib
import json
import os
from pathlib import Path
import re
import stat

from driver_journal import unique_json
from member_auxiliary import digest
from member_cli import RUN
from member_installation import MemberInstallation
from member_transport import require


ROOT = '/opt/keli-isolated'
PRIVATE_PATHS = tuple(ROOT + '/' + p for p in (
    'private/cloud-credential.json', 'private/dns-credential.json',
    'private/member-runtime-scope.json', 'private/environment', 'private/credentials',
    'private/bootstrap-authority', 'app/.env'))


def validate_pin(pin):
    require(isinstance(pin, dict) and set(pin) == {'run_id', 'path', 'sha256'}
            and isinstance(pin['run_id'], str) and re.fullmatch(r'keli-accept-[a-z0-9-]{8,80}', pin['run_id'])
            and pin['path'] in PRIVATE_PATHS
            and isinstance(pin['sha256'], str) and re.fullmatch('[a-f0-9]{64}', pin['sha256']),
            'cleanup_private_pin_invalid')


class MemberPrivateCleanup:
    def __init__(self, root=ROOT):
        require(os.name == 'posix' and os.geteuid() == 0, 'cleanup_private_root_required')
        self.installation = MemberInstallation(root)
        self.root = self.installation.root
        self.directory = self.root / 'private-cleanup'

    def checked(self, path, mode):
        require(path.resolve(strict=True) == path, 'cleanup_private_path_changed')
        for parent in (path.parent, self.root):
            info = parent.lstat()
            require(stat.S_ISDIR(info.st_mode) and info.st_uid == 0 and info.st_mode & 0o022 == 0,
                    'cleanup_private_directory_changed')
        info = path.lstat()
        require(stat.S_ISREG(info.st_mode) and info.st_uid == 0 and info.st_nlink == 1
                and stat.S_IMODE(info.st_mode) == mode and info.st_size <= 262144,
                'cleanup_private_file_changed')
        return info

    def read(self, path, mode=0o600):
        before = self.checked(path, mode)
        fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
        with os.fdopen(fd, 'rb') as stream:
            opened = os.fstat(stream.fileno())
            require((before.st_dev, before.st_ino) == (opened.st_dev, opened.st_ino),
                    'cleanup_private_file_changed')
            raw = stream.read(262145)
        require(len(raw) == before.st_size, 'cleanup_private_file_changed')
        return raw

    @contextmanager
    def lock(self, path, mode=0o600):
        import fcntl
        self.checked(path, mode)
        fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
        try:
            opened, current = os.fstat(fd), path.lstat()
            require((opened.st_dev, opened.st_ino) == (current.st_dev, current.st_ino),
                    'cleanup_private_file_changed')
            try:
                fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
            except BlockingIOError:
                require(False, 'cleanup_private_operations_pending')
            yield
        finally:
            os.close(fd)

    def publish(self, path, value):
        raw = (json.dumps(value, sort_keys=True) + '\n').encode()
        self.installation.exclusive(path, raw)
        fd = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        try:
            os.fsync(fd)
        finally:
            os.close(fd)
        require(self.read(path) == raw, 'cleanup_private_receipt_unconfirmed')

    def barrier(self):
        application = unique_json(self.read(self.root / 'application.json', 0o644))
        require(application.get('run_id') == RUN and application.get('machine_id_sha256')
                == hashlib.sha256(Path('/etc/machine-id').read_bytes()).hexdigest(),
                'cleanup_private_installation_changed')
        path = self.directory / 'barrier.json'
        if os.path.lexists(path):
            value = unique_json(self.read(path))
            require(isinstance(value, dict) and set(value) == {'run_id', 'stops', 'locks'}
                    and value['run_id'] == RUN and isinstance(value['stops'], dict)
                    and isinstance(value['locks'], dict), 'cleanup_private_barrier_changed')
            allowed = {'application.json': 0o644, 'private/import-stopped': 0o600,
                       'member-setup-stop.json': 0o644, 'private/member-journal/round-stop.json': 0o600}
            require({'application.json', 'private/import-stopped'} <= set(value['stops']) <= set(allowed)
                    and set(value['locks']) <= {'private/member-import/lock', 'member-setup-grant.json',
                                               'private/member-journal/rpc.lock'}, 'cleanup_private_barrier_changed')
            expected_locks = {'private/member-import/lock': 0o600}
            if 'member-setup-stop.json' in value['stops']:
                expected_locks['member-setup-grant.json'] = 0o644
            if 'private/member-journal/round-stop.json' in value['stops']:
                expected_locks['private/member-journal/rpc.lock'] = 0o600
            require(value['locks'] == expected_locks, 'cleanup_private_barrier_changed')
            for name, expected in value['stops'].items():
                require(hashlib.sha256(self.read(self.root / name, allowed[name])).hexdigest() == expected,
                        'cleanup_private_stop_changed')
            return value
        require(self.read(self.root / 'private/import-stopped') == (RUN + '\n').encode(),
                'cleanup_private_bootstrap_not_stopped')
        stops = {'application.json': 0o644, 'private/import-stopped': 0o600}
        locks = {'private/member-import/lock': 0o600}
        grant = self.root / 'member-setup-grant.json'
        if os.path.lexists(grant):
            raw = self.read(grant, 0o644)
            stop = unique_json(self.read(self.root / 'member-setup-stop.json', 0o644))
            require(stop == {'grant_sha256': hashlib.sha256(raw).hexdigest(), 'stopped': True}
                    and stop['stopped'] is True, 'cleanup_private_setup_not_stopped')
            stops['member-setup-stop.json'], locks['member-setup-grant.json'] = 0o644, 0o644
        grant = self.root / 'private/member-runtime-scope.json'
        if os.path.lexists(grant):
            native = unique_json(self.read(grant))
            stop = unique_json(self.read(self.root / 'private/member-journal/round-stop.json'))
            require(native.get('run_id') == RUN and isinstance(native.get('grant_id'), str)
                    and re.fullmatch('[a-f0-9]{32}', native['grant_id'])
                    and stop == {'grant_id': native['grant_id'], 'stopped': True}
                    and stop['stopped'] is True, 'cleanup_private_native_not_stopped')
            stops['private/member-journal/round-stop.json'] = 0o600
            locks['private/member-journal/rpc.lock'] = 0o600
        value = dict(run_id=RUN, stops={name: hashlib.sha256(self.read(self.root / name, mode)).hexdigest()
                                      for name, mode in stops.items()}, locks=locks)
        # The barrier is published only while every recorded operation lock is held.
        return value

    def operation(self, operation, pin):
        from contextlib import ExitStack
        validate_pin(pin)
        require(operation in ('remove', 'inspect') and pin['run_id'] == RUN, 'cleanup_private_operation_invalid')
        self.installation.check_root()
        self.directory.mkdir(mode=0o700, exist_ok=True)
        info = self.directory.lstat()
        require(self.directory.resolve(strict=True) == self.directory and stat.S_ISDIR(info.st_mode)
                and info.st_uid == 0 and stat.S_IMODE(info.st_mode) == 0o700, 'cleanup_private_directory_changed')
        lock = self.directory / 'lock'
        if not os.path.lexists(lock):
            try:
                self.installation.exclusive(lock, b'')
            except FileExistsError:
                pass
        with self.lock(lock), ExitStack() as stack:
            barrier = self.barrier()
            for name, mode in barrier['locks'].items():
                require(type(mode) is int and mode in (0o600, 0o644), 'cleanup_private_barrier_changed')
                stack.enter_context(self.lock(self.root / name, mode))
            require(self.barrier() == barrier, 'cleanup_private_barrier_changed')
            barrier_path = self.directory / 'barrier.json'
            if not os.path.lexists(barrier_path):
                self.publish(barrier_path, barrier)
            target = self.root / pin['path'][len(ROOT) + 1:]
            intent = self.directory / (digest(pin) + '.json')
            saved = unique_json(self.read(intent)) if os.path.lexists(intent) else None
            require(saved is None or saved == pin, 'cleanup_private_intent_changed')
            exists = os.path.lexists(target)
            if not exists:
                require(saved == pin, 'cleanup_private_absence_unproven')
                return dict(pin_sha256=digest(pin), status='absent')
            mode = 0o640 if pin['path'].endswith('/app/.env') else 0o600
            require(hashlib.sha256(self.read(target, mode)).hexdigest() == pin['sha256'],
                    'cleanup_private_hash_changed')
            if operation == 'inspect':
                return dict(pin_sha256=digest(pin), status='owned')
            if saved is None:
                self.publish(intent, pin)
            require(hashlib.sha256(self.read(target, mode)).hexdigest() == pin['sha256'],
                    'cleanup_private_hash_changed')
            target.unlink()
            fd = os.open(target.parent, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
            try:
                os.fsync(fd)
            finally:
                os.close(fd)
            require(not os.path.lexists(target), 'cleanup_private_remove_unconfirmed')
            return dict(pin_sha256=digest(pin), status='absent')


def main():
    import sys
    try:
        raw = sys.stdin.buffer.read(8193)
        require(len(raw) <= 8192, 'cleanup_private_request_invalid')
        request = unique_json(raw)
        require(isinstance(request, dict) and set(request) == {'operation', 'pin'}, 'cleanup_private_request_invalid')
        result = MemberPrivateCleanup().operation(request['operation'], request['pin'])
        print(json.dumps(dict(ok=True, result=result), sort_keys=True))
    except Exception:
        # No paths, hashes or material from an untrusted exception message.
        print(json.dumps(dict(ok=False, code='cleanup_private_unconfirmed')))
        return 1
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
