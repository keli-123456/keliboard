# Optional ICMP support; never change capabilities or block the node installer.
keli_iputils_ping_version() {
  [ -x "$1" ] || return 1
  "$1" -V 2>/dev/null
}

keli_iputils_ping_ready() {
  local binary version
  for binary in /usr/bin/ping /bin/ping; do
    version="$(keli_iputils_ping_version "$binary")" || continue
    case "$version" in *iputils*) return 0 ;; esac
  done
  return 1
}

keli_install_optional_ping() {
  keli_iputils_ping_ready && return 0

  local installed=false package="" status="" install=()
  if command -v apt-get >/dev/null 2>&1; then
    package=iputils-ping
    status="$(dpkg-query -W -f='${Status}' "$package" 2>/dev/null || true)"
    [ "$status" != "install ok installed" ] || installed=true
    install=(keli_apt_install "$package")
  elif command -v dnf >/dev/null 2>&1; then
    package=iputils
    if rpm -q "$package" >/dev/null 2>&1; then installed=true; fi
    install=(keli_apt_root dnf install -y --setopt=install_weak_deps=False "$package")
  elif command -v yum >/dev/null 2>&1; then
    package=iputils
    if rpm -q "$package" >/dev/null 2>&1; then installed=true; fi
    install=(keli_apt_root yum install -y "$package")
  elif command -v apk >/dev/null 2>&1; then
    package=iputils-ping
    if apk info -e "$package" >/dev/null 2>&1; then installed=true; fi
    install=(keli_apt_root apk add --no-cache --no-upgrade "$package")
  elif command -v pacman >/dev/null 2>&1; then
    package=iputils
    if pacman -Q "$package" >/dev/null 2>&1; then installed=true; fi
    install=(keli_apt_root pacman -S --noconfirm --needed "$package")
  else
    printf '%s\n' '[WARN] No supported package manager: install iputils ping for domestic reachability probes. Continuing node installation.' >&2
    return 0
  fi

  if [ "$installed" = true ]; then
    printf '%s\n' "[WARN] $package is installed but /usr/bin/ping or /bin/ping does not report iputils. Not reinstalling or changing permissions; probes remain unavailable. Continuing node installation." >&2
    return 0
  fi

  printf '%s\n' "[INFO] Installing optional $package for domestic reachability probes."
  if "${install[@]}"; then
    if keli_iputils_ping_ready; then
      printf '%s\n' '[INFO] iputils ping is available. Probes remain disabled until configured in the panel.'
    else
      printf '%s\n' '[WARN] iputils ping is still unavailable at /usr/bin/ping or /bin/ping; probes will report an error. Continuing node installation.' >&2
    fi
  else
    printf '%s\n' "[WARN] Optional $package installation failed; probes will report an error until it is installed. Continuing node installation." >&2
  fi
  return 0
}
