"""Opt-in ICMP evidence adapter for fresh isolated rounds; cleanup remains authoritative."""
import json
import math
import os
from pathlib import Path
import re
import time

import bootstrap_diagnostics as bootstrap
import diagnostic_capture
import entry_probe_diagnostics as probe


REPORT = "entry-probe-diagnostics.json"
INTENT = "entry-probe-diagnostics-intent.json"
MAX_SECONDS = 44
MAX_ROLE_SECONDS = 22
RESERVE_SECONDS = 60
SKIP_REASONS = {None, "stop_requested", "tool_unavailable", "source_mismatch", "route_unavailable", "network_namespace_unverified"}


def configuration(auth, run_id):
    value = auth.get("entry_probe_diagnostics")
    if value is None:
        return None
    bootstrap.require(isinstance(value, dict) and set(value) == {"schema", "enabled", "targets", "timeout_seconds"}
                      and type(value["schema"]) is int and value["schema"] == 1 and type(value["enabled"]) is bool
                      and auth.get("run_id") == run_id and auth.get("user_approved") is True, "invalid_identity")
    probe.validate_targets(value["targets"], value["timeout_seconds"])
    return value if value["enabled"] else None


def keys(value, fields):
    return isinstance(value, dict) and set(value) == set(fields.split())


def number(value, low=0, high=2**64):
    return type(value) is int and low <= value < high


def ref(value, length=16):
    return isinstance(value, str) and re.fullmatch("[a-f0-9]{" + str(length) + "}", value) is not None


def valid_firewall(value, target_count):
    if not keys(value, "state structure_sha256 chains rules unmodelled_objects effective_policy_evaluated"):
        return False
    if (value["state"] not in probe.STATES or value["effective_policy_evaluated"] is not False
            or not number(value["unmodelled_objects"], high=2049) or not isinstance(value["chains"], list)
            or len(value["chains"]) > probe.MAX_CHAINS or not isinstance(value["rules"], list) or len(value["rules"]) > probe.MAX_RULES):
        return False
    if value["state"] != "observed":
        return value["structure_sha256"] is None and not value["chains"] and not value["rules"]
    if not ref(value["structure_sha256"], 64):
        return False
    for chain in value["chains"]:
        if (not keys(chain, "ref family hook policy priority") or not ref(chain["ref"]) or chain["family"] not in {"ip", "inet"}
                or chain["hook"] not in {None, *probe.HOOKS} or chain["policy"] not in {None, "accept", "drop"}
                or chain["priority"] is not None and not number(chain["priority"], -2**31, 2**31)):
            return False
    for rule in value["rules"]:
        if (not keys(rule, "ref chain_ref position verdict explicit_icmp conditions_not_evaluated receipt_address_matches packets bytes")
                or not ref(rule["ref"]) or not ref(rule["chain_ref"]) or not number(rule["position"], high=2048)
                or rule["verdict"] not in probe.VERDICTS or type(rule["explicit_icmp"]) is not bool
                or rule["conditions_not_evaluated"] is not True
                or not isinstance(rule["receipt_address_matches"], list) or len(rule["receipt_address_matches"]) != target_count
                or any(item is not None and type(item) is not bool for item in rule["receipt_address_matches"])
                or any(rule[name] is not None and not number(rule[name]) for name in ["packets", "bytes"])):
            return False
    return len({rule["ref"] for rule in value["rules"]}) == len(value["rules"])


def valid_host(value):
    return (keys(value, "state values") and value["state"] in {"observed", "unavailable", "invalid", "oversized"}
            and (keys(value["values"], " ".join(probe.ICMP_COUNTERS)) and all(number(v) for v in value["values"].values())
                 if value["state"] == "observed" else value["values"] is None))


