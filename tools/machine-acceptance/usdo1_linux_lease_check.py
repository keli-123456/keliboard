"""Linux-only ICMP lease check in a disposable network namespace, no external interfaces."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import time

from driver_journal import DriverJournal
from usdo1_fault_lease import Usdo1FaultLease


TABLE = 'keli_usdo1_fixture'
TARGETS = ['192.0.1.30', '192.0.1.31', '192.0.1.32', '192.0.1.33']
CONTROL = '192.0.1.34'
SOURCE = {'run_id': 'keli-accept-usdo1-linux-fixture', 'role': 'new-source', 'instance_id': '301',
          'installation_sha256': 'a' * 64, 'ipv4': '192.0.1.36'}


def check(value, reason):
    if not value:
        raise RuntimeError(reason)


def run(*args, text=None, timeout=6):
    return subprocess.run(args, input=text, text=True, capture_output=True, timeout=timeout, check=True).stdout


def ping(ip):
    result = subprocess.run(['ping', '-4', '-n', '-q', '-c', '1', '-W', '1', ip], capture_output=True, timeout=3)
    check(result.returncode in (0, 1), 'lease_fixture_ping_backend_failed')
    return result.returncode == 0


def namespace():
    return os.readlink('/proc/self/ns/net')


def isolated(parent):
    check(namespace() != parent, 'new_network_namespace_required')
    links = json.loads(run('ip', '-j', 'link', 'show'))
    check([row['ifname'] for row in links] == ['lo'], 'loopback_only_required')
    check(not json.loads(run('ip', '-j', 'route', 'show', 'default')), 'default_route_forbidden')


def controller(parent, directory):
    isolated(parent)
    boot = Path('/proc/sys/kernel/random/boot_id').read_text().strip()
    source = SOURCE | {'boot_id': boot}
    state = None

    def inspect(pin):
        if state is None:
            tables = json.loads(run('nft', '-j', 'list', 'tables'))['nftables']
            check(not any(row.get('table', {}).get('name') == TABLE for row in tables), 'unexpected_lease_table')
            return {'source': pin, 'absent': True}
        rows = json.loads(run('nft', '-j', 'list', 'table', 'ip', TABLE))['nftables']
        entry = [row['set'] for row in rows if 'set' in row]
        check(len(entry) == 1 and entry[0]['name'] == 'targets' and 'timeout' in entry[0]['flags']
              and len(entry[0]['elem']) == 4, 'kernel_expiry_unconfirmed')
        check(all(0 < item['elem']['expires'] <= 90000 for item in entry[0]['elem']), 'kernel_expiry_unconfirmed')
        return state

    def apply(operation, intent):
        nonlocal state
        check(operation == 'arm' and state is None and intent['source'] == source and intent['targets'] == TARGETS, 'fixture_action_forbidden')
        seconds = intent['lease_until'] - int(time.monotonic())
        check(0 < seconds <= 90, 'fixture_deadline_invalid')
        elements = ', '.join(f'{ip} timeout {seconds}s' for ip in TARGETS)
        run('nft', '-f', '-', text=f'''add table ip {TABLE}
add set ip {TABLE} targets {{ type ipv4_addr; flags timeout; }}
add chain ip {TABLE} output {{ type filter hook output priority -50; policy accept; }}
add rule ip {TABLE} output ip daddr @targets icmp type echo-request drop
add element ip {TABLE} targets {{ {elements} }}
''')
        state = {key: intent[key] for key in ('source', 'targets', 'hard_until', 'lease_until')} | {'scope_verified': True}
        return True

    lease = Usdo1FaultLease(journal=DriverJournal(directory, source['run_id']), source=source, protected_ids=('17',),
                           targets=TARGETS, cutoff=int(time.monotonic()) + 900, clock=lambda: int(time.monotonic()),
                           inspect=inspect, apply=apply, authorize=lambda _op, pin, _context: pin == source)
    results = {ip: 'reachable' if ping(ip) else 'unreachable' for ip in TARGETS}
    lease.start({'source': source, 'results': results})
    print('armed', flush=True)
    # Even if the outer harness is interrupted this process cannot keep the lease alive.
    time.sleep(100)


def observe_loss(parent):
    isolated(parent)
    run('ip', 'link', 'set', 'lo', 'up')
    for ip in [*TARGETS, CONTROL]:
        run('ip', '-4', 'addr', 'add', ip + '/32', 'dev', 'lo')
    check(all(ping(ip) for ip in [*TARGETS, CONTROL]), 'fixture_baseline_not_healthy')
    with tempfile.TemporaryDirectory() as directory:
        worker = subprocess.Popen([sys.executable, __file__, '--controller', parent, directory], stdout=subprocess.PIPE, text=True)
        try:
            check(worker.stdout.readline().strip() == 'armed', 'controller_arm_failed')
            check(ping(CONTROL) and not ping(TARGETS[0]), 'icmp_scope_not_verified')
            worker.kill(); worker.wait(timeout=4)
            lost = time.monotonic()
            check(not ping(TARGETS[0]) and ping(CONTROL), 'lease_did_not_survive_controller_loss')
            while time.monotonic() - lost <= 90:
                if ping(TARGETS[0]):
                    elapsed = time.monotonic() - lost
                    check(elapsed <= 90 and all(ping(ip) for ip in [*TARGETS[1:], CONTROL]), 'recovery_unconfirmed')
                    print(json.dumps({'isolated_linux': True, 'controller_killed': True, 'icmp_recovered': True,
                                      'seconds_since_loss': round(elapsed, 3), 'non_target_unaffected': True}))
                    return
            raise RuntimeError('icmp_recovery_deadline_exceeded')
        finally:
            if worker.poll() is None:
                worker.kill(); worker.wait(timeout=4)


if __name__ == '__main__':
    check(sys.platform == 'linux', 'actual_linux_required')
    if len(sys.argv) == 1:
        check(all(shutil.which(name) for name in ('unshare', 'ip', 'nft', 'ping')), 'linux_fixture_tools_required')
        args = [] if os.geteuid() == 0 else ['--user', '--map-root-user']
        subprocess.run(['unshare', *args, '--net', sys.executable, __file__, '--observe', namespace()], timeout=115, check=True)
    elif len(sys.argv) == 3 and sys.argv[1] == '--observe':
        observe_loss(sys.argv[2])
    elif len(sys.argv) == 4 and sys.argv[1] == '--controller':
        controller(sys.argv[2], sys.argv[3])
    else:
        raise RuntimeError('fixture_arguments_invalid')
