#!/bin/sh
# Role dispatcher for the ventMail image. Migrations never run here: they run as
# a one-off step in deploy.sh, so app/worker/scheduler cannot race each other.
set -eu

role="${1:-app}"
printf '%s\n' "$role" > /tmp/ventmail-role

# D227: storage/app is the shared `ventmail-storage` volume (compose.prod.yml). A fresh volume is
# populated from the image (owned by 10001); a volume with the wrong owner must stop the
# container visibly instead of failing every upload, import and report later.
ensure_storage() {
    mkdir -p storage/app/private storage/app/public
    for dir in storage/app/private storage/app/public; do
        if [ ! -w "$dir" ]; then
            echo "entrypoint: $dir is not writable by $(id -u); fix the ventmail-storage volume ownership" >&2
            exit 1
        fi
    done
}

warm_caches() {
    ensure_storage
    # A failure here must stop the container: a half-configured sender that keeps
    # serving is worse than one that is visibly down (deploy.sh discards it).
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    php artisan event:cache
}

case "$role" in
    app)
        warm_caches
        exec php-fpm
        ;;
    worker)
        warm_caches
        # The inbound MIME parse alone needs ~100 MB for a 25 MB message; at the
        # 256M default a worker would FATAL (not a Throwable: no catch runs).
        exec php -d memory_limit=512M artisan horizon
        ;;
    worker-imports)
        warm_caches
        # Imports only (own container and cgroup, see compose.prod.yml): the send Horizon is never
        # starved or OOM-killed by a heavy upload. Two import supervisors at 512M plus the master.
        exec php -d memory_limit=512M artisan horizon --environment=imports
        ;;
    scheduler)
        warm_caches
        exec php artisan schedule:work
        ;;
    web)
        : "${TRUSTED_PROXIES:?TRUSTED_PROXIES must name the edge proxy}"
        exec caddy run --config /etc/caddy/Caddyfile --adapter caddyfile
        ;;
    *)
        exec "$@"
        ;;
esac
