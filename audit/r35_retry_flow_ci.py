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
        evidence_path = artifacts / 'native-retry-evidence.json'
        evidence_path.write_text(json.dumps(evidence, sort_keys=True) + '\n')
        evidence_path.chmod(0o644)
        summary['native_retry_flow'] = dict(actual_root_rpc_and_native_state_machine=True,
            actual_mysql_redis_http_tls_and_service_uid=True, provider_and_ssh_side_effects_simulated=True,
            synthetic_telemetry_not_real_ping=True, task_count=4, candidate_attempts=2,
            provider_creates=4, a1_exact_delete_calls=1, absence_reads_minimum_gap_seconds=10,
            real_cooldown_seconds=300, no_early_a2_create=True, a2_dns_updates=1,
            b_task_identity_provider_and_dns_unchanged=True, first_error_absent=True,
            stopped_evidence_sha256=evidence['sha256'], automatic_switches_changed=False,
            final_native_teardown_not_covered=True)
    finally:
        transition_path = artifacts / 'native-retry-transitions.json'
        transition_path.write_text(json.dumps(transitions, sort_keys=True) + '\n')
        transition_path.chmod(0o644)


def run_retry_teardown(*, bound, rebind, php, callback, report, save, load, work, artifacts, summary):
    from member_cli import MemberControllerPort, RUN
    from member_evidence import MemberEvidenceArchive

    summary['native_retry_teardown_started'] = True
    port = MemberControllerPort(bound)
    scope = port('reconcile', dict(run_id=RUN))['cleanup_scope']
    assert set(scope['roles']) == {'source-a', 'source-b', 'candidate-a1', 'candidate-a2'}
    assert callback(role='candidate-a2') == report('candidate-a2') == report('candidate-a2', node=True) == 403
    before = php('setup-retry-cleanup-summary')
    assert before['provider_rows'] == 3 and before['delete_calls'] == 1
    assert before['dns_records_remaining'] == 3 and before['dns_update_calls'] == 1
    private_calls = []

    def pending_private(operation):
        if operation == 'restore-faults': return dict(restored=True)  # No fault was injected.
        assert operation == 'dns-and-private-cleanup', 'controller_removal_not_bound'
        private_calls.append(operation)
        # Do not manufacture a private-material/controller teardown receipt.
        return dict(pending=True)

    cleanup = port.cleanup_factory(pending_private, lambda v: save('retry-cleanup', v),
                                  native_dns=True)(scope, None)
    first = cleanup.step()
    error = json.loads(json.dumps(first['first_error']))
    assert error and first['phase'] == 'tasks' and not private_calls
    # Lose source A's DELETE reply, then reconstruct host transport/cleanup. A1
    # was already deleted natively and must not receive a second DELETE.
    bound = rebind(); port = MemberControllerPort(bound)
    cleanup = port.cleanup_factory(pending_private, lambda v: save('retry-cleanup', v),
                                  native_dns=True)(scope, load('retry-cleanup'))
    for _ in range(12):
        state = cleanup.step()
        assert state['first_error'] == error
        if load('native-dns-cleanup-confirmed') is not None:
            assert state['phase'] == 'auxiliary' and private_calls
            break
        assert not private_calls and state['phase'] in ('tasks', 'auxiliary')
        time.sleep(10.1)
    else: raise AssertionError('post_cutover_cleanup_unconfirmed')
    assert all(len(v) == 2 and v[1] - v[0] >= 10 for v in state['reads'].values())
    finished = php('setup-retry-cleanup-summary')
    assert finished == dict(task_history_unchanged=True, tasks=4, attempts=2, provider_rows=0,
        delete_calls=4, dns_records_remaining=0, dns_delete_calls=3, dns_update_calls=1, dns_create_calls=3), finished
    assert bound.call('round-dns-cleanup') == dict(complete=True,
        records={role: dict(complete=True) for role in ('control', 'edge-a', 'edge-b')})
    assert php('setup-retry-cleanup-summary') == finished
    archive_dir = work / 'retry-teardown-evidence'; archive_dir.mkdir(mode=0o700)
    archive = MemberEvidenceArchive(bound, archive_dir)
    exported = archive.capture(); body = archive.verify(exported)
    assert body['stopped'] and body['reconciled'] and body['cleanup_complete_not_asserted']
    assert set(body['tasks']) == set(scope['roles']) and body['setup']['first_error_present']
    assert body['setup']['first_diagnostic']['class'] == 'ConnectionException'
    assert not body['driver']['first_error_present']
    restarted = MemberEvidenceArchive(rebind(), archive_dir)
    assert restarted.capture() == exported and restarted.verify(exported) == body
    raw = (archive_dir / ('evidence-' + exported['sha256'] + '.json')).read_bytes()
    assert all(secret.encode() not in raw for secret in ('s' * 32, 'r' * 32))
    assert not any(key in raw for key in (b'api_token', b'access_key_secret', b'callback_hash', b'"payload"'))
    assert all(p.stat().st_mode & 0o777 == 0o600 for p in archive_dir.iterdir())
    path = artifacts / 'native-retry-teardown-evidence.json'
    path.write_bytes(raw); path.chmod(0o644)
    summary['native_retry_teardown'] = dict(actual_bound_host_cleanup=True, actual_native_a2_dns_receipt_resolution=True,
        source_candidate_exact_deletes_total=4, dns_exact_deletes_total=3, no_duplicate_delete=True,
        real_absence_read_gap_minimum_seconds=10, source_and_dns_delete_reply_loss_reconciled=True,
        local_dns_confirmation_blocks_early_private_cleanup=True, task_and_attempt_history_retained=True,
        first_error_retained=True, stopped_evidence_sha256=exported['sha256'], immutable_host_readback=True,
        post_stop_source_b_explicitly_cleaned=True, private_cleanup_pending=True, controller_last_cleanup_pending=True,
        whole_round_cleanup_not_claimed=True, cloud_ssh_dns_simulated=True)
