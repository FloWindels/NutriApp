# Mavi'oh — API Laravel (PHP-FPM). Construite depuis la racine du dépôt :
#   docker compose build api
# La même image sert l'API (php-fpm) et le planificateur (artisan schedule:work).

FROM composer:2 AS vendor
WORKDIR /app
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-reqs
COPY backend/ ./
RUN composer dump-autoload --optimize --no-dev --no-scripts

FROM php:8.3-fpm-alpine AS api

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_pgsql intl zip bcmath opcache pcntl \
 && apk add --no-cache postgresql-client

COPY docker/api/php.ini /usr/local/etc/php/conf.d/zz-mavioh.ini
COPY docker/api/www.conf /usr/local/etc/php-fpm.d/zz-mavioh.conf
COPY docker/api/entrypoint.sh /usr/local/bin/mavioh-entrypoint

WORKDIR /var/www/html
COPY --from=vendor --chown=www-data:www-data /app ./
RUN php artisan package:discover --ansi \
 && mkdir -p storage/framework/llm storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache \
 && chmod +x /usr/local/bin/mavioh-entrypoint

ENTRYPOINT ["mavioh-entrypoint"]
CMD ["php-fpm"]

# Nginx devant PHP-FPM : seuls les fichiers de public/ lui sont utiles.
FROM nginx:1.27-alpine AS api-nginx
COPY docker/nginx/api.conf /etc/nginx/conf.d/default.conf
COPY docker/nginx/mavioh-common.conf docker/nginx/mavioh-php.conf /etc/nginx/snippets/
COPY --from=vendor /app/public /var/www/html/public
