"""Temporary CI manifest and aggregate-only native test result."""
import hashlib
import json
import os
from pathlib import Path
import re
import sys

HERE = Path(__file__).resolve().parent
ROOT = HERE.parent.parent
ARTIFACTS = ROOT / 'artifacts'


def save(name, value):
    ARTIFACTS.mkdir(exist_ok=True)
    with (ARTIFACTS / name).open('x', encoding='utf-8') as stream:
        json.dump(value, stream, indent=2)
        stream.write('\n')


def main():
    if sys.argv[1] == '--unit':
        text = Path(sys.argv[2]).read_text()
        matches = re.findall(r'test result: ok\. ([0-9]+) passed; ([0-9]+) failed; ([0-9]+) ignored;', text)
        if matches != [('14', '0', '1')]:
            raise RuntimeError('native_unit_count_or_result_changed')
        save('iputils-native-unit.json', {'ok': True, 'tests': 14, 'failed': 0, 'ignored': 1,
                                         'ignored_kernel_case_executed_separately': True})
        return
    rows = [json.loads(line) for line in Path(sys.argv[1]).read_text().splitlines()]
    binaries = [row['executable'] for row in rows if row.get('reason') == 'compiler-artifact' and row.get('executable')
                and row.get('profile', {}).get('test') and row.get('target', {}).get('name') == 'keli_entry_probe_isolated_tests']
    if len(binaries) != 1:
        raise RuntimeError('one_native_test_binary_required')
    binary = Path(binaries[0]).resolve()
    with Path(os.environ['GITHUB_ENV']).open('a') as stream:
        stream.write('NATIVE_TEST_BINARY=' + str(binary) + '\n')
    files = ['entry_probe_diagnostics.py', 'entry_probe_capture.py', 'test_entry_probe_diagnostics.py',
             'test_entry_probe_capture.py', 'test_entry_probe_iputils.py', 'iputils_namespace.py', 'test_iputils_namespace.py', 'ci_iputils_manifest.py',
             'ci-iputils-native/Cargo.toml', 'ci-iputils-native/Cargo.lock', 'ci-iputils-native/src/lib.rs',
             'ci-iputils-native/src/entry_probe.rs']
    paths = [HERE / name for name in files] + [ROOT / '.github/workflows/iputils-single-packet-acceptance.yml']
    save('iputils-sources.json', {'schema': 1, 'commit': os.environ['GITHUB_SHA'],
        'base_commit': '4eefa62b05db9ce6e6b66b1b9d9f9b44ab0aebd1',
        'native_source_base_commit': '8fff3a03a600cdee1d35ed9a937acb0acdc6cedc',
        'source_digests': {p.relative_to(ROOT).as_posix(): hashlib.sha256(p.read_bytes()).hexdigest() for p in paths},
        'native_binary_sha256': hashlib.sha256(binary.read_bytes()).hexdigest(),
        'live_round_or_credentials_loaded': False})


if __name__ == '__main__':
    main()
