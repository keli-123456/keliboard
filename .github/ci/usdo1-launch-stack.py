"""Disposable runner only: systemd/FPM/Nginx with socket-only mock fixture data paths."""

import gzip
import grp
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import shutil
import stat
import subprocess
import sys
import tarfile
import time
import xml.etree.ElementTree as ET

ROOT = Path.cwd()
RUN = Path("/run/keli-usdo1-launch-ci")
HOST = "receipt.example.test"
PORT = 18443
ROUTE = "/api/v2/server/machine/provision/result"
BRANCH = "refs/heads/audit/usdo1-launch-linux-20261124"
PREFIX = "keli-usdo1-launch-ci-"
FPM, NGINX, WORKER = "keli123fpm", "keli123nginx", "keli123worker"
GROUP = "keli123sock"
UNITS = ("nginx", "fpm", "worker", "mysql", "redis")
BASE = "a1c08cdc003f1c2b4da3543dc79cc0e4afa36d55"


def require(ok, reason):
    if not ok:
        raise RuntimeError(reason)


def run(argv, data=None, check=True, limit=15):
    p = subprocess.run(list(map(str, argv)), input=data, capture_output=True, text=True, timeout=limit)
    if check and p.returncode:
        # Temporary service output is not an artifact or part of user-facing evidence.
        raise RuntimeError(f"{Path(str(argv[0])).name}_exit_{p.returncode}")
    return p


def unit(name, user, argv, properties=(), wait=False):
    return run(["sudo", "systemd-run", "--quiet", f"--unit={PREFIX}{name}", f"--uid={user}",
                "--property=NoNewPrivileges=yes", "--property=WorkingDirectory=" + str(RUN / "app"),
                *("--property=" + p for p in properties), *(["--wait"] if wait else []), "--", *argv],
               check=not wait, limit=22)


def status(name):
    keys = ["ActiveState", "MainPID", "Result", "ExecMainStatus", "User", "PrivateNetwork", "RestrictAddressFamilies"]
    s = run(["systemctl", "show", PREFIX + name + ".service", *("-p" + k for k in keys)]).stdout
    return dict(line.split("=", 1) for line in s.splitlines() if "=" in line)


def write(path, value, mode=0o600):
    path.write_text(value, encoding="utf-8", newline="\n")
    path.chmod(mode)


def await_socket(path):
    until = time.monotonic() + 8
    while time.monotonic() < until:
        if path.exists() and stat.S_ISSOCK(path.stat().st_mode):
            return
        time.sleep(.05)
    raise RuntimeError("fixture_socket_unavailable_" + path.name)


def curl(scope, *, who=HOST, route=ROUTE, tls=True, trust=True, overrides=None, expected=None):
    s = status("nginx")
    require(s["ActiveState"] == "active" and int(s["MainPID"]) > 1, "fixture_nginx_not_running")
    receipt = {k: scope[k] for k in ("task_id", "installation_id", "state")}
    receipt.update(overrides or {})
    header = 'header = "Authorization: Bearer ' + scope["secret"] + '"\n'
    argv = ["sudo", "nsenter", "-t", s["MainPID"], "-n", "curl", "-q", "--silent", "--max-time", "5",
            "--noproxy", "*", "--resolve", f"{who}:{PORT}:127.0.0.1", "--config", "-",
            "--header", f"Host: {who}", "--header", "Content-Type: application/json", "--data-binary", json.dumps(receipt), "--write-out", "\n%{http_code}"]
    if trust:
        argv += ["--cacert", RUN / "tls.crt"]
    p = run(argv + [f"{'https' if tls else 'http'}://{who}:{PORT}{route}"], data=header, check=False)
    if expected is not None:
        require(p.returncode == expected, "unexpected_tls_verification_result")
        return True
    require(p.returncode == 0, f"fixture_curl_exit_{p.returncode}")
    body, code = p.stdout.rsplit("\n", 1)
    if code in ("200", "401", "503"):
        require(body == ('{"accepted":true}' if code == "200" else '{"accepted":false}'),
                "unexpected_callback_body")
    return int(code)


