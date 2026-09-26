#!/bin/bash
set -e

PORT="${PORT:-8080}"

# Apache listens on the platform-provided port.
sed -ri "s/^Listen 80$/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/:80>/:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# Persist app data on the mounted volume when one is provided.
mkdir -p /var/www/html/data
chown -R www-data:www-data /var/www/html/data || true

echo "Bolt Elite Redirect listening on ${PORT}"
exec apache2-foreground
