# Included only after the sourced-child guard in the service 123 bootstrap.
private_callback_ip='{{PRIVATE_IP}}'
private_callback_device='{{PRIVATE_DEVICE}}'
private_callback_route() {
    local route i
    local -a fields
    route=$(timeout 3s ip -o -4 route get fibmatch "$private_callback_ip" 2>/dev/null) || return 1
    [[ "$route" != *$'\n'* ]] || return 1
    read -r -a fields <<< "$route"
    # A default or special route is not an approved path even on the expected device.
    [[ "${fields[0]:-}" =~ ^[0-9]+(\.[0-9]+){3}(/[1-9][0-9]?)?$ ]] || return 1
    for ((i = 0; i + 1 < ${#fields[@]}; i++)); do
        if [ "${fields[i]}" = dev ]; then
            # An implicit return can inherit the installer's failure while in the EXIT trap.
            if [ "${fields[i + 1]}" = "$private_callback_device" ]; then return 0; fi
            return 1
        fi
    done
    return 1
}
private_callback_preflight() {
    local status
    [ -f "$directory/callback-transport.conf" ] && [ ! -L "$directory/callback-transport.conf" ] || return 1
    [ -f "$directory/callback.conf" ] && [ ! -L "$directory/callback.conf" ] || return 1
    command -v curl >/dev/null 2>&1 && private_callback_route || return 1
    # A tokenless POST verifies the TLS route without submitting a receipt.
    status=$(curl -q --silent --connect-timeout 3 --max-time 7 --config "$directory/callback-transport.conf" \
        --header 'Content-Type: application/json' --data-binary '{}' --output /dev/null --write-out '%{http_code}' 2>/dev/null) || return 1
    [ "$status" = 401 ]
}
if declare -F keli_provision_stage >/dev/null; then keli_provision_stage callback_preflight || true; fi
if ! private_callback_preflight; then
    if declare -F keli_provision_exit >/dev/null; then keli_provision_exit 85 "$LINENO" || true; fi
    exit 85
fi