def prepare(scope):
    require(not RUN.exists() and not (ROOT / ".env").exists(), "fixture_path_exists")
    me = pwd.getpwuid(os.getuid()).pw_name
    run(["sudo", "groupadd", "-r", GROUP])
    for user in (FPM, NGINX, WORKER):
        run(["sudo", "useradd", "-U", "-r", "-M", "-s", "/usr/sbin/nologin", user])
    for user in (FPM, NGINX):
        run(["sudo", "usermod", "-aG", GROUP, user])
    run(["sudo", "install", "-d", "-m", "0755", "-o", me, RUN])
    run(["sudo", "install", "-d", "-m", "2751", "-o", FPM, "-g", GROUP, RUN / "sockets"])
    run(["sudo", "install", "-d", "-m", "0700", "-o", FPM, RUN / "data"])
    run(["sudo", "install", "-d", "-m", "0755", "-o", WORKER, RUN / "worker"])
    run(["sudo", "install", "-d", "-m", "0700", "-o", NGINX, RUN / "nginx"])
    app = RUN / "app"
    shutil.copytree(ROOT, app, ignore=shutil.ignore_patterns(".git", ".github", ".env", "artifacts", ".phpunit.cache"))
    boot = app / "bootstrap/app.php"
    orig = boot.read_text()
    require(orig.count("return $app;") == 1, "fixture_bootstrap_context_changed")
    binding = (ROOT / ".github/ci/usdo1-fpm-binding.php").read_text()
    write(app / "bootstrap/usdo1-fixture.php", binding, 0o644)
    write(boot, orig.replace("return $app;", "require __DIR__ . '/usdo1-fixture.php';\nreturn $app;"), 0o644)
    shutil.copyfile(ROOT / ".github/ci/usdo1-timeout-worker.php", app / "bootstrap/usdo1-timeout-worker.php")
    run(["openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1", "-subj", f"/CN={HOST}",
         "-addext", f"subjectAltName=DNS:{HOST}", "-out", RUN / "tls.crt", "-keyout", RUN / "tls.tmp"])
    run(["sudo", "install", "-o", NGINX, "-m", "0400", RUN / "tls.tmp", RUN / "tls.key"])
    (RUN / "tls.tmp").unlink()
    for name in ("mysql", "redis"):
        port = int(os.environ[f"KELI_NATIVE_{name.upper()}_PORT"])
        require(1024 < port < 65536 and port not in (3306, 6379), "fixture_port_refused")
        path = RUN / "data" / (name + ".sock")
        unit(name, FPM, ["/usr/bin/socat", f"UNIX-LISTEN:{path},fork,mode=0600", f"TCP4:127.0.0.1:{port}"],
             ["Restart=no", "RestrictAddressFamilies=AF_UNIX AF_INET", "IPAddressDeny=any", "IPAddressAllow=localhost"])
        # IPAddress allow-lists do not apply to filesystem sockets.
        run(["sudo", "-u", FPM, "test", "-d", RUN / "data"])
    deadline = time.monotonic() + 8
    while True:
        ready = run(["sudo", "-u", FPM, "test", "-S", RUN / "data/mysql.sock", "-a", "-S", RUN / "data/redis.sock"], check=False)
        if ready.returncode == 0:
            break
        require(time.monotonic() < deadline, "fixture_data_bridge_not_ready")
        time.sleep(.05)
    sock = RUN / "sockets/php.sock"
    env = {
        "KELI_USDO1_GRANT_ID": scope["grant"], "KELI_USDO1_ADMIN_ID": str(scope["admin"]),
        "KELI_USDO1_CALLBACK_ORIGIN": "https://" + HOST, "KELI_CI_FPM_FIXTURE": "1",
        "KELI_CI_OWNER": scope["owner"], "KELI_CI_MYSQL_PASSWORD": os.environ["KELI_NATIVE_PASSWORD"],
    }
    require(all(re.fullmatch(r"[A-Za-z0-9.:/-]+", v) for v in env.values()), "fixture_env_invalid")
    fpm = f"""[global]
error_log = {RUN}/sockets/fpm.log
[usdo1]
user = {FPM}
group = {FPM}
listen = {sock}
listen.owner = {FPM}
listen.group = {GROUP}
listen.mode = 0660
pm = static
pm.max_children = 1
clear_env = yes
catch_workers_output = yes
security.limit_extensions = .php
php_admin_value[opcache.enable] = 0
"""
    for k, v in env.items():
        fpm += f"env[{k}] = {v}\n"
    conf = RUN / "fpm.conf"
    write(conf, fpm)
    run(["sudo", "chown", FPM, conf])
    net = ["PrivateNetwork=yes", "RestrictAddressFamilies=AF_UNIX", "ProtectSystem=strict"]
    unit("fpm", FPM, ["/usr/sbin/php-fpm8.3", "-F", "-y", conf], net + [f"ReadWritePaths={RUN}/sockets"])
    await_socket(sock)
    st = sock.stat()
    require(st.st_uid == pwd.getpwnam(FPM).pw_uid and st.st_gid == grp.getgrnam(GROUP).gr_gid
            and stat.S_IMODE(st.st_mode) == 0o660, "fixture_fpm_socket_mode")
    require(run(["sudo", "-u", "nobody", "/usr/bin/socat", "-T", "1", "-", f"UNIX-CONNECT:{sock}"],
                data="", check=False).returncode != 0, "unrelated_uid_could_connect")
    config = RUN / "nginx.conf"
    block = f"ssl_certificate {RUN}/tls.crt; ssl_certificate_key {RUN}/tls.key;"
    script = app / "tools/machine-acceptance/usdo1_callback_endpoint.php"
    write(config, f"""pid {RUN}/nginx/nginx.pid;
error_log stderr warn;
events {{ worker_connections 32; }}
http {{
  access_log off;
  client_body_temp_path {RUN}/nginx/body;
  proxy_temp_path {RUN}/nginx/proxy;
  fastcgi_temp_path {RUN}/nginx/fastcgi;
  uwsgi_temp_path {RUN}/nginx/uwsgi;
  scgi_temp_path {RUN}/nginx/scgi;
  server {{ listen 127.0.0.1:{PORT} ssl default_server; server_name _; {block} return 421; }}
  server {{
    listen 127.0.0.1:{PORT} ssl; server_name {HOST}; {block}
    location / {{ return 404; }}
    location = {ROUTE} {{
      client_max_body_size 2048;
      fastcgi_param SCRIPT_FILENAME {script};
      fastcgi_param HTTPS $https;
      fastcgi_param HTTP_HOST $http_host;
      fastcgi_param REQUEST_URI $request_uri;
      fastcgi_param REQUEST_METHOD $request_method;
      fastcgi_param CONTENT_TYPE $content_type;
      fastcgi_param CONTENT_LENGTH $content_length;
      fastcgi_param HTTP_AUTHORIZATION $http_authorization;
      fastcgi_param REDIRECT_STATUS 200;
      fastcgi_pass unix:{sock};
    }}
  }}
}}
""", 0o644)
    unit("nginx", NGINX, ["/usr/sbin/nginx", "-c", config, "-g", "daemon off;"],
         ["PrivateNetwork=yes", "RestrictAddressFamilies=AF_UNIX AF_INET AF_INET6", "ProtectSystem=strict",
          f"ReadWritePaths={RUN}/nginx"])
    until = time.monotonic() + 8
    while True:
        try:
            if curl(scope, route="/") == 404:
                break
        except RuntimeError:
            pass
        require(time.monotonic() < until, "fixture_nginx_readiness")
        time.sleep(.1)
    for name, user in (("fpm", FPM), ("nginx", NGINX)):
        s = status(name)
        uid = pwd.getpwnam(user).pw_uid
        text = Path(f"/proc/{s['MainPID']}/status").read_text()
        require(s["User"] == user and s["PrivateNetwork"] == "yes"
                and re.search(rf"^Uid:\s+{uid}\s+{uid}\s+{uid}\s+{uid}$", text, re.M),
                "fixture_runtime_identity_" + name)
    require(status("fpm")["RestrictAddressFamilies"].strip() == "AF_UNIX", "fixture_fpm_has_ip_family")
    require(run(["sudo", "-u", NGINX, "test", "-x", RUN / "data"], check=False).returncode != 0,
            "tls_proxy_could_access_data_sockets")


