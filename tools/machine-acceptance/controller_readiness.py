"""Control-plane readiness for fresh drivers, independent of mainland ICMP health."""

BOOT_CHECKS = ('owner_matches', 'bootstrap_ready', 'cloud_init_done', 'php_ready', 'docker_ready',
               'firewall_ready', 'ssh_key_only', 'public_http_80_closed')


def boot_ready(value):
    return (isinstance(value, dict) and value.get('ok') is True
            and all(value.get(key) is True for key in BOOT_CHECKS))


def pipeline_ready(value):
    return isinstance(value, dict) and value.get('ok') is True and value.get('control_ready') is True
