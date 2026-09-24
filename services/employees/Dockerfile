FROM composer:2 AS app
WORKDIR /app
RUN composer create-project laravel/laravel:^12.0 . --no-dev --prefer-dist --no-interaction
COPY routes/api.php /app/routes/api.php
COPY routes/web.php /app/routes/web.php
COPY bootstrap/app.php /app/bootstrap/app.php

FROM php:8.4-cli-alpine
RUN apk add --no-cache postgresql-dev \
    && docker-php-ext-install pdo_pgsql
WORKDIR /app
COPY --from=app /app /app
ENV APP_ENV=production APP_DEBUG=false LOG_CHANNEL=stderr
EXPOSE 8000
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
