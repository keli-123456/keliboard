#!/usr/bin/env bash
set -euo pipefail

if [ ! -d ".git" ]; then
  echo "Please deploy using Git."
  exit 1
fi

if ! command -v git &> /dev/null; then
  echo "Git is not installed! Please install git and try again."
  exit 1
fi

IN_DOCKER=0
if [ -f "/.dockerenv" ]; then
  IN_DOCKER=1
fi

git config --global --add safe.directory "$(pwd)"

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

COMPOSE_CMD=()
if command -v docker &> /dev/null && docker compose version >/dev/null 2>&1; then
  COMPOSE_CMD=(docker compose)
elif command -v docker-compose &> /dev/null; then
  COMPOSE_CMD=(docker-compose)
fi

COMPOSE_FILE_ARGS=()
if [ -f "compose.yaml" ]; then
  COMPOSE_FILE_ARGS=(-f compose.yaml)
elif [ -f "compose.yml" ]; then
  COMPOSE_FILE_ARGS=(-f compose.yml)
elif [ -f "docker-compose.yaml" ]; then
  COMPOSE_FILE_ARGS=(-f docker-compose.yaml)
elif [ -f "docker-compose.yml" ]; then
  COMPOSE_FILE_ARGS=(-f docker-compose.yml)
fi

USE_COMPOSE=0
if [ ${#COMPOSE_CMD[@]} -gt 0 ] && [ ${#COMPOSE_FILE_ARGS[@]} -gt 0 ]; then
  USE_COMPOSE=1
fi

if [ "${USE_COMPOSE}" = "1" ] && [ "${IN_DOCKER}" = "0" ]; then
  mkdir -p .docker/.data/redis .docker/.data/redis-cache || true
  if [ -d ".docker/.data" ]; then
    chmod -R 777 .docker/.data || true
  fi

  if ! "${COMPOSE_CMD[@]}" "${COMPOSE_FILE_ARGS[@]}" up -d redis redis-cache; then
    "${COMPOSE_CMD[@]}" "${COMPOSE_FILE_ARGS[@]}" up -d redis || true
  fi

  for _ in {1..30}; do
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
  done
fi

rm -rf composer.lock composer.phar
if command -v wget &> /dev/null; then
  wget https://github.com/composer/composer/releases/latest/download/composer.phar -O composer.phar
else
  curl -fsSL https://github.com/composer/composer/releases/latest/download/composer.phar -o composer.phar
fi

if [ "${USE_COMPOSE}" = "1" ] && [ "${IN_DOCKER}" = "0" ]; then
  "${COMPOSE_CMD[@]}" "${COMPOSE_FILE_ARGS[@]}" run --rm -T web sh -lc "cd /www && php composer.phar update -vvv"
  "${COMPOSE_CMD[@]}" "${COMPOSE_FILE_ARGS[@]}" run --rm -T web sh -lc "cd /www && php artisan xboard:update"
else
  php composer.phar update -vvv
  php artisan xboard:update
fi

if [ -f "/etc/init.d/bt" ] || [ -f "/.dockerenv" ]; then
  chown -R www:www "$(pwd)" || true
fi

if [ -d ".docker/.data" ]; then
  chmod -R 777 .docker/.data || true
fi

if [ "${USE_COMPOSE}" = "1" ]; then
  mkdir -p .docker/.data/redis-cache || true
  "${COMPOSE_CMD[@]}" "${COMPOSE_FILE_ARGS[@]}" up -d --remove-orphans || true
  "${COMPOSE_CMD[@]}" "${COMPOSE_FILE_ARGS[@]}" exec -T web php artisan config:cache || true
  "${COMPOSE_CMD[@]}" "${COMPOSE_FILE_ARGS[@]}" exec -T horizon php artisan horizon:terminate || true
  "${COMPOSE_CMD[@]}" "${COMPOSE_FILE_ARGS[@]}" restart web horizon || true
fi

echo "xboardpro updated"