def timeout(scope):
    props = ["Type=oneshot", "TimeoutStartSec=3s", "TimeoutStopSec=2s", "Restart=no", "KillMode=control-group",
             "PrivateNetwork=yes", "RestrictAddressFamilies=AF_UNIX", "ProtectSystem=strict",
             f"ReadWritePaths={RUN}/worker",
             "Environment=KELI_CI_TIMEOUT_FIXTURE=1 KELI_USDO1_GRANT_ID=" + scope["grant"]
             + " KELI_USDO1_ADMIN_ID=" + str(scope["admin"]) + " KELI_USDO1_CALLBACK_ORIGIN=https://" + HOST]
    started = time.monotonic()
    p = unit("worker", WORKER, ["/usr/bin/php8.3", RUN / "app/bootstrap/usdo1-timeout-worker.php"], props, wait=True)
    elapsed = time.monotonic() - started
    s = status("worker")
    require(p.returncode != 0 and s["Result"] == "timeout" and s["ExecMainStatus"] == "9" and s["MainPID"] == "0"
            and s["User"] == WORKER and s["PrivateNetwork"] == "yes" and 3 <= elapsed < 12,
            "fixture_worker_hard_timeout_not_proven")
    stamp = RUN / "worker/started"
    require(stamp.read_text() == "entered_worker" and stamp.stat().st_uid == pwd.getpwnam(WORKER).pw_uid,
            "fixture_worker_guard_not_reached")
    return round(elapsed, 2)


