FROM composer:2 AS app
WORKDIR /app
RUN composer create-project laravel/laravel:^12.0 . --no-dev --prefer-dist --no-interaction \
    && sed -i "s/'search_path' => 'public'/'search_path' => env('DB_SEARCH_PATH', 'public')/" /app/config/database.php
RUN echo onboarding-v1 > /app/.irlix-build-generation \
    && mkdir -p /app/app/Support /app/app/Http/Middleware
COPY app/Http/Middleware/KeycloakBearer.php /app/app/Http/Middleware/KeycloakBearer.php
COPY app/Http/Middleware/EmployeesAuthorization.php /app/app/Http/Middleware/EmployeesAuthorization.php
COPY app/Http/Middleware/EmployeeOnboarding.php /app/app/Http/Middleware/EmployeeOnboarding.php
COPY app/Support/EmployeesAccess.php /app/app/Support/EmployeesAccess.php
COPY app/Support/EmployeeOnboardingService.php /app/app/Support/EmployeeOnboardingService.php
COPY routes/api.php /app/routes/api.php
COPY routes/access.php /app/routes/access.php
COPY routes/onboarding.php /app/routes/onboarding.php
COPY routes/web.php /app/routes/web.php
COPY bootstrap/app.php /app/bootstrap/app.php
COPY database/migrations /app/database/migrations
RUN php -l /app/routes/api.php \
    && php -l /app/routes/access.php \
    && php -l /app/routes/onboarding.php \
    && php -l /app/app/Http/Middleware/KeycloakBearer.php \
    && php -l /app/app/Http/Middleware/EmployeesAuthorization.php \
    && php -l /app/app/Http/Middleware/EmployeeOnboarding.php \
    && php -l /app/app/Support/EmployeesAccess.php \
    && php -l /app/app/Support/EmployeeOnboardingService.php \
    && php -l /app/bootstrap/app.php

FROM php:8.4-cli-alpine
RUN apk add --no-cache postgresql-dev \
    && docker-php-ext-install pdo_pgsql
WORKDIR /app
COPY --from=app /app /app
ENV APP_ENV=production APP_DEBUG=false LOG_CHANNEL=stderr
EXPOSE 8000
CMD ["php", "-S", "0.0.0.0:8000", "-t", "public", "public/index.php"]
