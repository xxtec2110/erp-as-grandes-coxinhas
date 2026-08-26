FROM node:22-bookworm-slim AS frontend
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm install --ignore-scripts --include=optional \
    && npm install --no-save --ignore-scripts \
        @rolldown/binding-linux-x64-gnu@1.2.3 \
        lightningcss-linux-x64-gnu@1.33.0 \
        @tailwindcss/oxide-linux-x64-gnu@4.3.3
COPY resources ./resources
COPY vite.config.js ./
RUN npm run build

FROM composer:2 AS vendor
WORKDIR /app
COPY . .
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader --ignore-platform-reqs

FROM php:8.3-apache
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN apt-get update \
    && apt-get install -y --no-install-recommends $PHPIZE_DEPS curl libicu-dev libonig-dev libpq-dev libzip-dev unzip \
    && docker-php-ext-install intl mbstring opcache pdo_pgsql pgsql zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get purge -y --auto-remove $PHPIZE_DEPS \
    && a2enmod rewrite headers \
    && sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /var/www/html
COPY --chown=www-data:www-data . .
COPY --from=vendor --chown=www-data:www-data /app/vendor ./vendor
COPY --from=frontend --chown=www-data:www-data /app/public/build ./public/build
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY --chown=root:root docker/entrypoint.sh /usr/local/bin/erp-entrypoint
RUN chmod +x /usr/local/bin/erp-entrypoint \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && composer check-platform-reqs --no-dev
EXPOSE 80
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 CMD curl -fsS http://127.0.0.1/up || exit 1
ENTRYPOINT ["erp-entrypoint"]
CMD ["apache2-foreground"]
