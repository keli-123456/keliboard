#!/usr/bin/env python3
"""Isolated sing-box forwarding verifier. No provider or panel-user mutations."""
import argparse
import base64
import concurrent.futures
import contextlib
import functools
import hashlib
import hmac
import http.server
import ipaddress
import json
import os
from pathlib import Path
import re
import shutil
import signal
import socket
import sqlite3
import ssl
import stat
import subprocess
import tempfile
import threading
import time
import uuid


class Rejected(Exception):
    pass


def require(condition, code="invalid_request"):
    if not condition:
        raise Rejected(code)


def integer(value, low, high):
    return type(value) is int and low <= value <= high


def hex_value(value, length):
    return isinstance(value, str) and re.fullmatch(r"[a-f0-9]{%d}" % length, value) is not None


def public_ipv4(value):
    try:
        address = ipaddress.ip_address(value)
        return isinstance(value, str) and address.version == 4 and address.is_global and str(address) == value
    except (ValueError, TypeError):
        return False


def public_ipv6(value):
    try:
        address = ipaddress.ip_address(value)
        excluded = ["2001::/23", "2001:db8::/32", "2002::/16"]
        return (isinstance(value, str) and address.version == 6 and address.is_global
                and address in ipaddress.ip_network("2000::/3") and str(address) == value
                and not any(address in ipaddress.ip_network(net) for net in excluded))
    except (ValueError, TypeError):
        return False


def private_file(path, limit=131072):
    path = Path(path)
    require(path.is_absolute() and not path.is_symlink() and path.is_file(), "invalid_private_file")
    info = path.stat()
    if os.name == "posix":
        require(info.st_uid == os.geteuid() and stat.S_IMODE(info.st_mode) & 0o077 == 0, "insecure_private_file")
    require(info.st_size <= limit, "invalid_private_file")
    return path.read_bytes()


def unique_json(raw):
    def pairs(items):
        result = {}
        for key, value in items:
            require(key not in result)
            result[key] = value
        return result
    try:
        return json.loads(raw, object_pairs_hook=pairs, parse_constant=lambda _: require(False))
    except (ValueError, UnicodeError, TypeError):
        raise Rejected("invalid_request") from None