def diagnose_callback(scope, codes):
    # Retry a rejected request in a scratch-only instrumented copy. An instrumented result never passes CI.
    trace = RUN / "sockets/fpm-diagnostic"
    for file in ("tools/machine-acceptance/usdo1_callback_endpoint.php",
                 "app/Services/ServerMachine/Cloud/MachineUsdo1Runtime.php"):
        path = RUN / "app" / file
        text = path.read_text()
        if "catch (\\Throwable) {" not in text:
            continue
        item = "catch (\\Throwable $fixtureError) {\n"
        item += "file_put_contents('" + str(trace) + "', get_class($fixtureError).':'.basename($fixtureError->getFile()).':'.$fixtureError->getLine());"
        write(path, text.replace("catch (\\Throwable) {", item), 0o644)
    try:
        curl(scope, overrides={"installation_id":"e" * 32})
    except Exception:
        pass
    p = run(["sudo", "test", "-f", trace], check=False)
    hint = run(["sudo", "cat", trace]).stdout.strip() if p.returncode == 0 else "none"
    hint = hint.replace("\\", "_")
    if not re.fullmatch(r"[A-Za-z0-9_.:-]{1,120}", hint):
        hint = "unavailable"
    raise RuntimeError("fixture_callback_status_" + "_".join(map(str, codes)) + ":safe_trace_" + hint)


def cleanup():
    names = [PREFIX + u + ".service" for u in UNITS]
    run(["sudo", "systemctl", "stop", *names], check=False, limit=20)
    for u in UNITS:
        require(status(u).get("ActiveState") not in ("active", "activating", "deactivating"), "fixture_unit_not_stopped")
    run(["sudo", "systemctl", "reset-failed", *names], check=False)
    if RUN.exists():
        require(not RUN.is_symlink() and RUN.resolve() == RUN, "fixture_cleanup_path")
        run(["sudo", "rm", "-rf", "--one-file-system", "--", RUN], limit=30)
    require(not RUN.exists(), "fixture_cleanup_incomplete")


