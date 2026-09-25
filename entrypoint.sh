#!/bin/bash
# mailforge entrypoint.
#  1) Force EXACTLY ONE Apache MPM (the php:8.3-apache base otherwise
#     crash-loops with "AH00534: More than one MPM loaded").
#  2) Bind Apache to the port the Railway proxy forwards to ($PORT).
#  3) exec the foreground Apache runner with NO extra args.
set -u
CONFDIR="${APACHE_CONFDIR:-/etc/apache2}"
ME="$CONFDIR/mods-enabled"
MA="$CONFDIR/mods-available"

rm -f "$ME"/mpm_*.load "$ME"/mpm_*.conf 2>/dev/null || true
if [ -f "$MA/mpm_prefork.load" ]; then
    ln -sf "$MA/mpm_prefork.load" "$ME/mpm_prefork.load"
    [ -f "$MA/mpm_prefork.conf" ] && ln -sf "$MA/mpm_prefork.conf" "$ME/mpm_prefork.conf"
else
    for m in mpm_event mpm_worker mpm_prefork; do
        if [ -f "$MA/$m.load" ]; then
            ln -sf "$MA/$m.load" "$ME/$m.load"
            [ -f "$MA/$m.conf" ] && ln -sf "$MA/$m.conf" "$ME/$m.conf"
            break
        fi
    done
fi

# Enable the modules we need (idempotent).
a2enmod rewrite 2>/dev/null || true
a2enmod headers 2>/dev/null || true
a2enmod php8.3 2>/dev/null || true

# Make sure the data/ tree exists and is writable at runtime.
mkdir -p /var/www/html/data/config /var/www/html/data/alerts \
         /var/www/html/data/jobs /var/www/html/data/results 2>/dev/null || true
chown -R www-data:www-data /var/www/html/data 2>/dev/null || true
chmod -R u+rwX /var/www/html/data 2>/dev/null || true

# Bind Apache to the Railway proxy port.
P="${PORT:-8080}"
if [ -f "$CONFDIR/ports.conf" ]; then
    sed -i "s/^Listen[[:space:]]\{1,\}80\b/Listen $P/" "$CONFDIR/ports.conf"
fi
if [ -d "$CONFDIR/sites-available" ]; then
    find "$CONFDIR/sites-available" -maxdepth 1 -name '*.conf' \
        -exec sed -i "s/<VirtualHost \*:80>/<VirtualHost *:$P>/" {} +
fi
echo "=== [entry] PORT=$P; vhost: ==="
grep -rnE 'VirtualHost|Listen' "$CONFDIR/ports.conf" "$CONFDIR/sites-available"/*.conf 2>/dev/null || true

exec /usr/local/bin/apache2-foreground
