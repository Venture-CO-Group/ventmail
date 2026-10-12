#!/usr/bin/env bash
# Set one key in .env from STDIN, without the value ever reaching argv, a log
# or the terminal. Used for secrets that are created elsewhere and piped in:
#
#   ssh trust '<prints the secret>' | ssh mail '/srv/apps/ventmail/set-env.sh OIDC_CLIENT_SECRET'
#
# A multi-line value (a PEM public key) is stored on one line with literal \n,
# which config/sending.php turns back into newlines.
set -euo pipefail
APP_DIR="${APP_DIR:-$(cd "$(dirname "$0")" && pwd)}"
key="${1:-}"
[[ "$key" =~ ^[A-Z][A-Z0-9_]{1,63}$ ]] || { echo "usage: set-env.sh <KEY>  (value on stdin)" >&2; exit 2; }
cd "$APP_DIR"
[ -f .env ] || { echo ".env missing in $APP_DIR" >&2; exit 1; }

value="$(cat)"
value="${value%$'\n'}"
[ -n "$value" ] || { echo "empty value on stdin; .env unchanged" >&2; exit 1; }
# Compose's env_file interpolates `$` inside double quotes and Laravel's dotenv
# interprets backslashes: such a value would silently change. Refuse instead.
case "$value" in *'"'* | *'$'* | *'`'* | *\\*)
    echo "value contains a quote, \$, backtick or backslash; refusing" >&2
    exit 1
    ;;
esac
value="$(printf '%s' "$value" | awk 'BEGIN{ORS="\\n"} {print}' | sed 's/\\n$//')"

umask 077
tmp="$(mktemp .env.XXXXXX)"
trap 'rm -f "$tmp"' EXIT
KEY="$key" VALUE="$value" awk '
    BEGIN { k = ENVIRON["KEY"]; v = ENVIRON["VALUE"]; done = 0 }
    index($0, k "=") == 1 { print k "=\"" v "\""; done = 1; next }
    { print }
    END { if (!done) print k "=\"" v "\"" }
' .env >"$tmp"
chmod 600 "$tmp"
mv "$tmp" .env
trap - EXIT
echo "set $key in .env (value not shown)" >&2
