"""Real FPM/Nginx, role Unix sockets, and a throwaway SQLite journal, with no outer network."""

import ctypes
import json
import os
from pathlib import Path
import secrets
import shutil
import signal
import socket
import sqlite3
import stat
import subprocess
import tempfile
import time


ROOT = Path("/app")
FIXTURES = ROOT / ".github/ci"
RUN = Path("/run/keli-usdo1")
HOST = "receipt.example.test"
ROUTE = "/api/v2/server/machine/provision/result"
PORT = 18443
EDGE, RECEIPT, WORKER, PROXY, BROKER, CANDIDATE = range(1601, 1607)
ENV = {"PATH": "/usr/sbin:/usr/bin:/sbin:/bin", "LC_ALL": "C"}
PROCS = []


def require(value, message):
    if not value:
        raise RuntimeError(message)


def run(args, env=ENV, **options):
    return subprocess.run(args, env=env, stdin=subprocess.DEVNULL, capture_output=True, text=True,
                          timeout=20, check=True, **options).stdout


def sandbox(deny_connect=False):
    class Arg(ctypes.Structure):
        _fields_ = [("idx", ctypes.c_uint), ("op", ctypes.c_uint),
                    ("value", ctypes.c_uint64), ("other", ctypes.c_uint64)]

    lib = ctypes.CDLL("libseccomp.so.2", use_errno=True)
    lib.seccomp_init.argtypes = [ctypes.c_uint32]
    lib.seccomp_init.restype = ctypes.c_void_p
    lib.seccomp_rule_add_array.argtypes = [ctypes.c_void_p, ctypes.c_uint32, ctypes.c_int,
                                          ctypes.c_uint, ctypes.POINTER(Arg)]
    lib.seccomp_syscall_resolve_name.argtypes = [ctypes.c_char_p]
    lib.seccomp_load.argtypes = [ctypes.c_void_p]
    lib.seccomp_release.argtypes = [ctypes.c_void_p]
    ctx = lib.seccomp_init(0x7fff0000)
    for syscall, condition in ((b"socket", Arg(0, 1, socket.AF_UNIX, 0)),
                               (b"socketpair", Arg(0, 1, socket.AF_UNIX, 0)), (b"io_uring_setup", None),
                               *(([(b"connect", None)] if deny_connect else []))):
        nr = lib.seccomp_syscall_resolve_name(syscall)
        if not ctx or nr < 0 or lib.seccomp_rule_add_array(ctx, 0x00050001, nr, int(condition is not None),
                                                          ctypes.byref(condition) if condition else None):
            os._exit(126)
    if lib.seccomp_load(ctx):
        os._exit(126)
    lib.seccomp_release(ctx)


def as_user(uid):
    return ["setpriv", "--reuid", str(uid), "--regid", str(uid), "--clear-groups", "--no-new-privs"]


def make_dir(path, uid, mode=0o700, gid=None):
    path.mkdir()
    os.chown(path, uid, uid if gid is None else gid)
    path.chmod(mode)
    return path


def put(path, text, uid, mode=0o400):
    path.write_text(text, encoding="utf-8")
    os.chown(path, uid, uid)
    path.chmod(mode)
    return path


def process(uid, args, work, *, filter_connect=None):
    with (work / "process.log").open("ab") as out:
        proc = subprocess.Popen(as_user(uid) + args, cwd=work, env=ENV, stdin=subprocess.DEVNULL,
                                stdout=out, stderr=out, start_new_session=True,
                                preexec_fn=None if filter_connect is None else lambda: sandbox(filter_connect))
    PROCS.append(proc)
    return proc


def wait_ready(proc, ready, label):
    for _ in range(70):
        require(proc.poll() is None, label + " exited")
        if ready():
            return
        time.sleep(0.1)
    raise RuntimeError(label + " readiness timed out")


def process_identity(proc, uid, filtered):
    def check(pid):
        props = dict(line.split(":", 1) for line in Path(f"/proc/{pid}/status").read_text().splitlines() if ":" in line)
        require(props["Uid"].split() == [str(uid)] * 4 and props["Gid"].split() == [str(uid)] * 4
                and props["NoNewPrivs"].strip() == "1" and (not filtered or props["Seccomp"].strip() == "2"),
                "unexpected process UID, GID or sandbox")
    check(proc.pid)
    children_file = Path(f"/proc/{proc.pid}/task/{proc.pid}/children")
    for _ in range(24):
        children = children_file.read_text().split()
        if children:
            break
        time.sleep(0.05)
    require(len(children) == 1, "unexpected worker count")
    check(int(children[0]))


