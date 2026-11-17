"""R35 strict SSH adapter. Construction never grants execution authority."""
import hashlib
import copy
import ipaddress
import json
from pathlib import Path
import re
import subprocess
import time
import uuid
import shlex

from driver_journal import unique_json

ROLES = ('source-a', 'source-b', 'candidate-a1', 'candidate-a2')
OPERATIONS = ('source-observe', 'source-register', 'task-inspect', 'task-delete')
REMOTE_ENTRY = '/opt/keli-isolated/runtime/member_transport_entry.php'
LIMIT = 262144


class TransportError(RuntimeError):
    pass


def require(value, code):
    if not value:
        raise TransportError(code)


def reason(error, fallback):
    value = str(error) if isinstance(error, TransportError) else ''
    return value if re.fullmatch(r'(?:transport|cleanup)_[a-z_]{1,70}', value) else fallback


class StrictControllerTransport:
    def __init__(self, *, run_id, host, identity_file, known_hosts, guard, load, save,
                 execute=None):
        require(re.fullmatch(r'keli-accept-[a-z0-9-]{8,80}', run_id), 'transport_run_invalid')
        require(ipaddress.ip_address(host).version == 4, 'transport_ipv4_required')
        self.run_id, self.host = run_id, host
        self.identity, self.known = Path(identity_file).absolute(), Path(known_hosts).absolute()
        self.guard, self.load, self.save = guard, load, save
        self.execute = execute or self._execute

    @staticmethod
    def _execute(command, payload, timeout):
        # Temporary files bound memory even if an unexpected remote process floods stdout.
        import tempfile
        with tempfile.TemporaryFile() as output, tempfile.TemporaryFile() as errors:
            with subprocess.Popen(command, stdin=subprocess.PIPE, stdout=output, stderr=errors) as process:
                try:
                    process.communicate(payload, timeout=timeout)
                except subprocess.TimeoutExpired:
                    process.kill(); process.communicate()
                    raise TransportError('transport_timeout_reconcile_required') from None
                output.seek(0); raw = output.read(LIMIT + 1)
                require(process.returncode == 0, 'transport_remote_failed')
                require(len(raw) <= LIMIT, 'transport_response_too_large')
                return raw

    def call(self, operation, role):
        require(operation in OPERATIONS and role in ROLES, 'transport_operation_invalid')
        require(not operation.startswith('source-') or role.startswith('source-'), 'transport_source_role_invalid')
        self.guard(operation, role)
        for file in (self.identity, self.known):
            require(file.is_file() and not file.is_symlink() and file.stat().st_nlink == 1, 'transport_key_or_hosts_missing')
        # Destructive RPC uncertainty cannot be retried with a new request UUID.
        mutation = operation == 'task-delete'
        key = 'rpc-' + operation + '-' + role
        require(not mutation or self.load(key) is None, 'transport_mutation_reconcile_required')
        request = dict(schema=1, run_id=self.run_id, request_id=uuid.uuid4().hex, role=role, operation=operation)
        if mutation:
            self.save(key, dict(request, dispatched=False))
        command = self._command('php ' + REMOTE_ENTRY)
        try:
            raw = self.execute(command, json.dumps(request, separators=(',', ':')).encode(), 45)
            require(isinstance(raw, bytes) and len(raw) <= LIMIT, 'transport_response_too_large')
            result = unique_json(raw)
            require(all(result.get(k) == v for k, v in request.items()), 'transport_response_binding_changed')
            require(result.get('ok') is True and isinstance(result.get('result'), dict), 'transport_remote_rejected')
            return result['result']
        except Exception as error:
            self._first_error(operation, role, error)
            if isinstance(error, TransportError):
                raise
            raise TransportError('transport_invalid_response') from None

    def _command(self, remote_command):
        return ['ssh', '-F', 'NUL' if __import__('os').name == 'nt' else '/dev/null', '-T',
                   '-o', 'BatchMode=yes', '-o', 'IdentitiesOnly=yes', '-o', 'StrictHostKeyChecking=yes',
                   '-o', 'UpdateHostKeys=no', '-o', 'PasswordAuthentication=no', '-o', 'KbdInteractiveAuthentication=no',
                   '-o', 'ForwardAgent=no', '-o', 'ClearAllForwardings=yes', '-o', 'ConnectTimeout=10',
                   '-o', 'ConnectionAttempts=1', '-o', 'ServerAliveInterval=5', '-o', 'ServerAliveCountMax=2',
                   '-o', 'UserKnownHostsFile=' + str(self.known), '-i', str(self.identity),
                   'root@' + self.host, remote_command]

    def bootstrap_call(self, operation, control=None):
        """Fixed root bootstrap CLI. Uncertain mutations require explicit readback."""
        readers = {'import': 'readback', 'configure': 'configuration-read', 'setup-issue': 'setup-read'}
        require(operation in (*readers, *readers.values(), 'stop'), 'transport_bootstrap_operation_invalid')
        require((control is not None) == (operation == 'setup-issue'), 'transport_bootstrap_control_invalid')
        if control is not None:
            keys = {'run_id', 'external_id', 'task_id', 'label', 'marker', 'region', 'size', 'image', 'provider_created_at'}
            require(isinstance(control, dict) and set(control) == keys
                    and all(isinstance(v, str) and len(v) <= 128 for v in control.values()), 'transport_bootstrap_control_invalid')
            suffix = control['task_id'].replace('-', '')
            require(control['run_id'] == self.run_id and re.fullmatch(r'[1-9][0-9]{0,15}', control['external_id'])
                    and re.fullmatch(r'[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}', control['task_id'])
                    and control['label'] == 'keli-' + suffix and control['marker'] == 'keli-create-' + suffix
                    and control['region'] == 'sg-sin-2' and control['size'] == 'g6-standard-2'
                    and control['image'] == 'linode/debian12'
                    and re.fullmatch(r'\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ?', control['provider_created_at']),
                    'transport_bootstrap_control_invalid')
        self.guard('bootstrap-' + operation, 'control')
        for file in (self.identity, self.known):
            require(file.is_file() and not file.is_symlink() and file.stat().st_nlink == 1, 'transport_key_or_hosts_missing')

        def persist(key, value):
            self.save(key, copy.deepcopy(value))
            require(self.load(key) == value, 'transport_bootstrap_journal_unconfirmed')

        def digest(value):
            return hashlib.sha256(json.dumps(value, sort_keys=True, separators=(',', ':')).encode()).hexdigest()

        binding = dict(run_id=self.run_id, host=self.host,
            identity_sha256=hashlib.sha256(self.identity.read_bytes()).hexdigest(),
            known_hosts_sha256=hashlib.sha256(self.known.read_bytes()).hexdigest())
        prior_binding = self.load('bootstrap-binding')
        require(prior_binding is None or prior_binding == binding, 'transport_bootstrap_binding_changed')
        if prior_binding is None: persist('bootstrap-binding', binding)
        request = dict(operation=operation)
        if control is not None: request['control'] = copy.deepcopy(control)
        mutation = operation in readers
        owner = operation if mutation else next((k for k, v in readers.items() if v == operation), None)
        key = 'bootstrap-' + owner if owner else None
        prior = self.load(key) if key else None
        if mutation:
            require(self.load('bootstrap-stopped') is None, 'transport_bootstrap_stopped')
            require(prior is None, 'transport_bootstrap_readback_required')
            persist(key, dict(request=request, completed=False))
        elif operation == 'stop':
            persist('bootstrap-stopped', dict(stopped=True))
        else:
            require(isinstance(prior, dict) and type(prior.get('completed')) is bool
                    and isinstance(prior.get('request'), dict)
                    and set(prior['request']) == ({'operation', 'control'} if owner == 'setup-issue' else {'operation'})
                    and prior['request'].get('operation') == owner
                    and set(prior) == ({'request', 'completed', 'evidence_sha256'} if prior['completed'] else {'request', 'completed'})
                    and (not prior['completed'] or (isinstance(prior.get('evidence_sha256'), str)
                         and re.fullmatch(r'[a-f0-9]{64}', prior['evidence_sha256']))), 'transport_bootstrap_intent_missing')
        try:
            raw = self.execute(self._command('php /opt/keli-isolated/runtime/member_import_entry.php'),
                json.dumps(request, separators=(',', ':')).encode(), 90)
            require(isinstance(raw, bytes) and len(raw) <= LIMIT, 'transport_response_too_large')
            reply = unique_json(raw)
            field = {'import': 'scope', 'readback': 'scope', 'configure': 'configuration',
                'configuration-read': 'configuration', 'setup-issue': 'setup', 'setup-read': 'setup', 'stop': 'stopped'}[operation]
            require(isinstance(reply, dict) and set(reply) == {'ok', 'operation', field}
                    and reply['ok'] is True and reply['operation'] == operation, 'transport_bootstrap_reply_changed')
            value = reply[field]
            if field == 'stopped': require(value is True, 'transport_bootstrap_stop_unconfirmed')
            else:
                require(isinstance(value, dict) and bool(value), 'transport_bootstrap_reply_changed')
                if field == 'scope': require(value.get('run_id') == self.run_id, 'transport_bootstrap_reply_changed')
                if field == 'configuration':
                    require(value.get('initialization_scope', {}).get('run_id') == self.run_id
                            and value.get('health_or_forwarding_claimed') is False and value.get('cloud_or_dns_writes') is False,
                            'transport_bootstrap_reply_changed')
                if field == 'setup':
                    require(set(value) == {'grant_id', 'grant_sha256', 'deadline_at'}
                            and type(value['deadline_at']) is int and value['deadline_at'] > 0
                            and all(isinstance(value[k], str) and re.fullmatch(r'[a-f0-9]{%d}' % n, value[k])
                                    for k, n in [('grant_id', 32), ('grant_sha256', 64)]), 'transport_bootstrap_reply_changed')
                evidence = digest(value)
                if prior and prior.get('completed') is True:
                    require(prior.get('evidence_sha256') == evidence, 'transport_bootstrap_readback_changed')
                # Persist only a digest, never the full account/configuration reply.
                persist(key, dict(request=request if mutation else prior['request'], completed=True, evidence_sha256=evidence))
            return dict(operation=operation, verified=True, evidence_sha256=digest(value),
                        **({'setup': copy.deepcopy(value)} if field == 'setup' else {}))
        except Exception as error:
            self._first_error('bootstrap-' + operation, 'control', error)
            if isinstance(error, TransportError): raise
            raise TransportError('transport_bootstrap_uncertain') from None

    def setup_call(self, operation, identity):
        """Fixed setup CLI, with a durable intent before each advancing RPC."""
        require(operation in ('advance', 'status', 'resources', 'stop', 'cleanup', 'dns-control', 'dns-edges', 'dns-readback',
                              'native-issue', 'native-read', 'native-export', 'evidence-export'), 'transport_setup_operation_invalid')
        require(isinstance(identity, dict) and set(identity) == {'grant_id', 'grant_sha256', 'deadline_at'}
                and type(identity['deadline_at']) is int and identity['deadline_at'] > 0
                and all(isinstance(identity[k], str) and re.fullmatch(r'[a-f0-9]{%d}' % n, identity[k])
                        for k, n in [('grant_id', 32), ('grant_sha256', 64)]), 'transport_setup_identity_invalid')
        self.guard('setup-' + operation, 'control')
        for file in (self.identity, self.known):
            require(file.is_file() and not file.is_symlink() and file.stat().st_nlink == 1, 'transport_key_or_hosts_missing')
        pin = dict(run_id=self.run_id, host=self.host, **identity)
        prior_pin = self.load('setup-binding')
        require(prior_pin is None or prior_pin == pin, 'transport_setup_binding_changed')
        if prior_pin is None:
            self.save('setup-binding', copy.deepcopy(pin))
            require(self.load('setup-binding') == pin, 'transport_setup_journal_unconfirmed')
        previous = self.load('setup-mutation')
        prior_cleanup = self.load('setup-cleanup')
        mutation = operation in ('advance', 'dns-control', 'dns-edges', 'native-issue')
        if mutation:
            require(previous is None or previous.get('completed') is True, 'transport_setup_readback_required')
            require(prior_cleanup is None, 'transport_setup_cleanup_started')
        sequence = max((item['request']['sequence'] for item in (previous, prior_cleanup) if item), default=-1) + 1
        request = dict(schema=1, run_id=self.run_id, **identity, request_id=uuid.uuid4().hex,
                       sequence=sequence, operation=operation)
        ledger = 'setup-cleanup' if operation == 'cleanup' else 'setup-mutation'
        if mutation or operation == 'cleanup':
            intent = dict(request=request, completed=False)
            self.save(ledger, copy.deepcopy(intent))
            require(self.load(ledger) == intent, 'transport_setup_journal_unconfirmed')
        try:
            raw = self.execute(self._command('php /opt/keli-isolated/runtime/member_setup_entry.php'),
                               json.dumps(request, separators=(',', ':')).encode(), 45)
            require(isinstance(raw, bytes) and len(raw) <= LIMIT, 'transport_response_too_large')
            result = unique_json(raw)
            require(all(result.get(k) == v for k, v in request.items()), 'transport_response_binding_changed')
            require(result.get('ok') is True and isinstance(result.get('result'), dict), 'transport_remote_rejected')
            if operation in ('native-issue', 'native-read', 'native-export'):
                value = copy.deepcopy(result['result'])
                exported = value.pop('grant_base64', None)
                require((exported is not None) == (operation == 'native-export'), 'transport_native_export_invalid')
                metadata = {'uncertain_request', 'uncertain_cleanup'}
                if metadata & set(value):
                    require(operation in ('native-read', 'native-export') and metadata <= set(value)
                            and all(value[key] is None for key in metadata), 'transport_native_readback_uncertain')
                    for key in metadata: value.pop(key)
                if value.get('ready') is False:
                    require(operation == 'native-issue' and set(value) <= {'ready', 'phase', 'next_check_at', 'blockers'}
                            and value.get('phase') in ('setup_cooldown', 'source_failure_observation', 'source_plan_blocked')
                            and ('next_check_at' not in value or type(value['next_check_at']) is int
                                 and 0 < value['next_check_at'] < identity['deadline_at']), 'transport_native_reply_invalid')
                    if 'blockers' in value:
                        require(value['phase'] == 'source_plan_blocked' and isinstance(value['blockers'], list)
                                and 0 < len(value['blockers']) <= 30
                                and all(isinstance(v, str) and re.fullmatch(r'[a-z][a-z0-9_]{0,79}', v)
                                        for v in value['blockers']), 'transport_native_reply_invalid')
                else:
                    grant = value.get('identity')
                    require(set(value) == {'ready', 'identity', 'deadline_at', 'cloud_or_dns_writes'}
                            and value['ready'] is True and value['cloud_or_dns_writes'] is False
                            and value['deadline_at'] == identity['deadline_at']
                            and isinstance(grant, dict) and set(grant) == {'run_id', 'grant_id', 'grant_sha256', 'bundle_sha256'}
                            and grant['run_id'] == self.run_id
                            and all(isinstance(grant[k], str) and re.fullmatch(r'[a-f0-9]{%d}' % n, grant[k])
                                    for k, n in [('grant_id', 32), ('grant_sha256', 64), ('bundle_sha256', 64)]),
                            'transport_native_reply_invalid')
                    prior_grant = self.load('setup-native-grant')
                    require(prior_grant is None or prior_grant == value, 'transport_native_grant_changed')
                    if operation == 'native-export':
                        import base64
                        require(isinstance(exported, str) and len(exported) <= 87384, 'transport_native_export_invalid')
                        try: grant_raw = base64.b64decode(exported, validate=True)
                        except (ValueError, TypeError): raise TransportError('transport_native_export_invalid') from None
                        require(len(grant_raw) <= 65536 and hashlib.sha256(grant_raw).hexdigest() == grant['grant_sha256'],
                                'transport_native_export_changed')
                        document = unique_json(grant_raw)
                        require(isinstance(document, dict) and document.get('schema') == 2
                                and document.get('execution_authorized') is True and document.get('isolated') is True
                                and all(document.get(key) == grant[key] for key in ('run_id', 'grant_id', 'bundle_sha256'))
                                and document.get('deadline_at') == identity['deadline_at'], 'transport_native_export_changed')
                    self.save('setup-native-grant', copy.deepcopy(value))
                    require(self.load('setup-native-grant') == value, 'transport_setup_journal_unconfirmed')
            if mutation or operation == 'cleanup':
                complete = dict(request=request, completed=True, reply=result)
                self.save(ledger, copy.deepcopy(complete))
                require(self.load(ledger) == complete, 'transport_setup_journal_unconfirmed')
            return result['result']
        except Exception as error:
            self._first_error('setup-' + operation, 'control', error)
            if isinstance(error, TransportError):
                raise
            raise TransportError('transport_setup_uncertain') from None

    def application_call(self, operation, scope, service=None):
        """Initialize/read the pinned isolated app; never accept an Artisan command."""
        from member_auxiliary import digest
        from staging import read_file
        require(operation in ('initialize', 'readback', 'service-smoke')
                and isinstance(scope, dict) and set(scope) == {'run_id', 'bundle_sha256', 'started_at', 'deadline_at'}
                and scope['run_id'] == self.run_id
                and isinstance(scope['bundle_sha256'], str) and re.fullmatch(r'[a-f0-9]{64}', scope['bundle_sha256'])
                and type(scope['started_at']) is int and scope['started_at'] > 0
                and type(scope['deadline_at']) is int and 1200 < scope['deadline_at'] - scope['started_at'] <= 7200,
                'transport_application_scope_invalid')
        require((service is not None) == (operation == 'service-smoke'), 'transport_application_service_invalid')
        if service is not None:
            require(isinstance(service, dict) and set(service) == {'uid', 'gid'}
                    and all(type(v) is int and 0 < v < 2**31 for v in service.values()), 'transport_application_service_invalid')
        binding = dict(scope=copy.deepcopy(scope), host=self.host)
        for path, key in ((self.identity, 'identity_sha256'), (self.known, 'known_hosts_sha256')):
            binding[key] = read_file(path, 1048576)[0]['sha256']
        prior_binding = self.load('application-binding')
        require(prior_binding is None or prior_binding == binding, 'transport_application_binding_changed')
        self.guard('application-' + operation, 'control')
        if prior_binding is None:
            self.save('application-binding', copy.deepcopy(binding))
            require(self.load('application-binding') == binding, 'transport_application_journal_unconfirmed')
        request = dict(schema=1, **scope, operation=operation, request_id=digest([scope, operation])[:32])
        prior = self.load('application-initialize')
        if operation == 'initialize':
            require(prior is None, 'transport_application_readback_required')
            intent = dict(request=request, completed=False)
            self.save('application-initialize', intent)
            require(self.load('application-initialize') == intent, 'transport_application_journal_unconfirmed')
        else:
            require(isinstance(prior, dict) and prior.get('request') == dict(schema=1, **scope,
                    operation='initialize', request_id=digest([scope, 'initialize'])[:32]), 'transport_application_intent_missing')
            if operation == 'service-smoke': require(prior.get('completed') is True, 'transport_application_readback_required')
        command = 'php /opt/keli-isolated/runtime/member_application_entry.php'
        if service is not None:
            command = ('setpriv --reuid=%d --regid=%d --clear-groups --no-new-privs --bounding-set=-all ' %
                       (service['uid'], service['gid'])) + command
        try:
            raw = self.execute(self._command(command), json.dumps(request, separators=(',', ':')).encode(),
                               180 if operation == 'initialize' else 45)
            require(isinstance(raw, bytes) and len(raw) <= LIMIT, 'transport_response_too_large')
            reply = unique_json(raw)
            require(isinstance(reply, dict) and set(reply) == set(request) | {'ok', 'result'}
                    and reply['ok'] is True and all(type(reply[k]) is type(v) and reply[k] == v for k, v in request.items()),
                    'transport_application_reply_changed')
            value = reply['result']
            require(isinstance(value, dict), 'transport_application_reply_changed')
            if service is None:
                require(set(value) == {'schema', 'run_id', 'bundle_sha256', 'environment_sha256', 'machine_id_sha256',
                        'migrated', 'migration_count', 'migrations_sha256', 'tables'} and value['schema'] == 1
                        and value['run_id'] == self.run_id and value['bundle_sha256'] == scope['bundle_sha256']
                        and value['migrated'] is True and all(type(value[k]) is int and value[k] > 0 for k in ('migration_count', 'tables'))
                        and all(isinstance(value[k], str) and re.fullmatch(r'[a-f0-9]{64}', value[k])
                                for k in ('environment_sha256', 'machine_id_sha256', 'migrations_sha256')),
                        'transport_application_reply_changed')
                evidence = digest(value)
                require(prior is None or prior.get('completed') is not True or prior.get('evidence_sha256') == evidence,
                        'transport_application_readback_changed')
                completed = dict(request=prior['request'] if prior else request, completed=True, evidence_sha256=evidence)
                self.save('application-initialize', copy.deepcopy(completed))
                require(self.load('application-initialize') == completed, 'transport_application_journal_unconfirmed')
            else:
                require(value == dict(ready=True, **service, kernel='App\\Http\\Kernel',
                    database_and_cache_reachable=True, executable_paths_read_only=True), 'transport_application_service_unconfirmed')
            return copy.deepcopy(value)
        except Exception as error:
            self._first_error('application-' + operation, 'control', error)
            if isinstance(error, TransportError): raise
            raise TransportError('transport_application_uncertain') from None

    def installation_call(self, request):
        """Cold Python installer over the same pinned SSH; never a caller command."""
        from member_host_installation import validate
        from member_install_bootstrap import BOOTSTRAP
        from member_auxiliary import digest
        from staging import read_file
        operation, scope = validate(request)
        require(scope['run_id'] == self.run_id, 'transport_host_install_scope')
        self.guard('host-installation-' + operation, 'control')
        upload = self.load('upload-intent')
        require(isinstance(upload, dict) and set(upload) == {'sha256', 'bytes'}
                and upload['sha256'] == scope['bundle_sha256'] and type(upload['bytes']) is int
                and 0 < upload['bytes'] <= 100 * 1024 * 1024, 'transport_host_install_upload_required')
        binding = dict(scope=copy.deepcopy(scope), host=self.host)
        for path, key in ((self.identity, 'identity_sha256'), (self.known, 'known_hosts_sha256')):
            binding[key] = read_file(path, 1048576)[0]['sha256']
        prior = self.load('host-installation-binding')
        require(prior is None or prior == binding, 'transport_host_install_binding_changed')
        if prior is None:
            self.save('host-installation-binding', copy.deepcopy(binding))
            require(self.load('host-installation-binding') == binding, 'transport_host_install_journal')
        mutation = operation in ('publish', 'configure', 'private')
        if mutation:
            require(self.load('host-installation-stopped') is None, 'transport_host_install_stopped')
            key = 'host-installation-' + operation
            require(self.load(key) is None, 'transport_host_install_readback_required')
            intent = dict(request_sha256=digest(request), completed=False)
            self.save(key, copy.deepcopy(intent))
            require(self.load(key) == intent, 'transport_host_install_journal')
        if operation == 'stop':
            self.save('host-installation-stopped', dict(binding=digest(binding)))
            require(self.load('host-installation-stopped') == dict(binding=digest(binding)), 'transport_host_install_journal')
        try:
            raw = self.execute(self._command('python3 -I -B -c ' + shlex.quote(BOOTSTRAP)),
                json.dumps(request, separators=(',', ':')).encode(), 180)
            require(isinstance(raw, bytes) and len(raw) <= 65536, 'transport_host_install_response')
            response = unique_json(raw)
            require(isinstance(response, dict) and set(response) == {'ok', 'request_sha256', 'result'}
                    and response['ok'] is True and response['request_sha256'] == digest(request),
                    'transport_host_install_response')
            result = response['result']
            require(isinstance(result, dict) and set(result) == {'completed_operations', 'uncertain_operations', 'private_files', 'stopped'}
                    and type(result['stopped']) is bool and isinstance(result['private_files'], list),
                    'transport_host_install_response')
            for field in ('completed_operations', 'uncertain_operations'):
                values = result[field]
                require(isinstance(values, list) and all(isinstance(v, str) and v in ('publish', 'configure', 'private') for v in values)
                        and len(set(values)) == len(values), 'transport_host_install_response')
            require(not set(result['completed_operations']).intersection(result['uncertain_operations']),
                    'transport_host_install_response')
            stages = ['publish', 'configure', 'private']
            require(result['completed_operations'] == stages[:len(result['completed_operations'])]
                    and result['uncertain_operations'] in ([], stages[len(result['completed_operations']):len(result['completed_operations']) + 1]),
                    'transport_host_install_response')
            from member_private_cleanup import validate_pin
            paths = set()
            for pin in result['private_files']:
                validate_pin(pin)
                require(pin['run_id'] == self.run_id and pin['path'] not in paths and pin['path'] in (
                    '/opt/keli-isolated/app/.env', '/opt/keli-isolated/private/credentials',
                    '/opt/keli-isolated/private/bootstrap-authority'), 'transport_host_install_response')
                paths.add(pin['path'])
            if mutation:
                require(not result['stopped'] and operation in result['completed_operations']
                        and not result['uncertain_operations'], 'transport_host_install_unconfirmed')
                self.save(key, dict(intent, completed=True, evidence_sha256=digest(result)))
                require(self.load(key) == dict(intent, completed=True, evidence_sha256=digest(result)),
                        'transport_host_install_journal')
            if operation == 'stop': require(result['stopped'], 'transport_host_install_stop_unconfirmed')
            return copy.deepcopy(result)
        except Exception as error:
            self._first_error('host-installation-' + operation, 'control', error)
            if isinstance(error, TransportError): raise
            raise TransportError('transport_host_install_uncertain') from None

    def http_call(self, request):
        """Only the published, scoped transient callback service; no shell input."""
        from member_http_service import validate, unit, FILES, ROOT
        from member_auxiliary import digest
        from member_private_cleanup import validate_pin
        from staging import read_file
        operation, scope = validate(request)
        require(scope['run_id'] == self.run_id, 'transport_http_scope')
        self.guard('http-' + operation, 'control')
        binding = dict(scope=copy.deepcopy(scope), host=self.host)
        for path, key in ((self.identity, 'identity_sha256'), (self.known, 'known_hosts_sha256')):
            binding[key] = read_file(path, 1048576)[0]['sha256']
        require(self.load('host-installation-binding') == binding, 'transport_http_installation_required')
        prior = self.load('http-binding')
        require(prior is None or prior == binding, 'transport_http_binding_changed')
        if prior is None:
            self.save('http-binding', copy.deepcopy(binding))
            require(self.load('http-binding') == binding, 'transport_http_journal')
        if operation == 'start':
            require(self.load('http-start') is None and self.load('http-stop') is None
                    and self.load('host-installation-stopped') is None, 'transport_http_readback_required')
            require(self.load('application-binding') == binding
                    and (self.load('application-initialize') or {}).get('completed') is True,
                    'transport_http_application_required')
            intent = dict(request_sha256=digest(request), completed=False)
            self.save('http-start', intent)
            require(self.load('http-start') == intent, 'transport_http_journal')
        if operation == 'stop':
            self.save('http-stop', dict(binding=digest(binding)))
            require(self.load('http-stop') == dict(binding=digest(binding)), 'transport_http_journal')
        try:
            raw = self.execute(self._command('python3 -B /opt/keli-isolated/runtime/member_http_service.py'),
                json.dumps(request, separators=(',', ':')).encode(), 120)
            require(isinstance(raw, bytes) and len(raw) <= 65536, 'transport_http_response')
            reply = unique_json(raw)
            require(set(reply) == {'ok', 'request_sha256', 'result'} and reply['ok'] is True
                    and reply['request_sha256'] == digest(request), 'transport_http_response')
            result = reply['result']
            require(isinstance(result, dict) and set(result) == {'state', 'unit', 'private_files'}
                    and result['unit'] == unit(scope) and result['state'] in ('running', 'absent', 'stopped')
                    and isinstance(result['private_files'], list), 'transport_http_response')
            paths = set()
            for pin in result['private_files']:
                validate_pin(pin)
                require(pin['run_id'] == self.run_id and pin['path'] in [ROOT + '/' + p for p in FILES.values()]
                        and pin['path'] not in paths, 'transport_http_response')
                paths.add(pin['path'])
            if operation == 'start':
                require(result['state'] == 'running' and len(paths) == 2 and all(
                    next(p['sha256'] for p in result['private_files'] if p['path'] == ROOT + '/' + FILES[name]) == pin['sha256']
                    for name, pin in request['files'].items()), 'transport_http_unconfirmed')
                self.save('http-start', dict(intent, completed=True, evidence_sha256=digest(result)))
                require(self.load('http-start') == dict(intent, completed=True, evidence_sha256=digest(result)), 'transport_http_journal')
            if operation == 'stop': require(result['state'] == 'stopped', 'transport_http_stop_unconfirmed')
            return copy.deepcopy(result)
        except Exception as error:
            self._first_error('http-' + operation, 'control', error)
            if isinstance(error, TransportError): raise
            raise TransportError('transport_http_uncertain') from None

    def private_call(self, operation, pin):
        """Fixed root-only file removal endpoint; no arbitrary path or shell input."""
        from member_private_cleanup import validate_pin
        from member_auxiliary import digest
        validate_pin(pin)
        require(operation in ('remove', 'inspect') and pin['run_id'] == self.run_id,
                'cleanup_private_operation_invalid')
        self.guard('private-' + operation, 'control')
        binding = self.load('bootstrap-binding')
        cold = self.load('host-installation-binding')
        if cold is not None:
            from member_host_installation import validate
            require(isinstance(cold, dict) and set(cold) == {'scope', 'host', 'identity_sha256', 'known_hosts_sha256'},
                    'transport_host_install_binding_changed')
            validate(dict(operation='journal-read', scope=cold['scope']))
            require(cold['scope']['run_id'] == self.run_id and self.load('host-installation-stopped')
                    == dict(binding=digest(cold)), 'cleanup_private_installation_not_stopped')
            pinned = dict(run_id=self.run_id, **{k: cold[k] for k in ('host', 'identity_sha256', 'known_hosts_sha256')})
            require(binding is None or binding == pinned, 'transport_bootstrap_binding_changed')
            binding = pinned
        require(isinstance(binding, dict) and binding.get('run_id') == self.run_id
                and binding.get('host') == self.host, 'transport_bootstrap_binding_changed')
        for file, key in ((self.identity, 'identity_sha256'), (self.known, 'known_hosts_sha256')):
            from staging import read_file
            _, raw, _ = read_file(file, 1048576, collect=True)
            require(hashlib.sha256(raw).hexdigest() == binding.get(key), 'transport_ssh_material_changed')
        try:
            raw = self.execute(self._command('python3 -B /opt/keli-isolated/runtime/member_private_cleanup.py'),
                json.dumps(dict(operation=operation, pin=pin), separators=(',', ':')).encode(), 45)
            require(isinstance(raw, bytes) and len(raw) <= LIMIT, 'transport_response_too_large')
            reply = unique_json(raw)
            require(isinstance(reply, dict) and set(reply) == {'ok', 'result'} and reply['ok'] is True,
                    'cleanup_private_unconfirmed')
            result = reply['result']
            require(isinstance(result, dict) and set(result) == {'pin_sha256', 'status'}
                    and result['pin_sha256'] == digest(pin)
                    and result['status'] in (('absent',) if operation == 'remove' else ('owned', 'absent')),
                    'cleanup_private_unconfirmed')
            return result
        except Exception as error:
            self._first_error('private-' + operation, 'control', error)
            if isinstance(error, TransportError): raise
            raise TransportError('cleanup_private_unconfirmed') from None

    def remove_private(self, pin):
        return self.private_call('remove', pin)

    def inspect_private(self, pin):
        return self.private_call('inspect', pin)

    def _first_error(self, operation, role, error):
        if self.load('transport-first-error') is None:
            self.save('transport-first-error', {'operation': operation, 'role': role,
                      'class': type(error).__name__, 'reason': reason(error, 'transport_invalid_response')})

    def upload(self, archive, expected_sha256):
        """Exclusive, hash-checked staging only. Never extracts or runs the uploaded package."""
        from staging import read_file
        self.guard('upload', 'control')
        require(re.fullmatch(r'[a-f0-9]{64}', expected_sha256), 'transport_bundle_hash_invalid')
        for file in (self.identity, self.known):
            require(file.is_file() and not file.is_symlink() and file.stat().st_nlink == 1, 'transport_key_or_hosts_missing')
        _, raw, _ = read_file(Path(archive), 100 * 1024 * 1024, collect=True)
        require(hashlib.sha256(raw).hexdigest() == expected_sha256, 'transport_bundle_changed')
        require(self.load('upload-intent') is None, 'transport_upload_reconcile_required')
        # The destination is fixed; no caller-supplied path or shell fragment is accepted.
        script = """import hashlib,json,os,pathlib,sys
p=pathlib.Path('/opt/keli-isolated/incoming')
assert p.is_dir() and p.resolve()==p and not p.is_symlink()
assert p.stat().st_mode & 0o077 == 0
data=sys.stdin.buffer.read(104857601)
assert len(data)<=104857600
h=hashlib.sha256(data).hexdigest()
assert h==sys.argv[1]
with (p/'r35-adapters.tar.gz').open('xb') as f:
 os.chmod(f.name,0o600);f.write(data);f.flush();os.fsync(f.fileno())
assert hashlib.sha256((p/'r35-adapters.tar.gz').read_bytes()).hexdigest()==h
print(json.dumps({'sha256':h,'bytes':len(data),'staged':True,'executed':False}))
"""
        self.save('upload-intent', {'sha256': expected_sha256, 'bytes': len(raw)})
        try:
            reply = self.execute(self._command('python3 -c ' + shlex.quote(script) + ' ' + expected_sha256), raw, 120)
            require(isinstance(reply, bytes) and len(reply) <= LIMIT, 'transport_response_too_large')
            result = unique_json(reply)
            require(result == {'sha256': expected_sha256, 'bytes': len(raw), 'staged': True, 'executed': False},
                    'transport_upload_readback_changed')
            return result
        except Exception as error:
            self._first_error('upload', 'control', error)
            if isinstance(error, TransportError):
                raise
            raise TransportError('transport_upload_uncertain') from None


