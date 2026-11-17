"""Temporary CI-only namespace launcher. All credentials and transports are fake."""
import hashlib
import json
import os
from pathlib import Path
import pwd
import shutil
import subprocess
import sys
import xml.etree.ElementTree as ET

REPO = Path(__file__).resolve().parents[1]
ROOT = Path('/opt/keli-isolated')
ART = REPO / 'artifacts'
TOOLS = REPO / 'tools/machine-acceptance'
SUMMARY = dict(kind='r35_execution_http_mysql_redis_linux_simulation', status='running',
    cloud_ssh_dns='simulated', live_ready=False, live_grant_issued=False,
    namespace_synthetic_grant_only=True, external_routes=False, production_changed=False,
    automatic_switches_changed=False, queue_configuration_changed=False,
    cloud_instances_created=0, tanzhen_changed=False, r34_executed=False, r35_executed=False)


def cmd(args, **kwargs):
    return subprocess.run(args, check=True, timeout=kwargs.pop('timeout', 30), **kwargs)


def python_suite(modules, filename, root=False):
    script = '''
import json,pathlib,sys,unittest
suite=unittest.defaultTestLoader.loadTestsFromNames(sys.argv[2:])
r=unittest.TextTestRunner(verbosity=2).run(suite)
pathlib.Path(sys.argv[1]).write_text(json.dumps({'tests':r.testsRun,'failures':len(r.failures),
  'errors':len(r.errors),'skipped':len(r.skipped),'skip_reasons':[reason for _,reason in r.skipped]},indent=2))
sys.exit(not r.wasSuccessful())
'''
    env = ['PATH=/usr/local/bin:/usr/bin:/bin', 'HOME=/home/runner', 'LANG=C.UTF-8',
           'PYTHONDONTWRITEBYTECODE=1', 'GIT_CONFIG_NOSYSTEM=1', 'GIT_CONFIG_GLOBAL=/dev/null',
           'GIT_LFS_SKIP_SMUDGE=1']
    prefix = ['/usr/bin/env', '-i', *env, 'KELI_MEMBER_CLI_ENTRY=1'] if root else [
        '/usr/bin/setpriv', '--reuid=' + str(runner.pw_uid), '--regid=' + str(runner.pw_gid), '--clear-groups',
        '--inh-caps=-all', '--ambient-caps=-all', '--bounding-set=-all', '--no-new-privs',
        '/usr/bin/env', '-i', *env]
    cmd([*prefix, 'python3', '-B', '-c', script, str(ART / filename), *modules], cwd=TOOLS, timeout=400)
    return json.loads((ART / filename).read_text())


assert sys.platform == 'linux' and os.geteuid() == 0
assert not ROOT.exists() and not ROOT.is_symlink(), 'fixed fixture path must be unused'
ART.mkdir(exist_ok=True)
runner = pwd.getpwnam('runner')
os.chown(ART, runner.pw_uid, runner.pw_gid)
cmd(['ip', 'link', 'set', 'lo', 'up'])
assert cmd(['ip', 'route'], capture_output=True).stdout.strip() == b''
assert cmd(['ip', '-6', 'route'], capture_output=True).stdout.strip() == b''
SUMMARY['tested_commit'] = cmd(['git', '-c', 'safe.directory=' + str(REPO), 'rev-parse', 'HEAD'],
    cwd=REPO, capture_output=True, text=True).stdout.strip()
