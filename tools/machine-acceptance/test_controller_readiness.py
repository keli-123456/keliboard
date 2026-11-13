"""Readiness predicates only; no SSH, providers, DNS or packet dispatch."""
import unittest

from controller_readiness import BOOT_CHECKS, boot_ready, pipeline_ready


class ReadinessTests(unittest.TestCase):
    def test_domestic_ping_is_not_a_control_boot_prerequisite(self):
        for diagnostic in [{}, {'root_echoes': 0}, {'domestic_ping_status': 'unreachable'},
                           {'domestic_ping_status': 'unknown'}, {'root_echoes': 4}]:
            with self.subTest(diagnostic=diagnostic):
                self.assertTrue(boot_ready({'ok': True, **dict.fromkeys(BOOT_CHECKS, True), **diagnostic}))

    def test_each_control_plane_safety_check_is_still_mandatory(self):
        ready = {'ok': True, **dict.fromkeys(BOOT_CHECKS, True), 'root_echoes': 4}
        for key in ['ok', *BOOT_CHECKS]:
            for value in [None, False, 1, 'true']:
                with self.subTest(key=key, value=value):
                    self.assertFalse(boot_ready(dict(ready, **{key: value})))
            self.assertFalse(boot_ready({name: value for name, value in ready.items() if name != key}))

    def test_ping_cannot_substitute_for_a_verified_control_pipeline(self):
        self.assertTrue(pipeline_ready({'ok': True, 'control_ready': True, 'root_echoes': 0}))
        for value in [None, {}, {'ok': True, 'root_echoes': 4}, {'ok': False, 'control_ready': True},
                      {'ok': True, 'control_ready': 1}, {'ok': True, 'control_ready': False}]:
            with self.subTest(value=value):
                self.assertFalse(pipeline_ready(value))
                if not isinstance(value, dict):
                    self.assertFalse(boot_ready(value))


if __name__ == '__main__':
    unittest.main()
