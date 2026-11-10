import base64
import hashlib
import hmac
import http.client
import json
import os
from pathlib import Path
import secrets
import socket
import socketserver
import ssl
import subprocess
import sys
import tempfile
import threading
import time
import unittest
from unittest.mock import patch
import uuid

import worker


class WorkerFixture:
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory(prefix="keli-verifier-test-")
        self.root = Path(self.directory.name)
        self.key = self.root / "key"
        self.key.write_bytes(b"k" * 32); self.key.chmod(0o600)
        self.binary = Path(os.environ.get("KELI_TEST_SING_BOX", sys.executable)).resolve()
        self.config = {"shared_key_file": str(self.key), "sing_box": str(self.binary), "sing_box_sha256": hashlib.sha256(self.binary.read_bytes()).hexdigest(),
                       "state_dir": str(self.root / "state"), "profiles": {"test-profile": {"isolated": True, "expires_at": int(time.time()) + 600,
                       "allowed_targets": ["8.8.8.8"], "echo_address": "127.0.0.2", "echo_port": 24444,
                       "nodes": [{"id": 10, "protocol": "hysteria2", "port": 28446,
                                  "outbound": {"password": "isolated-fixture", "tls": {"enabled": True, "server_name": "verify.example.test", "insecure": False}}}]}}}
        self.verifier = worker.Verifier(self.config)
        frames = [secrets.token_bytes(32) for _ in range(3)]
        self.request = {"schema": 1, "challenge_id": secrets.token_hex(16), "issued_at": int(time.time()), "deadline_at": int(time.time()) + 45,
                        "profile": "test-profile", "task_id": str(uuid.uuid4()), "installation_id": "a" * 32, "target_ip": "8.8.8.8",
                        "configuration_fingerprint": "b" * 64, "session": "c" * 32, "binary_sha256": "d" * 64,
                        "nodes": [{"id": 10, "protocol": "hysteria2", "port": 28446}], "echo_frames": [base64.b64encode(frame).decode() for frame in frames],
                        "echo_sha256": hashlib.sha256(b"".join(frames)).hexdigest()}

    def tearDown(self):
        self.directory.cleanup()

    def signed(self):
        raw = json.dumps(self.request, separators=(",", ":")).encode()
        return raw, hmac.new(self.verifier.key, b"request\n" + raw, "sha256").hexdigest()

