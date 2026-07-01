#!/usr/bin/env bash
set -Eeuo pipefail

# ===== 每台机器只改这里 =====
AGENT_TYPE={{AGENT_TYPE}} # kelinode / kelinode-rs
MACHINE_URL={{MACHINE_URL}}
MACHINE_ID={{MACHINE_ID}}
MACHINE_TOKEN={{MACHINE_TOKEN}}
MACHINE_NAME={{MACHINE_NAME}}

KELINODE_INSTALL_SCRIPT_URL={{KELINODE_INSTALL_SCRIPT_URL}}
KELINODE_RS_INSTALL_SCRIPT_URL={{KELINODE_RS_INSTALL_SCRIPT_URL}}
KELINODE_RS_RELEASE_BASE_URL={{KELINODE_RS_RELEASE_BASE_URL}}

INSTALL_DEPS="1"
ENABLE_BBR="1"
ENABLE_SYS_TUNING="1"
ENABLE_EGRESS_GUARD="1"
# ==========================

log() { printf '\033[1;32m[INFO]\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[WARN]\033[0m %s\n' "$*"; }
die() { printf '\033[1;31m[ERROR]\033[0m %s\n' "$*" >&2; exit 1; }

have() { command -v "$1" >/dev/null 2>&1; }

as_root() {
  if [ "$(id -u)" -eq 0 ]; then
    "$@"
  elif have sudo; then
    sudo "$@"
  else
    die "需要 root 权限，但当前用户不是 root，且系统没有 sudo"
  fi
}

detect_pm() {
  if have apt-get; then echo apt
  elif have dnf; then echo dnf
  elif have yum; then echo yum
  elif have apk; then echo apk
  elif have pacman; then echo pacman
  elif have zypper; then echo zypper
  else echo unknown
  fi
}

install_packages() {
  [ "$INSTALL_DEPS" = "1" ] || return 0

  local pm
  pm="$(detect_pm)"
  [ "$pm" != "unknown" ] || {
    warn "无法识别包管理器，跳过依赖自动安装"
    return 0
  }

  log "安装/补齐依赖"

  case "$pm" in
    apt)
      as_root apt-get update -y
      as_root apt-get install -y curl wget ca-certificates iproute2 iptables nftables kmod procps
      ;;
    dnf)
      as_root dnf install -y curl wget ca-certificates iproute iptables nftables kmod procps-ng
      ;;
    yum)
      as_root yum install -y curl wget ca-certificates iproute iptables nftables kmod procps-ng
      ;;
    apk)
      as_root apk add --no-cache curl wget ca-certificates iproute2 iptables nftables kmod procps
      ;;
    pacman)
      as_root pacman -Sy --noconfirm curl wget ca-certificates iproute2 iptables nftables kmod procps-ng
      ;;
    zypper)
      as_root zypper --non-interactive install curl wget ca-certificates iproute2 iptables nftables kmod procps
      ;;
  esac
}

ensure_downloader() {
  have curl || have wget || die "curl/wget 都不可用，无法下载安装脚本"
}

download_file() {
  local url="$1"
  local output="$2"

  if have curl; then
    curl -fsSL "$url" -o "$output"
  elif have wget; then
    wget -qO "$output" "$url"
  else
    die "curl/wget 都不可用"
  fi
}

enable_bbr() {
  [ "$ENABLE_BBR" = "1" ] || return 0

  log "尝试开启 BBR"

  if [ "$(uname -s)" != "Linux" ]; then
    warn "当前不是 Linux，跳过 BBR"
    return 0
  fi

  have modprobe && as_root modprobe tcp_bbr 2>/dev/null || true

  local available=""
  available="$(sysctl -n net.ipv4.tcp_available_congestion_control 2>/dev/null || true)"

  if ! printf '%s' "$available" | grep -qw bbr; then
    warn "当前内核不支持 BBR，跳过。available=${available:-unknown}"
    return 0
  fi

  as_root mkdir -p /etc/sysctl.d
  as_root sh -c "cat > /etc/sysctl.d/99-kelicloud-bbr.conf" <<'EOF'
net.core.default_qdisc = fq
net.ipv4.tcp_congestion_control = bbr
EOF

  as_root sysctl --system >/dev/null 2>&1 || {
    warn "sysctl --system 执行失败，尝试直接设置 BBR"
    as_root sysctl -w net.core.default_qdisc=fq >/dev/null 2>&1 || true
    as_root sysctl -w net.ipv4.tcp_congestion_control=bbr >/dev/null 2>&1 || true
  }

  local current=""
  current="$(sysctl -n net.ipv4.tcp_congestion_control 2>/dev/null || true)"

  if [ "$current" = "bbr" ]; then
    log "BBR 已开启"
  else
    warn "BBR 未成功开启，当前拥塞控制: ${current:-unknown}"
  fi
}

