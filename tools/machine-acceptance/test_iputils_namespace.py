"""Offline guard checks; no unshare, root commands, sockets or real kernel mutations."""
import json
from pathlib import Path
from types import SimpleNamespace
import unittest
from unittest.mock import Mock, patch

import iputils_namespace as ns


class NamespaceGuardTests(unittest.TestCase):
    def check(self, ruleset, *, env=None, pid=1, interfaces=('lo',), routes=None, links=('net:[2]', 'net:[2]')):
        def command(args, **kwargs):
            if 'link' in args:
                return json.dumps([{'ifname': name} for name in interfaces]).encode()
            return json.dumps([] if routes is None else routes).encode() if args[0].endswith('ip') else json.dumps(ruleset).encode()
        dispatch = Mock(side_effect=command)
        with patch.object(ns.sys, 'platform', 'linux'), patch.object(ns.os, 'geteuid', return_value=0, create=True), \
                patch.object(ns.os, 'getpid', return_value=pid), patch.dict(ns.os.environ, env or {'KELI_IPUTILS_NAMESPACE': '1'}, clear=True), \
                patch.object(ns.os, 'readlink', side_effect=links), patch.object(ns.Path, 'iterdir', return_value=[Path(v) for v in interfaces]), \
                patch.object(ns, 'command', dispatch):
            return ns.topology('net:[1]'), dispatch

    def test_empty_nft_json_shapes_are_equivalent_not_a_missing_evidence_error(self):
        for value in [{}, {'nftables': []}, {'nftables': [{'metainfo': {'version': 'test'}}]}]:
            actual, dispatch = self.check(value)
            self.assertEqual(actual, 'net:[2]')
            self.assertEqual(dispatch.call_count, 3)
            self.assertTrue(all('list' in call.args[0] or 'show' in call.args[0] for call in dispatch.call_args_list))

    def test_an_existing_table_or_unrecognized_object_is_not_fresh(self):
        for value in [{'nftables': [{'table': {'name': 'foreign'}}]}, {'unknown': []}, {'nftables': {}},
                      {'nftables': [{'counter': {}}]}]:
            with self.subTest(value=value), self.assertRaisesRegex(RuntimeError, 'fresh_empty_firewall_required'):
                self.check(value)

    def test_real_host_pid_or_credential_environment_is_refused(self):
        for change, reason in [({'pid': 20}, 'fresh_unshare_root_pid_one_required'),
                               ({'env': {'KELI_IPUTILS_NAMESPACE': '1', 'API_TOKEN': 'synthetic'}}, 'clean_namespace_environment_required')]:
            with self.subTest(change=change), self.assertRaisesRegex(RuntimeError, reason):
                self.check({}, **change)

    def test_parent_namespace_or_other_network_interface_is_refused(self):
        for change, reason in [({'links': ('net:[1]', 'net:[1]')}, 'independent_network_namespace_required'),
                               ({'interfaces': ('lo', 'eth0')}, 'loopback_only_required')]:
            with self.subTest(change=change), self.assertRaisesRegex(RuntimeError, reason):
                self.check({}, **change)

    def test_external_or_preexisting_routes_are_refused(self):
        with self.assertRaisesRegex(RuntimeError, 'fresh_empty_routes_required'):
            self.check({}, routes=[{'dst': 'default', 'dev': 'eth0'}])


if __name__ == '__main__':
    unittest.main()