class WorkerTests(WorkerFixture, unittest.TestCase):
    def native(self, family="AAAA"):
        target = "2600:3c00::100" if family == "AAAA" else "8.8.8.8"
        self.config["profiles"]["test-profile"]["allowed_targets"] = [target]
        self.request.update(schema=2, address_family=family, target_ip=target, attempt_id=str(uuid.uuid4()),
                            native_context_sha256="e" * 64, source_installation_id="f" * 32)

    def test_native_receipts_bind_each_family_and_original_candidate_context(self):
        for family in ["A", "AAAA"]:
            with self.subTest(family=family):
                self.native(family); self.request["challenge_id"] = secrets.token_hex(16)
                with patch.object(self.verifier, "measure", return_value=({"node_id": 10}, True)) as measure:
                    receipt = self.verifier.handle(*self.signed())
                self.assertEqual(receipt["schema"], 2)
                for field in ["address_family", "target_ip", "task_id", "installation_id", "attempt_id", "native_context_sha256",
                              "source_installation_id", "session", "binary_sha256", "configuration_fingerprint"]:
                    self.assertEqual(receipt[field], self.request[field])
                self.assertEqual(measure.call_count, 1)
                with patch.object(self.verifier, "measure") as measure, self.assertRaisesRegex(worker.Rejected, "challenge_replayed"):
                    worker.Verifier(self.config).handle(*self.signed())
                measure.assert_not_called()

    def test_native_malformed_cross_family_and_non_public_targets_never_launch(self):
        self.native(); original = json.dumps(self.request)
        mutations = [dict(address_family="A"), dict(address_family="AAAA", target_ip="8.8.8.8"), dict(target_ip="::1"),
                     dict(target_ip="fe80::1"), dict(target_ip="fd00::1"), dict(target_ip="2001:db8::1"),
                     dict(target_ip="2002:808:808::1"), dict(target_ip="64:ff9b::808:808"), dict(target_ip="::ffff:8.8.8.8"),
                     dict(target_ip="2600:3c00::200"), dict(target_ip="2600:3c00:0:0::100"), dict(target_ip="https://example.test"),
                     dict(source_installation_id="a" * 32), dict(native_context_sha256="wrong"), dict(attempt_id="wrong")]
        for change in mutations:
            with self.subTest(change=change):
                self.request = json.loads(original); self.request.update(change)
                with patch.object(self.verifier, "measure") as measure, self.assertRaises(worker.Rejected):
                    self.verifier.handle(*self.signed())
                measure.assert_not_called()

    def test_legacy_challenge_cannot_publish_ipv6_or_smuggle_native_fields(self):
        self.config["profiles"]["test-profile"]["allowed_targets"] = ["2600:3c00::100"]
        self.request["target_ip"] = "2600:3c00::100"
        with patch.object(self.verifier, "measure") as measure, self.assertRaises(worker.Rejected):
            self.verifier.handle(*self.signed())
        measure.assert_not_called()
        self.request.update(target_ip="8.8.8.8", address_family="A")
        with self.assertRaises(worker.Rejected):
            self.verifier.validate(self.request)

    def test_no_launch_without_a_valid_signature(self):
        with patch.object(self.verifier, "measure") as measure, self.assertRaisesRegex(worker.Rejected, "unauthorized"):
            self.verifier.handle(self.signed()[0], "0" * 64)
        measure.assert_not_called()

    def test_signed_single_use_challenge_is_persisted_across_restarts(self):
        with patch.object(self.verifier, "measure", return_value=({"node_id": 10}, True)) as measure:
            receipt = self.verifier.handle(*self.signed())
            self.assertTrue(receipt["cleanup_complete"])
            with self.assertRaisesRegex(worker.Rejected, "challenge_replayed"):
                worker.Verifier(self.config).handle(*self.signed())
            self.assertEqual(measure.call_count, 1)

    def test_malformed_and_replayed_input_never_launches_a_client(self):
        mutations = [lambda r: r.update(target_ip="127.0.0.1"), lambda r: r.update(target_ip="169.254.169.254"),
                     lambda r: r.update(target_ip="1.1.1.1"), lambda r: r.update(deadline_at=r["issued_at"] - 1),
                     lambda r: r.update(passed=True), lambda r: r.update(schema=True), lambda r: r.update(nodes=[]),
                     lambda r: r["nodes"][0].update(port=22), lambda r: r["echo_frames"].__setitem__(1, r["echo_frames"][0]),
                     lambda r: r.update(echo_sha256="0" * 64)]
        original = json.dumps(self.request)
        for mutate in mutations:
            with self.subTest(mutate=mutate):
                self.request = json.loads(original); mutate(self.request)
                with patch.object(self.verifier, "measure") as measure, self.assertRaises(worker.Rejected):
                    self.verifier.handle(*self.signed())
                measure.assert_not_called()

    def test_profile_cannot_inject_direct_outbound_or_disable_tls(self):
        node = self.config["profiles"]["test-profile"]["nodes"][0]
        for field, value in [("type", "direct"), ("server", "127.0.0.1"), ("detour", "direct")]:
            node["outbound"][field] = value
            with self.assertRaises(worker.Rejected):
                self.verifier.validate(self.request)
            del node["outbound"][field]
        node["outbound"]["tls"]["insecure"] = True
        with self.assertRaisesRegex(worker.Rejected, "tls_identity_required"):
            self.verifier.validate(self.request)

    def test_profile_expiry_binary_change_and_concurrent_work_are_rejected(self):
        self.verifier.mutex.acquire()
        try:
            with self.assertRaisesRegex(worker.Rejected, "busy"):
                self.verifier.handle(*self.signed())
        finally:
            self.verifier.mutex.release()
        self.verifier.binary_hash = "0" * 64
        with self.assertRaisesRegex(worker.Rejected, "client_binary_changed"):
            self.verifier.check_binary()
        self.config["profiles"]["test-profile"]["expires_at"] = int(time.time()) - 1
        with self.assertRaisesRegex(worker.Rejected, "profile_expired"):
            self.verifier.handle(*self.signed())

    def test_duplicate_json_keys_are_rejected(self):
        with self.assertRaises(worker.Rejected):
            worker.unique_json(b'{"schema":1,"schema":1}')

    def test_cleanup_failure_is_not_a_passing_receipt(self):
        with patch.object(self.verifier, "measure", return_value=({"node_id": 10}, False)):
            self.assertFalse(self.verifier.handle(*self.signed())["cleanup_complete"])

    def test_cleanup_cannot_escape_the_private_job_directory(self):
        unrelated = self.root / "job-unrelated"; unrelated.mkdir()
        with self.assertRaisesRegex(worker.Rejected, "cleanup_scope_mismatch"):
            self.verifier.remove_job(unrelated)
        self.assertTrue(unrelated.exists())


