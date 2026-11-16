"""Real fixed-path CLI checks, explicitly gated to a disposable root namespace.

No cloud credentials, SSH server, provider call or actual execution round exists.
The positive entry exercises stop only; native transitions use deny-stray fakes.
"""
import copy
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys
import time
import unittest

if sys.platform == 'linux':
    import fcntl

ROOT = Path('/opt/keli-isolated')
RUN = 'keli-accept-20261116-r35-f4940b41'
RUNTIME = ('member_transport_entry.php', 'member_cli_authority.php', 'member_round_bridge.php',
           'member_retry_driver.php', 'member_controller_bridge.php', 'member_source_registry.php')
PROTECTED = ('bootstrap/app.php', 'vendor/autoload.php', '.env', 'config/database.php', 'config/cache.php',
    'config/machine_failover.php', 'config/machine_acceptance.php', 'config/machine_provision.php',
    'app/Services/ServerMachine/Cloud/MachineFailoverExecutionGate.php',
    'app/Services/ServerMachine/Cloud/MachineFailoverNativeBridge.php',
    'app/Services/ServerMachine/Cloud/MachineCandidateRetry.php',
    'app/Services/ServerMachine/Cloud/MachineCandidateCleanupClient.php')


def sha(path): return hashlib.sha256(path.read_bytes()).hexdigest()


@unittest.skipUnless(sys.platform == 'linux' and os.environ.get('KELI_MEMBER_CLI_ENTRY') == '1',
                     'requires disposable fixed-path Linux fixture')
