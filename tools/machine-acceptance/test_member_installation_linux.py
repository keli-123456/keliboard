"""Real Linux installation permissions; synthetic archives, no network or services."""
import hashlib
import os
import stat
import subprocess
import unittest
from unittest.mock import patch

from member_installation import MemberInstallation
import member_runtime_bundle as runtime
from member_transport import TransportError
from test_member_runtime_bundle import RuntimeBundleTests
from test_member_environment import settings
import member_application


@unittest.skipUnless(os.name == 'posix', 'Linux installation permissions required')
class InstallationTests(unittest.TestCase):
    def setUp(self):
        self.fixture = RuntimeBundleTests()
        self.fixture.setUp(); self.addCleanup(self.fixture.doCleanups)
        args = self.fixture.runtime_fixture()
        self.archive = args[-1]; self.result = runtime.assemble(*args)
        self.root = self.fixture.root / 'controller'; self.root.mkdir(mode=0o711)
        self.installer = MemberInstallation(self.root)

    def test_actual_extract_hash_permissions_and_private_import(self):
        result = self.installer.runtime(self.archive, self.result['archive_sha256'])
        self.assertTrue(result['verified'])
        self.assertTrue((self.root / 'payload/app/vendor/autoload.php').is_file())
        raw = b'{"synthetic":true}'; digest = hashlib.sha256(raw).hexdigest()
        self.installer.private('credentials', raw, digest)
        self.assertEqual(0o600, stat.S_IMODE((self.root / 'private/credentials').stat().st_mode))
        self.assertEqual(0o700, stat.S_IMODE((self.root / 'private').stat().st_mode))
        with self.assertRaises(FileExistsError): self.installer.private('credentials', raw, digest)
        with self.assertRaisesRegex(ValueError, 'destination_not_fresh'):
            self.installer.runtime(self.archive, self.result['archive_sha256'])

    def test_writable_root_hash_drift_and_linked_private_directory_refused(self):
        self.root.chmod(0o777)
        with self.assertRaisesRegex(TransportError, 'permissions'): MemberInstallation(self.root)
        self.root.chmod(0o711)
        with self.assertRaisesRegex(ValueError, 'archive_changed'): self.installer.runtime(self.archive, '0' * 64)
        self.assertFalse((self.root / 'payload').exists())
        outside = self.fixture.root / 'outside'; outside.mkdir(mode=0o700)
        (self.root / 'private').symlink_to(outside, target_is_directory=True)
        with self.assertRaisesRegex(TransportError, 'permissions'):
            self.installer.private('credentials', b'x', hashlib.sha256(b'x').hexdigest())
        self.assertEqual([], list(outside.iterdir()))

    def test_installed_content_or_permissions_drift_fails_readback(self):
        self.installer.runtime(self.archive, self.result['archive_sha256'])
        manifest = runtime.verify(self.archive)
        path = self.root / 'payload/app/vendor/autoload.php'
        path.chmod(0o666)
        with self.assertRaisesRegex(TransportError, 'permissions'): self.installer.verify_runtime(manifest)
        path.chmod(0o644); path.write_bytes(b'changed')
        with self.assertRaisesRegex(TransportError, 'hash_changed'): self.installer.verify_runtime(manifest)

    def test_verified_runtime_publishes_to_the_fixed_cli_layout(self):
        reply = self.installer.publish_runtime(self.archive, self.result['archive_sha256'])
        self.assertTrue(reply['complete'])
        self.assertEqual(['app', 'runtime', 'node'], reply['published'])
        self.assertTrue((self.root / 'app/vendor/autoload.php').is_file())
        self.assertTrue((self.root / 'runtime/member_transport_entry.php').is_file())
        self.assertTrue((self.root / 'node/kelinode').is_file())
        with self.assertRaisesRegex(TransportError, 'target_exists'):
            self.installer.publish_runtime(self.archive, self.result['archive_sha256'])

    def test_partial_publication_is_inspected_but_never_replayed(self):
        original = type(self.root).rename
        def rename(path, destination):
            if path.name == 'runtime': raise PermissionError('synthetic denial')
            return original(path, destination)
        with patch.object(type(self.root), 'rename', rename), self.assertRaises(PermissionError):
            self.installer.publish_runtime(self.archive, self.result['archive_sha256'])
        observed = self.installer.publication(self.result['archive_sha256'])
        self.assertFalse(observed['complete']); self.assertEqual(['app'], observed['published'])
        self.assertTrue((self.root / 'private/runtime-publication.json').is_file())
        with self.assertRaisesRegex(TransportError, 'target_exists'):
            self.installer.publish_runtime(self.archive, self.result['archive_sha256'])
        with self.assertRaisesRegex(TransportError, 'intent_changed'):
            self.installer.publication('0' * 64)

    def test_unknown_destination_or_drift_is_not_adopted_as_a_publication(self):
        (self.root / 'runtime').mkdir()
        with self.assertRaisesRegex(TransportError, 'target_exists'):
            self.installer.publish_runtime(self.archive, self.result['archive_sha256'])
        self.assertFalse((self.root / 'private/runtime-publication.json').exists())
        (self.root / 'runtime').rmdir()
        self.installer.publish_runtime(self.archive, self.result['archive_sha256'])
        (self.root / 'app/vendor/autoload.php').write_bytes(b'changed')
        with self.assertRaisesRegex(TransportError, 'hash_changed'):
            self.installer.publication(self.result['archive_sha256'])

    def test_unmanifested_executable_file_is_rejected(self):
        self.installer.publish_runtime(self.archive, self.result['archive_sha256'])
        (self.root / 'app/unexpected.php').write_bytes(b'<?php echo "unexpected";')
        with self.assertRaisesRegex(TransportError, 'unexpected_files'):
            self.installer.publication(self.result['archive_sha256'])


