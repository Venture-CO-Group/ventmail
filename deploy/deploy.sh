#!/usr/bin/env bash
# Deploy a released ventMail image to the mail box, blue/green.
#
#   deploy.sh <tag>        e.g. deploy.sh v1.0.0
#
# Only CI-built images from GHCR, pinned to a version tag (or a full commit
# SHA for an emergency rollback); never :latest, never a local build.
#
#   1. pull the image                     - fails: nothing changed
#   2. data stack (Redis), render stack   - shared by both colours; a no-op once running
#      (Gotenberg, D238) + shared storage
#      volumes (D227)
#   3. config/route/view/event:cache      - one-off container; proves the release
#                                           boots with this .env. Fails: nothing changed
#   4. pg_dump of the app database        - the way back if a migration misbehaves
#   5. migrate --force --isolated         - one-off (Redis lock). Migrations are
#                                           expand-only (repo rule), so the serving
#                                           colour keeps working on the new schema.
#   6. start the release on the idle colour next to the serving one; wait for health
#   7. healthy   -> drain the old colour (its /up turns 503, the edge's health check
#                   moves traffic to the new one), copy its storage onto the shared
#                   volumes once (D227/D254: no overwrite, verified, marker), then
#                   stop it gracefully. A failed copy keeps it running and blocks
#                   deploys until `deploy.sh --migrate-storage` succeeds.
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

# `deploy.sh --migrate-storage`: finish a D227 storage migration that a previous deploy could not
# complete (the old colour was left running on purpose; see migrate_d227 below).
mode=deploy
if [ "${1:-}" = "--migrate-storage" ]; then
    mode=migrate-storage
    set -- "$(cat "$APP_DIR/.deployed-tag" 2>/dev/null || true)"
fi