class MemberCliEntryLinuxTests(unittest.TestCase):
    def setUp(self):
        self.assertEqual(os.geteuid(), 0)
        self.assertEqual(ROOT.resolve(), ROOT)
        self.assertEqual(subprocess.check_output(['ip', 'route']).strip(), b'')
        self.assertEqual(subprocess.check_output(['ip', '-6', 'route']).strip(), b'')
        self.private = ROOT / 'private'; self.private.mkdir(mode=0o700, exist_ok=True)
        self.journal = self.private / 'member-journal'; self.journal.mkdir(mode=0o700, exist_ok=True)
        for p in self.journal.iterdir():
            self.assertTrue(p.is_file()); p.unlink()
        self.grant = self.private / 'member-runtime-scope.json'
        self.env = ROOT / 'app/.env'
        self.env.write_text('APP_ENV=acceptance\nAPP_DEBUG=false\nAPP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=\n'
            'DB_CONNECTION=mysql\nDB_HOST=127.0.0.1\nDB_PORT=1\nDB_DATABASE=keli_acceptance_r35\n'
            'DB_USERNAME=synthetic\nDB_PASSWORD=synthetic\nCACHE_DRIVER=redis\nCACHE_REDIS_CONNECTION=cache\n'
            'REDIS_HOST=127.0.0.1\nREDIS_PORT=1\nREDIS_CACHE_PORT=1\n'
            'CACHE_PREFIX=' + RUN + ':\nLOG_CHANNEL=single\n')
        self.env.chmod(0o600)
        now = int(time.time())
        self.scope = dict(schema=2, execution_authorized=True, isolated=True, run_id=RUN,
            grant_id='a' * 32, bundle_sha256='b' * 64, controller_machine_id_sha256=sha(Path('/etc/machine-id')),
            started_at=now - 2, deadline_at=now + 7198, database='keli_acceptance_r35',
            control={'external_id': '800'}, sources={},
            driver=dict(run_id=RUN, workflow='agent_readiness_v1', admin_id=1, account_id=1, service_id=1,
                        service_revision=1, release_id=1, plan_id=1, policy_a=1, policy_b=2, baseline_ids=[]))
        for i, role in enumerate(('source-a', 'source-b'), 1):
            self.scope['sources'][role] = dict(external_id=str(900 + i), machine_id=i,
                installation_id=str(i) * 32, task_id='00000000-0000-4000-8000-' + str(i).zfill(12))
        self.scope['runtime_sha256'] = {n: sha(ROOT / 'runtime' / n) for n in RUNTIME}
        self.scope['application_sha256'] = {n: sha(ROOT / 'app' / n) for n in PROTECTED}
        self.write_grant()

    def tearDown(self):
        if self.grant.is_symlink() or self.grant.exists(): self.grant.unlink()

    def write_grant(self):
        self.grant.write_text(json.dumps(self.scope, separators=(',', ':')))
        self.grant.chmod(0o600)

    def request(self, operation='round-stop'):
        return dict(schema=2, run_id=RUN, grant_id=self.scope['grant_id'], grant_sha256=sha(self.grant),
                    bundle_sha256=self.scope['bundle_sha256'], request_id='d' * 32, sequence=1,
                    operation=operation, role='control')

    def invoke(self, request=None):
        r = request or self.request()
        p = subprocess.run(['php', '-d', 'disable_functions=curl_exec,fsockopen,pfsockopen,stream_socket_client',
            str(ROOT / 'runtime/member_transport_entry.php')], input=json.dumps(r), text=True,
            capture_output=True, timeout=20, cwd=ROOT / 'app',
            env={'PATH': '/usr/local/bin:/usr/bin:/bin', 'HOME': '/root', 'LANG': 'C.UTF-8'})
        try: reply = json.loads(p.stdout)
        except ValueError: self.fail('entry output is not structured: ' + p.stdout[:1500] + p.stderr[:1500])
        return p.returncode, reply

    def denied(self, request=None):
        rc, reply = self.invoke(request)
        self.assertEqual(rc, 1, reply); self.assertFalse(reply['ok'], reply)
        self.assertEqual(reply['reason'], 'isolated_operation_rejected')
        self.assertNotIn('/opt/', json.dumps(reply)); self.assertNotIn('synthetic', json.dumps(reply))
        self.assertFalse((self.journal / 'round-stop.json').exists())
        return reply

    def test_authorized_stop_survives_cold_boot_and_is_idempotent_across_processes(self):
        request = self.request()
        for _ in range(2):
            rc, reply = self.invoke(request)
            self.assertEqual(rc, 0, reply)
            self.assertEqual(reply, dict(request, ok=True, result={'stopped': True}))
        self.assertEqual(json.loads((self.journal / 'round-stop.json').read_text()), {'grant_id': 'a' * 32, 'stopped': True})
        rc, reply = self.invoke(self.request('round-step'))
        self.assertEqual(rc, 1); self.assertEqual(reply['diagnostic']['file'], 'member_cli_authority.php')

    def test_expiry_blocks_new_work_but_preserves_stop(self):
        now = int(time.time()); self.scope.update(started_at=now - 7202, deadline_at=now - 2); self.write_grant()
        self.denied(self.request('round-step'))
        self.assertEqual(self.invoke()[0], 0)

    def test_missing_grant_and_wrong_permissions_fail_before_boot(self):
        request = self.request(); self.grant.unlink(); self.denied(request)
        self.write_grant(); self.grant.chmod(0o644); self.denied()

    def test_symlink_and_hardlink_grants_are_rejected(self):
        request = self.request(); target = self.private / 'synthetic-grant-target'
        self.grant.rename(target)
        try:
            self.grant.symlink_to(target); self.denied(request); self.grant.unlink()
            os.link(target, self.grant); self.denied(request); self.grant.unlink()
        finally:
            target.unlink()

    def test_machine_and_rpc_authority_are_bound(self):
        original = copy.deepcopy(self.scope)
        self.scope['controller_machine_id_sha256'] = 'e' * 64; self.write_grant(); self.denied()
        self.scope = original; self.write_grant()
        request = self.request(); request['grant_id'] = 'f' * 32; self.denied(request)
        request = self.request(); request['healthy'] = True; self.denied(request)

    def test_runtime_tamper_and_cached_configuration_are_rejected(self):
        self.scope['runtime_sha256']['member_retry_driver.php'] = 'f' * 64; self.write_grant(); self.denied()
        self.scope['runtime_sha256']['member_retry_driver.php'] = sha(ROOT / 'runtime/member_retry_driver.php'); self.write_grant()
        cache = ROOT / 'app/bootstrap/cache/config.php'
        self.assertFalse(cache.exists()); cache.write_text('<?php return [];')
        try: self.denied()
        finally: cache.unlink()

    def test_nonisolated_database_cache_url_or_open_switch_is_rejected(self):
        original = self.env.read_text()
        for extra in ('DB_HOST=192.0.2.99\n', 'REDIS_URL=redis://192.0.2.99\n',
                      'CACHE_REDIS_CONNECTION=default\n', 'MACHINE_FAILOVER_PREVIEW_ENABLED=true\n'):
            with self.subTest(extra=extra.split('=')[0]):
                key = extra.split('=')[0] + '='
                self.env.write_text('\n'.join(line for line in original.splitlines() if not line.startswith(key)) + '\n' + extra)
                self.scope['application_sha256']['.env'] = sha(self.env); self.write_grant()
                self.denied()

    def test_file_lock_excludes_a_concurrent_rpc_without_dispatch(self):
        with (self.journal / 'rpc.lock').open('a+b') as lock:
            os.chmod(lock.name, 0o600)
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            self.denied()
        self.assertEqual(self.invoke()[0], 0)


if __name__ == '__main__': unittest.main()
