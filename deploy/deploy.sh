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
# volumes (ventmail-storage, with ventmail-storage-public nested at public/). D254, hardened in the
# module 8 security review round 2 (D277-D281):
#   - the source container is looked up strictly: a failing `docker ps` is an error, and a colour
#     that has containers but no `app` container is an error too (never "nothing to migrate");
#   - free space is checked first: the volume needs >= D227_MIN_FREE_FACTOR x the source (staging +
#     copy) plus a margin, otherwise it fails BEFORE anything is staged;
#   - `.d227-preexisting` (what the new colour had already written) is recorded ONCE, by the first
#     attempt, outside the staging dir, and never recomputed: a retry cannot mistake a file of its
#     own failed attempt for one the new colour wrote;
#   - every file is copied atomically (`<name>.d227-part`, then `mv`); a retry first removes such
#     leftovers. A file that exists with different content is never overwritten: the old version is
#     kept under `.d227-conflicts/` and logged; skipped symlinks are counted;
#   - verification compares every migrated file byte for byte (`cmp`); a pre-existing or conflicting
#     file must exist; no `.d227-part` may be left. Only then is the `.d227-migrated` marker written.
# A failure removes the staging dir at once (space) and returns non-zero; the caller keeps the old
# colour (stopped) and blocks deploys until `deploy.sh --migrate-storage` succeeds.
D227_MIN_FREE_FACTOR="${D227_MIN_FREE_FACTOR:-2}"
D227_MIN_FREE_MARGIN_KB="${D227_MIN_FREE_MARGIN_KB:-65536}"

