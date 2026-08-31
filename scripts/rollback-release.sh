#!/usr/bin/env sh
set -eu

ROOT="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
RECEIPT="${1:-}"

if [ -z "$RECEIPT" ] || [ ! -f "$RECEIPT" ]; then
  echo "Usage: sh scripts/rollback-release.sh path/to/deployment-receipt.jsonl" >&2
  exit 2
fi

first_line="$(sed -n '1p' "$RECEIPT")"
previous_sha="$(printf '%s' "$first_line" | sed -n 's/.*"previous_git_sha":"\([a-f0-9]*\)".*/\1/p')"
previous_image="$(printf '%s' "$first_line" | sed -n 's/.*"previous_image":"\([^"]*\)".*/\1/p')"

printf '%s' "$previous_sha" | grep -Eq '^[a-f0-9]{40}$' || {
  echo "ERROR: receipt does not contain a valid previous Git SHA" >&2
  exit 1
}
[ -n "$previous_image" ] || {
  echo "ERROR: receipt does not contain a previous image" >&2
  exit 1
}

echo "Rollback runs as a new gated deployment; old code must pass the canary against the current database."
exec sh "$ROOT/scripts/deploy-release.sh" --no-fetch --ref="$previous_sha" --image="$previous_image"