SUMMARY['composer_lock_sha256'] = hashlib.sha256((REPO / 'composer.lock').read_bytes()).hexdigest()
try:
    os.umask(0o077)
    ROOT.mkdir(mode=0o700)
    (ROOT / '.r35-services-fixture').write_text(SUMMARY['tested_commit'])
    app = ROOT / 'app'; app.mkdir(mode=0o700)
    tracked = cmd(['git', '-c', 'safe.directory=' + str(REPO), 'ls-files', '-z'],
        cwd=REPO, capture_output=True).stdout.decode().split('\0')
    for name in filter(None, tracked):
        if name.startswith(('.github/', 'audit/')): continue
        source = REPO / name
        assert source.is_file() and not source.is_symlink()
        dest = app / name; dest.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(source, dest); dest.chmod(0o644)
    shutil.copytree(REPO / 'vendor', app / 'vendor', symlinks=False)
    runtime = ROOT / 'runtime'; runtime.mkdir(mode=0o700)
    sys.path.insert(0, str(TOOLS))
    from member_bundle import RUNTIME
    for name in RUNTIME:
        raw = (TOOLS / name).read_bytes()
        assert b'\r' not in raw, 'runtime must be LF'
        (runtime / name).write_bytes(raw); (runtime / name).chmod(0o644)
    for name in ('bootstrap/cache', 'storage/logs', 'storage/framework/cache/data',
                 'storage/framework/views', 'storage/framework/sessions'):
        (app / name).mkdir(parents=True, exist_ok=True)
    for directory in (ROOT, app, runtime):
        assert directory.stat().st_uid == 0 and directory.stat().st_mode & 0o022 == 0
    SUMMARY['fixed_entry'] = python_suite(['test_member_cli_entry_linux'], 'fixed-entry.json', root=True)
    assert SUMMARY['fixed_entry']['tests'] == 8 and SUMMARY['fixed_entry']['skipped'] == 0
    SUMMARY['installation_root'] = python_suite(['test_member_installation_linux.InstallationServiceUserTests'],
                                               'installation-root.json', root=True)
    assert SUMMARY['installation_root']['skipped'] == 0
    modules = ['test_member_execution.ExecutionTests', 'test_member_execution.ExecutionLinuxTests',
        'test_member_installation_linux.InstallationTests',
        'test_member_assembly_linux', 'test_member_assembly', 'test_member_initialization',
        'test_member_cli', 'test_member_bound_linux', 'test_member_transport', 'test_member_round',
        'test_member_bundle', 'test_member_runtime_bundle', 'test_member_linux',
        'test_driver_journal.JournalTests', 'test_application_bundle', 'test_staging']
    SUMMARY['python'] = python_suite(modules, 'python.json')
    assert SUMMARY['python']['skipped'] == 0
    cmd(['/usr/bin/setpriv', '--reuid=' + str(runner.pw_uid), '--regid=' + str(runner.pw_gid), '--clear-groups',
         '--inh-caps=-all', '--ambient-caps=-all', '--bounding-set=-all', '--no-new-privs',
         '/usr/bin/env', '-i', 'PATH=/usr/local/bin:/usr/bin:/bin', 'HOME=/home/runner', 'LANG=C.UTF-8',
         'php', '-d', 'memory_limit=768M', '-d',
         'disable_functions=curl_exec,fsockopen,pfsockopen,stream_socket_client',
         'vendor/phpunit/phpunit/phpunit', 'tests/Unit/Services/MachineMemberControlCreationTest.php',
         'tests/Unit/Services/MachineMemberProviderBindingsTest.php',
         'tests/Unit/Services/MachineFailoverNativeBridgeTest.php',
         '--filter', 'test_member_|MachineMemberControlCreationTest|MachineMemberProviderBindingsTest',
         '--log-junit', str(ART / 'php.xml')], cwd=REPO, timeout=900)
    xml = ET.parse(ART / 'php.xml').getroot()
    cases = list(xml.iter('testcase'))
    SUMMARY['php'] = dict(tests=len(cases), assertions=sum(int(c.get('assertions', 0)) for c in cases),
        failures=len(list(xml.iter('failure'))), errors=len(list(xml.iter('error'))), skipped=len(list(xml.iter('skipped'))))
    assert SUMMARY['php']['tests'] == 68 and SUMMARY['php']['skipped'] == 0
    cmd(['/usr/bin/setpriv', '--reuid=' + str(runner.pw_uid), '--regid=' + str(runner.pw_gid), '--clear-groups',
         '--inh-caps=-all', '--ambient-caps=-all', '--bounding-set=-all', '--no-new-privs',
         '/usr/bin/env', '-i', 'PATH=/usr/local/bin:/usr/bin:/bin', 'HOME=/home/runner', 'LANG=C.UTF-8',
         'GITHUB_ACTIONS=true', 'KELI_NATIVE_ACCEPTANCE=1', 'KELI_NATIVE_HOST=127.0.0.1',
         'KELI_NATIVE_DATABASE=keli_acceptance_native_failover', 'KELI_NATIVE_USER=keli_native_fixture',
         'KELI_NATIVE_PASSWORD=ephemeral-native-ci-only', 'KELI_NATIVE_ISOLATION=REPEATABLE READ',
         'KELI_NATIVE_MYSQL_PORT=13306', 'KELI_NATIVE_REDIS_PORT=16379',
         'php', '-d', 'memory_limit=768M', 'vendor/phpunit/phpunit/phpunit', '-c', 'phpunit-native-failover.xml',
         'tests/Integration/ServerMachine/MemberHttpServicesTest.php', '--log-junit', str(ART / 'http-services.xml')],
         cwd=REPO, timeout=240)
    xml = ET.parse(ART / 'http-services.xml').getroot()
    SUMMARY['http_services'] = dict(tests=len(list(xml.iter('testcase'))),
        failures=len(list(xml.iter('failure'))), errors=len(list(xml.iter('error'))), skipped=len(list(xml.iter('skipped'))))
    assert SUMMARY['http_services']['tests'] == 5 and SUMMARY['http_services']['skipped'] == 0
    SUMMARY['status'] = 'passed'
except BaseException as error:
    SUMMARY['status'] = 'failed'
    SUMMARY['failure_class'] = type(error).__name__
    raise
finally:
    assert ROOT.resolve() == Path('/opt/keli-isolated')
    if ROOT.exists(): shutil.rmtree(ROOT)
    SUMMARY['fixed_fixture_removed'] = not ROOT.exists()
    SUMMARY['external_routes_after'] = bool(cmd(['ip', 'route'], capture_output=True).stdout.strip())
    SUMMARY['external_ipv6_routes_after'] = bool(cmd(['ip', '-6', 'route'], capture_output=True).stdout.strip())
    (ART / 'services-linux-summary.json').write_text(json.dumps(SUMMARY, indent=2))
    for name in ('services-linux-summary.json', 'fixed-entry.json', 'python.json', 'installation-root.json'):
        path = ART / name
        if path.is_file(): path.chmod(0o644)
