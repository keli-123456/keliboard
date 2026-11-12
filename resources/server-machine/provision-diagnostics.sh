#!/usr/bin/env bash
# Loaded only by this task's explicitly scoped bootstrap/BASH_ENV.
_keli_diag_directory='{{DIRECTORY}}'
case "$0" in
  "$_keli_diag_directory/bootstrap.sh") _keli_diag_script=bootstrap; _keli_diag_phase=bootstrap_start ;;
  "$_keli_diag_directory/install.sh") _keli_diag_script=installer; _keli_diag_phase=arguments ;;
  /tmp/keli-native-node-install.sh) _keli_diag_script=native_installer; _keli_diag_phase=native_install ;;
  *) return 0 ;;
esac
_keli_diag_error_code=null
_keli_diag_error_line=null
_keli_diag_exit_line=null

_keli_provision_record() (
  trap - ERR EXIT
  set +e
  umask 077
  local event="$1" exit_code="$2" exit_line="$3" temporary target
  target="$_keli_diag_directory/diagnostic-$_keli_diag_script.json"
  [ -d "$_keli_diag_directory" ] && [ ! -L "$_keli_diag_directory" ] || return 0
  [ ! -L "$target" ] && { [ ! -e "$target" ] || [ -f "$target" ]; } || return 0
  temporary="$(mktemp "$_keli_diag_directory/.diagnostic-$_keli_diag_script.XXXXXX" 2>/dev/null)" || return 0
  if printf '{"schema":1,"task_id":"{{TASK}}","installation_id":"{{IDENTITY}}","script":"%s","phase":"%s","event":"%s","exit_code":%s,"error_code":%s,"error_line":%s,"exit_line":%s}\n' \
      "$_keli_diag_script" "$_keli_diag_phase" "$event" "$exit_code" "$_keli_diag_error_code" "$_keli_diag_error_line" "$exit_line" > "$temporary" \
      && chmod 600 "$temporary" && mv -fT -- "$temporary" "$target"; then
    :
  else
    rm -f -- "$temporary" 2>/dev/null || true
  fi
  return 0
)

keli_provision_stage() {
  case "${1:-}" in
    bootstrap_start|identity|already_completed|installer|arguments|dependencies|bbr|system_tuning|egress_guard|installer_download|installer_permissions|native_install|lock|base_dependencies|version_resolution|manifest_download|archive_download|archive_verify|unpack|binary_install|binary_verify|configuration|geo_rules|legacy_cleanup|service_install|complete)
      _keli_diag_phase="$1" ;;
    *) return 0 ;;
  esac
  _keli_provision_record phase null null >/dev/null 2>&1 || true
  return 0
}

keli_provision_error() {
  local code="${1:-}" line="${2:-}" source="${3:-}"
  if [[ "$code" =~ ^[0-9]{1,3}$ ]] && ((10#$code > 0 && 10#$code <= 255)); then
    _keli_diag_error_code=$((10#$code))
    _keli_diag_error_line=null
    case "$source" in
      "$_keli_diag_directory/bootstrap.sh"|"$_keli_diag_directory/install.sh"|/tmp/keli-native-node-install.sh)
        if [[ "$line" =~ ^[0-9]{1,6}$ ]] && ((10#$line > 0)); then _keli_diag_error_line=$((10#$line)); fi ;;
    esac
    _keli_provision_record error null null >/dev/null 2>&1 || true
  fi
  return 0
}

keli_provision_exit() {
  local code="${1:-}" line="${2:-}"
  [[ "$code" =~ ^[0-9]{1,3}$ ]] && ((10#$code <= 255)) || return 0
  code=$((10#$code))
  if [[ "$line" =~ ^[0-9]{1,6}$ ]] && ((10#$line > 0)); then _keli_diag_exit_line=$((10#$line)); fi
  _keli_provision_record exit "$code" "$_keli_diag_exit_line" >/dev/null 2>&1 || true
  return 0
}

set -E
trap 'keli_provision_error "$?" "$LINENO" "${BASH_SOURCE[0]:-}"' ERR
trap 'keli_provision_exit "$?" ""' EXIT
keli_provision_stage "$_keli_diag_phase"
