"""Real Linux CLI and Redis socket, fixture-only, no routable network."""
import hashlib
import io
import json
import os
from pathlib import Path, PurePosixPath
import re
import socket
import stat
import subprocess
import sys
import tarfile
import tempfile
import time

from ci_empty_worker_contract import child_trace, decode_heartbeat, remove_trial_files

ROOT = Path(__file__).resolve().parents[2]
CASE = sys.argv[1]
UID = 65082
RUNTIME = Path("/runtime")
SOCKET_PARENT = Path("/trial-sock")
BOOTSTRAP = ROOT / "tests/Fixtures/ci_empty_worker_bootstrap.php"
PHP = "/usr/bin/php"
PREFIX = "keli-empty-trial:"
HEARTBEAT = PREFIX + "trial-cache:machine-failover:operator-worker:ready"
QUEUE = PREFIX + "queues:machine_failover_operator"


def need(condition, reason):
    if not condition:
        raise RuntimeError(reason)


def run(*cmd, seconds=15):
    p = subprocess.run(cmd, capture_output=True, text=True, timeout=seconds)
    if p.returncode:
        raise RuntimeError(f"fixture_{Path(cmd[0]).name}_failed")
    return p.stdout.strip()


def examine(*cmd, seconds=8):
    return subprocess.run(cmd, capture_output=True, text=True, timeout=seconds)


