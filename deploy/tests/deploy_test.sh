#!/usr/bin/env bash
# Tests for deploy/deploy.sh against a stub `docker` that records every call.
# shellcheck disable=SC2016,SC2034 # expectations are strings eval'd after each run
#   deploy/tests/deploy_test.sh
# A call fails when its recorded line matches the regex in $FAKE_FAIL.
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
DEPLOY="$HERE/../deploy.sh"
PASS=0
FAIL=0

setup() {
    WORK="$(mktemp -d)"
    mkdir -p "$WORK/bin" "$WORK/app" "$WORK/backups"
    cat >"$WORK/bin/docker" <<'STUB'
#!/usr/bin/env bash
line="VENTMAIL_TAG=${VENTMAIL_TAG:-} VENTMAIL_COLOR=${VENTMAIL_COLOR:-} $*"
echo "$line" >>"$CALLS"
[ -n "${APP_URL:-}" ] && echo "APP_URL=$APP_URL leaked into compose" >>"$CALLS"
[ -n "${TRUSTED_PROXIES:-}" ] && echo "TRUSTED_PROXIES=$TRUSTED_PROXIES leaked into compose" >>"$CALLS"
if [ -n "${FAKE_FAIL:-}" ] && [[ "$line" =~ $FAKE_FAIL ]]; then exit 1; fi
if [ -n "${FAKE_RUNNING:-}" ] && [[ "$*" == ps* ]] && [[ "$*" == *"project=$FAKE_RUNNING"* ]]; then echo deadbeef; fi
exit 0
STUB
    chmod +x "$WORK/bin/docker"
    cp "$HERE/../compose.prod.yml" "$HERE/../compose.data.yml" "$HERE/../compose.render.yml" "$WORK/app/"
    printf 'DB_DATABASE=ventmail\nTRUSTED_HOSTS=app.ventmail.app,api.ventmail.app,ventmail.app,ventmail-web\n' >"$WORK/app/.env"
    export PATH="$WORK/bin:$PATH" CALLS="$WORK/calls" APP_DIR="$WORK/app" BACKUP_DIR="$WORK/backups"
    export WAIT_TIMEOUT=5 DRAIN_SECONDS=0
    unset FAKE_FAIL FAKE_RUNNING
    : >"$CALLS"
}
teardown() { rm -rf "$WORK"; }

ok() { PASS=$((PASS + 1)); printf '  ok    %s\n' "$1"; }
no() {
    FAIL=$((FAIL + 1))
    printf '  FAIL  %s\n' "$1"
    sed 's/^/          /' "$CALLS" 2>/dev/null || true
}
expect() { if eval "$2"; then ok "$1"; else no "$1"; fi; }
deployed() { cat "$APP_DIR/.deployed-tag" 2>/dev/null || echo "<none>"; }
# A generated secret is checked by shape, never written down as a literal.
redis_password_is_64_hex() {
    awk -F= '$1 == "REDIS_PASSWORD" && $2 ~ /^[0-9a-f]+$/ && length($2) == 64 { found = 1 } END { exit !found }' "$1"
}
color() { cat "$APP_DIR/.active-color" 2>/dev/null || echo "<none>"; }
called() { grep -Eq -- "$1" "$CALLS"; }
order() { # order <regex>... : the calls appear in this order
    local last=0 n
    for pattern in "$@"; do
        n="$(grep -En -- "$pattern" "$CALLS" | head -1 | cut -d: -f1)"
        [ -n "$n" ] && [ "$n" -gt "$last" ] || return 1
        last="$n"
    done
}

echo "deploy.sh"

setup
printf 'DB_DATABASE=ventmail\nTRUSTED_HOSTS=app.ventmail.app,api.ventmail.app,ventmail-web\n' >"$APP_DIR/.env"
rc=0; out="$("$DEPLOY" v1.0.0 2>&1)" || rc=$?
expect "refuses to deploy when the landing host (default ventmail.app) is not in TRUSTED_HOSTS" '[ "$rc" -ne 0 ] && [[ "$out" == *set-env.sh* ]] && [ ! -s "$CALLS" ]'
printf 'DB_DATABASE=ventmail\nLANDING_HOST=site.example\nTRUSTED_HOSTS=app.ventmail.app,site.example\n' >"$APP_DIR/.env"
rc=0; "$DEPLOY" v1.0.0 >/dev/null 2>&1 || rc=$?
expect "accepts an explicit LANDING_HOST that is trusted" '[ "$rc" -eq 0 ]'
teardown

