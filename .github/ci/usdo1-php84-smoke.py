"""Temporary Debian 13 smoke: real non-root HTTPS/PHP-FPM, no grant or databases."""

import grp
import json
import os
from pathlib import Path
import pwd
import stat
import subprocess
import tempfile
import time


ROOT = Path("/app")
HOST = "receipt.example.test"
PORT = 18443
ROUTE = "/api/v2/server/machine/provision/result"
FPM_USER = pwd.getpwnam("www-data")
PROXY_USER = pwd.getpwnam("nobody")
GROUP = grp.getgrnam("www-data").gr_gid


def require(test, description):
    if not test:
        raise RuntimeError(description)


def as_user(user):
    return ["setpriv", "--reuid", str(user.pw_uid), "--regid", str(GROUP),
            "--clear-groups", "--no-new-privs"]


def confirm_user(process, user):
    status = Path(f"/proc/{process.pid}/status").read_text()
    values = dict(line.split(":", 1) for line in status.splitlines() if ":" in line)
    require(values["Uid"].split() == [str(user.pw_uid)] * 4
            and values["Gid"].split() == [str(GROUP)] * 4
            and values["NoNewPrivs"].strip() == "1", "unexpected runtime identity")


def request(cert, host=HOST, route=ROUTE, method="POST", trust=True, token="1" * 64):
    command = ["curl", "-q", "--silent", "--noproxy", "*", "--max-time", "3",
               "--resolve", f"{host}:{PORT}:127.0.0.1", "-X", method, "-H", f"Host: {host}",
               "-H", "Content-Type: application/json", "-w", "\\n%{http_code}",
               "--data", json.dumps({"task_id": "00000000-0000-4000-8000-000000000002",
                                     "installation_id": "a" * 32, "state": "succeeded"})]
    if trust:
        command += ["--cacert", str(cert)]
    if token:
        command += ["-H", f"Authorization: Bearer {token}"]
    return subprocess.run(command + [f"https://{host}:{PORT}{route}"],
                          capture_output=True, text=True, timeout=5, check=False)


def check_rejected(result, status):
    body, _, http = result.stdout.rpartition("\n")
    require(result.returncode == 0 and http == str(status)
            and json.loads(body) == {"accepted": False}, f"callback did not return {status}; got {http}")


