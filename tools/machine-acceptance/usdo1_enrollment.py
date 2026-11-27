"""Offline service 123 peer registration. No transport or peer activation."""

import base64
import contextlib
import hashlib
import ipaddress
import json
import re
import sqlite3
from dataclasses import asdict, dataclass

from driver_journal import Rejected, require


RECOVERY_SECONDS = 2100
ENROLLMENT_SECONDS = 300
PROVIDER_FIELDS = ("account_id", "region", "size", "image", "label", "marker")
PRIVATE_NETS = tuple(ipaddress.IPv4Network(network) for network in ("10.0.0.0/8", "172.16.0.0/12", "192.168.0.0/16"))


def matches(pattern, value):
    return isinstance(value, str) and re.fullmatch(pattern, value) is not None


def instance_id(value):
    return matches(r"[1-9][0-9]{0,15}", value)


def ipv4(value):
    if isinstance(value, str):
        try:
            return ipaddress.IPv4Address(value)
        except ipaddress.AddressValueError:
            pass
    return None


def digest(value):
    return hashlib.sha256(json.dumps(value, sort_keys=True, separators=(",", ":")).encode("ascii")).hexdigest()


@dataclass(frozen=True)
class EnrollmentIntent:
    service_revision: int
    task_id: str
    installation_id: str
    marker: str
    account_id: int
    region: str
    size: str
    image: str
    label: str
    candidate_private_ip: str
    transport_sha256: str
    protected_instances: tuple[str, ...]
    opened_at: int
    enrollment_until: int
    task_until: int
    grant_until: int

    def __post_init__(self):
        address = ipv4(self.candidate_private_ip)
        require(type(self.service_revision) is int and self.service_revision > 0
                and type(self.account_id) is int and self.account_id > 0
                and matches(r"[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}", self.task_id)
                and matches(r"[a-f0-9]{32}", self.installation_id)
                and matches(r"[a-f0-9]{32}", self.marker)
                and matches(r"[a-f0-9]{64}", self.transport_sha256)
                and all(matches(r"[a-z0-9][a-z0-9_.-]{0,63}", value)
                        for value in (self.region, self.size, self.label))
                and matches(r"[a-z0-9][a-z0-9_./-]{0,127}", self.image)
                and address is not None and any(address in network for network in PRIVATE_NETS)
                and isinstance(self.protected_instances, tuple) and bool(self.protected_instances)
                and all(instance_id(i) for i in self.protected_instances)
                and tuple(sorted(set(self.protected_instances))) == self.protected_instances,
                "enrollment_scope_invalid")
        require(all(type(t) is int and 0 < t < 2**53 for t in
                    (self.opened_at, self.enrollment_until, self.task_until, self.grant_until))
                and self.opened_at < self.enrollment_until <= min(self.opened_at + ENROLLMENT_SECONDS,
                                                                 self.task_until,
                                                                 self.grant_until - RECOVERY_SECONDS),
                "enrollment_window_invalid")