def mode_socket(sock, uid, gid):
    require(sock.is_socket() and sock.stat().st_uid == uid, "socket not owned by expected server")
    os.chown(sock, uid, gid)
    sock.chmod(0o660)
    require(stat.S_IMODE(sock.stat().st_mode) == 0o660 and sock.stat().st_gid == gid, "socket ACL invalid")


def fpm(work, uid, group, variables, filter_connect):
    sock = work / "fpm.sock"
    config = f"""
[global]
pid = {work}/fpm.pid
error_log = {work}/error.log
daemonize = no
[test]
listen = {sock}
listen.mode = 0660
pm = static
pm.max_children = 1
request_terminate_timeout = 10s
clear_env = yes
php_admin_flag[display_errors] = off
"""
    for k, v in variables.items():
        config += f"env[{k}] = {v}\n"
    path = put(work / "fpm.conf", config, uid)
    proc = process(uid, ["php-fpm8.4", "-F", "-p", str(work), "-y", str(path)], work,
                   filter_connect=filter_connect)
    wait_ready(proc, sock.is_socket, "PHP-FPM")
    mode_socket(sock, uid, group)
    process_identity(proc, uid, True)
    return sock


def fastcgi(script, sock, lane=""):
    return f"""
        fastcgi_param SCRIPT_FILENAME {script};
        fastcgi_param HTTPS $https;
        fastcgi_param HTTP_HOST $http_host;
        fastcgi_param REQUEST_URI $request_uri;
        fastcgi_param REQUEST_METHOD $request_method;
        fastcgi_param CONTENT_TYPE $content_type;
        fastcgi_param CONTENT_LENGTH $content_length;
        fastcgi_param HTTP_AUTHORIZATION $http_authorization;
        fastcgi_param REDIRECT_STATUS 200;
        {lane}
        fastcgi_pass unix:{sock};
"""


def nginx(work, uid, servers):
    conf = f"""
worker_processes 1;
pid {work}/nginx.pid;
error_log {work}/error.log warn;
events {{ worker_connections 32; }}
http {{
    access_log off;
    client_body_temp_path {work}/body;
    proxy_temp_path {work}/proxy;
    fastcgi_temp_path {work}/fastcgi;
    uwsgi_temp_path {work}/uwsgi;
    scgi_temp_path {work}/scgi;
    {servers}
}}
"""
    return process(uid, ["nginx", "-p", str(work), "-c", str(put(work/"nginx.conf", conf, uid)),
                         "-g", "daemon off;"], work)


def certificates(work, edge):
    ca, ca_key, leaf, leaf_key, csr = (work / n for n in ("ca.pem", "ca.key", "leaf.pem", "leaf.key", "leaf.csr"))
    run(["openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1",
         "-subj", "/CN=Isolated service 123 CA", "-addext", "basicConstraints=critical,CA:TRUE",
         "-keyout", str(ca_key), "-out", str(ca)])
    run(["openssl", "req", "-new", "-newkey", "rsa:2048", "-nodes", "-subj", "/CN="+HOST,
         "-keyout", str(leaf_key), "-out", str(csr)])
    extensions = put(work/"leaf.ext", f"subjectAltName=DNS:{HOST}\nextendedKeyUsage=serverAuth\n", 0)
    run(["openssl","x509","-req","-in",str(csr),"-CA",str(ca),"-CAkey",str(ca_key),"-CAcreateserial",
         "-days","1","-sha256","-extfile",str(extensions),"-out",str(leaf)])
    ca_key.unlink()
    ca.chmod(0o644)
    leaf_copy = put(edge/"leaf.pem", leaf.read_text(), EDGE)
    key_copy = put(edge/"leaf.key", leaf_key.read_text(), EDGE)
    leaf_key.unlink()
    return ca, leaf_copy, key_copy


