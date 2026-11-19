"""Validate the final review templates with real systemd, a sealed root, and Docker Compose."""

from hashlib import sha256
import grp
import json
import os
from pathlib import Path
import pwd
import re
import shutil
import socket
import stat
import sys
import time
import traceback

from rootfs import RuntimeTree
from run import Lab, Rejected, ROLLBACK, REPO, SOCKET, SOURCE, call, eventually, need, properties, system


BRANCH = "audit/failover-example-assembly-linux-20261119-0e92f73d"
TEMPLATES = ("keli-failover-example.socket.in", "keli-failover-example@.service.in", "web-socket.compose.yaml.in")
STATE = Path("/var/lib/keli-failover-example")
GRANT_DIR = Path("/etc/keli-failover-example")
ROUTE = "/api/v2/fixture-admin/server/machine/cloud/failover/example"
HTTP_CLIENT = r"""$headers=implode("\r\n",['Content-Type: application/json','X-Fixture-Identity: '.$argv[3],'X-Fixture-Drop-Reply: '.$argv[5]]);
$ctx=stream_context_create(['http'=>['method'=>$argv[1],'header'=>$headers,
'content'=>$argv[4],'ignore_errors'=>true,'follow_location'=>0,'timeout'=>20]]);
$raw=@file_get_contents('http://127.0.0.1:8080'.$argv[2],false,$ctx);
if($raw===false||!isset($http_response_header[0]))exit(4);
preg_match('#^HTTP/\S+ (\d{3})\b#',$http_response_header[0],$m);
echo json_encode(['code'=>(int)($m[1]??0),'no_store'=>count(array_filter($http_response_header,
fn($h)=>str_starts_with(strtolower($h),'cache-control:')&&str_contains(strtolower($h),'no-store')))>0,
'body'=>json_decode($raw,true)],JSON_THROW_ON_ERROR);"""


