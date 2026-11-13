"""Kernel regression runner, refusing execution outside a fresh unshare PID/network namespace."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import signal
import subprocess
import sys
import tempfile
import time
import traceback

HERE = Path(__file__).resolve().parent
TABLE = 'keli_iputils_regression'
COUNTER = 'echo_requests'
ALLOWED_ENV = {'PATH', 'LANG', 'LC_ALL', 'PYTHONDONTWRITEBYTECODE', 'KELI_IPUTILS_NAMESPACE', 'HOME'}


def require(value, reason):
    if not value:
        raise RuntimeError(reason)


def command(args, timeout=5, body=None):
    value = subprocess.run(args, input=body, capture_output=True, timeout=timeout,
                           env={'PATH': '/usr/sbin:/usr/bin:/sbin:/bin', 'LANG': 'C', 'LC_ALL': 'C'})
    require(value.returncode == 0, 'namespace_tool_failed')
    return value.stdout


def topology(parent):
    require(sys.platform == 'linux' and os.geteuid() == 0 and os.getpid() == 1, 'fresh_unshare_root_pid_one_required')
    require(os.environ.get('KELI_IPUTILS_NAMESPACE') == '1' and set(os.environ) <= ALLOWED_ENV, 'clean_namespace_environment_required')
    require(re.fullmatch(r'net:\[[0-9]+\]', parent), 'parent_namespace_required')
    own = os.readlink('/proc/self/ns/net')
    require(own != parent and own == os.readlink('/proc/1/ns/net'), 'independent_network_namespace_required')
    require({p.name for p in Path('/sys/class/net').iterdir()} == {'lo'}, 'loopback_only_required')
    require(json.loads(command(['/usr/sbin/ip', '-j', 'route', 'show', 'table', 'all'])) == [], 'fresh_empty_routes_required')
    ruleset = json.loads(command(['/usr/sbin/nft', '-j', 'list', 'ruleset']))
    require(isinstance(ruleset, dict) and set(ruleset) <= {'nftables'} and isinstance(ruleset.get('nftables', []), list)
            and all(set(row) == {'metainfo'} for row in ruleset.get('nftables', [])), 'fresh_empty_firewall_required')
    return own


def counter():
    value = json.loads(command(['/usr/sbin/nft', '-j', 'list', 'counter', 'inet', TABLE, COUNTER]))
    rows = [r['counter'] for r in value['nftables'] if 'counter' in r]
    require(len(rows) == 1 and rows[0]['name'] == COUNTER, 'dedicated_counter_required')
    return rows[0]['packets']


def rule(mode):
    value = json.loads(command(['/usr/sbin/nft', '-j', '-a', 'list', 'chain', 'inet', TABLE, 'output']))
    rows = [r['rule'] for r in value['nftables'] if 'rule' in r]
    require(len(rows) <= 1, 'dedicated_rule_changed')
    batch = ''
    if rows:
        require(type(rows[0]['handle']) is int, 'rule_handle_required')
        batch = 'delete rule inet ' + TABLE + ' output handle ' + str(rows[0]['handle']) + '\n'
    batch += 'add rule inet ' + TABLE + ' output ip daddr {127.0.0.2,127.0.0.3,127.0.0.4,127.0.0.5} icmp type echo-request counter name ' + COUNTER + (' drop\n' if mode == 'drop' else ' accept\n')
    command(['/usr/sbin/nft', '-f', '-'], body=batch.encode())


def run_child(args, uid, gid, home, mode, timeout):
    env = {'PATH': '/usr/bin:/bin', 'LANG': 'C', 'LC_ALL': 'C', 'HOME': str(home),
           'PYTHONDONTWRITEBYTECODE': '1', 'KELI_IPUTILS_ISOLATED': '1', 'KELI_IPUTILS_MODE': mode}
    process = subprocess.Popen(['/usr/bin/setpriv', '--reuid', str(uid), '--regid', str(gid), '--clear-groups',
                                '--inh-caps=-all', '--ambient-caps=-all', '--bounding-set=-all', '--no-new-privs',
                                *args], cwd=home, env=env, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
                               start_new_session=True)
    try:
        raw, _ = process.communicate(timeout=timeout)
        require(len(raw) <= 262144, 'test_output_oversized')
        return process.returncode, raw
    finally:
        if process.poll() is None:
            os.killpg(process.pid, signal.SIGKILL)
        process.wait(timeout=2)
        if process.stdout is not None:
            process.stdout.close()


def no_remaining_children():
    # In this private PID namespace only our PID 1 is permitted to remain.
    return [int(p.name) for p in Path('/proc').iterdir() if p.name.isdigit() and p.name != '1']


def run(options):
    own = topology(options.parent_netns)
    require(options.uid > 0 and options.gid > 0, 'non_root_test_identity_required')
    version = command(['/usr/bin/ping', '-V']).decode(errors='replace')
    require('iputils' in version, 'real_iputils_required')
    value = {'schema': 1, 'ok': False, 'isolation': {'independent_network_namespace': True, 'private_pid_namespace': True,
             'only_loopback': True, 'external_routes': 0, 'child_non_root': True, 'child_capabilities': 0, 'no_new_privileges': True},
             'iputils_version_sha256': hashlib.sha256(version.encode()).hexdigest(), 'cases': []}
    root = Path(tempfile.mkdtemp(prefix='keli-iputils-namespace-'))
    root.chmod(0o755)
    table_created = False
    try:
        command(['/usr/sbin/ip', 'link', 'set', 'lo', 'up'])
        command(['/usr/sbin/nft', '-f', '-'], body=('add table inet ' + TABLE + '\nadd counter inet ' + TABLE + ' ' + COUNTER
            + '\nadd chain inet ' + TABLE + ' output { type filter hook output priority -50; policy accept; }\n').encode())
        table_created = True
        cases = [('echo', 'echo'), ('no_reply', 'drop'), ('four_no_reply_targets', 'drop'),
                 ('legacy_deadline_reference', 'drop'), ('forced_timeout_reaps_real_ping', 'drop'),
                 ('permission_denied_is_not_packet_loss', 'permission')]
        for name, mode in cases:
            value['current_case'] = name
            Path('/proc/sys/net/ipv4/ping_group_range').write_text(str(options.gid) + ' ' + str(options.gid) if mode != 'permission' else '65534 65534')
            require(options.gid != 65534, 'test_gid_conflicts_with_denial_group')
            rule('drop' if mode == 'drop' else 'echo')
            home = root / name
            home.mkdir(mode=0o700)
            os.chown(home, options.uid, options.gid)
            before = counter()
            code, raw = run_child(['/usr/bin/python3', '-B', str(HERE / 'test_entry_probe_iputils.py'), name],
                                  options.uid, options.gid, home, mode, 16)
            observed = json.loads(raw)
            delta = counter() - before
            observed.update(kernel_echo_requests=delta, packet_counter_matches=delta == observed.get('expected_packets'),
                            no_remaining_children=not no_remaining_children())
            observed['ok'] = code == 0 and observed.get('ok') is True and observed['packet_counter_matches'] and observed['no_remaining_children']
            value['cases'].append(observed)
        if options.rust_test:
            binary = Path(options.rust_test).resolve()
            require(binary.is_file() and os.access(binary, os.X_OK) and binary.name.startswith('keli_entry_probe_isolated_tests-'), 'scoped_rust_test_binary_required')
            value['rust_binary_sha256'] = hashlib.sha256(binary.read_bytes()).hexdigest()
            for mode in ['echo', 'drop']:
                value['current_case'] = 'rust_' + mode
                Path('/proc/sys/net/ipv4/ping_group_range').write_text(str(options.gid) + ' ' + str(options.gid))
                rule(mode)
                home = root / ('rust-' + mode)
                home.mkdir(mode=0o700)
                os.chown(home, options.uid, options.gid)
                before = counter()
                code, raw = run_child([str(binary), '--ignored', '--exact', 'entry_probe::tests::real_iputils_in_isolated_namespace',
                                      '--test-threads=1'], options.uid, options.gid, home, mode, 8)
                delta = counter() - before
                value['cases'].append({'case': 'rust_' + mode, 'ok': code == 0 and delta == 1 and not no_remaining_children(),
                                       'kernel_echo_requests': delta, 'expected_packets': 1, 'exit_success': code == 0,
                                       'no_remaining_children': not no_remaining_children(), 'raw_output_retained': False})
        value['ok'] = bool(value['cases']) and all(c['ok'] for c in value['cases'])
    finally:
        if sys.exc_info()[0] is not None:
            value['error_class'] = sys.exc_info()[0].__name__
            value['error_frames'] = [{'file': Path(f.filename).name, 'line': f.lineno} for f in traceback.extract_tb(sys.exc_info()[2])][-6:]
        value['cleanup'] = {'temporary_directory_removed': False, 'owned_firewall_removed': False,
                            'no_remaining_children': not no_remaining_children()}
        if table_created:
            command(['/usr/sbin/nft', 'delete', 'table', 'inet', TABLE])
            check = json.loads(command(['/usr/sbin/nft', '-j', 'list', 'ruleset']))
            value['cleanup']['owned_firewall_removed'] = not any(r.get('table', {}).get('name') == TABLE for r in check.get('nftables', []))
        shutil.rmtree(root)
        value['cleanup']['temporary_directory_removed'] = not root.exists()
        value['ok'] = value['ok'] and all(value['cleanup'].values())
        value['namespace_ref_sha256'] = hashlib.sha256(own.encode()).hexdigest()
        output = Path(options.output).resolve()
        with output.open('x', encoding='utf-8') as stream:
            json.dump(value, stream, indent=2)
            stream.write('\n')
    return value


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--uid', type=int, required=True)
    parser.add_argument('--gid', type=int, required=True)
    parser.add_argument('--parent-netns', required=True)
    parser.add_argument('--output', required=True)
    parser.add_argument('--rust-test')
    options = parser.parse_args()
    try:
        result = run(options)
        print(json.dumps({'ok': result['ok'], 'cases': len(result['cases']), 'cleanup': result['cleanup']}))
        return 0 if result['ok'] else 1
    except Exception as error:
        failure = {'ok': False, 'error_class': type(error).__name__, 'raw_output_omitted': True,
                   'error_frames': [{'file': Path(f.filename).name, 'line': f.lineno} for f in traceback.extract_tb(error.__traceback__)][-6:]}
        if isinstance(error, RuntimeError) and re.fullmatch('[a-z_]{1,80}', str(error)):
            failure['reason'] = str(error)
        output = Path(options.output).resolve()
        if os.getpid() == 1 and os.environ.get('KELI_IPUTILS_NAMESPACE') == '1' and not output.exists():
            with output.open('x', encoding='utf-8') as stream:
                json.dump(failure, stream, indent=2)
        print(json.dumps(failure))
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
