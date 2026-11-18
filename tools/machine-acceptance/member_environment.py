"""Generate only the isolated controller environment, never a production .env."""
import base64
import re

from member_cli import RUN
from member_transport import require


FIELDS = {'app_key', 'database', 'username', 'password', 'mysql_port', 'redis_password', 'redis_port'}


def environment(settings):
    require(isinstance(settings, dict) and set(settings) == FIELDS, 'transport_application_settings_invalid')
    require(isinstance(settings['database'], str) and isinstance(settings['username'], str)
            and re.fullmatch(r'keli_acceptance_[a-z0-9_]{3,60}', settings['database'])
            and re.fullmatch(r'keli_[a-z0-9_]{3,25}', settings['username']),
            'transport_application_database_invalid')
    for name in ('password', 'redis_password'):
        require(isinstance(settings[name], str) and re.fullmatch(r'[A-Za-z0-9_-]{24,128}', settings[name]),
                'transport_application_secret_invalid')
    key = settings['app_key']
    require(isinstance(key, str) and re.fullmatch(r'[A-Za-z0-9+/]{43}=', key),
            'transport_application_key_invalid')
    require(len(base64.b64decode(key, validate=True)) == 32, 'transport_application_key_invalid')
    for name in ('mysql_port', 'redis_port'):
        require(type(settings[name]) is int and 1024 <= settings[name] <= 65535,
                'transport_application_port_invalid')
    require(settings['mysql_port'] != settings['redis_port'], 'transport_application_port_invalid')
    values = {
        'APP_NAME': 'KeliIsolatedAcceptance', 'APP_ENV': 'acceptance', 'APP_DEBUG': 'false',
        'APP_KEY': 'base64:' + key, 'APP_URL': 'https://control-' + RUN + '.dnscloudcloud.top',
        'DB_CONNECTION': 'mysql', 'DB_HOST': '127.0.0.1', 'DB_PORT': str(settings['mysql_port']),
        'DB_DATABASE': settings['database'], 'DB_USERNAME': settings['username'], 'DB_PASSWORD': settings['password'],
        'REDIS_HOST': '127.0.0.1', 'REDIS_PORT': str(settings['redis_port']), 'REDIS_PASSWORD': settings['redis_password'],
        'REDIS_CACHE_HOST': '127.0.0.1', 'REDIS_CACHE_PORT': str(settings['redis_port']),
        'REDIS_CACHE_PASSWORD': settings['redis_password'], 'REDIS_DB': '0', 'REDIS_CACHE_DB': '1',
        'CACHE_DRIVER': 'redis', 'CACHE_REDIS_CONNECTION': 'cache', 'CACHE_PREFIX': RUN + ':',
        'REDIS_PREFIX': RUN + ':', 'QUEUE_CONNECTION': 'redis', 'REDIS_QUEUE': 'r35_callbacks',
        'MAIL_MAILER': 'log', 'MAIL_DRIVER': 'log', 'BROADCAST_DRIVER': 'log',
        'INSTALLED': 'true', 'ENABLE_AUTO_BACKUP_AND_UPDATE': 'false',
        'MACHINE_FAILOVER_WORKER_ENABLED': 'false', 'MACHINE_FAILOVER_PREVIEW_ENABLED': 'false',
        'USER_SYNC_USE_STATE_TABLE_FOR_SERVER_USERS': 'false', 'SERVER_API_MEMORY_LIMIT': '256M',
        'LOG_CHANNEL': 'single', 'XBOARD_TRUSTED_PROXIES': '127.0.0.1,::1', 'KELI_ACCEPTANCE_OWNER': RUN,
    }
    return ''.join(name + '=' + value + '\n' for name, value in values.items()).encode('ascii')


def public_settings(settings):
    environment(settings)
    return {name: settings[name] for name in ('database', 'username', 'mysql_port', 'redis_port')}