def fixture(work):
    release = work / "release"
    shutil.copytree(ROOT, release, ignore=shutil.ignore_patterns(".github"))
    put(work/"binding.php", (FIXTURES/"usdo1-socket-binding.php").read_text(), BROKER)
    put(work/"fixture.php", (FIXTURES/"usdo1-socket-fixture.php").read_text(), BROKER)
    make_dir(work/"cache", BROKER)
    put(work/"rehearsal.sqlite", "", BROKER, 0o600)
    bootstrap = release/"bootstrap/app.php"
    code = bootstrap.read_text()
    require(code.count("return $app;")==1, "unexpected bootstrap fixture seam")
    bootstrap.write_text(code.replace("return $app;", "require dirname(__DIR__, 2).'/binding.php';\nreturn $app;"))
    variables = {"KELI_RECEIPT_FIXTURE_OWNER": secrets.token_hex(16), "KELI_RECEIPT_FIXTURE_KEY": secrets.token_hex(32)}
    return release, variables


def fixture_cli(work, variables, command):
    return json.loads(run(as_user(BROKER)+["php", str(work/"fixture.php"), command], ENV | variables,
                          preexec_fn=lambda: sandbox(True)))


def journal(path):
    with sqlite3.connect(path.as_uri()+"?mode=ro", uri=True) as db:
        require(db.execute("PRAGMA quick_check").fetchone()==("ok",), "SQLite integrity failed")
        return {"writes":dict(db.execute("SELECT kind, count(*) FROM receipt_fixture_writes GROUP BY kind")),
                "steps":db.execute("SELECT state, count(*) FROM v2_machine_provision_step GROUP BY state").fetchall(),
                "tasks":db.execute("SELECT bootstrap_state,bootstrap_at,next_check_at FROM v2_machine_provision_task").fetchall(),
                "runtime":db.execute("SELECT state,generation,lease_until,ticket_hash,next_check_at FROM v2_machine_failover_runtime").fetchall(),
                "auth":db.execute("SELECT revoked_at,revoked_by FROM v2_machine_failover_handover_grant").fetchall(),
                "other_writes":sum(db.execute("SELECT count(*) FROM "+table).fetchone()[0] for table in (
                    "v2_machine_cloud_task", "v2_machine_owned_dns", "v2_machine_dns_transition",
                    "v2_machine_failover_dns_run", "v2_machine_failover_runtime_event"))}


UNIX_CHECK = r"""
import errno,os,socket,sys
path, access = sys.argv[1:]
assert os.geteuid() != 0
with socket.socket(socket.AF_UNIX) as peer:
    try:
        peer.connect(path)
    except OSError as e:
        assert access == 'no' and e.errno == errno.EACCES, (access,e.errno)
    else:
        assert access == 'yes', 'role ACL allowed unapproved caller'
"""


def http_unix(uid, sock, path, token, data, expected):
    command = ["curl", "-q", "--silent", "--noproxy", "*", "--max-time", "5",
               "--unix-socket", str(sock), "--config", "-", "--data", json.dumps(data),
               "-w", "\n%{http_code}", "http://localhost"+path]
    result = subprocess.run(as_user(uid)+command, env=ENV, text=True, capture_output=True, timeout=8,
                            input=f'header = "Authorization: Bearer {token}"\nheader = "Content-Type: application/json"\n')
    payload, _, status = result.stdout.rpartition("\n")
    require(result.returncode==0 and status==str(expected), f"Unix HTTP {expected} expected; got {status}")
    if expected == 404:
        return None
    return json.loads(payload)


