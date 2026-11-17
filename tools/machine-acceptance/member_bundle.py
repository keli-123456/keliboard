"""Freeze an unarmed R35 adapter package from a Git baseline and explicit local files.

This is a preparation artifact, NOT a live round authorization, node release,
controller image or a replacement for future bootstrap/credential preflight.
"""
import argparse
import gzip
import hashlib
import io
import json
from pathlib import Path
import tarfile
import tempfile

import application_bundle
import staging

RUNTIME = ('member_retry_driver.php', 'member_source_registry.php', 'member_controller_bridge.php',
           'member_transport_entry.php', 'member_transport.py', 'driver_journal.py', 'member_bundle.py',
           'member_cli_authority.php', 'member_round_bridge.php', 'member_native_controller.php', 'member_cli.py', 'member_evidence.py',
           'member_auxiliary.py', 'member_round.py', 'member_runtime_bundle.py', 'application_bundle.py', 'staging.py', 'worker.py',
           'member_initialization.py', 'member_auxiliary_bindings.py', 'member_source_initialization.php',
           'member_provider_bindings.php', 'aliyun_cleanup.php', 'member_control_creation.php',
           'member_setup_bindings.py', 'member_assembly.py', 'member_execution.py', 'member_remote_setup.py', 'member_installation.py',
           'member_environment.py', 'member_application.py', 'member_application_bootstrap.php', 'member_private_cleanup.py',
           'member_dns_initialization.php', 'member_native_dns_receipt.php', 'member_source_configuration.php',
           'member_prerequisite_import.php', 'member_import_entry.php', 'member_bootstrap_controller.php',
           'member_setup_authority.php', 'member_candidate_callbacks.php', 'member_native_grant.php', 'member_setup_dns.php', 'member_callback_entry.php', 'member_setup_controller.php', 'member_setup_entry.php')
TESTS = ('tools/machine-acceptance/test_member_transport.py', 'tools/machine-acceptance/test_member_bundle.py',
         'tests/Support/TestsMemberTransport.php', 'tools/machine-acceptance/test_member_round.py',
         'tools/machine-acceptance/test_member_runtime_bundle.py', 'tools/machine-acceptance/test_member_linux.py',
         'tests/Support/TestsMemberCli.php', 'tools/machine-acceptance/test_member_cli.py',
         'tests/Support/TestsMemberInitialization.php', 'tools/machine-acceptance/test_member_initialization.py',
         'tests/Unit/Services/MachineMemberProviderBindingsTest.php',
         'tests/Unit/Services/MachineMemberControlCreationTest.php',
         'tools/machine-acceptance/test_member_assembly.py', 'tools/machine-acceptance/test_member_assembly_linux.py',
         'tools/machine-acceptance/test_member_execution.py',
         'tools/machine-acceptance/test_member_remote_setup.py',
         'tools/machine-acceptance/test_member_installation_linux.py',
         'tools/machine-acceptance/test_member_private_cleanup.py',
         'tools/machine-acceptance/test_member_environment.py',
         'tests/Unit/Services/MachineMemberDnsInitializationTest.php',
         'tests/Unit/Services/MachineMemberSourceConfigurationTest.php',
         'tests/Unit/Services/MachineMemberPrerequisiteImportTest.php',
         'tests/Unit/Services/MachineMemberBootstrapControllerTest.php',
         'tests/Unit/Services/MachineMemberSetupAuthorityTest.php',
         'tests/Unit/Services/MachineProviderGroupsTest.php')
FILES = tuple('tools/machine-acceptance/' + name for name in RUNTIME) + TESTS + (
    'tests/Support/TestsMemberRetryDriver.php',
    'app/Services/ServerMachine/Cloud/MachineNativeDnsAdmission.php',
    'app/Services/ServerMachine/Dns/MachineDnsRecordSetExecution.php',
    'app/Services/ServerMachine/Cloud/MachineCloudCatalog.php',
    'app/Services/ServerMachine/Cloud/MachineFailoverPolicyService.php')


def require(value, reason):
    if not value:
        raise ValueError(reason)


