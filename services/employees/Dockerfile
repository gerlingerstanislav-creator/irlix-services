FROM composer:2 AS app
WORKDIR /app
RUN composer create-project laravel/laravel:^12.0 . --no-dev --prefer-dist --no-interaction \
    && sed -i "s/'search_path' => 'public'/'search_path' => env('DB_SEARCH_PATH', 'public')/" /app/config/database.php
RUN echo identity-v1 > /app/.irlix-build-generation
COPY routes/api.php /app/routes/api.php
RUN php -l /app/routes/api.php
COPY routes/web.php /app/routes/web.php
COPY bootstrap/app.php /app/bootstrap/app.php
COPY database/migrations /app/database/migrations

FROM php:8.4-cli-alpine
RUN apk add --no-cache postgresql-dev \
    && docker-php-ext-install pdo_pgsql
WORKDIR /app
COPY --from=app /app /app
ENV APP_ENV=production APP_DEBUG=false LOG_CHANNEL=stderr
EXPOSE 8000
CMD ["php", "-S", "0.0.0.0:8000", "-t", "public", "public/index.php"]
