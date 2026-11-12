"""Run only a private synthetic Linux fixture; never operate host services."""
import argparse
import json
import os
from pathlib import Path
import re
import signal
import stat
import subprocess
import sys
import time


def processes():
    rows = {}
    for path in Path('/proc').glob('[0-9]*/stat'):
        try:
            fields = path.read_text().rsplit(') ', 1)[1].split()
            rows[int(path.parent.name)] = (int(fields[1]), int(fields[2]), int(fields[3]), int(fields[19]), fields[0])
        except (OSError, ValueError, IndexError):
            continue
    return rows


def alive(pid, recorded):
    row = processes().get(pid)
    return row is not None and row[3] == recorded[3] and row[4] != 'Z'


def run(base, scenario):
    if sys.platform != 'linux' or os.geteuid() == 0:
        raise RuntimeError('nonroot_linux_required')
    base = base.resolve(strict=True)
    if base.parent != Path('/tmp') or not re.fullmatch(r'keli-provision-test-[a-f0-9]{24}', base.name):
        raise RuntimeError('synthetic_private_directory_required')
    for path in [base, base / 'fixture-scope.json', base / 'run-fixture.sh']:
        info = path.lstat()
        if path.is_symlink() or info.st_uid != os.geteuid() or stat.S_IMODE(info.st_mode) & 0o077:
            raise RuntimeError('fixture_permissions_unsafe')
    scope = json.loads((base / 'fixture-scope.json').read_text())
    if scope != {'schema': 1, 'synthetic': True, 'directory': str(base)}:
        raise RuntimeError('synthetic_scope_changed')
    started = time.monotonic()
    owned = {}
    result = {'scenario': scenario, 'synthetic_only': True, 'signal_sent': False}
    with (base / 'fixture-process.log').open('xb') as output:
        process = subprocess.Popen(['/bin/bash', './run-fixture.sh'], cwd=base, stdout=output,
                                   stderr=subprocess.STDOUT, start_new_session=True)
        try:
            deadline = started + 8
            while not (base / 'native-ready.pid').is_file():
                if process.poll() is not None or time.monotonic() >= deadline:
                    raise RuntimeError('fixture_not_ready')
                time.sleep(.025)
            snapshot = processes()
            owned = {pid: row for pid, row in snapshot.items() if row[2] == process.pid}
            target = int((base / 'native-ready.pid').read_text())
            if process.pid not in owned or target not in owned or len(owned) > 128:
                raise RuntimeError('fixture_process_identity_unverified')
            if scenario != 'timeout':
                os.kill(target if scenario == 'installer_term' else process.pid, signal.SIGTERM)
                result['signal_sent'] = True
            result['exit_code'] = process.wait(timeout=18)
            result['elapsed_seconds'] = round(time.monotonic() - started, 3)
            result['native_cleanup_before_harness'] = not (base / 'native-lock').exists() and not (base / 'native-work').exists()
            grace = time.monotonic() + .5
            while time.monotonic() < grace and any(row[2] == process.pid and row[4] != 'Z' for row in processes().values()):
                time.sleep(.02)
            result['remaining_before_harness'] = sum(row[2] == process.pid and row[4] != 'Z' for row in processes().values())
        finally:
            snapshot = processes()
            owned.update({pid: row for pid, row in snapshot.items() if row[2] == process.pid})
            for sig in (signal.SIGTERM, signal.SIGKILL):
                groups = {row[1] for pid, row in owned.items() if alive(pid, row)}
                for group in groups:
                    if group <= 1 or group == os.getpgrp():
                        raise RuntimeError('foreign_process_group_refused')
                    try:
                        os.killpg(group, sig)
                    except ProcessLookupError:
                        pass
                if not groups:
                    break
                time.sleep(.2)
            process.wait(timeout=3)
            result['remaining_owned_processes'] = sum(row[2] == process.pid and row[4] != 'Z' for row in processes().values())
    return result


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--work', type=Path, required=True)
    parser.add_argument('--scenario', choices=['timeout', 'installer_term', 'bootstrap_term'], required=True)
    args = parser.parse_args()
    try:
        print(json.dumps(run(args.work, args.scenario)))
    except Exception as error:
        print(json.dumps({'ok': False, 'error_class': type(error).__name__, 'details_omitted': True}))
        raise SystemExit(1)
