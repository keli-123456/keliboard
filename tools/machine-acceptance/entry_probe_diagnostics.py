"""Bounded ICMP diagnostics on an explicitly owned isolated node, never an agent loop."""
from dataclasses import dataclass
import hashlib
import ipaddress
import json
import os
from pathlib import Path
import re
import selectors
import stat
import subprocess
import time

import bootstrap_diagnostics as bootstrap


MAX_BYTES = 262144
MAX_SECONDS = 20
MAX_CHAINS = 32
MAX_RULES = 128
STATES = {"observed", "missing", "unavailable", "invalid", "timeout", "oversized", "budget_exhausted"}
ROUTE_TYPES = {"unicast", "local", "broadcast", "unreachable", "prohibit", "blackhole", "throw", "unknown"}
HOOKS = {"input", "output", "prerouting", "postrouting", "forward", "ingress", "egress"}
VERDICTS = {"accept", "drop", "reject", "return", "jump", "goto", "continue", "unknown"}
CLASSIFICATIONS = {"echo_reply", "no_echo_by_deadline", "icmp_error", "execution_error", "unavailable"}
ERROR_PATTERNS = {
    "permission_denied": r"operation not permitted|permission denied|cap_net_raw",
    "source_bind_error": r"cannot assign requested address|bind:|invalid source address",
    "local_route_error": r"network is unreachable|no route to host",
    "icmp_prohibited": r"administratively prohibited|communication prohibited|packet filtered|destination .*prohibited",
    "icmp_host_unreachable": r"destination host unreachable",
    "icmp_network_unreachable": r"destination (?:net|network) unreachable",
    "icmp_port_unreachable": r"destination port unreachable",
    "icmp_protocol_unreachable": r"destination protocol unreachable",
    "icmp_fragmentation_needed": r"frag needed|fragmentation needed",
    "icmp_ttl_exceeded": r"time to live exceeded|time exceeded",
    "icmp_parameter_problem": r"parameter problem",
    "icmp_redirect": r"redirect (?:host|network)",
}
ICMP_COUNTERS = ("InEchoReps", "OutEchos", "InDestUnreachs", "OutDestUnreachs", "InTimeExcds", "InErrors", "OutErrors")
PROGRAMS = {"ping": ("/usr/bin/ping", "/bin/ping"), "ip": ("/usr/sbin/ip", "/usr/bin/ip", "/sbin/ip", "/bin/ip"),
            "nft": ("/usr/sbin/nft", "/sbin/nft"), "systemctl": ("/usr/bin/systemctl", "/bin/systemctl")}


def program(name):
    return next((path for path in PROGRAMS[name] if os.path.isfile(path) and os.access(path, os.X_OK)), None)


def public_ipv4(value):
    try:
        ip = ipaddress.IPv4Address(value)
        return isinstance(value, str) and str(ip) == value and ip.is_global and not ip.is_multicast and not ip.is_reserved
    except (ValueError, TypeError, ipaddress.AddressValueError):
        return False


def validate_targets(targets, timeout):
    bootstrap.require(isinstance(targets, list) and 1 <= len(targets) <= 4 and all(public_ipv4(ip) for ip in targets)
                      and len(set(targets)) == len(targets) and type(timeout) is int and 1 <= timeout <= 2, "invalid_identity")


@dataclass(frozen=True)
class CommandResult:
    state: str
    exit_code: int | None = None
    output: bytes = b""