class Usdo1Enrollment:
    """The caller supplies a dedicated local SQLite ledger and trusted readers.

    The provider reader must use the exact, unique create readback. The console
    reader must read that instance via the provider, not forward guest input.
    The same ledger must survive restarts; losing it requires reconciliation.
    A receipt from this class does not authorize installing a WireGuard peer.
    """

    def __init__(self, db, intent, *, clock, authorize, provider_readback, console_readback):
        require(isinstance(db, sqlite3.Connection) and isinstance(intent, EnrollmentIntent), "enrollment_scope_invalid")
        self.db, self.intent, self.clock, self.authorize = db, intent, clock, authorize
        self.provider_readback, self.console_readback = provider_readback, console_readback
        self.scope_sha = digest(asdict(intent) | {"service_id": 1, "provider": "linode"})

    def _permit(self, action):
        require(self.authorize(action, self.scope_sha) is True, "enrollment_authority_denied")

    def _now(self):
        now = self.clock()
        require(type(now) is int and self.intent.opened_at <= now < 2**53, "enrollment_clock_invalid")
        return now

    def _row(self):
        row = self.db.execute(
            "SELECT scope, candidate, provider_sha, peer_sha, closed, observed_at FROM usdo1_enrollment WHERE id = 1"
        ).fetchone()
        require(row is not None and row[0] == self.scope_sha, "enrollment_scope_changed")
        return row

    @contextlib.contextmanager
    def _transaction(self):
        require(not self.db.in_transaction, "enrollment_transaction_in_use")
        self.db.execute("BEGIN IMMEDIATE")
        try:
            yield
            self.db.execute("COMMIT")
        finally:
            if self.db.in_transaction:
                self.db.execute("ROLLBACK")

    def prepare(self):
        """Called only before dispatch, never to recover a missing ledger."""
        self._permit("prepare")
        with self._transaction():
            now = self._now()
            require(now < self.intent.enrollment_until, "enrollment_expired")
            self.db.execute("""CREATE TABLE IF NOT EXISTS usdo1_enrollment (
                id INTEGER PRIMARY KEY CHECK (id = 1),
                scope TEXT NOT NULL,
                candidate TEXT,
                provider_sha TEXT CHECK ((provider_sha IS NULL) = (candidate IS NULL)),
                peer_sha TEXT CHECK (peer_sha IS NULL OR candidate IS NOT NULL),
                closed TEXT CHECK (closed IN ('expired', 'revoked')),
                observed_at INTEGER NOT NULL
            )""")
            self.db.execute("INSERT OR IGNORE INTO usdo1_enrollment (id, scope, observed_at) VALUES (1, ?, ?)",
                            (self.scope_sha, now))
            require(self._row()[1:5] == (None, None, None, None), "enrollment_already_started")

    def _live(self):
        _, candidate, provider, peer, closed, observed = self._row()
        if closed:
            return candidate, provider, peer, closed, None
        now = self._now()
        require(now >= observed, "enrollment_clock_regressed")
        if now >= self.intent.enrollment_until:
            self.db.execute("UPDATE usdo1_enrollment SET closed = 'expired', observed_at = ? WHERE id = 1", (now,))
            return candidate, provider, peer, "expired", None
        return candidate, provider, peer, None, now

    def _check_live(self):
        with self._transaction():
            candidate, provider, _, closed, _ = self._live()
        require(closed is None, "enrollment_" + str(closed))
        return candidate, provider

    def _provider_sha(self, expected_id):
        row = self.provider_readback(expected_id)
        require(isinstance(row, dict) and set(row) == set(PROVIDER_FIELDS) | {"instance_id", "created_at", "ipv4"}
                and row["instance_id"] == expected_id and type(row["account_id"]) is int
                and all(row[k] == getattr(self.intent, k) for k in PROVIDER_FIELDS)
                and type(row["created_at"]) is int and self.intent.opened_at <= row["created_at"] <= self._now(),
                "enrollment_candidate_mismatch")
        address = ipv4(row["ipv4"])
        require(address is not None and address.is_global and not address.is_multicast and not address.is_reserved,
                "enrollment_candidate_address_invalid")
        return digest(row)

    def bind_candidate(self, expected_id):
        self._permit("bind")
        current, _ = self._check_live()
        require(instance_id(expected_id) and expected_id not in self.intent.protected_instances
                and current in (None, expected_id), "enrollment_candidate_mismatch")
        proof = self._provider_sha(expected_id)
        self._permit("bind")
        with self._transaction():
            current, provider, _, closed, now = self._live()
            if closed is None:
                require(current in (None, expected_id) and provider in (None, proof), "enrollment_candidate_mismatch")
                if current is None:
                    self.db.execute("""UPDATE usdo1_enrollment
                        SET candidate = ?, provider_sha = ?, observed_at = ? WHERE id = 1""", (expected_id, proof, now))
        require(closed is None, "enrollment_" + str(closed))

    def register(self):
        self._permit("register")
        candidate, provider = self._check_live()
        require(candidate is not None, "enrollment_candidate_unbound")
        row = self.console_readback(candidate)
        require(isinstance(row, dict) and set(row) == {"instance_id", "marker", "public_key"}
                and row["instance_id"] == candidate and row["marker"] == self.intent.marker,
                "enrollment_console_mismatch")
        value = row["public_key"]
        require(matches(r"[A-Za-z0-9+/]{43}=", value), "enrollment_public_key_invalid")
        key = base64.b64decode(value, validate=True)
        require(key != bytes(32) and base64.b64encode(key).decode("ascii") == value, "enrollment_public_key_invalid")
        peer = hashlib.sha256(key).hexdigest()
        require(self._provider_sha(candidate) == provider, "enrollment_provider_drift")
        self._permit("register")
        with self._transaction():
            current, pinned, previous, closed, now = self._live()
            if closed is None:
                require(current == candidate and pinned == provider, "enrollment_candidate_mismatch")
                require(previous in (None, peer), "enrollment_key_changed")
                if previous is None:
                    self.db.execute("UPDATE usdo1_enrollment SET peer_sha = ?, observed_at = ? WHERE id = 1", (peer, now))
        require(closed is None, "enrollment_" + str(closed))
        return {"status": "registered" if previous is None else "already_registered", "peer_sha256": peer}

    def revoke(self):
        self._permit("revoke")
        with self._transaction():
            self._row()
            self.db.execute("UPDATE usdo1_enrollment SET closed = 'revoked' WHERE id = 1 AND closed IS NOT 'revoked'")
        return {"status": "revoked"}
