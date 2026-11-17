"""Controller-local R35 installation primitives. No shell or network operations.

Only an explicit installer invokes these primitives. They neither issue grants
nor start services. The caller supplies verified bytes and a new private root.
"""
import hashlib
import os
from pathlib import Path, PurePosixPath
import stat

import member_runtime_bundle
from member_transport import require


class MemberInstallation:
    def __init__(self, root):
        self.root = Path(root).absolute()
        self.check_root()

    def check_root(self):
        require(os.name == 'posix' and self.root.resolve(strict=True) == self.root,
                'transport_installation_linux_root_required')
        for path in (self.root, self.root.parent):
            info = path.lstat()
            require(stat.S_ISDIR(info.st_mode) and info.st_uid == os.geteuid()
                    and info.st_mode & 0o022 == 0, 'transport_installation_root_permissions')

    def runtime(self, archive, expected_sha256):
        self.check_root()
        target = self.root / 'payload'
        # The existing extractor rejects links, traversal, duplicate files and hash drift.
        manifest = member_runtime_bundle.unpack(archive, target, expected_sha256)
        target.chmod(0o755)
        for path in target.rglob('*'):
            if path.is_dir(): path.chmod(0o755)
        self.verify_runtime(manifest)
        # mkdir's requested mode is filtered by umask. Permit service-user path
        # traversal only after verification; private/ remains separately 0700.
        self.root.chmod(0o711)
        return {'bundle_sha256': expected_sha256, 'files': len(manifest['files']), 'verified': True}

    def verify_runtime(self, manifest):
        self.check_root()
        target = self.root / 'payload'
        require(target.resolve(strict=True) == target and target.is_dir(), 'transport_installation_path_changed')
        for directory in [target, *[p for p in target.rglob('*') if p.is_dir()]]:
            info = directory.lstat()
            require(directory.resolve(strict=True) == directory and info.st_uid == os.geteuid()
                    and stat.S_IMODE(info.st_mode) == 0o755, 'transport_installation_directory_permissions')
        for name, pin in manifest['files'].items():
            relative = PurePosixPath(name)
            require(name == relative.as_posix() and not relative.is_absolute()
                    and '..' not in relative.parts and '\\' not in name,
                    'transport_installation_path_changed')
            path = target / name
            require(path.resolve(strict=True) == path and path.is_file(), 'transport_installation_path_changed')
            info = path.lstat()
            require(info.st_uid == os.geteuid() and info.st_nlink == 1
                    and stat.S_IMODE(info.st_mode) == pin['mode'] & 0o755,
                    'transport_installation_file_permissions')
            require(hashlib.sha256(path.read_bytes()).hexdigest() == pin['sha256'],
                    'transport_installation_hash_changed')

    def private(self, name, raw, expected_sha256):
        require(name in ('environment', 'credentials', 'bootstrap-authority') and isinstance(raw, bytes)
                and 0 < len(raw) <= 65536 and hashlib.sha256(raw).hexdigest() == expected_sha256,
                'transport_installation_private_invalid')
        self.check_root()
        private = self.root / 'private'
        private.mkdir(mode=0o700, exist_ok=True)
        info = private.lstat()
        require(private.resolve(strict=True) == private and stat.S_ISDIR(info.st_mode)
                and info.st_uid == os.geteuid() and stat.S_IMODE(info.st_mode) == 0o700,
                'transport_installation_private_permissions')
        path = private / name
        fd = os.open(path, os.O_CREAT | os.O_EXCL | os.O_WRONLY | os.O_NOFOLLOW, 0o600)
        with os.fdopen(fd, 'wb') as stream:
            stream.write(raw); stream.flush(); os.fsync(stream.fileno())
        require(hashlib.sha256(path.read_bytes()).hexdigest() == expected_sha256,
                'transport_installation_private_readback_failed')
        return {'name': name, 'sha256': expected_sha256, 'mode': '0600'}