def command(args, timeout, limit=MAX_BYTES):
    """Drain both pipes together, cap bytes before parsing, and reap our child on every path."""
    if os.name != "posix":
        return CommandResult("unavailable")
    process = None
    selector = None
    try:
        process = subprocess.Popen(args, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
                                   env={"PATH": "/usr/sbin:/usr/bin:/sbin:/bin", "LC_ALL": "C", "LANG": "C"},
                                   close_fds=True, start_new_session=True)
        selector = selectors.DefaultSelector()
        selector.register(process.stdout, selectors.EVENT_READ)
        deadline = time.monotonic() + timeout
        output = bytearray()
        while selector.get_map():
            remaining = deadline - time.monotonic()
            if remaining <= 0:
                return CommandResult("timeout")
            for key, _ in selector.select(min(remaining, 0.1)):
                chunk = os.read(key.fileobj.fileno(), min(4096, limit + 1 - len(output)))
                if not chunk:
                    selector.unregister(key.fileobj)
                    continue
                output.extend(chunk)
                if len(output) > limit:
                    return CommandResult("oversized")
        remaining = deadline - time.monotonic()
        if remaining <= 0:
            return CommandResult("timeout")
        code = process.wait(timeout=remaining)
        return CommandResult("observed", code if -255 <= code <= 255 else None, bytes(output))
    except FileNotFoundError:
        return CommandResult("missing")
    except subprocess.TimeoutExpired:
        return CommandResult("timeout")
    except (OSError, ValueError):
        return CommandResult("unavailable")
    finally:
        if selector is not None:
            selector.close()
        if process is not None:
            try:
                if process.poll() is None:
                    process.kill()
                process.wait(timeout=1)
            finally:
                if process.stdout is not None:
                    process.stdout.close()


def classify_ping(result, target):
    row = {"state": result.state, "exit_code": result.exit_code, "classification": "unavailable", "sent": None,
           "received": None, "reported_errors": None, "error_categories": []}
    if result.state != "observed":
        return row
    text = result.output.decode("utf-8", errors="replace")
    row["error_categories"] = [name for name, pattern in ERROR_PATTERNS.items() if re.search(pattern, text, re.I)]
    summary = re.search(r"\b([0-9]+) packets transmitted, ([0-9]+) (?:packets )?received(?:, \+([0-9]+) errors)?", text)
    if summary and all(int(value or 0) <= limit for value, limit in zip(summary.groups(), [1, 1, 1024])):
        row.update(sent=int(summary[1]), received=int(summary[2]), reported_errors=int(summary[3] or 0))
    echo = re.search(r"\bbytes from " + re.escape(target) + r"(?:\s|:)\s*.*\bicmp_seq=1\b", text)
    if result.exit_code == 0 and row["received"] == 1 and echo:
        row["classification"] = "echo_reply"
    elif any(name.startswith("icmp_") for name in row["error_categories"]) or row["reported_errors"]:
        row["classification"] = "icmp_error"
    elif result.exit_code == 1 and row["received"] == 0 and not row["error_categories"]:
        row["classification"] = "no_echo_by_deadline"
    else:
        row["classification"] = "execution_error"
    return row


def route_summary(result, expected_source):
    row = {"state": result.state, "route_type": "unknown", "source_ip": None, "source_matches_receipt": False,
           "permission_error": False, "local_route_error": False}
    if result.state != "observed":
        return row
    if result.exit_code != 0:
        text = result.output.decode(errors="replace")
        row.update(state="unavailable", permission_error=bool(re.search(ERROR_PATTERNS["permission_denied"], text, re.I)),
                   local_route_error=bool(re.search(ERROR_PATTERNS["local_route_error"], text, re.I)))
        return row
    try:
        value = bootstrap.unique_json(result.output)
        if not isinstance(value, list) or len(value) != 1 or not isinstance(value[0], dict):
            raise ValueError()
        route = value[0]
        source = route.get("prefsrc") or route.get("src")
        kind = route.get("type", "unicast")
        row.update(route_type=kind if kind in ROUTE_TYPES else "unknown", source_ip=source if public_ipv4(source) else None,
                   source_matches_receipt=public_ipv4(source) and source == expected_source)
    except (ValueError, TypeError, UnicodeError):
        row["state"] = "invalid"
    return row


def fingerprint(value):
    return hashlib.sha256(json.dumps(value, sort_keys=True, separators=(",", ":"), allow_nan=False).encode()).hexdigest()


def counter_value(value):
    return type(value) is int and 0 <= value < 2**64


def address_member(address, expression):
    """Only literal IPv4 predicates; named sets/maps remain unknown without evaluation."""
    try:
        ip = ipaddress.IPv4Address(address)
        if isinstance(expression, str) and not expression.startswith("@"):
            return ip in ipaddress.IPv4Network(expression, strict=False)
        if isinstance(expression, dict) and set(expression) == {"prefix"}:
            prefix = expression["prefix"]
            if isinstance(prefix, dict) and set(prefix) == {"addr", "len"} and type(prefix["len"]) is int and 0 <= prefix["len"] <= 32:
                return ip in ipaddress.IPv4Network(str(prefix["addr"]) + "/" + str(prefix["len"]), strict=False)
        if isinstance(expression, dict) and set(expression) == {"set"} and isinstance(expression["set"], list) and len(expression["set"]) <= 128:
            answers = [address_member(address, item) for item in expression["set"]]
            return True if True in answers else None if None in answers else False
    except (ValueError, TypeError, RecursionError):
        pass
    return None


