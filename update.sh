#!/usr/bin/env sh
set -eu

if [ ! -d ".git" ]; then
  echo "Please deploy using Git."
  exit 1
fi

if ! command -v git >/dev/null 2>&1; then
  echo "Git is not installed! Please install git and try again."
  exit 1
fi

IN_DOCKER=0
if [ -f "/.dockerenv" ]; then
  IN_DOCKER=1
fi

git config --global --add safe.directory "$(pwd)" >/dev/null 2>&1 || true

git fetch --all

UPSTREAM="$(git rev-parse --abbrev-ref --symbolic-full-name '@{u}' 2>/dev/null || true)"
if [ -n "${UPSTREAM}" ]; then
  git reset --hard "${UPSTREAM}"
else
  for candidate in origin/main origin/master keliboard/main keliboard/master; do
    if git show-ref --verify --quiet "refs/remotes/${candidate}"; then
      git reset --hard "${candidate}"
      break
    fi
  done
fi

COMPOSE_BIN=""
if command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1; then
  COMPOSE_BIN="docker"
elif command -v docker-compose >/dev/null 2>&1; then
  COMPOSE_BIN="docker-compose"
fi

COMPOSE_FILE=""
for f in compose.yaml compose.yml docker-compose.yaml docker-compose.yml; do
  if [ -f "$f" ]; then
    COMPOSE_FILE="$f"
    break
  fi
done

USE_COMPOSE=0
if [ -n "$COMPOSE_BIN" ] && [ -n "$COMPOSE_FILE" ]; then
  USE_COMPOSE=1
fi

compose() {
  if [ "${USE_COMPOSE}" != "1" ]; then
    return 1
  fi

  if [ "${COMPOSE_BIN}" = "docker" ]; then
    docker compose -f "${COMPOSE_FILE}" "$@"
  else
    "${COMPOSE_BIN}" -f "${COMPOSE_FILE}" "$@"
  fi
}

if [ "${USE_COMPOSE}" = "1" ] && [ "${IN_DOCKER}" = "0" ]; then
  mkdir -p .docker/.data/redis .docker/.data/redis-cache || true
  if [ -d ".docker/.data" ]; then
    chmod -R 777 .docker/.data || true
  fi

  if ! compose up -d redis redis-cache; then
    compose up -d redis || true
  fi

  i=0
  while [ "${i}" -lt 30 ]; do
    ok=1
    if [ -d ".docker/.data/redis" ] && [ ! -S ".docker/.data/redis/redis.sock" ]; then
      ok=0
    fi
    if [ -d ".docker/.data/redis-cache" ] && [ ! -S ".docker/.data/redis-cache/redis.sock" ]; then
      ok=0
    fi
    if [ "${ok}" = "1" ]; then
      break
    fi
    sleep 0.2
    i=$((i + 1))
  done
fi

rm -rf composer.lock composer.phar
if command -v wget >/dev/null 2>&1; then
  wget https://github.com/composer/composer/releases/latest/download/composer.phar -O composer.phar
else
  curl -fsSL https://github.com/composer/composer/releases/latest/download/composer.phar -o composer.phar
fi

if [ "${USE_COMPOSE}" = "1" ] && [ "${IN_DOCKER}" = "0" ]; then
  compose run --rm -T web sh -lc "cd /www && php composer.phar update -vvv"
  compose run --rm -T web sh -lc "cd /www && php artisan xboard:update"
else
  php composer.phar update -vvv
  php artisan xboard:update
  php artisan config:clear || true
  php artisan config:cache || true
  php artisan horizon:terminate || true
fi

if [ -f "/etc/init.d/bt" ] || [ -f "/.dockerenv" ]; then
  chown -R www:www "$(pwd)" || true
fi

if [ -d ".docker/.data" ]; then
  chmod -R 777 .docker/.data || true
fi

if [ "${USE_COMPOSE}" = "1" ]; then
  mkdir -p .docker/.data/redis-cache || true
  compose up -d --remove-orphans || true
  compose exec -T web php artisan config:clear || true
  compose exec -T web php artisan config:cache || true
  compose exec -T horizon php artisan horizon:terminate || true
  compose restart web horizon || true
fi

echo "xboardpro updated"