migrate_d227() {
    local old="$1" helper="$2" all cid mounted source_kb entries free_kb need_kb
    local mounts=(-v ventmail-storage:/dst -v ventmail-storage-public:/dst/public)

    if docker run --rm --label vm.step=d227-marker-check --user 0 --entrypoint sh "${mounts[@]}" "$helper" -c 'test -f /dst/.d227-migrated'; then
        log "storage: D227 migration already done (marker present)"
        return 0
    fi

    all="$(docker ps -aq --filter "label=com.docker.compose.project=ventmail-$old")" || { log "storage: cannot list the containers of $old"; return 1; }
    cid="$(docker ps -aq --filter "label=com.docker.compose.project=ventmail-$old" --filter "label=com.docker.compose.service=app")" || { log "storage: cannot look up the app container of $old"; return 1; }
    cid="$(head -1 <<<"$cid")"
    if [ -z "$cid" ]; then
        if [ -n "$all" ]; then
            log "storage: colour $old has containers but no app container; refusing to guess"
            return 1
        fi
        log "storage: colour $old has no containers; nothing to migrate"
        return 0
    fi

    # A colour that already runs on the shared volume (a D227 release) has nothing in its own layer:
    # never copy the volume onto itself. NO marker is written here (review round 4 B2, D293): the
    # marker is global, and the OTHER colour may still be a pre-D227 one holding the only copy.
    if app_on_volume "$cid"; then
        log "storage: colour $old already uses the shared volume; nothing to copy (marker NOT written)"
        return 0
    fi

    # 0. the source: size (tar stream) against the volume's free space, and its entry count - the
    #    verification compares the staged tree against it, so a stream cut at a header boundary
    #    (which busybox tar extracts without error) cannot pass (review round 3 N4, D289).
    source_kb="$(docker cp "$cid:/app/storage/app" - | wc -c | awk '{ print int(($1 + 1023) / 1024) }')" || { log "storage: cannot measure the source"; return 1; }
    entries="$(docker cp "$cid:/app/storage/app" - | docker run --rm -i --label vm.step=d227-count --entrypoint sh "$helper" -c 'tar -t -f - | wc -l')" || { log "storage: cannot count the source entries"; return 1; }
    entries="$(tr -dc '0-9' <<<"$entries")"
    free_kb="$(docker run --rm --label vm.step=d227-space --user 0 --entrypoint sh "${mounts[@]}" "$helper" -c 'df -Pk /dst | awk "NR == 2 { print \$4 }"')" || { log "storage: cannot measure the free space"; return 1; }
    need_kb=$((source_kb * D227_MIN_FREE_FACTOR + D227_MIN_FREE_MARGIN_KB))
    if ! [[ "$free_kb" =~ ^[0-9]+$ ]] || [ "$free_kb" -lt "$need_kb" ]; then
        log "storage: not enough free space on ventmail-storage (free ${free_kb:-?} KB, need $need_kb KB for $source_kb KB of files)"
        return 1
    fi

    # 1. copy: stage the tar stream on the volume, then copy entry by entry. Atomic no-clobber: the
    #    file is written as `<name>.d227-part` and HARD-LINKED to its final name (`ln` fails if the
    #    name exists - no check-then-move race); an existing name goes to the conflict branch.
    docker cp "$cid:/app/storage/app" - | docker run --rm -i --label vm.step=d227-copy --user 0 --entrypoint sh "${mounts[@]}" "$helper" -c '
        set -eu
        ok=0
        trap '"'"'if [ "$ok" != 1 ] && [ -d /dst/.d227-staging ]; then rm -r /dst/.d227-staging; fi'"'"' EXIT
        if [ -d /dst/.d227-staging ]; then rm -r /dst/.d227-staging; fi
        if [ ! -f /dst/.d227-preexisting ]; then
            find /dst -path /dst/.d227-conflicts -prune -o -type f -print > /dst/.d227-preexisting.tmp
            mv /dst/.d227-preexisting.tmp /dst/.d227-preexisting
        fi
        mkdir -p /dst/.d227-staging
        tar -x -p -C /dst/.d227-staging -f -
        cd /dst/.d227-staging/app
        echo "skipped symlinks: $(find . -type l | wc -l)"
        find . -mindepth 1 -type d | sort | while IFS= read -r d; do
            if [ ! -e "/dst/$d" ]; then
                mkdir "/dst/$d"; chown "$(stat -c %u:%g "$d")" "/dst/$d"; chmod "$(stat -c %a "$d")" "/dst/$d"
            fi
        done
        conflicts=0
        find . -type f > /tmp/staged
        while IFS= read -r f; do
            part="/dst/$f.d227-part"
            # Only OUR leftover from an interrupted attempt is removed - never a live file that
            # merely ends in .d227-part (it would be on the pre-existing list).
            if [ -e "$part" ] && ! grep -qxF "$part" /dst/.d227-preexisting; then rm "$part"; fi
            # A LIVE file already carries our temp name (review round 4 N1, D296): never overwrite or
            # delete it - the old version goes to the conflict branch instead.
            if [ -e "$part" ]; then
                mkdir -p "/dst/.d227-conflicts/$(dirname "$f")"
                cp -p "$f" "/dst/.d227-conflicts/$f.d227-tmp"
                mv "/dst/.d227-conflicts/$f.d227-tmp" "/dst/.d227-conflicts/$f"
                cmp -s "$f" "/dst/.d227-conflicts/$f"
                echo "conflict kept (temp name taken): $f" >&2
                conflicts=$((conflicts + 1))
                continue
            fi
            if [ ! -e "/dst/$f" ]; then
                cp -p "$f" "$part"
                if ln "$part" "/dst/$f" 2>/dev/null; then rm "$part"; continue; fi
                rm "$part"
            fi
            if ! cmp -s "$f" "/dst/$f"; then
                mkdir -p "/dst/.d227-conflicts/$(dirname "$f")"
                cp -p "$f" "/dst/.d227-conflicts/$f.d227-part"
                mv "/dst/.d227-conflicts/$f.d227-part" "/dst/.d227-conflicts/$f"
                cmp -s "$f" "/dst/.d227-conflicts/$f"
                echo "conflict kept: $f" >&2
                conflicts=$((conflicts + 1))
            fi
        done < /tmp/staged
        echo "conflicts: $conflicts"
        ok=1
    ' || return 1

    # 2. verify: the staged tree has the source's entry count; a migrated file is byte-identical; a
    #    pre-existing one exists; a conflict copy is byte-identical; no part file of ours is left.
    docker run --rm -e "D227_ENTRIES=$entries" --label vm.step=d227-verify --user 0 --entrypoint sh "${mounts[@]}" "$helper" -c '
        set -eu
        ok=0
        trap '"'"'if [ "$ok" != 1 ] && [ -d /dst/.d227-staging ]; then rm -r /dst/.d227-staging; fi'"'"' EXIT
        staged_entries="$(find /dst/.d227-staging/app | wc -l)"
        if [ "$staged_entries" != "${D227_ENTRIES:-}" ]; then
            echo "entry count mismatch: source ${D227_ENTRIES:-?}, staged $staged_entries" >&2; exit 1
        fi
        cd /dst/.d227-staging/app
        files=0; bytes=0; bad=0
        find . -type f > /tmp/staged
        while IFS= read -r f; do
            rel="${f#./}"; files=$((files + 1))
            bytes=$((bytes + $(stat -c %s "$f")))
            if [ ! -f "/dst/$rel" ]; then echo "missing: $rel" >&2; bad=$((bad + 1)); continue; fi
            if [ -e "/dst/$rel.d227-part" ] && ! grep -qxF "/dst/$rel.d227-part" /dst/.d227-preexisting; then
                echo "partial file left: $rel" >&2; bad=$((bad + 1))
            fi
            if [ -f "/dst/.d227-conflicts/$rel" ]; then
                cmp -s "$f" "/dst/.d227-conflicts/$rel" || { echo "conflict copy mismatch: $rel" >&2; bad=$((bad + 1)); }
                continue
            fi
            if grep -qxF "/dst/$rel" /dst/.d227-preexisting; then continue; fi
            if ! cmp -s "$f" "/dst/$rel"; then echo "content mismatch: $rel" >&2; bad=$((bad + 1)); fi
        done < /tmp/staged
        [ "$bad" -eq 0 ] || { echo "$bad file(s) failed verification" >&2; exit 1; }
        printf "migrated=%s files=%s bytes=%s\\n" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$files" "$bytes" > /dst/.d227-migrated
        chown 10001:10001 /dst/.d227-migrated
        cd /
        rm -r /dst/.d227-staging
        rm /dst/.d227-preexisting
        ok=1
        echo "files=$files bytes=$bytes"
    ' || return 1

    log "storage: D227 migration from $old verified, marker written"
}