def receipt_address_matches(expr, hook, targets, source):
    values = []
    for target in targets:
        answers = []
        for term in expr:
            match = term.get("match")
            if not isinstance(match, dict):
                continue
            left = match.get("left")
            field = next((field for field in ["saddr", "daddr"] if left == {"payload": {"protocol": "ip", "field": field}}), None)
            if field is None:
                continue
            if hook in {"output", "postrouting"}:
                address = source if field == "saddr" else target
            elif hook in {"input", "prerouting"}:
                address = target if field == "saddr" else source
            else:
                answers.append(None)
                continue
            answer = address_member(address, match.get("right")) if match.get("op") in {"==", "!="} else None
            answers.append(not answer if answer is not None and match.get("op") == "!=" else answer)
        values.append(False if False in answers else None if not answers or None in answers else True)
    return values


def firewall_summary(result, targets=(), source=None):
    out = {"state": result.state, "structure_sha256": None, "chains": [], "rules": [], "unmodelled_objects": 0,
           "effective_policy_evaluated": False}
    if result.state != "observed":
        return out
    if len(result.output) > MAX_BYTES:
        return dict(out, state="oversized")
    if result.exit_code != 0:
        out["state"] = "unavailable"
        return out
    try:
        value = bootstrap.unique_json(result.output)
        if not isinstance(value, dict) or set(value) != {"nftables"} or not isinstance(value["nftables"], list) or len(value["nftables"]) > 2048:
            raise ValueError()
        hooks = {}
        for obj in value["nftables"]:
            if isinstance(obj, dict) and isinstance(obj.get("chain"), dict):
                chain = obj["chain"]
                identity = [chain.get("family"), chain.get("table"), chain.get("name")]
                if all(isinstance(part, str) for part in identity):
                    hooks[tuple(identity)] = chain.get("hook")
        structure = []
        for index, obj in enumerate(value["nftables"]):
            if not isinstance(obj, dict) or len(obj) != 1:
                raise ValueError()
            kind, item = next(iter(obj.items()))
            if kind == "metainfo":
                continue
            if not isinstance(item, dict):
                raise ValueError()
            if item.get("family") not in {"ip", "inet"}:
                out["unmodelled_objects"] += 1
                structure.append(obj)
                continue
            if kind not in {"chain", "rule"}:
                out["unmodelled_objects"] += 1
                structure.append(obj)
                continue
            identity = [item.get("family"), item.get("table"), item.get("name") if kind == "chain" else item.get("chain")]
            if not all(isinstance(part, str) and len(part) <= 256 for part in identity):
                raise ValueError()
            ref = fingerprint(identity)[:16]
            if kind == "chain":
                if len(out["chains"]) >= MAX_CHAINS:
                    raise OverflowError()
                hook, policy, priority = item.get("hook"), item.get("policy"), item.get("prio")
                out["chains"].append({"ref": ref, "family": item["family"], "hook": hook if hook in HOOKS else None,
                                      "policy": policy if policy in {"accept", "drop"} else None,
                                      "priority": priority if type(priority) is int and -2**31 <= priority < 2**31 else None})
                structure.append(obj)
                continue
            expr = item.get("expr")
            if not isinstance(expr, list) or len(expr) > 128 or not all(isinstance(term, dict) for term in expr):
                raise ValueError()
            if len(out["rules"]) >= MAX_RULES:
                raise OverflowError()
            verdicts = [name for term in expr for name in term if name in VERDICTS - {"unknown"}]
            explicit_icmp = any(term.get("match", {}).get("left") in [
                {"payload": {"protocol": "icmp", "field": "type"}}, {"payload": {"protocol": "icmp", "field": "code"}}]
                or term.get("match", {}).get("left") in [{"meta": {"key": "l4proto"}}, {"payload": {"protocol": "ip", "field": "protocol"}}]
                and term.get("match", {}).get("right") in ["icmp", 1] for term in expr if isinstance(term.get("match", {}), dict))
            # Keep all rules: catch-all drops and jumps can affect ICMP without mentioning it.
            counters = [term["counter"] for term in expr if isinstance(term.get("counter"), dict)]
            counts = counters[0] if len(counters) == 1 else {}
            packets, size = counts.get("packets"), counts.get("bytes")
            out["rules"].append({"ref": fingerprint([*identity, item.get("handle"), index])[:16], "chain_ref": ref,
                                 "position": index, "verdict": verdicts[0] if len(verdicts) == 1 else "unknown",
                                 "explicit_icmp": explicit_icmp, "conditions_not_evaluated": True,
                                 "receipt_address_matches": receipt_address_matches(expr, hooks.get(tuple(identity)), targets, source),
                                 "packets": packets if counter_value(packets) else None,
                                 "bytes": size if counter_value(size) else None})
            normalized = json.loads(json.dumps(obj))
            for term in normalized["rule"]["expr"]:
                if isinstance(term.get("counter"), dict):
                    term["counter"] = {key: 0 if key in {"packets", "bytes"} else val for key, val in term["counter"].items()}
            structure.append(normalized)
        out["structure_sha256"] = fingerprint(structure)
        return out
    except OverflowError:
        return dict(out, state="oversized", structure_sha256=None, chains=[], rules=[])
    except (ValueError, TypeError, AttributeError, UnicodeError, RecursionError):
        return dict(out, state="invalid", structure_sha256=None, chains=[], rules=[])


