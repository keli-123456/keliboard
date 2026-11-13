"""Offline guard checks; no unshare, root commands, sockets or real kernel mutations."""
from contextlib import ExitStack, redirect_stdout
import io
import importlib.machinery
import importlib.util
import importlib._bootstrap_external as bootstrap_external
import json
from pathlib import Path
import shutil
import signal
import stat
import subprocess
import sys
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import Mock, patch

import iputils_namespace as ns


SECRET = 'synthetic_private_argument_body_log_environment_message'


def successful_child(name):
    measurement = {'state': 'observed', 'exit_code': 0, 'classification': 'echo_reply', 'sent': 1,
                   'received': 1, 'reported_errors': 0, 'error_categories': []}
    expected = 1
    if name in {'no_reply', 'four_no_reply_targets', 'legacy_deadline_reference'}:
        measurement.update(exit_code=1, classification='no_echo_by_deadline', received=0)
    if name == 'legacy_deadline_reference':
        expected = 2
        measurement.update(sent=2, classification='packet_budget_exceeded')
    if name == 'four_no_reply_targets':
        expected = 4
    if name == 'forced_timeout_reaps_real_ping':
        measurement.update(state='timeout', exit_code=None, classification='unavailable', sent=None,
                           received=None, reported_errors=None)
    if name == 'permission_denied_is_not_packet_loss':
        expected = 0
        measurement.update(exit_code=2, classification='execution_error', sent=None, received=None,
                           reported_errors=None, error_categories=['permission_denied'])
    result = {'case': name, 'ok': True, 'tests': 1, 'failures': 0, 'errors': 0, 'skipped': 0,
              'expected_packets': expected, 'measurements': [measurement] * (4 if expected == 4 else 1)}
    if name == 'legacy_deadline_reference':
        result['legacy_extra_packets_reproduced'] = True
    return result


def failed_child(name='echo'):
    value = successful_child(name)
    value.update(ok=False, failures=1, failed_tests=[SECRET],
                 failure_details=[{'error_class': 'AssertionError', 'message': SECRET,
                                   'frames': [{'file': 'test_entry_probe_iputils.py', 'line': 44},
                                              {'file': SECRET + '.py', 'line': 99}]}])
    return value