# mark_d227 <helper image> <note>: write the `.d227-migrated` marker without a copy (a fresh box or
# a colour that already runs on the volume: there is no container layer to rescue).
mark_d227() {
    local helper="$1" note="$2"

    docker run --rm -e "D227_NOTE=$note" --label vm.step=d227-mark --user 0 --entrypoint sh -v ventmail-storage:/dst "$helper" -c '
        set -eu
        if [ ! -f /dst/.d227-migrated ]; then
            printf "migrated=%s %s\\n" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$D227_NOTE" > /dst/.d227-migrated.tmp
            chown 10001:10001 /dst/.d227-migrated.tmp
            mv /dst/.d227-migrated.tmp /dst/.d227-migrated
        fi
    '
}

# app_on_volume <container>: does this app container mount /app/storage/app (a D227 release)?
app_on_volume() {
    local mounted

    mounted="$(docker inspect -f '{{range .Mounts}}{{.Destination}} {{end}}' "$1")" || return 1
    [[ " $mounted " == *" /app/storage/app "* ]]
}

# d227_guard <colour> <helper image>: before a colour is (re)created, make sure it does not hold
# the only copy of pre-D227 files (review round 3 B1, D286; mount-aware since round 4 B2, D294):
#   - no containers in the project, or the marker present  -> fine;
#   - its app container already mounts the shared volume   -> fine (nothing in its layer to rescue);
#   - otherwise (a pre-D227 colour, or containers without an app container) -> stop: `up` would
#     destroy the layer. Only such a colour may be recorded in .d227-pending.
d227_guard() {
    local target="$1" helper="$2" existing cid

    existing="$(docker ps -aq --filter "label=com.docker.compose.project=ventmail-$target")" || die "cannot list the containers of $target; nothing changed"
    [ -z "$existing" ] && return 0
    if docker run --rm --label vm.step=d227-marker-check --user 0 --entrypoint sh -v ventmail-storage:/dst "$helper" -c 'test -f /dst/.d227-migrated'; then
        return 0
    fi
    cid="$(docker ps -aq --filter "label=com.docker.compose.project=ventmail-$target" --filter "label=com.docker.compose.service=app")" || die "cannot look up the app container of $target; nothing changed"
    cid="$(head -1 <<<"$cid")"
    if [ -n "$cid" ] && app_on_volume "$cid"; then
        log "storage: colour $target already runs on the shared volume; recreating it loses nothing"
        return 0
    fi
    if [ -z "$cid" ]; then
        die "colour $target has containers but no app container, and the D227 storage migration is not done; inspect it by hand (docker ps -a --filter label=com.docker.compose.project=ventmail-$target) before deploying"
    fi
    die "colour $target is a pre-D227 colour (its files live in its container layer) and the D227 storage migration is not done: recreating it would destroy them. Record it and migrate first: echo $target > .d227-pending && ./deploy.sh --migrate-storage"
}

