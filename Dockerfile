FROM php:8.3.32-apache

# SQLite ships with php:8.3 (pdo_sqlite/sqlite3); libzip-dev for the zip ext
# (harmless extra). mbstring is included in the CLI/apache defaults.
RUN apt-get update && apt-get install -y --no-install-recommends libzip-dev \
    && docker-php-ext-install zip \
    && rm -rf /var/lib/apt/lists/*

COPY . /var/www/html/

# robots.txt: keep crawlers off
RUN printf 'User-agent: *\\nDisallow: /\\n' > /var/www/html/public/robots.txt

# data/ is excluded from the image (local test data) — create the writable
# dirs now and hand ownership to www-data (Apache) so the app can persist.
RUN mkdir -p /var/www/html/data/config /var/www/html/data/alerts \
             /var/www/html/data/jobs /var/www/html/data/results \
    && sed -i 's|/var/www/html|/var/www/html/public|g' /etc/apache2/sites-available/000-default.conf \
    && chmod -R a+rx /var/www/html/public \
    && chown -R www-data:www-data /var/www/html \
    && chmod 775 /var/www/html/data \
    && chmod +x /var/www/html/entrypoint.sh \
    && cp /var/www/html/entrypoint.sh /usr/local/bin/entrypoint.sh

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["/usr/local/bin/entrypoint.sh"]