class Assembly(Lab):
    def __init__(self, workspace, temp, php):
        super().__init__(workspace,temp,php)
        self.worker = "keli-example-runner"
        self.socket_group = "keli-example-web"
        self.names += (self.prefix + "-probe.service",)
        self.state_owned = self.grant_owned = False
        self.project = self.prefix.replace("keli-example-linux-", "keli-example-final-")
        self.image = None
        self.compose_initialized = False
        self.web_args = []
        self.templates = {name:(workspace / ".ci-example/templates" / name).read_text() for name in TEMPLATES}
        self.proof["template_sha256"] = {name:sha256(value.encode()).hexdigest() for name,value in self.templates.items()}

    def prepare(self):
        need(not STATE.exists() and not STATE.is_symlink() and not GRANT_DIR.exists() and not GRANT_DIR.is_symlink(),
             "proposed_names_already_in_use")
        super().prepare()
        self.advance("sealed_runtime_and_disposable_web_image")
        self.web_uid = pwd.getpwnam(self.panel).pw_uid
        self.web_gid = pwd.getpwnam(self.panel).pw_gid
        self.web_socket_gid = grp.getgrnam(self.socket_group).gr_gid
        need(self.web_socket_gid not in (0,self.uid,self.gid,self.web_gid), "invalid_dedicated_group")
        self.rootfs = RuntimeTree(self.area / "rootfs",self.php,self.uid,self.gid,self.web_uid,self.web_gid,self.web_socket_gid)
        self.image = self.rootfs.disposable_web_image(self.area / "web-root.tar")
        shutil.copytree(self.area / "candidate",self.rootfs.path / "app",dirs_exist_ok=True)
        self.proof["sealed_root_sha256"] = self.rootfs.fingerprint()
        self.proof["runtime_php_version"] = self.rootfs.version
        self.proof["runtime_omits_live_environment_and_dev_vendor"] = not any((self.rootfs.path / "app" / p).exists()
                                                                              for p in (".env","vendor/phpunit"))
        need(self.proof["runtime_omits_live_environment_and_dev_vendor"], "unsafe_runtime_inputs")
        self.state = STATE
        self.state.mkdir(mode=0o700)
        self.state_owned = True
        os.chown(self.state,self.uid,self.gid)
        self.env["FAILOVER_EXAMPLE_STATE_DIR"] = "/state"
        GRANT_DIR.mkdir(mode=0o700)
        self.grant_owned = True
        grant = GRANT_DIR / "grant.env"
        with grant.open("x",encoding="ascii") as output:
            for key,value in self.env.items():
                if key != "FAILOVER_EXAMPLE_STATE_DIR":
                    output.write(key+"="+value+"\n")
        grant.chmod(0o600)
        probe = self.state / "ci-probe.php"
        shutil.copyfile(self.workspace / ".ci-example/probe.php",probe)
        os.chown(probe,self.uid,self.gid)
        probe.chmod(0o600)
        mock = self.area / "forbidden"
        # The test socket does not point to Docker or any pre-existing service.
        decoy = mock / "sentinel.sock"
        with socket.socket(socket.AF_UNIX) as sentinel:
            sentinel.bind(str(decoy))
        datum = {"uid":self.uid,"host_netns":os.readlink("/proc/self/ns/net"),
                 "paths":[str(mock / name) for name in ("db.fixture","redis.fixture","sentinel.sock")]}
        need(all(Path(p).exists() for p in datum["paths"]), "missing_forbidden_test_fixture")
        f = self.state / "fixture-paths.json"
        f.write_text(json.dumps(datum))
        os.chown(f,self.uid,self.gid)
        f.chmod(0o600)
        fixture = self.area / "web-fixture"
        fixture.mkdir(mode=0o755)
        shutil.copyfile(self.workspace / ".ci-example/web.php",fixture / "web.php")
        self.overlay = self.area / "web-socket.yaml"
        self.overlay.write_text(self.templates[TEMPLATES[2]].replace("@WEB_SOCKET_GID@",str(self.web_socket_gid)))
        self.base_file = self.area / "web-base.yaml"
        self.negative = self.area / "web-negative.yaml"
        mounts_only = json.loads(self.overlay.read_text())
        del mounts_only["services"]["web"]["group_add"]
        self.negative.write_text(json.dumps(mounts_only))

    def start(self):
        self.advance("exact_socket_and_worker_unit")
        need(not SOCKET.parent.exists() and not any((self.units / n).exists() for n in self.names), "unowned_socket_or_unit")
        SOCKET.parent.mkdir(mode=0o750)
        os.chown(SOCKET.parent,0,self.web_socket_gid)
        self.write_unit(0,self.templates[TEMPLATES[0]])
        worker = self.templates[TEMPLATES[1]].replace("@ROOTFS@",str(self.rootfs.path))
        self.write_unit(1,worker)
        for n, mode in ((2,"stubborn"),(3,"isolation")):
            variant = worker.replace("/app/tools/failover-example/worker.php","/state/ci-probe.php "+mode)
            for field in ("StandardInput","StandardOutput"):
                variant = variant.replace(field+"=socket",field+"=null")
            self.write_unit(n,variant)
        system("daemon-reload")
        system("start",self.names[0])
        parent, child = SOCKET.parent.lstat(), SOCKET.lstat()
        need(stat.S_ISDIR(parent.st_mode) and stat.S_ISSOCK(child.st_mode) and
             (parent.st_uid,parent.st_gid,stat.S_IMODE(parent.st_mode)) == (0,self.web_socket_gid,0o750) and
             (child.st_uid,child.st_gid,stat.S_IMODE(child.st_mode)) == (0,self.web_socket_gid,0o660),"socket_group_or_mode_wrong")
        need(self.as_user(self.other,"/usr/bin/python3","-B",self.area/"sandbox.py","deny")=={"permission_denied":True},
             "unrelated_os_user_not_rejected")
        self.proof["socket_mode_0660_parent_0750_and_unrelated_user_denied"] = True
        self.advance("sealed_root_and_egress_probe")
        system("start",self.names[3])
        f = self.state/"sandbox-check.json"
        eventually(f.is_file,4,"isolated_probe_no_report")
        def probe_ready():
            try: return json.loads(f.read_text())
            except ValueError: return None
        probe = eventually(probe_ready,2,"invalid_isolated_probe_report")
        self.proof["sandbox_checks"] = probe
        need(len(probe)==10 and all(v is True for v in probe.values()) and (self.state/"probe-created").read_text()=="fixture",
             "isolated_probe_failed")

    def compose(self,*args,files=None, timeout=25, check=True):
        paths = [self.base_file, *(self.web_args if files is None else files)]
        return call("docker","compose","--project-name",self.project,
                    *(v for file in paths for v in ("-f",file)),*args,timeout=timeout,check=check)

    def web_configuration(self,root,grant):
        environment = {"EXAMPLE_FIXTURE_WEB":"isolated-ci"}
        if grant: environment |= {k:v for k,v in self.env.items() if k!="FAILOVER_EXAMPLE_STATE_DIR"}
        mounts = [{"type":"bind","source":str(self.area/name),"target":target,"read_only":True,
                   "bind":{"create_host_path":False}} for name,target in ((root,"/app"),("web-fixture","/fixture"))]
        base = {"services":{"web":{"image":self.image,"pull_policy":"never","user":f"{self.web_uid}:{self.web_gid}","network_mode":"none",
          "read_only":True,"cap_drop":["ALL"],"security_opt":["no-new-privileges:true"],"working_dir":"/app",
          "entrypoint":["/usr/bin/php","-d","display_errors=0","-d","log_errors=0","-S","127.0.0.1:8080","/fixture/web.php"],
          "environment":environment,"volumes":mounts}}}
        self.base_file.write_text(json.dumps(base))
        self.compose_initialized = True

    def launch(self,mode,root="candidate",grant=True):
        self.web_configuration(root,grant)
        self.web_args = [] if mode=="none" else [self.overlay if mode=="full" else self.negative]
        original = json.loads(self.compose("config","--format","json",files=[]).stdout)["services"]
        merged = json.loads(self.compose("config","--format","json").stdout)["services"]
        want = set() if mode=="none" else {"volumes","group_add"} if mode=="full" else {"volumes"}
        need(set(original)==set(merged)=={"web"} and {k for k in original["web"].keys()|merged["web"].keys()
                if original["web"].get(k)!=merged["web"].get(k)}==want,"compose_merge_not_web_only")
        self.compose("up","-d","--force-recreate","--no-deps","--pull","never","web",timeout=65)
        self.web_id = self.compose("ps","-q","web").stdout.decode().strip()
        need(re.fullmatch("[a-f0-9]{12,64}",self.web_id),"web_scope_ambiguous")
        shape = json.loads(call("docker","inspect","--format","{{json .Mounts}}",self.web_id).stdout)
        target = [m for m in shape if m.get("Destination")==str(SOCKET.parent)]
        need((not target if mode=="none" else len(target)==1 and target[0]["Source"]==str(SOCKET.parent)
              and target[0]["Type"]=="bind" and target[0]["RW"] is False) and not any(m["Destination"]=="/state" for m in shape)
              and call("docker","inspect","--format","{{.HostConfig.NetworkMode}}",self.web_id).stdout.decode().strip()=="none",
             "runtime_mount_or_network_changed")
        def reachable():
            try: return self.http("/fixture/identity").get("body")
            except Rejected: return None
        identity = eventually(reachable,7,"web_http_not_ready")
        need(identity["uid"] == self.web_uid and identity["uid"] > 0 and identity["sapi"]=="cli-server"
             and identity["network_namespace"] != os.readlink("/proc/self/ns/net")
             and identity["state_mount_absent"] and identity["directory_write_rejected"]
             and (self.web_socket_gid in identity["groups"])==(mode=="full"),"actual_web_process_membership_mismatch")
        return identity

    def http(self,path=ROUTE,actor="granted",action=None,drop=False):
        need(path in (ROUTE,"/fixture/identity"),"fixture_http_scope_invalid")
        body = "" if action is None else json.dumps({"action":action})
        command = self.compose("exec","-T","web","/usr/bin/php","-r",HTTP_CLIENT,"--",
                               "GET" if action is None else "POST",path,actor,body,str(int(drop)),timeout=27,check=False)
        need(command.returncode==0,"fixture_http_io_failed")
        reply = json.loads(command.stdout)
        if reply["code"]==500 and isinstance(reply.get("body"),dict):
            info=reply["body"]
            if isinstance(info.get("line"),int) and re.fullmatch(r"[a-zA-Z0-9_\\]+",info.get("class","")):
                self.proof["test_http_error"]={"class":info["class"],"line":info["line"]}
        return reply

    def request(self,action=None,code=200,actor="granted",drop=False):
        reply=self.http(ROUTE,actor,action,drop)
        need(reply["code"]==code,"unexpected_http_status_"+str(reply["code"]))
        if code==200:
            need(reply["no_store"] and isinstance(reply["body"],dict),"preview_cached_or_invalid")
            return reply["body"]["data"]
        return reply["body"]

    def run_stages(self):
        self.advance("web_mount_real_process_positive_and_negative")
        negative = self.launch("negative")
        self.proof["without_group_control"] = {"socket_visible":negative["socket_visible"],
                                              "directory_read_only":negative["directory_write_rejected"]}
        # The non-member may not traverse the parent directory; the mount was independently proved by inspect.
        n=self.accepted()
        self.request(code=503)
        need(self.accepted()==n,"without_group_connected")
        self.proof["actual_web_without_group_rejected"] = True
        yes=self.launch("full")
        need(yes["socket_visible"] and yes["socket_mode"]==0o660,"actual_web_cannot_read_socket")
        self.proof["actual_nonroot_web_group_and_read_only_mount_verified"] = True
        self.advance("two_stage_actual_web_and_lost_response")
        n = self.accepted()
        for actor,code in (("none",401),("staff",403),("other",404),("banned",404)):
            self.request(code=code,actor=actor)
        need(self.accepted()==n,"denied_http_reached_worker")
        need(self.request()["phase"]=="ready","expected_ready")
        self.request("dns",409)
        v2 = self.request("v2")
        need(v2["phase"]=="v2_passed" and v2["live_operations"]==0 and v2["v2"]=={"failure_rounds":3,"candidate_creates":1,"b_unchanged":True},
             "wrong_v2_result")
        n = self.accepted()
        need(self.request("dns",504,drop=True)=={"result_discarded":True},"http_response_not_discarded")
        done = self.request()
        need(self.accepted()==n+2 and done["phase"]=="passed" and done["live_operations"]==0 and done["v2"]==v2["v2"]
             and done["dns"]=={"a_updated":4,"b_unchanged":4,"receipt_recovered":True}, "read_only_recovery_failed")
        need(self.db_count(False)==[1,0,0,0,0] and self.db_count(True)==[1,1,8,0,0],"actual_simulation_count_mismatch")
        need(self.request("stop")==done,"stop_changed_completed_receipt")
        self.proof.update(rehearsal=done,actual_http_denials_do_not_activate_worker=True,receipt_readback_without_repost=True)

    def active_worker(self):
        for name in self.instances():
            pid = int(properties(name,"MainPID").get("MainPID",0))
            if not pid:
                continue
            try:
                args = Path("/proc",str(pid),"cmdline").read_bytes().split(b"\0")
            except FileNotFoundError:
                continue
            if b"/app/tools/failover-example/worker.php" in args:
                return name,pid
        return None

    def hard_timeout(self):
        self.advance("actual_final_worker_hard_timeout")
        process = self.as_user(self.panel,"/usr/bin/python3","-B",self.area/"sandbox.py","hold",start=True)
        try:
            instance,pid = eventually(self.active_worker,6,"held_worker_not_seen")
            proc=Path("/proc",str(pid))
            values=dict(line.split(":",1) for line in (proc/"status").read_text().splitlines() if ":" in line)
            mounts=[line.split() for line in (proc/"mountinfo").read_text().splitlines()]
            need(os.readlink(proc/"root")==str(self.rootfs.path) and [int(v) for v in values["Uid"].split()]==[self.uid]*4
                 and int(values["CapEff"],16)==0 and values["NoNewPrivs"].strip()=="1" and values["Seccomp"].strip()=="2"
                 and os.readlink(proc/"ns/net")!=os.readlink("/proc/self/ns/net")
                 and any(m[4]=="/" and "ro" in m[5].split(",") for m in mounts)
                 and any(m[4]=="/state" and "rw" in m[5].split(",") for m in mounts)
                 and not (proc/"root/run/keli-failover-example/worker.sock").exists(),"actual_worker_isolation_or_mount_differs")
            self.proof["actual_worker_chroot_uid_netns_and_mounts_verified"]=True
            raw,_ = process.communicate(timeout=18)
            result=json.loads(raw)
            props=properties(instance,"Result","MainPID","ExecMainStatus")
            need(process.returncode==0 and result["closed_without_report"] and 12_000<=result["elapsed_ms"]<16_000
                 and props["Result"]=="timeout" and props["MainPID"]=="0" and props["ExecMainStatus"] in {"15","9"},
                 "blocked_worker_not_reaped")
            self.proof["actual_worker_timeout_ms"]=result["elapsed_ms"]
        finally:
            if process.poll() is None:
                process.kill()
                process.communicate(timeout=5)
        t0=time.monotonic()
        system("start",self.names[2])
        eventually((self.state/"stubborn-ready").is_file,3,"sigterm_fixture_not_ready")
        eventually(lambda:properties(self.names[2],"ActiveState").get("ActiveState")=="failed",16,"sigkill_not_seen")
        d=properties(self.names[2],"MainPID","Result","ExecMainStatus")
        ms=round((time.monotonic()-t0)*1000)
        need(d=={"MainPID":"0","Result":"timeout","ExecMainStatus":"9"} and 13_700<=ms<16_500,"final_supervisor_kill_failed")
        self.proof["same_template_sigkill_ms"]=ms

    def rollback(self):
        self.advance("grant_revocation_mount_removal_exact_baseline")
        need(self.request()==self.proof["rehearsal"],"original_receipt_changed")
        self.launch("full",grant=False)
        n=self.accepted()
        self.request(code=404)
        self.request("v2",404)
        need(self.accepted()==n,"revoked_grant_reached_worker")
        system("stop",self.names[0])
        eventually(lambda:not SOCKET.exists() and self.active_worker() is None,5,"worker_socket_or_process_left")
        self.launch("full")
        self.request(code=503)
        self.launch("none",root="rollback",grant=False)
        self.request(code=404)
        self.request("v2",404)
        need(self.db_count(False)==[1,0,0,0,0] and self.db_count(True)==[1,1,8,0,0],"rollback_changed_fixture_receipts")
        self.proof.update(revocation_prevented_activation=True,socket_removed_no_fallback=True,
                          actual_web_restored_old_release_without_mount=True,verified_receipt_preserved=True)

    def cleanup(self):
        ok=True
        if self.compose_initialized:
            out=self.compose("down","--remove-orphans",timeout=45,check=False)
            remaining=call("docker","ps","-aq","--filter","label=com.docker.compose.project="+self.project).stdout.strip()
            ok=out.returncode==0 and not remaining
        if self.image and ok:
            ok=call("docker","image","rm",self.image,check=False,timeout=20).returncode==0
        if self.unit_files:
            system("stop",self.names[3],check=False)
            system("reset-failed",self.names[3],check=False)
        try:
            base=super().cleanup()
        finally:
            for path,owned,expected in ((STATE,self.state_owned,"/var/lib/keli-failover-example"),
                                        (GRANT_DIR,self.grant_owned,"/etc/keli-failover-example")):
                if owned:
                    need(str(path)==expected and path.resolve()==path and not path.is_symlink(),"unsafe_cleanup")
                    shutil.rmtree(path)
                    ok=ok and not path.exists()
        return ok and base