def bootstrap(work, ca, case, scenario):
    folder = make_dir(work/scenario,CANDIDATE)
    tools = make_dir(folder/"bin",CANDIDATE)
    put(tools/"ip", """#!/bin/bash
[ "$*" = '-o -4 route get fibmatch 10.25.0.1' ] || exit 91
[ "$CASE" != no_route ] || exit 2
if [ "$CASE" = default ] || [ -e "$HOME/route_lost" ]; then
    echo 'default via 10.25.0.254 dev lo'
else
    echo '10.25.0.1 dev lo scope link'
fi
""", CANDIDATE, 0o500)
    put(folder/"install.sh", """#!/bin/bash
printf 'installer\\n' > "$HOME/started"
if [ "$CASE" = lost ]; then touch "$HOME/route_lost"; fi
""", CANDIDATE)
    # Poison the default curl config with a loopback trap; the real bootstrap must ignore it.
    put(folder/".curlrc", f'url = "https://{HOST}/forbidden"\n', CANDIDATE)
    trust = "/etc/ssl/certs/ca-certificates.crt" if scenario=="untrusted" else str(ca)
    endpoint = "wrong.example.test" if scenario=="wrong_host" else HOST
    path = "/redirect" if scenario=="redirect" else ROUTE
    put(folder/"callback-transport.conf", f"""url = "https://{endpoint}{path}"
resolve = "{endpoint}:443:127.0.0.1"
connect-to = "{endpoint}:443:127.0.0.1:{PORT}"
interface = "if!lo"
noproxy = "*"
proto = "=https"
max-redirs = 0
cacert = "{trust}"
""", CANDIDATE)
    put(folder/"callback.conf", f'header = "Authorization: Bearer {case["secret"]}"\nheader = "Content-Type: application/json"\n', CANDIDATE)
    raw = (ROOT/"app/Services/ServerMachine/Cloud/ProvisionBootstrap.php").read_text()
    shell = raw.split("<<<'SH'\n",1)[1].split("\nSH;",1)[0].replace("/etc/kelinode", '"$HOME"/identity')
    check = (ROOT/"resources/server-machine/usdo1-callback-preflight.sh").read_text().replace("{{PRIVATE_IP}}","10.25.0.1").replace("{{PRIVATE_DEVICE}}","lo")
    values = {"DIAGNOSTICS":"","CALLBACK_PREFLIGHT":check,"CALLBACK_ROUTE_GUARD":" && private_callback_route",
              "DIRECTORY":str(folder),"IDENTITY":case["installation"],"TASK":case["task"],
              "CALLBACK_TRANSPORT":' -q --config "$directory/callback-transport.conf"'}
    for name,value in values.items():
        shell=shell.replace("{{"+name+"}}",value)
    require("{{" not in shell, "boot fixture incomplete")
    script = put(folder/"bootstrap.sh", shell, CANDIDATE)
    proc = subprocess.run(as_user(CANDIDATE)+["bash",str(script)],env=ENV|{"HOME":str(folder),"CASE":scenario,
                          "PATH":str(tools)+":"+ENV["PATH"],"HTTPS_PROXY":"http://127.0.0.1:7","ALL_PROXY":"http://127.0.0.1:7"},
                          stdin=subprocess.DEVNULL,text=True,capture_output=True,timeout=22)
    expected = 0 if scenario in ("lost","success") else 85
    started = (folder/"started").exists()
    require(proc.returncode==expected and started==(expected==0),
            f"bootstrap outcome differs: {scenario}, exit {proc.returncode}, started {started}")
    require((folder/"result.json").exists()==(expected==0), "bootstrap reported a preflight failure: "+scenario)
    return expected


def https(port, cert, case, expected, wrong_key=False):
    secret = secrets.token_hex(32) if wrong_key else case["secret"]
    body = json.dumps({"task_id":case["task"],"installation_id":case["installation"],"state":"succeeded"})
    p = subprocess.run(["curl", "-q","--silent","--noproxy","*","--max-time","5","--resolve",
                        f"{HOST}:{port}:127.0.0.1","--cacert",str(cert),"-H",f"Host: {HOST}","--config","-",
                        "--data",body,"-w","\n%{http_code}",f"https://{HOST}:{port}{ROUTE}"],
                       input=f'header = "Authorization: Bearer {secret}"\nheader = "Content-Type: application/json"\n',
                       env=ENV,text=True,capture_output=True,timeout=8)
    result, _, status=p.stdout.rpartition("\n")
    require(p.returncode==0 and status==str(expected) and json.loads(result)=={"accepted":expected==200},
            f"TLS HTTP {expected} expected; got {status}, curl {p.returncode}")