class Verifier:
    def __init__(self, config):
        require(isinstance(config, dict) and set(config) == {"shared_key_file", "sing_box", "sing_box_sha256", "state_dir", "profiles"}, "invalid_config")
        self.key = private_file(config["shared_key_file"], 256).strip()
        require(32 <= len(self.key) <= 128, "invalid_config")
        self.binary = Path(config["sing_box"])
        require(self.binary.is_absolute() and self.binary.is_file() and not self.binary.is_symlink() and hex_value(config["sing_box_sha256"], 64), "invalid_config")
        self.binary_hash = config["sing_box_sha256"]
        self.check_binary()
        self.state = Path(config["state_dir"])
        require(self.state.is_absolute() and not self.state.is_symlink(), "invalid_config")
        self.state.mkdir(mode=0o700, parents=False, exist_ok=True)
        if os.name == "posix":
            info = self.state.stat()
            require(info.st_uid == os.geteuid() and stat.S_IMODE(info.st_mode) & 0o077 == 0, "insecure_state_dir")
        self.profiles = config["profiles"]
        require(isinstance(self.profiles, dict) and len(self.profiles) <= 100, "invalid_config")
        self.mutex = threading.Lock()
        database = self.state / "challenges.sqlite3"
        require(not database.is_symlink(), "insecure_state_dir")
        with contextlib.closing(sqlite3.connect(database)) as db, db:
            db.execute("CREATE TABLE IF NOT EXISTS challenges (id TEXT PRIMARY KEY, digest TEXT NOT NULL, expires INTEGER NOT NULL)")
        os.chmod(database, 0o600)

    def check_binary(self):
        with self.binary.open("rb") as source:
            digest = hashlib.file_digest(source, "sha256").hexdigest()
        require(hmac.compare_digest(digest, self.binary_hash), "client_binary_changed")

    def validate(self, request):
        fields = {"schema", "challenge_id", "issued_at", "deadline_at", "profile", "task_id", "installation_id", "target_ip", "configuration_fingerprint", "session", "binary_sha256", "nodes", "echo_frames", "echo_sha256"}
        require(isinstance(request, dict) and type(request.get("schema")) is int and request["schema"] in (1, 2))
        if request["schema"] == 2:
            fields |= {"address_family", "attempt_id", "native_context_sha256", "source_installation_id"}
        require(set(request) == fields)
        now = int(time.time())
        require(integer(request["issued_at"], now - 45, now + 3) and integer(request["deadline_at"], now + 1, now + 48)
                and request["deadline_at"] == request["issued_at"] + 45, "challenge_expired")
        for name, size in [("challenge_id", 32), ("installation_id", 32), ("session", 32), ("configuration_fingerprint", 64), ("binary_sha256", 64), ("echo_sha256", 64)]:
            require(hex_value(request[name], size))
        try:
            require(str(uuid.UUID(request["task_id"])) == request["task_id"])
        except (ValueError, AttributeError, TypeError):
            raise Rejected("invalid_request") from None
        if request["schema"] == 2:
            require(request["address_family"] in ("A", "AAAA") and hex_value(request["native_context_sha256"], 64)
                    and hex_value(request["source_installation_id"], 32)
                    and request["source_installation_id"] != request["installation_id"])
            try:
                require(str(uuid.UUID(request["attempt_id"])) == request["attempt_id"])
            except (ValueError, AttributeError, TypeError):
                raise Rejected("invalid_request") from None
        require((public_ipv6 if request.get("address_family") == "AAAA" else public_ipv4)(request["target_ip"]), "target_not_allowed")
        require(isinstance(request["profile"], str) and re.fullmatch(r"[a-z0-9_-]{1,64}", request["profile"]), "profile_not_configured")
        profile = self.profiles.get(request["profile"])
        require(isinstance(profile, dict) and set(profile) == {"isolated", "expires_at", "allowed_targets", "echo_address", "echo_port", "nodes"}
                and profile["isolated"] is True, "profile_not_configured")
        require(integer(profile["expires_at"], now + 30, now + 3600), "profile_expired")
        require(isinstance(profile["allowed_targets"], list) and len(profile["allowed_targets"]) <= 16
                and request["target_ip"] in profile["allowed_targets"]
                and all(public_ipv4(ip) or public_ipv6(ip) for ip in profile["allowed_targets"]), "target_not_allowed")
        try:
            echo = ipaddress.ip_address(profile["echo_address"])
            require(str(echo) == profile["echo_address"] and not echo.is_unspecified and not echo.is_multicast and not echo.is_link_local, "invalid_echo_target")
        except (ValueError, TypeError):
            raise Rejected("invalid_echo_target") from None
        require(integer(profile["echo_port"], 1, 65535), "invalid_echo_target")
        nodes = request["nodes"]
        require(isinstance(nodes, list) and 1 <= len(nodes) <= 4 and isinstance(profile["nodes"], list)
                and len(profile["nodes"]) == len(nodes), "unsupported_node_group")
        seen = set()
        for node, configured in zip(nodes, profile["nodes"]):
            require(isinstance(node, dict) and set(node) == {"id", "protocol", "port"} and integer(node["id"], 1, 2**53 - 1)
                    and node["id"] not in seen and integer(node["port"], 1, 65535), "unsupported_node_group")
            require(isinstance(configured, dict) and set(configured) == {"id", "protocol", "port", "outbound"}
                    and {key: configured[key] for key in node} == node, "profile_node_mismatch")
            seen.add(node["id"])
            self.outbound(configured, request["target_ip"])
        require(isinstance(request["echo_frames"], list) and len(request["echo_frames"]) == 3)
        try:
            frames = [base64.b64decode(item, validate=True) for item in request["echo_frames"]]
            require(all(len(frame) == 32 and base64.b64encode(frame).decode() == item for frame, item in zip(frames, request["echo_frames"]))
                    and len(set(frames)) == 3 and hashlib.sha256(b"".join(frames)).hexdigest() == request["echo_sha256"])
        except (ValueError, TypeError):
            raise Rejected("invalid_request") from None
        return profile, frames

    def outbound(self, node, target):
        outbound = node["outbound"]
        fields = {"hysteria2": {"password"}, "trojan": {"password"}, "vless": {"uuid", "packet_encoding"}, "tuic": {"uuid", "password"}}
        protocol = node["protocol"]
        require(protocol in fields and isinstance(outbound, dict) and set(outbound) <= fields[protocol] | {"tls"}, "unsupported_protocol")
        for key in fields[protocol] - {"packet_encoding"}:
            require(isinstance(outbound.get(key), str) and 1 <= len(outbound[key]) <= 512, "invalid_profile")
        if protocol in {"vless", "tuic"}:
            try:
                require(str(uuid.UUID(outbound["uuid"])) == outbound["uuid"], "invalid_profile")
            except (ValueError, TypeError):
                raise Rejected("invalid_profile") from None
        if protocol == "vless":
            require(outbound.get("packet_encoding", "xudp") == "xudp", "invalid_profile")
        tls = outbound.get("tls")
        require(isinstance(tls, dict) and set(tls) <= {"enabled", "server_name", "insecure", "certificate", "certificate_public_key_sha256"}
                and tls.get("enabled") is True and tls.get("insecure", False) is False
                and isinstance(tls.get("server_name"), str) and 1 <= len(tls["server_name"]) <= 253, "tls_identity_required")
        certificates = tls.get("certificate", [])
        require(isinstance(certificates, list) and len(certificates) <= 8 and all(isinstance(value, str) and len(value) <= 16384 for value in certificates), "invalid_profile")
        pins = tls.get("certificate_public_key_sha256", [])
        require(isinstance(pins, list) and len(pins) <= 4, "invalid_profile")
        for pin in pins:
            try:
                require(len(base64.b64decode(pin, validate=True)) == 32, "invalid_profile")
            except (ValueError, TypeError):
                raise Rejected("invalid_profile") from None
        return {"type": protocol, "tag": "verified-out", "server": target, "server_port": node["port"], **outbound}

    def handle(self, raw, signature):
        require(len(raw) <= 32768 and isinstance(signature, str) and hex_value(signature, 64)
                and hmac.compare_digest(signature, hmac.new(self.key, b"request\n" + raw, "sha256").hexdigest()), "unauthorized")
        require(self.mutex.acquire(blocking=False), "busy")
        try:
            request = unique_json(raw)
            profile, frames = self.validate(request)
            with contextlib.closing(sqlite3.connect(self.state / "challenges.sqlite3", timeout=1)) as db, db:
                db.execute("DELETE FROM challenges WHERE expires < ?", (int(time.time()) - 300,))
                require(db.execute("SELECT COUNT(*) FROM challenges").fetchone()[0] < 2000, "journal_full")
                try:
                    db.execute("INSERT INTO challenges VALUES (?, ?, ?)", (request["challenge_id"], hashlib.sha256(raw).hexdigest(), request["deadline_at"]))
                except sqlite3.IntegrityError:
                    raise Rejected("challenge_replayed") from None
            deadline = min(time.monotonic() + 24, time.monotonic() + request["deadline_at"] - time.time() - 1)
            results = []
            cleanup = True
            for node in profile["nodes"]:
                result, cleaned = self.measure(node, profile, request["target_ip"], frames, deadline)
                results.append(result)
                cleanup = cleanup and cleaned
                if not cleaned:
                    break
            receipt = {"schema": request["schema"], "challenge_id": request["challenge_id"], "request_sha256": hashlib.sha256(raw).hexdigest(),
                       "finished_at": int(time.time()), "cleanup_complete": cleanup, "results": results}
            if request["schema"] == 2:
                receipt.update({key: request[key] for key in ("address_family", "target_ip", "task_id", "installation_id", "attempt_id",
                                "native_context_sha256", "source_installation_id", "session", "binary_sha256", "configuration_fingerprint")})
            return receipt
        finally:
            self.mutex.release()

    def measure(self, node, profile, target, frames, deadline):
        self.check_binary()
        require(time.monotonic() < deadline - 1, "verification_deadline")
        directory = Path(tempfile.mkdtemp(prefix="job-", dir=self.state))
        process = None
        result = {"node_id": node["id"], "endpoint_authenticated": False}
        cleaned = False
        try:
            with contextlib.ExitStack() as stack:
                tcp = stack.enter_context(socket.socket())
                tcp.bind(("127.0.0.1", 0))
                port = tcp.getsockname()[1]
                udp = stack.enter_context(socket.socket(socket.AF_INET, socket.SOCK_DGRAM))
                udp.bind(("127.0.0.1", port))
            config = {"log": {"disabled": True}, "inbounds": [{"type": "direct", "listen": "127.0.0.1", "listen_port": port,
                      "override_address": profile["echo_address"], "override_port": profile["echo_port"]}],
                      "outbounds": [self.outbound(node, target)], "route": {"final": "verified-out"}}
            filename = directory / "client.json"
            with open(filename, "x", encoding="utf-8", opener=lambda path, flags: os.open(path, flags, 0o600)) as stream:
                json.dump(config, stream)
            options = {"stdin": subprocess.DEVNULL, "stdout": subprocess.DEVNULL, "stderr": subprocess.DEVNULL, "cwd": directory, "close_fds": True}
            if os.name == "nt":
                options["creationflags"] = subprocess.CREATE_NO_WINDOW | subprocess.CREATE_NEW_PROCESS_GROUP
            else:
                options["start_new_session"] = True
                options["preexec_fn"] = functools.partial(child_limits, os.getpid())
            process = subprocess.Popen([str(self.binary), "run", "-c", str(filename)], **options)
            ready_until = min(deadline - 1, time.monotonic() + 2)
            while time.monotonic() < ready_until:
                require(process.poll() is None, "client_start_failed")
                try:
                    with socket.create_connection(("127.0.0.1", port), timeout=0.1):
                        break
                except OSError:
                    time.sleep(0.04)
            else:
                raise Rejected("client_start_failed")
            with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
                tcp_work = pool.submit(echo_tcp, port, frames, deadline)
                udp_work = pool.submit(echo_udp, port, frames, deadline)
                result["tcp"], result["udp"] = tcp_work.result(), udp_work.result()
            result["endpoint_authenticated"] = result["tcp"]["sha256"] == hashlib.sha256(b"".join(frames)).hexdigest() and result["tcp"]["bytes"] == 96
        finally:
            stopped = stop_client(process)
            try:
                if stopped:
                    self.remove_job(directory)
                    cleaned = not directory.exists()
            except (OSError, Rejected):
                cleaned = False
        return result, cleaned

    def remove_job(self, directory):
        require(not directory.is_symlink() and directory.resolve().parent == self.state.resolve()
                and re.fullmatch(r"job-[a-zA-Z0-9_-]{3,80}", directory.name), "cleanup_scope_mismatch")
        shutil.rmtree(directory)


