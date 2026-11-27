"""Synthetic provider/console evidence meets the real Linux and TLS fixture."""

import base64
import hashlib
import multiprocessing
from pathlib import Path
import sqlite3
import sys
from contextlib import closing

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "tools/machine-acceptance"))
from driver_journal import Rejected
from usdo1_enrollment import EnrollmentIntent, Usdo1Enrollment


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def service(db, case, clock, console=None, drift=False):
    marker = hashlib.sha256(case["task"].encode("ascii")).hexdigest()[:32]
    intent = EnrollmentIntent(
        service_revision=2, task_id=case["task"], installation_id=case["installation"], marker=marker,
        account_id=92, region="sg-sin-2", size="g6-standard-4", image="linode/debian12", label="keli-enrollment-fixture",
        candidate_private_ip="10.25.0.2", transport_sha256="c" * 64, protected_instances=("6119",),
        opened_at=1000, enrollment_until=1300, task_until=2000, grant_until=4000,
    )
    public_key = lambda value: base64.b64encode(bytes([value]) * 32).decode("ascii")
    def provider(instance):
        return {k: getattr(intent, k) for k in ("account_id", "region", "size", "image", "label", "marker")} | {
            "instance_id": instance, "created_at": 1005, "ipv4": "1.2.3.5" if drift else "1.2.3.4",
        }
    def read_console(instance):
        row = {"instance_id": instance, "marker": marker, "public_key": public_key(8)}
        return console(row, public_key) if console is not None else row
    return Usdo1Enrollment(db, intent, clock=clock, authorize=lambda *_: True,
                           provider_readback=provider, console_readback=read_console)


def gate_fixture(path, case, scenario, start):
    now = 1010
    def console(row, _):
        nonlocal now
        if scenario == "wrong_instance":
            return row | {"instance_id": "7119"}
        if scenario == "expires_while_reading":
            now = 1300
        if scenario == "revoked_while_reading":
            with closing(sqlite3.connect(path)) as other:
                service(other, case, lambda: now).revoke()
        return row
    with closing(sqlite3.connect(path)) as db:
        initial = service(db, case, lambda: now)
        initial.prepare()
        initial.bind_candidate("7118")
        current = service(db, case, lambda: now, console=console, drift=scenario == "provider_drift")
        try:
            admission = current.register()
        except Rejected as error:
            require(db.execute("SELECT peer_sha FROM usdo1_enrollment").fetchone() == (None,),
                    "rejected registration wrote a peer")
            return str(error), None
        require(admission["status"] == "registered", "fixture admission was never fresh")
        before = db.total_changes
        require(current.register()["status"] == "already_registered" and db.total_changes == before,
                "replayed registration was not read-only")
        return "registered", start()


def race_worker(path, case, gate, channel, byte, hold=None):
    def console(row, key):
        if gate is not None:
            gate.wait()
        return row | {"public_key": key(byte)}
    with closing(sqlite3.connect(path, timeout=3)) as db, channel:
        try:
            result = service(db, case, lambda: 1011, console=console).register()["status"]
        except Rejected as error:
            result = str(error)
        channel.send(result)
        if hold is not None:
            hold.wait(15)


def multiprocess_evidence(work, case):
    ctx = multiprocessing.get_context("spawn")
    def init(name):
        database = work / (name + ".sqlite3")
        with closing(sqlite3.connect(database)) as db:
            initial = service(db, case, lambda: 1010)
            initial.prepare()
            initial.bind_candidate("7118")
        return database
    def child(database, barrier, byte, hold=None):
        rx, tx = ctx.Pipe(duplex=False)
        proc = ctx.Process(target=race_worker, args=(database, case, barrier, tx, byte, hold))
        proc.start()
        tx.close()
        return proc, rx
    def stop_children(children):
        for proc, rx in children:
            rx.close()
            if proc.is_alive():
                proc.kill()
            proc.join(5)
            require(proc.exitcode is not None, "child cleanup not confirmed")
    def get(rx):
        require(rx.poll(7), "missing enrollment child result")
        return rx.recv()
    def compete(name, keys, expected):
        database = init(name)
        barrier = ctx.Barrier(2, timeout=5)
        children = []
        try:
            for key in keys:
                children.append(child(database, barrier, key))
            outcomes = sorted(get(rx) for _, rx in children)
            require(outcomes == sorted(expected), "unexpected concurrent enrollment outcome")
            for proc, _ in children:
                proc.join(5)
                require(proc.exitcode == 0, "enrollment child did not exit normally")
            with closing(sqlite3.connect(database)) as db:
                require(db.execute("SELECT count(*) FROM usdo1_enrollment WHERE peer_sha IS NOT NULL").fetchone() == (1,),
                        "multiple bindings after competing enrollment")
        finally:
            stop_children(children)
    compete("competing_keys", (8, 9), ("registered", "enrollment_key_changed"))
    compete("same_key", (8, 8), ("registered", "already_registered"))
    database = init("lost_reply")
    children = []
    try:
        hold = ctx.Event()
        children.append(child(database, None, 8, hold=hold))
        proc, signal_only = children[0]
        require(get(signal_only) == "registered", "first commit not confirmed before crash")
        proc.kill()
        proc.join(5)
        require(proc.exitcode == -9, "post-commit crash not exercised")
        with closing(sqlite3.connect(database)) as db:
            require(service(db, case, lambda: 1012).register()["status"] == "already_registered" and db.total_changes == 0,
                    "restart after crash duplicated registration")
    finally:
        stop_children(children)
    return {"different_keys": "one_registered", "same_key": "one_registered", "post_commit_sigkill": "read_only_retry"}
