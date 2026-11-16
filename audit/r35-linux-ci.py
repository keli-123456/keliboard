"""Temporary-branch-only Linux rehearsal. No production access or publishing."""
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile

repo = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(repo / 'tools/machine-acceptance'))
from member_runtime_bundle import unpack, NODE_SHA

assert sys.platform == 'linux'
assert subprocess.run(['ip', 'route'], capture_output=True, check=True).stdout == b''
assert subprocess.run(['ip', '-6', 'route'], capture_output=True, check=True).stdout == b''
artifacts = repo / 'artifacts'; artifacts.mkdir(exist_ok=True)
archive = repo / 'ci-assets/r35-runtime.tar.gz'
expected = (repo / 'ci-assets/runtime.sha256').read_text().strip()
assert hashlib.sha256(archive.read_bytes()).hexdigest() == expected

with tempfile.TemporaryDirectory(prefix='r35-linux-') as tmp:
    root = Path(tmp) / 'runtime'
    manifest = unpack(archive, root, expected)
    node = root / 'node/kelinode'
    assert hashlib.sha256(node.read_bytes()).hexdigest() == NODE_SHA
    result = subprocess.run([str(node), 'build-info'], capture_output=True, timeout=20, check=True)
    build = json.loads(result.stdout)
    assert build['version'] == '0.1.387' and build['features']['embedded_core'] is True
    (artifacts / 'node-build-info.json').write_text(json.dumps(build, sort_keys=True))
    app = root / 'app'; tools = app / 'tools/machine-acceptance'
    subprocess.run(['php', '-r', "require $argv[1].'/vendor/autoload.php'; "
        "foreach (['App\\Services\\ServerMachine\\Cloud\\MachineFailoverNativeBridge',"
        "'App\\Services\\ServerMachine\\Cloud\\MachineCandidateCleanupClient',"
        "'App\\Services\\ServerMachine\\Cloud\\MachineProvisionService'] as $c) {"
        "if (!class_exists($c)) exit(2); }", str(app)], check=True, timeout=30)
    for file in (root / 'runtime').glob('*.php'):
        subprocess.run(['php', '-l', str(file)], check=True, timeout=10)
    tests = ['test_member_round', 'test_member_runtime_bundle', 'test_member_transport',
             'test_member_bundle', 'test_member_linux', 'test_application_bundle', 'test_driver_journal']
    subprocess.run([sys.executable, '-m', 'unittest', *tests, '-v'], cwd=tools, check=True, timeout=240)
    subprocess.run(['php', '-d', 'memory_limit=768M', '-d',
        'disable_functions=curl_exec,fsockopen,pfsockopen,stream_socket_client',
        'vendor/phpunit/phpunit/phpunit', 'tests/Unit/Services/MachineFailoverNativeBridgeTest.php',
        '--log-junit', str(artifacts / 'native-bridge.xml')], cwd=app, check=True, timeout=900)
    (artifacts / 'runtime-summary.json').write_text(json.dumps({
        'runtime_archive_sha256': expected, 'node_sha256': NODE_SHA,
        'composer_lock_sha256': manifest['composer_lock_sha256'],
        'network_namespace': True, 'external_routes': False,
        'cloud_ssh_dns': 'simulated', 'execution_authorized': False,
        'live_ready': False, 'github_node_build': False,
        'temporary_workflow_only': True, 'status': 'passed'}, indent=2))
