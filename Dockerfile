# Dependencias PHP
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts

# Imagen final: Apache + PHP 8.5 con PostgreSQL
FROM php:8.5-apache

RUN apt-get update \
 && apt-get install -y --no-install-recommends libpq-dev \
 && docker-php-ext-install pdo_pgsql pcntl \
 && rm -rf /var/lib/apt/lists/* \
 && (a2dismod -f mpm_event mpm_worker || true) \
 && a2enmod mpm_prefork rewrite headers \
 && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
 && sed -ri 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf \
 && printf '<Directory /var/www/html/public>\n  AllowOverride All\n  Require all granted\n</Directory>\nServerTokens Prod\nServerSignature Off\n' > /etc/apache2/conf-available/app.conf \
 && a2enconf app

WORKDIR /var/www/html
COPY --from=vendor /app/vendor ./vendor
COPY . .
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh

RUN mkdir -p storage/logs storage/cache storage/jobs/failed cache \
 && chown -R www-data:www-data storage cache \
 && chmod +x /usr/local/bin/entrypoint.sh scheduler point

ENV APP_ENV=production
EXPOSE 8080
CMD ["entrypoint.sh"]
