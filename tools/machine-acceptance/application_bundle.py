"""Canonical, inspected application archives. No authorization or live transport."""
import argparse
import hashlib
import io
import json
import os
from pathlib import Path, PurePosixPath
import re
import shutil
import stat
import subprocess
import tarfile
import tempfile

import staging
from driver_journal import safe_error


REQUIRED_SHELLS = ('resources/server-machine/install-script.sh', 'resources/server-machine/kelinode-rs-install.sh',
                   'resources/server-machine/provision-diagnostics.sh', 'resources/server-machine/apt-dependencies.sh')
STOP_MARKERS = ('closed.json', 'stop-request.json', 'round-intent.json', 'control-intent.json',
                'source-dispatch-intent.json', 'cleanup-intent.json')
MAX_MEMBERS = 20000
MAX_SHELL_BYTES = 2097152
GIT_OPTIONS = ('-c', 'core.autocrlf=false', '-c', 'core.eol=lf', '-c', 'core.attributesFile=' + os.devnull,
               '-c', 'filter.lfs.process=', '-c', 'filter.lfs.smudge=', '-c', 'filter.lfs.required=false')


class BundleError(ValueError):
    pass


def require(value, code):
    if not value:
        raise BundleError(code)


def inspect_archive(path, *, required_shells=REQUIRED_SHELLS):
    digest, raw, _ = staging.read_file(Path(path), staging.MAX_FILE_BYTES, collect=True)
    shells, seen = {}, set()
    total, regular = 0, 0
    try:
        with tarfile.open(fileobj=io.BytesIO(raw), mode='r:gz') as archive:
            for member in archive:
                name = member.name
                logical = PurePosixPath(name)
                require(name and not logical.is_absolute() and '..' not in logical.parts
                        and name.rstrip('/') == logical.as_posix() and '\\' not in name
                        and not any(ord(c) < 32 or ord(c) == 127 for c in name), 'bundle_member_path_invalid')
                require(name.rstrip('/') not in seen and len(seen) < MAX_MEMBERS, 'bundle_duplicate_or_excess_members')
                seen.add(name.rstrip('/'))
                require(logical.name != '.env' and not {'.git', '.codex-audit'}.intersection(logical.parts),
                        'bundle_private_member_refused')
                require(member.isfile() or member.isdir(), 'bundle_link_or_special_member_refused')
                total += member.size
                require(total <= staging.MAX_TOTAL_BYTES, 'bundle_expanded_size_exceeded')
                if member.isdir():
                    continue
                regular += 1
                with archive.extractfile(member) as stream:
                    prefix = stream.read(256)
                    shell = name.endswith('.sh') or re.match(br'#![^\n]*(?:/|\s)(?:bash|sh|dash|ash|ksh|zsh)(?:\s|$)',
                                                            prefix.split(b'\n', 1)[0]) is not None
                    if not shell:
                        continue
                    require(member.size <= MAX_SHELL_BYTES, 'bundle_shell_size_exceeded')
                    content = prefix + stream.read(MAX_SHELL_BYTES + 1 - len(prefix))
                require(len(content) == member.size, 'bundle_shell_truncated')
                require(b'\r' not in content, 'bundle_shell_cr_refused')
                require(not content.startswith(b'\xef\xbb\xbf') and b'\0' not in content, 'bundle_shell_encoding_refused')
                try:
                    content.decode('utf-8')
                except UnicodeDecodeError:
                    raise BundleError('bundle_shell_encoding_refused') from None
                shells[name] = {'bytes': len(content), 'sha256': hashlib.sha256(content).hexdigest(),
                                'lf_only': True, 'executable': bool(member.mode & 0o111)}
    except (tarfile.TarError, EOFError):
        raise BundleError('bundle_archive_invalid') from None
    require(set(required_shells) <= shells.keys(), 'bundle_required_shell_missing')
    return {'ok': True, 'archive': digest, 'regular_files': regular, 'expanded_bytes': total,
            'shell_count': len(shells), 'shells': shells, 'required_shells_verified': list(required_shells)}