setup
printf 'DB_DATABASE=ventmail\nTRUSTED_HOSTS="app.ventmail.app api.ventmail.app ventmail.app ventmail-web"\n' >"$APP_DIR/.env"
rc=0; "$DEPLOY" v1.0.0 >/dev/null 2>&1 || rc=$?
expect "accepts a whitespace-separated TRUSTED_HOSTS that contains the landing host" '[ "$rc" -eq 0 ]'
teardown

setup
rc=0; "$DEPLOY" latest >/dev/null 2>&1 || rc=$?
expect "rejects :latest" '[ "$rc" -ne 0 ] && [ ! -s "$CALLS" ]'
rc=0; "$DEPLOY" 'v1.0.0;rm' >/dev/null 2>&1 || rc=$?
expect "rejects a malformed tag before touching docker" '[ "$rc" -ne 0 ] && [ ! -s "$CALLS" ]'
rc=0; "$DEPLOY" >/dev/null 2>&1 || rc=$?
expect "requires a tag" '[ "$rc" -ne 0 ]'
teardown

setup
"$DEPLOY" v1.0.0 >/dev/null 2>&1
expect "first deploy records the tag and starts blue" '[ "$(deployed)" = v1.0.0 ] && [ "$(color)" = blue ]'
expect "pull -> data stack -> caches -> backup -> migrate -> colour up, in that order" \
    'order "pull ghcr.io/venture-co-group/ventmail:v1.0.0" "-p ventmail-data .*up -d --wait" "ops artisan config:cache" "ops artisan route:cache" "ops artisan view:cache" "ops artisan event:cache" "pg_dump" "ops artisan migrate --force" "^VENTMAIL_TAG=v1.0.0 VENTMAIL_COLOR=blue .*-p ventmail-blue .*up -d --remove-orphans --wait"'
expect "creates the shared storage volumes before any colour or one-off (D227)" \
    'order "volume create ventmail-storage$" "volume create ventmail-storage-public$" "ops artisan config:cache" "^VENTMAIL_TAG=v1.0.0 VENTMAIL_COLOR=blue .*up -d"'
expect "never removes or prunes a volume" '! called "volume (rm|prune)"'
expect "brings the render stack (Gotenberg) up and healthy before any colour (D238)" \
    'order "-p ventmail-data .*up -d --wait" "-p ventmail-render -f compose.render.yml up -d --wait" "^VENTMAIL_TAG=v1.0.0 VENTMAIL_COLOR=blue .*up -d"'
expect "never takes the render stack down" '! called "-p ventmail-render .*down"'
expect "the pre-migration dump lands in the backup dir" 'ls "$BACKUP_DIR"/ventmail/ventmail-predeploy-v1.0.0-*.dump >/dev/null 2>&1'
APP_URL=https://shell.example TRUSTED_PROXIES=0.0.0.0/0 "$DEPLOY" v1.1.0 >/dev/null 2>&1
expect "the next release starts on the other colour" 'called "^VENTMAIL_TAG=v1.1.0 VENTMAIL_COLOR=green .*-p ventmail-green .*up -d --remove-orphans --wait" && [ "$(color)" = green ] && [ "$(deployed)" = v1.1.0 ]'
expect "... and the old colour is retired only after the new one is healthy" \
    'order "-p ventmail-green .*up -d --remove-orphans --wait" "-p ventmail-blue .*down"'
expect "never lets the caller's shell override .env values in compose interpolation" 'called "^VENTMAIL_TAG=v1.1.0 VENTMAIL_COLOR=green .*-p ventmail-green" && ! grep -q "APP_URL=https://shell.example" "$CALLS"'
expect "never removes volumes" '! called "down .*(-v|--volumes)"'
expect "drains the old colour at the edge before stopping it" \
    'order "-p ventmail-blue .*exec -T web touch /tmp/ventmail-draining" "-p ventmail-blue .*down"'
