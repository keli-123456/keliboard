#!/usr/bin/env bash
set -Eeuo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
helper="$root/resources/server-machine/apt-dependencies.sh"

if [ "${1:-}" = "--case" ]; then
  scenario="$2"
  case_dir="$3"
  export TMPDIR="$case_dir"
  export CALL_LOG="$case_dir/calls"
  export SCENARIO="$scenario"
  : > "$CALL_LOG"
  printf 'synthetic-keyring\n' > "$case_dir/keyring.gpg"
  . "$helper"

  dpkg-query() {
    local package="${!#}"
    if [ "$SCENARIO" = all-installed ] || [ "$package" = curl ]; then
      printf '%s' 'install ok installed'
    else
      return 1
    fi
  }
  keli_apt_root() {
    if [ "$1" = env ]; then
      shift
      [ "$1" = DEBIAN_FRONTEND=noninteractive ] || exit 91
      shift
      [ "$1" = NEEDRESTART_MODE=l ] || exit 92
      shift
    fi
    [ "$1" = apt-get ] || exit 93
    "$@"
  }
  keli_apt_is_bullseye() { [ "$SCENARIO" != wrong-distro ]; }
  keli_apt_archive_keyring() {
    if [ "$SCENARIO" = missing-keyring ]; then
      printf '%s\n' "$case_dir/absent.gpg"
    else
      printf '%s\n' "$case_dir/keyring.gpg"
    fi
  }
  apt-get() {
    local phase=normal command="" value sources=""
    for value in "$@"; do
      case "$value" in
        Dir::Etc::sourcelist=*) phase=archive; sources="${value#*=}" ;;
        update|install) command="$value" ;;
      esac
    done
    printf '%s %s %s\n' "$phase" "$command" "$*" >> "$CALL_LOG"
    if [ "$phase" = archive ]; then
      [ -s "$sources" ] || return 94
      grep -q 'signed-by=.*check-valid-until=no' "$sources" || return 95
      grep -q 'archive.debian.org/debian-security bullseye-security main' "$sources" || return 96
      printf '%s\n' "$*" | grep -q 'Acquire::AllowInsecureRepositories=false' || return 97
      if [ "$SCENARIO" = archive-update-fails ] && [ "$command" = update ]; then return 100; fi
      if [ "$SCENARIO" = archive-install-fails ] && [ "$command" = install ]; then return 100; fi
      return 0
    fi
    case "$SCENARIO:$command" in
      normal:*|all-installed:*) return 0 ;;
      network:update) printf 'Could not resolve security.debian.org\n'; return 100 ;;
      third-party:update) printf 'Hit http://deb.debian.org bullseye\n404 Not Found https://vendor.example.test\n'; return 100 ;;
      lock:install) printf 'Could not get lock /var/lib/dpkg/lock-frontend\n'; return 100 ;;
      lock:update) return 0 ;;
      signature:update) printf '404 security.debian.org NO_PUBKEY synthetic\n'; return 100 ;;
      release:update) printf 'The repository security.debian.org bullseye-security does not have a Release file\n'; return 100 ;;
      update-404:update|wrong-distro:update) printf '404 Not Found security.debian.org bullseye-security\n'; return 100 ;;
      *:update) return 0 ;;
      *:install) printf 'Failed to fetch http://security.debian.org/debian-security/pool/updates/example.deb 404 Not Found\n'; return 100 ;;
    esac
  }

  trap 'printf "outer-trap\n" >> "$case_dir/events"' EXIT
  before="$(trap -p EXIT)"
  keli_apt_install curl wget ca-certificates
  [ "$(trap -p EXIT)" = "$before" ] || exit 98
  printf 'post-dependencies\n' >> "$case_dir/events"
  exit 0
fi

base="${TMPDIR:-/tmp}"
case "$base" in /*) ;; *) printf 'Absolute TMPDIR required\n' >&2; exit 1 ;; esac
base="$(cd "$base" && pwd -P)"
[ "$base" != / ] || exit 1
work="$(mktemp -d "$base/kelicloud-apt-tests.XXXXXX")"
[ "${work%/*}" = "$base" ] || exit 1
trap '[ "${work%/*}" = "$base" ] && rm -rf -- "$work"' EXIT
count=0
for scenario in all-installed normal stale-index update-404 release wrong-distro network third-party signature lock missing-keyring archive-update-fails archive-install-fails; do
  dir="$work/$scenario"
  mkdir -p "$dir"
  if bash "$0" --case "$scenario" "$dir" > "$dir/output" 2>&1; then code=0; else code=$?; fi
  case "$scenario" in
    all-installed|normal|stale-index|update-404|release) expected=0 ;;
    *) expected=1 ;;
  esac
  if [ "$code" -ne "$expected" ]; then
    printf 'FAIL %s: expected %s got %s\n' "$scenario" "$expected" "$code" >&2
    cat "$dir/output" >&2
    exit 1
  fi
  if [ "$code" -ne 0 ] && grep -q post-dependencies "$dir/events"; then
    printf 'FAIL %s: continued after dependency failure\n' "$scenario" >&2
    exit 1
  fi
  if find "$dir" -type d -name 'kelicloud-apt.*' | grep -q .; then
    printf 'FAIL %s: temporary apt state was not cleaned\n' "$scenario" >&2
    exit 1
  fi
  if grep -Eq '(install.* curl( |$))|dist-upgrade|full-upgrade|--allow-unauthenticated|trusted=yes' "$dir/calls"; then
    printf 'FAIL %s: upgraded an existing package or bypassed verification\n' "$scenario" >&2
    exit 1
  fi
  case "$scenario" in
    wrong-distro|network|third-party|signature|lock|missing-keyring)
      if grep -q '^archive ' "$dir/calls"; then
        printf 'FAIL %s: unsafe archive fallback\n' "$scenario" >&2
        exit 1
      fi ;;
    stale-index|update-404|release)
      grep -q '^archive install ' "$dir/calls" || exit 1 ;;
    all-installed) [ ! -s "$dir/calls" ] || exit 1 ;;
  esac
  count=$((count + 1))
done
printf 'PASS: %s isolated apt dependency scenarios (no real package commands)\n' "$count"
