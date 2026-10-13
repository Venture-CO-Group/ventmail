#!/usr/bin/env bash
# The PDF-renderer contract environment (v1.1 module 8, review RS3/S2): the REAL
# deploy/compose.render.yml (same image digest, flags and hardening: internal network,
# read-only root, tmpfs /tmp + /home/gotenberg, no capabilities) plus a test-only ingress
# proxy (nginx stream, BSD) that publishes 127.0.0.1:3000 -> gotenberg:3000. The proxy is the
# only way in: an `internal: true` network is not reachable from the host on every platform,
# and Gotenberg itself keeps no egress.
#
#   deploy/tests/gotenberg-up.sh      start, wait for /health on 127.0.0.1:3000
#   deploy/tests/gotenberg-up.sh down remove the probe and the render stack
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
PROXY_IMAGE="nginx:1.30.4-alpine@sha256:dc5069ad14f19660b141b21236140b91656bf89bbc3e2417c70ae650cd66104c"
render() { docker compose -p ventmail-render -f "$HERE/../compose.render.yml" "$@"; }

if [ "${1:-}" = down ]; then
    docker rm -f vm-render-probe >/dev/null 2>&1 || true
    render down >/dev/null 2>&1 || true
    exit 0
fi

render up -d --wait --wait-timeout 120
docker rm -f vm-render-probe >/dev/null 2>&1 || true
docker create --name vm-render-probe -p 127.0.0.1:3000:3000 --read-only \
    --tmpfs /tmp --tmpfs /var/cache/nginx --tmpfs /var/run --entrypoint sh "$PROXY_IMAGE" \
    -c 'printf "events {}\nstream { server { listen 3000; proxy_pass gotenberg:3000; } }\npid /tmp/nginx.pid;\n" > /tmp/nginx.conf && exec nginx -c /tmp/nginx.conf -g "daemon off;"' >/dev/null
docker network connect ventmail-render vm-render-probe
docker start vm-render-probe >/dev/null

for _ in $(seq 1 30); do
    if curl -fs http://127.0.0.1:3000/health >/dev/null; then
        echo "gotenberg ready on http://127.0.0.1:3000 (internal network, via probe)"
        exit 0
    fi
    sleep 2
done

docker logs vm-render-probe >&2 || true
render logs >&2 || true
echo "gotenberg did not become reachable" >&2
exit 1
