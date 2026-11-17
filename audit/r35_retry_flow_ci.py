"""Temporary full-flow CI driver; providers and telemetry are synthetic, clocks are real."""
import hashlib
import json
import time


def run_retry_flow(*, bound, php, report, callback, pins, root, artifacts, summary):
    fixture = root / 'private/setup-fixture'
    seen = set()
    transitions = []
    deadline = time.monotonic() + 1000
    last_phase = None
    cooldown_start = None
    cooldown_checked = False
    all_attempts = []
    try:
        assert bound.call('round-ready')['ready']
        while True:
            assert time.monotonic() < deadline, 'native_flow_deadline'
            # Keep real HTTP observations fresh during all actual native waits.
            for role in ('source-a', 'source-b', *sorted(seen)):
                if role == 'candidate-a1' and all_attempts and (
                        all_attempts[0]['status'] == 'cleaned' or (all_attempts[0]['cleanup'] or {}).get('delete_dispatched_at')):
                    continue
                assert report(role) == report(role, node=True) == 200, role
            state = bound.call('round-step')
            snapshot = php('setup-flow-inspect')
            all_attempts = snapshot['attempts']
            phase = (state['native_phase'], snapshot['member_phase'], snapshot['member_reason'])
            if phase != last_phase:
                transitions.append(dict(at=int(time.time()), phase=list(phase), tasks=snapshot['task_count']))
                last_phase = phase
            summary['native_retry_last_state'] = dict(state=state, snapshot=snapshot)
            assert state['phase'] != 'failed', summary['native_retry_last_state']
            assert snapshot['b_unchanged'] and snapshot['provider_first_error'] is None, snapshot
            assert len(all_attempts) <= 2
            for attempt in all_attempts:
                role = 'candidate-a' + str(attempt['ordinal'])
                if role not in seen and attempt['dispatched_at']:
                    php('setup-candidate-pins')
                    pins[role] = json.loads((fixture / (role + '-callback.json')).read_bytes())
                    assert callback(role=role) == callback(role=role) == 200
                    seen.add(role)
            if all_attempts and all_attempts[0]['status'] == 'cleaned':
                cleanup = all_attempts[0]['cleanup']
                assert cleanup['state'] == 'confirmed' and cleanup['failures'] >= cleanup['threshold']
                assert len(cleanup['absent_at']) == 2 and cleanup['absent_at'][1] - cleanup['absent_at'][0] >= 10
                assert cleanup['retry_at'] - cleanup['confirmed_at'] == 300
                if cooldown_start is None: cooldown_start = time.monotonic()
                if time.time() < cleanup['retry_at']:
                    assert len(all_attempts) == 1 and snapshot['task_count'] == 3
                    assert snapshot['delete_calls'] == 1 and snapshot['dns_updates'] == 0
                    cooldown_checked = True
                if len(all_attempts) == 2:
                    assert cooldown_checked and int(snapshot['create_events']['100004']) >= cleanup['retry_at']
                    assert all_attempts[1]['dispatched_at'] >= cleanup['retry_at']
            if state['phase'] == 'finished':
                assert seen == {'candidate-a1', 'candidate-a2'} and len(all_attempts) == 2
                for branch in ('native_cleanup', 'cooldown', 'retry', 'b_unchanged', 'dns_cutover'):
                    assert state['branches'][branch] is True, state
                assert snapshot['task_count'] == 4 and snapshot['delete_calls'] == 1 and snapshot['dns_updates'] == 1
                assert len(snapshot['create_events']) == 4
                break
            time.sleep(5.1)
        assert php('setup-queues')['no_redis_queued_delayed_or_reserved_jobs']
        assert bound.call('round-stop')['stopped']
        evidence = bound.call('round-evidence')
        assert hashlib.sha256(evidence['content'].encode()).hexdigest() == evidence['sha256']
        body = json.loads(evidence['content'])
        assert body['reconciled'] and not body['driver']['first_error_present']
        (artifacts / 'native-retry-evidence.json').write_text(json.dumps(evidence, sort_keys=True) + '\n')
        summary['native_retry_flow'] = dict(actual_root_rpc_and_native_state_machine=True,
            actual_mysql_redis_http_tls_and_service_uid=True, provider_and_ssh_side_effects_simulated=True,
            synthetic_telemetry_not_real_ping=True, task_count=4, candidate_attempts=2,
            provider_creates=4, a1_exact_delete_calls=1, absence_reads_minimum_gap_seconds=10,
            real_cooldown_seconds=300, no_early_a2_create=True, a2_dns_updates=1,
            b_task_identity_provider_and_dns_unchanged=True, first_error_absent=True,
            stopped_evidence_sha256=evidence['sha256'], automatic_switches_changed=False,
            final_native_teardown_not_covered=True)
    finally:
        (artifacts / 'native-retry-transitions.json').write_text(json.dumps(transitions, sort_keys=True) + '\n')