tag="${1:-}"
if ! [[ "$tag" =~ ^v[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?$ || "$tag" =~ ^[0-9a-f]{40}$ ]]; then
    echo "usage: deploy.sh <tag>   (vX.Y.Z or a full 40-char commit SHA; never 'latest')" >&2
    exit 2
fi

cd "$APP_DIR"
[ -f .env ] || die ".env missing in $APP_DIR"
if [ ! -f compose.prod.yml ] || [ ! -f compose.data.yml ] || [ ! -f compose.render.yml ]; then
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

# D227 / D254: the pre-D227 colours keep the template images and private files in their own
# container layer. Before such a colour is removed, its storage/app is copied onto the shared
# volumes. A failed copy leaves that colour RUNNING (its files are the only copy) and records it
# here; until `deploy.sh --migrate-storage` finishes the copy, no deploy may run: a new deploy
# would recreate that colour and destroy its container layer.
if [ "$mode" = deploy ] && [ -f .d227-pending ]; then
    die "a storage migration is pending for colour $(cat .d227-pending); run ./deploy.sh --migrate-storage first (see deploy.log)"
fi

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

# migrate_d227 <old colour> <helper image>: copy the old colour's /app/storage/app onto the shared
# volumes (ventmail-storage, with ventmail-storage-public nested at public/), NEVER overwriting a
# file the new colour already wrote, preserving owner and mode (root helper, `tar -p`, `cp -p`); verify every copied file (present; same size unless it pre-existed); write the
# `.d227-migrated` marker. Idempotent: with the marker present it does nothing. Non-zero on any
# failure (the caller then keeps the colour running).
migrate_d227() {
    local old="$1" helper="$2" cid
    local mounts=(-v ventmail-storage:/dst -v ventmail-storage-public:/dst/public)

    if docker run --rm --label vm.step=d227-marker-check --user 0 --entrypoint sh "${mounts[@]}" "$helper" -c 'test -f /dst/.d227-migrated'; then
        log "storage: D227 migration already done (marker present)"
        return 0
    fi

    cid="$(docker ps -aq --filter "label=com.docker.compose.project=ventmail-$old" --filter "label=com.docker.compose.service=app" | head -1)"
    if [ -z "$cid" ]; then
        log "storage: colour $old has no app container; nothing to migrate"
        return 0
    fi

    # 1. copy: the old container's storage/app as a tar stream into a staging dir on the volume,
    #    then into place without overwriting; the files that already existed are listed first.
    docker cp "$cid:/app/storage/app" - | docker run --rm -i --label vm.step=d227-copy --user 0 --entrypoint sh "${mounts[@]}" "$helper" -c '
        set -eu
        if [ -d /dst/.d227-staging ]; then rm -r /dst/.d227-staging; fi
        mkdir -p /dst/.d227-staging
        find /dst -path /dst/.d227-staging -prune -o -type f -print > /dst/.d227-staging/.preexisting
        tar -x -p -C /dst/.d227-staging -f -
        # Entry by entry (busybox `cp -n` skips whole existing directories, `tar -k` fails on them):
        # a missing directory is created with the source owner/mode, a missing file copied with
        # `cp -p`; anything that already exists (the new colour wrote it) is left untouched.
        cd /dst/.d227-staging/app
        find . -mindepth 1 -type d | sort | while IFS= read -r d; do
            if [ ! -e "/dst/$d" ]; then
                mkdir "/dst/$d"; chown "$(stat -c %u:%g "$d")" "/dst/$d"; chmod "$(stat -c %a "$d")" "/dst/$d"
            fi
        done
        find . -type f | while IFS= read -r f; do
            if [ ! -e "/dst/$f" ]; then cp -p "$f" "/dst/$f"; fi
        done
    ' || return 1

    # 2. verify: every staged file is in place; a newly copied file has the staged size.
    docker run --rm --label vm.step=d227-verify --user 0 --entrypoint sh "${mounts[@]}" "$helper" -c '
        set -eu
        cd /dst/.d227-staging/app
        files=0; bytes=0; bad=0
        find . -type f > /tmp/staged
        while IFS= read -r f; do
            rel="${f#./}"; files=$((files + 1))
            size="$(stat -c %s "$f")"; bytes=$((bytes + size))
            if [ ! -f "/dst/$rel" ]; then echo "missing: $rel" >&2; bad=$((bad + 1)); continue; fi
            if ! grep -qxF "/dst/$rel" /dst/.d227-staging/.preexisting && [ "$(stat -c %s "/dst/$rel")" != "$size" ]; then
                echo "size mismatch: $rel" >&2; bad=$((bad + 1))
            fi
        done < /tmp/staged
        [ "$bad" -eq 0 ] || { echo "$bad file(s) failed verification" >&2; exit 1; }
        printf "migrated=%s files=%s bytes=%s\n" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$files" "$bytes" > /dst/.d227-migrated
        chown 10001:10001 /dst/.d227-migrated
        cd /
        rm -r /dst/.d227-staging
        echo "files=$files bytes=$bytes"
    ' || return 1

    log "storage: D227 migration from $old verified, marker written"
}

# retire <old colour> <old tag> <helper image>: migrate the storage, then stop the colour. A
# failed migration keeps the colour running and blocks further deploys (.d227-pending).
retire() {
    local old="$1" old_tag="$2" helper="$3"

    if ! migrate_d227 "$old" "$helper"; then
        printf '%s\n' "$old" >.d227-pending
        die "storage migration from $old FAILED; $old keeps running (drained) and holds the only copy of its files. Fix the cause, then run ./deploy.sh --migrate-storage (do NOT deploy again before that)"
    fi

    colour "$old" "$old_tag" down --remove-orphans || log "warning: could not stop $old; stop it by hand"
    rm -f .d227-pending
    log "retired $old ($old_tag)"
}

if [ "$mode" = migrate-storage ]; then
    [ -f .d227-pending ] || die "no storage migration is pending"
    pending="$(cat .d227-pending)"
    [[ "$pending" =~ ^(blue|green)$ ]] || die ".d227-pending is invalid"
    retire "$pending" "$tag" "$IMAGE:$tag"
    log "storage migration finished; deploys are unblocked"
    exit 0
fi
render() { docker compose -p ventmail-render -f compose.render.yml "$@"; }
ops() { colour ops "$tag" --profile ops run --rm --no-deps -T ops artisan "$@"; }

log "deploying $tag to $next (serving: ${previous:-nothing} on ${active:-no colour})"

# 1. pull
docker pull "$IMAGE:$tag" >/dev/null || die "cannot pull $IMAGE:$tag; nothing changed"
log "pulled $(docker image inspect --format '{{index .RepoDigests 0}}' "$IMAGE:$tag" 2>/dev/null || echo "$IMAGE:$tag")"

# 2. the data stack must exist before any colour or one-off (they join its network).
data up -d --wait --wait-timeout "$WAIT_TIMEOUT" >/dev/null || die "data stack (Redis) did not become healthy; nothing changed"
# The PDF renderer (D238): colour-independent Gotenberg sidecar on the internal `ventmail-render`
# network, which the app and worker colours join. It must be healthy before a colour starts.
render up -d --wait --wait-timeout "$WAIT_TIMEOUT" >/dev/null || die "render stack (Gotenberg) did not become healthy; nothing changed"
# The shared storage volumes (D227): every colour and one-off mounts them. `volume create` is
# idempotent (an existing volume is kept as is); nothing here ever removes them.
for volume in ventmail-storage ventmail-storage-public; do
    docker volume create "$volume" >/dev/null || die "cannot create the $volume volume; nothing changed"
done

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
    retire "$active" "${previous:-$tag}" "$IMAGE:$tag"
fi

log "deployed $tag on $next"
