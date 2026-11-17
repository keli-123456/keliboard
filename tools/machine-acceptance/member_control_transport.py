"""Fixed local control-creation endpoint; provider POST/recovery stays in PHP."""
import copy
import json
from pathlib import Path

from driver_journal import safe_error, unique_json
from member_auxiliary import digest
from member_cli import RUN
from member_operator_transport import MemberOperatorProviderTransport
from member_transport import require
from staging import read_file


class MemberControlTransport:
    def __init__(self, *, directory, scope, php, guard, load, save, execute=None):
        require(isinstance(scope, dict) and scope.get('run_id') == RUN
                and {'bundle_sha256', 'started_at', 'deadline_at'} <= set(scope), 'transport_operator_creation_scope')
        self.directory, self.php = Path(directory).absolute(), Path(php).absolute()
        self.scope, self.guard, self.load, self.save = copy.deepcopy(scope), guard, load, save
        self.entry = Path(__file__).absolute().with_name('member_operator_creation_entry.php')
        self.execute = execute or MemberOperatorProviderTransport._execute

    def dispatch(self, operation, request):
        scope = {k: self.scope[k] for k in ('run_id', 'bundle_sha256', 'started_at', 'deadline_at')}
        expected = dict(run_id=RUN, bundle_sha256=scope['bundle_sha256'], stage='control-create',
            predecessors_sha256=digest({}), request_id=digest([scope, 'control-create'])[:32])
        require(operation in ('control-create', 'control-readback') and request == expected,
                'transport_operator_creation_request')
        self.guard(operation, copy.deepcopy(request))
        require(self.directory.resolve(strict=True) == self.directory and self.directory.is_dir(),
                'transport_operator_creation_directory')
        binding = dict(scope_sha256=digest(self.scope), directory=str(self.directory),
            entry_sha256=read_file(self.entry, 65536)[0]['sha256'], php_sha256=read_file(self.php, 67108864)[0]['sha256'])
        previous = self.load('operator-creation-binding')
        require(previous is None or previous == binding, 'transport_operator_creation_binding_changed')
        if previous is None:
            self.save('operator-creation-binding', binding)
            require(self.load('operator-creation-binding') == binding, 'transport_operator_creation_journal')
        outer = dict(operation=operation, scope_sha256=digest(self.scope), request=copy.deepcopy(request))
        try:
            raw = self.execute([str(self.php), '-d', 'display_errors=0', '-d', 'log_errors=0', str(self.entry), str(self.directory)],
                json.dumps(outer, separators=(',', ':')).encode())
            require(isinstance(raw, bytes) and len(raw) <= 16384, 'transport_operator_creation_unconfirmed')
            reply = unique_json(raw)
            require(set(reply) == {'ok', 'request_sha256', 'result'} and reply['ok'] is True
                    and reply['request_sha256'] == digest(outer), 'transport_operator_creation_reply')
            result = reply['result']
            require(isinstance(result, dict) and set(result) in (
                {'request_sha256', 'resolved', 'dispatched', 'ready', 'pin'},
                {'request_sha256', 'resolved', 'dispatched', 'ready', 'pin', 'creation_rejected'})
                and result['request_sha256'] == digest(request)
                and all(type(result[k]) is bool for k in ('resolved', 'dispatched', 'ready', 'creation_rejected') if k in result),
                'transport_operator_creation_reply')
            pin = result['pin']
            require(pin is None or isinstance(pin, dict) and set(pin) == {'run_id', 'external_id', 'task_id', 'label', 'marker',
                    'region', 'size', 'image', 'provider_created_at'} and all(isinstance(v, str) and len(v) <= 256 for v in pin.values()),
                    'transport_operator_creation_private_reply')
            return copy.deepcopy(result)
        except BaseException as error:
            try:
                if self.load('operator-creation-first-error') is None:
                    self.save('operator-creation-first-error', dict(operation=operation, diagnostic=safe_error(error)))
            except Exception:
                pass
            raise
