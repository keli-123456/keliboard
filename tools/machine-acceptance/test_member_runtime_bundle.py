import hashlib
import io
import json
from pathlib import Path
import tarfile
from unittest.mock import patch

from test_member_bundle import MemberBundleTests
import member_bundle
import member_runtime_bundle as runtime


class RuntimeBundleTests(MemberBundleTests):
    def runtime_fixture(self):
        app = self.prepare()
        package = {'name': 'fixture/library', 'version': '1.0.0', 'dist': {'reference': 'a' * 40}}
        (self.repo / 'composer.lock').write_text(json.dumps({'packages': [package], 'packages-dev': []}))
        (self.repo / 'bootstrap').mkdir(exist_ok=True)
        (self.repo / 'bootstrap/app.php').write_text('<?php return true;')
        # Only these new fixture blobs enter the baseline, not the dirty test entry or .env.
        self.run_git('add', 'composer.lock', 'bootstrap/app.php')
        self.run_git('commit', '-qm', 'fixture lock')
        self.commit = self.run_git('rev-parse', 'HEAD').decode().strip()
        member_bundle.freeze(self.repo, self.commit, app, 'keli-accept-offline-r35-fixture')
        vendor = self.root / 'vendor'; (vendor / 'composer').mkdir(parents=True)
        (vendor / 'composer/installed.json').write_text(json.dumps({'packages': [package]}))
        (vendor / 'autoload.php').write_text('<?php return true;')
        binary = b'\x7fELF\x02\x01' + b'\0' * 12 + b'\x3e\x00' + b'synthetic-not-executed'
        node = self.root / 'node.tar.gz'
        with tarfile.open(node, 'w:gz') as archive:
            info = tarfile.TarInfo(runtime.NODE_MEMBER); info.size = len(binary)
            archive.addfile(info, io.BytesIO(binary))
        for key, value in [('NODE_ARCHIVE_SHA', hashlib.sha256(node.read_bytes()).hexdigest()),
                           ('NODE_SHA', hashlib.sha256(binary).hexdigest())]:
            p = patch.object(runtime, key, value); p.start(); self.addCleanup(p.stop)
        return app, vendor, node, self.output / 'runtime.tar.gz'

    def test_full_runtime_has_app_vendor_node_and_matching_runtime_copies(self):
        args = self.runtime_fixture(); result = runtime.assemble(*args)
        manifest = runtime.verify(args[-1], result['archive_sha256'])
        self.assertFalse(result['execution_authorized']); self.assertFalse(result['live_ready'])
        self.assertEqual(manifest['dependency_count'], 1)
        out = self.root / 'unpacked'; runtime.unpack(args[-1], out, result['archive_sha256'])
        self.assertTrue((out / 'app/vendor/autoload.php').is_file())
        self.assertTrue((out / 'node/kelinode').is_file())
        for name in runtime.RUNTIME:
            self.assertEqual((out / 'runtime' / name).read_bytes(),
                             (out / 'app/tools/machine-acceptance' / name).read_bytes())
        with self.assertRaisesRegex(ValueError, 'output_exists'): runtime.assemble(*args)
        with self.assertRaisesRegex(ValueError, 'destination_not_fresh'):
            runtime.unpack(args[-1], out, result['archive_sha256'])

    def test_dependency_lock_drift_and_binary_hash_drift_fail_before_publish(self):
        args = self.runtime_fixture()
        (args[1] / 'composer/installed.json').write_text('{"packages":[]}')
        with self.assertRaisesRegex(ValueError, 'vendor_lock_mismatch'): runtime.assemble(*args)
        self.assertFalse(args[-1].exists())
        args[2].write_bytes(b'wrong node')
        with self.assertRaisesRegex(ValueError, 'node_archive_changed'): runtime.assemble(*args)

    def test_extract_requires_archive_hash_no_links_and_no_path_escape(self):
        args = self.runtime_fixture(); result = runtime.assemble(*args)
        out = self.root / 'extract'
        with self.assertRaisesRegex(ValueError, 'archive_changed'): runtime.unpack(args[-1], out, '0' * 64)
        self.assertFalse(out.exists())
        for name, link in [('../outside', False), ('app/vendor/link', True), ('/absolute', False)]:
            changed = self.output / 'tampered.tar.gz'
            with tarfile.open(args[-1]) as source, tarfile.open(changed, 'w:gz') as destination:
                for item in source: destination.addfile(item, source.extractfile(item))
                item = tarfile.TarInfo(name)
                if link: item.type = tarfile.SYMTYPE; item.linkname = '/etc/passwd'
                destination.addfile(item)
            with self.assertRaises(ValueError): runtime.verify(changed)

    def test_committed_transport_trait_is_not_duplicated_in_next_bundle(self):
        app = self.prepare()
        entry = self.repo / 'tests/Unit/Services/MachineFailoverNativeBridgeTest.php'
        entry.write_bytes(b'<?php\n    use \\Tests\\Support\\TestsMemberRetryDriver;\n'
                          b'    use \\Tests\\Support\\TestsMemberTransport;\n')
        self.run_git('add', str(entry)); self.run_git('commit', '-qm', 'existing trait')
        commit = self.run_git('rev-parse', 'HEAD').decode().strip()
        member_bundle.freeze(self.repo, commit, app, 'keli-accept-offline-r35-fixture')
        with tarfile.open(app) as archive:
            self.assertEqual(archive.extractfile('tests/Unit/Services/MachineFailoverNativeBridgeTest.php')
                             .read().count(b'TestsMemberTransport'), 1)
