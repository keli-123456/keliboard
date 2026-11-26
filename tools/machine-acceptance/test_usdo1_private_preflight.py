"""Execute the generated bootstrap body with simulated ip, curl and installer."""

import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[2]
BASH = os.environ.get("KELI_TEST_BASH") or (
    shutil.which(str(Path(os.environ.get("ProgramFiles", "C:/Program Files")) / "Git/bin/bash.exe"))
    if os.name == "nt" else shutil.which("bash")
)


@unittest.skipUnless(BASH, "requires Bash")
class PrivateCallbackTests(unittest.TestCase):
    def rehearsal(self, route, tls, *, lose_route=False, installer_exit=0, skip_config=False):
        with tempfile.TemporaryDirectory(prefix="keli-private-callback-") as tmp:
            directory = Path(tmp)
            fake = directory / "fake"
            fake.mkdir()
            (fake / "ip").write_text("""#!/usr/bin/env bash
[ "$*" = '-o -4 route get fibmatch 10.25.0.1' ] || exit 91
[ ! -e "$TEST_DIR/lost" ] || { echo 'default via 10.25.0.254 dev wg-keli123'; exit; }
case "$TEST_ROUTE" in
  none) exit 2 ;;
  default) echo 'default via 10.25.0.254 dev wg-keli123' ;;
  wrong) echo '10.25.0.1 dev eth0 scope link' ;;
  ready) echo '10.25.0.1 dev wg-keli123 scope link' ;;
  *) exit 92 ;;
esac
""", encoding="ascii", newline="\n")
            (fake / "curl").write_text("""#!/usr/bin/env bash
[ "$1" = -q ] || exit 90
case " $* " in
  *'/callback-transport.conf '*) ;;
  *) exit 91 ;;
esac
case " $* " in
  *' --write-out '*)
    case " $* " in *'/callback.conf '*) exit 92 ;; esac
    printf 'tls\\n' >> "$TEST_DIR/trace"
    [ "$TEST_TLS" != bad_cert ] || exit 60
    printf '%s' "$TEST_TLS"
    ;;
  *'/callback.conf '*)
    printf 'receipt\\n' >> "$TEST_DIR/trace"
    ;;
  *) exit 93 ;;
esac
""", encoding="ascii", newline="\n")
            for tool in fake.iterdir():
                tool.chmod(0o700)
            (directory / "install.sh").write_text("""#!/usr/bin/env bash
printf 'install\\n' >> "$TEST_DIR/trace"
if [ "$TEST_LOSE_ROUTE" = yes ]; then : > "$TEST_DIR/lost"; fi
exit "$TEST_INSTALL_EXIT"
""", encoding="ascii", newline="\n")
            if not skip_config:
                (directory / "callback-transport.conf").touch()
                (directory / "callback.conf").touch()
            source = (ROOT / "app/Services/ServerMachine/Cloud/ProvisionBootstrap.php").read_text(encoding="ascii")
            shell = source.split("<<<'SH'\n", 1)[1].split("\nSH;", 1)[0]
            preflight = (ROOT / "resources/server-machine/usdo1-callback-preflight.sh").read_text(encoding="ascii")
            preflight = preflight.replace("{{PRIVATE_IP}}", "10.25.0.1").replace("{{PRIVATE_DEVICE}}", "wg-keli123")
            boot = shell.replace("/etc/kelinode", '"$TEST_DIR"/identity')
            for placeholder, value in {
                "DIAGNOSTICS": "", "DIRECTORY": directory.as_posix(), "IDENTITY": "a"*32, "TASK": "fixture",
                "CALLBACK_PREFLIGHT": preflight, "CALLBACK_ROUTE_GUARD": " && private_callback_route",
                "CALLBACK_TRANSPORT": ' -q --config "$directory/callback-transport.conf"',
            }.items():
                boot = boot.replace("{{" + placeholder + "}}", value)
            self.assertNotIn("{{", boot)
            (directory / "bootstrap.sh").write_text(boot, encoding="ascii", newline="\n")
            env = {key: value for key, value in os.environ.items() if key.upper() not in {"BASH_ENV", "ENV"}}
            env.update({"TEST_DIR": directory.as_posix(), "TEST_ROUTE": route, "TEST_TLS": tls,
                        "TEST_LOSE_ROUTE": "yes" if lose_route else "no", "TEST_INSTALL_EXIT": str(installer_exit)})
            cmd = ('if command -v cygpath >/dev/null; then TEST_DIR=$(cygpath -u "$TEST_DIR"); fi; '
                   'export TEST_DIR PATH="$TEST_DIR/fake:$PATH"; '
                   '[ "$(command -v curl)" = "$TEST_DIR/fake/curl" ] && [ "$(command -v ip)" = "$TEST_DIR/fake/ip" ] || exit 97; '
                   'exec bash "$TEST_DIR/bootstrap.sh"')
            process = subprocess.run([BASH, "--noprofile", "--norc", "-c", cmd], env=env, cwd=directory,
                                     capture_output=True, text=True, timeout=14)
            trace = (directory/"trace").read_text().splitlines() if (directory/"trace").exists() else []
            result = json.loads((directory/"result.json").read_bytes()) if (directory/"result.json").exists() else None
            return process, trace, result

    def test_route_or_tls_failure_stops_before_installer_and_bearer(self):
        for route, tls, config, trace in (
            ("none", "401", True, []), ("default", "401", True, []), ("wrong", "401", True, []),
            ("ready", "bad_cert", True, ["tls"]), ("ready", "302", True, ["tls"]), ("ready", "200", True, ["tls"]),
            ("ready", "401", False, []),
        ):
            with self.subTest(route=route, tls=tls, config=config):
                process, observed, result = self.rehearsal(route, tls, skip_config=not config)
                self.assertEqual(85, process.returncode, process.stderr)
                self.assertEqual(trace, observed)
                self.assertIsNone(result)

    def test_endpoint_accepted_before_install_and_then_route_rechecked(self):
        for lose, code, status, expected in (
            (False, 0, "succeeded", ["tls", "install", "receipt"]),
            (False, 42, "failed", ["tls", "install", "receipt"]),
            (True, 0, "succeeded", ["tls", "install"]),
        ):
            with self.subTest(lose=lose, code=code):
                process, trace, result = self.rehearsal("ready", "401", lose_route=lose, installer_exit=code)
                self.assertEqual(code, process.returncode, process.stderr)
                self.assertEqual(expected, trace)
                self.assertEqual(status, result["state"])


if __name__ == "__main__":
    unittest.main()
