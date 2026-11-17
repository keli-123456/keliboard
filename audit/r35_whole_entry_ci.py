"""Continue the actual cold-install prefix; only infrastructure IO is simulated."""
import hashlib
import http.client
import json
import socket
import ssl
import subprocess
import time


def continue_entry(*, initialization, transport, remote, scope, creation_directory, root,
                   repo, env, certificate, domain, node_sha, journal, php, artifacts, summary):
    from member_cli import BoundControllerTransport
    from member_initialization import STAGES
    from member_transport import TransportError
    from r35_retry_flow_ci import run_retry_flow

    result = subprocess.run(['php', str(repo / 'audit/r35-whole-context.php'), str(creation_directory)],
        cwd=root, env=env, capture_output=True, timeout=45)
    assert result.returncode == 0, 'actual_prefix_context_binding_failed'
    assert json.loads(result.stdout) == dict(bound=True, application_tasks=0, provider_records=1)
    previous_execute = transport.execute

    def execute(args, payload, timeout):
        for value in ('BatchMode=yes', 'IdentitiesOnly=yes', 'StrictHostKeyChecking=yes', 'UpdateHostKeys=no'):
            assert value in args
        assert args[-2] == 'root@9.9.9.10'
        if args[-1] == 'php /opt/keli-isolated/runtime/member_setup_entry.php':
            mode = 'setup-host-rpc'
        elif args[-1] == 'php /opt/keli-isolated/runtime/member_transport_entry.php':
            mode = 'setup-retry-cleanup-rpc' if summary.get('native_retry_teardown_started') else 'setup-flow-rpc'
        else:
            return previous_execute(args, payload, timeout)
        process = subprocess.run(['php', '-d', 'display_errors=0', '-d', 'log_errors=0',
            str(root / 'app/tests/Fixtures/member_full_application.php'), mode], input=payload,
            cwd=root / 'app', env=env, capture_output=True, timeout=timeout)
        if process.returncode != 0:
            value = json.loads(process.stdout)
            summary.setdefault('whole_entry_rpc_first_error', {k: value.get(k) for k in ('reason', 'class', 'file', 'line')})
            raise TransportError('transport_remote_failed')
        return process.stdout

    transport.execute = execute
    tls = ssl.create_default_context(cafile=str(certificate))

    class Client(http.client.HTTPSConnection):
        def connect(self):
            self.sock = tls.wrap_socket(socket.create_connection(('127.0.0.1', 443), 10), server_hostname=domain)

    pins = {}
    probe = php('setup-probe-configuration')
    assert probe['enabled'] and len(probe['targets']) == 4

    def request(endpoint, body, headers):
        connection = Client(domain, 443, timeout=15)
        connection.request('POST', endpoint, json.dumps(body), dict(headers, **{'Content-Type': 'application/json'}))
        response = connection.getresponse()
        raw = response.read(65536)
        code = response.status
        connection.close()
        assert all(p['secret'].encode() not in raw and p['token'].encode() not in raw for p in pins.values())
        return code

    def callback(*, role):
        pin = pins[role]
        return request('/api/v2/server/machine/provision/result', dict(task_id=pin['task_id'],
            installation_id=pin['installation_id'], state='succeeded'), {'Authorization': 'Bearer ' + pin['secret']})

    def report(role, *, node=False):
        pin = pins[role]
        body = dict(machine_id=pin['machine_id'], token=pin['token'])
        stats = dict(cpu=1, mem=dict(total=8192, used=128), swap=dict(total=0, used=0), disk=dict(total=16384, used=256))
        if node:
            body.update(node_id=pin['node_id'], node_type='v2node', **stats)
            endpoint = '/api/v2/server/status'
        else:
            body['status'] = dict(stats, version='v0.1.387', ip=dict(public_ipv4=pin['ip']),
                runtime=dict(binary_sha256=node_sha, observation=dict(schema=1,
                    session=hashlib.sha256(role.encode()).hexdigest()[:32], pid=42,
                    started_at=pin['dispatched_at'], uptime_seconds=max(1, int(time.time()) - pin['dispatched_at'])),
                    node_statuses=[dict(node_id=pin['node_id'], protocol='hysteria2', server_port=24443, status='configured')]),
                entry_probe=dict(schema=1, revision=probe['revision'], checked_at=int(time.time()), state='ready',
                    targets=[dict(id=t['id'], source_ip=pin['ip'],
                        status='unreachable' if role in ('source-a', 'candidate-a1') else 'reachable') for t in probe['targets']]))
            endpoint = '/api/v2/server/machine/status'
        return request(endpoint, body, {'X-Keli-Instance-Id': pin['installation_id']})

    deadline = time.monotonic() + 1000
    transitions = []
    last = None
    while initialization.state['index'] < len(STAGES):
        assert time.monotonic() < deadline, 'actual_initialization_deadline'
        php('setup-callback-pins')
        for role in ('source-a', 'source-b'):
            path = root / ('private/setup-fixture/' + role + '-callback.json')
            if role not in pins and path.exists():
                pins[role] = json.loads(path.read_bytes())
                assert callback(role=role) == callback(role=role) == 200
            if role in pins:
                assert report(role) == report(role, node=True) == 200
        before = initialization.state['index']
        state = initialization.step()
        status = transport.setup_call('status', journal['remote-setup-identity'])
        current = (before, tuple((r, v['status'], v['fresh_reports']) for r, v in status['sources'].items()))
        summary['whole_entry_last_stage'] = dict(stage=STAGES[before], sources={
            r: {k: v[k] for k in ('status', 'fresh_reports', 'registered')} for r, v in status['sources'].items()})
        if current != last:
            transitions.append(dict(at=int(time.time()), stage=STAGES[before], source_state=current[1]))
            last = current
        if initialization.state['index'] == before:
            time.sleep(5.1)
    assert set(pins) == {'source-a', 'source-b'}
    assert pins['source-b']['created_at'] - pins['source-a']['created_at'] >= 300
    assert list(initialization.state['receipts']) == list(STAGES)
    assert remote.grant_file.read_bytes() == (root / 'private/member-runtime-scope.json').read_bytes()
    transitions_path = artifacts / 'whole-initialization-transitions.json'
    transitions_path.write_text(json.dumps(transitions, sort_keys=True) + '\n')
    transitions_path.chmod(0o644)
    summary['whole_initialization'] = dict(actual_cold_prefix_and_all_stages=True, stages=list(STAGES),
        stage_receipts_synthetic=False, source_tasks_actual_mysql=True, callback_and_agent_http_tls=True,
        synthetic_agent_payloads=True, real_source_serial_cooldown_seconds=300,
        source_create_gap_seconds=pins['source-b']['created_at'] - pins['source-a']['created_at'],
        native_grant_from_actual_setup=True, cloud_ssh_dns_simulated=True, real_node_binary_execution=False)
    bound = BoundControllerTransport(grant_file=remote.grant_file,
        grant_sha256=initialization.state['receipts']['native-grant']['grant_sha256'],
        run_id=scope['run_id'], host='9.9.9.10', identity_file=transport.identity, known_hosts=transport.known,
        guard=lambda *_: None, load=journal.get, save=lambda k, v: journal.__setitem__(k, v), execute=execute)
    run_retry_flow(bound=bound, php=php, report=report, callback=callback, pins=pins,
        root=root, artifacts=artifacts, summary=summary)
    summary['native_retry_teardown_started'] = True
    return bound
