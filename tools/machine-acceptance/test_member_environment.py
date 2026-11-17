import base64
import unittest

from member_cli import RUN
from member_environment import environment, public_settings
from member_transport import TransportError


def settings():
    return dict(app_key=base64.b64encode(b'x' * 32).decode(), database='keli_acceptance_application',
                username='keli_application', password='s' * 32, mysql_port=13306,
                redis_password='r' * 32, redis_port=16379)


class EnvironmentTests(unittest.TestCase):
    def test_generated_configuration_has_only_private_services_and_closed_switches(self):
        raw = environment(settings())
        self.assertNotIn(b'\r', raw)
        lines = dict(line.split('=', 1) for line in raw.decode().splitlines())
        self.assertEqual(lines['APP_ENV'], 'acceptance')
        self.assertEqual(lines['APP_DEBUG'], 'false')
        self.assertEqual(lines['APP_URL'], 'https://control-' + RUN + '.dnscloudcloud.top')
        for key in ('DB_HOST', 'REDIS_HOST', 'REDIS_CACHE_HOST'):
            self.assertEqual(lines[key], '127.0.0.1')
        self.assertEqual(lines['CACHE_PREFIX'], RUN + ':')
        self.assertEqual(lines['MACHINE_FAILOVER_PREVIEW_ENABLED'], 'false')
        self.assertFalse(any(key in lines for key in ('DATABASE_URL', 'REDIS_URL', 'APP_BASE_PATH')))
        self.assertEqual(set(public_settings(settings())), {'database', 'username', 'mysql_port', 'redis_port'})
        self.assertNotIn('s' * 32, str(public_settings(settings())))

    def test_injected_or_ambiguous_settings_are_refused(self):
        for extra in ('host', 'APP_DEBUG', 'APP_BASE_PATH', 'allow_live'):
            with self.subTest(extra=extra), self.assertRaises(TransportError):
                environment(dict(settings(), **{extra: 'production'}))
        for key, values in {
            'database': ['keliboard', 'keli_acceptance_../app', 'keli_acceptance_x\nAPP_DEBUG=true'],
            'username': ['root', 'keli_x\nDB_HOST=outside'],
            'app_key': ['x' * 44, base64.b64encode(b'x' * 16).decode()],
            'password': ['short', 'x' * 30 + '\nAPP_DEBUG=true', 'x' * 30 + '#comment'],
            'redis_password': ['null', '${DB_PASSWORD}'],
            'mysql_port': [True, '13306', 0, 65536, 16379],
        }.items():
            for value in values:
                with self.subTest(key=key, value=value), self.assertRaises(TransportError):
                    environment(dict(settings(), **{key: value}))


if __name__ == '__main__':
    unittest.main()
