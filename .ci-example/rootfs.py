"""Build a disposable Linux PHP root from exact runner binary dependencies, no panel data."""

from hashlib import sha256
import json
from pathlib import Path
import re
import shutil
import subprocess
import tarfile

from run import call, need


MODULES = {'ctype','curl','dom','fileinfo','iconv','mbstring','openssl','pcntl','pdo','pdo_sqlite','phar','posix',
           'simplexml','sockets','sodium','sqlite3','tokenizer','xml','xmlreader','xmlwriter','zip'}


class RuntimeTree:
    def __init__(self, path, php, worker_uid, worker_gid, web_uid, web_gid, web_socket_gid):
        self.path = path
        path.mkdir(mode=0o755)
        for rel in ("app", "dev", "etc", "proc", "run", "sys", "state", "tmp", "var/tmp"):
            (path / rel).mkdir(parents=True, exist_ok=True)
        (path / "tmp").chmod(0o1777)
        (path / "var/tmp").chmod(0o1777)
        self.put("/etc/passwd", f"root:x:0:0:root:/root:/usr/sbin/nologin\nworker:x:{worker_uid}:{worker_gid}:worker:/state:/usr/sbin/nologin\nweb:x:{web_uid}:{web_gid}:web:/tmp:/usr/sbin/nologin\n")
        self.put("/etc/group", f"root:x:0:\nworker:x:{worker_gid}:\nweb:x:{web_gid}:\nexample-socket:x:{web_socket_gid}:\n")
        self.put("/etc/nsswitch.conf", "passwd: files\ngroup: files\nhosts: files\n")
        self.put("/etc/hosts", "127.0.0.1 localhost\n::1 localhost\n")
        self.copy_elf(Path("/usr/bin/env"), "/usr/bin/env")
        self.copy_elf(php, "/usr/bin/php")
        info = json.loads(call(php, "-r", "echo json_encode([PHP_VERSION,php_ini_loaded_file(),php_ini_scanned_files(),ini_get('extension_dir')]);").stdout)
        version, ini, fragments, extension_root = info
        need(re.fullmatch(r"8\.2\.[0-9]+", version) and isinstance(ini,str) and ini.startswith("/etc/php/8.2/")
             and isinstance(extension_root,str) and extension_root.startswith("/usr/lib/php/"), "unverified_runtime_source")
        chosen = []
        config_files = [ini, *(fragments or "").replace("\n","").split(",")]
        for file in config_files:
            file = file.strip()
            if not file:
                continue
            need(file.startswith("/etc/php/8.2/"), "unexpected_php_config_root")
            for line in Path(file).read_text(errors="strict").splitlines():
                match = re.fullmatch(r'\s*extension\s*=\s*"?([a-zA-Z0-9_]+)(?:\.so)?"?\s*(?:;.*)?', line)
                if not match or match[1].lower() not in MODULES or match[1].lower() in chosen:
                    continue
                name = match[1].lower()
                binary = Path(extension_root, name + ".so")
                need(binary.is_file(), "php_module_file_missing")
                self.copy_elf(binary, str(binary))
                chosen.append(name)
        self.put(ini, "display_errors=0\nlog_errors=0\ndate.timezone=UTC\nopcache.enable_cli=0\n"
                 + "extension_dir=" + extension_root + "\n" + "".join("extension=" + name + ".so\n" for name in chosen))
        # libnss_files is dlopened by some glibc builds, rather than linked at startup.
        nss = Path("/lib/x86_64-linux-gnu/libnss_files.so.2")
        if nss.is_file():
            self.copy_elf(nss, str(nss))
        smoke = call("chroot", path, "/usr/bin/php", "-r",
            "echo json_encode(['version'=>PHP_VERSION,'sqlite'=>class_exists('PDO')&&in_array('sqlite',PDO::getAvailableDrivers(),true),'modules'=>"
            "array_map('extension_loaded',['sockets','pcntl','posix','mbstring','openssl'])]);", check=False)
        if smoke.returncode:
            why = smoke.stderr.decode(errors="replace").lower()
            code = ("chroot_binary_permission_denied" if "permission denied" in why else
                    "chroot_loader_or_binary_missing" if "no such file" in why else
                    "chroot_library_missing" if "loading shared libraries" in why else
                    "chroot_capability_unavailable" if "operation not permitted" in why else "chroot_php_nonzero")
            need(False, code)
        result = json.loads(smoke.stdout)
        need(result == {"version":version,"sqlite":True,"modules":[True]*5}, "sealed_php_modules_missing")
        self.version = version

    def target(self, absolute):
        source = Path(absolute)
        need(source.is_absolute() and ".." not in source.parts, "invalid_runtime_path")
        dest = self.path.joinpath(source.relative_to("/"))
        dest.parent.mkdir(parents=True, exist_ok=True)
        return dest

    def put(self, path, text):
        self.target(path).write_text(text, encoding="ascii")

    def copy(self, source, destination):
        file = self.target(destination)
        if file.exists():
            need(sha256(source.read_bytes()).digest() == sha256(file.read_bytes()).digest(), "inconsistent_library")
            return
        shutil.copy2(source.resolve(strict=True), file)

    def copy_elf(self, source, destination):
        self.copy(source, destination)
        deps = call("ldd", source).stdout.decode()
        need("not found" not in deps, "unresolved_runtime_library")
        for line in deps.splitlines():
            hit = re.search(r"(?:^\s*|=>\s*)(/[^\s]+)\s+\(", line)
            if hit:
                path = Path(hit[1])
                self.copy(path, str(path))

    def fingerprint(self):
        digest = sha256()
        for p in sorted(self.path.rglob("*")):
            kind = "link" if p.is_symlink() else "file" if p.is_file() else "dir"
            value = str(p.readlink()) if kind == "link" else sha256(p.read_bytes()).hexdigest() if kind == "file" else ""
            digest.update(json.dumps([p.relative_to(self.path).as_posix(),kind,p.lstat().st_mode & 0o7777,value]).encode()+b"\n")
        return digest.hexdigest()

    def disposable_web_image(self, tar_path):
        with tarfile.open(tar_path, "x") as archive:
            for path in sorted(self.path.iterdir()):
                archive.add(path, arcname=path.name)
        need(tar_path.stat().st_size < 120*1024*1024, "runtime_tar_oversized")
        with tar_path.open("rb") as archive:
            out = subprocess.run(["docker","image","import","-"],stdin=archive,capture_output=True,timeout=75,check=True)
        image = out.stdout.decode().strip()
        need(re.fullmatch("sha256:[a-f0-9]{64}",image), "unexpected_local_image_id")
        return image
