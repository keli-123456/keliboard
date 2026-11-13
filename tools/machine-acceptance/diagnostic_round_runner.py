"""Fresh-scope isolated entry point. No import, run or cleanup of a closed round."""
import argparse
import hashlib
import importlib
import json
import os
from pathlib import Path
import re
import sys

import bootstrap_diagnostics
import diagnostic_capture
import entry_probe_capture
import aliyun_cleanup
import probe_sampling


STOP_FILES = ("closed.json", "stop-request.json", "cleanup-intent.json", "round-intent.json", "control-intent.json",
              "campaign-window.json", "control-pipeline-intent.json", "resource-ledger.json")
FLAGS = ("production_automatic_creation", "production_automatic_rotation", "production_dns_switch", "production_deployment",
         "production_queue_changes", "production_database_writes", "tanzhen_access", "business_machine_5_access")
TOOLS = ("bootstrap_diagnostics.py", "diagnostic_capture.py", "diagnostic_round_runner.py",
         "entry_probe_diagnostics.py", "entry_probe_capture.py", "aliyun_cleanup.py", "aliyun_cleanup.php", "probe_sampling.py")
REQUIRED = {*TOOLS, "campaign.py", "node.py", "cleanup.py", "round_runner.py", "authorization.json"}


class Refused(Exception):
    pass


def require(condition, reason):
    if not condition:
        raise Refused(reason)


def read_json(path):
    require(path.is_file() and not path.is_symlink() and path.stat().st_size <= 131072, "local_record_invalid")
    value = bootstrap_diagnostics.unique_json(path.read_bytes())
    require(isinstance(value, dict), "local_record_invalid")
    return value


def prepare(work):
    base = Path(work).absolute()
    require(base.is_dir() and base.resolve(strict=True) == base, "private_work_required")
    require(not any(os.path.lexists(base / name) for name in STOP_FILES), "new_round_required")
    auth = read_json(base / "authorization.json")
    require(auth.get("user_approved") is True and all(auth.get(name) is False for name in FLAGS), "approved_isolated_scope_required")
    require(all(type(auth.get(key)) is int and auth[key] == value for key, value in
                [("peak_instance_count", 3), ("maximum_total_create_attempts", 3), ("maximum_campaign_seconds", 7200)]),
            "bounded_scope_required")
    bootstrap_diagnostics.validate_identity(auth.get("run_id"), "11111111-1111-4111-8111-111111111111", "source")
    try:
        entry_probe_capture.configuration(auth, auth["run_id"])
    except bootstrap_diagnostics.Refused:
        raise Refused("probe_diagnostic_scope_invalid") from None
    prepared = read_json(base / "offline-result.json")
    require(prepared.get("ok") is True and prepared.get("run_id") == auth["run_id"], "offline_preflight_required")
    pins = prepared.get("file_digests")
    require(isinstance(pins, dict) and REQUIRED <= set(pins) and len(pins) <= 128, "pinned_diagnostics_required")
    total = 0
    for name, pin in pins.items():
        require(isinstance(name, str) and re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9_.-]{0,119}", name)
                and isinstance(pin, str) and re.fullmatch(r"[a-f0-9]{64}", pin), "local_pin_invalid")
        path = base / name
        require(path.is_file() and not path.is_symlink() and path.stat().st_nlink == 1, "local_file_invalid")
        total += path.stat().st_size
        require(path.stat().st_size <= 268435456 and total <= 536870912, "local_bundle_too_large")
        with path.open("rb") as stream:
            require(hashlib.file_digest(stream, "sha256").hexdigest() == pin, "local_file_changed")
    for name in TOOLS:
        with Path(__file__).with_name(name).open("rb") as stream:
            require(hashlib.file_digest(stream, "sha256").hexdigest() == pins[name], "launcher_tool_changed")
    require(aliyun_cleanup.SOURCE_SHA256 == pins['aliyun_cleanup.php'], "loaded_cleanup_reader_changed")
    return base, auth["run_id"], pins


def run(work):
    base, run_id, pins = prepare(work)

    def load(name):
        require(name + ".py" in pins, "unpinned_driver_module")
        prior = sys.modules.get(name)
        require(prior is None or Path(prior.__file__).resolve() == base / (name + ".py"), "foreign_driver_module")
        module = importlib.import_module(name)
        require(Path(module.__file__).resolve() == base / (name + ".py"), "foreign_driver_module")
        if name == "cleanup":
            require(isinstance(getattr(module, "CLEANUP_DNS_PHP", None), str), "cleanup_readback_adapter_required")
            module.CLEANUP_DNS_PHP = aliyun_cleanup.CLEANUP_DNS_PHP
        return module

    sys.path.insert(0, str(base))
    try:
        campaign = load("campaign")
        campaign.assert_scope()
        require(campaign.RUN == run_id, "driver_identity_changed")
        driver = load("round_runner")
        require(isinstance(getattr(driver, 'RoundStopped', None), type)
                and issubclass(driver.RoundStopped, RuntimeError), 'sampling_driver_contract_required')
        class SamplingRunner(driver.RoundRunner):
            def baseline(self, role):
                try:
                    return probe_sampling.baseline(self, role)
                except probe_sampling.Refused as error:
                    raise driver.RoundStopped(str(error)) from None
        return SamplingRunner(loader=diagnostic_capture.loader_with_diagnostics(load)).run()
    finally:
        sys.path.remove(str(base))


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--work", required=True)
    args = parser.parse_args(argv)
    try:
        result = run(args.work)
        output = {"ok": result.get("ok") is True, "details": "see_local_round_records"}
    except Refused as error:
        output = {"ok": False, "reason": str(error)}
    except Exception:
        output = {"ok": False, "reason": "scoped_runner_unavailable"}
    print(json.dumps(output))
    return 0 if output["ok"] else 1


if __name__ == "__main__":
    raise SystemExit(main())