def exercise(work, dbhome):
    edge, proxy = (make_dir(work/name, uid) for name,uid in (("edge",EDGE),("broker-edge",PROXY)))
    callback = make_dir(work/"callback", RECEIPT, 0o750, EDGE)
    backend = make_dir(work/"broker-fpm", BROKER, 0o750, PROXY)
    boots = make_dir(work/"boots",0,0o755)
    ca, cert, private = certificates(work,edge)
    release, fixture_env = fixture(dbhome)
    case = fixture_cli(dbhome,fixture_env,"seed")
    receipt_token, worker_token = secrets.token_hex(32), secrets.token_hex(32)
    base = {"KELI_USDO1_GRANT_ID":case["grant"],"KELI_USDO1_ADMIN_ID":str(case["admin"]),
            "KELI_USDO1_CALLBACK_ORIGIN":"https://"+HOST}
    private_scope = {"KELI_USDO1_CALLBACK_PRIVATE_IP":"10.25.0.1","KELI_USDO1_CALLBACK_INTERFACE":"wg-keli123",
                     "KELI_USDO1_CALLBACK_TRUST":"system"}
    f1=fpm(callback,RECEIPT,EDGE,base|{"KELI_USDO1_DATA_TOKEN":receipt_token},False)
    f2=fpm(backend,BROKER,PROXY,base|fixture_env|private_scope|{"KELI_USDO1_BROKER_RECEIPT_TOKEN":receipt_token,
                                                              "KELI_USDO1_BROKER_WORKER_TOKEN":worker_token},True)
    rs, ws = RUN/"data-receipt.sock", RUN/"data-worker.sock"
    script=release/"tools/machine-acceptance/usdo1_data_broker.php"
    servers=""
    for lane, listen, path in (("receipt",rs,"/receipt"),("worker",ws,"/tick")):
        fcgi=fastcgi(script,f2,f"fastcgi_param KELI_USDO1_DATA_LANE {lane};")
        servers+=f"server {{ listen unix:{listen}; location = {path} {{ {fcgi} }} location / {{ return 404; }} }}\n"
    private_nginx=nginx(proxy,PROXY,servers)
    wait_ready(private_nginx,lambda: rs.is_socket() and ws.is_socket(),"Unix proxy")
    mode_socket(rs,PROXY,RECEIPT)
    mode_socket(ws,PROXY,WORKER)
    process_identity(private_nginx,PROXY,False)
    fcgi=fastcgi(ROOT/"tools/machine-acceptance/usdo1_callback_endpoint.php",f1)
    target_nginx=nginx(edge,EDGE,f"""
server {{
    listen 127.0.0.1:{PORT} ssl;
    server_name {HOST};
    ssl_certificate {cert};
    ssl_certificate_key {private};
    location = {ROUTE} {{ client_max_body_size 2048; {fcgi} }}
    location = /redirect {{ return 302 https://{HOST}/forbidden; }}
    location = /forbidden {{ access_log {edge}/fallback.log; return 418; }}
    location / {{ return 404; }}
}}
""")
    wait_ready(target_nginx,lambda: bool(run(["ss","-H","-ltn",f"( sport = :{PORT} )"]).strip()),"TLS Nginx")
    process_identity(target_nginx,EDGE,False)
    inet = run(["ss","-H","-lntu"]).strip().splitlines()
    require(len(inet)==1 and inet[0].split()[4]==f"127.0.0.1:{PORT}","non-loopback or extra INET listener")
    acl = [(RECEIPT,rs,"yes"),(WORKER,ws,"yes"),(RECEIPT,ws,"no"),(WORKER,rs,"no"),
           (CANDIDATE,rs,"no"),(CANDIDATE,ws,"no"),(RECEIPT,f2,"no"),(WORKER,f2,"no")]
    for uid,endpoint,allowed in acl:
        run(as_user(uid)+["python3","-c",UNIX_CHECK,str(endpoint),allowed])
    db=dbhome/"rehearsal.sqlite"
    prior=journal(db)
    require(not prior["writes"] and not prior["steps"] and prior["other_writes"]==0, "dirty fixture")
    request={"task_id":case["task"],"installation_id":case["installation"],"receipt_key":case["secret"],"state":"succeeded"}
    require(http_unix(WORKER,ws,"/tick",receipt_token,{},401)=={"ok":False},"receipt key could advance")
    require(http_unix(RECEIPT,rs,"/receipt",worker_token,request,401)=={"ok":False},"worker key could receipt")
    http_unix(RECEIPT,rs,"/tick",receipt_token,{},404)
    http_unix(WORKER,ws,"/receipt",worker_token,request,404)
    require(http_unix(WORKER,ws,"/tick",worker_token,{"grant_id":"untrusted"},400)=={"ok":False},"worker could select scope")
    require(http_unix(WORKER,ws,"/tick",worker_token,{},401)=={"ok":False},"running fixture unexpectedly advanced")
    https(PORT,ca,case,401,wrong_key=True)
    observed={}
    for scenario in ("no_route","default","untrusted","wrong_host","redirect","lost"):
        observed[scenario]=bootstrap(boots,ca,case,scenario)
        require(journal(db)==prior,"rejected or disconnected bootstrap changed journal")
    observed["success"]=bootstrap(boots,ca,case,"success")
    first=journal(db)
    require(first["writes"]=={"task_update":1,"receipt_insert":1,"wake_update":1}
            and first["steps"]==[("bootstrap_succeeded",1)] and first["tasks"][0][0]=="succeeded"
            and first["other_writes"]==0,"first receipt did not commit exactly once")
    https(PORT,ca,case,200)
    require(journal(db)==first,"duplicate receipt mutated journal")
    require(fixture_cli(dbhome,fixture_env,"revoke")=={"revoked":True},"revoke not committed")
    after_revoke=journal(db)
    require(after_revoke["auth"][0][0] is not None and after_revoke|{"auth":first["auth"]}==first,
            "revoke changed other state")
    https(PORT,ca,case,401)
    require(journal(db)==after_revoke,"revoked receipt mutated state")
    with sqlite3.connect(db) as admin:
        admin.execute("UPDATE v2_machine_failover_execution SET status='stopped'")
        admin.execute("UPDATE v2_machine_failover_runtime SET state='stopped'")
    fenced=journal(db)
    summary=json.loads(run(as_user(WORKER)+["php",str(ROOT/"tools/machine-acceptance/usdo1_worker_client.php")],
                           ENV|{"KELI_USDO1_DATA_TOKEN":worker_token},preexec_fn=lambda: sandbox(False)))
    require(summary=={"status":"stopped","runtime":"stopped","expired":False,"revoked":True,"stop_requested":False}
            and journal(db)==fenced,"fenced worker state or write boundary differs")
    require(not (edge/"fallback.log").exists() or (edge/"fallback.log").stat().st_size==0,"curl used alternate URL")
    return {"unix_acl_checks":len(acl),"nonroot_separate_roles":True,"http_role_denials":6,
            "tls_bootstrap_exits":observed,"route_fixture_simulated":True,"real_tls":True,
            "unauthorized":401,"first":200,"duplicate":200,"revoked":401,"duplicate_and_revoked_extra_writes":0,
            "receipt_writes":first["writes"],"stopped_worker_response":"stopped","cloud_dns_queue_writes":0}


