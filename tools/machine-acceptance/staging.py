#!/usr/bin/env python3
"""Read-only control-bundle preflight; never grants execution authority."""
import argparse
import hashlib
import hmac
import json
import os
from pathlib import Path
import re
import stat
import time

from worker import Rejected, hex_value, integer, require, unique_json


MANIFEST = "staging-manifest.json"
MAX_FILE_BYTES = 268435456
MAX_TOTAL_BYTES = 536870912
STOP_MARKERS = ("closed.json", "stop-request.json")
RESERVED_NAMES = {MANIFEST, "owner.json", *STOP_MARKERS}


def filename(value):
    return (isinstance(value, str) and re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9._-]{0,119}", value) is not None
            and value not in RESERVED_NAMES)


def linked(info):
    return stat.S_ISLNK(info.st_mode) or bool(getattr(info, "st_file_attributes", 0) & 0x400)


def signature(info):
    # Windows fstat exposes a different ctime field from path-based stat.
    identity = (info.st_dev, info.st_ino, info.st_size, info.st_mtime_ns)
    return identity + ((info.st_ctime_ns,) if os.name == "posix" else ())


def permissions(info, private=False):
    if os.name == "posix":
        require(info.st_uid == os.geteuid(), "staging_owner_mismatch")
        require(stat.S_IMODE(info.st_mode) & (0o077 if private else 0o022) == 0, "staging_permissions_unsafe")


def directory(path):
    path = Path(path)
    require(path.is_absolute() and path.resolve(strict=True) == path, "staging_directory_invalid")
    info = path.lstat()
    require(stat.S_ISDIR(info.st_mode) and not linked(info), "staging_directory_invalid")
    permissions(info, private=True)
    return path, (info.st_dev, info.st_ino)


def unchanged_directory(path, identity):
    info = path.lstat()
    require(stat.S_ISDIR(info.st_mode) and not linked(info) and path.resolve(strict=True) == path
            and (info.st_dev, info.st_ino) == identity, "staging_directory_changed")
    permissions(info, private=True)


def unchanged_file(path, identity, *, private=False):
    info = path.lstat()
    require(stat.S_ISREG(info.st_mode) and not linked(info) and info.st_nlink == 1
            and signature(info) == identity, "staging_file_changed")
    permissions(info, private)


def read_file(path, limit, *, collect=False, private=False):
    before = path.lstat()
    require(stat.S_ISREG(before.st_mode) and not linked(before) and before.st_nlink == 1, "staging_file_invalid")
    require(before.st_size <= limit, "staging_size_exceeded")
    permissions(before, private)
    flags = os.O_RDONLY | getattr(os, "O_BINARY", 0) | getattr(os, "O_NOFOLLOW", 0) | getattr(os, "O_NONBLOCK", 0)
    descriptor = os.open(path, flags)
    with os.fdopen(descriptor, "rb") as stream:
        opened = os.fstat(stream.fileno())
        require(signature(opened) == signature(before) and stat.S_ISREG(opened.st_mode)
                and opened.st_nlink == 1, "staging_file_changed")
        digest = hashlib.sha256()
        total = 0
        content = []
        while block := stream.read(min(1048576, limit - total + 1)):
            total += len(block)
            require(total <= limit, "staging_size_exceeded")
            digest.update(block)
            if collect:
                content.append(block)
        after = os.fstat(stream.fileno())
    require(total == before.st_size and signature(after) == signature(before), "staging_file_changed")
    unchanged_file(path, signature(before), private=private)
    return {"bytes": total, "sha256": digest.hexdigest()}, b"".join(content), signature(before)


def read_json(path, *, private=True):
    digest, raw, identity = read_file(path, 131072, collect=True, private=private)
    try:
        value = unique_json(raw)
    except Rejected:
        raise Rejected("staging_json_invalid") from None
    require(isinstance(value, dict), "staging_json_invalid")
    return value, digest, identity


def active(state):
    require(not any(os.path.lexists(state / name) for name in STOP_MARKERS), "staging_run_stopped_or_closed")


def window(scope, now, minimum_remaining):
    started, expires = scope.get("started_at"), scope.get("expires_at")
    require(integer(started, 1, 2**53) and integer(expires, 1, 2**53)
            and 0 < expires - started <= 7200, "staging_window_invalid")
    require(started <= now and now + minimum_remaining <= expires, "staging_window_expired")


