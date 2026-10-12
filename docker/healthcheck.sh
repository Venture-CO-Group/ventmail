#!/bin/sh
# Container healthcheck; the role was recorded by the entrypoint.
set -eu

case "$(cat /tmp/ventmail-role 2>/dev/null || echo unknown)" in
    app)
        SCRIPT_NAME=/fpm-ping SCRIPT_FILENAME=/fpm-ping REQUEST_METHOD=GET \
            cgi-fcgi -bind -connect /run/php/fpm.sock | grep -q pong
        ;;
    web)
        # Full path through Caddy -> php-fpm -> Laravel. Production only accepts
        # the trusted hosts, so send the app's own.
        host="$(printf '%s' "${APP_URL:-}" | sed -E 's#^https?://##; s#[/:].*$##')"
        wget -q -O /dev/null --header "Host: ${host:-localhost}" http://127.0.0.1:8080/up
        ;;
    worker)
        php artisan horizon:status | grep -qi running
        ;;
    scheduler)
        pgrep -f 'schedule:work' >/dev/null
        ;;
    *)
        exit 0
        ;;
esac
