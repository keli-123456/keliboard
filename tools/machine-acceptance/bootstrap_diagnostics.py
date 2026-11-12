"""Read-only, source-local bootstrap summaries. Never return log or config text."""
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import uuid


MAX_LOG_BYTES = 262144
MAX_JSON_BYTES = 16384
ROLES = {"source", "candidate"}
READ_STATES = {"observed", "missing", "unreadable", "unsafe", "oversized", "invalid"}
CATEGORIES = {
    "dependency_failure": r"dependency installation failed|failed to fetch|unable to fetch",
    "repository_signature": r"no_pubkey|expkeysig|badsig|repository[^\r\n]{0,256}not signed",
    "dns_failure": r"could not resolve|temporary failure in name resolution",
    "tls_failure": r"certificate verify failed|ssl certificate problem|certificate verification failed",
    "http_failure": r"curl:\s*\(22\)|requested url returned error",
    "connection_failure": r"connection refused|connection timed out|failed to connect",
    "checksum_failure": r"sha256[^\r\n]{0,160}(?:mismatch|failed)|checksum[^\r\n]{0,160}(?:mismatch|failed)|computed checksum did not match",
    "archive_failure": r"not in gzip format|unexpected eof in archive|tar:[^\r\n]{0,160}error|gzip:[^\r\n]{0,160}error",
    "manifest_failure": r"release manifest[^\r\n]{0,160}(?:missing|mismatch)|failed to resolve latest|invalid version:",
    "unsupported_platform": r"unsupported (?:architecture|platform)|exec format error",
    "binary_failure": r"installed binary cannot run|glibc_[0-9.]+[^\r\n]{0,160}not found|segmentation fault",
    "service_failure": r"failed to (?:start|restart) kelinode|kelinode[.]service[^\r\n]{0,160}failed",
    "permission_failure": r"permission denied|operation not permitted",
    "missing_command": r"command not found",
    "shell_syntax": r"syntax error|unbound variable|bad substitution",
}
PHASES = {
    "dependencies": ["installing dependencies", "\u5b89\u88c5/\u8865\u9f50\u4f9d\u8d56"],
    "installer_download": ["\u4e0b\u8f7d\u5b89\u88c5\u811a\u672c:"],
    "native_install": ["installing keli native node"],
    "manifest_download": ["manifest:"],
    "archive_download": ["download:"],
    "configuration": ["wrote native machine config", "machine config unchanged"],
    "service_start": ["failed to start kelinode", "failed to restart kelinode"],
    "installer_finished": ["keli native node installed.", "[info] \u5b89\u88c5\u5b8c\u6210"],
}
UNIT_KEYS = ("LoadState", "ActiveState", "SubState", "Result", "ExecMainStatus", "ExecMainCode", "NRestarts")
UNIT_ENUMS = {
    "LoadState": {"loaded", "not-found", "masked", "error", "bad-setting", "stub", "merged"},
    "ActiveState": {"active", "inactive", "failed", "activating", "deactivating", "reloading", "maintenance"},
    "SubState": {"running", "dead", "failed", "exited", "start", "start-pre", "start-post", "stop", "stop-sigterm", "auto-restart", "condition"},
    "Result": {"success", "exit-code", "signal", "timeout", "oom-kill", "resources", "protocol", "start-limit-hit", "core-dump", "watchdog", "dependency", "condition"},
}
REASONS = {"invalid_identity", "owner_unverified", "diagnostic_unavailable"}


class Refused(Exception):
    pass


def require(condition, reason):
    if not condition:
        raise Refused(reason)


def validate_identity(run_id, task_id, role):
    require(isinstance(run_id, str) and re.fullmatch(r"keli-accept-[a-z0-9-]{8,80}", run_id), "invalid_identity")
    require(role in ROLES, "invalid_identity")
    try:
        require(isinstance(task_id, str) and str(uuid.UUID(task_id)) == task_id, "invalid_identity")
    except (ValueError, AttributeError, TypeError):
        raise Refused("invalid_identity") from None


def unique_json(raw):
    def pairs(items):
        result = {}
        for key, value in items:
            if key in result:
                raise ValueError()
            result[key] = value
        return result
    return json.loads(raw, object_pairs_hook=pairs, parse_constant=lambda _: (_ for _ in ()).throw(ValueError()))


