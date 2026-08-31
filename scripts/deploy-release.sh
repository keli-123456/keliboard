#!/usr/bin/env sh
set -eu

ROOT="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
cd "$ROOT"

PLAN_ONLY=0
NO_FETCH=0
RELEASE_REF="${KELI_RELEASE_REF:-}"
RELEASE_MANIFEST="${KELI_RELEASE_MANIFEST:-}"
TARGET_IMAGE="${KELIBOARD_IMAGE:-}"
HEALTH_URL="${KELI_HEALTH_URL:-http://127.0.0.1:7001}"
HEALTH_HOST="${KELI_HEALTH_HOST:-}"
CANARY_PORT="${KELI_CANARY_PORT:-17001}"
STATE_ROOT="${KELI_RELEASE_STATE_DIR:-$ROOT/storage/app/releases}"
COMPOSE_FILE="${KELI_COMPOSE_FILE:-}"
COMPOSE_KIND=""
DEPLOYMENT_ID=""
DEPLOYMENT_DIR=""
JOURNAL=""
LOCK_DIR="$STATE_ROOT/deploy.lock"
ACTIVE_OVERRIDE="$STATE_ROOT/active-compose.override.yaml"
CANARY_OVERRIDE=""
CANARY_PROJECT=""
CANARY_WORKTREE=""
CANARY_STARTED=0
ROLLBACK_NEEDED=0
FINISHED=0
PREVIOUS_GIT_SHA=""
TARGET_GIT_SHA=""
PREVIOUS_IMAGE=""
PREVIOUS_IMAGE_REF=""
TARGET_IMAGE_ID=""
RELEASE_VERSION="legacy"
MANIFEST_SHA=""

usage() {
  cat <<'EOF'
Usage: sh scripts/deploy-release.sh [options]
  --release-manifest PATH  Bind deployment to a verified full-stack manifest.
  --ref REF                Git commit, tag, or remote branch to deploy.
  --image IMAGE            Container image tag or digest to deploy.
  --health-url URL         Local panel URL checked after cutover.
  --health-host HOST       Host header for tenant-aware checks.
  --canary-port PORT       Isolated candidate port (default 17001).
  --compose-file PATH      Compose file to use.
  --plan                   Print the transaction without changing anything.
  --no-fetch               Resolve only already available Git refs.

No arguments preserves the `sh update.sh` entry and resolves its upstream, while
still requiring backup, canary, health, and rollback gates.
EOF
}

while [ "$#" -gt 0 ]; do
  case "$1" in
    --release-manifest) RELEASE_MANIFEST="${2:-}"; shift 2 ;;
    --release-manifest=*) RELEASE_MANIFEST="${1#*=}"; shift ;;
    --ref) RELEASE_REF="${2:-}"; shift 2 ;;
    --ref=*) RELEASE_REF="${1#*=}"; shift ;;
    --image) TARGET_IMAGE="${2:-}"; shift 2 ;;
    --image=*) TARGET_IMAGE="${1#*=}"; shift ;;
    --health-url) HEALTH_URL="${2:-}"; shift 2 ;;
    --health-url=*) HEALTH_URL="${1#*=}"; shift ;;
    --health-host) HEALTH_HOST="${2:-}"; shift 2 ;;
    --health-host=*) HEALTH_HOST="${1#*=}"; shift ;;
    --canary-port) CANARY_PORT="${2:-}"; shift 2 ;;
    --canary-port=*) CANARY_PORT="${1#*=}"; shift ;;
    --compose-file) COMPOSE_FILE="${2:-}"; shift 2 ;;
    --compose-file=*) COMPOSE_FILE="${1#*=}"; shift ;;
    --plan) PLAN_ONLY=1; shift ;;
    --no-fetch) NO_FETCH=1; shift ;;
    --help|-h) usage; exit 0 ;;
    *) echo "ERROR: unknown option: $1" >&2; usage >&2; exit 2 ;;
  esac
done

require_command() {
  command -v "$1" >/dev/null 2>&1 || {
    echo "ERROR: required command is not installed: $1" >&2
    exit 1
  }
}

