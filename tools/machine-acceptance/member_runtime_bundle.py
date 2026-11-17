"""Assemble an immutable, unarmed Linux R35 application + vendor + pinned ELF."""
import argparse
import gzip
import hashlib
import io
import json
import os
from pathlib import Path, PurePosixPath
import tarfile
import tempfile

import member_bundle
import staging
from application_bundle import require, output_directory
from driver_journal import unique_json

NODE_SOURCE = '3c707fc89b16e2895727c24251b6076252d5cab0'
NODE_ARCHIVE_SHA = 'e9012da846fc6b5404ea842bf2e8cf19e1883eac8f2f2d517a961940c018e689'
NODE_SHA = '654a8344d22bc3373b114345d49d5f6a2804be98e6f51bc2ce85e309fbda0be0'
NODE_MEMBER = 'kelinode-local-3c707fc/bin/kelinode'
RUNTIME = member_bundle.RUNTIME
MAX_BYTES = 536870912


def sha(raw):
    return hashlib.sha256(raw).hexdigest()


def packages(items):
    result = {p['name']: (p['version'], p.get('dist', {}).get('reference')) for p in items}
    require(len(result) == len(items), 'runtime_duplicate_dependency')
    return result


def assemble(application, vendor, node_archive, destination):
    app = member_bundle.verify(application)
    vendor, destination = Path(vendor).absolute(), Path(destination).absolute()
    output, identity = output_directory(destination.parent)
    require(not os.path.lexists(destination), 'runtime_output_exists')
    require(vendor.resolve(strict=True) == vendor, 'runtime_vendor_link_refused')
    node_digest, node_raw, _ = staging.read_file(Path(node_archive), 64 * 1024 * 1024, collect=True)
    require(node_digest['sha256'] == NODE_ARCHIVE_SHA, 'runtime_node_archive_changed')
    with tarfile.open(fileobj=io.BytesIO(node_raw)) as source:
        nodes = [n for n in source if n.name == NODE_MEMBER]
        require(len(nodes) == 1 and nodes[0].isfile() and nodes[0].size < 64 * 1024 * 1024, 'runtime_node_missing')
        binary = source.extractfile(nodes[0]).read()
    require(sha(binary) == NODE_SHA and binary[:6] == b'\x7fELF\x02\x01'
            and binary[18:20] == b'\x3e\x00', 'runtime_node_not_pinned_x86_64')
    _, installed_raw, _ = staging.read_file(vendor / 'composer/installed.json', 4 * 1024 * 1024, collect=True)
    installed = unique_json(installed_raw)
    files = {}
    manifest = {'schema': 1, 'run_id': app['run_id'], 'base_commit': app['base_commit'],
        'execution_authorized': False, 'live_ready': False, 'credentials_included': False,
        'artifact_kind': 'unarmed_r35_linux_runtime', 'node': {'version': 'v0.1.387',
            'source_commit': NODE_SOURCE, 'sha256': NODE_SHA, 'input_archive_sha256': NODE_ARCHIVE_SHA,
            'new_compilation': False, 'path': 'node/kelinode', 'archive_path': 'node/release.tar.gz',
            'manifest_path': 'node/release.json'}, 'files': files}
    with tempfile.TemporaryDirectory(prefix='runtime-freeze-', dir=output) as directory:
        tmp = Path(directory) / 'runtime.tar.gz'
        with tmp.open('wb') as stream, gzip.GzipFile(filename='', fileobj=stream, mode='wb', mtime=0, compresslevel=6) as gz:
            with tarfile.open(fileobj=gz, mode='w') as archive:
                def add(name, raw, mode=0o644):
                    require(name not in files, 'runtime_duplicate_path')
                    info = tarfile.TarInfo(name); info.mode = mode; info.size = len(raw)
                    archive.addfile(info, io.BytesIO(raw))
                    files[name] = {'sha256': sha(raw), 'bytes': len(raw), 'mode': mode}
                with tarfile.open(application) as source:
                    lock_raw = source.extractfile('composer.lock').read()
                    lock = unique_json(lock_raw)
                    require(packages(lock['packages'] + lock.get('packages-dev', [])) == packages(installed['packages']),
                            'runtime_vendor_lock_mismatch')
                    manifest['composer_lock_sha256'] = sha(lock_raw)
                    manifest['dependency_count'] = len(installed['packages'])
                    for item in source:
                        if item.isfile():
                            require(not item.name.startswith('vendor/'), 'runtime_vendor_already_present')
                            add('app/' + item.name, source.extractfile(item).read(), item.mode & 0o755)
                    for name in RUNTIME:
                        add('runtime/' + name, source.extractfile('tools/machine-acceptance/' + name).read())
                for file in sorted(vendor.rglob('*')):
                    require(not file.is_symlink() and file.resolve(strict=True) == file, 'runtime_vendor_link_refused')
                    if not file.is_file():
                        require(file.is_dir(), 'runtime_vendor_special_refused')
                        continue
                    relative = file.relative_to(vendor).as_posix()
                    require(not {'.git', '.env', 'auth.json'}.intersection(PurePosixPath(relative).parts),
                            'runtime_private_dependency_refused')
                    _, raw, _ = staging.read_file(file, 64 * 1024 * 1024, collect=True)
                    add('app/vendor/' + relative, raw, 0o755 if relative.startswith('bin/') else 0o644)
                add('node/kelinode', binary, 0o755)
                add('node/release.tar.gz', node_raw)
                add('node/release.json', (json.dumps({'component': 'kelinode-rs', 'version': 'v0.1.387',
                    'platform': 'linux-x86_64', 'sha256': NODE_ARCHIVE_SHA, 'binary_sha256': NODE_SHA},
                    sort_keys=True) + '\n').encode())
                require(sum(f['bytes'] for f in files.values()) <= MAX_BYTES, 'runtime_expanded_limit')
                raw = (json.dumps(manifest, sort_keys=True, indent=2) + '\n').encode()
                item = tarfile.TarInfo('runtime-manifest.json'); item.mode = 0o644; item.size = len(raw)
                archive.addfile(item, io.BytesIO(raw))
        verify(tmp)
        staging.unchanged_directory(output, identity)
        os.link(tmp, destination)
    return {'archive_sha256': staging.read_file(destination, staging.MAX_FILE_BYTES)[0]['sha256'],
            'files': len(files), 'dependency_count': manifest['dependency_count'],
            'composer_lock_sha256': manifest['composer_lock_sha256'], 'node_sha256': NODE_SHA,
            'execution_authorized': False, 'live_ready': False}


