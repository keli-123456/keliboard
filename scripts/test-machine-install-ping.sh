#!/usr/bin/env bash
set -Eeuo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if [ "${1:-}" = --case ]; then
  pm="$2" scenario="$3" input="$4"
  if grep -q '^# BEGIN KELI_PING_DEPENDENCY' "$input"; then
    source <(tr -d '\r' < "$input" | sed -n '/^# BEGIN KELI_PING_DEPENDENCY/,/^# END KELI_PING_DEPENDENCY/p')
  else
    source "$input"
  fi

  calls=0 root_calls=0 installed_ping=false
  command() {
    [ "$#" -eq 2 ] && [ "$1" = -v ] || return 99
    [ "$2" = "$pm" ]
  }
  dpkg-query() {
    [ "$*" = '-W -f=${Status} iputils-ping' ] || return 99
    [ "$scenario" = installed-broken ] || return 1
    printf '%s\n' 'install ok installed'
  }
  rpm() {
    [ "$*" = '-q iputils' ] || return 99
    [ "$scenario" = installed-broken ]
  }
  apk() {
    [ "$*" = 'info -e iputils-ping' ] || return 99
    [ "$scenario" = installed-broken ]
  }
  pacman() {
    [ "$*" = '-Q iputils' ] || return 99
    [ "$scenario" = installed-broken ]
  }
  mock_install() {
    calls=$((calls + 1))
    [ "$scenario" != failed ] || return 42
    [ "$scenario" != no-root ] || return 1
    [ "$scenario" = postcheck-failed ] || installed_ping=true
  }
  keli_apt_install() {
    [ "$pm" = apt-get ] && [ "$*" = iputils-ping ] || return 99
    mock_install
  }
  keli_apt_root() {
    root_calls=$((root_calls + 1))
    case "$pm:$*" in
      'dnf:dnf install -y --setopt=install_weak_deps=False iputils' | \
      'yum:yum install -y iputils' | \
      'apk:apk add --no-cache --no-upgrade iputils-ping' | \
      'pacman:pacman -S --noconfirm --needed iputils') ;;
      *) return 99 ;;
    esac
    mock_install
  }
  keli_iputils_ping_version() {
    case "$1" in /usr/bin/ping | /bin/ping) ;; *) return 99 ;; esac
    if [ "$scenario" = present ] || [ "$installed_ping" = true ]; then
      printf '%s\n' 'ping from iputils 20240905'
    elif [ "$scenario" = bin-only ] && [ "$1" = /bin/ping ]; then
      printf '%s\n' 'ping from iputils 20240905'
    elif [ "$scenario" = busybox ]; then
      printf '%s\n' 'BusyBox ping'
    elif [ "$scenario" = version-error ]; then
      printf '%s\n' 'ping from iputils but executable failed'
      return 2
    else
      return 1
    fi
  }

  trap 'printf "%s\n" "unexpected error" >&2; exit 91' ERR
  before="$(trap -p EXIT)"
  keli_install_optional_ping
  [ "$(trap -p EXIT)" = "$before" ]
  case "$scenario:$pm" in
    present:* | bin-only:* | installed-broken:* | *:unknown) expected=0 ;;
    *) expected=1 ;;
  esac
  [ "$calls" -eq "$expected" ]
  if [ "$pm" != apt-get ]; then [ "$root_calls" -eq "$expected" ]; fi
  printf '%s\n' 'installer continued'
  exit 0
fi

work="$(mktemp -d)"
trap 'rm -rf -- "$work"' EXIT
inputs=("$root/resources/server-machine/ping-dependency.sh" "$root/resources/server-machine/kelinode-rs-install.sh")
if [ "$#" -gt 0 ]; then inputs=("$@"); fi
count=0
for input in "${inputs[@]}"; do
  for pm in apt-get dnf yum apk pacman unknown; do
    for scenario in missing present bin-only busybox version-error installed-broken failed no-root postcheck-failed; do
      output="$work/output"
      bash "$0" --case "$pm" "$scenario" "$input" > "$output" 2>&1 || {
        printf 'FAILED: %s %s %s\n' "$input" "$pm" "$scenario" >&2
        cat "$output" >&2
        exit 1
      }
      grep -q 'installer continued' "$output"
      case "$scenario:$pm" in
        present:* | bin-only:*) ! grep -q '\[WARN\]' "$output" ;;
        installed-broken:* | failed:* | no-root:* | postcheck-failed:* | *:unknown)
          grep -q '\[WARN\].*Continuing node installation.' "$output" ;;
        *) grep -q 'Probes remain disabled until configured in the panel.' "$output" ;;
      esac
      count=$((count + 1))
    done
  done
done
printf 'Optional iputils installer tests passed: %s cases (mocked; no system packages changed).\n' "$count"
