#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
IMAGE="${PHP82_TEST_IMAGE:-ghcr.io/keli-123456/keliboard:main}"
CACHE_DIR="${COMPOSER_CACHE_DIR_HOST:-${ROOT_DIR}/.composer-cache}"

mkdir -p "${CACHE_DIR}"

TTY_ARGS=()
if [ -t 1 ]; then
  TTY_ARGS=(-t)
fi

docker run --rm "${TTY_ARGS[@]}" \
  -u "$(id -u):$(id -g)" \
  -v "${ROOT_DIR}:/www" \
  -v "${CACHE_DIR}:/tmp/composer-cache" \
  -w /www \
  -e APP_ENV=testing \
  -e COMPOSER_CACHE_DIR=/tmp/composer-cache \
  -e XDG_CACHE_HOME=/tmp \
  "${IMAGE}" \
  sh -lc '
    php -v
    if [ ! -x vendor/bin/phpunit ]; then
      composer install --no-interaction --prefer-dist
    fi
    if [ "$#" -gt 0 ]; then
      exec "$@"
    fi
    exec composer test
  ' sh "$@"
