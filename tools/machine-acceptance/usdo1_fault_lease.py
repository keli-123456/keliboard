"""Offline source fault lease policy, not an SSH command or firewall implementation.

The isolated host adapter must enforce the absolute deadlines with its own
monotonic clock and kernel expiry, even if the caller is killed or offline.
The caller holds the round's exclusive lock across inspection and dispatch.
"""
import ipaddress
import re

from driver_journal import require


def public_ipv4(ip):
    try:
        value = ipaddress.IPv4Address(ip)
    except (ValueError, TypeError):
        return False
    return isinstance(ip, str) and str(value) == ip and value.is_global and not value.is_multicast and not value.is_reserved


class Usdo1FaultLease:
    INTENT = 'usdo1-source-fault-intent.json'
    ACTIVE = 'usdo1-source-fault-verified.json'
    STOP = 'usdo1-source-fault-stop.json'
    LOSS_SECONDS = 90
    MAX_SECONDS = 900

    def __init__(self, *, journal, source, protected_ids, targets, cutoff, clock, inspect, apply, authorize):
        # All identity pins, targets and cutoff are from the frozen round, not a remote report.
        require(isinstance(protected_ids, (tuple, frozenset)) and bool(protected_ids)
                and all(isinstance(value, str) and re.fullmatch(r'[1-9][0-9]{0,15}', value) for value in protected_ids),
                'usdo1_fault_protected_instances_required')
        require(isinstance(source, dict) and set(source) == {'run_id', 'role', 'instance_id', 'installation_sha256', 'boot_id', 'ipv4'}
                and source['run_id'] == journal.run_id and source['role'] == 'new-source'
                and isinstance(source['instance_id'], str) and re.fullmatch(r'[1-9][0-9]{0,15}', source['instance_id'])
                and source['instance_id'] not in protected_ids
                and isinstance(source['installation_sha256'], str) and re.fullmatch(r'[a-f0-9]{64}', source['installation_sha256'])
                and isinstance(source['boot_id'], str) and re.fullmatch(r'[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}', source['boot_id'])
                and public_ipv4(source['ipv4']), 'usdo1_fault_source_invalid')
        require(isinstance(targets, (tuple, list)) and len(targets) == 4 and all(public_ipv4(ip) for ip in targets)
                and len(set(targets)) == 4 and source['ipv4'] not in targets and type(cutoff) is int and cutoff > 0,
                'usdo1_fault_scope_invalid')
        self.journal, self.source, self.targets, self.cutoff = journal, dict(source), tuple(targets), cutoff
        self.clock, self.inspect, self.apply, self.authorize = clock, inspect, apply, authorize

    def _permit(self, operation, **context):
        require(self.authorize(operation, dict(self.source), context) is True, 'usdo1_fault_guard_refused')

    def _load(self, name):
        try:
            return self.journal.load(name)
        except FileNotFoundError:
            return None

    def _save_once(self, name, data):
        self.journal.save(name, data, exclusive=True)
        require(self.journal.load(name) == data, 'usdo1_fault_journal_unconfirmed')

    def _intent(self):
        intent = self._load(self.INTENT)
        if intent is None:
            return None
        require(set(intent) == {'source', 'targets', 'started_at', 'hard_until', 'lease_until'}
                and intent['source'] == self.source and intent['targets'] == list(self.targets)
                and all(type(intent[k]) is int for k in ('started_at', 'hard_until', 'lease_until'))
                and intent['hard_until'] == min(intent['started_at'] + self.MAX_SECONDS, self.cutoff)
                and intent['lease_until'] == min(intent['started_at'] + self.LOSS_SECONDS, intent['hard_until'])
                and 0 <= intent['started_at'] < intent['lease_until'], 'usdo1_fault_intent_changed')
        return intent

    def _inspect(self, intent):
        # A missing or unreadable host is an error, not an authoritative absence.
        self._permit('inspect')
        state = self.inspect(dict(self.source))
        require(isinstance(state, dict) and state.get('source') == self.source, 'usdo1_fault_readback_invalid')
        if set(state) == {'source', 'absent'} and state['absent'] is True:
            return None
        require(intent is not None and set(state) == {'source', 'targets', 'hard_until', 'lease_until', 'scope_verified'}
                and state['scope_verified'] is True and state['targets'] == list(self.targets)
                and type(state['hard_until']) is int and state['hard_until'] == intent['hard_until']
                and (state['lease_until'] is None or type(state['lease_until']) is int
                     and intent['started_at'] < state['lease_until'] <= intent['hard_until']), 'usdo1_fault_readback_invalid')
        return state

    def start(self, observation):
        require(self._intent() is None and self._load(self.STOP) is None, 'usdo1_fault_already_used')
        require(isinstance(observation, dict) and observation.get('source') == self.source
                and isinstance(observation.get('results'), dict) and set(observation['results']) == set(self.targets)
                and all(value in ('reachable', 'unreachable') for value in observation['results'].values())
                and 'reachable' in observation['results'].values(), 'usdo1_fault_healthy_observation_required')
        self._permit('arm', observation=observation)
        require(self._inspect(None) is None, 'usdo1_fault_absence_required')
        now = self.clock()
        require(type(now) is int and 0 <= now < self.cutoff, 'usdo1_fault_deadline')
        intent = {'source': dict(self.source), 'targets': list(self.targets), 'started_at': now,
                  'hard_until': min(now + self.MAX_SECONDS, self.cutoff), 'lease_until': min(now + self.LOSS_SECONDS, self.cutoff)}
        self._save_once(self.INTENT, intent)
        self._permit('arm', observation=observation)
        require(self.clock() < intent['lease_until'] and self._inspect(None) is None, 'usdo1_fault_deadline_or_drift')
        require(self.apply('arm', intent) is True, 'usdo1_fault_arm_unconfirmed')
        state = self._inspect(intent)
        require(state is not None and state['lease_until'] == intent['lease_until'] > self.clock(), 'usdo1_fault_arm_unconfirmed')
        self._save_once(self.ACTIVE, intent)
        return state['lease_until']

    def renew(self):
        intent = self._intent()
        require(intent is not None and self._load(self.ACTIVE) == intent and self._load(self.STOP) is None,
                'usdo1_fault_renew_not_available')
        state = self._inspect(intent)
        now = self.clock()
        previous = None if state is None else state['lease_until']
        require(previous is not None and intent['started_at'] <= now < previous <= now + self.LOSS_SECONDS,
                'usdo1_fault_lease_lost_no_reinjection')
        lease_until = min(now + self.LOSS_SECONDS, intent['hard_until'])
        if lease_until <= previous:
            return previous
        self._permit('renew', previous=previous, lease_until=lease_until)
        require(self.clock() < previous, 'usdo1_fault_lease_lost_no_reinjection')
        # The host must atomically compare the existing unexpired set before extending it.
        require(self.apply('renew', intent | {'previous': previous, 'lease_until': lease_until}) is True, 'usdo1_fault_renew_unconfirmed')
        state = self._inspect(intent)
        require(state is not None and state['lease_until'] == lease_until > self.clock(), 'usdo1_fault_renew_unconfirmed')
        return lease_until

    def restore(self):
        intent = self._intent()
        stop = {'source': self.source, 'stopped': True}
        previous = self._load(self.STOP)
        if previous is None:
            self._save_once(self.STOP, stop)
        else:
            require(previous == stop, 'usdo1_fault_stop_changed')
        if self._inspect(intent) is not None:
            self._permit('restore')
            require(self.apply('restore', intent) is True, 'usdo1_fault_restore_unconfirmed')
        require(self._inspect(intent) is None, 'usdo1_fault_restore_unconfirmed')
