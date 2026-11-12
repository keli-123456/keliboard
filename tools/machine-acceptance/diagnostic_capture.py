"""Explicit loader adapter: bounded diagnostics first, original cleanup always next."""
import ipaddress
import json
import os
from pathlib import Path
import time
from types import SimpleNamespace

import bootstrap_diagnostics as probe


REPORT = "bootstrap-diagnostics.json"
INTENT = "bootstrap-diagnostics-intent.json"
MAX_SECONDS = 20
MAX_ROLE_SECONDS = 10
CLEANUP_RESERVE_SECONDS = 60


def validate_record(value, run_id, task_id, role):
    """Validate the complete wire schema; never persist arbitrary remote output."""
    try:
        expected = {"schema", "ok", "run_id", "task_id", "role", "read_only", "owner_verified", "private_permissions_checked",
                    "root_cause_confirmed", "installer", "bootstrap", "execution", "service", "files"}
        if not isinstance(value, dict) or set(value) != expected or type(value["schema"]) is not int or value["schema"] != 2:
            return None
        if (value["ok"] is not True or value["run_id"] != run_id or value["task_id"] != task_id or value["role"] != role
                or any(value[name] is not True for name in ["read_only", "owner_verified", "private_permissions_checked"])
                or value["root_cause_confirmed"] is not False):
            return None
        log = value["installer"]
        if not isinstance(log, dict) or set(log) != {"state", "bytes_total", "bytes_sampled", "truncated", "phase", "category_counts", "curl_error_codes", "http_error_statuses"}:
            return None
        if (log["state"] not in probe.READ_STATES or log["phase"] not in {*probe.PHASES, "unknown"}
                or type(log["bytes_sampled"]) is not int or not 0 <= log["bytes_sampled"] <= probe.MAX_LOG_BYTES
                or type(log["truncated"]) is not bool or not (log["bytes_total"] is None or type(log["bytes_total"]) is int and 0 <= log["bytes_total"] < 2**63)):
            return None
        counts = log["category_counts"]
        if counts is not None and (not isinstance(counts, dict) or set(counts) != set(probe.CATEGORIES)
                                   or any(type(n) is not int or not 0 <= n <= probe.MAX_LOG_BYTES for n in counts.values())):
            return None
        if (log["state"] == "observed") != (counts is not None):
            return None
        if log["state"] == "observed" and (log["bytes_total"] is None or log["bytes_sampled"] > log["bytes_total"]):
            return None
        if log["state"] != "observed" and (log["bytes_sampled"] != 0 or log["phase"] != "unknown" or log["curl_error_codes"] or log["http_error_statuses"]):
            return None
        for name, low, high in [("curl_error_codes", 1, 255), ("http_error_statuses", 400, 599)]:
            numbers = log[name]
            if not isinstance(numbers, list) or len(numbers) > 8 or any(type(n) is not int or not low <= n <= high for n in numbers):
                return None
        result = value["bootstrap"]
        if not isinstance(result, dict) or set(result) != {"state", "result"} or result["state"] not in probe.READ_STATES or result["result"] not in {None, "failed", "succeeded"}:
            return None
        execution = value["execution"]
        if (not isinstance(execution, dict) or set(execution) != set(probe.EXECUTION_SCRIPTS)
                or not all(probe.valid_execution(entry) for entry in execution.values())):
            return None
        unit = value["service"]
        if not isinstance(unit, dict) or set(unit) != {"state", *probe.UNIT_KEYS} or unit["state"] not in {"observed", "unreadable"}:
            return None
        for key in probe.UNIT_KEYS:
            entry = unit[key]
            valid = entry in probe.UNIT_ENUMS[key] if key in probe.UNIT_ENUMS else type(entry) is int and 0 <= entry < 10**9
            if entry is not None and not valid:
                return None
        files = value["files"]
        if not isinstance(files, dict) or set(files) != {"agent_binary_present", "configuration_present"} or any(v is not None and type(v) is not bool for v in files.values()):
            return None
        # JSON round-trip removes aliases while rejecting oversized responses and exotic objects.
        serialized = json.dumps(value, allow_nan=False)
        return json.loads(serialized) if len(serialized) <= 32768 else None
    except (KeyError, TypeError, ValueError):
        return None


