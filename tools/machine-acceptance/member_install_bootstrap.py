"""Cold installer command; verified runtime modules only, no shell interpolation."""

BOOTSTRAP = r'''
import hashlib,io,json,os,pathlib,re,stat,sys,tarfile,tempfile
def need(value):
 if not value: raise RuntimeError('host_bootstrap_rejected')
def pairs(items):
 value={}
 for key,item in items:
  need(key not in value); value[key]=item
 return value
try:
 need(os.name=='posix' and os.geteuid()==0)
 raw=sys.stdin.buffer.read(262145); need(len(raw)<=262144)
 request=json.loads(raw,object_pairs_hook=pairs)
 expected=request['scope']['bundle_sha256']
 need(isinstance(expected,str) and re.fullmatch('[a-f0-9]{64}',expected))
 root=pathlib.Path('/opt/keli-isolated')
 for p in (root,root.parent,root/'incoming'):
  s=p.lstat();need(p.resolve(strict=True)==p and stat.S_ISDIR(s.st_mode) and s.st_uid==0 and s.st_mode&0o022==0)
 p=root/'incoming/r35-adapters.tar.gz'
 s=p.lstat();need(p.resolve(strict=True)==p and stat.S_ISREG(s.st_mode) and s.st_uid==0 and s.st_nlink==1 and stat.S_IMODE(s.st_mode)==0o600 and s.st_size<=104857600)
 fd=os.open(p,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
 with os.fdopen(fd,'rb') as stream:
  opened=os.fstat(stream.fileno());need((s.st_dev,s.st_ino)==(opened.st_dev,opened.st_ino));archive=stream.read(104857601)
 need(len(archive)==s.st_size and hashlib.sha256(archive).hexdigest()==expected)
 with tempfile.TemporaryDirectory(prefix='.r35-installer-',dir=root) as folder:
  sources={};total=0;seen=set()
  with tarfile.open(fileobj=io.BytesIO(archive)) as package:
   for member in package:
    name=pathlib.PurePosixPath(member.name)
    need(member.isfile() and member.name==name.as_posix() and not name.is_absolute() and '..' not in name.parts and '\\' not in member.name and member.name not in seen)
    seen.add(member.name);total+=member.size
    need(len(seen)<=50000 and 0<=member.size<=67108864 and total<=536870912)
    if len(name.parts)==2 and name.parts[0]=='runtime' and re.fullmatch(r'[a-z][a-z0-9_]*\.py',name.name):
     source=package.extractfile(member).read();need(len(source)==member.size and b'\r' not in source)
     sources[name.name]=source
  need('member_host_installation.py' in sources)
  for name,source in sources.items():
   target=pathlib.Path(folder)/name
   with target.open('xb') as stream: stream.write(source)
   target.chmod(0o600)
  sys.path.insert(0,folder)
  from member_host_installation import execute
  from member_auxiliary import digest
  result=execute(request)
  print(json.dumps(dict(ok=True,request_sha256=digest(request),result=result),sort_keys=True))
except BaseException as error:
 print(json.dumps(dict(ok=False,reason='host_installation_rejected',diagnostic=dict(error_class=type(error).__name__))))
 sys.exit(1)
'''.lstrip()
