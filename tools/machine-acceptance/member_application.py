"""Configure a published application for a distinct, unprivileged service user.

No package manager, daemon, migration, cloud request or grant is invoked here.
The fixed controller bootstrap consumes the resulting public identity receipt.
"""
import hashlib
import json
import os
from pathlib import Path
import stat

from member_cli import RUN
from member_environment import environment, public_settings
from member_installation import MemberInstallation
from member_transport import require


WRITABLE = ('storage/logs', 'storage/app/public', 'storage/framework')
READONLY_DATA = ('storage/app/geoip/GeoLite2-City.mmdb', 'storage/app/geoip/GeoLite2-Country.mmdb')
REQUIRED = ('storage/logs', 'storage/app/public', 'storage/framework/cache/data',
            'storage/framework/sessions', 'storage/framework/views', 'bootstrap/cache')


def configure(root, bundle_sha256, settings, *, uid, gid):
    import grp
    import pwd
    installation = MemberInstallation(root)
    root = installation.root
    raw = environment(settings)
    require(os.geteuid() == 0 and type(uid) is int and type(gid) is int
            and uid > 0 and gid > 0, 'transport_application_service_identity')
    require(pwd.getpwuid(uid).pw_gid == gid and grp.getgrgid(gid).gr_gid == gid,
            'transport_application_service_identity')
    require(installation.publication(bundle_sha256)['complete'], 'transport_application_publication_required')
    app = root / 'app'
    require(not os.path.lexists(app / '.env') and not os.path.lexists(root / 'application.json'),
            'transport_application_configuration_exists')
    # Only generated Laravel state is writable. A package containing precomputed
    # executable cache/config or logs is not a clean initial application.
    for name in ('storage/logs', 'storage/app', 'storage/framework', 'bootstrap/cache'):
        target = app / name
        if target.exists():
            require(all(p.is_dir() or p.name == '.gitignore' or p.relative_to(app).as_posix() in READONLY_DATA
                        for p in target.rglob('*')),
                    'transport_application_dirty_state')
    machine = Path('/etc/machine-id')
    require(machine.is_file() and not machine.is_symlink(), 'transport_application_machine_identity')
    receipt = dict(schema=1, run_id=RUN, bundle_sha256=bundle_sha256,
        machine_id_sha256=hashlib.sha256(machine.read_bytes()).hexdigest(),
        environment_sha256=hashlib.sha256(raw).hexdigest(), uid=uid, gid=gid,
        app_key_sha256=hashlib.sha256(('base64:' + settings['app_key']).encode()).hexdigest(),
        settings=public_settings(settings))
    data = (json.dumps(receipt, sort_keys=True) + '\n').encode()
    installation.exclusive(installation.private_directory() / 'application-intent.json', data)
    installation.exclusive(app / '.env', raw, 0o640, gid)
    for name in REQUIRED:
        (app / name).mkdir(mode=0o755, parents=True, exist_ok=True)
    # mkdir(parents=True) also obeys umask. Keep only the writable subtree roots
    # service-owned; their ancestors remain root-owned and traversable.
    for name in ('storage', 'storage/app', 'bootstrap', 'bootstrap/cache'):
        target = app / name
        require(target.resolve(strict=True) == target and target.stat().st_uid == 0,
                'transport_application_state_path_changed')
        target.chmod(0o755)
    for name in WRITABLE:
        target = app / name
        for path in [target, *target.rglob('*')]:
            info = path.lstat()
            require(path.resolve(strict=True) == path and (stat.S_ISDIR(info.st_mode)
                    or stat.S_ISREG(info.st_mode) and info.st_nlink == 1), 'transport_application_state_path_changed')
            if path.is_dir():
                os.chown(path, uid, gid); path.chmod(0o750)
    # Root must still be able to bootstrap, but unrelated users cannot read .env
    # or write executable application/runtime files. Only this receipt is public.
    installation.exclusive(root / 'application.json', data, 0o644)
    return receipt
