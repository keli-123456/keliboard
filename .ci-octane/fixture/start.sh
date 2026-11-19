#!/bin/sh
set -eu

test "${KELI_OCTANE_FIXTURE:-}" = isolated-ci
test ! -e /www/.env
test "${1:-}" = octane:start

if [ "${APP_CONFIG_CACHE:-}" = /www/bootstrap/cache/config.php ]; then
    # Never clear or cache configuration in the shared source mount.
    awk '$5 == "/www/bootstrap/cache" && $0 ~ / - tmpfs / {found=1} END {exit !found}' /proc/self/mountinfo
    test ! -f /www/bootstrap/cache/shared-anchor
    php /fixture/artisan config:cache --no-interaction
    test -s /www/bootstrap/cache/config.php
fi

exec php /fixture/artisan "$@"