get_conntrack_max() {
  local mem_kb="0"
  mem_kb="$(awk '/MemTotal:/ {print $2}' /proc/meminfo 2>/dev/null || echo 0)"

  if [ "$mem_kb" -ge 8000000 ]; then
    echo "1048576"
  elif [ "$mem_kb" -ge 4000000 ]; then
    echo "524288"
  else
    echo "262144"
  fi
}

tune_system_for_traffic() {
  [ "$ENABLE_SYS_TUNING" = "1" ] || return 0

  log "优化系统参数：面向高流量/高带宽/转发场景"

  if [ "$(uname -s)" != "Linux" ]; then
    warn "当前不是 Linux，跳过系统参数优化"
    return 0
  fi

  have modprobe && as_root modprobe nf_conntrack 2>/dev/null || true

  local conntrack_max
  conntrack_max="$(get_conntrack_max)"
  log "自适应 conntrack_max: $conntrack_max"

  as_root mkdir -p /etc/sysctl.d

  as_root sh -c "cat > /etc/sysctl.d/98-kelicloud-traffic-tuning.conf" <<EOF
net.core.somaxconn = 65535
net.core.netdev_max_backlog = 250000
net.ipv4.tcp_max_syn_backlog = 65535
net.ipv4.ip_local_port_range = 10000 65535
net.ipv4.tcp_keepalive_time = 600
net.ipv4.tcp_keepalive_intvl = 30
net.ipv4.tcp_keepalive_probes = 5
net.ipv4.tcp_fin_timeout = 15
net.ipv4.tcp_tw_reuse = 1
net.ipv4.tcp_mtu_probing = 1
net.ipv4.tcp_slow_start_after_idle = 0
net.ipv4.tcp_fastopen = 3
net.ipv4.ip_forward = 1
net.ipv6.conf.all.forwarding = 1
net.core.rmem_max = 67108864
net.core.wmem_max = 67108864
net.core.rmem_default = 1048576
net.core.wmem_default = 1048576
net.ipv4.tcp_rmem = 4096 87380 67108864
net.ipv4.tcp_wmem = 4096 65536 67108864
vm.swappiness = 10
fs.file-max = 2097152
net.netfilter.nf_conntrack_max = ${conntrack_max}
EOF

  as_root sysctl --system >/dev/null 2>&1 || {
    warn "sysctl --system 有部分参数不支持，尝试逐项应用"
    while IFS= read -r line; do
      case "$line" in ""|\#*) continue ;; esac
      as_root sysctl -w "$line" >/dev/null 2>&1 || true
    done < /etc/sysctl.d/98-kelicloud-traffic-tuning.conf
  }

  ulimit -n 1048576 2>/dev/null || true

  log "系统参数优化完成"
}

