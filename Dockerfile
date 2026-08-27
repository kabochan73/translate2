# syntax=docker/dockerfile:1

ARG PHP_VERSION=8.4

########################################
# base: php + extensions shared by all stages
########################################
FROM php:${PHP_VERSION}-fpm-alpine AS base
RUN apk add --no-cache bash curl gettext \
    && curl -sSLf -o /usr/local/bin/install-php-extensions \
       https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions \
    && chmod +x /usr/local/bin/install-php-extensions \
    && install-php-extensions pdo_pgsql pgsql redis intl zip opcache bcmath pcntl
WORKDIR /var/www/html

########################################
# dev: used by docker-compose for local development
########################################
FROM base AS dev
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
RUN apk add --no-cache nodejs npm \
    && install-php-extensions xdebug
COPY docker/php/php.dev.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts
COPY package.json package-lock.json* ./
RUN npm install
COPY . .
RUN composer run-script post-autoload-dump --no-interaction
EXPOSE 8000 5173
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]

# NOTE: production ステージ(nginx + supervisord, Railway デプロイ用)はフェーズ7で追加する
