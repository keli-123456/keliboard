"""CI-only material and HTTP fixture; actual local creation transport is used."""
import base64
import hashlib
import json
from pathlib import Path
import shutil
import subprocess

from member_auxiliary import digest
from member_cli import RUN
from member_control_transport import MemberControlTransport
from member_transport import TransportError


def control_port(work, repo, scope, credentials, journal, environment):
    directory = work / 'creation'; directory.mkdir(mode=0o700)
    bootstrap = '#cloud-config\nssh_pwauth: false\ndisable_root: false\n'
    material = dict(scope, task_id='11111111-1111-4111-8111-111111111111', account_id=71, baseline_ids=[],
        bootstrap_sha256=hashlib.sha256(bootstrap.encode()).hexdigest(), plan=dict(region='sg-sin-2',
            size='g6-standard-2', image='linode/debian12', authorized_keys=['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAITestOnly fixture']))
    key = json.dumps(dict(key=base64.b64encode(b'k' * 32).decode())).encode()
    permit = dict(schema=1, run_id=RUN, operation='control-create', authorized=True, scope_sha256=digest(material),
        credentials_sha256=hashlib.sha256(credentials).hexdigest(), journal_key_sha256=hashlib.sha256(key).hexdigest(),
        operator_machine_sha256=hashlib.sha256(Path('/etc/machine-id').read_bytes()).hexdigest())
    for name, raw in [('creation-scope.json', json.dumps(material).encode()), ('creation-key.json', key),
        ('creation-permit.json', json.dumps(permit).encode()), ('provider-credentials.json', credentials),
        ('creation-bootstrap.json', json.dumps(dict(content=bootstrap)).encode()),
        ('fixture-cloud.json', json.dumps(dict(present=False, methods=[])).encode())]:
        (directory / name).write_bytes(raw); (directory / name).chmod(0o600)
    def execute(args, payload):
        assert args[-2] == str(repo / 'tools/machine-acceptance/member_operator_creation_entry.php')
        assert args[-1] == str(directory)
        actual = [*args[:-2], str(repo / 'audit/r35-operator-creation-fixture.php'), args[-1]]
        result = subprocess.run(actual, input=payload, capture_output=True, timeout=90, env=environment, cwd=repo)
        if result.returncode != 0: raise TransportError('transport_operator_creation_unconfirmed')
        return result.stdout
    return MemberControlTransport(directory=directory, scope=material, php=Path(shutil.which('php')).resolve(),
        guard=lambda *_: None, load=journal.get, save=lambda k, v: journal.__setitem__(k, v), execute=execute), directory