def smoke(run):
    os.chown(run, 0, GROUP)
    run.chmod(0o750)
    fpm_dir, proxy_dir = (run / name for name in ("fpm", "proxy"))
    for directory, user in ((fpm_dir, FPM_USER), (proxy_dir, PROXY_USER)):
        directory.mkdir(mode=0o750)
        os.chown(directory, user.pw_uid, GROUP)
    socket = fpm_dir / "php.sock"
    cert, key = run / "cert.pem", run / "key.pem"
    subprocess.run(["openssl", "req", "-x509", "-nodes", "-newkey", "rsa:2048", "-days", "1",
                    "-subj", f"/CN={HOST}", "-addext", f"subjectAltName=DNS:{HOST}",
                    "-out", str(cert), "-keyout", str(key)],
                   stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=10, check=True)
    os.chown(key, PROXY_USER.pw_uid, GROUP)
    key.chmod(0o400)
    fpm_conf = run / "fpm.conf"
    fpm_conf.write_text(f"""
[global]
error_log = {fpm_dir}/error.log
[receipt]
listen = {socket}
listen.mode = 0660
pm = static
pm.max_children = 1
clear_env = yes
env[KELI_USDO1_GRANT_ID] = 00000000-0000-4000-8000-000000000001
env[KELI_USDO1_ADMIN_ID] = 1
env[KELI_USDO1_CALLBACK_ORIGIN] = https://{HOST}
""")
    proxy_conf = run / "nginx.conf"
    proxy_conf.write_text(f"""
pid {proxy_dir}/nginx.pid;
error_log {proxy_dir}/error.log warn;
events {{}}
http {{
    access_log off;
    client_body_temp_path {proxy_dir}/body;
    proxy_temp_path {proxy_dir}/proxy;
    fastcgi_temp_path {proxy_dir}/fastcgi;
    uwsgi_temp_path {proxy_dir}/uwsgi;
    scgi_temp_path {proxy_dir}/scgi;
    server {{
        listen 127.0.0.1:{PORT} ssl;
        server_name {HOST};
        ssl_certificate {cert};
        ssl_certificate_key {key};
        location = {ROUTE} {{
            client_max_body_size 2048;
            fastcgi_param SCRIPT_FILENAME {ROOT}/tools/machine-acceptance/usdo1_callback_endpoint.php;
            fastcgi_param HTTPS $https;
            fastcgi_param HTTP_HOST $http_host;
            fastcgi_param REQUEST_URI $request_uri;
            fastcgi_param REQUEST_METHOD $request_method;
            fastcgi_param CONTENT_TYPE $content_type;
            fastcgi_param CONTENT_LENGTH $content_length;
            fastcgi_param HTTP_AUTHORIZATION $http_authorization;
            fastcgi_param REDIRECT_STATUS 200;
            fastcgi_pass unix:{socket};
        }}
        location / {{ return 404; }}
    }}
}}
""")
    subprocess.run(as_user(FPM_USER) + ["test", "!", "-w", str(ROOT / "bootstrap/cache")], check=True)
    subprocess.run(as_user(FPM_USER) + ["test", "!", "-w", str(ROOT)], check=True)
    processes = []
    try:
        fpm = subprocess.Popen(as_user(FPM_USER) + ["php-fpm8.4", "-F", "-y", str(fpm_conf)])
        processes.append(fpm)
        for _ in range(40):
            if socket.is_socket():
                break
            require(fpm.poll() is None, "PHP-FPM exited")
            time.sleep(0.1)
        mode = socket.stat()
        require(mode.st_uid == FPM_USER.pw_uid and mode.st_gid == GROUP
                and stat.S_IMODE(mode.st_mode) == 0o660, "unexpected Unix socket ownership or mode")
        proxy = subprocess.Popen(as_user(PROXY_USER) + ["nginx", "-c", str(proxy_conf),
                                                        "-g", "daemon off;"])
        processes.append(proxy)
        for _ in range(40):
            result = request(cert)
            if result.returncode != 7:
                break
            require(proxy.poll() is None, "Nginx exited")
            time.sleep(0.1)
        check_rejected(result, 401)
        confirm_user(fpm, FPM_USER)
        confirm_user(proxy, PROXY_USER)
        check_rejected(request(cert, method="GET"), 405)
        check_rejected(request(cert, route=ROUTE + "?other=1"), 404)
        check_rejected(request(cert, token=None), 401)
        require(request(cert, trust=False).returncode == 60, "untrusted certificate not rejected")
        require(request(cert, host="wrong.example.test").returncode == 60, "wrong hostname not rejected")
        require(not (ROOT / "bootstrap/cache/packages.php").exists(), "package discovery wrote to release")
        print("PASS: non-root Nginx and PHP-FPM; Unix socket 0660; read-only release; "
              "trusted HTTPS, four closed/invalid requests rejected; wrong CA and hostname rejected")
    except BaseException:
        for log in (fpm_dir / "error.log", proxy_dir / "error.log"):
            if log.is_file():
                print(log.name + ": " + log.read_text()[-2000:], flush=True)
        raise
    finally:
        for process in reversed(processes):
            process.terminate()
            try:
                process.wait(timeout=3)
            except subprocess.TimeoutExpired:
                process.kill()
                process.wait(timeout=3)


if __name__ == "__main__":
    require(all(line.strip() == "lo" for line in os.listdir("/sys/class/net"))
            and Path("/sys/class/net/lo").exists(), "fixture network is not isolated")
    with tempfile.TemporaryDirectory(prefix="usdo1-php84-") as directory:
        smoke(Path(directory))