timestamp() { date -u '+%Y-%m-%dT%H:%M:%SZ'; }
json_escape() { printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g; s/[[:cntrl:]]/ /g'; }

sha256_file() {
  if command -v sha256sum >/dev/null 2>&1; then
    sha256sum "$1" | awk '{print $1}'
  elif command -v shasum >/dev/null 2>&1; then
    shasum -a 256 "$1" | awk '{print $1}'
  else
    echo "ERROR: sha256sum or shasum is required" >&2
    exit 1
  fi
}

append_event() {
  printf '{"deployment_id":"%s","event":"%s","status":"%s","evidence":"%s","reason":"%s","at":"%s"}\n' \
    "$(json_escape "$DEPLOYMENT_ID")" "$(json_escape "$1")" "$(json_escape "$2")" \
    "$(json_escape "${3:-}")" "$(json_escape "${4:-}")" "$(timestamp)" >> "$JOURNAL"
}

finish_journal() {
  [ "$FINISHED" = "0" ] && [ -n "$JOURNAL" ] && [ -f "$JOURNAL" ] || return 0
  printf '{"deployment_id":"%s","event":"deployment_finished","status":"%s","reason":"%s","at":"%s"}\n' \
    "$(json_escape "$DEPLOYMENT_ID")" "$(json_escape "$1")" "$(json_escape "${2:-}")" "$(timestamp)" >> "$JOURNAL"
  FINISHED=1
}

find_compose_file() {
  if [ -n "$COMPOSE_FILE" ]; then
    [ -f "$COMPOSE_FILE" ] || { echo "ERROR: compose file not found: $COMPOSE_FILE" >&2; exit 1; }
    return
  fi
  for candidate in compose.yaml compose.yml docker-compose.yaml docker-compose.yml; do
    if [ -f "$candidate" ]; then COMPOSE_FILE="$candidate"; return; fi
  done
  echo "ERROR: compose file not found" >&2
  exit 1
}

detect_compose() {
  if docker compose version >/dev/null 2>&1; then
    COMPOSE_KIND=plugin
  elif command -v docker-compose >/dev/null 2>&1; then
    COMPOSE_KIND=standalone
  else
    echo "ERROR: Docker Compose is required" >&2
    exit 1
  fi
}

compose_base() {
  if [ "$COMPOSE_KIND" = plugin ]; then docker compose -f "$COMPOSE_FILE" "$@"; else docker-compose -f "$COMPOSE_FILE" "$@"; fi
}

compose_current() {
  if [ -f "$ACTIVE_OVERRIDE" ]; then
    if [ "$COMPOSE_KIND" = plugin ]; then
      docker compose -f "$COMPOSE_FILE" -f "$ACTIVE_OVERRIDE" "$@"
    else
      docker-compose -f "$COMPOSE_FILE" -f "$ACTIVE_OVERRIDE" "$@"
    fi
  else
    compose_base "$@"
  fi
}

compose_canary() {
  if [ "$COMPOSE_KIND" = plugin ]; then
    docker compose -p "$CANARY_PROJECT" -f "$COMPOSE_FILE" -f "$CANARY_OVERRIDE" "$@"
  else
    docker-compose -p "$CANARY_PROJECT" -f "$COMPOSE_FILE" -f "$CANARY_OVERRIDE" "$@"
  fi
}

write_active_override() {
  temporary="$ACTIVE_OVERRIDE.tmp"
  cat > "$temporary" <<EOF
services:
  web:
    image: "$1"
  horizon:
    image: "$1"
  ws-server:
    image: "$1"
EOF
  mv "$temporary" "$ACTIVE_OVERRIDE"
}

write_canary_override() {
  case "$CANARY_WORKTREE" in *"'"*) echo "ERROR: canary path cannot contain a single quote" >&2; exit 1 ;; esac
  cat > "$CANARY_OVERRIDE" <<EOF
services:
  web:
    image: "$TARGET_IMAGE_ID"
    network_mode: host
    volumes:
      - '$CANARY_WORKTREE:/www'
      - '$ROOT/.docker/.data/redis:/data'
      - '$ROOT/.docker/.data/redis-cache:/data-cache'
      - '$ROOT/theme:/www/theme:ro'
      - '$ROOT/storage/theme:/www/storage/theme:ro'
    command: php artisan octane:start --port=$CANARY_PORT --host=127.0.0.1
    healthcheck:
      test: ["CMD-SHELL", "php -r 'exit(@file_get_contents(\"http://127.0.0.1:$CANARY_PORT/api/v1/guest/comm/config\") === false);'"]
      interval: 5s
      timeout: 3s
      retries: 6
      start_period: 5s
    restart: "no"
EOF
}

