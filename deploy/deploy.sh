#!/usr/bin/env bash
# Deploy a released ventMail image to the mail box, blue/green.
#
#   deploy.sh <tag>        e.g. deploy.sh v1.0.0
#
# Only CI-built images from GHCR, pinned to a version tag (or a full commit
# SHA for an emergency rollback); never :latest, never a local build.
#
#   1. pull the image                     - fails: nothing changed
#   2. data stack (Redis, storage) up     - shared by both colours; a no-op once running
#   3. config/route/view/event:cache      - one-off container; proves the release
#                                           boots with this .env. Fails: nothing changed
#   4. pg_dump of the app database        - the way back if a migration misbehaves
#   5. migrate --force --isolated         - one-off (Redis lock). Migrations are
#                                           expand-only (repo rule), so the serving
#                                           colour keeps working on the new schema.
#   6. start the release on the idle colour next to the serving one; wait for health
#   7. healthy   -> drain the old colour (its /up turns 503, the edge's health check
#                   moves traffic to the new one), then stop it gracefully
#      unhealthy -> discard the new colour; the old one never stopped serving
#
# Idempotent: deploying the running tag again converges to the same state.
# Never removes a volume.
set -euo pipefail

IMAGE="ghcr.io/venture-co-group/ventmail"
APP_DIR="${APP_DIR:-$(cd "$(dirname "$0")" && pwd)}"
BACKUP_DIR="${BACKUP_DIR:-/srv/backups/pg}"
PG_CONTAINER="${PG_CONTAINER:-mail-postgres}"
WAIT_TIMEOUT="${WAIT_TIMEOUT:-180}"
# Long enough for the edge (2 s health interval) to mark a draining colour down
# and for in-flight requests to finish before it is stopped.
DRAIN_SECONDS="${DRAIN_SECONDS:-8}"

log() { printf '%s [deploy] %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*" | tee -a "$APP_DIR/deploy.log" >&2; }
die() {
    log "FAILED: $*"
    exit 1
}

tag="${1:-}"
if ! [[ "$tag" =~ ^v[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?$ || "$tag" =~ ^[0-9a-f]{40}$ ]]; then
    echo "usage: deploy.sh <tag>   (vX.Y.Z or a full 40-char commit SHA; never 'latest')" >&2
    exit 2
fi

cd "$APP_DIR"
[ -f .env ] || die ".env missing in $APP_DIR"
if [ ! -f compose.prod.yml ] || [ ! -f compose.data.yml ]; then
    die "compose files missing in $APP_DIR"
fi
# The apex landing host must be a trusted host, or production answers it with 400.
# Existing boxes predate the key: refuse early instead of shipping a broken apex.
env_value() { sed -n "s/^$1=//p" .env | tail -1 | tr -d "\"'"; }
landing_host="$(env_value LANDING_HOST)"
landing_host="${landing_host:-ventmail.app}"
# config/proxy.php accepts commas OR whitespace; so does this check.
trusted_hosts="$(env_value TRUSTED_HOSTS | tr -s ' \t,' ',,,')"
case ",$trusted_hosts," in
    *",$landing_host,"*) ;;
    *) die "LANDING_HOST ($landing_host) is not in TRUSTED_HOSTS in $APP_DIR/.env; run set-env.sh to add it (and LANDING_HOST) before deploying" ;;
esac
# Compose interpolation prefers the caller's environment over .env; the values
# the colours and the data stack read must come from .env alone.
unset APP_URL TRUSTED_PROXIES REDIS_PASSWORD

# mkdir is atomic everywhere (flock is not on every platform).
mkdir .deploy.lock 2>/dev/null || die "another deploy is running (remove $APP_DIR/.deploy.lock if it is stale)"
trap 'rmdir "$APP_DIR/.deploy.lock" 2>/dev/null || true' EXIT