def freeze(repo, commit, destination, run_id):
    import re
    require(re.fullmatch(r'keli-accept-[a-z0-9-]{8,80}', run_id), 'bundle_run_invalid')
    repo, destination = Path(repo).resolve(strict=True), Path(destination).absolute()
    directory, identity = application_bundle.output_directory(destination.parent)
    require(not destination.exists(), 'bundle_output_exists')
    overlay, evidence = {}, {}
    for name in FILES:
        path = repo / name
        require(path.resolve(strict=True) == path.absolute(), 'bundle_overlay_link_refused')
        _, raw, _ = staging.read_file(path, 262144, collect=True)
        require(b'\0' not in raw and not raw.startswith(b'\xef\xbb\xbf'), 'bundle_overlay_encoding')
        raw.decode('utf-8')
        raw = raw.replace(b'\r\n', b'\n')
        require(b'\r' not in raw, 'bundle_overlay_cr_refused')
        overlay[name] = raw
        evidence[name] = {'sha256': hashlib.sha256(raw).hexdigest(), 'bytes': len(raw), 'lf_only': True}
    manifest = {'schema': 1, 'run_id': run_id, 'base_commit': commit, 'artifact_kind': 'unarmed_r35_adapter_application',
                'execution_authorized': False, 'live_ready': False, 'node_release_included': False,
                'credentials_included': False, 'automatic_switches_changed': False, 'files': evidence,
                'remaining_live_gates': ['isolated_bootstrap_transport_integration',
                    'node_binary_and_vendor_provenance', 'isolated_runtime_authority_and_environment',
                    'minimal_readonly_preflight', 'new_execution_authorization']}
    # Include the new trait in the committed test entry without mixing other worktree edits.
    entry = 'tests/Unit/Services/MachineFailoverNativeBridgeTest.php'
    original = application_bundle.git_read(repo, ['cat-file', 'blob', commit + ':' + entry])
    needle = b'    use \\Tests\\Support\\TestsMemberRetryDriver;\n'
    require(original.count(needle) == 1, 'bundle_test_entry_changed')
    transport_trait = b'    use \\Tests\\Support\\TestsMemberTransport;\n'
    require(original.count(transport_trait) <= 1, 'bundle_test_entry_changed')
    overlay[entry] = original if transport_trait in original else original.replace(needle, needle + transport_trait)
    cli_trait = b'    use \\Tests\\Support\\TestsMemberCli;\n'
    require(overlay[entry].count(cli_trait) <= 1, 'bundle_test_entry_changed')
    if cli_trait not in overlay[entry]:
        overlay[entry] = overlay[entry].replace(transport_trait, transport_trait + cli_trait)
    initialization_trait = b'    use \\Tests\\Support\\TestsMemberInitialization;\n'
    require(overlay[entry].count(initialization_trait) <= 1, 'bundle_test_entry_changed')
    if initialization_trait not in overlay[entry]:
        overlay[entry] = overlay[entry].replace(cli_trait, cli_trait + initialization_trait)
    manifest['test_entry_sha256'] = hashlib.sha256(overlay[entry]).hexdigest()
    manifest_raw = (json.dumps(manifest, indent=2, sort_keys=True) + '\n').encode()
    with tempfile.TemporaryDirectory(prefix='member-freeze-', dir=directory) as temporary:
        temporary = Path(temporary)
        base = temporary / 'application.tar.gz'
        application_bundle.build_archive(repo, commit, base)
        output = temporary / 'r35.tar.gz'
        with output.open('wb') as stream, gzip.GzipFile(filename='', fileobj=stream, mode='wb', mtime=0) as gz:
            with tarfile.open(fileobj=gz, mode='w') as archive, tarfile.open(base, 'r:gz') as source:
                for item in source:
                    if item.name in overlay:
                        continue
                    archive.addfile(item, source.extractfile(item) if item.isfile() else None)
                for name, raw in sorted(overlay.items()):
                    info = tarfile.TarInfo(name); info.mode = 0o644; info.size = len(raw)
                    archive.addfile(info, io.BytesIO(raw))
                info = tarfile.TarInfo('r35-adapter-manifest.json'); info.mode = 0o644; info.size = len(manifest_raw)
                archive.addfile(info, io.BytesIO(manifest_raw))
        report = application_bundle.inspect_archive(output)
        staging.unchanged_directory(directory, identity)
        require(not destination.exists(), 'bundle_output_exists')
        # Exclusive creation prevents a preparation rerun overwriting a frozen artifact.
        import os
        os.link(output, destination)
    return dict(manifest, archive=report['archive'], shell_count=report['shell_count'])


def verify(path):
    application_bundle.inspect_archive(path)
    with tarfile.open(path, 'r:gz') as archive:
        manifest = json.load(archive.extractfile('r35-adapter-manifest.json'))
        require(manifest['execution_authorized'] is False and manifest['live_ready'] is False, 'bundle_armed')
        require(set(manifest['files']) == set(FILES), 'bundle_manifest_incomplete')
        for name, evidence in manifest['files'].items():
            raw = archive.extractfile(name).read()
            require(hashlib.sha256(raw).hexdigest() == evidence['sha256'] and len(raw) == evidence['bytes']
                    and b'\r' not in raw, 'bundle_file_changed')
        entry = archive.extractfile('tests/Unit/Services/MachineFailoverNativeBridgeTest.php').read()
        require(hashlib.sha256(entry).hexdigest() == manifest['test_entry_sha256'], 'bundle_test_entry_changed')
        require(not any(Path(name).name in ('authorization.json', 'member-runtime-scope.json',
                    'member-setup-grant.json', 'member-setup-stop.json', 'member-setup-issuance.json') for name in archive.getnames()),
                'bundle_authority_refused')
    return manifest


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--repo', required=True); parser.add_argument('--commit', required=True)
    parser.add_argument('--output', required=True); parser.add_argument('--run-id', required=True)
    args = parser.parse_args()
    result = freeze(args.repo, args.commit, args.output, args.run_id)
    verify(args.output)
    print(json.dumps(result, sort_keys=True))