class MemberCleanup:
    """Final teardown, distinct from A1's native cleanup/cooldown proof.

    Remote task operations use StrictControllerTransport. Auxiliary cleanup is the
    round's separately pinned fault/DNS/private-material/control adapter, never a
    prefix delete. Only explicit receipts release the final controller step.
    """
    def __init__(self, *, run_id, roles, call, auxiliary, save, clock=time.time, restored=None):
        require(set(roles) <= set(ROLES), 'cleanup_role_invalid')
        require(all(isinstance(v, dict) and re.fullmatch(r'[1-9][0-9]{0,15}', v.get('external_id', ''))
                    and re.fullmatch(r'[a-f0-9-]{36}', v.get('task_id', '')) for v in roles.values()), 'cleanup_pin_invalid')
        require(len({v['external_id'] for v in roles.values()}) == len(roles), 'cleanup_identity_reused')
        self.roles, self.call, self.auxiliary, self.save, self.clock = copy.deepcopy(roles), call, auxiliary, save, clock
        digest = hashlib.sha256(json.dumps([run_id, roles], sort_keys=True).encode()).hexdigest()
        self.state = copy.deepcopy(restored) if restored is not None else {'binding': digest, 'reads': {}, 'delete_intents': [], 'phase': 'restore',
                                  'first_error': None, 'last_at': int(clock()), 'control_reads': []}
        require(self.state.get('binding') == digest, 'cleanup_resume_changed')

    def step(self):
        if self.state['phase'] == 'closed':
            return self.state
        try:
            now = int(self.clock())
            require(now >= self.state['last_at'], 'cleanup_clock_rollback')
            self.state['last_at'] = now
            if self.state['phase'] == 'restore':
                require(self.auxiliary('restore-faults').get('restored') is True, 'cleanup_restore_unconfirmed')
                self.state['phase'] = 'tasks'
            if self.state['phase'] == 'tasks':
                for role in ('candidate-a2', 'candidate-a1', 'source-a', 'source-b'):
                    if role not in self.roles:
                        continue
                    pin = self.roles[role]
                    reads = self.state['reads'].setdefault(role, [])
                    if reads and int(self.clock()) - reads[-1] < 10:
                        continue
                    receipt = self.call('task-inspect', role)
                    observed_at = int(self.clock())
                    require(observed_at >= self.state['last_at'], 'cleanup_clock_rollback')
                    self.state['last_at'] = observed_at
                    require(all(receipt.get(k) == pin[k] for k in ('task_id', 'external_id')), 'cleanup_receipt_changed')
                    if receipt.get('absent') is True:
                        reads[:] = (reads + [observed_at])[-2:]
                    else:
                        reads.clear()
                        require(receipt.get('absent') is False, 'cleanup_absence_unknown')
                        if role not in self.state['delete_intents']:
                            self.state['delete_intents'].append(role)
                            self.save(self.state)
                            self.call('task-delete', role)
                if all(len(self.state['reads'].get(r, [])) == 2 for r in self.roles):
                    self.state['phase'] = 'auxiliary'
            if self.state['phase'] == 'auxiliary':
                result = self.auxiliary('dns-and-private-cleanup')
                if result.get('pending') is True:
                    self.save(self.state)
                    return self.state
                require(all(result.get(k) is True for k in ('dns_absent_twice', 'private_materials_removed',
                            'intents_reconciled')), 'cleanup_auxiliary_unconfirmed')
                # Failure to export evidence must fail acceptance, not keep cloud resources billed.
                self.state['evidence_saved_and_read_back'] = result.get('evidence_saved_and_read_back') is True
                if not self.state['evidence_saved_and_read_back']:
                    self.state['first_error'] = self.state['first_error'] or {
                        'class': 'TransportError', 'reason': 'cleanup_evidence_unconfirmed'}
                self.state['phase'] = 'control'
                self.save(self.state)
            if self.state['phase'] == 'control':
                # The auxiliary adapter persists its own one-shot delete intent. A lost
                # controller response cannot imply its cloud instance is already absent.
                reads = self.state['control_reads']
                if reads and int(self.clock()) - reads[-1] < 10:
                    self.save(self.state)
                    return self.state
                receipt = self.auxiliary('control-delete-or-inspect')
                observed_at = int(self.clock())
                require(observed_at >= self.state['last_at'], 'cleanup_clock_rollback')
                self.state['last_at'] = observed_at
                require(receipt.get('exact_identity') is True, 'cleanup_control_identity_unknown')
                if receipt.get('absent') is True:
                    reads[:] = (reads + [observed_at])[-2:]
                    if len(reads) == 2:
                        self.state['phase'] = 'closed'
                else:
                    self.state['control_reads'] = []
        except Exception as error:
            # Invalid/failed reads break consecutive absence confirmation.
            if self.state['phase'] == 'tasks':
                self.state['reads'] = {}
            if self.state['phase'] == 'control':
                self.state['control_reads'] = []
            self.state['first_error'] = self.state['first_error'] or {
                'class': type(error).__name__, 'reason': reason(error, 'cleanup_transport_failed')}
        self.save(self.state)
        return self.state
