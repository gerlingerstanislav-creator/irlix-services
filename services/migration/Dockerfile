FROM composer:2 AS app
WORKDIR /app
RUN composer create-project laravel/laravel:^12.0 . --no-dev --prefer-dist --no-interaction
COPY app /app/app
COPY bootstrap/app.php /app/bootstrap/app.php
COPY bootstrap/providers.php /app/bootstrap/providers.php
COPY config/migration.php /app/config/migration.php
COPY database/migrations /app/database/migrations
COPY routes/api.php /app/routes/api.php
COPY routes/snapshot-delete.php /app/routes/snapshot-delete.php
COPY routes/console.php /app/routes/console.php
COPY tests /app/tests
COPY docker-entrypoint.sh /app/docker-entrypoint.sh
RUN find /app/app /app/routes /app/bootstrap /app/config /app/database/migrations -name '*.php' -print0 | xargs -0 -n1 php -l \
    && php /app/tests/safety_smoke.php \
    && chmod +x /app/docker-entrypoint.sh

FROM php:8.4-cli-alpine
RUN apk add --no-cache postgresql-dev sqlite-dev oniguruma-dev curl-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pdo_sqlite mbstring curl
WORKDIR /app
COPY --from=app /app /app
ENV APP_ENV=production APP_DEBUG=false LOG_CHANNEL=stderr
ENTRYPOINT ["/app/docker-entrypoint.sh"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
