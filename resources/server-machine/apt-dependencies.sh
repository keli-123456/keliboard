# Debian 11 archive fallback is isolated to this dependency installation.
keli_apt_root() {
  if [ "$(id -u)" -eq 0 ]; then
    "$@"
  elif command -v sudo >/dev/null 2>&1; then
    sudo "$@"
  else
    printf '%s\n' "Dependency installation requires root or sudo." >&2
    return 1
  fi
}

keli_apt_is_bullseye() {
  [ -r /etc/os-release ] || return 1
  local ID="" VERSION_ID="" VERSION_CODENAME=""
  . /etc/os-release
  [ "$ID" = "debian" ] && { [ "$VERSION_CODENAME" = "bullseye" ] || [ "$VERSION_ID" = "11" ]; }
}

keli_apt_archive_keyring() {
  printf '%s\n' /usr/share/keyrings/debian-archive-keyring.gpg
}

keli_apt_install() (
  set -o pipefail
  export LC_ALL=C
  local missing=() package status
  for package in "$@"; do
    status="$(dpkg-query -W -f='${Status}' "$package" 2>/dev/null || true)"
    [ "$status" = "install ok installed" ] || missing+=("$package")
  done
  if [ "${#missing[@]}" -eq 0 ]; then
    printf '%s\n' "Required packages are already installed; keeping existing versions."
    return 0
  fi

  local work tmp_base keyring
  tmp_base="${TMPDIR:-/tmp}"
  case "$tmp_base" in /*) ;; *) printf '%s\n' "Temporary directory must be absolute." >&2; return 1 ;; esac
  tmp_base="$(cd "$tmp_base" && pwd -P)" || return 1
  [ "$tmp_base" != / ] || return 1
  work="$(mktemp -d "$tmp_base/kelicloud-apt.XXXXXX")" || return 1
  [ "${work%/*}" = "$tmp_base" ] || return 1
  trap '[ "${work%/*}" = "$tmp_base" ] && rm -rf -- "$work"' EXIT

  if keli_apt_root apt-get -o APT::Update::Error-Mode=any update 2>&1 | tee "$work/apt.log"; then
    if keli_apt_root env DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=l apt-get install -y \
      --no-install-recommends --no-upgrade --no-remove "${missing[@]}" 2>&1 | tee "$work/apt.log"; then
      return 0
    fi
  fi

  if ! keli_apt_is_bullseye \
    || ! awk '/404|does not have a Release file|no longer has a Release file|Release file.*expired/ && /security\.debian\.org|deb\.debian\.org/ { found=1 } END { exit !found }' "$work/apt.log"; then
    printf '%s\n' "Dependency installation failed; not changing system repositories or continuing node installation." >&2
    return 1
  fi
  if grep -Eqi 'NO_PUBKEY|EXPKEYSIG|BADSIG|not signed|signature.*(invalid|could not)' "$work/apt.log"; then
    printf '%s\n' "Repository signature verification failed; archive fallback is not permitted." >&2
    return 1
  fi

  keyring="$(keli_apt_archive_keyring)"
  [ -s "$keyring" ] || { printf '%s\n' "Debian archive keyring is unavailable; cannot verify archived packages." >&2; return 1; }
  printf '%s\n' "Debian 11 repositories are retired; using the signed Debian archive for this installation only." >&2
  printf '%s\n' "Debian 11 no longer receives Debian LTS security updates. Plan an OS upgrade separately." >&2
  mkdir -p "$work/empty" "$work/lists/partial" "$work/cache/archives/partial" || return 1
  chmod 0755 "$work" "$work/empty" "$work/lists" "$work/cache" "$work/cache/archives" || return 1
  printf 'deb [signed-by=%s check-valid-until=no] http://archive.debian.org/debian bullseye main\n' "$keyring" > "$work/sources.list"
  printf 'deb [signed-by=%s check-valid-until=no] http://archive.debian.org/debian-security bullseye-security main\n' "$keyring" >> "$work/sources.list"

  local options=(
    -o "Dir::Etc::sourcelist=$work/sources.list"
    -o "Dir::Etc::sourceparts=$work/empty"
    -o "Dir::State::lists=$work/lists"
    -o "Dir::Cache=$work/cache"
    -o APT::Update::Error-Mode=any
    -o Acquire::AllowInsecureRepositories=false
    -o Acquire::AllowDowngradeToInsecureRepositories=false
    -o APT::Get::AllowUnauthenticated=false
  )
  keli_apt_root apt-get "${options[@]}" update || return 1
  keli_apt_root env DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=l apt-get "${options[@]}" install -y \
    --no-install-recommends --no-upgrade --no-remove "${missing[@]}" || return 1
)
