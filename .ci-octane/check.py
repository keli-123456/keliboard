"""Disposable local Compose validation of the Web-only mount with real Octane."""

from hashlib import sha256
import io
import json
import os
from pathlib import Path
import re
import shutil
import socket
import stat
import subprocess
import sys
import tarfile
import threading
import time
import traceback


BASE = '984af21185a71879425a9796aadc22ef8f745d62'
TARGET = '0e92f73dc987156d65bb2399eaddf904d607330f'
BRANCH = 'audit/failover-example-octane-linux-20261119-0e92f73d'
UID = GID = 1000
SOCKET_GID = 43103
SOCKET_DIR = Path('/run/keli-failover-example')
SOCKET = SOCKET_DIR/'worker.sock'
ADMIN = 'public/assets/admin-xboard/'
ROUTE = '/api/v2/fixture-admin/server/machine/cloud/failover/example'
FILES = ('app/Http/Routes/V2/AdminRoute.php', 'app/Http/Controllers/V2/Admin/Server/MachineCloudController.php',
         'app/Http/Controllers/V2/Admin/Server/MachineFailoverExampleController.php', 'config/failover_example.php',
         *(ADMIN+p for p in ('index.html','build-manifest.json','assets/index.js','assets/index.css')))
GROUP = ('horizon','ws-server')


class Refused(RuntimeError):
    pass


def need(ok, reason):
    if not ok:
        raise Refused(reason)


def cmd(*args, timeout=25, check=True):
    answer = subprocess.run(args, capture_output=True, timeout=timeout)
    if check:
        need(not answer.returncode,'command_failed_' + Path(args[0]).name)
    return answer


def sha(path):
    return sha256(path.read_bytes()).hexdigest()


def data(path, value):
    path.write_text(json.dumps(value), encoding='ascii')


def eventually(read, seconds, label):
    until = time.monotonic()+seconds
    while time.monotonic()<until:
        value=read()
        if value:
            return value
        time.sleep(.18)
    raise Refused(label)


class Listener:
    def __init__(self):
        self.accepted = self.valid = 0
        self.server = socket.socket(socket.AF_UNIX)
        self.server.settimeout(.2)
        self.server.bind(str(SOCKET))
        os.chown(SOCKET,0,SOCKET_GID)
        SOCKET.chmod(0o660)
        self.server.listen(4)
        self.stopping = False
        self.thread = threading.Thread(target=self.serve)
        self.thread.start()

    def serve(self):
        while not self.stopping:
            try:
                peer,_=self.server.accept()
            except TimeoutError:
                continue
            with peer:
                self.accepted+=1
                peer.settimeout(1)
                with peer.makefile('rb') as stream:
                    if stream.readline(64)==b'octane-acl-only\n':
                        self.valid+=1
                        peer.sendall(b'fixture-ok\n')

    def close(self):
        self.stopping=True
        self.thread.join(timeout=3)
        self.server.close()
        need(not self.thread.is_alive(),'acl_fixture_did_not_stop')
        SOCKET.unlink()