def firewall_delta(before, after):
    stable = (before["state"] == after["state"] == "observed" and before["structure_sha256"] == after["structure_sha256"])
    rows = []
    if stable:
        previous = {rule["ref"]: rule for rule in before["rules"]}
        for rule in after["rules"]:
            old = previous.get(rule["ref"], {})
            valid = all(counter_value(rule[name]) and counter_value(old.get(name)) and rule[name] >= old[name] for name in ["packets", "bytes"])
            rows.append({"ref": rule["ref"], "packets": rule["packets"] - old["packets"] if valid else None,
                         "bytes": rule["bytes"] - old["bytes"] if valid else None, "available": valid})
    return {"structure_stable": stable, "rules": rows, "shared_counters_not_probe_attribution": True}


def icmp_counters(root):
    result = {"state": "unavailable", "values": None}
    try:
        # procfs reports size zero; /proc/self is an intentional kernel link, not a user-supplied path.
        flags = os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0) | getattr(os, "O_NONBLOCK", 0) | getattr(os, "O_BINARY", 0)
        with os.fdopen(os.open(root / "proc/self/net/snmp", flags), "rb") as stream:
            if not stat.S_ISREG(os.fstat(stream.fileno()).st_mode):
                return result
            raw = stream.read(16385)
        if len(raw) > 16384:
            return {"state": "oversized", "values": None}
        lines = raw.decode("ascii").splitlines()
        for header, numbers in zip(lines, lines[1:]):
            if header.startswith("Icmp:") and numbers.startswith("Icmp:"):
                keys, values = header.split()[1:], numbers.split()[1:]
                if len(keys) != len(values):
                    raise ValueError()
                fields = dict(zip(keys, map(int, values)))
                if not all(counter_value(fields.get(key)) for key in ICMP_COUNTERS):
                    raise ValueError()
                return {"state": "observed", "values": {key: fields[key] for key in ICMP_COUNTERS}}
    except OSError:
        pass
    except (ValueError, UnicodeError):
        result["state"] = "invalid"
    return result


def namespace_summary(root, result):
    out = {"state": "unavailable", "agent_pid": None, "matches_agent": None}
    if result.state != "observed" or result.exit_code != 0 or not re.fullmatch(rb"[1-9][0-9]{0,8}\s*", result.output):
        return out
    pid = int(result.output)
    try:
        own = os.readlink(root / "proc/self/ns/net")
        agent = os.readlink(root / "proc" / str(pid) / "ns/net")
        if re.fullmatch(r"net:\[[0-9]+\]", own) and re.fullmatch(r"net:\[[0-9]+\]", agent):
            out.update(state="observed", agent_pid=pid, matches_agent=own == agent)
    except OSError:
        pass
    return out