def git_read(repo, arguments, *, timeout=60):
    environment = dict(os.environ, GIT_TERMINAL_PROMPT='0', GCM_INTERACTIVE='never', GIT_NO_LAZY_FETCH='1')
    process = subprocess.run([shutil.which('git') or 'git', *GIT_OPTIONS, '-C', str(repo), *arguments],
                             stdout=subprocess.PIPE, stderr=subprocess.PIPE, env=environment, timeout=timeout)
    require(process.returncode == 0, 'bundle_local_git_failed')
    return process.stdout


def output_directory(path):
    directory, identity = staging.directory(path)
    require(not any(os.path.lexists(parent / marker) for parent in (directory, *directory.parents)
                    for marker in STOP_MARKERS), 'bundle_round_not_fresh')
    return directory, identity


def build_archive(repo, commit, destination):
    require(isinstance(commit, str) and re.fullmatch(r'[a-f0-9]{40}', commit), 'bundle_full_commit_required')
    repo = Path(repo).resolve(strict=True)
    destination = Path(destination).absolute()
    require(re.fullmatch(r'[a-z0-9][a-z0-9_.-]{0,100}\.tar\.gz', destination.name), 'bundle_output_name_invalid')
    directory, identity = output_directory(destination.parent)
    require(not os.path.lexists(destination), 'bundle_output_exists')
    require(git_read(repo, ['cat-file', '-t', commit]).strip() == b'commit', 'bundle_commit_required')
    descriptor, temporary_name = tempfile.mkstemp(prefix='.application-', suffix='.tmp', dir=directory)
    os.close(descriptor)
    temporary = Path(temporary_name)
    try:
        git_read(repo, ['archive', '--format=tar.gz', '--output=' + str(temporary), commit], timeout=90)
        with temporary.open('r+b') as stream:
            os.fsync(stream.fileno())
        report = inspect_archive(temporary)
        # A good Git blob hash is insufficient: compare it to the actual exported bytes.
        for name in REQUIRED_SHELLS:
            blob = git_read(repo, ['cat-file', 'blob', commit + ':' + name])
            require(hashlib.sha256(blob).hexdigest() == report['shells'][name]['sha256'], 'bundle_shell_differs_from_git_blob')
        staging.unchanged_directory(directory, identity)
        output_directory(directory)
        require(not os.path.lexists(destination), 'bundle_output_exists')
        # Same-directory hard linking publishes exclusively without overwriting another writer.
        os.link(temporary, destination)
    finally:
        temporary.unlink(missing_ok=True)
    if os.name == 'posix':
        descriptor = os.open(directory, os.O_RDONLY | os.O_DIRECTORY)
        try:
            os.fsync(descriptor)
        finally:
            os.close(descriptor)
    require(stat.S_ISREG(destination.lstat().st_mode) and destination.stat().st_nlink == 1, 'bundle_publish_unverified')
    return dict(report, commit=commit, source='immutable_git_objects', canonical_git_eol=True,
                lfs_filter_downloads=False, authority_created=False)


def main(arguments=None):
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest='command', required=True)
    build = commands.add_parser('build')
    build.add_argument('--repo', required=True)
    build.add_argument('--commit', required=True)
    build.add_argument('--output', required=True)
    check = commands.add_parser('check')
    check.add_argument('--archive', required=True)
    args = parser.parse_args(arguments)
    try:
        result = build_archive(args.repo, args.commit, args.output) if args.command == 'build' else inspect_archive(args.archive)
        print(json.dumps(result, ensure_ascii=True))
        return 0
    except Exception as error:
        reason = str(error) if isinstance(error, BundleError) else 'bundle_io_unavailable'
        print(json.dumps({'ok': False, 'reason': reason, 'error': safe_error(error, site='journal')}))
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