class Check:
    def __init__(self, repo, temp):
        self.repo,self.temp = repo,temp
        number=os.environ['GITHUB_RUN_ID']; attempt=os.environ['GITHUB_RUN_ATTEMPT']
        need(re.fullmatch('[0-9]{1,14}',number) and re.fullmatch('[0-9]{1,3}',attempt),'unsafe_fixture_identity')
        self.project = f'keli-octane-{number}-{attempt}'
        self.root = Path('/run',self.project)
        self.image = 'keli-octane-fixture:'+number+'-'+attempt
        self.base,self.render,self.deny = (self.root/name for name in ('base.json','candidate.json','without-group.json'))
        self.old,self.candidate,self.fixture = (self.root/name for name in ('old','candidate','fixture'))
        self.proof,self.files = {},{}
        self.made=self.bind_dir=False
        self.server=None
        self.stage='scope'

    def step(self,name):
        self.stage=name
        print('octane acceptance: '+name,flush=True)

    def export(self,dest,revision,asset_revision):
        arc=cmd('git','-c','safe.directory='+str(self.repo),'-c','core.autocrlf=false','-c','core.eol=lf',
                '-C',self.repo,'archive',revision, timeout=40).stdout
        dest.mkdir(mode=0o755)
        with tarfile.open(fileobj=io.BytesIO(arc)) as tar:
            tar.extractall(dest,filter='data')
        need(not (dest/'.env').exists() and not (dest/'vendor').exists(),'unexpected_environment_or_vendor')
        mf=json.loads((dest/(ADMIN+'build-manifest.json')).read_text())
        need(mf['source_git_short_sha']==asset_revision and mf['source_dirty'] is False,'asset_source_mismatch')
        html=(dest/(ADMIN+'index.html')).read_text()
        for name,entry in mf['files'].items():
            p=dest/(ADMIN+name)
            need(p.stat().st_size==entry['bytes'] and sha(p)==entry['sha256'],'asset_manifest_mismatch')
            if name in ('assets/index.js','assets/index.css'):
                need('/assets/admin-xboard/'+name+'?v='+entry['sha256'][:12] in html,'uncorrelated_asset_url')
        self.files[revision]={p:sha(dest/p) if (dest/p).is_file() else None for p in FILES}

    def prepare(self):
        self.step('frozen_code_assets_and_web_only_compose')
        need(not self.root.exists() and not SOCKET_DIR.exists() and not SOCKET_DIR.is_symlink(),'fixture_paths_in_use')
        self.root.mkdir(mode=0o755)
        self.made=True
        self.export(self.old,BASE,'cac50ff')
        self.export(self.candidate,TARGET,'c718b74')
        need(sha(self.old/'composer.lock')==sha(self.candidate/'composer.lock'),'shared_dependencies_changed')
        vendor=self.repo/'vendor'
        need((vendor/'laravel/octane/bin/swoole-server').is_file() and not (vendor/'phpunit').exists(),'unexpected_composer_install')
        # This shared path is mounted read-only in all three disposable containers.
        shutil.copytree(vendor,self.old/'vendor')
        shutil.copytree(self.repo/'.ci-octane/fixture',self.fixture)
        (self.fixture/'vendor').symlink_to('/www/vendor',target_is_directory=True)
        (self.old/'bootstrap/cache').mkdir(parents=True,exist_ok=True)
        (self.old/'bootstrap/cache/shared-anchor').write_text('old synthetic cache\n')
        self.anchor = sha(self.old/'bootstrap/cache/shared-anchor')
        SOCKET_DIR.mkdir(mode=0o750)
        self.bind_dir=True
        os.chown(SOCKET_DIR,0,SOCKET_GID); SOCKET_DIR.chmod(0o750)
        self.server=Listener()
        need(stat.S_IMODE(SOCKET.lstat().st_mode)==0o660 and SOCKET.lstat().st_gid==SOCKET_GID,'wrong_fixture_socket_mode')
        template=(self.repo/'.ci-octane/web-only.compose.yaml.in').read_text()
        self.proof['overlay_sha256']=sha256(template.encode()).hexdigest()
        for k,v in {'RELEASE':self.candidate,'WEB_UID':UID,'WEB_GID':GID,'SOCKET_GID':SOCKET_GID}.items():
            template=template.replace('@'+k+'@',str(v))
        candidate=json.loads(template)
        need(set(candidate['services'])=={'web'},'overlay_changed_non_web_service')
        data(self.render,candidate)
        candidate['services']['web'].pop('group_add')
        data(self.deny,candidate)
        paths=('CONFIG','ROUTES','PACKAGES','SERVICES','EVENTS')
        default={'image':self.image,'pull_policy':'never','network_mode':'none','read_only':True,'cap_drop':['ALL'],
                 'security_opt':['no-new-privileges:true'],'working_dir':'/www',
                 'tmpfs':['/state:mode=0777,nosuid,nodev,size=8m','/tmp:mode=1777,nosuid,nodev,size=8m'],
                 'volumes':[{'type':'bind','source':str(src),'target':dest,'read_only':True,'bind':{'create_host_path':False}}
                            for src,dest in ((self.old,'/www'),(self.fixture,'/fixture'))],
                 'environment':{'KELI_OCTANE_FIXTURE':'isolated-ci','APP_ENV':'testing','APP_NAME':'KeliOctaneFixture','APP_DEBUG':'false',
                     'CACHE_STORE':'array','SESSION_DRIVER':'array','QUEUE_CONNECTION':'fixture_disabled','DB_CONNECTION':'fixture_disabled',
                     'LOG_CHANNEL':'stderr',**{'APP_'+k+'_CACHE':'/state/cache/'+k.lower()+'.php' for k in paths}}}
        command=['octane:start','--server=swoole','--host=127.0.0.1','--port=7001','--workers=2','--task-workers=1','--max-requests=128']
        data(self.base,{'services':{
             'web':{**default,'user':'0:0','entrypoint':['php','/fixture/artisan'],'command':command},
             **{name:{**default,'user':f'{UID}:{GID}','entrypoint':['php','/fixture/witness.php']} for name in GROUP}}})

    def compose(self,*args,override=None,timeout=30,check=True):
        files=['-f',self.base,*(['-f',override] if override else [])]
        return cmd('docker','compose','--project-name',self.project,*files,*args,timeout=timeout,check=check)

    def metadata(self,name):
        cid=self.compose('ps','-q',name).stdout.decode().strip()
        need(re.fullmatch('[a-f0-9]{12,64}',cid),'fixture_container_not_running')
        details=json.loads(cmd('docker','inspect',cid).stdout)[0]
        need(details['State']['Running'] and details['HostConfig']['NetworkMode']=='none'
             and not details['HostConfig']['PortBindings'],'fixture_network_or_service_mismatch')
        return details

    def witness(self):
        result={}
        for role in GROUP:
            actual=self.metadata(role)
            content=eventually(lambda:self.witness_read(role),6,'no_persistent_witness')
            need(content['files']==self.files[BASE] and content['socket_absent'] is True,'other_service_saw_candidate')
            need(not any(m['Destination'] in ('/www/app','/www/config','/www/public/assets/admin-xboard',str(SOCKET_DIR))
                         for m in actual['Mounts']),'candidate_mounted_to_other_service')
            result[role]={'id':actual['Id'],'pid':actual['State']['Pid'],'started':actual['State']['StartedAt'],'tick':content['tick']}
        return result

    def witness_read(self,role):
        check=self.compose('exec','-T',role,'php','-r','echo @file_get_contents("/state/read.json");',check=False,timeout=8)
        if check.returncode or not check.stdout:
            return None
        return json.loads(check.stdout)

    def http(self,path):
        r=self.compose('exec','-T','web','php','/fixture/http.php',path,check=False,timeout=10)
        if r.returncode:
            self.proof['http_probe_last_exit']=r.returncode
        return None if r.returncode else json.loads(r.stdout)

    def processes(self,meta):
        rows=cmd('docker','top',meta['Id'],'-eo','pid,args').stdout.decode().splitlines()
        workers={}
        for row in rows[1:]:
            pid,args=row.strip().split(None,1)
            if not re.search(r'swoole_http_server:\s+worker process\b',args):
                continue
            s=dict(l.split(':',1) for l in Path('/proc',pid,'status').read_text().splitlines() if ':' in l)
            workers[int(s['NSpid'].split()[-1])]={'uid':list(map(int,s['Uid'].split())), 'gid':list(map(int,s['Gid'].split())),
                             'groups':list(map(int,s['Groups'].split())), 'no_caps':int(s['CapEff'],16)==0}
        return workers if len(workers)==2 else None

    def web(self,mode):
        candidate=mode in ('allow','deny')
        override=self.render if mode=='allow' else self.deny if mode=='deny' else None
        before=json.loads(self.compose('config','--format','json').stdout)['services']
        after=json.loads(self.compose('config','--format','json',override=override).stdout)['services']
        want=({'user','tmpfs','environment','volumes'} | ({'group_add'} if mode=='allow' else set())) if candidate else set()
        need(set(before)==set(after)=={'web',*GROUP} and all(before[k]==after[k] for k in GROUP) and
             {k for k in before['web'].keys()|after['web'].keys() if before['web'].get(k)!=after['web'].get(k)}==want,
             'compose_outside_web_scope')
        self.compose('up','-d','--force-recreate','--no-deps','--pull','never','web',override=override,timeout=60)
        first=eventually(lambda:self.http('/_ci_octane_identity'),22,'octane_not_listening')
        if first['code']!=200 or not first['json']:
            self.proof['identity_http_status']=first['code']
        need(first['code']==200 and bool(first['json']),'octane_identity_response_failed')
        body=first['json']
        actual=self.metadata('web')
        workers=eventually(lambda:self.processes(actual),5,'actual_swoole_workers_missing')
        uid,gid=(UID,GID) if candidate else (0,0)
        need(body['pid'] in workers and body['uid']==uid and body['gid']==gid and body['octane'] and body['swoole']
             and body['php'].startswith('8.2.') and body['admin_loaded_from_release'] is True and int(body['cap_eff'],16)==0
             and all(p['uid']==[uid]*4 and p['gid']==[gid]*4 and p['no_caps'] and (SOCKET_GID in p['groups'])==(mode=='allow') for p in workers.values())
             and (SOCKET_GID in body['groups'])==(mode=='allow') and body['grant_supplied'] is False,'web_process_credentials_wrong')
        mounts={m['Destination']:m for m in actual['Mounts']}
        required={'/www/app':self.candidate/'app','/www/config':self.candidate/'config',
                  '/www/public/assets/admin-xboard':self.candidate/ADMIN.rstrip('/'),str(SOCKET_DIR):SOCKET_DIR}
        if candidate:
            need(all(k in mounts and mounts[k]['Type']=='bind' and mounts[k]['Source']==str(v) and mounts[k]['RW'] is False for k,v in required.items())
                 and all(body['mounts'].get(k)=='ro' for k in required) and body['mounts'].get('/www/bootstrap/cache')=='rw'
                 and '/www/bootstrap/cache' in (actual['HostConfig']['Tmpfs'] or {}) and not body['cache_anchor_present']
                 and body['config_cache_path']=='/www/bootstrap/cache/config.php','web_mounts_or_cache_not_isolated')
        else:
            need(not set(required)&set(mounts) and not (set(required)&set(body['mounts']))
                 and body['cache_anchor_present'] and body['socket_mode'] is None,'rollback_mount_still_present')
        revision = TARGET if candidate else BASE
        need(body['files']==self.files[revision],'running_web_release_mismatch')
        n=self.server.accepted
        closed=self.http(ROUTE)
        need(closed and closed['code']==404 and self.server.accepted==n,'closed_entry_touched_socket')
        if candidate:
            a=self.http('/_ci_octane_identity?local_test=1')
            need(a and a['code']==200 and a['json']['pid'] in workers,'acl_probe_not_served_by_octane')
            if mode=='allow':
                need(a['json']['acl']=='fixture_reply' and a['json']['socket_mode']==0o660
                     and self.server.accepted==self.server.valid==n+1,'grouped_octane_cannot_connect')
            else:
                need(a['json']['acl']=='denied' and a['json']['socket_mode'] is None and self.server.accepted==n,'ungrouped_octane_connected')
        for relative in ('index.html','assets/index.js','assets/index.css'):
            file=self.files[revision][ADMIN+relative]
            path='/assets/admin-xboard/'+relative+('?v='+file[:12] if relative.startswith('assets/') else '')
            asset=self.http(path)
            need(asset and asset['code']==200 and asset['sha256']==file,'octane_sent_wrong_admin_asset')
        self.proof['runtime']={'php':body['php'],'swoole':body['swoole'],'octane_workers_checked':len(workers)}
        return {'revision':revision,'web_nonroot':candidate,'supplementary_group':mode=='allow','closed_http_status':404,
                'candidate_mounts_read_only':candidate,'old_cache_hidden':candidate,'http_assets_match':True}

    def retained(self,old,current):
        return all({k:v for k,v in old[role].items() if k!='tick'}=={k:v for k,v in current[role].items() if k!='tick'}
                   and current[role]['tick']>=old[role]['tick'] for role in GROUP)

    def run(self):
        self.prepare()
        self.step('shared_old_revision')
        self.compose('up','-d','--no-deps','--pull','never',*GROUP,timeout=60)
        old=self.witness()
        self.proof['baseline']=self.web('old')
        self.step('ungrouped_octane_denied')
        self.proof['negative_control']=self.web('deny')
        self.step('grouped_octane_web_only_candidate')
        self.proof['candidate']=self.web('allow')
        active=self.witness()
        need(self.retained(old,active),'other_service_recreated_or_changed')
        self.proof['horizon_ws_retained_984af211']=True
        self.step('remove_web_override_and_serve_old_assets')
        self.proof['rollback']=self.web('old')
        after=self.witness()
        need(self.retained(active,after) and all(after[k]['tick']>old[k]['tick'] for k in GROUP)
             and sha(self.old/'bootstrap/cache/shared-anchor')==self.anchor,'rollback_or_shared_tree_changed')
        self.proof.update(horizon_ws_unchanged_through_rollback=True,old_shared_cache_unchanged=True,
                          socket_acl_verified_in_http_worker=True,only_acl_fixture_connections=self.server.accepted==self.server.valid==1)
        need(self.proof['only_acl_fixture_connections'],'unexpected_socket_calls')

    def diagnostics(self):
        if not self.base.is_file():
            return {}
        proc=self.compose('logs','--tail','35','web',check=False)
        logs=(proc.stdout+proc.stderr).decode(errors='replace')
        tags={'missing_app_key':'MissingAppKeyException','bad_vendor':'Composer detected issues','failed_php_module':'Unable to load dynamic library',
              'bad_permissions':'Permission denied','no_octane':'octane:start" is not defined','artisan_ready':'Server running','fixture_fatal':'Fatal error',
              'cannot_make_directory':'Failed to create directory','invalid_php_cli':'PHP Fatal error'}
        findings={k:True for k,v in tags.items() if v in logs}
        # Workload paths are fixture-only; retain just a class and a bounded source line if available.
        error=re.search(r'\b([A-Za-z][A-Za-z0-9_\\]{0,100}(?:Exception|Error))\b',logs)
        if error:
            findings['exception_class']=error[1]
        rows=self.compose('ps','-a','--format','json','web',check=False).stdout.decode().strip()
        parsed=json.loads(rows) if rows.startswith('[') else [json.loads(v) for v in rows.splitlines() if v]
        findings['fixture_web_states']=[{k:s[k] for k in ('State','ExitCode') if k in s} for s in parsed]
        findings['fixture_log_tail']=[re.sub(r'\x1b\[[0-9;]*m','',line)[:200] for line in logs.splitlines()
                                      if line.strip() and not re.search('password|token|secret|credential',line,re.I)][-12:]
        return findings

    def cleanup(self):
        ok=True
        if self.base.is_file():
            out=self.compose('down','--remove-orphans','--timeout','4',check=False,timeout=45)
            ok=out.returncode==0 and not cmd('docker','ps','-aq','--filter','label=com.docker.compose.project='+self.project).stdout.strip()
        if self.server:
            self.server.close()
        if self.bind_dir:
            SOCKET_DIR.rmdir()
        if self.made:
            need(self.root.parent==Path('/run') and self.root.name==self.project and self.root.resolve()==self.root,'unsafe_fixture_cleanup')
            shutil.rmtree(self.root)
        image=cmd('docker','image','rm',self.image,check=False,timeout=30)
        return ok and image.returncode==0 and not self.root.exists() and not SOCKET_DIR.exists()