def child_limits(parent):
    import ctypes
    import resource
    resource.setrlimit(resource.RLIMIT_CORE, (0, 0))
    resource.setrlimit(resource.RLIMIT_NOFILE, (128, 128))
    resource.setrlimit(resource.RLIMIT_CPU, (8, 8))
    if sys_platform_linux():
        require(ctypes.CDLL(None).prctl(1, signal.SIGKILL) == 0, "client_limits_failed")
        if os.getppid() != parent:
            os._exit(1)


def sys_platform_linux():
    import sys
    return sys.platform.startswith("linux")


def stop_client(process):
    if process is None:
        return True
    try:
        if process.poll() is None:
            if os.name == "posix":
                os.killpg(process.pid, signal.SIGTERM)
            else:
                process.terminate()
            try:
                process.wait(timeout=0.5)
            except subprocess.TimeoutExpired:
                if os.name == "posix":
                    os.killpg(process.pid, signal.SIGKILL)
                else:
                    process.kill()
                process.wait(timeout=0.5)
        return process.poll() is not None
    except (OSError, subprocess.TimeoutExpired):
        return False


def echo_tcp(port, frames, deadline):
    data = bytearray()
    sent = 0
    try:
        with socket.create_connection(("127.0.0.1", port), timeout=max(0.05, min(1.5, deadline - time.monotonic()))) as stream:
            stream.settimeout(max(0.05, min(1.5, deadline - time.monotonic())))
            stream.sendall(b"".join(frames)); sent = 3
            until = min(deadline, time.monotonic() + 2)
            while len(data) < 96 and time.monotonic() < until:
                stream.settimeout(max(0.01, until - time.monotonic()))
                chunk = stream.recv(128 - len(data))
                if not chunk:
                    break
                data.extend(chunk)
            if len(data) == 96:
                stream.settimeout(max(0.01, min(0.15, deadline - time.monotonic())))
                with contextlib.suppress(socket.timeout):
                    data.extend(stream.recv(32))
    except OSError:
        pass
    received = min(3, len(data) // 32)
    return {"sent": sent, "received": received, "missing": 3 - received, "duplicates": max(0, (len(data) - 96 + 31) // 32),
            "bytes": len(data), "sha256": hashlib.sha256(data).hexdigest()}


def echo_udp(port, frames, deadline):
    found = set(); duplicates = 0; total = 0; sent = 0
    try:
        with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as stream:
            stream.connect(("127.0.0.1", port))
            for frame in frames:
                stream.send(frame); sent += 1
            until = min(deadline, time.monotonic() + 2)
            for _ in range(16):
                remaining = until - time.monotonic()
                if remaining <= 0:
                    break
                stream.settimeout(remaining)
                data = stream.recv(1024); total += len(data)
                if data not in frames or data in found:
                    duplicates += 1
                else:
                    found.add(data)
                if len(found) == 3:
                    until = min(until, time.monotonic() + 0.15)
    except OSError:
        pass
    return {"sent": sent, "received": len(found), "missing": 3 - len(found), "duplicates": duplicates, "bytes": total,
            "sha256": hashlib.sha256(b"".join(frame for frame in frames if frame in found)).hexdigest()}


class Handler(http.server.BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.0"

    def log_message(self, *_):
        pass

    def do_POST(self):
        self.connection.settimeout(3)
        status = 200
        try:
            require(self.path == "/v1/verify" and not self.headers.get("Transfer-Encoding"))
            lengths = self.headers.get_all("Content-Length", [])
            signatures = self.headers.get_all("X-Keli-Signature", [])
            require(len(lengths) == 1 and re.fullmatch(r"[0-9]{1,5}", lengths[0]) and 0 < int(lengths[0]) <= 32768)
            require(len(signatures) == 1, "unauthorized")
            raw = self.rfile.read(int(lengths[0])); require(len(raw) == int(lengths[0]))
            result = self.server.verifier.handle(raw, signatures[0])
        except Rejected as error:
            code = str(error); status = 403 if code == "unauthorized" else 409 if code in {"busy", "challenge_replayed"} else 422
            result = {"error": code}
        except Exception:
            status = 500; result = {"error": "verification_failed"}
        body = json.dumps(result, separators=(",", ":")).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("X-Keli-Signature", hmac.new(self.server.verifier.key, b"response\n" + body, "sha256").hexdigest())
        self.end_headers()
        with contextlib.suppress(OSError):
            self.wfile.write(body)


class Server(http.server.HTTPServer):
    request_queue_size = 4

    def get_request(self):
        connection, address = super().get_request()
        connection.settimeout(3)
        try:
            return self.context.wrap_socket(connection, server_side=True), address
        except Exception:
            connection.close()
            raise


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--config", required=True)
    parser.add_argument("--certificate", required=True)
    parser.add_argument("--key", required=True)
    parser.add_argument("--port", type=int, default=9446)
    args = parser.parse_args()
    require(sys_platform_linux() and os.geteuid() != 0, "nonroot_linux_required")
    os.umask(0o077)
    verifier = Verifier(unique_json(private_file(args.config)))
    private_file(args.certificate); private_file(args.key)
    require(1024 <= args.port <= 65535, "invalid_port")
    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER); context.minimum_version = ssl.TLSVersion.TLSv1_2
    context.load_cert_chain(args.certificate, args.key)
    server = Server(("127.0.0.1", args.port), Handler)
    server.timeout = 1; server.verifier = verifier
    server.context = context
    server.serve_forever(poll_interval=0.2)


if __name__ == "__main__":
    try:
        main()
    except Exception:
        raise SystemExit("Verifier stopped; check its private configuration.") from None