expect "records the new state before retiring the old colour" '[ "$(color)" = green ]'
teardown

setup
echo v1.0.0 >"$APP_DIR/.deployed-tag"
echo blue >"$APP_DIR/.active-color"
export FAKE_FAIL='pull .*:v9\.9\.9'
rc=0; "$DEPLOY" v9.9.9 >/dev/null 2>&1 || rc=$?
expect "a tag that cannot be pulled fails" '[ "$rc" -ne 0 ]'
expect "... and changes nothing" '! called "up -d" && ! called migrate && ! called down && [ "$(deployed)" = v1.0.0 ] && [ "$(color)" = blue ]'
teardown

setup
echo v1.0.0 >"$APP_DIR/.deployed-tag"
echo blue >"$APP_DIR/.active-color"
export FAKE_FAIL='migrate'
rc=0; "$DEPLOY" v1.1.0 >/dev/null 2>&1 || rc=$?
expect "a failed migration stops before any colour changes" '[ "$rc" -ne 0 ] && ! called "-p ventmail-(blue|green) .*up" && ! called down && [ "$(deployed)" = v1.0.0 ]'
teardown

setup
echo v1.0.0 >"$APP_DIR/.deployed-tag"
echo blue >"$APP_DIR/.active-color"
export FAKE_FAIL='-p ventmail-green .*up -d --remove-orphans'
rc=0; "$DEPLOY" v1.1.0 >/dev/null 2>&1 || rc=$?
expect "an unhealthy release fails" '[ "$rc" -ne 0 ]'
expect "... discards the new colour" 'called "-p ventmail-green .*down"'
expect "... and never touches the serving colour" '! called "-p ventmail-blue .*(down|stop|up)"'
expect "... which stays recorded as active" '[ "$(deployed)" = v1.0.0 ] && [ "$(color)" = blue ]'
teardown

setup
export FAKE_FAIL='ops artisan route:cache'
rc=0; "$DEPLOY" v1.0.0 >/dev/null 2>&1 || rc=$?
expect "a failed preflight cache stops before the dump and the migration" '[ "$rc" -ne 0 ] && ! called pg_dump && ! called migrate'
teardown

setup
printf 'APP_ENV=production\nTRUSTED_HOSTS=app.ventmail.app,ventmail.app\n' >"$APP_DIR/.env"
rc=0; "$DEPLOY" v1.0.0 >/dev/null 2>&1 || rc=$?
expect "refuses to migrate without knowing which database to dump" '[ "$rc" -ne 0 ] && ! called migrate'
teardown

setup
printf 'DB_DATABASE="ventmail"\nTRUSTED_HOSTS=app.ventmail.app,ventmail.app\n' >"$APP_DIR/.env"
"$DEPLOY" v1.0.0 >/dev/null 2>&1
expect "reads a quoted DB_DATABASE" 'called "pg_dump -U postgres -Fc ventmail$"'
teardown

setup
export FAKE_FAIL='-p ventmail-render .*up'
rc=0; "$DEPLOY" v1.0.0 >/dev/null 2>&1 || rc=$?
expect "an unhealthy render stack stops before any change" '[ "$rc" -ne 0 ] && ! called migrate && ! called "-p ventmail-(blue|green) .*up" && [ "$(deployed)" = "<none>" ]'
teardown

setup
export FAKE_FAIL='pg_dump'
rc=0; "$DEPLOY" v1.0.0 >/dev/null 2>&1 || rc=$?
expect "a failed dump stops before the migration" '[ "$rc" -ne 0 ] && ! called migrate'
expect "... and leaves no partial dump behind" '! ls "$BACKUP_DIR"/ventmail/*.dump >/dev/null 2>&1'
teardown

setup
export FAKE_RUNNING=ventmail-green
rc=0; "$DEPLOY" v1.0.0 >/dev/null 2>&1 || rc=$?
expect "refuses to guess when .active-color is lost but a colour is running" '[ "$rc" -ne 0 ] && ! called migrate'
teardown