def validate_record(value, run_id, task_id, role, scope, source, active):
    try:
        if not keys(value, "schema ok run_id task_id role read_only owner_verified private_permissions_checked expected_source network_namespace network_namespace_after agent_context_stable firewall_scope non_nft_filters_not_evaluated active_requested iputils_verified timeout_seconds rows firewall_before firewall_after firewall_delta host_icmp_before host_icmp_after host_counters_not_per_target root_cause_confirmed censorship_confirmed"):
            return None
        if (type(value["schema"]) is not int or value["schema"] != 1 or value["run_id"] != run_id or value["task_id"] != task_id
                or value["role"] != role or value["expected_source"] != source or value["active_requested"] is not active
                or type(value["timeout_seconds"]) is not int or value["timeout_seconds"] != scope["timeout_seconds"]
                or type(value["iputils_verified"]) is not bool or any(value[key] is not True for key in
                    ["ok", "read_only", "owner_verified", "private_permissions_checked", "host_counters_not_per_target"])
                or value["root_cause_confirmed"] is not False or value["censorship_confirmed"] is not False
                or value["firewall_scope"] != "nftables_current_network_namespace_only" or value["non_nft_filters_not_evaluated"] is not True
                or not isinstance(value["rows"], list) or len(value["rows"]) != len(scope["targets"])):
            return None
        for namespace in [value["network_namespace"], value["network_namespace_after"]]:
            if (not keys(namespace, "state agent_pid matches_agent") or namespace["state"] not in {"observed", "unavailable"}
                    or (not number(namespace["agent_pid"], 1, 10**9) or type(namespace["matches_agent"]) is not bool
                        if namespace["state"] == "observed" else namespace["agent_pid"] is not None or namespace["matches_agent"] is not None)):
                return None
        namespace = value["network_namespace"]
        stable = namespace == value["network_namespace_after"] and namespace["matches_agent"] is True
        if type(value["agent_context_stable"]) is not bool or value["agent_context_stable"] != stable:
            return None
        for target, row in zip(scope["targets"], value["rows"]):
            if not keys(row, "target route skip_reason ping") or row["target"] != target or row["skip_reason"] not in SKIP_REASONS:
                return None
            route, ping = row["route"], row["ping"]
            if (not keys(route, "state route_type source_ip source_matches_receipt permission_error local_route_error")
                    or route["state"] not in probe.STATES or route["route_type"] not in probe.ROUTE_TYPES
                    or route["source_ip"] is not None and not probe.public_ipv4(route["source_ip"])
                    or any(type(route[k]) is not bool for k in ["source_matches_receipt", "permission_error", "local_route_error"])
                    or route["source_matches_receipt"] != (route["source_ip"] == source)):
                return None
            if (not keys(ping, "state exit_code classification sent received reported_errors error_categories")
                    or ping["state"] not in probe.STATES or ping["classification"] not in probe.CLASSIFICATIONS
                    or ping["exit_code"] is not None and not number(ping["exit_code"], -255, 256)
                    or any(ping[k] is not None and not number(ping[k], high=2) for k in ["sent", "received"])
                    or ping["reported_errors"] is not None and not number(ping["reported_errors"], high=1025)
                    or not isinstance(ping["error_categories"], list) or len(ping["error_categories"]) > len(probe.ERROR_PATTERNS)
                    or any(not isinstance(k, str) or k not in probe.ERROR_PATTERNS for k in ping["error_categories"])):
                return None
            if row["skip_reason"] is not None and ping != probe.classify_ping(probe.CommandResult("unavailable"), target):
                return None
            if not active and row["skip_reason"] != "stop_requested":
                return None
            if ping["classification"] == "echo_reply" and (row["skip_reason"] is not None or not route["source_matches_receipt"]
                    or namespace["matches_agent"] is not True or ping["state"] != "observed" or ping["exit_code"] != 0
                    or ping["received"] != 1 or not value["iputils_verified"]):
                return None
        if not all(valid_firewall(value[key], len(scope["targets"])) for key in ["firewall_before", "firewall_after"]):
            return None
        delta = value["firewall_delta"]
        if (not keys(delta, "structure_stable rules shared_counters_not_probe_attribution") or type(delta["structure_stable"]) is not bool
                or delta["shared_counters_not_probe_attribution"] is not True or not isinstance(delta["rules"], list)
                or any(not keys(row, "ref packets bytes available") or not ref(row["ref"]) or type(row["available"]) is not bool
                       or any(row[key] is not None and not number(row[key]) for key in ["packets", "bytes"]) for row in delta["rules"])
                or delta != probe.firewall_delta(value["firewall_before"], value["firewall_after"])):
            return None
        if not valid_host(value["host_icmp_before"]) or not valid_host(value["host_icmp_after"]):
            return None
        encoded = json.dumps(value, allow_nan=False)
        return json.loads(encoded) if len(encoded) <= 131072 else None
    except (ValueError, KeyError, TypeError, AttributeError):
        return None