def freeze():
    def report(key):
        cases = list(ET.parse(Path(os.environ["RUNNER_TEMP"]) / f"usdo1-{key}.xml").getroot().iter("testcase"))
        require(cases and not any(c.find(x) is not None for c in cases for x in ("error", "failure", "skipped")), "junit_not_green")
        return {"tests": len(cases), "assertions": sum(int(c.attrib.get("assertions", 0)) for c in cases)}
    output = ROOT / "artifacts"
    proof = json.loads((output / "usdo1-launch-summary.json").read_text())
    require(proof["https_accepted"] and proof["revoked_rejected"] and proof["hard_timeout_seconds"]
            and not RUN.exists(), "launch_evidence_incomplete")
    sha = run(["git", "rev-parse", "HEAD"]).stdout.strip()
    require(sha == os.environ["GITHUB_SHA"], "fixture_revision_changed")
    proof.update({"revision":sha, "native":report("native"), "linux_launch":report("launch"),
                  "cleanup_confirmed":True, "cloud_mocked":True, "dns_mocked":True})
    write(output / "usdo1-launch-summary.json", json.dumps(proof, indent=2) + "\n", 0o644)
    overlay = run(["git", "diff", "--name-only", BASE, sha, "--", "app", "tools/machine-acceptance/usdo1_callback_endpoint.php"]).stdout.splitlines()
    paths = sorted(overlay + ["composer.lock"])
    require(len(overlay) == 7 and all(p.startswith("app/") or p.endswith("/usdo1_callback_endpoint.php") for p in overlay),
            "freeze_scope_changed")
    dest = output / "usdo1-execution-inputs.tar.gz"
    archive = subprocess.check_output(["git", "archive", "--format=tar", sha, "--", *paths], timeout=10)
    dest.write_bytes(gzip.compress(archive, compresslevel=9, mtime=0))
    with tarfile.open(dest, "r:gz") as tar:
        members = {f.name: hashlib.sha256(tar.extractfile(f).read()).hexdigest() for f in tar.getmembers() if f.isfile()}
    require(list(sorted(members)) == paths, "frozen_file_list_changed")
    write(output / "usdo1-launch-manifest.json", json.dumps({"base":BASE, "tested_revision":sha,
          "package_kind":"reviewed_overlay_not_configured_for_production", "files":members,
          "archive_sha256":hashlib.sha256(dest.read_bytes()).hexdigest()}, indent=2) + "\n", 0o644)


def main():
    require(os.environ.get("GITHUB_ACTIONS") == "true" and os.environ.get("GITHUB_REF") == BRANCH
            and os.environ.get("KELI_USDO1_ACCEPTANCE") == "1" and os.environ.get("KELI_NATIVE_HOST") == "127.0.0.1"
            and os.environ.get("KELI_NATIVE_DATABASE") == "keli_acceptance_native_failover" and os.getuid() != 0,
            "runner_fixture_only")
    action = sys.argv[1]
    if action == "cleanup":
        cleanup()
    elif action == "freeze":
        freeze()
    else:
        scope = json.loads(sys.stdin.read(2049))
        require(isinstance(scope["admin"], int) and scope["admin"] > 0 and scope["state"] == "succeeded"
                and all(re.fullmatch(pattern, scope[k]) for k, pattern in (
                    ("grant", r"[a-f0-9-]{36}"), ("task_id", r"[a-f0-9-]{36}"), ("installation_id", r"[a-f0-9]{32}"),
                    ("secret", r"[a-f0-9]{64}"), ("owner", r"[a-f0-9]{32}"))), "fixture_scope_invalid")
        if action == "start":
            prepare(scope)
            hard = timeout(scope)
            require(curl(scope, trust=False, expected=60) and curl(scope, who="other.example.test", expected=60),
                    "fixture_tls_negatives_failed")
            require(curl(scope, route=ROUTE + "?grant=other") == 404 and curl(scope, tls=False) == 400,
                    "fixture_http_scope_not_fenced")
            invalid = [curl(scope, overrides={"installation_id":"e" * 32}), curl(scope | {"secret":"0" * 64})]
            if invalid != [401, 401]:
                diagnose_callback(scope, invalid)
            valid = [curl(scope), curl(scope)]
            if valid != [200, 200]:
                diagnose_callback(scope, valid)
            print(json.dumps({"https_accepted":True, "tls_hostname_and_ca_checked":True, "fpm_unix_only":True,
                              "wrong_id_or_secret_rejected":True, "non_root_proxy_and_fpm":True, "unrelated_uid_denied":True,
                              "hard_timeout_seconds":hard}))
        elif action == "revoked":
            require(curl(scope) == 401, "fixture_revoked_receipt_accepted")
            print('{"revoked_rejected":true}')
        else:
            raise RuntimeError("unsupported_fixture_action")


if __name__ == "__main__":
    try:
        main()
    except Exception as e:
        # No ingress body, credential, provider response, or raw process logs in the report.
        print(f"fixture_error:{type(e).__name__}:{str(e) if type(e) is RuntimeError else ''}", file=sys.stderr)
        sys.exit(1)