setup
echo v1.0.0 >"$APP_DIR/.deployed-tag"
echo blue >"$APP_DIR/.active-color"
export FAKE_FAIL='-p ventmail-green .*(up -d --remove-orphans|down)'
out="$("$DEPLOY" v1.1.0 2>&1 || true)"
expect "says so when discarding a failed colour also fails" 'grep -qi "could not remove" <<<"$out"'
teardown

setup
"$DEPLOY" v1.0.0 >/dev/null 2>&1
"$DEPLOY" v1.0.0 >/dev/null 2>&1
expect "redeploying the same tag is idempotent" '[ "$(deployed)" = v1.0.0 ]'
teardown

setup
mkdir "$APP_DIR/.deploy.lock"
rc=0; "$DEPLOY" v1.0.0 >/dev/null 2>&1 || rc=$?
expect "refuses to run while another deploy holds the lock" '[ "$rc" -ne 0 ] && [ ! -s "$CALLS" ]'
teardown

echo "init-env.sh"
INIT="$HERE/../init-env.sh"

setup
cat >"$WORK/bin/docker" <<'STUB'
#!/usr/bin/env bash
echo "VENTMAIL_TAG=${VENTMAIL_TAG:-} VENTMAIL_COLOR=${VENTMAIL_COLOR:-} $*" >>"$CALLS"
case "$*" in
    *key:generate*) echo "base64:$FAKE_APP_KEY" ;;
    *ventmail:pepper*) echo "$FAKE_PEPPER" ;;
esac
STUB
rm -f "$APP_DIR/.env"
# Per-run values: nothing secret-shaped is ever committed, even in a test.
FAKE_APP_KEY="$(openssl rand -base64 32)"
FAKE_PEPPER="$(openssl rand -hex 32)"
DB_PW="$(openssl rand -hex 16)"
export FAKE_APP_KEY FAKE_PEPPER
printf 'DB_CONNECTION=pgsql\nDB_HOST=mail-postgres\nDB_PORT=5432\nDB_DATABASE=ventmail\nDB_USERNAME=ventmail\nDB_PASSWORD=%s\n' "$DB_PW" >"$APP_DIR/db.env"
rc=0; "$INIT" v1.0.0 >/dev/null 2>&1 || rc=$?
expect "refuses to run without the seed allowlist" '[ "$rc" -ne 0 ] && [ ! -f "$APP_DIR/.env" ]'
export SEED_ALLOWLIST="seed-a@example.test,seed-b@example.test"
out="$("$INIT" v1.0.0 2>&1)"
expect "writes .env with mode 600" '[ "$(stat -c %a "$APP_DIR/.env" 2>/dev/null || stat -f %Lp "$APP_DIR/.env")" = 600 ]'
expect "never prints a secret" '! grep -qF "$FAKE_APP_KEY" <<<"$out" && ! grep -qF "$DB_PW" <<<"$out" && ! grep -qF "$FAKE_PEPPER" <<<"$out"'
expect "lists every key the production example lists" \
    'missing=0; for k in $(sed -n "s/^\([A-Z][A-Z0-9_]*\)=$/\1/p" "$HERE/../.env.production.example"); do grep -q "^$k=" "$APP_DIR/.env" || { echo "missing $k"; missing=1; }; done; [ "$missing" = 0 ]'