def render_probe(run_id, task_id, role, scope, source, seconds, active):
    bootstrap.validate_identity(run_id, task_id, role)
    probe.validate_targets(scope["targets"], scope["timeout_seconds"])
    bootstrap.require(probe.public_ipv4(source) and type(seconds) is int and 1 <= seconds <= probe.MAX_SECONDS
                      and type(active) is bool, "invalid_identity")
    code = "import types,sys,json\n"
    for name, module in [("bootstrap_diagnostics", bootstrap), ("entry_probe_diagnostics", probe)]:
        code += "m=types.ModuleType(" + repr(name) + ");sys.modules[" + repr(name) + "]=m\nexec(" + repr(Path(module.__file__).read_text(encoding="utf-8")) + ",m.__dict__)\n"
    args = (run_id, task_id, role, scope["targets"], source, scope["timeout_seconds"], seconds)
    return code + "print(json.dumps(m.collect(" + ",".join(map(repr, args)) + ",active=" + repr(active) + ")))\n"


def capture(campaign, node, *, clock=time.monotonic, wall=time.time):
    base = Path(campaign.BASE)
    out = {"schema": 1, "run_id": campaign.RUN, "read_only": True, "status": "not_enabled", "records": []}
    if os.path.lexists(base / "closed.json"):
        return dict(out, status="closed_round_refused")
    if not (base / "authorization.json").is_file():
        return out
    scope = configuration(campaign.load("authorization.json"), campaign.RUN)
    if scope is None:
        return out
    campaign.assert_scope()
    window = campaign.load("campaign-window.json")
    bootstrap.require(window.get("run_id") == campaign.RUN and type(window.get("started_at")) is int
                      and type(window.get("expires_at")) is int and 0 < window["expires_at"] - window["started_at"] <= 7200
                      and window["started_at"] <= wall(), "invalid_identity")
    budget = min(MAX_SECONDS, max(0, window["expires_at"] - wall() - RESERVE_SECONDS))
    try:
        campaign.save(INTENT, {"run_id": campaign.RUN, "read_only": True, "maximum_seconds": MAX_SECONDS,
                               "targets": scope["targets"]}, exclusive=True)
    except FileExistsError:
        return dict(out, status="original_collection_only")
    deadline = clock() + budget
    for role in ["source", "candidate"]:
        entry = {"role": role, "status": "receipt_unavailable"}
        if os.path.lexists(base / "closed.json") or os.path.lexists(base / (role + "-delete-intent.json")):
            out["records"].append(dict(entry, status="delete_or_close_started"))
            continue
        try:
            task = diagnostic_capture.owned_task(campaign, node, role)
        except Exception:
            out["records"].append(entry)
            continue
        remaining = min(MAX_ROLE_SECONDS, deadline - clock(), window["expires_at"] - wall() - RESERVE_SECONDS)
        if remaining < 3:
            out["records"].append(dict(entry, status="budget_exhausted"))
            continue
        source = next(ip for ip in task["public_ips"] if probe.public_ipv4(ip))
        active = not os.path.lexists(base / "stop-request.json")
        try:
            raw = node.execute(role, render_probe(campaign.RUN, task["id"], role, scope, source,
                                                 min(probe.MAX_SECONDS, math.floor(remaining - 2)), active), timeout=remaining)
            safe = validate_record(raw, campaign.RUN, task["id"], role, scope, source, active)
            out["records"].append(dict(entry, status="collected", evidence=safe) if safe is not None else dict(entry, status="response_unavailable"))
        except Exception:
            out["records"].append(dict(entry, status="transport_unavailable"))
    out["status"] = "completed"
    campaign.save(REPORT, out, exclusive=True)
    return out