previous="$(cat .deployed-tag 2>/dev/null || true)"
active="$(cat .active-color 2>/dev/null || true)"
if [ -z "$active" ]; then
    # No recorded colour must mean nothing runs; otherwise the state was lost and
    # guessing could leave an old colour serving forever.
    for c in blue green; do
        if [ -n "$(docker ps -q --filter "label=com.docker.compose.project=ventmail-$c")" ]; then
            die ".active-color is missing but ventmail-$c is running; write the serving colour into .active-color first"
        fi
    done
fi
if [ "$active" = blue ]; then next=green; else next=blue; fi

colour() { # colour <name> <tag> <compose args...>
    local name="$1" release="$2"
    shift 2
    VENTMAIL_TAG="$release" VENTMAIL_COLOR="$name" docker compose -p "ventmail-$name" -f compose.prod.yml "$@"
}
data() { docker compose -p ventmail-data -f compose.data.yml "$@"; }
ops() { colour ops "$tag" --profile ops run --rm --no-deps -T ops artisan "$@"; }

log "deploying $tag to $next (serving: ${previous:-nothing} on ${active:-no colour})"

# 1. pull
docker pull "$IMAGE:$tag" >/dev/null || die "cannot pull $IMAGE:$tag; nothing changed"
log "pulled $(docker image inspect --format '{{index .RepoDigests 0}}' "$IMAGE:$tag" 2>/dev/null || echo "$IMAGE:$tag")"

# 2. the data stack must exist before any colour or one-off (they join its network).
data up -d --wait --wait-timeout "$WAIT_TIMEOUT" >/dev/null || die "data stack (Redis) did not become healthy; nothing changed"

# 3. preflight: the release must boot and cache with the production env.
for cache in config:cache route:cache view:cache event:cache; do
    ops "$cache" >/dev/null || die "$cache failed for $tag; nothing changed"
done
log "preflight caches OK"

# 4. pre-migration dump
db="$(sed -n 's/^DB_DATABASE=//p' .env | tail -1 | tr -d "\"'")"
[[ "$db" =~ ^[a-z][a-z0-9_]{1,30}$ ]] || die "DB_DATABASE missing or invalid in .env; refusing to migrate without a dump"
umask 077
mkdir -p "$BACKUP_DIR/$db"
dump="$BACKUP_DIR/$db/$db-predeploy-$tag-$(date -u +%Y%m%dT%H%M%SZ).dump"
if ! docker exec -u postgres "$PG_CONTAINER" pg_dump -U postgres -Fc "$db" >"$dump.partial"; then
    rm -f "$dump.partial"
    die "pre-migration dump of $db failed; nothing changed"
fi
mv "$dump.partial" "$dump"
log "pre-migration dump: $dump"

# 5. migrate
ops migrate --force --isolated --no-interaction ||
    die "migration failed for $tag; ${active:-nothing} keeps serving $previous. Schema may be partially migrated: RUNBOOK 'Failed migration'"

log "migrations applied"

# 6. start the idle colour next to the serving one
if ! colour "$next" "$tag" up -d --remove-orphans --wait --wait-timeout "$WAIT_TIMEOUT"; then
    log "$tag did not become healthy on $next; discarding it"
    colour "$next" "$tag" down --remove-orphans ||
        log "warning: could not remove the failed $next colour; it receives no traffic while unhealthy, remove it by hand"
    die "$tag is NOT deployed; ${active:-nothing} keeps serving ${previous:-nothing} and was never stopped"
fi
log "$next is healthy on $tag"

# 7. record the new state, then drain and retire the old colour
printf '%s\n' "$tag" >.deployed-tag.tmp && mv .deployed-tag.tmp .deployed-tag
printf '%s\n' "$next" >.active-color.tmp && mv .active-color.tmp .active-color
if [ -n "$active" ] && [ "$active" != "$next" ]; then
    colour "$active" "${previous:-$tag}" exec -T web touch /tmp/ventmail-draining ||
        log "warning: could not mark $active as draining; stopping it anyway"
    sleep "$DRAIN_SECONDS"
    colour "$active" "${previous:-$tag}" down --remove-orphans || log "warning: could not stop $active; stop it by hand"
    log "retired $active ($previous)"
fi

log "deployed $tag on $next"
