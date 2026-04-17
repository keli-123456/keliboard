#!/usr/bin/env bash
set -euo pipefail

if ! command -v php >/dev/null 2>&1; then
  echo "ERROR: php is required" >&2
  exit 1
fi

if ! command -v composer >/dev/null 2>&1; then
  echo "ERROR: composer is required" >&2
  exit 1
fi

php_version="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION.".".PHP_RELEASE_VERSION;')"
if [[ ! "${php_version}" =~ ^8\.2\. ]]; then
  echo "ERROR: lock file updates must run on PHP 8.2.x, current PHP is ${php_version}" >&2
  exit 1
fi

for arg in "$@"; do
  if [[ "${arg}" == "--ignore-platform-reqs" ]] || [[ "${arg}" == --ignore-platform-req=* ]]; then
    echo "ERROR: --ignore-platform-reqs is forbidden for lock file updates" >&2
    exit 1
  fi
done

composer update --no-interaction --no-progress --prefer-dist "$@"
php scripts/verify-composer-platform.php
composer check-platform-reqs --lock

echo "OK: composer.lock refreshed under PHP ${php_version}"