def verify(path, expected_sha256=None):
    observed, raw, _ = staging.read_file(Path(path), staging.MAX_FILE_BYTES, collect=True)
    require(expected_sha256 is None or observed['sha256'] == expected_sha256, 'runtime_archive_changed')
    with tarfile.open(fileobj=io.BytesIO(raw)) as archive:
        seen, total = {}, 0
        for item in archive:
            p = PurePosixPath(item.name)
            require(item.isfile() and item.name == p.as_posix() and not p.is_absolute() and '..' not in p.parts
                    and '\\' not in item.name and not any(ord(c) < 32 for c in item.name), 'runtime_unsafe_member')
            require(item.name not in seen and len(seen) < 50000, 'runtime_duplicate_path')
            require(p.name not in ('.env', 'authorization.json', 'member-runtime-scope.json', 'auth.json')
                    and not {'.git', '.codex-audit'}.intersection(p.parts), 'runtime_authority_refused')
            total += item.size
            require(total <= MAX_BYTES and item.size <= 64 * 1024 * 1024, 'runtime_expanded_limit')
            content = archive.extractfile(item).read()
            seen[item.name] = {'sha256': sha(content), 'bytes': len(content), 'mode': item.mode}
            if item.name == 'runtime-manifest.json':
                manifest = unique_json(content)
            if item.name == 'node/release.json':
                release = unique_json(content)
            if item.name.startswith('runtime/'):
                require(b'\r' not in content, 'runtime_crlf_refused')
        require('runtime-manifest.json' in seen, 'runtime_manifest_missing')
        del seen['runtime-manifest.json']
        require(manifest['files'] == seen and manifest['execution_authorized'] is False
                and manifest['live_ready'] is False and manifest['credentials_included'] is False,
                'runtime_manifest_changed')
        require(seen['node/kelinode']['sha256'] == NODE_SHA and seen['node/kelinode']['mode'] == 0o755,
                'runtime_node_changed')
        require(seen.get('node/release.tar.gz', {}).get('sha256') == NODE_ARCHIVE_SHA
                and 'node/release.json' in seen and release == {'component': 'kelinode-rs', 'version': 'v0.1.387',
                    'platform': 'linux-x86_64', 'sha256': NODE_ARCHIVE_SHA, 'binary_sha256': NODE_SHA},
                'runtime_release_changed')
        for name in RUNTIME:
            require(seen['runtime/' + name]['sha256'] == seen['app/tools/machine-acceptance/' + name]['sha256'],
                    'runtime_duplicate_source_changed')
        require('app/vendor/autoload.php' in seen and 'app/bootstrap/app.php' in seen, 'runtime_layout_incomplete')
    return manifest


def unpack(path, destination, expected_sha256):
    # Verification finishes before creating a directory; extraction never follows archive links.
    manifest = verify(path, expected_sha256)
    observed, frozen, _ = staging.read_file(Path(path), staging.MAX_FILE_BYTES, collect=True)
    require(observed['sha256'] == expected_sha256, 'runtime_archive_changed')
    destination = Path(destination).absolute()
    require(not os.path.lexists(destination) and destination.parent.resolve(strict=True) == destination.parent,
            'runtime_destination_not_fresh')
    destination.mkdir(mode=0o700)
    with tarfile.open(fileobj=io.BytesIO(frozen)) as archive:
        for item in archive:
            target = destination.joinpath(*PurePosixPath(item.name).parts)
            target.parent.mkdir(parents=True, exist_ok=True)
            require(target.parent.resolve(strict=True) == target.parent, 'runtime_extract_parent_changed')
            with target.open('xb') as stream:
                content = archive.extractfile(item).read()
                # Revalidate bytes in case the input archive changed after verify.
                if item.name != 'runtime-manifest.json':
                    require(sha(content) == manifest['files'][item.name]['sha256'], 'runtime_extract_changed')
                stream.write(content)
            target.chmod(item.mode & 0o755)
    return manifest


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--application', required=True); parser.add_argument('--vendor', required=True)
    parser.add_argument('--node-archive', required=True); parser.add_argument('--output', required=True)
    args = parser.parse_args()
    print(json.dumps(assemble(args.application, args.vendor, args.node_archive, args.output), sort_keys=True))
