FROM composer:2 AS app
WORKDIR /app
RUN composer create-project laravel/laravel:^12.0 . --no-dev --prefer-dist --no-interaction \
    && sed -i "s/'search_path' => 'public'/'search_path' => env('DB_SEARCH_PATH', 'public')/" /app/config/database.php
COPY app /app/app
COPY routes /app/routes
COPY bootstrap/app.php /app/bootstrap/app.php
COPY database/migrations /app/database/migrations
COPY bin /app/bin
COPY tests /app/tests
RUN apk add --no-cache sqlite-dev && docker-php-ext-install -j"$(nproc)" pdo_sqlite
RUN php /app/tests/assignment_reconciliation_smoke.php
RUN find /app/app /app/routes /app/bootstrap /app/database/migrations -name '*.php' -print0 | xargs -0 -n1 php -l

FROM php:8.4-cli-alpine
RUN apk add --no-cache postgresql-dev oniguruma-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql mbstring
WORKDIR /app
COPY --from=app /app /app
ENV APP_ENV=production APP_DEBUG=false LOG_CHANNEL=stderr
EXPOSE 8000
CMD ["php", "-S", "0.0.0.0:8000", "-t", "public", "public/index.php"]