def sha(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def inspect_redis():
    out = examine("docker", "container", "inspect", REDIS)
    if out.returncode:
        need("No such" in out.stderr, "redis_container_identity_unknown")
        return None
    row = json.loads(out.stdout)[0]
    need(row["Config"]["Labels"].get("keli.empty-worker-ci") == STAMP, "bad_redis_fixture_identity")
    return row


def show(key):
    return run("systemctl", "show", UNIT, "--property="+key, "--value")


def known_quiescent():
    return show("ActiveState") in {"inactive", "failed"} and (show("MainPID")=="0" or show("LoadState")=="not-found")


def wait_for(fn, limit, reason):
    deadline = time.monotonic() + limit
    while time.monotonic() < deadline:
        result = fn()
        if result:
            return result
        time.sleep(0.25)
    raise RuntimeError(reason)


def redis_query(*fields):
    with socket.socket(socket.AF_UNIX) as peer:
        peer.settimeout(2)
        peer.connect(str(actual_socket))
        peer.sendall(b"*"+str(len(fields)).encode()+b"\r\n"+b"".join(
            b"$"+str(len(val.encode())).encode()+b"\r\n"+val.encode()+b"\r\n" for val in fields))
        with peer.makefile("rb") as rx:
            header = rx.readline(256)
            if header[:1] == b":":return int(header[1:])
            if header[:1] == b"+":return header[1:].strip().decode()
            if header[:1] == b"$":
                length = int(header[1:])
                need(-1 <= length <= 255, "unexpected_redis_reply_length")
                return None if length == -1 else rx.read(length).decode()
            raise RuntimeError("unexpected_redis_reply")


def redis_ready():
    if not actual_socket.is_socket():
        return False
    try:return redis_query("PING") == "PONG"
    except OSError:return False


def empty():
    return [redis_query(op, QUEUE+s) for op,s in (("LLEN",""),("ZCARD",":delayed"),("ZCARD",":reserved"))] == [0,0,0]


def stage():
    project = trial/"runtime"
    project.mkdir()
    spec = ["artisan","app","bootstrap/app.php","config","routes","resources","plugins-core","composer.json","composer.lock"]
    raw = subprocess.check_output(["git","archive","--format=tar","HEAD","--",*spec],cwd=ROOT,timeout=25)
    with tarfile.open(fileobj=io.BytesIO(raw),mode="r:") as pkg:
        for m in pkg:
            rel = PurePosixPath(m.name)
            need(not rel.is_absolute() and ".." not in rel.parts and (m.isfile() or m.isdir()),"unsafe_fixture_tar")
            dst = project/rel
            if m.isdir():dst.mkdir(parents=True,exist_ok=True)
            else:
                dst.parent.mkdir(parents=True,exist_ok=True)
                dst.write_bytes(pkg.extractfile(m).read());dst.chmod(0o444)
                tracked[dst] = sha(dst)
    boot = project/"empty-trial-entry.php"
    boot.write_bytes(BOOTSTRAP.read_bytes());boot.chmod(0o444)
    tracked[boot] = sha(boot)
    for p in ("bootstrap/cache","storage","vendor","plugins"):
        (project/p).mkdir(parents=True,exist_ok=True)
    for p in ("bootstrap-cache","storage","socket"):
        d = trial/p
        d.mkdir(mode=0o700)
        os.chown(d,UID,UID)
    for p in ("app","logs","framework","framework/cache","framework/sessions","framework/views"):
        d = trial/"storage"/p
        d.mkdir(mode=0o700)
        os.chown(d,UID,UID)
    need(not (project/".env").exists() and tracked[boot]==sha(BOOTSTRAP),"unsafe_fixture_root")
    return project


def start_local_redis():
    local_socket = actual_socket.name
    rid = run("docker","run","-d","--pull","never","--name",REDIS,"--label","keli.empty-worker-ci="+STAMP,
          "--user",f"{UID}:{UID}","--network","none","--read-only","--no-healthcheck","--cap-drop","ALL",
          "--security-opt","no-new-privileges","--memory","64m","--memory-swap","64m","--pids-limit","8",
          "--mount",f"type=bind,src={trial/'socket'},dst=/data",
          "--entrypoint","/bin/sh","redis:7-alpine","-c",
          "umask 077; exec timeout -s TERM -k 5 92 redis-server --port 0 --save '' --appendonly no --databases 1 --unixsocket /data/"
          + local_socket + " --unixsocketperm 600 --dir /data",seconds=25)
    row=inspect_redis()
    need(row and row["Id"]==rid and row["HostConfig"]["NetworkMode"]=="none" and not row["HostConfig"]["PortBindings"],
         "redis_socket_isolation_mismatch")
    wait_for(redis_ready,9,"isolated_redis_unavailable")


def start_worker(project):
    readonly = f"{project}:/runtime {ROOT/'vendor'}:/runtime/vendor {trial/'socket'}:/trial-sock"
    writable = f"{trial/'bootstrap-cache'}:/runtime/bootstrap/cache {trial/'storage'}:/runtime/storage"
    overrides = ["-d","disable_functions=posix_kill"] if CASE=="missing-signal" else []
    run("systemd-run","--quiet","--unit",UNIT,"--working-directory=/runtime","--property=Type=exec",
        "--property=Restart=no","--property=RuntimeMaxSec=46s","--property=TimeoutStopSec=8s",
        "--property=MemoryMax=192M","--property=MemorySwapMax=0","--property=CPUQuota=25%","--property=TasksMax=8",
        "--property=NoNewPrivileges=yes","--property=PrivateNetwork=yes","--property=RestrictAddressFamilies=AF_UNIX",
        "--property=ProtectSystem=strict","--property=BindReadOnlyPaths="+readonly,"--property=BindPaths="+writable,
        "--property=ReadWritePaths=/runtime/bootstrap/cache /runtime/storage",
        "--","/usr/bin/setpriv",f"--reuid={UID}",f"--regid={UID}","--clear-groups","--no-new-privs",
        "/usr/bin/env","-i","PATH=/usr/bin:/bin","APP_ENV=testing","APP_CONFIG_CACHE=bootstrap/cache/operator-config.php",
        PHP,"-d","memory_limit=160M",*overrides,"/runtime/empty-trial-entry.php","prepare",seconds=20)


def logs_trace():
    return child_trace(run("journalctl","--unit",UNIT,"--no-pager","-n","100","-o","cat"))


def live_worker():
    if known_quiescent():
        raise RuntimeError("worker_ended_before_heartbeat")
    pid=int(show("MainPID"))
    if not pid:return False
    proc=Path("/proc")/str(pid)
    cmd=(proc/"cmdline").read_bytes()
    if b"empty-trial-entry.php\0consume\0" not in cmd:return False
    keys=dict(line.split(":",1) for line in (proc/"status").read_text().splitlines() if ":" in line)
    need(keys["Uid"].split()[1]==str(UID) and keys["Gid"].split()[1]==str(UID)
         and keys["NoNewPrivs"].strip()=="1" and int(keys["CapEff"].strip(),16)==0,"worker_not_nonroot")
    cgroup=Path("/sys/fs/cgroup"+show("ControlGroup"))
    q,p=map(int,(cgroup/"cpu.max").read_text().split())
    need(q*4==p and (cgroup/"memory.max").read_text().strip()==str(192*1024*1024)
         and (cgroup/"memory.swap.max").read_text().strip()=="0" and (cgroup/"pids.max").read_text().strip()=="8",
         "unexpected_kernel_limits")
    return pid


def capture_error():
    trace = logs_trace()
    report["startup_stages"] = trace["stages"]
    if "first_error" not in report and trace["first_error"] is not None:
        report["first_error"] = trace["first_error"]
    return trace


need(os.environ.get("GITHUB_ACTIONS") == "true" and os.environ.get("GITHUB_REPOSITORY") == "keli-123456/keliboard"
     and os.environ.get("GITHUB_WORKFLOW") == "Isolated empty worker startup"
     and os.environ.get("GITHUB_REF_NAME") == "codex/operator-empty-worker-ci-20261128"
     and ROOT == Path(os.environ["GITHUB_WORKSPACE"]).resolve() and os.geteuid() == 0
     and CASE in {"heartbeat","missing-signal","missing-socket"}, "disposable_host_only")
need(not RUNTIME.exists() and not RUNTIME.is_symlink() and not SOCKET_PARENT.exists() and not SOCKET_PARENT.is_symlink(),
     "fixture_mount_anchor_already_exists")

trial=Path(tempfile.mkdtemp(prefix="keli-empty-worker-ci-",dir="/run"))
STAMP=trial.name.removeprefix("keli-empty-worker-ci-").lower()
UNIT=f"keli-empty-worker-ci-{STAMP}.service"
REDIS=f"keli-empty-worker-ci-{STAMP}-redis"
actual_socket=trial/"socket"/("missing-fixture.sock" if CASE=="missing-socket" else "redis.sock")
root_identity=(trial.stat().st_dev,trial.stat().st_ino)
tracked={}
anchors={}
report={"case":CASE,"bootstrap_sha256":sha(BOOTSTRAP),"status":"failed"}
launched=False
failed=False
try:
    for p in (RUNTIME, SOCKET_PARENT):
        p.mkdir(mode=0o755)
        anchors[p]=(p.stat().st_dev,p.stat().st_ino)
    project=stage()
    funcs=["pcntl_exec","pcntl_async_signals","pcntl_signal","pcntl_alarm","posix_geteuid","posix_getegid","posix_kill"]
    script="echo json_encode(array_map('function_exists',json_decode("+repr(json.dumps(funcs))+",true)));"
    need(all(json.loads(run(PHP,"-r",script))),"php_signal_capability_not_available")
    report["signal_functions_verified"]=len(funcs)
    start_local_redis()
    need(empty() and redis_query("DBSIZE")==0,"fixture_not_empty_at_start")
    launched=True
    start_worker(project)
    cache=trial/"bootstrap-cache/operator-config.php"
    if CASE=="heartbeat":
        pid=wait_for(live_worker,18,"worker_identity_not_observed")
        wire=wait_for(lambda:redis_query("GET",HEARTBEAT),9,"no_heartbeat_observed")
        beat=decode_heartbeat(wire)
        need(beat is not None,"heartbeat_encoding_unverified")
        report["cache_wire"]="serialized_string" if wire != beat else "raw_string"
        for _ in range(3):
            need(live_worker()==pid,"worker_identity_changed_during_heartbeat")
            need(empty(),"isolated_queues_not_empty_during_heartbeat")
            need(decode_heartbeat(redis_query("GET",HEARTBEAT))==beat,"heartbeat_owner_changed")
            need(10500<=redis_query("PTTL",HEARTBEAT)<=15000,"heartbeat_ttl_not_refreshing")
            time.sleep(4)
        trace=capture_error()
        need(trace["first_error"] is None and trace["stages"]==["runtime","autoload","config_cache","child_exec",
             "runtime","autoload","config_readback","artisan_bootstrap","operator_gate","redis_socket","queue_command"],
             "startup_sequence_mismatch")
        need(cache.is_file() and cache.stat().st_uid==UID and stat.S_IMODE(cache.stat().st_mode)==0o400,
             "independent_config_cache_not_verified")
        run("systemctl","stop",UNIT)
        wait_for(lambda:redis_query("GET",HEARTBEAT) is None,16,"heartbeat_remained_after_stop")
        need(empty(),"queue_changed_on_stop")
        report.update(heartbeat_samples=3,config_cache=True,heartbeat_removed=True,worker_non_root=True,queue_empty=True,
                      cpu_percent=25,memory_mib=192,swap_mib=0,pids_limit=8)
    else:
        wait_for(known_quiescent,22,"injury_fixture_did_not_stop")
        trace=capture_error()
        expected="runtime" if CASE=="missing-signal" else "redis_socket"
        need(show("ExecMainStatus")=="74" and trace["first_error"] and trace["first_error"]["stage"]==expected,
             "first_error_stage_or_exit_mismatch")
        if CASE=="missing-signal":
            need(trace["first_error"].get("check")=="posix_kill" and not cache.exists(),"missing_signal_gate_did_not_close")
        else:
            need(cache.is_file() and "operator_gate" in trace["stages"],"cache_generation_not_reached")
        need(empty() and redis_query("GET",HEARTBEAT) is None,"unexpected_heartbeat_or_job")
        report.update(expected_failure_verified=True,worker_exit=74,first_error_retained_before_cleanup=True)
    report["status"]="passed"
except Exception as error:
    failed=True
    code=str(error)
    report["fixture_error"]=code if re.fullmatch("[a-z0-9_]{1,90}",code) else type(error).__name__
    if launched:
        try:capture_error()
        except Exception:pass
finally:
    release_error=[]
    if launched:
        try:
            if not known_quiescent():run("systemctl","stop",UNIT)
            need(known_quiescent(),"worker_cleanup_unconfirmed")
        except Exception as e:release_error.append(type(e).__name__)
    try:
        row=inspect_redis()
        if row:run("docker","rm","-fv",row["Id"])
        need(inspect_redis() is None,"redis_cleanup_unconfirmed")
        # The production cleanup whitelist expects this synthetic socket basename.
        if actual_socket.name!="redis.sock" and actual_socket.is_socket():
            need(actual_socket.stat().st_uid==UID,"redis_socket_identity_mismatch")
            actual_socket.rename(trial/"socket/redis.sock")
    except Exception as e:release_error.append(type(e).__name__)
    if not release_error:
        try:
            remove_trial_files(trial,root_identity,tracked,UID,lambda: (not launched or known_quiescent()) and inspect_redis() is None)
            for path,i in anchors.items():
                st=path.lstat()
                need((st.st_dev,st.st_ino)==i and not any(path.iterdir()),"fixture_anchor_changed")
                path.rmdir()
        except Exception as e:release_error.append(type(e).__name__)
    report["cleanup_verified"]=not release_error and not trial.exists() and all(not p.exists() for p in anchors)
    if release_error:report["cleanup_errors"]=release_error
    print(json.dumps(report,sort_keys=True),flush=True)
    sys.exit(1 if failed or release_error or not report["cleanup_verified"] else 0)