manifest_value() {
  sed -n "s/^[[:space:]]*\"$1\"[[:space:]]*:[[:space:]]*\"\([^\"]*\)\".*/\1/p" "$RELEASE_MANIFEST" | sed -n '1p'
}

manifest_board_sha() {
  awk '/"keliboard"[[:space:]]*:/ { found=1; next } found && /"git_sha"[[:space:]]*:/ { line=$0; sub(/^.*"git_sha"[[:space:]]*:[[:space:]]*"/, "", line); sub(/".*$/, "", line); print line; exit }' "$RELEASE_MANIFEST"
}

resolve_release() {
  PREVIOUS_GIT_SHA="$(git rev-parse HEAD)"
  if [ -z "$RELEASE_REF" ]; then RELEASE_REF="$(git rev-parse --abbrev-ref --symbolic-full-name '@{u}' 2>/dev/null || true)"; fi
  if [ -z "$RELEASE_REF" ]; then
    for candidate in origin/main origin/master keliboard/main keliboard/master; do
      if git show-ref --verify --quiet "refs/remotes/$candidate"; then RELEASE_REF="$candidate"; break; fi
    done
  fi
  [ -n "$RELEASE_REF" ] || { echo "ERROR: no deployment ref or upstream was found" >&2; exit 1; }
  TARGET_GIT_SHA="$(git rev-parse "$RELEASE_REF^{commit}")"

  if [ -n "$RELEASE_MANIFEST" ]; then
    [ -f "$RELEASE_MANIFEST" ] || { echo "ERROR: release manifest not found: $RELEASE_MANIFEST" >&2; exit 1; }
    MANIFEST_SHA="$(sha256_file "$RELEASE_MANIFEST")"
    RELEASE_VERSION="$(manifest_value release_version)"
    [ "$(manifest_board_sha)" = "$TARGET_GIT_SHA" ] || { echo "ERROR: manifest keliboard SHA does not match deployment ref" >&2; exit 1; }
    grep -Eq '"strict"[[:space:]]*:[[:space:]]*true' "$RELEASE_MANIFEST" || { echo "ERROR: deployment requires a strict release manifest" >&2; exit 1; }
  fi
}

print_plan() {
  cat <<EOF
Keli safe deployment plan
  root: $ROOT
  previous Git SHA: $PREVIOUS_GIT_SHA
  target ref: $RELEASE_REF
  target Git SHA: $TARGET_GIT_SHA
  image: ${TARGET_IMAGE:-current compose image}
  release manifest: ${RELEASE_MANIFEST:-legacy compatibility mode}
  canary: http://127.0.0.1:$CANARY_PORT
  post-deploy health: $HEALTH_URL

Gates: clean tracked source -> immutable candidate -> verified database backup ->
isolated canary -> locked Composer install -> cutover -> HTTP smoke -> automatic code/image rollback.
EOF
}