def main():
    workspace,temp,php=map(lambda v:Path(v).resolve(strict=True),sys.argv[1:])
    need(sys.platform=="linux" and os.geteuid()==0 and os.environ.get("GITHUB_ACTIONS")=="true"
         and os.environ.get("GITHUB_REPOSITORY")==REPO and os.environ.get("GITHUB_REF_NAME")==BRANCH
         and workspace.name=="keliboard" and temp.name=="_temp" and re.fullmatch("[a-f0-9]{40}",os.environ["GITHUB_SHA"])
         and re.fullmatch(r"/[a-zA-Z0-9_./-]+",str(php)),"disposable_github_runner_required")
    lab=Assembly(workspace,temp,php)
    report={"source_commit":SOURCE,"rollback_commit":ROLLBACK,"ci_commit":os.environ["GITHUB_SHA"],"checks":lab.proof,
            "synthetic_only":True,"production_connections":0,"real_cloud_creates":0,"real_dns_writes":0,"image_published":False,"ok":False}
    try:
        lab.prepare(); lab.start(); lab.run_stages(); lab.hard_timeout(); lab.rollback()
        report["ok"]=True
    except Exception as error:
        frames=[f for f in traceback.extract_tb(error.__traceback__) if Path(f.filename).name in {"assembly.py","run.py","rootfs.py"}]
        report.update(failed_stage=lab.stage,error=str(error) if isinstance(error,Rejected) else type(error).__name__,
                      fixture_location=(Path(frames[-1].filename).name+":"+str(frames[-1].lineno)) if frames else None)
    try:
        report["cleaned"]=lab.cleanup()
    except Exception as error:
        report.update(cleaned=False,cleanup_error=str(error) if isinstance(error,Rejected) else type(error).__name__)
    report["ok"] = report["ok"] and report["cleaned"]
    path=temp/"failover-example-final-result.json"
    with path.open("x",encoding="utf-8") as out:
        json.dump(report,out,indent=2)
        out.write("\n")
    path.chmod(0o644)
    print(json.dumps(report),flush=True)
    return 0 if report["ok"] else 1


if __name__=="__main__":
    sys.exit(main())
