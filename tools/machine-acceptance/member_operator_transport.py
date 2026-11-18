"""Local PHP provider cleanup: independent of the remote controller's lifetime."""
import copy
import json
from pathlib import Path
import re
import subprocess
import tempfile

from driver_journal import unique_json, safe_error
from member_auxiliary import digest
from member_cli import RUN
from member_transport import require
from staging import read_file


class MemberOperatorProviderTransport:
    def __init__(self, *, directory, scope, php, guard, load, save, execute=None):
        require(isinstance(scope, dict) and set(scope) == {'run_id', 'bundle_sha256', 'baseline_ids', 'pins', 'dns_receipts'}
                and scope['run_id'] == RUN and isinstance(scope['bundle_sha256'], str)
                and re.fullmatch('[a-f0-9]{64}', scope['bundle_sha256']) and isinstance(scope['pins'], list)
                and len(scope['pins']) <= 5, 'cleanup_operator_scope')
        self.directory, self.php = Path(directory).absolute(), Path(php).absolute()
        self.scope, self.guard, self.load, self.save = copy.deepcopy(scope), guard, load, save
        self.execute = execute or self._execute
        self.entry = Path(__file__).absolute().with_name('member_operator_entry.php')

    @staticmethod
    def _execute(command, payload):
        with tempfile.TemporaryFile() as output, tempfile.TemporaryFile() as errors:
            with subprocess.Popen(command, stdin=subprocess.PIPE, stdout=output, stderr=errors) as process:
                try: process.communicate(payload, timeout=90)
                except subprocess.TimeoutExpired:
                    process.kill(); process.communicate()
                    require(False, 'cleanup_operator_timeout')
                output.seek(0); raw = output.read(16385)
                require(process.returncode == 0 and len(raw) <= 16384, 'cleanup_operator_unconfirmed')
                return raw

    def dispatch(self, operation, pin):
        require(operation in ('control-inspect', 'control-delete', 'dns-inspect', 'dns-delete')
                and pin in self.scope['pins'] and pin.get('run_id') == RUN, 'cleanup_operator_pin')
        self.guard(operation, copy.deepcopy(pin))
        require(self.directory.resolve(strict=True) == self.directory and self.directory.is_dir(), 'cleanup_operator_directory')
        binding = dict(scope_sha256=digest(self.scope), directory=str(self.directory),
            entry_sha256=read_file(self.entry, 65536)[0]['sha256'], php_sha256=read_file(self.php, 67108864)[0]['sha256'])
        prior = self.load('operator-provider-binding')
        require(prior is None or prior == binding, 'cleanup_operator_binding_changed')
        if prior is None:
            self.save('operator-provider-binding', copy.deepcopy(binding))
            require(self.load('operator-provider-binding') == binding, 'cleanup_operator_journal')
        request = dict(operation=operation, pin=copy.deepcopy(pin), scope_sha256=digest(self.scope))
        key = 'operator-delete-' + digest(pin)
        if operation.endswith('-delete'):
            require(self.load(key) is None, 'cleanup_operator_readback_required')
            intent = dict(request_sha256=digest(request))
            self.save(key, intent)
            require(self.load(key) == intent, 'cleanup_operator_journal')
        try:
            # Credentials and authorization are pre-existing private inputs; this
            # adapter neither writes permits nor copies secrets onto a controller.
            raw = self.execute([str(self.php), '-d', 'display_errors=0', '-d', 'log_errors=0', str(self.entry), str(self.directory)],
                               json.dumps(request, separators=(',', ':')).encode())
            require(isinstance(raw, bytes) and len(raw) <= 16384, 'cleanup_operator_unconfirmed')
            reply = unique_json(raw)
            require(set(reply) == {'ok', 'request_sha256', 'result'} and reply['ok'] is True
                    and reply['request_sha256'] == digest(request), 'cleanup_operator_reply_changed')
            result = reply['result']
            require(isinstance(result, dict) and {'pin_sha256', 'status'} <= set(result)
                    <= {'pin_sha256', 'status', 'dispatched', 'reconciliation_required'}
                    and result['pin_sha256'] == digest(pin) and result['status'] in ('owned', 'absent')
                    and all(type(result[k]) is bool for k in ('dispatched', 'reconciliation_required') if k in result),
                    'cleanup_operator_reply_changed')
            return copy.deepcopy(result)
        except BaseException as error:
            if self.load('operator-provider-first-error') is None:
                self.save('operator-provider-first-error', dict(operation=operation, diagnostic=safe_error(error)))
            raise