class EchoTCP(socketserver.BaseRequestHandler):
    def handle(self):
        self.request.settimeout(2)
        try:
            while data := self.request.recv(1024):
                self.request.sendall(data)
        except OSError:
            pass


class EchoUDP(socketserver.BaseRequestHandler):
    mode = "normal"

    def handle(self):
        data, connection = self.request
        if self.mode == "missing":
            return
        connection.sendto(data, self.client_address)
        if self.mode == "duplicate":
            connection.sendto(data, self.client_address)


@unittest.skipUnless(os.environ.get("KELI_TEST_SING_BOX") and os.environ.get("KELI_TEST_OPENSSL"), "explicit loopback integration binaries required")
class RealHysteriaTests(WorkerFixture, unittest.TestCase):
    def setUp(self):
        super().setUp()
        self.cert = self.root / "cert.pem"; self.private = self.root / "private.pem"
        subprocess.run([os.environ["KELI_TEST_OPENSSL"], "req", "-x509", "-nodes", "-newkey", "rsa:2048", "-keyout", str(self.private), "-out", str(self.cert),
                        "-days", "1", "-subj", "/CN=verify.example.test", "-addext", "subjectAltName=DNS:verify.example.test,IP:127.0.0.1"],
                       check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=10)
        self.tcp = socketserver.ThreadingTCPServer(("127.0.0.2", 0), EchoTCP); self.tcp.daemon_threads = True
        self.udp = socketserver.ThreadingUDPServer(("127.0.0.2", self.tcp.server_address[1]), EchoUDP); self.udp.daemon_threads = True
        self.threads = [threading.Thread(target=server.serve_forever, daemon=True) for server in [self.tcp, self.udp]]
        for thread in self.threads:
            thread.start()
        with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as sock:
            sock.bind(("127.0.0.1", 0)); port = sock.getsockname()[1]
        profile = self.config["profiles"]["test-profile"]
        profile.update(allowed_targets=["127.0.0.1"], echo_port=self.tcp.server_address[1])
        profile["nodes"][0]["port"] = port
        profile["nodes"][0]["outbound"]["tls"]["certificate"] = [self.cert.read_text()]
        self.request["target_ip"] = "127.0.0.1"; self.request["nodes"][0]["port"] = port
        server = {"log": {"disabled": True}, "inbounds": [{"type": "hysteria2", "listen": "127.0.0.1", "listen_port": port,
                   "users": [{"password": "isolated-fixture"}], "tls": {"enabled": True, "certificate_path": str(self.cert), "key_path": str(self.private)}}], "outbounds": [{"type": "direct"}]}
        self.server_file = self.root / "server.json"; self.server_file.write_text(json.dumps(server)); self.server_file.chmod(0o600)
        flags = subprocess.CREATE_NO_WINDOW if os.name == "nt" else 0
        self.server = subprocess.Popen([str(self.binary), "run", "-c", str(self.server_file)], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, creationflags=flags)
        time.sleep(0.3); self.assertIsNone(self.server.poll(), "loopback HY2 fixture failed to start")
        # Production has no loopback opt-in. Only this fixture patches the address-policy predicate.
        self.address_patch = patch.object(worker, "public_ipv4", side_effect=lambda value: value == "127.0.0.1")
        self.address_patch.start(); EchoUDP.mode = "normal"

    def tearDown(self):
        self.address_patch.stop(); self.server.terminate(); self.server.wait(timeout=3)
        for server in [self.tcp, self.udp]:
            server.shutdown(); server.server_close()
        for thread in self.threads:
            thread.join(timeout=2)
        super().tearDown()

    def test_real_tcp_udp_roundtrip_and_private_client_cleanup(self):
        receipt = self.verifier.handle(*self.signed())
        self.assertTrue(receipt["cleanup_complete"])
        result = receipt["results"][0]; self.assertTrue(result["endpoint_authenticated"])
        for transport in ["tcp", "udp"]:
            self.assertEqual(result[transport], {"sent": 3, "received": 3, "missing": 0, "duplicates": 0, "bytes": 96, "sha256": self.request["echo_sha256"]})
        self.assertEqual(list(self.verifier.state.glob("job-*")), [])
        with self.assertRaisesRegex(worker.Rejected, "challenge_replayed"):
            self.verifier.handle(*self.signed())

    def test_real_wrong_password_is_not_authenticated(self):
        self.config["profiles"]["test-profile"]["nodes"][0]["outbound"]["password"] = "wrong-fixture-password"
        receipt = self.verifier.handle(*self.signed())
        self.assertFalse(receipt["results"][0]["endpoint_authenticated"]); self.assertTrue(receipt["cleanup_complete"])
        self.assertEqual(list(self.verifier.state.glob("job-*")), [])
        self.config["profiles"]["test-profile"]["nodes"][0]["outbound"]["password"] = "isolated-fixture"
        self.request["challenge_id"] = secrets.token_hex(16)
        self.assertTrue(self.verifier.handle(*self.signed())["results"][0]["endpoint_authenticated"])

    def test_real_wrong_tls_name_is_not_authenticated(self):
        self.config["profiles"]["test-profile"]["nodes"][0]["outbound"]["tls"]["server_name"] = "wrong.example.test"
        receipt = self.verifier.handle(*self.signed())
        self.assertFalse(receipt["results"][0]["endpoint_authenticated"]); self.assertTrue(receipt["cleanup_complete"])

    def test_real_https_hmac_and_forwarding_contract(self):
        server = worker.Server(("127.0.0.1", 0), worker.Handler); server.verifier = self.verifier
        server.context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER); server.context.load_cert_chain(self.cert, self.private)
        thread = threading.Thread(target=server.serve_forever, daemon=True); thread.start()
        try:
            context = ssl.create_default_context(cafile=str(self.cert))
            connection = http.client.HTTPSConnection("127.0.0.1", server.server_address[1], context=context, timeout=10)
            raw, signature = self.signed()
            connection.request("POST", "/v1/verify", raw, {"Content-Type": "application/json", "X-Keli-Signature": signature})
            response = connection.getresponse(); body = response.read(); connection.close()
            self.assertEqual(response.status, 200)
            self.assertEqual(response.getheader("X-Keli-Signature"), hmac.new(self.verifier.key, b"response\n" + body, "sha256").hexdigest())
            receipt = json.loads(body); self.assertEqual(receipt["request_sha256"], hashlib.sha256(raw).hexdigest())
            self.assertTrue(receipt["cleanup_complete"]); self.assertEqual(receipt["results"][0]["udp"]["bytes"], 96)
        finally:
            server.shutdown(); server.server_close(); thread.join(timeout=2)

    def test_real_missing_or_duplicate_udp_never_passes(self):
        for mode in ["missing", "duplicate"]:
            with self.subTest(mode=mode):
                EchoUDP.mode = mode; self.request["challenge_id"] = secrets.token_hex(16)
                receipt = self.verifier.handle(*self.signed()); result = receipt["results"][0]["udp"]
                self.assertTrue(result["missing"] > 0 or result["duplicates"] > 0)
                self.assertTrue(receipt["cleanup_complete"])


if __name__ == "__main__":
    unittest.main(verbosity=1)
