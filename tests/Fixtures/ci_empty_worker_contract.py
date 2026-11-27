# Generated from the actual trial's sanitized trace and cleanup code.
import hashlib,json,os,re,stat
def sha(path): return hashlib.sha256(path.read_bytes()).hexdigest()
def need(ok, reason):
    if not ok: raise RuntimeError(reason)

TRIAL_STAGES={'runtime','autoload','config_cache','child_exec','config_readback','artisan_bootstrap','operator_gate',
              'redis_socket','queue_command'}
TRIAL_CHECKS={'pcntl_exec','pcntl_async_signals','pcntl_signal','pcntl_alarm','posix_geteuid','posix_getegid','posix_kill'}
TRIAL_REASONS={'required_function_unavailable','wrong_worker_identity','wrong_worker_scope','cwd_unavailable',
               'wrong_cache_path','cache_path_exists','cache_create_failed','cache_write_failed','cache_permission_failed',
               'isolated_database_mismatch','isolated_redis_mismatch','isolated_queue_mismatch','worker_exec_failed',
               'cached_file_unverified','worker_command_not_registered','worker_command_source_mismatch',
               'isolated_redis_ping_failed','exception','php_fatal','queue_command_nonzero'}

def decode_heartbeat(wire):
    if not isinstance(wire,str):return None
    if re.fullmatch(r'[a-f0-9]{32}',wire):return wire
    # Laravel RedisStore serializes string values when the connection has no serializer.
    match=re.fullmatch(r's:32:"([a-f0-9]{32})";',wire)
    return match.group(1) if match else None

def child_trace(raw):
    stages=[]
    first=None
    exit_code=None
    for line in raw.splitlines():
        if not line.startswith('KELI_TRIAL ') or len(line)>440:continue
        try:event=json.loads(line[len('KELI_TRIAL '):])
        except ValueError:continue
        if not isinstance(event,dict) or not isinstance(event.get('stage'),str) or event['stage'] not in TRIAL_STAGES:continue
        if event.get('kind')=='stage':
            if not stages or stages[-1]!=event['stage']:stages.append(event['stage'])
        if event.get('kind')=='command_exit' and type(event.get('exit')) is int and 0<=event['exit']<=255:
            exit_code=event['exit']
        if (first is not None or event.get('kind')!='first_error' or not isinstance(event.get('reason'),str)
                or event['reason'] not in TRIAL_REASONS):continue
        first={'stage':event['stage'],'reason':event['reason']}
        if isinstance(event.get('type'),str) and re.fullmatch(r'[A-Za-z_][A-Za-z0-9_\\]{0,118}',event['type']):
            first['type']=event['type']
        if isinstance(event.get('file'),str) and re.fullmatch(r'[A-Za-z0-9_.-]{1,90}',event['file']):
            first['file']=event['file']
        for key,limit in [('line',1000000),('exit',255)]:
            if type(event.get(key)) is int and 0<=event[key]<=limit:first[key]=event[key]
        if isinstance(event.get('check'),str) and event['check'] in TRIAL_CHECKS:first['check']=event['check']
    return {'stages':stages[:16],'first_error':first,'command_exit':exit_code}

def remove_trial_files(trial,root_identity,tracked,UID,quiescent):
    if root_identity is None:return
    ds=trial.lstat()
    need((ds.st_dev,ds.st_ino)==root_identity and trial.resolve()==trial,'trial_directory_moved')
    need(quiescent(),'trial_containers_still_present')
    files=[];dirs=[]
    for p in trial.rglob('*'):
        s=p.lstat()
        need(not stat.S_ISLNK(s.st_mode) and p.resolve()==p,'unexpected_trial_file')
        if stat.S_ISDIR(s.st_mode):dirs.append(p);continue
        rel=p.relative_to(trial).as_posix()
        if p in tracked:
            need(stat.S_ISREG(s.st_mode) and s.st_nlink==1 and sha(p)==tracked[p],'trial_file_changed')
        elif rel=='socket/redis.sock':
            need(stat.S_ISSOCK(s.st_mode) and s.st_uid==UID,'trial_socket_identity_changed')
        else:
            need(stat.S_ISREG(s.st_mode) and s.st_uid==UID and s.st_nlink==1 and rel in (
                'bootstrap-cache/operator-config.php','bootstrap-cache/packages.php','bootstrap-cache/services.php'),
                'unexpected_trial_state_file')
            tracked[p]=sha(p)
        files.append((p,s.st_dev,s.st_ino))
    for p,dev,ino in files:
        s=p.lstat()
        need((s.st_dev,s.st_ino)==(dev,ino) and (p not in tracked or sha(p)==tracked[p]),'trial_cleanup_readback_changed')
        p.unlink()
    for p in sorted(dirs,key=lambda p:len(p.parts),reverse=True):p.rmdir()
    trial.rmdir()
    need(not os.path.lexists(trial),'trial_cleanup_unconfirmed')
