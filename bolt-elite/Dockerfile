FROM php:8.3.32-apache

# pdo_sqlite / sqlite3 ship with php:8.3-apache - no extra extension build
# needed (building extensions here can leave the image with two MPMs loaded).
COPY . /var/www/html/

RUN mkdir -p /var/www/html/data/config /var/www/html/data/cache \
 && printf 'Require all denied\n' > /var/www/html/data/.htaccess \
 && sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf \
 && printf 'ServerName localhost\nServerTokens Prod\n' >> /etc/apache2/apache2.conf \
 && chown -R www-data:www-data /var/www/html/data \
 && chmod 775 /var/www/html/data \
 && chmod +x /var/www/html/entrypoint.sh \
 && cp /var/www/html/entrypoint.sh /usr/local/bin/entrypoint.sh

EXPOSE 8080
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["/usr/local/bin/entrypoint.sh"]