apply_egress_guard() {
  [ "$ENABLE_EGRESS_GUARD" = "1" ] || return 0

  log "应用出站风控：阻断 SMTP / BT / 常见矿池端口"

  if [ "$(uname -s)" != "Linux" ]; then
    warn "当前不是 Linux，跳过出站风控"
    return 0
  fi

  local guard="/usr/local/sbin/kelicloud-egress-guard.sh"
  as_root mkdir -p /usr/local/sbin

  as_root sh -c "cat > '$guard'" <<'EOF'
#!/usr/bin/env sh
set -eu

CHAIN="KELICLOUD_EGRESS_GUARD"

SMTP_PORTS="25,465,587,2525"
BT_TCP_PORTS="6881:6999,6969,51413"
BT_UDP_PORTS="6881:6999,6969,51413"
MINING_TCP_PORTS="3333,4444,5555,7777,9999,14433,14444,18081,18082"
MINING_UDP_PORTS="3333,4444,5555,7777,9999,14433,14444,18081,18082"

apply_iptables() {
  IPTABLES="$(command -v iptables || command -v iptables-nft || command -v iptables-legacy || true)"
  IP6TABLES="$(command -v ip6tables || command -v ip6tables-nft || command -v ip6tables-legacy || true)"
  [ -n "$IPTABLES" ] || return 1

  "$IPTABLES" -N "$CHAIN" 2>/dev/null || true
  "$IPTABLES" -F "$CHAIN" 2>/dev/null || true
  "$IPTABLES" -C OUTPUT -j "$CHAIN" 2>/dev/null || "$IPTABLES" -I OUTPUT 1 -j "$CHAIN"

  "$IPTABLES" -A "$CHAIN" -m conntrack --ctstate ESTABLISHED,RELATED -j RETURN 2>/dev/null || true
  "$IPTABLES" -A "$CHAIN" -p tcp -m multiport --dports "$SMTP_PORTS" -j REJECT --reject-with tcp-reset
  "$IPTABLES" -A "$CHAIN" -p tcp -m multiport --dports "$BT_TCP_PORTS" -j REJECT --reject-with tcp-reset
  "$IPTABLES" -A "$CHAIN" -p udp -m multiport --dports "$BT_UDP_PORTS" -j REJECT
  "$IPTABLES" -A "$CHAIN" -p tcp -m multiport --dports "$MINING_TCP_PORTS" -j REJECT --reject-with tcp-reset
  "$IPTABLES" -A "$CHAIN" -p udp -m multiport --dports "$MINING_UDP_PORTS" -j REJECT
  "$IPTABLES" -A "$CHAIN" -j RETURN

  if [ -n "$IP6TABLES" ]; then
    "$IP6TABLES" -N "$CHAIN" 2>/dev/null || true
    "$IP6TABLES" -F "$CHAIN" 2>/dev/null || true
    "$IP6TABLES" -C OUTPUT -j "$CHAIN" 2>/dev/null || "$IP6TABLES" -I OUTPUT 1 -j "$CHAIN"
    "$IP6TABLES" -A "$CHAIN" -m conntrack --ctstate ESTABLISHED,RELATED -j RETURN 2>/dev/null || true
    "$IP6TABLES" -A "$CHAIN" -p tcp -m multiport --dports "$SMTP_PORTS" -j REJECT --reject-with tcp-reset
    "$IP6TABLES" -A "$CHAIN" -p tcp -m multiport --dports "$BT_TCP_PORTS" -j REJECT --reject-with tcp-reset
    "$IP6TABLES" -A "$CHAIN" -p udp -m multiport --dports "$BT_UDP_PORTS" -j REJECT
    "$IP6TABLES" -A "$CHAIN" -p tcp -m multiport --dports "$MINING_TCP_PORTS" -j REJECT --reject-with tcp-reset
    "$IP6TABLES" -A "$CHAIN" -p udp -m multiport --dports "$MINING_UDP_PORTS" -j REJECT
    "$IP6TABLES" -A "$CHAIN" -j RETURN
  fi

  return 0
}

apply_nftables() {
  NFT="$(command -v nft || true)"
  [ -n "$NFT" ] || return 1

  "$NFT" add table inet kelicloud_egress 2>/dev/null || true
  "$NFT" delete chain inet kelicloud_egress output 2>/dev/null || true
  "$NFT" add chain inet kelicloud_egress output '{ type filter hook output priority 0; policy accept; }'

  "$NFT" add rule inet kelicloud_egress output ct state established,related accept
  "$NFT" add rule inet kelicloud_egress output tcp dport '{ 25, 465, 587, 2525 }' reject with tcp reset
  "$NFT" add rule inet kelicloud_egress output tcp dport '{ 3333, 4444, 5555, 7777, 9999, 14433, 14444, 18081, 18082 }' reject with tcp reset
  "$NFT" add rule inet kelicloud_egress output udp dport '{ 3333, 4444, 5555, 7777, 9999, 14433, 14444, 18081, 18082 }' reject
  "$NFT" add rule inet kelicloud_egress output tcp dport 6881-6999 reject with tcp reset
  "$NFT" add rule inet kelicloud_egress output udp dport 6881-6999 reject
  "$NFT" add rule inet kelicloud_egress output tcp dport '{ 6969, 51413 }' reject with tcp reset
  "$NFT" add rule inet kelicloud_egress output udp dport '{ 6969, 51413 }' reject

  return 0
}

if apply_iptables; then
  echo "kelicloud egress guard applied with iptables"
elif apply_nftables; then
  echo "kelicloud egress guard applied with nftables"
else
  echo "kelicloud egress guard failed: iptables/nftables not found" >&2
  exit 1
fi
EOF

  as_root chmod 0755 "$guard"
  as_root "$guard" || warn "出站风控规则应用失败"

  if have systemctl; then
    as_root sh -c "cat > /etc/systemd/system/kelicloud-egress-guard.service" <<EOF
[Unit]
Description=kelicloud egress guard
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
ExecStart=$guard
RemainAfterExit=yes

[Install]
WantedBy=multi-user.target
EOF

    as_root systemctl daemon-reload
    as_root systemctl enable kelicloud-egress-guard.service >/dev/null 2>&1 || true
    as_root systemctl restart kelicloud-egress-guard.service >/dev/null 2>&1 || true
    log "出站风控已设置为开机自动应用"
  else
    warn "当前系统没有 systemd，出站风控已临时应用"
  fi
}