cleanup_canary() {
  if [ "$CANARY_STARTED" = 1 ] && [ -f "$CANARY_OVERRIDE" ]; then compose_canary down --remove-orphans >/dev/null 2>&1 || true; fi
  CANARY_STARTED=0
  if [ -n "$CANARY_WORKTREE" ] && [ -d "$CANARY_WORKTREE" ]; then
    case "$CANARY_WORKTREE" in "$STATE_ROOT"/candidates/*) git worktree remove --force "$CANARY_WORKTREE" >/dev/null 2>&1 || true ;; esac
  fi
}

run_smoke() {
  mode="$1"; service_url="$2"
  if [ "$mode" = canary ]; then
    compose_canary exec -T web php scripts/release-smoke.php --base-url="$service_url" --host="$HEALTH_HOST" --attempts=20 --interval-ms=1000
  else
    smoke_script="/www/${DEPLOYMENT_DIR#"$ROOT"/}/release-smoke.php"
    compose_current exec -T web php "$smoke_script" --base-url="$service_url" --host="$HEALTH_HOST" --root=/www --attempts=20 --interval-ms=1000
  fi
}

verify_services_running() {
  for service in web horizon ws-server redis redis-cache; do
    container="$(compose_current ps -q "$service")"
    [ -n "$container" ] || { echo "ERROR: service has no container: $service" >&2; return 1; }
    [ "$(docker inspect --format '{{.State.Running}}' "$container")" = true ] || {
      echo "ERROR: service is not running: $service" >&2
      return 1
    }
  done
}

perform_rollback() {
  append_event rollback running previous_release_restore
  compose_current stop web horizon ws-server >/dev/null 2>&1 || true
  git reset --hard "$PREVIOUS_GIT_SHA" >/dev/null
  write_active_override "$PREVIOUS_IMAGE"
  if ! compose_current run --rm --no-deps -T web composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader > "$DEPLOYMENT_DIR/rollback-composer.log" 2>&1; then
    append_event rollback failed composer_install_failed; finish_journal rollback_failed composer_install_failed; return 1
  fi
  if ! compose_current up -d --remove-orphans > "$DEPLOYMENT_DIR/rollback-compose.log" 2>&1; then
    append_event rollback failed services_failed; finish_journal rollback_failed services_failed; return 1
  fi
  if ! verify_services_running > "$DEPLOYMENT_DIR/rollback-services.log" 2>&1 || \
      ! run_smoke current "$HEALTH_URL" > "$DEPLOYMENT_DIR/rollback-smoke.json" 2>&1; then
    append_event rollback failed health_failed; finish_journal rollback_failed health_failed; return 1
  fi
  append_event rollback passed rollback-smoke.json
  finish_journal rolled_back deployment_gate_failed
}

on_exit() {
  code=$?
  trap - EXIT INT TERM
  cleanup_canary
  if [ "$code" -ne 0 ] && [ "$ROLLBACK_NEEDED" = 1 ]; then perform_rollback || true
  elif [ "$code" -ne 0 ]; then finish_journal failed pre_cutover_gate_failed
  fi
  rmdir "$LOCK_DIR" >/dev/null 2>&1 || true
  exit "$code"
}

require_command git
require_command sed
require_command awk
[ -d .git ] || { echo "ERROR: deployment must run from a Git checkout" >&2; exit 1; }

if [ "$NO_FETCH" != 1 ] && [ "$PLAN_ONLY" != 1 ]; then git fetch --all --tags; fi
resolve_release
if [ "$PLAN_ONLY" = 1 ]; then print_plan; exit 0; fi

require_command docker
find_compose_file
detect_compose

tracked_changes="$(git status --porcelain --untracked-files=no)"
[ -z "$tracked_changes" ] || { echo "ERROR: tracked source changes must be committed or backed up before deployment" >&2; printf '%s\n' "$tracked_changes" >&2; exit 1; }

mkdir -p "$STATE_ROOT/deployments" "$STATE_ROOT/candidates"
mkdir "$LOCK_DIR" 2>/dev/null || { echo "ERROR: another deployment is already running: $LOCK_DIR" >&2; exit 1; }

DEPLOYMENT_ID="$(date -u '+%Y%m%d-%H%M%S')-$(printf '%s' "$TARGET_GIT_SHA" | cut -c1-7)"
DEPLOYMENT_DIR="$STATE_ROOT/deployments/$DEPLOYMENT_ID"
mkdir -p "$DEPLOYMENT_DIR"
JOURNAL="$DEPLOYMENT_DIR/deployment-receipt.jsonl"
CANARY_OVERRIDE="$DEPLOYMENT_DIR/canary-compose.override.yaml"
CANARY_PROJECT="keli-canary-$(printf '%s' "$TARGET_GIT_SHA" | cut -c1-7)"
CANARY_WORKTREE="$STATE_ROOT/candidates/$DEPLOYMENT_ID"
cp scripts/release-smoke.php "$DEPLOYMENT_DIR/release-smoke.php"
trap on_exit EXIT INT TERM

current_container="$(compose_current ps -q web 2>/dev/null || true)"
if [ -n "$current_container" ]; then
  PREVIOUS_IMAGE="$(docker inspect --format '{{.Image}}' "$current_container")"
  PREVIOUS_IMAGE_REF="$(docker inspect --format '{{.Config.Image}}' "$current_container")"
fi
if [ -z "$PREVIOUS_IMAGE" ]; then
  PREVIOUS_IMAGE_REF="$(compose_current config --images | sed -n '1p')"
  [ -n "$PREVIOUS_IMAGE_REF" ] || { echo "ERROR: unable to resolve current web image" >&2; exit 1; }
  docker pull "$PREVIOUS_IMAGE_REF" >/dev/null
  PREVIOUS_IMAGE="$(docker image inspect --format '{{.Id}}' "$PREVIOUS_IMAGE_REF")"
fi
if [ -z "$TARGET_IMAGE" ]; then TARGET_IMAGE="$PREVIOUS_IMAGE_REF"; fi
[ -n "$TARGET_IMAGE" ] || { echo "ERROR: target image is required" >&2; exit 1; }
case "$TARGET_IMAGE" in
  sha256:*) docker image inspect "$TARGET_IMAGE" > "$DEPLOYMENT_DIR/image-pull.log" ;;
  *) docker pull "$TARGET_IMAGE" > "$DEPLOYMENT_DIR/image-pull.log" ;;
esac
TARGET_IMAGE_ID="$(docker image inspect --format '{{.Id}}' "$TARGET_IMAGE")"

printf '{"schema_version":1,"deployment_id":"%s","event":"deployment_started","status":"running","release_version":"%s","release_manifest_sha256":"%s","previous_git_sha":"%s","target_git_sha":"%s","previous_image":"%s","target_image":"%s","at":"%s"}\n' \
  "$(json_escape "$DEPLOYMENT_ID")" "$(json_escape "$RELEASE_VERSION")" "$(json_escape "$MANIFEST_SHA")" "$PREVIOUS_GIT_SHA" "$TARGET_GIT_SHA" \
  "$(json_escape "$PREVIOUS_IMAGE")" "$(json_escape "$TARGET_IMAGE_ID")" "$(timestamp)" > "$JOURNAL"
append_event preflight passed target_and_image_resolved

git worktree add --detach "$CANARY_WORKTREE" "$TARGET_GIT_SHA" > "$DEPLOYMENT_DIR/worktree.log"
[ -f .env ] || { echo "ERROR: .env is required for canary validation" >&2; exit 1; }
cp -p .env "$CANARY_WORKTREE/.env"
mkdir -p "$CANARY_WORKTREE/storage/framework/cache" "$CANARY_WORKTREE/storage/framework/sessions" "$CANARY_WORKTREE/storage/framework/views" "$CANARY_WORKTREE/storage/logs"
write_canary_override
compose_canary run --rm --no-deps -T web composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader > "$DEPLOYMENT_DIR/canary-composer.log" 2>&1
compose_canary up -d --no-deps web > "$DEPLOYMENT_DIR/canary-compose.log" 2>&1
CANARY_STARTED=1
run_smoke canary "http://127.0.0.1:$CANARY_PORT" > "$DEPLOYMENT_DIR/canary-smoke.json" 2>&1
append_event canary_http passed canary-smoke.json

BACKUP_JSON="$(compose_current exec -T web php artisan backup:database --sync --trigger=manual --json)"
printf '%s\n' "$BACKUP_JSON" > "$DEPLOYMENT_DIR/backup.json"
BACKUP_ID="$(printf '%s\n' "$BACKUP_JSON" | sed -n 's/.*"record_id":[[:space:]]*\([0-9][0-9]*\).*/\1/p' | sed -n '1p')"
printf '%s' "$BACKUP_ID" | grep -Eq '^[1-9][0-9]*$' || {
  echo "ERROR: database backup did not return a valid record id" >&2
  exit 1
}
compose_current exec -T web php artisan backup:restore-drill --id="$BACKUP_ID" --record --environment=production_rehearsal --json > "$DEPLOYMENT_DIR/backup-drill.json" 2>&1
append_event backup_verified passed "backup-drill.json#record=$BACKUP_ID"

cleanup_canary
ROLLBACK_NEEDED=1
compose_current stop web horizon ws-server > "$DEPLOYMENT_DIR/cutover-stop.log" 2>&1
git reset --hard "$TARGET_GIT_SHA" > "$DEPLOYMENT_DIR/cutover-git.log"
write_active_override "$TARGET_IMAGE_ID"
compose_current run --rm --no-deps -T web composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader > "$DEPLOYMENT_DIR/cutover-composer.log" 2>&1
compose_current run --rm --no-deps -T web php artisan xboard:update > "$DEPLOYMENT_DIR/cutover-update.log" 2>&1
compose_current up -d --remove-orphans > "$DEPLOYMENT_DIR/cutover-compose.log" 2>&1
verify_services_running > "$DEPLOYMENT_DIR/cutover-services.log" 2>&1
append_event cutover passed services_started

run_smoke current "$HEALTH_URL" > "$DEPLOYMENT_DIR/post-deploy-smoke.json" 2>&1
append_event post_deploy_http passed post-deploy-smoke.json

ROLLBACK_NEEDED=0
finish_journal succeeded
echo "Keli deployment succeeded: $DEPLOYMENT_ID"
echo "Receipt: $JOURNAL"