class ChildBootstrapTests(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory(prefix='offline-iputils-bootstrap-')
        self.addCleanup(temporary.cleanup)
        self.stage = Path(temporary.name).resolve()
        for name in ['test_entry_probe_iputils.py', 'entry_probe_diagnostics.py', 'bootstrap_diagnostics.py']:
            shutil.copyfile(ns.HERE / name, self.stage / name)
            (self.stage / name).chmod(0o644)
        self.stage.chmod(0o711)
        spec = importlib.util.spec_from_file_location('offline_iputils_child', self.stage / 'test_entry_probe_iputils.py')
        self.child = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(self.child)
        for name in ['Popen', 'run', 'call', 'check_call', 'check_output']:
            blocked = patch.object(subprocess, name, side_effect=AssertionError('dispatch forbidden'))
            blocked.start()
            self.addCleanup(blocked.stop)

    def invoke(self, arguments=None, *, loader_error=None, loader=None, suite_error=None):
        test = Mock()
        template = successful_child('echo')
        test.summary = {key: template[key] for key in ['expected_packets', 'measurements']}
        suite = Mock()
        def run(result):
            if suite_error is not None:
                raise suite_error
            result.testsRun = 1
        suite.run.side_effect = run
        output = io.StringIO()
        with redirect_stdout(output), patch.object(self.child, 'load_probe',
                side_effect=loader_error if loader_error is not None else loader,
                return_value=SimpleNamespace()), \
                patch.object(self.child, 'RealIputilsTests', return_value=test), \
                patch.object(self.child.unittest, 'TestSuite', return_value=suite):
            code = self.child.main(['echo'] if arguments is None else arguments)
        return code, json.loads(output.getvalue())

    def test_denied_directory_listing_breaks_filefinder_but_fixed_loading_succeeds(self):
        original = bootstrap_external._os.listdir
        denied_calls = []
        def listdir(path):
            if Path(path).resolve() == self.stage:
                denied_calls.append(True)
                raise PermissionError(13, SECRET)
            return original(path)
        finder = importlib.machinery.FileFinder(str(self.stage),
            (importlib.machinery.SourceFileLoader, importlib.machinery.SOURCE_SUFFIXES))
        with patch.object(bootstrap_external._os, 'listdir', side_effect=listdir):
            self.assertIsNone(finder.find_spec('entry_probe_diagnostics'))
            before = len(denied_calls)
            module = self.child.load_probe()
            self.assertEqual(len(denied_calls), before)
        self.assertTrue(denied_calls)
        self.assertEqual(Path(module.__file__).parent, self.stage)
        self.assertEqual(Path(module.bootstrap.__file__).parent, self.stage)
        self.assertEqual(module.ping_arguments('ping', '127.0.0.2', '127.0.0.1', 2)[-1], '127.0.0.2')

    def test_nested_dependency_uses_fixed_origin_not_shadow_modules(self):
        shadow_bootstrap, shadow_probe = Mock(), Mock()
        with patch.dict(sys.modules, {'bootstrap_diagnostics': shadow_bootstrap, 'entry_probe_diagnostics': shadow_probe}):
            loaded = self.child.load_probe()
            self.assertIsNot(loaded, shadow_probe)
            self.assertIsNot(loaded.bootstrap, shadow_bootstrap)
            self.assertEqual(Path(loaded.bootstrap.__file__), self.stage / 'bootstrap_diagnostics.py')
            self.assertIs(sys.modules['bootstrap_diagnostics'], shadow_bootstrap)
            self.assertIs(sys.modules['entry_probe_diagnostics'], shadow_probe)

    def test_loader_rejects_unsafe_file_metadata_without_running_it(self):
        original = Path.lstat
        def lstat(path):
            if path == self.stage / 'entry_probe_diagnostics.py':
                return SimpleNamespace(st_mode=stat.S_IFLNK | 0o777, st_size=20)
            return original(path)
        with patch.object(Path, 'lstat', lstat), self.assertRaises(PermissionError):
            self.child.load_probe()

    def test_missing_module_is_structured_before_any_test_or_ping(self):
        code, value = self.invoke(loader_error=ModuleNotFoundError(SECRET))
        self.assertEqual(code, 1)
        self.assertEqual((value['tests'], value['errors']), (0, 1))
        self.assertEqual((value['first_error']['stage'], value['first_error']['reason'], value['first_error']['error_class']),
                         ('child_import', 'child_import_failed', 'ModuleNotFoundError'))
        self.assertNotIn(SECRET, json.dumps(value))
        safe, reason = ns.child_result(json.dumps(value).encode(), 'echo', code)
        self.assertIsNone(reason)
        self.assertFalse(safe['ok'])
        self.assertEqual(safe['first_error']['exit_code'], 1)

    def test_access_error_preserves_errno_and_not_exception_text(self):
        code, value = self.invoke(loader_error=PermissionError(13, SECRET, SECRET))
        self.assertEqual(code, 1)
        self.assertEqual(value['first_error']['errno'], 13)
        self.assertEqual(value['first_error']['error_class'], 'PermissionError')
        self.assertNotIn(SECRET, json.dumps(value))
        self.assertTrue(value['first_error']['frames'])

    def test_syntax_error_records_known_basename_and_line_only(self):
        (self.stage / 'bootstrap_diagnostics.py').write_text('def broken(:\n' + SECRET, encoding='ascii')
        error = None
        try:
            self.child.load_probe()
        except SyntaxError as issue:
            error = issue
        self.assertIsNotNone(error)
        code, value = self.invoke(loader_error=error)
        self.assertEqual(code, 1)
        self.assertIn({'file': 'bootstrap_diagnostics.py', 'line': 1}, value['first_error']['frames'])
        self.assertNotIn(SECRET, json.dumps(value))
        self.assertNotIn(str(self.stage), json.dumps(value))

    def test_unknown_arguments_never_echo_input_or_load_modules(self):
        for arguments in [[], [SECRET], ['echo', SECRET]]:
            with self.subTest(length=len(arguments)):
                code, value = self.invoke(arguments, loader_error=AssertionError('must not load'))
                self.assertEqual(code, 1)
                self.assertEqual(value['first_error']['reason'], 'child_arguments_invalid')
                self.assertIsNone(value['case'])
                self.assertNotIn(SECRET, json.dumps(value))

    def test_import_noise_is_discarded_not_mistaken_for_success(self):
        def noisy():
            print(SECRET)
            print(SECRET, file=sys.stderr)
            return SimpleNamespace()
        code, value = self.invoke(loader=noisy)
        self.assertEqual(code, 1)
        self.assertEqual(value['first_error']['reason'], 'child_output_contaminated')
        self.assertNotIn(SECRET, json.dumps(value))

    def test_import_error_keeps_priority_over_noise(self):
        def noisy():
            print(SECRET)
            raise PermissionError(13, SECRET)
        code, value = self.invoke(loader=noisy)
        self.assertEqual(code, 1)
        self.assertEqual(value['first_error']['reason'], 'child_import_failed')
        self.assertEqual(value['secondary_errors'][0]['reason'], 'child_output_contaminated')
        self.assertNotIn(SECRET, json.dumps(value))

    def test_interrupted_case_still_emits_one_structured_failure(self):
        for error in [KeyboardInterrupt(SECRET), subprocess.TimeoutExpired([SECRET], 1), SystemExit(SECRET)]:
            with self.subTest(error=type(error).__name__):
                code, value = self.invoke(suite_error=error)
                self.assertEqual(code, 1)
                self.assertEqual(value['first_error']['stage'], 'child_test')
                self.assertNotIn(SECRET, json.dumps(value))

    def test_synthetic_success_retains_existing_case_contract(self):
        code, value = self.invoke()
        self.assertEqual(code, 0)
        self.assertTrue(value['ok'])
        self.assertNotIn('first_error', value)
        safe, reason = ns.child_result(json.dumps(value).encode(), 'echo', code)
        self.assertIsNone(reason)
        self.assertTrue(safe['ok'])

    def test_output_capture_is_bounded_and_keeps_no_contents(self):
        sink = self.child.DiscardOutput()
        self.assertEqual(sink.write(SECRET * self.child.MAX_OUTPUT), len(SECRET) * self.child.MAX_OUTPUT)
        self.assertEqual(sink.count, self.child.MAX_OUTPUT + 1)
        self.assertEqual(set(vars(sink)), {'count'})
        self.assertNotIn(SECRET, repr(vars(sink)))


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


class DiagnosticTests(unittest.TestCase):
    def test_first_error_and_legacy_fields_are_immutable_and_secondaries_bounded(self):
        report = ns.new_report()
        ns.record_error(report, ns.NamespaceFailure('namespace_tool_failed', stage='topology_links', code=23), 'main')
        first = dict(report['first_error'])
        for _ in range(30):
            ns.record_error(report, PermissionError(SECRET), 'cleanup_directory')
        self.assertEqual(report['first_error'], first)
        self.assertEqual(report['error_class'], first['error_class'])
        self.assertEqual(report['reason'], first['reason'])
        self.assertEqual(report['error_frames'], first['frames'])
        self.assertEqual(len(report['secondary_errors']), ns.MAX_SECONDARY_ERRORS)
        self.assertNotIn(SECRET, json.dumps(report))

    def test_untrusted_reasons_classes_stages_and_frames_are_not_echoed(self):
        report = ns.new_report()
        private_error = type(SECRET, (RuntimeError,), {})
        ns.record_error(report, private_error(SECRET), SECRET)
        ns.record_error(report, ns.NamespaceFailure(SECRET, stage=SECRET, code=True), 'main')
        frames = [{'file': 'iputils_namespace.py', 'line': n} for n in range(1, 30)]
        frames += [{'file': SECRET, 'line': 1}, {'file': '/private/iputils_namespace.py', 'line': 1},
                   {'file': 'iputils_namespace.py', 'line': True}, {'file': 'iputils_namespace.py', 'line': -1},
                   {'file': 'iputils_namespace.py', 'line': 100001}, {'file': 'iputils_namespace.py', 'line': SECRET}]
        safe = ns.safe_frames(frames)
        self.assertEqual(safe, [{'file': 'iputils_namespace.py', 'line': n} for n in range(24, 30)])
        self.assertEqual(report['first_error']['error_class'], 'Exception')
        self.assertEqual(report['first_error']['stage'], 'unknown')
        self.assertEqual(report['first_error']['reason'], 'operation_failed')
        self.assertNotIn('exit_code', report['secondary_errors'][0])
        self.assertNotIn(SECRET, json.dumps(report))

    def test_traceback_excludes_foreign_paths_and_keeps_no_source_or_function(self):
        try:
            exec(compile('raise RuntimeError()', '/private/' + SECRET + '/iputils_namespace.py', 'exec'))
        except RuntimeError as error:
            entry = ns.error_entry(error, 'main')
        self.assertEqual(entry['frames'], [])
        self.assertNotIn(SECRET, json.dumps(entry))

    def test_child_projection_retains_only_typed_allowlisted_evidence(self):
        value = failed_child()
        value.update(command=SECRET, body=SECRET, stdout=SECRET, environment={'API_TOKEN': SECRET},
                     launch_error={'error_class': SECRET, 'errno': 13, 'message': SECRET})
        value['measurements'][0].update(target=SECRET, stdout=SECRET)
        safe, reason = ns.child_result(json.dumps(value).encode(), 'echo', 1)
        self.assertIsNone(reason)
        self.assertFalse(safe['ok'])
        self.assertEqual(safe['failure_details'], [{'error_class': 'AssertionError',
                                                   'frames': [{'file': 'test_entry_probe_iputils.py', 'line': 44}]}])
        self.assertEqual(safe['launch_error'], {'error_class': 'Exception', 'errno': 13})
        self.assertNotIn(SECRET, json.dumps(safe))

    def test_malformed_child_json_always_has_fixed_reason_and_exit_code(self):
        for raw in [b'', SECRET.encode(), b'{"ok": true, "ok": false}', b'{"ok": NaN}',
                    b'{"ok": Infinity}', b'\xff', b'[' * 2000]:
            with self.subTest(raw_length=len(raw)), self.assertRaises(ns.NamespaceFailure) as failed:
                ns.child_result(raw, 'echo', -15)
            detail = ns.error_entry(failed.exception, 'main')
            self.assertEqual(detail['stage'], 'child_output')
            self.assertEqual(detail['reason'], 'child_output_invalid')
            self.assertEqual(detail['exit_code'], -15)
            self.assertNotIn(SECRET, json.dumps(detail))

    def test_missing_malformed_or_unknown_child_evidence_is_not_success(self):
        for value in [None, [], True, {}, {'case': 'echo', 'ok': True},
                      dict(successful_child('echo'), tests=True),
                      dict(successful_child('echo'), expected_packets=None),
                      dict(successful_child('echo'), measurements=[]),
                      dict(successful_child('echo'), measurements=[{'state': SECRET}]),
                      dict(successful_child('echo'), case=SECRET),
                      dict(successful_child('echo'), ok=1)]:
            with self.subTest(value_type=type(value).__name__):
                safe, reason = ns.child_result(json.dumps(value).encode(), 'echo', 0)
                self.assertFalse(safe['ok'])
                self.assertIn(reason, {'child_result_invalid', 'child_result_incomplete'})
                self.assertNotIn(SECRET, json.dumps(safe))

    def test_malformed_bootstrap_diagnostics_cannot_crash_or_pass_projection(self):
        for change in [None, [], {'stage': [], 'reason': {}}, {'stage': 'child_import', 'reason': SECRET}]:
            value = dict(successful_child('echo'), first_error=change)
            safe, reason = ns.child_result(json.dumps(value).encode(), 'echo', 0)
            self.assertFalse(safe['ok'])
            self.assertEqual(reason, 'child_result_invalid')
            self.assertNotIn(SECRET, json.dumps(safe))
        for secondary in [{}, [None], [{'stage': [], 'reason': {}}]]:
            value = dict(successful_child('echo'), secondary_errors=secondary)
            safe, reason = ns.child_result(json.dumps(value).encode(), 'echo', 0)
            self.assertFalse(safe['ok'])
            self.assertEqual(reason, 'child_result_invalid')

    def test_bootstrap_diagnostic_fields_are_allowlisted_and_bounded(self):
        value = dict(successful_child('echo'), ok=False, tests=0, errors=1, measurements=[],
            first_error={'stage': 'child_import', 'reason': 'child_import_failed', 'error_class': 'PermissionError',
                         'errno': 13, 'message': SECRET, 'command': SECRET,
                         'frames': [{'file': 'bootstrap_diagnostics.py', 'line': 1}, {'file': SECRET, 'line': 2}]})
        safe, reason = ns.child_result(json.dumps(value).encode(), 'echo', 1)
        self.assertIsNone(reason)
        self.assertEqual(safe['first_error'], {'stage': 'child_import', 'reason': 'child_import_failed',
            'error_class': 'PermissionError', 'errno': 13, 'exit_code': 1,
            'frames': [{'file': 'bootstrap_diagnostics.py', 'line': 1}]})
        self.assertNotIn(SECRET, json.dumps(safe))


class CommandTests(unittest.TestCase):
    def test_nonzero_and_signal_exits_keep_the_fixed_command_phase(self):
        for code in [19, -15]:
            with self.subTest(code=code), patch.object(ns.subprocess, 'run', return_value=SimpleNamespace(
                    returncode=code, stdout=SECRET.encode(), stderr=SECRET.encode())), self.assertRaises(ns.NamespaceFailure) as failed:
                ns.command(['/private/' + SECRET, SECRET], body=SECRET.encode(), stage='firewall_setup')
            detail = ns.error_entry(failed.exception, 'main')
            self.assertEqual((detail['stage'], detail['reason'], detail['exit_code']),
                             ('firewall_setup', 'namespace_tool_failed', code))
            self.assertFalse(detail['timed_out'])
            self.assertNotIn(SECRET, json.dumps(detail))

    def test_timeout_and_missing_tool_never_report_command_or_output(self):
        for error, reason in [(subprocess.TimeoutExpired([SECRET], 5, output=SECRET, stderr=SECRET), 'namespace_tool_timeout'),
                              (FileNotFoundError(2, SECRET, SECRET), 'namespace_tool_unavailable'),
                              (ValueError(SECRET), 'namespace_tool_failed')]:
            with self.subTest(error=type(error).__name__), patch.object(ns.subprocess, 'run', side_effect=error), \
                    self.assertRaises(ns.NamespaceFailure) as failed:
                ns.command([SECRET], body=SECRET.encode(), stage='topology_routes')
            detail = ns.error_entry(failed.exception, 'main')
            self.assertEqual((detail['reason'], detail['stage']), (reason, 'topology_routes'))
            if isinstance(error, subprocess.TimeoutExpired):
                self.assertTrue(detail['timed_out'])
            self.assertNotIn(SECRET, json.dumps(detail))

    def test_malformed_tool_json_retains_its_phase(self):
        with patch.object(ns, 'command', return_value=SECRET.encode()), self.assertRaises(ns.NamespaceFailure) as failed:
            ns.command_json([SECRET], stage='cleanup_firewall_check')
        detail = ns.error_entry(failed.exception, 'main')
        self.assertEqual((detail['stage'], detail['reason'], detail['error_class']),
                         ('cleanup_firewall_check', 'namespace_tool_output_invalid', 'JSONDecodeError'))
        self.assertNotIn(SECRET, json.dumps(detail))

    def test_tool_uses_the_original_clean_environment(self):
        with patch.object(ns.subprocess, 'run', return_value=SimpleNamespace(returncode=0, stdout=b'{}')) as called:
            self.assertEqual(ns.command(['fixed'], stage='topology_links'), b'{}')
        self.assertEqual(called.call_args.kwargs['env'], {'PATH': '/usr/sbin:/usr/bin:/sbin:/bin', 'LANG': 'C', 'LC_ALL': 'C'})
        self.assertTrue(called.call_args.kwargs['capture_output'])
        self.assertEqual(called.call_args.kwargs['timeout'], 5)


class ChildProcessTests(unittest.TestCase):
    def test_timeout_checkpoint_precedes_kill_wait_and_pipe_cleanup(self):
        process = Mock(pid=1234, returncode=-9)
        process.communicate.side_effect = subprocess.TimeoutExpired([SECRET], 16, output=SECRET)
        process.poll.return_value = None
        process.wait.return_value = -9
        with tempfile.TemporaryDirectory() as directory:
            output = Path(directory) / 'report.json'
            options = SimpleNamespace(output=str(output))
            report = ns.new_report()
            report.checkpoint = lambda: ns.checkpoint_error(options, report)
            def before_kill(*args):
                self.assertTrue(output.with_name('report.first-error.json').is_file())
                self.assertEqual(report['first_error']['reason'], 'child_timeout')
            with patch.object(ns, 'in_namespace', return_value=True), \
                    patch.object(ns.subprocess, 'Popen', return_value=process), \
                    patch.object(ns.os, 'killpg', side_effect=before_kill, create=True), \
                    patch.object(ns.signal, 'SIGKILL', 9, create=True), self.assertRaises(ns.NamespaceFailure):
                ns.run_child([SECRET], 1000, 1001, Path('home'), 'echo', 16, Path('ping'),
                             on_failure=lambda error: ns.record_error(report, error, 'child_wait'))
            self.assertTrue(report['first_error_persisted'])
            self.assertNotIn(SECRET, output.with_name('report.first-error.json').read_text())
        process.wait.assert_called_once()
        process.stdout.close.assert_called_once()

    def test_failure_observer_error_does_not_skip_child_teardown(self):
        process = Mock(pid=1234, returncode=-9)
        process.communicate.side_effect = subprocess.TimeoutExpired([SECRET], 16)
        process.poll.return_value = None
        with patch.object(ns.subprocess, 'Popen', return_value=process), \
                patch.object(ns.os, 'killpg', create=True) as kill, \
                patch.object(ns.signal, 'SIGKILL', 9, create=True), self.assertRaises(ns.NamespaceFailure) as failed:
            ns.run_child([SECRET], 1000, 1001, Path('home'), 'echo', 16, Path('ping'),
                         on_failure=Mock(side_effect=PermissionError(SECRET)))
        self.assertEqual(failed.exception.reason, 'child_timeout')
        self.assertEqual(failed.exception.secondary_errors[0]['reason'], 'report_write_failed')
        kill.assert_called_once()
        process.wait.assert_called_once()
        process.stdout.close.assert_called_once()

    def launch(self, process, *, observe=None):
        with patch.object(ns.subprocess, 'Popen', return_value=process) as launch, \
                patch.object(ns.os, 'killpg', create=True) as kill, patch.object(ns.signal, 'SIGKILL', 9, create=True):
            try:
                result = ns.run_child([SECRET], 1000, 1001, Path('private-home'), 'echo', 16,
                                      Path('private-ping'), observe=observe)
            except ns.NamespaceFailure as error:
                result = error
        return result, launch, kill

    def test_timeout_wins_over_kill_wait_and_pipe_cleanup_errors(self):
        process = Mock(pid=1234, returncode=-9)
        process.communicate.side_effect = subprocess.TimeoutExpired([SECRET], 16, output=SECRET, stderr=SECRET)
        process.poll.return_value = None
        process.wait.side_effect = PermissionError(SECRET)
        process.stdout.close.side_effect = OSError(SECRET)
        with patch.object(ns.subprocess, 'Popen', return_value=process), \
                patch.object(ns.os, 'killpg', side_effect=ProcessLookupError(SECRET), create=True), \
                patch.object(ns.signal, 'SIGKILL', 9, create=True), \
                self.assertRaises(ns.NamespaceFailure) as failed:
            ns.run_child([SECRET], 1000, 1001, Path('home'), 'echo', 16, Path('ping'))
        report = ns.new_report()
        ns.record_error(report, failed.exception, 'main')
        self.assertEqual((report['first_error']['reason'], report['first_error']['error_class']), ('child_timeout', 'TimeoutExpired'))
        self.assertTrue(report['first_error']['timed_out'])
        self.assertEqual(report['first_error']['exit_code'], -9)
        self.assertEqual([entry['stage'] for entry in report['secondary_errors']],
                         ['cleanup_child_kill', 'cleanup_child_wait', 'cleanup_child_close'])
        process.wait.assert_called_once_with(timeout=2)
        process.stdout.close.assert_called_once_with()
        self.assertNotIn(SECRET, json.dumps(report))

    def test_invalid_output_is_observed_before_pipe_cleanup_failure(self):
        process = Mock(pid=1234, returncode=7)
        process.communicate.return_value = (SECRET.encode(), None)
        process.poll.return_value = 7
        process.stdout.close.side_effect = OSError(SECRET)
        error, _, _ = self.launch(process, observe=lambda code, raw: ns.child_result(raw, 'echo', code))
        report = ns.new_report()
        ns.record_error(report, error, 'main')
        self.assertEqual((report['first_error']['reason'], report['first_error']['exit_code']), ('child_output_invalid', 7))
        self.assertEqual(report['secondary_errors'][0]['stage'], 'cleanup_child_close')
        self.assertNotIn(SECRET, json.dumps(report))

    def test_exit_failure_survives_wait_failure_without_an_observer(self):
        process = Mock(pid=1234, returncode=-15)
        process.communicate.return_value = (SECRET.encode(), None)
        process.poll.return_value = -15
        process.wait.side_effect = OSError(SECRET)
        error, _, _ = self.launch(process)
        report = ns.new_report()
        ns.record_error(report, error, 'main')
        self.assertEqual((report['first_error']['reason'], report['first_error']['exit_code']), ('child_signaled', -15))
        self.assertEqual(report['secondary_errors'][0]['stage'], 'cleanup_child_wait')
        process.stdout.close.assert_called_once_with()

    def test_interrupt_and_oversized_output_still_reap_and_close(self):
        for issue, reason in [(KeyboardInterrupt(SECRET), 'interrupted'), (b'x' * (ns.MAX_OUTPUT + 1), 'test_output_oversized')]:
            process = Mock(pid=1234, returncode=-9)
            if isinstance(issue, BaseException):
                process.communicate.side_effect = issue
            else:
                process.communicate.return_value = (issue, None)
            process.poll.return_value = None
            error, _, kill = self.launch(process)
            self.assertEqual(ns.error_entry(error, 'main')['reason'], reason)
            kill.assert_called_once_with(1234, 9)
            process.wait.assert_called_once_with(timeout=2)
            process.stdout.close.assert_called_once_with()

    def test_launch_failure_is_sanitized(self):
        with patch.object(ns.subprocess, 'Popen', side_effect=FileNotFoundError(SECRET)), self.assertRaises(ns.NamespaceFailure) as failed:
            ns.run_child([SECRET], 1000, 1001, Path('home'), 'echo', 16, Path('ping'))
        detail = ns.error_entry(failed.exception, 'main')
        self.assertEqual((detail['stage'], detail['reason'], detail['error_class']),
                         ('child_launch', 'child_launch_failed', 'FileNotFoundError'))
        self.assertNotIn(SECRET, json.dumps(detail))

    def test_stderr_import_failure_precedes_json_decode_and_remains_private(self):
        process = Mock(pid=1234, returncode=1)
        raw = ('Traceback (most recent call last):\n  File "' + str(ns.HERE / 'test_entry_probe_iputils.py')
               + '", line 12, in <module>\n    ' + SECRET + '\nModuleNotFoundError: ' + SECRET).encode()
        process.communicate.return_value = (b'', raw)
        process.poll.return_value = 1
        error, launch, _ = self.launch(process, observe=lambda code, data: ns.child_result(data, 'echo', code))
        detail = ns.error_entry(error, 'main')
        self.assertEqual((detail['stage'], detail['reason'], detail['error_class'], detail['exit_code']),
                         ('child_import', 'child_import_failed', 'ModuleNotFoundError', 1))
        self.assertEqual(detail['frames'], [{'file': 'test_entry_probe_iputils.py', 'line': 12}])
        self.assertEqual(launch.call_args.kwargs['stderr'], subprocess.PIPE)
        process.stderr.close.assert_called_once_with()
        self.assertNotIn(SECRET, json.dumps(detail))

    def test_interpreter_and_setpriv_access_errors_are_not_packet_loss(self):
        for raw, reason in [(b"python3: can't open file '/private/file': [Errno 13] Permission denied", 'child_interpreter_failed'),
                            (b'setpriv: failed to execute /private/file: Permission denied', 'child_permission_denied')]:
            with self.subTest(reason=reason):
                process = Mock(pid=1234, returncode=126)
                process.communicate.return_value = (b'', raw)
                process.poll.return_value = 126
                error, _, _ = self.launch(process)
                detail = ns.error_entry(error, 'main')
                self.assertEqual((detail['reason'], detail['error_class'], detail['errno']), (reason, 'PermissionError', 13))
                self.assertNotIn('/private/', json.dumps(detail))

    def test_structured_import_error_survives_stderr_and_close_failure(self):
        value = dict(successful_child('echo'), ok=False, tests=0, errors=1, measurements=[],
            first_error={'stage': 'child_import', 'reason': 'child_import_failed', 'error_class': 'PermissionError',
                         'errno': 13, 'frames': [{'file': 'bootstrap_diagnostics.py', 'line': 1}]})
        process = Mock(pid=1234, returncode=1)
        process.communicate.return_value = (json.dumps(value).encode(), SECRET.encode())
        process.poll.return_value = 1
        process.stderr.close.side_effect = OSError(SECRET)
        report = ns.new_report()
        def observe(code, data):
            safe, invalid = ns.child_result(data, 'echo', code)
            self.assertIsNone(invalid)
            ns.record_child_failure(report, safe, code)
        error, _, _ = self.launch(process, observe=observe)
        ns.record_error(report, error, 'main')
        self.assertEqual(report['first_error']['reason'], 'child_import_failed')
        self.assertEqual(report['first_error']['errno'], 13)
        self.assertEqual([item['reason'] for item in report['secondary_errors']],
                         ['child_output_contaminated', 'child_cleanup_failed'])
        self.assertNotIn(SECRET, json.dumps(report))

    def test_mixed_output_is_not_salvaged_into_a_passing_result(self):
        process = Mock(pid=1234, returncode=0)
        process.communicate.return_value = (SECRET.encode() + json.dumps(successful_child('echo')).encode(), None)
        process.poll.return_value = 0
        error, _, _ = self.launch(process, observe=lambda code, data: ns.child_result(data, 'echo', code))
        self.assertEqual(error.reason, 'child_output_invalid')
        process.communicate.return_value = (json.dumps(successful_child('echo')).encode(), SECRET.encode())
        error, _, _ = self.launch(process, observe=lambda code, data: ns.child_result(data, 'echo', code))
        self.assertEqual(error.reason, 'child_output_contaminated')
        self.assertNotIn(SECRET, json.dumps(ns.error_entry(error, 'main')))

    def test_timeout_keeps_priority_over_partial_stderr_and_both_pipes_close(self):
        process = Mock(pid=1234, returncode=-9)
        process.communicate.side_effect = subprocess.TimeoutExpired([SECRET], 16, output=b'{',
            stderr=b'ModuleNotFoundError: ' + SECRET.encode())
        process.poll.return_value = None
        error, _, _ = self.launch(process)
        self.assertEqual(error.reason, 'child_timeout')
        process.stdout.close.assert_called_once_with()
        process.stderr.close.assert_called_once_with()

    def test_stderr_output_budget_is_enforced(self):
        process = Mock(pid=1234, returncode=0)
        process.communicate.return_value = (b'{}', b'x' * (ns.MAX_OUTPUT + 1))
        process.poll.return_value = 0
        error, _, _ = self.launch(process)
        self.assertEqual(error.reason, 'test_output_oversized')

    def test_success_keeps_setpriv_and_pipe_isolation_controls(self):
        process = Mock(pid=1234, returncode=0)
        process.communicate.return_value = (b'{}', None)
        process.poll.return_value = 0
        result, launch, kill = self.launch(process)
        self.assertEqual(result, (0, b'{}'))
        args = launch.call_args.args[0]
        for flag in ['--clear-groups', '--inh-caps=-all', '--ambient-caps=-all', '--bounding-set=-all', '--no-new-privs']:
            self.assertIn(flag, args)
        self.assertEqual(args[:5], ['/usr/bin/setpriv', '--reuid', '1000', '--regid', '1001'])
        self.assertTrue(launch.call_args.kwargs['start_new_session'])
        self.assertEqual(launch.call_args.kwargs['stdin'], subprocess.DEVNULL)
        self.assertNotIn(SECRET, json.dumps(launch.call_args.kwargs['env']))
        kill.assert_not_called()
        process.stdout.close.assert_called_once_with()


class RunnerReportTests(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory(prefix='mock-iputils-report-')
        self.addCleanup(temporary.cleanup)
        self.base = Path(temporary.name)
        self.root = self.base / 'workspace'
        self.root.mkdir()
        self.options = SimpleNamespace(uid=1000, gid=1001, parent_netns='net:[1]',
                                       output=str(self.base / 'report.json'), rust_test=None)
        stack = ExitStack()
        self.addCleanup(stack.close)

        def mocked(target, **kwargs):
            return stack.enter_context(patch.object(ns, target, **kwargs))

        self.topology = mocked('topology', return_value='net:[2]')
        mocked('in_namespace', return_value=True)
        self.command_errors = {}
        self.command_outputs = {}
        self.commands = mocked('command', side_effect=self.command)
        self.packets = 0
        self.counter = mocked('counter', side_effect=lambda: self.packets)
        self.rules = mocked('rule')
        self.children = mocked('no_remaining_children', return_value=[])
        self.payloads = {}
        self.codes = {}
        self.child_errors = {}
        self.post_child_errors = {}
        self.child = mocked('run_child', side_effect=self.run_child)
        self.mkdtemp = stack.enter_context(patch.object(ns.tempfile, 'mkdtemp', return_value=str(self.root)))
        self.copy = stack.enter_context(patch.object(ns.shutil, 'copyfile'))
        self.remove = stack.enter_context(patch.object(ns.shutil, 'rmtree', wraps=ns.shutil.rmtree))
        self.capabilities = stack.enter_context(patch.object(ns.os, 'listxattr', return_value=[], create=True))
        stack.enter_context(patch.object(ns.os, 'chown', create=True))
        stack.enter_context(patch.object(ns.Path, 'chmod'))
        stack.enter_context(patch.object(ns.Path, 'read_bytes', return_value=b'synthetic iputils bytes'))
        self.group_range = stack.enter_context(patch.object(ns.Path, 'write_text'))

    def command(self, args, *, stage, **kwargs):
        if stage in self.command_errors:
            raise self.command_errors[stage]
        return self.command_outputs.get(stage, b'ping from iputils synthetic' if stage == 'iputils_version' else b'{}')

    def run_child(self, args, uid, gid, home, mode, timeout, ping, *, observe, on_failure=None):
        name = args[-1] if args[0] == '/usr/bin/python3' else 'rust_' + mode
        if name in self.child_errors:
            raise self.child_errors[name]
        value = self.payloads.get(name, successful_child(name))
        self.packets += value.get('expected_packets', 0) if isinstance(value, dict) else 0
        code = self.codes.get(name, 0)
        raw = value if isinstance(value, bytes) else json.dumps(value).encode()
        observe(code, raw)
        if name in self.post_child_errors:
            raise self.post_child_errors[name]
        return code, raw

    def assert_saved(self, report):
        self.assertEqual(json.loads(Path(self.options.output).read_text(encoding='utf-8')), report)
        self.assertNotIn(SECRET, json.dumps(report))

    def main(self):
        argv = ['iputils_namespace.py', '--uid', str(self.options.uid), '--gid', str(self.options.gid),
                '--parent-netns', self.options.parent_netns, '--output', self.options.output]
        output = io.StringIO()
        with patch.object(ns.sys, 'argv', argv), patch.object(ns.signal, 'signal', return_value=signal.SIG_DFL), redirect_stdout(output):
            code = ns.main()
        return code, json.loads(output.getvalue())

    def test_six_successes_keep_existing_case_and_isolation_semantics(self):
        report = ns.run(self.options)
        self.assertTrue(report['ok'])
        self.assertEqual([row['case'] for row in report['cases']], [name for name, _ in ns.CASES])
        self.assertEqual([row['expected_packets'] for row in report['cases']], [1, 1, 4, 2, 1, 0])
        self.assertIsNone(report['first_error'])
        self.assertEqual(report['secondary_errors'], [])
        self.assertTrue(all(report['cleanup'].values()))
        self.assertEqual(report['isolation']['child_capabilities'], 0)
        self.assertTrue(report['isolation']['no_new_privileges'])
        self.copy.assert_called_once_with('/usr/bin/ping', self.root / 'iputils-ping')
        self.assertTrue(report['capability_free_copy_matches_system_binary'])
        self.assertEqual(self.child.call_count, 6)
        self.assertEqual(self.group_range.call_args.args[0], '65534 65534')
        self.assert_saved(report)

        for call in self.child.call_args_list:
            self.assertEqual(call.args[0][1:3], ['-I', '-B'])

    def test_root_topology_failure_exists_before_any_workspace_or_tool_activity(self):
        self.topology.side_effect = ns.NamespaceFailure('fresh_unshare_root_pid_one_required')
        report = ns.run(self.options)
        self.assertFalse(report['ok'])
        self.assertEqual((report['first_error']['stage'], report['reason']), ('topology', 'fresh_unshare_root_pid_one_required'))
        self.assertEqual(report['cases'], [])
        self.assertFalse(report['isolation']['private_pid_namespace'])
        self.assertIsNone(report['isolation']['external_routes'])
        self.commands.assert_not_called()
        self.mkdtemp.assert_not_called()
        self.children.assert_not_called()
        self.remove.assert_not_called()
        self.assert_saved(report)

    def test_version_tool_failure_keeps_exit_code_before_report_setup(self):
        self.command_errors['iputils_version'] = ns.NamespaceFailure('namespace_tool_failed', stage='iputils_version', code=4)
        report = ns.run(self.options)
        self.assertEqual((report['first_error']['stage'], report['first_error']['exit_code']), ('iputils_version', 4))
        self.assertEqual(report['cases'], [])
        self.mkdtemp.assert_not_called()
        self.assert_saved(report)

    def test_partial_cases_and_invalid_raw_child_survive_all_cleanup_errors(self):
        self.payloads['no_reply'] = SECRET.encode()
        self.codes['no_reply'] = 7
        self.children.side_effect = [[], OSError(SECRET)]
        self.command_errors['cleanup_firewall_delete'] = ns.NamespaceFailure('namespace_tool_failed', stage='cleanup_firewall_delete', code=18)
        self.remove.side_effect = PermissionError(SECRET)
        report = ns.run(self.options)
        self.assertEqual(report['current_case'], 'no_reply')
        self.assertEqual(len(report['cases']), 2)
        self.assertTrue(report['cases'][0]['ok'])
        self.assertFalse(report['cases'][1]['ok'])
        first = report['first_error']
        self.assertEqual((first['stage'], first['reason'], first['error_class'], first['exit_code']),
                         ('child_output', 'child_output_invalid', 'JSONDecodeError', 7))
        self.assertEqual([entry['stage'] for entry in report['secondary_errors']],
                         ['cleanup_children', 'cleanup_firewall_delete', 'cleanup_directory'])
        self.assertEqual(report['secondary_errors'][1]['exit_code'], 18)
        self.assertFalse(any(report['cleanup'].values()))
        self.remove.assert_called_once_with(self.root)
        self.assert_saved(report)

    def test_child_assertion_precedes_later_counter_and_cleanup_failures(self):
        self.payloads['echo'] = failed_child()
        self.codes['echo'] = 1
        self.counter.side_effect = [0, OSError(SECRET)]
        self.remove.side_effect = PermissionError(SECRET)
        report = ns.run(self.options)
        first = report['first_error']
        self.assertEqual((first['stage'], first['reason'], first['error_class'], first['exit_code']),
                         ('child_result', 'child_case_failed', 'AssertionError', 1))
        self.assertEqual(first['frames'], [{'file': 'test_entry_probe_iputils.py', 'line': 44}])
        self.assertEqual([entry['stage'] for entry in report['secondary_errors']], ['case_counter', 'cleanup_directory'])
        self.assertFalse(report['cases'][0]['ok'])
        self.assert_saved(report)

    def test_failed_setup_child_preserves_assertion_even_with_missing_summary(self):
        self.payloads['echo'] = {key: item for key, item in failed_child().items() if key not in {'expected_packets', 'measurements'}}
        self.codes['echo'] = 1
        report = ns.run(self.options)
        self.assertEqual((report['reason'], report['error_class']), ('child_case_failed', 'AssertionError'))
        self.assertEqual(report['secondary_errors'][0]['reason'], 'child_result_incomplete')
        self.assertEqual(report['cases'][0]['tests'], 1)
        self.assertFalse(report['cases'][0]['ok'])
        self.assert_saved(report)

    def test_first_failed_case_survives_a_later_missing_case(self):
        self.payloads['echo'] = failed_child()
        self.codes['echo'] = 1
        self.payloads['no_reply'] = {'case': 'no_reply', 'ok': True}
        report = ns.run(self.options)
        self.assertEqual((report['reason'], report['error_class']), ('child_case_failed', 'AssertionError'))
        self.assertEqual(report['secondary_errors'][0]['reason'], 'child_result_incomplete')
        self.assertEqual(report['current_case'], 'no_reply')
        self.assertEqual(len(report['cases']), 2)
        self.assertFalse(report['ok'])
        self.assert_saved(report)

    def test_counter_failure_after_successful_child_leaves_current_case_incomplete(self):
        self.counter.side_effect = [0, PermissionError(SECRET)]
        report = ns.run(self.options)
        self.assertFalse(report['ok'])
        self.assertFalse(report['cases'][0]['ok'])
        self.assertEqual((report['first_error']['stage'], report['error_class']), ('case_counter', 'PermissionError'))
        self.assert_saved(report)

    def test_child_cleanup_failure_does_not_replace_already_observed_assertion(self):
        self.payloads['echo'] = failed_child()
        self.codes['echo'] = 1
        self.post_child_errors['echo'] = ns.NamespaceFailure('child_cleanup_failed', stage='cleanup_child_close', error=OSError(SECRET))
        report = ns.run(self.options)
        self.assertEqual(report['reason'], 'child_case_failed')
        self.assertEqual(report['secondary_errors'][0]['stage'], 'cleanup_child_close')
        self.assert_saved(report)

    def test_partial_firewall_setup_is_still_cleaned_and_setup_error_wins(self):
        self.command_errors['firewall_setup'] = ns.NamespaceFailure('namespace_tool_failed', stage='firewall_setup', code=9)
        report = ns.run(self.options)
        self.assertEqual((report['first_error']['stage'], report['first_error']['exit_code']), ('firewall_setup', 9))
        self.assertIn('cleanup_firewall_delete', [call.kwargs['stage'] for call in self.commands.call_args_list])
        self.assertTrue(report['cleanup']['owned_firewall_removed'])
        self.remove.assert_called_once_with(self.root)
        self.assert_saved(report)

    def test_unknown_cleanup_evidence_cannot_become_success(self):
        self.command_outputs['cleanup_firewall_check'] = b'{"nftables": {}}'
        report = ns.run(self.options)
        self.assertFalse(report['ok'])
        self.assertTrue(all(row['ok'] for row in report['cases']))
        self.assertEqual((report['first_error']['stage'], report['reason']), ('cleanup_firewall_check', 'cleanup_incomplete'))
        self.assertFalse(report['cleanup']['owned_firewall_removed'])
        self.assertTrue(report['cleanup']['temporary_directory_removed'])
        self.assert_saved(report)

    def test_capability_guard_failure_is_reported_and_never_launches_children(self):
        self.capabilities.return_value = ['security.capability']
        report = ns.run(self.options)
        self.assertEqual((report['first_error']['stage'], report['reason']), ('iputils_copy', 'file_capabilities_not_allowed'))
        self.child.assert_not_called()
        self.assert_saved(report)

    def test_optional_rust_cases_keep_success_semantics_and_discard_output(self):
        binary = self.base / 'keli_entry_probe_isolated_tests-synthetic'
        binary.touch()
        self.options.rust_test = str(binary)
        self.payloads['rust_echo'] = SECRET.encode()
        self.payloads['rust_drop'] = SECRET.encode()

        def rust_counter(args, *a, **kwargs):
            if args[0] != '/usr/bin/python3':
                self.packets += 1
            return self.run_child(args, *a, **kwargs)

        self.child.side_effect = rust_counter
        with patch.object(ns.os, 'access', return_value=True):
            report = ns.run(self.options)
        self.assertTrue(report['ok'])
        self.assertEqual(len(report['cases']), 8)
        self.assertTrue(all(row['exit_success'] for row in report['cases'][-2:]))
        self.assert_saved(report)

    def test_main_prints_and_persists_partial_report_without_replacing_first_error(self):
        def escaped(options, *, report, persist, checkpoint):
            self.assertFalse(persist)
            self.assertTrue(checkpoint)
            report['cases'].append({'case': 'echo', 'ok': False})
            report['current_case'] = 'echo'
            ns.record_error(report, ns.NamespaceFailure('namespace_tool_failed', stage='rule_update', code=6), 'main')
            raise PermissionError(SECRET)

        with patch.object(ns, 'run', side_effect=escaped):
            code, report = self.main()
        self.assertEqual(code, 1)
        self.assertEqual((report['first_error']['stage'], report['first_error']['exit_code']), ('rule_update', 6))
        self.assertEqual(report['secondary_errors'][0]['error_class'], 'PermissionError')
        self.assertEqual(report['current_case'], 'echo')
        self.assert_saved(report)

    def test_first_error_is_durable_before_workspace_cleanup(self):
        self.payloads['echo'] = failed_child()
        self.codes['echo'] = 1
        original_remove = self.remove._mock_wraps
        records = []
        def inspect_before_remove(path):
            sidecar = self.base / 'report.first-error.json'
            self.assertTrue(sidecar.is_file())
            records.append(json.loads(sidecar.read_text(encoding='utf-8'))['first_error'])
            original_remove(path)
        self.remove.side_effect = inspect_before_remove
        report = ns.run(self.options)
        self.assertEqual(records, [report['first_error']])
        self.assertTrue(report['first_error_persisted'])
        self.assertEqual(report['first_error']['reason'], 'child_case_failed')

    def test_checkpoint_write_error_is_secondary_and_original_error_remains(self):
        self.topology.side_effect = ns.NamespaceFailure('namespace_tool_failed', stage='topology_links', code=5)
        with patch.object(ns.os, 'open', side_effect=PermissionError(SECRET)):
            report = ns.run(self.options)
        self.assertEqual(report['first_error']['reason'], 'namespace_tool_failed')
        self.assertEqual(report['first_error']['exit_code'], 5)
        self.assertFalse(report['first_error_persisted'])
        self.assertEqual(report['secondary_errors'][0]['reason'], 'report_write_failed')
        self.assertNotIn(SECRET, json.dumps(report))

    def test_report_write_failure_is_secondary_and_is_still_printed(self):
        self.payloads['echo'] = failed_child()
        self.codes['echo'] = 1
        with patch.object(ns.Path, 'open', side_effect=PermissionError(SECRET)):
            code, report = self.main()
        self.assertEqual(code, 1)
        self.assertEqual(report['reason'], 'child_case_failed')
        self.assertEqual(report['secondary_errors'][-1]['stage'], 'report_write')
        self.assertEqual(report['secondary_errors'][-1]['reason'], 'report_write_failed')
        self.assertEqual(report['secondary_errors'][-1]['error_class'], 'PermissionError')
        self.assertNotIn(SECRET, json.dumps(report))

    def test_preexisting_output_is_not_overwritten_or_treated_as_success(self):
        with Path(self.options.output).open('w', encoding='utf-8') as stream:
            stream.write('existing evidence')
        code, report = self.main()
        self.assertEqual(code, 1)
        self.assertEqual((report['first_error']['stage'], report['reason'], report['error_class']),
                         ('report_write', 'report_write_failed', 'FileExistsError'))
        self.assertEqual(Path(self.options.output).read_text(encoding='utf-8'), 'existing evidence')

    def test_sigterm_during_topology_is_safe_and_handlers_are_restored(self):
        handlers = {}

        def install(number, handler):
            handlers[number] = handler
            return signal.SIG_DFL

        self.topology.side_effect = lambda parent: handlers[signal.SIGTERM](signal.SIGTERM, None)
        argv = ['iputils_namespace.py', '--uid', '1000', '--gid', '1001', '--parent-netns', 'net:[1]', '--output', self.options.output]
        output = io.StringIO()
        with patch.object(ns.sys, 'argv', argv), patch.object(ns.signal, 'signal', side_effect=install), redirect_stdout(output):
            code = ns.main()
        report = json.loads(output.getvalue())
        self.assertEqual(code, 1)
        self.assertEqual((report['first_error']['stage'], report['reason'], report['first_error']['exit_code']),
                         ('signal', 'signal_received', -signal.SIGTERM))
        self.assertTrue(all(handler == signal.SIG_DFL for handler in handlers.values()))
        self.children.assert_not_called()
        self.assert_saved(report)

    def test_signal_during_cleanup_remains_secondary_to_first_case_failure(self):
        self.payloads['echo'] = failed_child()
        self.codes['echo'] = 1
        self.children.side_effect = [[], [], [], [], [], [], ns.NamespaceFailure('signal_received', stage='signal', code=-15)]
        report = ns.run(self.options)
        self.assertEqual(report['reason'], 'child_case_failed')
        self.assertEqual((report['secondary_errors'][-1]['stage'], report['secondary_errors'][-1]['exit_code']), ('signal', -15))
        self.assertTrue(report['cleanup']['temporary_directory_removed'])
        self.assert_saved(report)


if __name__ == '__main__':
    unittest.main()