def read_bounded(path, limit, *, tail=False, private=False):
    """Inspect fixed regular files only; missing/unreadable never means zero errors."""
    metadata = {"state": "unreadable", "bytes_total": None, "bytes_sampled": 0, "truncated": False}
    try:
        if any(parent.is_symlink() for parent in (path, *path.parents)):
            return dict(metadata, state="unsafe"), None
        info = path.lstat()
        if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1:
            return dict(metadata, state="unsafe"), None
        if private and os.name == "posix" and (info.st_uid != os.geteuid() or stat.S_IMODE(info.st_mode) & 0o077):
            return dict(metadata, state="unsafe"), None
        metadata.update(bytes_total=info.st_size, truncated=info.st_size > limit)
        if not tail and info.st_size > limit:
            return dict(metadata, state="oversized"), None
        flags = os.O_RDONLY | getattr(os, "O_BINARY", 0) | getattr(os, "O_NOFOLLOW", 0) | getattr(os, "O_NONBLOCK", 0)
        with os.fdopen(os.open(path, flags), "rb") as stream:
            opened = os.fstat(stream.fileno())
            if (info.st_dev, info.st_ino) != (opened.st_dev, opened.st_ino) or not stat.S_ISREG(opened.st_mode):
                return dict(metadata, state="unsafe"), None
            if tail:
                stream.seek(max(0, info.st_size - limit))
            raw = stream.read(min(limit, info.st_size))
        metadata.update(state="observed", bytes_sampled=len(raw))
        return metadata, raw
    except FileNotFoundError:
        return dict(metadata, state="missing"), None
    except OSError:
        return metadata, None


def classify_log(metadata, raw):
    result = dict(metadata, phase="unknown", category_counts=None, curl_error_codes=[], http_error_statuses=[])
    if raw is None:
        return result
    text = raw.decode("utf-8", errors="replace").lower()
    result["category_counts"] = {name: len(re.findall(pattern, text)) for name, pattern in CATEGORIES.items()}
    seen = [(text.rfind(marker), phase) for phase, markers in PHASES.items() for marker in markers if marker in text]
    if seen:
        result["phase"] = max(seen)[1]
    result["curl_error_codes"] = sorted({int(code) for code in re.findall(r"curl:\s*\(([0-9]{1,3})\)", text) if 1 <= int(code) <= 255})[:8]
    result["http_error_statuses"] = sorted({int(code) for code in re.findall(r"(?:returned error:|http status:)\s*([45][0-9]{2})(?![0-9])", text)})[:8]
    return result


def service_summary():
    result = {"state": "unreadable", **dict.fromkeys(UNIT_KEYS)}
    try:
        process = subprocess.run(["/usr/bin/systemctl", "show", "kelinode", "--no-pager", "--property=" + ",".join(UNIT_KEYS)],
                                 capture_output=True, timeout=2)
        if process.returncode != 0 or len(process.stdout) > 16384:
            return result
        fields = dict(line.split("=", 1) for line in process.stdout.decode(errors="replace").splitlines() if "=" in line)
        for name in UNIT_KEYS:
            value = fields.get(name)
            if name in UNIT_ENUMS:
                result[name] = value if value in UNIT_ENUMS[name] else None
            elif isinstance(value, str) and re.fullmatch(r"[0-9]{1,9}", value):
                result[name] = int(value)
        result["state"] = "observed"
    except (OSError, subprocess.TimeoutExpired):
        pass
    return result


def presence(path):
    try:
        if any(parent.is_symlink() for parent in (path, *path.parents)):
            return None
        return stat.S_ISREG(path.lstat().st_mode)
    except FileNotFoundError:
        return False
    except OSError:
        return None


def collect(run_id, task_id, role, *, root=Path("/"), read_service=service_summary):
    try:
        validate_identity(run_id, task_id, role)
        root = Path(root)
        owner_meta, owner_raw = read_bounded(root / "var/lib/keli-test-owner/owner.json", MAX_JSON_BYTES, private=True)
        try:
            owner = unique_json(owner_raw) if owner_raw is not None else None
        except (ValueError, TypeError, UnicodeError):
            owner = None
        require(owner_meta["state"] == "observed" and owner == {"schema": 1, "run_id": run_id, "task_id": task_id}
                and type(owner.get("schema")) is int, "owner_unverified")
        directory = root / "var/lib/kelinode-provision" / task_id
        meta, raw = read_bounded(directory / "install.log", MAX_LOG_BYTES, tail=True, private=True)
        installer = classify_log(meta, raw)
        meta, raw = read_bounded(directory / "result.json", MAX_JSON_BYTES, private=True)
        bootstrap = {"state": meta["state"], "result": None}
        if raw is not None:
            try:
                result = unique_json(raw)
                if (not isinstance(result, dict) or result.get("task_id") != task_id
                        or not isinstance(result.get("installation_id"), str) or not re.fullmatch(r"[a-f0-9]{32}", result["installation_id"])
                        or result.get("state") not in {"failed", "succeeded"}):
                    raise ValueError()
                bootstrap["result"] = result["state"]
            except (ValueError, TypeError, UnicodeError):
                bootstrap["state"] = "invalid"
        return {"schema": 1, "ok": True, "run_id": run_id, "task_id": task_id, "role": role, "read_only": True,
                "owner_verified": True, "private_permissions_checked": os.name == "posix", "root_cause_confirmed": False,
                "installer": installer, "bootstrap": bootstrap, "service": read_service(),
                "files": {"agent_binary_present": presence(root / "usr/local/kelinode/kelinode"),
                          "configuration_present": presence(root / "etc/kelinode/config.yml")}}
    except Refused as error:
        return {"schema": 1, "ok": False, "read_only": True, "reason": str(error)}
    except Exception:
        return {"schema": 1, "ok": False, "read_only": True, "reason": "diagnostic_unavailable"}