def collect(run_id, task_id, role, targets, expected_source, timeout=2, maximum_seconds=MAX_SECONDS, *,
            active=True, root=Path("/"), run_command=command, clock=time.monotonic):
    try:
        bootstrap.validate_identity(run_id, task_id, role)
        validate_targets(targets, timeout)
        bootstrap.require(public_ipv4(expected_source) and type(active) is bool and type(maximum_seconds) is int
                          and 1 <= maximum_seconds <= MAX_SECONDS, "invalid_identity")
        root = Path(root)
        metadata, raw = bootstrap.read_bounded(root / "var/lib/keli-test-owner/owner.json", 16384, private=True)
        owner = bootstrap.unique_json(raw) if raw is not None else None
        bootstrap.require(metadata["state"] == "observed" and owner == {"schema": 1, "run_id": run_id, "task_id": task_id}
                          and type(owner["schema"]) is int, "owner_unverified")
        deadline = clock() + maximum_seconds

        def invoke(args, seconds=1, limit=16384):
            if args[0] is None:
                return CommandResult("missing")
            remaining = deadline - clock()
            if remaining <= 0:
                return CommandResult("budget_exhausted")
            result = run_command(args, min(seconds, remaining), limit)
            if (not isinstance(result, CommandResult) or result.state not in STATES or not isinstance(result.output, bytes)
                    or len(result.output) > limit):
                return CommandResult("oversized")
            return result

        nft, ping_program, ip = (program(name) for name in ["nft", "ping", "ip"])
        namespace = namespace_summary(root, invoke([program("systemctl"), "show", "kelinode", "--no-pager", "--property=MainPID", "--value"]))
        before = firewall_summary(invoke([nft, "-j", "list", "ruleset"], limit=MAX_BYTES), targets, expected_source)
        host_before = icmp_counters(root)
        version = invoke([ping_program, "-V"])
        iputils = version.state == "observed" and version.exit_code == 0 and b"iputils" in version.output
        rows = []
        for target in targets:
            route = route_summary(invoke([ip, "-j", "-4", "route", "get", target]), expected_source)
            reason = ("stop_requested" if not active else "tool_unavailable" if not iputils else
                      "network_namespace_unverified" if namespace["matches_agent"] is not True else
                      "route_unavailable" if route["state"] != "observed" or route["route_type"] != "unicast" else
                      "source_mismatch" if not route["source_matches_receipt"] else None)
            ping = classify_ping(CommandResult("unavailable"), target)
            if reason is None:
                ping = classify_ping(invoke([ping_program, "-I", expected_source, "-4", "-n", "-c", "1", "-W", str(timeout),
                                            "-w", str(timeout + 1), "--", target], timeout + 2), target)
            rows.append({"target": target, "route": route, "skip_reason": reason, "ping": ping})
        host_after = icmp_counters(root)
        after = firewall_summary(invoke([nft, "-j", "list", "ruleset"], limit=MAX_BYTES), targets, expected_source)
        namespace_after = namespace_summary(root, invoke([program("systemctl"), "show", "kelinode", "--no-pager", "--property=MainPID", "--value"]))
        return {"schema": 1, "ok": True, "run_id": run_id, "task_id": task_id, "role": role, "read_only": True,
                "owner_verified": True, "private_permissions_checked": os.name == "posix", "expected_source": expected_source,
                "network_namespace": namespace, "firewall_scope": "nftables_current_network_namespace_only", "non_nft_filters_not_evaluated": True,
                "network_namespace_after": namespace_after,
                "agent_context_stable": namespace == namespace_after and namespace["matches_agent"] is True,
                "active_requested": active, "iputils_verified": iputils, "timeout_seconds": timeout, "rows": rows,
                "firewall_before": before, "firewall_after": after, "firewall_delta": firewall_delta(before, after),
                "host_icmp_before": host_before, "host_icmp_after": host_after, "host_counters_not_per_target": True,
                "root_cause_confirmed": False, "censorship_confirmed": False}
    except bootstrap.Refused as error:
        return {"ok": False, "read_only": True, "reason": str(error)}
    except Exception:
        return {"ok": False, "read_only": True, "reason": "diagnostic_unavailable"}
