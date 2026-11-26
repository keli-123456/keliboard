"""Freeze only the Composer-locked runtime tree and offline verification results."""

import gzip
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import stat
import subprocess
import tarfile


def require(condition, label):
    if not condition:
        raise RuntimeError(label)


def capture(*command):
    return subprocess.check_output(command, text=True, timeout=45).strip()


def digest(file):
    with file.open("rb") as stream:
        return hashlib.file_digest(stream, "sha256").hexdigest()


os_release = Path("/etc/os-release").read_text().splitlines()
require("VERSION_CODENAME=trixie" in os_release and set(os.listdir("/sys/class/net")) == {"lo"},
        "build platform or network is not isolated")
require(capture("php", "-r", "echo PHP_VERSION;") == "8.4.28", "unexpected PHP version")
capture("composer", "validate", "--no-check-publish", "--no-check-all", "--strict")
check = json.loads(capture("composer", "check-platform-reqs", "--no-dev", "--format=json"))
require(check and all(item["status"] == "success" for item in check), "Composer platform checks failed")
ext = {item["name"][4:] for item in check if item["name"].startswith("ext-")}
ext.update(("ctype", "curl", "mbstring", "openssl", "pcntl", "pdo_mysql", "posix", "redis", "sockets"))
ext_versions = json.loads(capture("php", "-r", """
$extensions = [];
foreach (array_slice($argv, 1) as $name) {
    if (!extension_loaded($name)) exit(1);
    $version = phpversion($name);
    $extensions[$name] = $version === false ? null : $version;
}
echo json_encode($extensions, JSON_THROW_ON_ERROR);
""", *sorted(ext)))
locked = json.loads(Path("composer.lock").read_text())
installed = json.loads(Path("vendor/composer/installed.json").read_text())
selection = {item["name"]: item["version"] for item in installed["packages"]}
require(installed.get("dev") is False and not installed.get("dev-package-names")
        and selection == {item["name"]: item["version"] for item in locked["packages"]},
        "installed packages differ from locked production packages")
require(not {item["name"] for item in locked["packages-dev"]}.intersection(selection), "dev package included")
require(not Path(".env").exists(), "environment data present")
require(capture("php", "-r", r"""
require 'vendor/autoload.php';
if (!class_exists(Illuminate\Foundation\Application::class)
    || !class_exists(App\Services\ServerMachine\Cloud\MachineUsdo1LaunchScope::class)
    || !class_exists(App\Services\ServerMachine\Cloud\MachineUsdo1Runtime::class)) exit(1);
echo 'autoload_ok';
""") == "autoload_ok", "CLI autoload failed in build fixture")

build = os.environ["KELI_BUILD_SHA"]
require(re.fullmatch(r"[0-9a-f]{40}", build), "invalid build pointer")
output = Path("/out")
archive = output / "vendor.tar.gz"
files = {}
with archive.open("xb") as raw, gzip.GzipFile(fileobj=raw, filename="", mtime=0) as packed:
    with tarfile.open(fileobj=packed, mode="w|", format=tarfile.PAX_FORMAT) as bundle:
        for path in sorted(Path("vendor").rglob("*")):
            name = path.as_posix()
            require(all(re.fullmatch(r"[A-Za-z0-9._+@,=() -]+", part) and part not in (".", "..")
                        for part in PurePosixPath(name).parts)
                    and not path.is_symlink(), "unsupported vendor entry")
            mode = path.stat().st_mode
            if stat.S_ISDIR(mode):
                continue
            require(stat.S_ISREG(mode) and path.stat().st_size <= 20_000_000, "invalid vendor file type or size")
            item = tarfile.TarInfo(name)
            item.size = path.stat().st_size
            item.mode = 0o500 if mode & 0o111 else 0o400
            files[name] = {"size": item.size, "mode": item.mode, "sha256": digest(path)}
            with path.open("rb") as data:
                bundle.addfile(item, data)
require("vendor/autoload.php" in files and len(files) >= 1000, "incomplete bundle")
manifest = {"schema": 1, "source": "0c9c6324dd126432e384551949022c2bdb82b59c", "build": build,
            "os": "debian-13-amd64", "php": "8.4.28", "composer": capture("composer", "--version"),
            "composer_lock_sha256": digest(Path("composer.lock")), "composer_json_sha256": digest(Path("composer.json")),
            "runtime_packages": selection, "platform": check, "extensions": ext_versions,
            "archive_sha256": digest(archive), "archive_bytes": archive.stat().st_size, "files": files}
(output / "manifest.json").write_text(json.dumps(manifest, indent=2, sort_keys=True) + "\n", encoding="utf-8")
print(json.dumps({"php": manifest["php"], "packages": len(selection), "files": len(files),
                  "platform_checks": len(check), "extensions": len(ext_versions), "archive_sha256": manifest["archive_sha256"],
                  "archive_bytes": manifest["archive_bytes"]}))