select_agent() {
  case "$AGENT_TYPE" in
    kelinode)
      INSTALL_SCRIPT_URL="$KELINODE_INSTALL_SCRIPT_URL"
      RELEASE_BASE_URL=""
      INSTALLER="/tmp/v2node-install.sh"
      ;;
    kelinode-rs)
      INSTALL_SCRIPT_URL="$KELINODE_RS_INSTALL_SCRIPT_URL"
      RELEASE_BASE_URL="$KELINODE_RS_RELEASE_BASE_URL"
      INSTALLER="/tmp/keli-native-node-install.sh"
      ;;
    *)
      die "AGENT_TYPE 只能是 kelinode 或 kelinode-rs"
      ;;
  esac
}

run_installer() {
  if [ "$AGENT_TYPE" = "kelinode-rs" ]; then
    local args=()
    if [ -n "$RELEASE_BASE_URL" ]; then
      args+=(--release-base-url "$RELEASE_BASE_URL")
    fi
    as_root bash "$INSTALLER" \
      "${args[@]}" \
      --machine-url "$MACHINE_URL" \
      --machine-id "$MACHINE_ID" \
      --machine-token "$MACHINE_TOKEN" \
      --machine-name "$MACHINE_NAME"
    return 0
  fi

  as_root bash "$INSTALLER" \
    --machine-url "$MACHINE_URL" \
    --machine-id "$MACHINE_ID" \
    --machine-token "$MACHINE_TOKEN" \
    --machine-name "$MACHINE_NAME"
}

main() {
  [ -n "$MACHINE_URL" ] || die "MACHINE_URL 不能为空"
  [ -n "$MACHINE_ID" ] || die "MACHINE_ID 不能为空"
  [ -n "$MACHINE_TOKEN" ] || die "MACHINE_TOKEN 不能为空"
  [ -n "$MACHINE_NAME" ] || die "MACHINE_NAME 不能为空"

  select_agent

  log "开始准备系统环境"
  install_packages
  ensure_downloader

  enable_bbr
  tune_system_for_traffic
  apply_egress_guard

  log "下载安装脚本: $INSTALL_SCRIPT_URL"
  download_file "$INSTALL_SCRIPT_URL" "$INSTALLER"
  chmod +x "$INSTALLER"

  log "开始安装 $AGENT_TYPE: $MACHINE_NAME"
  run_installer

  log "安装完成"
}

main "$@"
