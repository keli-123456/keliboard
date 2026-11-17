"""Narrow an existing operator cleanup approval to stopped, recorded resources.

No approval or credentials are acquired here. Preparation is local, and the
provider still independently checks exact cloud/DNS ownership before deletion.
"""
import copy
import hashlib
import json
import os
from pathlib import Path
import tempfile

from driver_journal import unique_json
from member_auxiliary import MemberAuxiliary, digest
from member_operator_transport import MemberOperatorProviderTransport
from member_transport import require
from staging import directory, read_file, unchanged_directory


def immutable_file(path, raw):
    """Publish a complete private file without overwriting an existing receipt."""
    path = Path(path)
    parent, identity = directory(path.parent)
    if not os.path.lexists(path):
        fd, name = tempfile.mkstemp(prefix='.prepare-', dir=parent)
        try:
            with os.fdopen(fd, 'wb') as stream:
                stream.write(raw); stream.flush(); os.fsync(stream.fileno())
            unchanged_directory(parent, identity)
            try: os.link(name, path)
            except FileExistsError: pass
        finally:
            os.unlink(name)
        if os.name == 'posix':
            fd = os.open(parent, os.O_RDONLY | os.O_DIRECTORY)
            try: os.fsync(fd)
            finally: os.close(fd)
    unchanged_directory(parent, identity)
    require(read_file(path, 131072, collect=True, private=True)[1] == raw,
            'cleanup_preparation_file_changed')


class MemberPreparedProvider:
    def __init__(self, *, runtime, directory_path, php, guard, machine_file='/etc/machine-id', execute=None):
        self.runtime, self.php, self.guard, self.execute = runtime, php, guard, execute
        self.root, self.root_identity = directory(Path(directory_path).absolute())
        self.machine = Path(machine_file).absolute()
        self.prepared = self.root / 'prepared-cleanup'
        self.binding = self.inputs()[0]
        previous = runtime.load('provider-preparation-inputs')
        require(previous is None or previous == self.binding, 'cleanup_preparation_inputs_changed')
        # Persist before the round can create a controller. No provider call occurs.
        if previous is None: runtime.setup.persist('provider-preparation-inputs', self.binding)

    def inputs(self):
        unchanged_directory(self.root, self.root_identity)
        credential_hash, credentials, _ = read_file(self.root / 'provider-credentials.json',
                                                   65536, collect=True, private=True)
        approval_hash, raw, _ = read_file(self.root / 'cleanup-parent.json', 65536, collect=True, private=True)
        material, approval = unique_json(credentials), unique_json(raw)
        machine = read_file(self.machine, 4096, collect=True)[1]
        require(bool(machine), 'cleanup_preparation_machine_required')
        baseline = material.get('baseline_ids') if isinstance(material, dict) else None
        require(isinstance(baseline, list) and len(baseline) <= 996
                and all(isinstance(v, str) and v.isascii() and v.isdecimal() and 1 <= len(v) <= 16
                        and not v.startswith('0') for v in baseline)
                and len(set(baseline)) == len(baseline), 'cleanup_preparation_baseline')
        expected = dict(schema=1, operation='prepare-provider-cleanup', authorized=True,
            scope=self.runtime.setup.scope, credentials_sha256=credential_hash['sha256'],
            operator_machine_sha256=hashlib.sha256(machine).hexdigest(), baseline_ids=baseline)
        require(isinstance(approval, dict) and approval == expected and approval['authorized'] is True
                and type(approval['schema']) is int, 'cleanup_preparation_approval')
        return (dict(approval_sha256=approval_hash['sha256'], credentials_sha256=credential_hash['sha256'],
                     operator_machine_sha256=expected['operator_machine_sha256']), credentials, baseline)

    def prepare(self):
        binding, credentials, baseline = self.inputs()
        require(binding == self.binding == self.runtime.load('provider-preparation-inputs'),
                'cleanup_preparation_inputs_changed')
        stopped = self.runtime.stopped()
        scope = stopped['auxiliary']
        MemberAuxiliary(scope=scope, io=None, load=lambda _: None, save=lambda *_: None)
        control = scope['control']
        require(control == self.runtime.load('setup-control-pin')
                and (control is None or control.get('external_id') not in baseline),
                'cleanup_preparation_control_changed')
        pins = ([] if control is None else [control]) + scope['records']
        provider_scope = dict(run_id=self.runtime.setup.scope['run_id'],
            bundle_sha256=self.runtime.setup.scope['bundle_sha256'], baseline_ids=baseline,
            pins=copy.deepcopy(pins), dns_receipts=[])
        sealed = dict(inputs=binding, scope_sha256=digest(provider_scope))
        previous = self.runtime.load('provider-preparation-scope')
        require(previous is None or previous == sealed, 'cleanup_preparation_scope_changed')
        if previous is None: self.runtime.setup.persist('provider-preparation-scope', sealed)
        try: self.prepared.mkdir(mode=0o700)
        except FileExistsError: pass
        directory(self.prepared)
        permit = dict(schema=1, run_id=provider_scope['run_id'], operation='provider-cleanup', authorized=True,
            scope_sha256=digest(provider_scope), credentials_sha256=binding['credentials_sha256'],
            operator_machine_sha256=binding['operator_machine_sha256'])
        for name, value in (('provider-scope.json', provider_scope), ('provider-permit.json', permit)):
            immutable_file(self.prepared / name, json.dumps(value, sort_keys=True, separators=(',', ':')).encode())
        immutable_file(self.prepared / 'provider-credentials.json', credentials)
        require(self.inputs()[0] == binding, 'cleanup_preparation_inputs_changed')
        return MemberOperatorProviderTransport(directory=self.prepared, scope=provider_scope, php=self.php,
            guard=self.guard, load=self.runtime.load, save=self.runtime.save, execute=self.execute)

    def dispatch(self, operation, pin):
        require(operation in ('control-inspect', 'control-delete', 'dns-inspect', 'dns-delete'),
                'cleanup_preparation_operation')
        return self.prepare().dispatch(operation, pin)