# retire <old colour> <old tag> <helper image>: stop the (drained) colour first - its workers and
# scheduler must not keep running (duplicate scheduled jobs, new queue jobs) while it waits; a
# stopped container keeps its layer, and `docker cp` reads it - then migrate the storage, then
# remove the colour. A failed migration leaves it STOPPED (not removed) and blocks further deploys
# (.d227-pending) until `deploy.sh --migrate-storage` succeeds.
retire() {
    local old="$1" old_tag="$2" helper="$3"

    if ! colour "$old" "$old_tag" stop; then
        printf '%s\n' "$old" >.d227-pending
        die "could not stop $old; it was NOT removed. Stop it by hand (docker compose -p ventmail-$old stop), then run ./deploy.sh --migrate-storage"
    fi

    if ! migrate_d227 "$old" "$helper"; then
        printf '%s\n' "$old" >.d227-pending
        die "storage migration from $old FAILED; $old is stopped (not removed) and holds the only copy of its files. Fix the cause, then run ./deploy.sh --migrate-storage (do NOT deploy again before that)"
    fi

    colour "$old" "$old_tag" down --remove-orphans || log "warning: could not remove $old; remove it by hand"
    rm -f .d227-pending
    log "retired $old ($old_tag)"
}

if [ "$mode" = migrate-storage ]; then
    [ -f .d227-pending ] || die "no storage migration is pending"
    pending="$(cat .d227-pending)"
    [[ "$pending" =~ ^(blue|green)$ ]] || die ".d227-pending is invalid"
    # Never the SERVING colour (review round 3 N6): stopping and removing it would take the site down.
    [ "$pending" != "$(cat .active-color 2>/dev/null || true)" ] || die ".d227-pending names the serving colour ($pending); refusing. A serving colour runs this release (on the shared volume) and holds nothing to migrate: if the file is stale, remove it by hand (rm .d227-pending)"
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

# 6. start the idle colour next to the serving one. Never over a colour that may still hold the
#    only copy of pre-D227 files (an interrupted retire; review round 3 B1, D286).
d227_guard "$next" "$IMAGE:$tag"
# A FRESH box: no colour recorded AND no container (running or stopped) in either colour project.
fresh_box=0
if [ -z "$active" ]; then
    leftovers="$(docker ps -aq --filter "label=com.docker.compose.project=ventmail-blue"; docker ps -aq --filter "label=com.docker.compose.project=ventmail-green")" ||
        die "cannot list the colour containers; nothing changed"
    [ -z "$leftovers" ] && fresh_box=1
fi
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
    # B1 (D285): recorded BEFORE the drain, atomically - an interrupted retire (SIGHUP, reboot) must
    # still block further deploys; `retire` removes it only after a successful `down`.
    printf '%s\n' "$active" >.d227-pending.tmp && mv .d227-pending.tmp .d227-pending
    colour "$active" "${previous:-$tag}" exec -T web touch /tmp/ventmail-draining ||
        log "warning: could not mark $active as draining; stopping it anyway"
    sleep "$DRAIN_SECONDS"
    retire "$active" "${previous:-$tag}" "$IMAGE:$tag"
elif [ "$fresh_box" = 1 ]; then
    # A fresh box (no colour, no container before this deploy): no pre-D227 layer exists anywhere
    # (N3, D288; both projects checked since round 4, D295).
    mark_d227 "$IMAGE:$tag" "fresh-box" || log "warning: could not write the D227 marker on a fresh box"
fi

log "deployed $tag on $next"