@unittest.skipUnless(os.name == 'posix' and os.geteuid() == 0, 'root-owned installation fixture required')
class InstallationServiceUserTests(InstallationTests):
    def test_separate_service_user_loads_public_php_but_cannot_read_private_material(self):
        self.fixture.root.chmod(0o711)
        self.installer.runtime(self.archive, self.result['archive_sha256'])
        raw = b'synthetic-private-material'
        self.installer.private('credentials', raw, hashlib.sha256(raw).hexdigest())
        path = self.root / 'payload/app/vendor/autoload.php'
        command = ['setpriv', '--reuid', '65534', '--regid', '65534', '--clear-groups',
                   '--no-new-privs', '--bounding-set=-all']
        result = subprocess.run(command + ['php', '-r', 'require $argv[1]; echo "loaded";', str(path)],
                                capture_output=True, text=True, timeout=10)
        self.assertEqual(0, result.returncode, result.stderr); self.assertEqual('loaded', result.stdout)
        result = subprocess.run(command + ['php', '-r',
            'exit(@file_get_contents($argv[1]) === false ? 0 : 1);', str(self.root / 'private/credentials')],
            capture_output=True, text=True, timeout=10)
        self.assertEqual(0, result.returncode, result.stderr)

    def test_configured_service_reads_environment_and_writes_only_laravel_state(self):
        self.fixture.root.chmod(0o711)
        self.installer.publish_runtime(self.archive, self.result['archive_sha256'])
        before = (self.root / 'app/vendor/autoload.php').read_bytes()
        previous = os.umask(0o077)
        try:
            receipt = member_application.configure(self.root, self.result['archive_sha256'], settings(), uid=65534, gid=65534)
        finally:
            os.umask(previous)
        self.assertEqual(0o640, stat.S_IMODE((self.root / 'app/.env').stat().st_mode))
        self.assertEqual(0, (self.root / 'app/.env').stat().st_uid)
        self.assertNotIn(settings()['password'], str(receipt))
        self.assertEqual(before, (self.root / 'app/vendor/autoload.php').read_bytes())
        code = '''import pathlib,sys
r=pathlib.Path(sys.argv[1])
assert b"APP_ENV=acceptance" in (r/'app/.env').read_bytes()
for name in ('storage/logs/check', 'storage/framework/cache/data/check', 'storage/app/public/check'):
 p=r/'app'/name;p.write_bytes(b'ok');p.unlink()
for p in (r/'app/.env', r/'app/vendor/autoload.php', r/'runtime/member_cli_authority.php', r/'app/bootstrap/cache/services.php', r/'app/storage/app/check'):
 try:
  with p.open('ab'): pass
 except PermissionError: continue
 raise AssertionError('code_or_environment_writable')
try: (r/'private/application-intent.json').read_bytes()
except PermissionError: pass
else: raise AssertionError('private_readable')
'''
        result = subprocess.run(['setpriv', '--reuid', '65534', '--regid', '65534', '--clear-groups',
            '--no-new-privs', '--bounding-set=-all', 'python3', '-c', code, str(self.root)],
            capture_output=True, text=True, timeout=10)
        self.assertEqual(0, result.returncode, result.stderr)
        with self.assertRaises(TransportError):
            member_application.configure(self.root, self.result['archive_sha256'], settings(), uid=65534, gid=65534)

    def test_dirty_cache_and_privileged_service_identity_are_rejected(self):
        self.installer.publish_runtime(self.archive, self.result['archive_sha256'])
        with self.assertRaisesRegex(TransportError, 'service_identity'):
            member_application.configure(self.root, self.result['archive_sha256'], settings(), uid=0, gid=0)
        cache = self.root / 'app/bootstrap/cache'
        cache.mkdir(mode=0o755); (cache / 'config.php').write_text('<?php return [];')
        with self.assertRaisesRegex(TransportError, 'dirty_state|unexpected_files'):
            member_application.configure(self.root, self.result['archive_sha256'], settings(), uid=65534, gid=65534)
        self.assertFalse((self.root / 'app/.env').exists())


if __name__ == '__main__': unittest.main()