expect "the PDF renderer is the internal Gotenberg sidecar" 'grep -qx "ANALYTICS_PDF_RENDERER=gotenberg" "$APP_DIR/.env" && grep -qx "GOTENBERG_URL=http://gotenberg:3000" "$APP_DIR/.env"'
expect "production values" 'grep -qx "APP_ENV=production" "$APP_DIR/.env" && grep -qx "APP_DEBUG=false" "$APP_DIR/.env" && grep -qx "LOG_CHANNEL=stderr" "$APP_DIR/.env" && grep -qx "IDENTITY_DEV_LOGIN=false" "$APP_DIR/.env" && grep -qx "OIDC_PROVIDER_URL=https://sso.ventureco.app" "$APP_DIR/.env"'
expect "the sending switches start CLOSED (deploy rule 3)" 'grep -qx "SENDING_LIVE=false" "$APP_DIR/.env" && grep -qx "SENDING_MARKETING_ENABLED=false" "$APP_DIR/.env" && grep -qx "SEND_ALLOWLIST_ENFORCED=true" "$APP_DIR/.env" && grep -qx "SEND_ALLOWLIST=seed-a@example.test,seed-b@example.test" "$APP_DIR/.env"'
expect "a __Host- session cookie and the app, api and apex trusted hosts" 'grep -qx "SESSION_COOKIE=__Host-ventmail_session" "$APP_DIR/.env" && grep -qx "TRUSTED_HOSTS=app.ventmail.app,api.ventmail.app,ventmail.app,ventmail-web" "$APP_DIR/.env"'
expect "two separate Postal instances" 'grep -qx "POSTAL_TX_ENDPOINT=http://postal-tx-web:5000" "$APP_DIR/.env" && grep -qx "POSTAL_MK_ENDPOINT=http://postal-mk-web:5000" "$APP_DIR/.env"'
expect "takes the database credentials from db.env" 'grep -qx "DB_PASSWORD=$DB_PW" "$APP_DIR/.env"'
expect "generates a Redis password" 'redis_password_is_64_hex "$APP_DIR/.env"'
before="$(cat "$APP_DIR/.env")"
"$INIT" v1.0.0 >/dev/null 2>&1
expect "is idempotent: an existing .env is never overwritten" '[ "$(cat "$APP_DIR/.env")" = "$before" ]'
expect "trusts only the edge proxy addresses (v4 + v6)" 'grep -qx "TRUSTED_PROXIES=\"172.30.0.2/32 fd30:0:0:1::2/128\"" "$APP_DIR/.env"'
teardown

echo "set-env.sh"
SETENV="$HERE/../set-env.sh"
setup
printf 'APP_ENV=production\nOIDC_CLIENT_SECRET=\n' >"$APP_DIR/.env"
chmod 600 "$APP_DIR/.env"
secret="$(openssl rand -hex 20)"
out="$(printf '%s\n' "$secret" | "$SETENV" OIDC_CLIENT_SECRET 2>&1)"
expect "sets the key from stdin" 'grep -qx "OIDC_CLIENT_SECRET=\"$secret\"" "$APP_DIR/.env"'
expect "never prints the value" '! grep -qF "$secret" <<<"$out"'
expect "keeps the file private" '[ "$(stat -c %a "$APP_DIR/.env" 2>/dev/null || stat -f %Lp "$APP_DIR/.env")" = 600 ]'
printf -- '-----BEGIN PUBLIC KEY-----\nAAAA\nBBBB\n-----END PUBLIC KEY-----\n' | "$SETENV" POSTAL_TX_WEBHOOK_PUBLIC_KEY 2>/dev/null
expect "stores a PEM on one line" 'grep -qx "POSTAL_TX_WEBHOOK_PUBLIC_KEY=\"-----BEGIN PUBLIC KEY-----\\\\nAAAA\\\\nBBBB\\\\n-----END PUBLIC KEY-----\"" "$APP_DIR/.env"'
rc=0; printf '' | "$SETENV" OIDC_CLIENT_ID 2>/dev/null || rc=$?
expect "refuses an empty value" '[ "$rc" -ne 0 ] && ! grep -q "^OIDC_CLIENT_ID=" "$APP_DIR/.env"'
rc=0; printf 'abc$HOME' | "$SETENV" OIDC_CLIENT_ID 2>/dev/null || rc=$?
expect "refuses a value compose would interpolate (\$, backtick, backslash)" '[ "$rc" -ne 0 ] && ! grep -q "^OIDC_CLIENT_ID=" "$APP_DIR/.env"'
rc=0; printf 'x' | "$SETENV" 'bad key' 2>/dev/null || rc=$?
expect "refuses a malformed key" '[ "$rc" -eq 2 ]'
teardown

echo
echo "passed: $PASS  failed: $FAIL"
[ "$FAIL" -eq 0 ]
