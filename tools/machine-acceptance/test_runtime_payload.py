import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest
from unittest.mock import patch

import runtime_payload as payload


HERE = Path(__file__).resolve().parent
PHP = os.environ.get('PHP_BINARY') or shutil.which('php')


class RuntimePayloadTests(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory(prefix='keli-runtime-offline-')
        self.addCleanup(temporary.cleanup)
        self.root = Path(temporary.name).resolve()
        self.bundle = self.root / 'bundle'
        self.target = self.root / 'runtime'
        self.bundle.mkdir(mode=0o700)
        self.target.mkdir(mode=0o755)
        pins = {}
        for name in payload.STAGED:
            source = HERE / name
            content = source.read_bytes() if source.is_file() else b'<?php // public inert fixture\n'
            (self.bundle / name).write_bytes(content)
            pins[name] = {'bytes': len(content), 'sha256': hashlib.sha256(content).hexdigest()}
        self.manifest = {'schema': 1, 'run_id': 'keli-accept-20990101-offline-runtime', 'role': 'control', 'files': pins}
        self.save_manifest()

    def save_manifest(self):
        (self.bundle / 'staging-manifest.json').write_text(json.dumps(self.manifest), encoding='utf-8')

    def test_active_runtime_contains_every_dependency_with_readback(self):
        result = payload.publish(self.bundle, self.target)
        self.assertEqual(result, {'ok': True, 'files_verified': len(payload.FILES), 'active_hashes_verified': True})
        for name in payload.FILES:
            self.assertEqual((self.bundle / name).read_bytes(), (self.target / name).read_bytes())

    def test_missing_manifest_or_staged_helper_refuses_before_any_write(self):
        for name in ['source_fixture_evidence.php', 'cli_diagnostics.php']:
            with self.subTest(name=name):
                original = self.manifest['files'].pop(name)
                self.save_manifest()
                with self.assertRaises(payload.Refused):
                    payload.publish(self.bundle, self.target)
                self.assertEqual(list(self.target.iterdir()), [])
                self.manifest['files'][name] = original
                self.save_manifest()
                content = (self.bundle / name).read_bytes()
                (self.bundle / name).unlink()
                with self.assertRaises(OSError):
                    payload.publish(self.bundle, self.target)
                self.assertEqual(list(self.target.iterdir()), [])
                (self.bundle / name).write_bytes(content)

    def test_hash_drift_duplicate_manifest_and_invalid_size_refuse(self):
        for name in ['source_fixture_evidence.php', 'cli_diagnostics.php']:
            content = (self.bundle / name).read_bytes()
            (self.bundle / name).write_bytes(content + b'// changed\n')
            with self.assertRaisesRegex(payload.Refused, 'runtime_hash_mismatch'):
                payload.publish(self.bundle, self.target)
            (self.bundle / name).write_bytes(content)
        (self.bundle / 'staging-manifest.json').write_text('{"schema":1,"schema":1}')
        with self.assertRaisesRegex(payload.Refused, 'runtime_manifest_duplicate'):
            payload.publish(self.bundle, self.target)
        self.save_manifest()
        self.manifest['files']['source_fixture.php']['bytes'] = True
        self.save_manifest()
        with self.assertRaisesRegex(payload.Refused, 'runtime_pin_invalid'):
            payload.publish(self.bundle, self.target)
        self.assertEqual(list(self.target.iterdir()), [])

    def test_existing_runtime_is_not_overwritten_and_tamper_cannot_verify(self):
        payload.publish(self.bundle, self.target)
        before = (self.target / 'source_fixture.php').read_bytes()
        with self.assertRaisesRegex(payload.Refused, 'runtime_original_install_only'):
            payload.publish(self.bundle, self.target)
        self.assertEqual((self.target / 'source_fixture.php').read_bytes(), before)
        (self.target / 'source_fixture_evidence.php').write_bytes(b'<?php // tamper\n')
        with self.assertRaisesRegex(payload.Refused, 'runtime_hash_mismatch'):
            payload.verify(self.bundle, self.target)

    def test_write_or_readback_failure_is_failure_not_runtime_success(self):
        with patch.object(payload.os, 'fsync', side_effect=PermissionError('private-sentinel-never-export')):
            with self.assertRaises(PermissionError):
                payload.publish(self.bundle, self.target)
        with self.assertRaises(OSError):
            payload.verify(self.bundle, self.target)

    @unittest.skipUnless(os.name == 'posix', 'real POSIX ownership and symlink checks require Linux')
    def test_symlink_hardlink_and_writable_stage_are_refused(self):
        path = self.bundle / 'source_fixture_evidence.php'
        content = path.read_bytes()
        path.unlink()
        path.symlink_to(self.bundle / 'source_fixture.php')
        with self.assertRaises(payload.Refused):
            payload.publish(self.bundle, self.target)
        path.unlink()
        os.link(self.bundle / 'source_fixture.php', path)
        with self.assertRaises(payload.Refused):
            payload.publish(self.bundle, self.target)
        path.unlink()
        path.write_bytes(content)
        self.bundle.chmod(0o777)
        with self.assertRaises(payload.Refused):
            payload.publish(self.bundle, self.target)

    def bindings(self):
        names = tuple(sorted(payload.STAGED))
        (self.root / 'live_operator.py').write_text('import cli_error_capture\nRUNTIME = ' + repr(names) +
            "\ndef command():\n    result = cli_error_capture.capture_result(campaign, result)\n    campaign.save('latest-' + name + '.json', result)\n")
        (self.root / 'staging_control.py').write_text('STAGING = ' + repr(names))
        (self.root / 'runtime_install.py').write_text('import runtime_payload\ndef install():\n    runtime_payload.publish(BUNDLE, BASE)\n')
        (self.root / 'live_cli.php').write_text("<?php\nrequire_once __DIR__ . '/cli_diagnostics.php';\n$out = keli_acceptance_cli_failure($command, $error);\n")

    def test_fresh_binding_checks_active_installation_not_just_staging(self):
        self.bindings()
        payload.validate_bindings(self.root)
        (self.root / 'runtime_install.py').write_text("def install():\n    for name in ['source_fixture.php']:\n        run(['install', name])\n")
        with self.assertRaisesRegex(payload.Refused, 'runtime_publisher_binding_required'):
            payload.validate_bindings(self.root)
        self.bindings()
        (self.root / 'live_cli.php').write_text('<?php // diagnostic removed\n')
        with self.assertRaisesRegex(payload.Refused, 'cli_diagnostic_binding_required'):
            payload.validate_bindings(self.root)
        self.bindings()
        (self.root / 'runtime_install.py').write_text("import runtime_payload\ndef install():\n    run(['systemctl', 'start', 'isolated'])\n    runtime_payload.publish(BUNDLE, BASE)\n")
        with self.assertRaisesRegex(payload.Refused, 'runtime_publish_before_services_required'):
            payload.validate_bindings(self.root)

    @unittest.skipUnless(PHP and (HERE.parents[1] / 'vendor/autoload.php').is_file(), 'PHP and locked Laravel vendor required')
    def test_real_laravel_warning_missing_active_helper_and_fixed_published_runtime(self):
        payload.publish(self.bundle, self.target)
        helper = self.target / 'source_fixture_evidence.php'
        content = helper.read_bytes()
        helper.unlink()
        script = HERE.parents[1] / 'tests/Fixtures/MachineAcceptance/source_runtime_probe.php'
        for mode in ['normal', 'storage-failure']:
            result = subprocess.run([PHP, str(script), str(self.target), mode], capture_output=True, timeout=20)
            self.assertEqual(result.returncode, 0, result.stderr[:200])
            value = json.loads(result.stdout)
            self.assertFalse(value['ok'])
            diagnostic = value['cli_diagnostic']
            self.assertEqual(diagnostic['error_class'], 'ErrorException')
            self.assertEqual(diagnostic['file'], 'source_fixture.php')
            self.assertGreater(diagnostic['line'], 0)
            self.assertEqual(diagnostic['severity'], 'warning')
            self.assertEqual(value['cli_diagnostic_recorded'], mode == 'normal')
            for secret in ['private-sentinel-never-export', str(self.target), 'Failed to open stream']:
                self.assertNotIn(secret, result.stdout.decode())
        helper.write_bytes(content)
        self.assertTrue(payload.verify(self.bundle, self.target)['ok'])
        result = subprocess.run([PHP, str(script), str(self.target), 'normal'], capture_output=True, timeout=20)
        self.assertEqual(result.returncode, 0, result.stderr[:200])
        self.assertEqual(json.loads(result.stdout), {'ok': True, 'evidence_valid': True})
        result = subprocess.run([PHP, str(script), str(self.target), 'invalid-evidence'], capture_output=True, timeout=20)
        self.assertEqual(result.returncode, 0, result.stderr[:200])
        value = json.loads(result.stdout)
        self.assertFalse(value['ok'])
        self.assertEqual(value['reason'], 'source_fixture_evidence_invalid_runtime_nodes')
        self.assertEqual(value['source_fixture_evidence']['failed_fields'], ['runtime_nodes'])


if __name__ == '__main__':
    unittest.main()