def main():
    repo,temp=(Path(x).resolve(strict=True) for x in sys.argv[1:])
    need(sys.platform=='linux' and os.geteuid()==0 and os.environ.get('GITHUB_ACTIONS')=='true'
         and os.environ.get('GITHUB_REPOSITORY')=='keli-123456/keliboard' and os.environ.get('GITHUB_REF_NAME')==BRANCH
         and repo.name=='keliboard' and temp.name=='_temp','disposable_github_runner_required')
    lab=Check(repo,temp)
    state={'candidate':TARGET,'rollback':BASE,'ci_commit':os.environ['GITHUB_SHA'],'checks':lab.proof,'ok':False,
           'synthetic_only':True,'real_cloud_operations':0,'real_dns_operations':0,'production_connections':0,
           'migrations':0,'queue_jobs':0,'rehearsals':0,'image_published':False}
    try:
        lab.run()
        state['ok']=True
    except Exception as e:
        frames=[f for f in traceback.extract_tb(e.__traceback__) if Path(f.filename).name=='check.py']
        state.update(failed_stage=lab.stage,error=str(e) if isinstance(e,Refused) else type(e).__name__,
                     location=f'check.py:{frames[-1].lineno}' if frames else None)
        try: state['diagnostic']=lab.diagnostics()
        except Exception: state['diagnostic_unavailable']=True
    try: state['cleaned']=lab.cleanup()
    except Exception as e: state.update(cleaned=False,cleanup_error=type(e).__name__)
    state['ok']=state['ok'] and state['cleaned']
    path=temp/'octane-web-only-evidence.json'
    data(path,state)
    path.chmod(0o644)
    print(json.dumps(state),flush=True)
    return 0 if state['ok'] else 1


if __name__=='__main__':
    sys.exit(main())