def cleanup():
    for proc in reversed(PROCS):
        if proc.poll() is None:
            os.killpg(proc.pid,signal.SIGTERM)
    for proc in PROCS:
        try:
            proc.wait(timeout=4)
        except subprocess.TimeoutExpired:
            os.killpg(proc.pid,signal.SIGKILL)
            proc.wait(timeout=4)


def main():
    require(os.geteuid()==0 and set(os.listdir("/sys/class/net"))=={"lo"} and not (ROOT/".env").exists()
            and not RUN.exists(),"CI isolation boundary failed")
    os.umask(0o077)
    make_dir(RUN,PROXY,0o755)
    try:
        with tempfile.TemporaryDirectory(prefix="keli-socket-ci-") as temp, \
                tempfile.TemporaryDirectory(prefix="keli-123-receipts-") as data:
            work,dbhome=Path(temp),Path(data)
            work.chmod(0o755)
            os.chown(dbhome,BROKER,BROKER)
            try:
                result=exercise(work,dbhome)
            finally:
                cleanup()
    finally:
        shutil.rmtree(RUN)
    require(not work.exists() and not dbhome.exists() and not RUN.exists() and not run(["ss","-H","-lntu"]).strip(),
            "temporary resources remain")
    print("USDO1_SOCKET_CI "+json.dumps(result|{"temporary_resources_removed":True},sort_keys=True),flush=True)


if __name__ == "__main__":
    signal.signal(signal.SIGALRM, lambda *_: (_ for _ in ()).throw(TimeoutError("CI harness timed out")))
    signal.alarm(100)
    main()
