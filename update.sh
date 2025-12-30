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

rm -rf composer.lock composer.phar
if command -v wget &> /dev/null; then
  wget https://github.com/composer/composer/releases/latest/download/composer.phar -O composer.phar
else
  curl -fsSL https://github.com/composer/composer/releases/latest/download/composer.phar -o composer.phar
fi

php composer.phar update -vvv
php artisan xboard:update

if [ -f "/etc/init.d/bt" ] || [ -f "/.dockerenv" ]; then
  chown -R www:www "$(pwd)" || true
fi

if [ -d ".docker/.data" ]; then
  chmod -R 777 .docker/.data || true
fi

# Docker compose restart (optional, for docker deployments).
COMPOSE=""
if command -v docker &> /dev/null && docker compose version >/dev/null 2>&1; then
  COMPOSE="docker compose"
elif command -v docker-compose &> /dev/null; then
  COMPOSE="docker-compose"
fi

if [ -n "${COMPOSE}" ]; then
  mkdir -p .docker/.data/redis-cache || true

  COMPOSE_FILE_ARGS=""
  if [ -f "compose.yaml" ]; then
    COMPOSE_FILE_ARGS="-f compose.yaml"
  elif [ -f "compose.yml" ]; then
    COMPOSE_FILE_ARGS="-f compose.yml"
  elif [ -f "docker-compose.yaml" ]; then
    COMPOSE_FILE_ARGS="-f docker-compose.yaml"
  elif [ -f "docker-compose.yml" ]; then
    COMPOSE_FILE_ARGS="-f docker-compose.yml"
  fi

  ${COMPOSE} ${COMPOSE_FILE_ARGS} up -d --remove-orphans || true
  ${COMPOSE} ${COMPOSE_FILE_ARGS} exec -T web php artisan config:cache || true
  ${COMPOSE} ${COMPOSE_FILE_ARGS} exec -T horizon php artisan horizon:terminate || true
  ${COMPOSE} ${COMPOSE_FILE_ARGS} restart web horizon || true
fi

echo "xboardpro updated"
