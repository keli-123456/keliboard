"""Publish the complete reviewed CLI payload before isolated services can start."""
import ast
import hashlib
import json
import os
from pathlib import Path
import re
import stat


FILES = ('live_support.php', 'fixture_payload.php', 'test_router.php', 'seed_live.php',
         'echo_fixture.py', 'live_cli.php', 'initial_dns.php', 'source_fixture.php',
         'source_fixture_evidence.php', 'cli_diagnostics.php', 'http_smoke.php', 'bounded_worker.py')
STAGED = {*FILES, 'runtime_payload.py'}
MAX_FILE_BYTES = 262144


class Refused(RuntimeError):
    pass


def require(ok, code):
    if not ok:
        raise Refused(code)


def directory(path):
    path = Path(path).absolute()
    info = path.lstat()
    require(path.resolve(strict=True) == path and stat.S_ISDIR(info.st_mode), 'runtime_directory_invalid')
    if os.name == 'posix':
        require(info.st_uid == os.geteuid() and not info.st_mode & 0o022, 'runtime_directory_permissions_invalid')
    return path


def read(path, maximum=MAX_FILE_BYTES):
    info = path.lstat()
    require(stat.S_ISREG(info.st_mode) and info.st_nlink == 1 and 0 < info.st_size <= maximum,
            'runtime_file_invalid')
    if os.name == 'posix':
        require(info.st_uid == os.geteuid() and not info.st_mode & 0o022, 'runtime_file_permissions_invalid')
    fd = os.open(path, os.O_RDONLY | getattr(os, 'O_NOFOLLOW', 0))
    with os.fdopen(fd, 'rb') as stream:
        current = os.fstat(stream.fileno())
        require((info.st_dev, info.st_ino, info.st_size) == (current.st_dev, current.st_ino, current.st_size),
                'runtime_file_changed')
        data = stream.read(maximum + 1)
    require(len(data) == info.st_size, 'runtime_file_changed')
    return data


def unique(pairs):
    result = {}
    for key, value in pairs:
        require(key not in result, 'runtime_manifest_duplicate')
        result[key] = value
    return result


def manifest(bundle):
    value = json.loads(read(bundle / 'staging-manifest.json', 65536), object_pairs_hook=unique)
    require(isinstance(value, dict) and set(value) == {'schema', 'run_id', 'role', 'files'}
            and type(value['schema']) is int and value['schema'] == 1 and value['role'] == 'control'
            and isinstance(value['run_id'], str) and re.fullmatch(r'keli-accept-[a-z0-9-]{8,100}', value['run_id'])
            and isinstance(value['files'], dict) and STAGED <= set(value['files'])
            and len(value['files']) <= 128, 'runtime_manifest_invalid')
    pins = {}
    for name in FILES:
        pin = value['files'][name]
        require(isinstance(pin, dict) and set(pin) == {'bytes', 'sha256'}
                and type(pin['bytes']) is int and 0 < pin['bytes'] <= MAX_FILE_BYTES
                and isinstance(pin['sha256'], str) and re.fullmatch('[a-f0-9]{64}', pin['sha256']),
                'runtime_pin_invalid')
        pins[name] = pin
    return pins


def checked(path, pin):
    data = read(path)
    require(len(data) == pin['bytes'] and hashlib.sha256(data).hexdigest() == pin['sha256'],
            'runtime_hash_mismatch')
    return data


def verify(bundle, target):
    bundle, target = directory(bundle), directory(target)
    pins = manifest(bundle)
    for name, pin in pins.items():
        checked(target / name, pin)
    return {'ok': True, 'files_verified': len(FILES), 'active_hashes_verified': True}


def publish(bundle, target):
    bundle, target = directory(bundle), directory(target)
    require(bundle != target, 'separate_runtime_directories_required')
    pins = manifest(bundle)
    # Validate every dependency before the first write; an incomplete stage cannot start services.
    data = {name: checked(bundle / name, pin) for name, pin in pins.items()}
    require(not any(os.path.lexists(target / name) for name in FILES), 'runtime_original_install_only')
    for name in FILES:
        fd = os.open(target / name, os.O_WRONLY | os.O_CREAT | os.O_EXCL | getattr(os, 'O_NOFOLLOW', 0), 0o644)
        with os.fdopen(fd, 'wb') as stream:
            stream.write(data[name])
            stream.flush()
            os.fsync(stream.fileno())
        (target / name).chmod(0o644)
    return verify(bundle, target)


def validate_bindings(base):
    """Read only: fresh adapters must publish the active files, not merely upload them."""
    base = Path(base)
    for name, variable in [('live_operator.py', 'RUNTIME'), ('staging_control.py', 'STAGING')]:
        tree = ast.parse((base / name).read_text(encoding='utf-8'))
        values = [node.value for node in tree.body if isinstance(node, ast.Assign)
                  and any(isinstance(key, ast.Name) and key.id == variable for key in node.targets)]
        require(len(values) == 1, 'runtime_staging_binding_required')
        names = ast.literal_eval(values[0])
        require(isinstance(names, (tuple, list)) and all(isinstance(item, str) for item in names)
                and STAGED <= set(names), 'runtime_staging_binding_required')
        if name == 'live_operator.py':
            require(any(isinstance(node, ast.Import) and any(alias.name == 'cli_error_capture' and alias.asname is None
                                                           for alias in node.names) for node in tree.body),
                    'cli_capture_binding_required')
            functions = [node for node in tree.body if isinstance(node, ast.FunctionDef) and node.name == 'command']
            require(len(functions) == 1, 'cli_capture_binding_required')
            statements = [ast.dump(node, include_attributes=False) for node in functions[0].body]
            capture = ast.dump(ast.parse('result = cli_error_capture.capture_result(campaign, result)').body[0],
                               include_attributes=False)
            saved = ast.dump(ast.parse("campaign.save('latest-' + name + '.json', result)").body[0], include_attributes=False)
            require(statements.count(capture) == 1 and statements.count(saved) == 1
                    and statements.index(capture) < statements.index(saved), 'cli_capture_binding_required')
    tree = ast.parse((base / 'runtime_install.py').read_text(encoding='utf-8'))
    require(any(isinstance(node, ast.Import) and any(alias.name == 'runtime_payload' and alias.asname is None
                                                   for alias in node.names) for node in tree.body),
            'runtime_publisher_binding_required')
    functions = [node for node in tree.body if isinstance(node, ast.FunctionDef) and node.name == 'install']
    require(len(functions) == 1, 'runtime_publisher_binding_required')
    expected = ast.dump(ast.parse('runtime_payload.publish(BUNDLE, BASE)').body[0], include_attributes=False)
    require(sum(ast.dump(node, include_attributes=False) == expected for node in functions[0].body) == 1,
            'runtime_publisher_binding_required')
    position = next(index for index, node in enumerate(functions[0].body)
                    if ast.dump(node, include_attributes=False) == expected)
    for statement in functions[0].body[:position]:
        for node in ast.walk(statement):
            if isinstance(node, ast.Call) and isinstance(node.func, ast.Name) and node.func.id == 'run' and node.args:
                args = node.args[0]
                require(not (isinstance(args, (ast.List, ast.Tuple)) and args.elts
                    and isinstance(args.elts[0], ast.Constant) and args.elts[0].value in {'systemctl', 'service', 'nginx'}),
                    'runtime_publish_before_services_required')
    cli = (base / 'live_cli.php').read_text(encoding='utf-8')
    require("require_once __DIR__ . '/cli_diagnostics.php';" in cli
            and 'keli_acceptance_cli_failure($command, $error)' in cli, 'cli_diagnostic_binding_required')