def check_staging(bundle, state, *, run_id, manifest_sha256, required_files, minimum_remaining=300, clock=time.time):
    require(isinstance(run_id, str) and re.fullmatch(r"[a-z0-9][a-z0-9-]{7,99}", run_id) is not None
            and hex_value(manifest_sha256, 64) and integer(minimum_remaining, 30, 1800), "staging_input_invalid")
    require(isinstance(required_files, (list, tuple)) and 1 <= len(required_files) <= 64
            and all(filename(name) for name in required_files) and len(set(required_files)) == len(required_files)
            and "run-scope.json" in required_files, "staging_required_files_invalid")
    try:
        bundle, bundle_identity = directory(bundle)
        state, state_identity = directory(state)
        require(bundle != state, "staging_directory_invalid")
        active(state)
        began = clock()
        owner, _, owner_identity = read_json(state / "owner.json")
        expected_owner = {"schema": 1, "run_id": run_id, "role": "control"}
        require(owner == expected_owner and type(owner.get("schema")) is int, "staging_owner_mismatch")
        manifest, manifest_digest, manifest_identity = read_json(bundle / MANIFEST)
        require(hmac.compare_digest(manifest_digest["sha256"], manifest_sha256), "staging_manifest_changed")
        require(set(manifest) == {"schema", "run_id", "role", "files"}
                and type(manifest.get("schema")) is int and manifest["schema"] == 1
                and manifest["run_id"] == run_id and manifest["role"] == "control", "staging_manifest_invalid")
        files = manifest["files"]
        require(isinstance(files, dict) and set(files) == set(required_files), "staging_files_incomplete")
        require(all(isinstance(item, dict) and set(item) == {"bytes", "sha256"}
                    and integer(item["bytes"], 0, MAX_FILE_BYTES) and hex_value(item["sha256"], 64)
                    for item in files.values()), "staging_manifest_invalid")
        require(sum(item["bytes"] for item in files.values()) <= MAX_TOTAL_BYTES, "staging_size_exceeded")
        scope, scope_digest, scope_identity = read_json(bundle / "run-scope.json")
        require(type(scope.get("schema")) is int and scope["schema"] == 1 and scope.get("run_id") == run_id
                and scope.get("role") == "control", "staging_scope_mismatch")
        require(scope_digest == files["run-scope.json"], "staging_digest_changed")
        window(scope, began, minimum_remaining)
        observed = {"run-scope.json": scope_identity, MANIFEST: manifest_identity}
        for name in sorted(set(files) - {"run-scope.json"}):
            digest, _, identity = read_file(bundle / name, files[name]["bytes"])
            require(digest == files[name], "staging_digest_changed")
            observed[name] = identity
        # Recheck stop, time and all file identities after the bounded hash pass.
        active(state)
        finished = clock()
        require(finished >= began, "staging_clock_changed")
        window(scope, finished, minimum_remaining)
        unchanged_directory(bundle, bundle_identity)
        unchanged_directory(state, state_identity)
        unchanged_file(state / "owner.json", owner_identity, private=True)
        for name, identity in observed.items():
            unchanged_file(bundle / name, identity, private=name in {MANIFEST, "run-scope.json"})
        return {"ok": True, "read_only": True, "authorizes_execution": False, "run_id": run_id,
                "role": "control", "manifest_sha256": manifest_sha256, "file_count": len(files),
                "total_bytes": sum(item["bytes"] for item in files.values()), "expires_at": scope["expires_at"],
                "private_permissions_checked": os.name == "posix"}
    except (OSError, ValueError, RuntimeError):
        raise Rejected("staging_unavailable") from None


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--bundle", required=True)
    parser.add_argument("--state", required=True)
    parser.add_argument("--run-id", required=True)
    parser.add_argument("--manifest-sha256", required=True)
    parser.add_argument("--require", action="append", required=True, dest="required_files")
    parser.add_argument("--minimum-remaining", type=int, default=300)
    arguments = vars(parser.parse_args(argv))
    try:
        result = check_staging(**arguments)
    except Rejected as error:
        result = {"ok": False, "read_only": True, "authorizes_execution": False, "reason": str(error)}
    print(json.dumps(result, separators=(",", ":")))
    return 0 if result["ok"] else 1


if __name__ == "__main__":
    raise SystemExit(main())
