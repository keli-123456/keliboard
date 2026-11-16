"""Synthetic local Git fixtures; no network, credentials or real round authority."""
import hashlib
import io
import json
import tarfile

from test_application_bundle import BundleCase
import member_bundle


class MemberBundleTests(BundleCase):
    def prepare(self):
        entry = self.repo / 'tests/Unit/Services/MachineFailoverNativeBridgeTest.php'
        entry.parent.mkdir(parents=True)
        entry.write_bytes(b'<?php\n    use \\Tests\\Support\\TestsMemberRetryDriver;\n')
        self.run_git('add', '.'); self.run_git('commit', '-qm', 'fixture baseline')
        self.commit = self.run_git('rev-parse', 'HEAD').decode().strip()
        for name in member_bundle.FILES:
            file = self.repo / name; file.parent.mkdir(parents=True, exist_ok=True)
            file.write_bytes(b'fixture\r\n')
        entry.write_bytes(b'unrelated dirty test content')
        (self.repo / '.env').write_text('not included')
        return self.output / 'member.tar.gz'

    def test_freeze_contains_only_declared_overlay_and_no_authority(self):
        path = self.prepare()
        result = member_bundle.freeze(self.repo, self.commit, path, 'keli-accept-offline-r35-fixture')
        self.assertFalse(result['execution_authorized']); self.assertFalse(result['live_ready'])
        self.assertFalse(result['node_release_included'])
        self.assertEqual(result['archive']['sha256'], hashlib.sha256(path.read_bytes()).hexdigest())
        member_bundle.verify(path)
        with tarfile.open(path) as archive:
            self.assertNotIn('.env', archive.getnames())
            entry = archive.extractfile('tests/Unit/Services/MachineFailoverNativeBridgeTest.php').read()
            self.assertIn(b'TestsMemberTransport', entry); self.assertNotIn(b'unrelated', entry)
            self.assertEqual(entry.count(b'TestsMemberCli'), 1)
            for name in member_bundle.FILES:
                self.assertEqual(archive.extractfile(name).read(), b'fixture\n')
        with self.assertRaisesRegex(ValueError, 'output_exists'):
            member_bundle.freeze(self.repo, self.commit, path, 'keli-accept-offline-r35-fixture')

    def test_missing_overlay_prevents_freeze(self):
        path = self.prepare(); (self.repo / member_bundle.FILES[0]).unlink()
        with self.assertRaises(FileNotFoundError):
            member_bundle.freeze(self.repo, self.commit, path, 'keli-accept-offline-r35-fixture')
        self.assertFalse(path.exists())

    def test_tamper_is_not_a_valid_freeze(self):
        path = self.prepare()
        member_bundle.freeze(self.repo, self.commit, path, 'keli-accept-offline-r35-fixture')
        changed = self.output / 'changed.tar.gz'
        with tarfile.open(path) as source, tarfile.open(changed, 'w:gz') as output:
            for item in source:
                raw = source.extractfile(item).read() if item.isfile() else None
                if item.name == member_bundle.FILES[0]: raw = b'tampered\n'; item.size = len(raw)
                output.addfile(item, io.BytesIO(raw) if raw is not None else None)
        with self.assertRaisesRegex(ValueError, 'file_changed'): member_bundle.verify(changed)