def render_probe(run_id, task_id, role):
    probe.validate_identity(run_id, task_id, role)
    source = Path(probe.__file__).read_text(encoding="utf-8")
    return "__name__ = 'keli_bootstrap_diagnostic'\n" + source + "\nprint(json.dumps(collect(" + ",".join(map(repr, (run_id, task_id, role))) + ")))\n"


def owned_task(campaign, node, role):
    row = node.task(role)
    probe.validate_identity(campaign.RUN, row.get("id"), role)
    if row.get("label") != campaign.RUN + "-" + role or not str(row.get("external_id", "")).isdigit() or int(row["external_id"]) <= 0:
        raise ValueError()
    addresses = row.get("public_ips")
    if not isinstance(addresses, list) or not addresses or not row.get("host_public_key", "").startswith("ssh-ed25519 "):
        raise ValueError()
    ipv4 = 0
    for address in addresses:
        parsed = ipaddress.ip_address(address)
        if not parsed.is_global or str(parsed) != address:
            raise ValueError()
        ipv4 += parsed.version == 4
    if ipv4 != 1:
        raise ValueError()
    return row


def capture(campaign, node, *, clock=time.monotonic, wall=time.time):
    base = Path(campaign.BASE)
    result = {"schema": 1, "run_id": campaign.RUN, "read_only": True, "status": "not_collected", "records": []}
    if os.path.lexists(base / "closed.json"):
        return dict(result, status="closed_round_refused")
    campaign.assert_scope()
    try:
        campaign.save(INTENT, {"run_id": campaign.RUN, "read_only": True, "maximum_seconds": MAX_SECONDS}, exclusive=True)
    except FileExistsError:
        return dict(result, status="original_collection_only")
    budget = MAX_SECONDS
    if (base / "campaign-window.json").is_file():
        window = campaign.load("campaign-window.json")
        if window.get("run_id") != campaign.RUN:
            raise ValueError()
        budget = min(budget, max(0, window.get("expires_at", 0) - wall() - CLEANUP_RESERVE_SECONDS))
    deadline = clock() + budget
    for role in ("source", "candidate"):
        entry = {"role": role, "status": "receipt_unavailable"}
        if os.path.lexists(base / (role + "-delete-intent.json")):
            result["records"].append(dict(entry, status="delete_already_started"))
            continue
        try:
            row = owned_task(campaign, node, role)
        except Exception:
            result["records"].append(entry)
            continue
        remaining = min(MAX_ROLE_SECONDS, deadline - clock())
        if remaining < 1:
            result["records"].append(dict(entry, status="budget_exhausted"))
            continue
        try:
            raw = node.execute(role, render_probe(campaign.RUN, row["id"], role), timeout=remaining)
            safe = validate_record(raw, campaign.RUN, row["id"], role)
            if safe is None:
                reason = raw.get("reason") if isinstance(raw, dict) else None
                status = "owner_unverified" if reason == "owner_unverified" else "response_unavailable"
                result["records"].append(dict(entry, status=status))
            else:
                result["records"].append(dict(entry, status="collected", evidence=safe))
        except Exception:
            result["records"].append(dict(entry, status="transport_unavailable"))
    result["status"] = "completed"
    campaign.save(REPORT, result, exclusive=True)
    return result


def loader_with_diagnostics(loader):
    """Use as the existing isolated RoundRunner(loader=...) dependency, not a queue hook."""
    def load(name):
        module = loader(name)
        if name != "cleanup":
            return module

        def main(*args, **kwargs):
            campaign = None
            try:
                campaign = loader("campaign")
                capture(campaign, loader("node"))
            except BaseException:
                # Diagnostics are best effort; no failure may postpone resource cleanup.
                if campaign is not None:
                    try:
                        if not os.path.lexists(Path(campaign.BASE) / "closed.json"):
                            campaign.save(REPORT, {"schema": 1, "run_id": campaign.RUN, "read_only": True,
                                                  "status": "collector_failed", "records": []}, exclusive=True)
                    except BaseException:
                        pass
            return module.main(*args, **kwargs)
        return SimpleNamespace(main=main)
    return load
