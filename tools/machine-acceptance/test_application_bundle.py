"""Offline Git/archive fixtures. Never install a node or contact a service."""
import contextlib
import hashlib
import io
import json
import os
from pathlib import Path
import shutil
import subprocess
import tarfile
import tempfile
import unittest
from unittest.mock import patch

import application_bundle as bundle


class BundleCase(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory(prefix='keli-bundle-fixture-')
        self.addCleanup(temporary.cleanup)
        self.root = Path(temporary.name).resolve()
        self.repo, self.output = self.root / 'repo', self.root / 'output'
        self.repo.mkdir(mode=0o700); self.output.mkdir(mode=0o700)
        self.git = shutil.which('git')
        self.assertTrue(self.git, 'local Git required')
        self.env = dict(os.environ, GIT_CONFIG_GLOBAL=os.devnull, GIT_CONFIG_NOSYSTEM='1', GIT_NO_LAZY_FETCH='1',
                        GIT_TERMINAL_PROMPT='0', GCM_INTERACTIVE='never')
        self.run_git('init', '-q')
        (self.repo / '.gitattributes').write_bytes(b'* text=auto\n*.dat -text\n')
        for name in bundle.REQUIRED_SHELLS:
            path = self.repo / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(b'#!/usr/bin/env bash\nset -euo pipefail\nexit 99\n')
        (self.repo / 'README.txt').write_bytes(b'committed\n')
        (self.repo / 'binary.dat').write_bytes(b'\0\r\nKEEP\r\n')
        self.run_git('add', '.')
        self.run_git('update-index', '--chmod=+x', '--', bundle.REQUIRED_SHELLS[1])
        self.run_git('commit', '-qm', 'synthetic fixture')
        self.commit = self.run_git('rev-parse', 'HEAD').decode().strip()

    def run_git(self, *arguments, autocrlf='false', eol='lf'):
        args = [self.git, '-c', 'core.hooksPath=' + os.devnull, '-c', 'commit.gpgsign=false',
                '-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.test',
                '-c', 'core.autocrlf=' + autocrlf, '-c', 'core.eol=' + eol, '-C', str(self.repo), *arguments]
        result = subprocess.run(args, env=self.env, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=30)
        self.assertEqual(result.returncode, 0, 'local fixture Git operation failed')
        return result.stdout

    def legacy_archive(self, name='legacy.tar.gz'):
        path = self.output / name
        self.run_git('archive', '--format=tar.gz', '--output=' + str(path), self.commit, autocrlf='true', eol='crlf')
        return path

    def archive_member(self, path, name):
        with tarfile.open(path) as archive:
            return archive.extractfile(name).read()

    def fixture_archive(self, name, entries):
        path = self.output / name
        with tarfile.open(path, 'w:gz') as archive:
            for member, data in entries:
                archive.addfile(member, io.BytesIO(data) if member.isfile() else None)
        return path

    @staticmethod
    def member(name, raw=b'#!/bin/sh\nexit 0\n', kind=tarfile.REGTYPE):
        member = tarfile.TarInfo(name)
        member.type, member.mode, member.size = kind, 0o755, len(raw)
        if kind in {tarfile.SYMTYPE, tarfile.LNKTYPE}:
            member.linkname = '../outside'
        return member, raw


class BundleTests(BundleCase):
    def test_canonical_archive_contains_lf_git_bytes_and_preserves_binary_and_mode(self):
        path = self.output / 'application.tar.gz'
        result = bundle.build_archive(self.repo, self.commit, path)
        self.assertTrue(result['ok'] and result['canonical_git_eol'])
        self.assertEqual(result['commit'], self.commit)
        self.assertEqual(result['archive']['sha256'], hashlib.sha256(path.read_bytes()).hexdigest())
        for name in bundle.REQUIRED_SHELLS:
            self.assertNotIn(b'\r', self.archive_member(path, name))
            self.assertEqual(self.archive_member(path, name), self.run_git('cat-file', 'blob', self.commit + ':' + name))
        self.assertEqual(self.archive_member(path, 'binary.dat'), b'\0\r\nKEEP\r\n')
        self.assertTrue(result['shells'][bundle.REQUIRED_SHELLS[1]]['executable'])
        self.assertEqual(path.stat().st_nlink, 1)
        self.assertFalse(list(self.output.glob('.application-*')))

    def test_legacy_windows_git_profile_reproduces_crlf_artifact_and_is_rejected(self):
        path = self.legacy_archive()
        self.assertIn(b'\r\n', self.archive_member(path, bundle.REQUIRED_SHELLS[1]))
        with self.assertRaisesRegex(bundle.BundleError, 'bundle_shell_cr_refused'):
            bundle.inspect_archive(path)

    def test_canonical_export_is_independent_of_host_eol_without_changing_config(self):
        self.run_git('config', 'core.autocrlf', 'true')
        self.run_git('config', 'core.eol', 'crlf')
        config = (self.repo / '.git/config').read_bytes()
        first = bundle.build_archive(self.repo, self.commit, self.output / 'windows.tar.gz')
        self.assertEqual((self.repo / '.git/config').read_bytes(), config)
        self.run_git('config', 'core.autocrlf', 'false')
        self.run_git('config', 'core.eol', 'lf')
        config = (self.repo / '.git/config').read_bytes()
        second = bundle.build_archive(self.repo, self.commit, self.output / 'linux.tar.gz')
        self.assertEqual(first['archive'], second['archive'])
        self.assertEqual(first['shells'], second['shells'])
        self.assertEqual((self.repo / '.git/config').read_bytes(), config)

    def test_shell_attribute_protects_legacy_export_even_under_autocrlf_true(self):
        (self.repo / '.gitattributes').write_bytes(b'* text=auto\n*.dat -text\n*.sh text eol=lf\n')
        self.run_git('add', '.gitattributes')
        self.run_git('commit', '-qm', 'shell LF attribute')
        self.commit = self.run_git('rev-parse', 'HEAD').decode().strip()
        result = bundle.inspect_archive(self.legacy_archive())
        self.assertEqual(result['shell_count'], len(bundle.REQUIRED_SHELLS))

    def test_dirty_tree_and_untracked_private_file_never_enter_archive(self):
        (self.repo / bundle.REQUIRED_SHELLS[1]).write_bytes(b'changed\r\n')
        (self.repo / '.env').write_bytes(b'SYNTHETIC_PRIVATE_MUST_NOT_ENTER_ARCHIVE=1\n')
        (self.repo / 'untracked.sh').write_bytes(b'changed\r\n')
        result = bundle.build_archive(self.repo, self.commit, self.output / 'committed.tar.gz')
        self.assertTrue(result['ok'])
        with tarfile.open(self.output / 'committed.tar.gz') as archive:
            self.assertNotIn('.env', archive.getnames())
            self.assertNotIn('untracked.sh', archive.getnames())
        self.assertEqual(self.archive_member(self.output / 'committed.tar.gz', bundle.REQUIRED_SHELLS[1]),
                         self.run_git('cat-file', 'blob', self.commit + ':' + bundle.REQUIRED_SHELLS[1]))

    def test_existing_output_is_never_overwritten_or_retried(self):
        path = self.output / 'existing.tar.gz'
        path.write_bytes(b'keep')
        with patch.object(bundle, 'git_read') as called, self.assertRaisesRegex(bundle.BundleError, 'bundle_output_exists'):
            bundle.build_archive(self.repo, self.commit, path)
        called.assert_not_called()
        self.assertEqual(path.read_bytes(), b'keep')

    def test_closed_or_dispatched_parent_and_nested_output_are_refused_before_git(self):
        nested = self.output / 'nested'
        nested.mkdir(mode=0o700)
        for marker in bundle.STOP_MARKERS:
            (self.output / marker).write_bytes(b'{}')
            try:
                for parent in [self.output, nested]:
                    with self.subTest(marker=marker, nested=parent == nested), patch.object(bundle, 'git_read') as called:
                        with self.assertRaisesRegex(bundle.BundleError, 'bundle_round_not_fresh'):
                            bundle.build_archive(self.repo, self.commit, parent / 'new.tar.gz')
                        called.assert_not_called()
            finally:
                (self.output / marker).unlink()

    def test_invalid_commit_or_output_name_never_starts_git(self):
        for commit in ['HEAD', '--remote=other', self.commit[:8], None]:
            with self.subTest(commit=commit), patch.object(bundle, 'git_read') as called:
                with self.assertRaises(bundle.BundleError):
                    bundle.build_archive(self.repo, commit, self.output / 'new.tar.gz')
                called.assert_not_called()
        with patch.object(bundle, 'git_read') as called, self.assertRaises(bundle.BundleError):
            bundle.build_archive(self.repo, self.commit, self.output / 'not-a-tar.zip')
        called.assert_not_called()

    def test_failed_export_removes_only_its_temporary_file(self):
        original = bundle.git_read
        def failed(repo, args, **kwargs):
            if args[0] == 'archive':
                raise bundle.BundleError('synthetic_git_failed')
            return original(repo, args, **kwargs)
        with patch.object(bundle, 'git_read', side_effect=failed), self.assertRaises(bundle.BundleError):
            bundle.build_archive(self.repo, self.commit, self.output / 'failed.tar.gz')
        self.assertFalse(list(self.output.iterdir()))

    def test_publish_race_preserves_the_other_writers_file(self):
        destination = self.output / 'raced.tar.gz'
        def raced(source, target):
            with Path(target).open('xb') as stream:
                stream.write(b'other-writer')
            raise FileExistsError()
        with patch.object(bundle.os, 'link', side_effect=raced), self.assertRaises(FileExistsError):
            bundle.build_archive(self.repo, self.commit, destination)
        self.assertEqual(destination.read_bytes(), b'other-writer')
        self.assertFalse(list(self.output.glob('.application-*')))

    def test_archive_paths_links_private_files_and_duplicates_are_rejected(self):
        cases = [('absolute', [self.member('/outside.sh')]), ('traversal', [self.member('../outside.sh')]),
                 ('backslash', [self.member('dir\\outside.sh')]), ('noncanonical', [self.member('dir/./script.sh')]),
                 ('symlink', [self.member('link', kind=tarfile.SYMTYPE)]),
                 ('hardlink', [self.member('link', kind=tarfile.LNKTYPE)]),
                 ('private', [self.member('dir/.env')]), ('audit', [self.member('.codex-audit/entry.sh')]),
                 ('duplicate', [self.member('entry.sh'), self.member('entry.sh')])]
        for name, entries in cases:
            path = self.fixture_archive(name + '.tar.gz', entries)
            with self.subTest(kind=name), self.assertRaises(bundle.BundleError):
                bundle.inspect_archive(path, required_shells=())

    def test_cr_bom_binary_and_non_utf8_shells_are_rejected(self):
        for number, raw in enumerate([b'#!/bin/sh\r\nexit 0\r\n', b'#!/bin/sh\rexit 0\r',
                                      b'\xef\xbb\xbf#!/bin/sh\n', b'#!/bin/sh\n\0', b'#!/bin/sh\n\xff']):
            path = self.fixture_archive('encoding-' + str(number) + '.tar.gz', [self.member('entry.sh', raw)])
            with self.subTest(case=number), self.assertRaises(bundle.BundleError):
                bundle.inspect_archive(path, required_shells=())

    def test_extensionless_shell_and_required_missing_are_checked(self):
        path = self.fixture_archive('extensionless.tar.gz', [self.member('entry', b'#!/usr/bin/env bash\r\nexit 0\r\n')])
        with self.assertRaisesRegex(bundle.BundleError, 'bundle_shell_cr_refused'):
            bundle.inspect_archive(path, required_shells=())
        path = self.fixture_archive('missing.tar.gz', [self.member('entry.sh')])
        with self.assertRaisesRegex(bundle.BundleError, 'bundle_required_shell_missing'):
            bundle.inspect_archive(path)

    def test_corrupt_archive_and_shell_size_limits_are_rejected(self):
        path = self.output / 'corrupt.tar.gz'
        path.write_bytes(b'not-a-tar')
        with self.assertRaises(bundle.BundleError):
            bundle.inspect_archive(path)
        path = self.fixture_archive('large.tar.gz', [self.member('entry.sh', b'#!/bin/sh\n' + b'x' * 1024)])
        with patch.object(bundle, 'MAX_SHELL_BYTES', 512), self.assertRaisesRegex(bundle.BundleError, 'bundle_shell_size_exceeded'):
            bundle.inspect_archive(path, required_shells=())

    def test_cli_failure_is_structured_and_does_not_export_exception_text(self):
        with patch.object(bundle, 'inspect_archive', side_effect=OSError('SYNTHETIC_PRIVATE_VALUE')), \
             patch('sys.stdout', new_callable=io.StringIO) as output:
            code = bundle.main(['check', '--archive', str(self.output / 'missing.tar.gz')])
        self.assertEqual(code, 1)
        self.assertNotIn('SYNTHETIC_PRIVATE_VALUE', output.getvalue())
        self.assertFalse(json.loads(output.getvalue())['ok'])

    @unittest.skipUnless(os.name == 'posix', 'actual Linux Bash line parsing')
    def test_linux_bash_reproduces_exit_2_from_crlf_prefix_and_lf_passes(self):
        bad = self.legacy_archive()
        good = self.output / 'lf.tar.gz'
        bundle.build_archive(self.repo, self.commit, good)
        codes = []
        environment = {key: value for key, value in os.environ.items() if key not in {'BASH_ENV', 'ENV', 'SHELLOPTS', 'BASHOPTS'}}
        for path in [bad, good]:
            prefix = b''.join(self.archive_member(path, bundle.REQUIRED_SHELLS[1]).splitlines(keepends=True)[:2])
            process = subprocess.run(['/bin/bash', '--noprofile', '--norc', '-s'], input=prefix,
                                     capture_output=True, env=environment, timeout=5)
            codes.append(process.returncode)
        self.assertEqual(codes, [2, 0])


if __name__ == '__main__':
    unittest.main()
