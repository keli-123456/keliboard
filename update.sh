#!/usr/bin/env sh
set -eu

ROOT="${KELI_UPDATE_ROOT:-$(git rev-parse --show-toplevel 2>/dev/null || pwd)}"
cd "$ROOT"

if [ "${1:-}" = "--safe" ]; then
  shift
  exec sh "$ROOT/scripts/deploy-release.sh" "$@"
fi
if [ "$#" -gt 0 ]; then
  exec sh "$ROOT/scripts/deploy-release.sh" "$@"
fi

[ -d .git ] || { echo "ERROR: update must run from a Git checkout" >&2; exit 1; }
command -v git >/dev/null 2>&1 || { echo "ERROR: Git is not installed" >&2; exit 1; }

read_app_key() {
  [ -f .env ] || return 0
  sed -n 's/^APP_KEY=//p' .env | sed -n '1p'
}

APP_KEY_BEFORE="$(read_app_key)"
assert_app_key_unchanged() {
  current="$(read_app_key)"
  if [ -n "$APP_KEY_BEFORE" ] && [ "$current" != "$APP_KEY_BEFORE" ]; then
    echo "ERROR: APP_KEY changed during update; restore the previous APP_KEY before starting services" >&2
    exit 1
  fi
}

git config --global --add safe.directory "$ROOT" >/dev/null 2>&1 || true
tracked_changes="$(git status --porcelain --untracked-files=no)"
[ -z "$tracked_changes" ] || {
  echo "ERROR: tracked source changes must be committed or backed up before update" >&2
  printf '%s\n' "$tracked_changes" >&2
  exit 1
}

OLD_HEAD="$(git rev-parse HEAD)"
git fetch --all --tags
UPSTREAM="$(git rev-parse --abbrev-ref --symbolic-full-name '@{u}' 2>/dev/null || true)"
if [ -z "$UPSTREAM" ]; then
  for candidate in origin/main origin/master keliboard/main keliboard/master; do
    if git show-ref --verify --quiet "refs/remotes/$candidate"; then
      UPSTREAM="$candidate"
      break
    fi
  done
fi
[ -n "$UPSTREAM" ] || { echo "ERROR: no update branch was found" >&2; exit 1; }

TARGET_SHA="$(git rev-parse "$UPSTREAM^{commit}")"
git reset --hard "$TARGET_SHA"
assert_app_key_unchanged

if [ "${KELI_UPDATE_REEXEC:-0}" != 1 ] && [ "$OLD_HEAD" != "$TARGET_SHA" ]; then
  KELI_UPDATE_REEXEC=1 exec sh "$ROOT/update.sh"
fi

COMPOSE_KIND=""
COMPOSE_FILE=""
if command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1; then
  COMPOSE_KIND="plugin"
elif command -v docker-compose >/dev/null 2>&1; then
  COMPOSE_KIND="standalone"
fi
for candidate in compose.yaml compose.yml docker-compose.yaml docker-compose.yml; do
  if [ -f "$candidate" ]; then
    COMPOSE_FILE="$candidate"
    break
  fi
done

compose() {
  if [ "$COMPOSE_KIND" = plugin ]; then
    docker compose -f "$COMPOSE_FILE" "$@"
  else
    docker-compose -f "$COMPOSE_FILE" "$@"
  fi
}

if [ -n "$COMPOSE_KIND" ] && [ -n "$COMPOSE_FILE" ] && [ ! -f /.dockerenv ]; then
  mkdir -p .docker/.data/redis .docker/.data/redis-cache
  chmod -R 777 .docker/.data || true

  compose pull
  compose up -d redis redis-cache
  compose run --rm --no-deps -T web composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
  compose run --rm --no-deps -T web php artisan xboard:update

  rm -f storage/app/releases/active-compose.override.yaml
  compose up -d --remove-orphans
  compose exec -T web php artisan optimize:clear || true
  compose exec -T web php artisan config:cache || true
  compose exec -T horizon php artisan horizon:terminate || true

  for service in web horizon ws-server; do
    container="$(compose ps -q "$service")"
    [ -n "$container" ] || { echo "ERROR: service has no container: $service" >&2; exit 1; }
    [ "$(docker inspect --format '{{.State.Running}}' "$container")" = true ] || {
      echo "ERROR: service is not running: $service" >&2
      exit 1
    }
  done
else
  command -v composer >/dev/null 2>&1 || { echo "ERROR: Composer is not installed" >&2; exit 1; }
  composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
  php artisan xboard:update
  php artisan optimize:clear || true
  php artisan config:cache || true
  php artisan horizon:terminate || true
fi

assert_app_key_unchanged
echo "Keli update succeeded: $(git rev-parse --short HEAD)"
