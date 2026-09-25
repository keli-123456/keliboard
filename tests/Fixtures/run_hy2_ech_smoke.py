"""Loopback-only panel-export/client ECH smoke; not real-panel SQL acceptance."""
import argparse
import importlib.util
import json
import os
from pathlib import Path
import re
import secrets
import socket
import socketserver
import struct
import subprocess
import threading
import time
import traceback
from urllib.parse import parse_qs, urlsplit
from urllib.request import ProxyHandler, Request, build_opener


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    for name in ['core', 'hysteria', 'sing-box', 'mihomo', 'php', 'php-ext', 'core-source', 'output']:
        parser.add_argument('--' + name, required=True, type=Path)
    args = parser.parse_args()
    out = args.output.resolve()
    out.mkdir(parents=True, exist_ok=False)
    spec = importlib.util.spec_from_file_location('fixture', args.core_source / 'scripts/hy2_ech_acceptance.py')
    fixture = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(fixture)
    result = {'passed': False, 'scope': 'current panel validation/model/export and real clients/core, not node scheduler or SQL billing', 'processes': [], 'cases': []}
    for name in ['core', 'hysteria', 'sing_box', 'mihomo']:
        result[name + '_sha256'] = fixture.sha256_file(getattr(args, name))
    children, logs, servers = [], [], []
    core = None
    flags = subprocess.CREATE_NO_WINDOW if os.name == 'nt' else 0
    control_port, hy2_port = fixture.port(), fixture.port(socket.SOCK_DGRAM)

    def write(name, data):
        path = out / name
        path.write_text(json.dumps(data, indent=2), encoding='utf-8')
        return path

    def spawn(name, command):
        log = (out / (name + '.log')).open('wb')
        logs.append(log)
        process = subprocess.Popen([str(x) for x in command], stdout=log, stderr=log, creationflags=flags)
        children.append((name, process))
        return process

    def run(name, command):
        process = spawn(name, command)
        assert process.wait(timeout=30) == 0, name

    def wait(check, timeout=15):
        deadline = time.monotonic() + timeout
        while time.monotonic() < deadline:
            try:
                value = check()
                if value:
                    return value
            except (ConnectionRefusedError, ConnectionResetError):
                pass
            time.sleep(.05)
        raise TimeoutError('bounded fixture condition not met')

    def control(request):
        with socket.create_connection(('127.0.0.1', control_port), timeout=4) as stream:
            stream.sendall(json.dumps(request).encode() + b'\n')
            return json.loads(stream.makefile('rb').readline(1048576))

    def listening(process, port):
        assert process.poll() is None, 'client exited'
        with socket.create_connection(('127.0.0.1', port), timeout=1):
            return True

    try:
        run('cert', [args.hysteria, '--disable-update-check', 'cert', '--host', 'ech.internal.test', '--valid-for', '2h', '--cert', out / 'server.crt', '--key', out / 'server.key'])
        run('ech', [args.hysteria, '--disable-update-check', 'ech', '--public-name', 'public.ech-test.invalid', '--output', out / 'ech.pem'])
        public = re.search(r'(?m)^\s+ech:\s+([A-Za-z0-9+/=]+)\s*$', (out / 'ech.log').read_text()).group(1)
        command = [str(args.php), '-d', 'extension_dir=' + str(args.php_ext), '-d', 'extension=mbstring', str(Path(__file__).with_name('export_hy2_ech.php'))]
        exported = subprocess.run(command, input=json.dumps({'config': public, 'port': hy2_port}), capture_output=True, text=True, timeout=20, creationflags=flags)
        (out / 'panel-export.stderr').write_text(exported.stderr)
        assert exported.returncode == 0, exported.stderr
        panel = json.loads(exported.stdout)
        write('panel-export.json', panel)
        assert panel['node']['network_settings']['ech_key_file'] == '/private/fixture/ech.pem'
        user = {'id': 7, 'uuid': '123e4567-e89b-12d3-a456-426614174000', 'password': 'fixture-password', 'email': None, 'speed_limit': 0, 'device_limit': 0}
        config = {'instance_id': 'panel-ech-smoke', 'log_level': 'info', 'outbounds': [], 'routes': [],
                  'stats': {'enabled': True, 'per_user': True}, 'inbounds': [{
                      'tag': 'hy2-ech', 'protocol': 'hysteria2', 'listen': '127.0.0.1', 'port': hy2_port, 'users': [user],
                      'sniffing': {'enabled': False, 'dest_override': []},
                      'transport': {'network': 'hysteria', 'proxy_protocol': False, 'ech_key_file': str(out / 'ech.pem')},
                      'tls': {'server_name': 'ech.internal.test', 'reject_unknown_sni': True, 'alpn': [], 'cert_file': str(out / 'server.crt'), 'key_file': str(out / 'server.key')}}]}
        core = spawn('core', [args.core, 'run-config', write('core.json', config), '--control', f'127.0.0.1:{control_port}'])
        wait(lambda: control({'type': 'status'})['status'] == 'running')
        tcp = socketserver.ThreadingTCPServer(('127.0.0.1', 0), fixture.TcpEcho)
        tcp.daemon_threads = True
        udp = socketserver.UDPServer(('127.0.0.1', 0), fixture.UdpEcho)
        udp.received = 0
        servers.extend([tcp, udp])
        for server in servers:
            threading.Thread(target=server.serve_forever, kwargs={'poll_interval': .05}, daemon=True).start()
        uri = urlsplit(panel['uri'].strip())
        query = parse_qs(uri.query)
        for name in ['sing-box', 'mihomo', 'hysteria-uri', 'legacy-plain']:
            proxy_port = fixture.port()
            if name == 'sing-box':
                outbound = panel['sing_box']
                outbound['tls']['certificate_path'] = str(out / 'server.crt')
                config = {'log': {'level': 'info'}, 'inbounds': [{'type': 'socks', 'listen': '127.0.0.1', 'listen_port': proxy_port}], 'outbounds': [outbound]}
                process = spawn(name, [args.sing_box, 'run', '-c', write(name + '.json', config)])
            elif name == 'mihomo':
                api_port, secret = fixture.port(), secrets.token_hex(24)
                config = {'socks-port': proxy_port, 'bind-address': '127.0.0.1', 'allow-lan': False, 'mode': 'rule', 'log-level': 'info', 'ipv6': False,
                          'geodata-mode': False, 'geo-auto-update': False,
                          'external-controller': f'127.0.0.1:{api_port}', 'secret': secret,
                          'tls': {'custom-certifactes': [(out / 'server.crt').read_text()]},
                          'proxies': [panel['mihomo']], 'rules': ['MATCH,' + panel['mihomo']['name']]}
                # Upstream creates proxy TLS configs before installing custom CAs.
                # Seed the test CA first, then load the unchanged exported proxy.
                seed = {**config, 'proxies': [], 'rules': ['MATCH,REJECT']}
                write(name + '.json', config)
                process = spawn(name, [args.mihomo, '-d', out / 'mihomo-home', '-f', write(name + '-seed.json', seed)])
                wait(lambda: listening(process, api_port))
                request = Request(f'http://127.0.0.1:{api_port}/configs?force=true',
                                  data=json.dumps({'payload': json.dumps(config)}).encode(), method='PUT',
                                  headers={'Authorization': 'Bearer ' + secret, 'Content-Type': 'application/json'})
                with build_opener(ProxyHandler({})).open(request, timeout=10) as response:
                    assert response.status == 204
                result['mihomo_test_ca'] = 'preloaded fixture CA, verified TLS, no insecure bypass'
            else:
                config = {'server': f'{uri.hostname}:{uri.port}', 'auth': uri.username,
                          'tls': {'sni': query['sni'][0], 'ca': str(out / 'server.crt'), 'insecure': False},
                          'socks5': {'listen': f'127.0.0.1:{proxy_port}'}}
                if name != 'legacy-plain': config['tls']['ech'] = query['ech'][0]
                process = spawn(name, [args.hysteria, '--disable-update-check', 'client', '-c', write(name + '.json', config)])
            wait(lambda: listening(process, proxy_port))
            with fixture.socks(proxy_port, 1, tcp.server_address)[0] as stream:
                payload = bytes(range(256)) * 256
                stream.sendall(payload)
                assert fixture.receive(stream, len(payload)) == payload
            udp_control, relay = fixture.socks(proxy_port, 3, ('0.0.0.0', 0))
            with udp_control, socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as datagram:
                datagram.bind(('127.0.0.1', 0))
                datagram.settimeout(5)
                packet = b'\0\0\0\1' + socket.inet_aton(udp.server_address[0]) + struct.pack('!H', udp.server_address[1]) + bytes(range(256)) * 2
                datagram.sendto(packet, relay)
                assert datagram.recvfrom(4096)[0] == packet
            result['cases'].append({'client': name, 'each_direction_bytes': 66048})
            process.terminate()
            process.wait(timeout=10)

        def drained():
            metrics = control({'type': 'metrics'})['metrics']
            result['metrics'] = metrics
            return metrics['keli_core_quic_resource']['active_connections'] == 0 and metrics['keli_core_hy2_udp_active_sessions'] == 0 and metrics['keli_core_connection_active_total'] == 0 and not any(metrics['keli_core_async_relay_active'].values())
        # Windows terminate kills the client without QUIC CONNECTION_CLOSE.
        # Allow the configured 30-second idle expiry, then require full cleanup.
        drain_started = time.monotonic()
        wait(drained, timeout=45)
        result['resource_cleanup_seconds'] = round(time.monotonic() - drain_started, 3)
        assert result['metrics']['keli_core_hy2_ech_accepted_total'] == 3
        assert result['metrics']['keli_core_hy2_ech_plain_total'] == 1
        assert result['metrics']['keli_core_hy2_ech_resumed_total'] == 0
        result['traffic'] = control({'type': 'drain_traffic', 'minimum_bytes': 0})['records']
        assert len(result['traffic']) == 1
        record = result['traffic'][0]
        assert record['user_id'] == 7 and record['user_uuid'] == user['uuid']
        assert record['upload'] == record['download'] == 264192
        assert control({'type': 'drain_traffic', 'minimum_bytes': 0})['records'] == []
        assert control({'type': 'stop'})['type'] == 'stopped'
        assert core.wait(timeout=10) == 0
        result['passed'] = True
    except BaseException:
        result['error'] = traceback.format_exc()
        if core and core.poll() is None:
            try:
                control({'type': 'stop'})
                core.wait(timeout=10)
            except Exception:
                pass
    finally:
        for server in servers:
            server.shutdown()
            server.server_close()
        for name, process in reversed(children):
            forced = process.poll() is None
            if forced:
                process.terminate()
                try: process.wait(timeout=5)
                except subprocess.TimeoutExpired:
                    process.kill()
                    process.wait(timeout=5)
            result['processes'].append({'name': name, 'exit_code': process.returncode, 'intentional_client_cleanup': name in ['sing-box', 'mihomo', 'hysteria-uri', 'legacy-plain'], 'cleanup_after_failure': forced})
        for log in logs: log.close()
        write('result.json', result)
    print(json.dumps({'passed': result['passed'], 'cases': result['cases'], 'error': result.get('error')}))
    return 0 if result['passed'] else 1


if __name__ == '__main__':
    raise SystemExit(main())
