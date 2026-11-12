"""Temporary isolated CI verifier. Export aggregate evidence only."""
import contextlib
import hashlib
import json
import os
from pathlib import Path
import re
import socket
import sys
import unittest
from unittest.mock import patch
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[2]
OUT = ROOT / 'artifacts'


def save(name, value):
    OUT.mkdir(exist_ok=True)
    (OUT / name).write_text(json.dumps(value, indent=2) + '\n')


def verify():
    expected = json.loads(Path(__file__).with_name('ci-source-pins.json').read_text())
    observed = {name: hashlib.sha256((ROOT / name).read_bytes()).hexdigest() for name in expected}
    if observed != expected or sys.platform != 'linux' or os.geteuid() == 0 or (ROOT / '.env').exists():
        raise RuntimeError('ci_context_changed')
    return observed


def namespace():
    verify()
    value = {'network_namespace_isolated': os.readlink('/proc/self/ns/net') != os.environ['HOST_NET_NS'],
             'only_loopback_interface': [name for _, name in socket.if_nameindex()] == ['lo'],
             'effective_uid': os.geteuid()}
    save('namespace.json', value)
    if not all(value[key] for key in ['network_namespace_isolated', 'only_loopback_interface']):
        raise RuntimeError('isolated_network_required')


def python_tests():
    namespace()
    class Evidence(unittest.TextTestResult):
        def __init__(self, *args, **kwargs):
            super().__init__(*args, **kwargs)
            self.passed = []
        def addSuccess(self, test):
            self.passed.append(test.id())
            super().addSuccess(test)
    denied = []
    def refuse(*args, **kwargs):
        denied.append(True)
        raise RuntimeError('external_operation_refused')
    suite = unittest.defaultTestLoader.loadTestsFromNames([
        'test_staging', 'test_bootstrap_diagnostics', 'test_diagnostic_capture', 'test_worker.WorkerTests'])
    with contextlib.ExitStack() as stack:
        for target in ['socket.create_connection', 'socket.socket.connect', 'socket.socket.connect_ex',
                       'socket.getaddrinfo', 'subprocess.run', 'subprocess.Popen']:
            stack.enter_context(patch(target, side_effect=refuse))
        result = unittest.TextTestRunner(verbosity=2, resultclass=Evidence).run(suite)
    report = {'ok': result.wasSuccessful() and not result.skipped and not denied, 'tests': result.testsRun,
              'passed': len(result.passed), 'skipped': len(result.skipped), 'failures': len(result.failures),
              'errors': len(result.errors), 'successful_tests': result.passed, 'denied_operations': len(denied)}
    save('python.json', report)
    if report['ok'] is not True or report['tests'] != 84:
        raise RuntimeError('python_acceptance_failed')


def aggregate():
    pins = verify()
    python = json.loads((OUT / 'python.json').read_text()) if (OUT / 'python.json').exists() else {'ok': False}
    ns = json.loads((OUT / 'namespace.json').read_text()) if (OUT / 'namespace.json').exists() else {}
    php = {'ok': False, 'tests': 0, 'skipped': 0, 'failures': [], 'errors': []}
    path = OUT / 'phpunit.xml'
    if path.exists():
        cases = ET.parse(path).getroot().findall('.//testcase')
        php['tests'] = len(cases)
        php['skipped'] = sum(case.find('skipped') is not None for case in cases)
        for case in cases:
            for kind in ['failure', 'error']:
                child = case.find(kind)
                if child is not None:
                    php[kind + 's'].append({'test': case.get('name'),
                        'numeric_assertions': re.findall(r'Failed asserting that ([0-9]+) is identical to ([0-9]+)', child.text or '')})
        php['ok'] = php['tests'] == 198 and not php['skipped'] and not php['failures'] and not php['errors']
    lifecycle = {}
    for scenario, expected in [('timeout', 124), ('installer_term', 143), ('bootstrap_term', 143)]:
        path = OUT / ('lifecycle-' + scenario + '.json')
        row = json.loads(path.read_text()) if path.exists() else {}
        lifecycle[scenario] = row
        row['passed'] = (row.get('exit_code') == expected and row.get('bootstrap_exit_code') == expected
                         and row.get('native_installer_exit_code') == 143 and row.get('callback_state') == 'failed'
                         and row.get('callback_count') == 1 and row.get('native_cleanup_before_harness') is True
                         and row.get('remaining_owned_processes') == 0 and row.get('synthetic_only') is True)
    permissions = ['test_staging.StagingTests.test_private_directory_and_json_permissions_are_required',
                   'test_bootstrap_diagnostics.ProbeTests.test_unsafe_owner_and_log_permissions_are_refused']
    passed = (php['ok'] and python.get('ok') is True and all(row['passed'] for row in lifecycle.values())
              and all(name in python.get('successful_tests', []) for name in permissions)
              and ns.get('network_namespace_isolated') is True and ns.get('only_loopback_interface') is True)
    report = {'schema': 1, 'ok': passed, 'base_commit': 'a701aa72a61d7fe4314263833d63d078c6e655b2',
              'ci_commit': os.environ['GITHUB_SHA'], 'source_hashes': pins, 'php': php, 'python': python,
              'lifecycle': lifecycle, 'namespace': ns, 'platform': sys.platform,
              'production_connections': 0, 'cloud_creates': 0, 'dns_writes': 0, 'synthetic_only': True}
    save('linux-execution-result.json', report)
    print(json.dumps({key: value for key, value in report.items() if key not in {'source_hashes', 'python'}}))
    return 0 if passed else 1


if __name__ == '__main__':
    mode = sys.argv[1]
    if mode == 'aggregate':
        raise SystemExit(aggregate())
    if mode == 'namespace': namespace()
    elif mode == 'python-tests': python_tests()
    else: verify()
