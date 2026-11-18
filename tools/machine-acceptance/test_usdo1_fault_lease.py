import tempfile
import unittest
from pathlib import Path

from driver_journal import DriverJournal, Rejected
from usdo1_fault_lease import Usdo1FaultLease


class Usdo1FaultLeaseTest(unittest.TestCase):
    RUN = 'keli-accept-test-usdo1'
    SOURCE = {'run_id': RUN, 'role': 'new-source', 'instance_id': '520', 'installation_sha256': 'f' * 64,
              'ipv4': '192.0.1.11', 'boot_id': 'f0000000-0000-0000-0000-f00000000000'}
    TARGETS = ['192.0.1.31', '192.0.1.32', '192.0.1.33', '192.0.1.34']

    def setUp(self):
        work = tempfile.TemporaryDirectory()
        self.addCleanup(work.cleanup)
        self.journal = DriverJournal(Path(work.name).resolve(), self.RUN)
        self.now = 1000
        self.table = None
        self.applied = []
        self.lost_ack = False
        self.denied = set()

    def lease(self, source=None, targets=None, cutoff=2500):
        return Usdo1FaultLease(journal=self.journal, source=self.SOURCE if source is None else source,
                              protected_ids=('17', '521'), targets=self.TARGETS if targets is None else targets,
                              cutoff=cutoff, clock=lambda: self.now, inspect=self.inspect, apply=self.apply,
                              authorize=lambda operation, _, __: operation not in self.denied)

    def inspect(self, source):
        self.assertEqual(self.SOURCE, source)
        if self.table is None:
            return {'source': source, 'absent': True}
        if self.table['lease_until'] is not None and self.table['lease_until'] <= self.now:
            self.table['lease_until'] = None
        return dict(self.table)

    def apply(self, operation, intent):
        self.assertEqual(self.SOURCE, intent['source'])
        self.assertEqual(self.TARGETS, intent['targets'])
        self.assertEqual(intent['source'], self.journal.load(Usdo1FaultLease.INTENT)['source'])
        self.applied.append(operation)
        if operation == 'arm':
            self.assertIsNone(self.table)
            self.table = {k: intent[k] for k in ('source', 'targets', 'hard_until', 'lease_until')} | {'scope_verified': True}
            if self.lost_ack:
                raise TimeoutError('test transport lost reply')
        elif operation == 'renew':
            self.assertEqual(intent['previous'], self.inspect(self.SOURCE)['lease_until'])
            self.assertLess(self.now, intent['previous'])
            self.table['lease_until'] = intent['lease_until']
        elif operation == 'restore':
            self.assertIs(self.journal.load(Usdo1FaultLease.STOP)['stopped'], True)
            self.table = None
        else:
            self.fail('Unknown fault operation')
        return True

    def observation(self):
        return {'source': self.SOURCE, 'results': {ip: 'reachable' for ip in self.TARGETS}}

    def test_one_healthy_new_source_only_and_loopback_never_counts(self):
        for identity in ({'instance_id': '17'}, {'role': 'rollback'}, {'role': 'candidate', 'instance_id': '521'}):
            with self.assertRaisesRegex(Rejected, 'source_invalid'):
                self.lease(source=self.SOURCE | identity)
        with self.assertRaisesRegex(Rejected, 'scope_invalid'):
            self.lease(targets=self.TARGETS[:3] + ['127.0.0.1'])
        lease = self.lease()
        for status in ('unreachable', 'error'):
            with self.assertRaisesRegex(Rejected, 'healthy_observation_required'):
                lease.start(self.observation() | {'results': dict.fromkeys(self.TARGETS, status)})
        self.denied.add('arm')
        with self.assertRaisesRegex(Rejected, 'guard_refused'):
            lease.start(self.observation())
        self.assertEqual([], self.applied)

    def test_loss_expires_in_ninety_seconds_and_cannot_reinject(self):
        lease = self.lease()
        observation = self.observation()
        observation['results'] |= dict.fromkeys(self.TARGETS[:3], 'unreachable')
        self.assertEqual(1090, lease.start(observation))
        self.now += 30
        self.assertEqual(1120, lease.renew())
        self.now += 90
        restarted = self.lease()
        self.assertIsNone(self.inspect(self.SOURCE)['lease_until'])
        with self.assertRaisesRegex(Rejected, 'lease_lost_no_reinjection'):
            restarted.renew()
        with self.assertRaisesRegex(Rejected, 'already_used'):
            restarted.start(observation)
        restarted.restore()
        restarted.restore()
        self.assertEqual(['arm', 'renew', 'restore'], self.applied)

    def test_renewal_does_not_extend_past_nine_hundred_seconds(self):
        lease = self.lease()
        lease.start(self.observation())
        for self.now in range(1040, 1900, 40):
            self.assertEqual(min(self.now + 90, 1900), lease.renew())
        self.now = 1900
        with self.assertRaisesRegex(Rejected, 'lease_lost_no_reinjection'):
            lease.renew()
        lease.restore()

    def test_cutoff_caps_first_lease_and_renewal(self):
        lease = self.lease(cutoff=1060)
        self.assertEqual(1060, lease.start(self.observation()))
        self.now = 1050
        self.assertEqual(1060, lease.renew())
        self.assertEqual(['arm'], self.applied)
        self.now = 1060
        self.assertIsNone(self.inspect(self.SOURCE)['lease_until'])
        lease.restore()

    def test_lost_start_ack_does_not_renew_and_restores_exact_lease(self):
        self.lost_ack = True
        with self.assertRaisesRegex(TimeoutError, 'lost reply'):
            self.lease().start(self.observation())
        restarted = self.lease()
        with self.assertRaisesRegex(Rejected, 'renew_not_available'):
            restarted.renew()
        with self.assertRaisesRegex(Rejected, 'already_used'):
            restarted.start(self.observation())
        restarted.restore()
        self.assertEqual(['arm', 'restore'], self.applied)

    def test_restore_refuses_changed_scope_and_unreadable_host(self):
        lease = self.lease()
        lease.start(self.observation())
        self.table['source'] = self.SOURCE | {'boot_id': 'f0000000-0000-0000-0000-000000000001'}
        with self.assertRaisesRegex(Rejected, 'readback_invalid'):
            lease.restore()
        self.assertEqual(['arm'], self.applied)
        with self.assertRaisesRegex(Rejected, 'renew_not_available'):
            lease.renew()
        lease.inspect = lambda _: {'source': self.SOURCE, 'absent': 1}
        with self.assertRaisesRegex(Rejected, 'readback_invalid'):
            lease.restore()
        self.assertEqual(['arm'], self.applied)

    def test_stop_before_arm_cannot_later_enable_the_lease(self):
        lease = self.lease()
        lease.restore()
        with self.assertRaisesRegex(Rejected, 'already_used'):
            lease.start(self.observation())
        self.assertEqual([], self.applied)
