# HTE (2026-10-16): run the HTE test suite in a throwaway image.
#   docker build -f docker/hte-test.Dockerfile -t hte-cortendesk-test . && docker run --rm hte-cortendesk-test
FROM composer:2 AS deps
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY . .
RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/testing \
      storage/framework/views storage/logs storage/app/private storage/app/public bootstrap/cache \
    && composer dump-autoload

FROM php:8.4-cli-alpine
RUN apk add --no-cache icu-libs \
    && apk add --no-cache --virtual .build icu-dev \
    && docker-php-ext-install -j"$(nproc)" bcmath intl \
    && apk del .build
WORKDIR /app
COPY --from=deps /app /app
CMD ["php", "artisan", "test", "--compact"]
