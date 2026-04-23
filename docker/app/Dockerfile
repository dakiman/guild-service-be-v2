# Stage 1: Composer dependencies
FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --no-interaction \
    --no-scripts \
    --optimize-autoloader \
    --prefer-dist \
    --ignore-platform-req=ext-pcntl

# Stage 2: Production image
FROM php:8.4-fpm-alpine

# Install system dependencies
RUN apk add --no-cache \
    postgresql-dev \
    linux-headers \
    libzip-dev \
    icu-dev \
    $PHPIZE_DEPS

# Install PHP extensions
RUN docker-php-ext-install \
    pdo_pgsql \
    pcntl \
    bcmath \
    opcache \
    intl \
    zip

# Install phpredis via PECL
RUN pecl install redis && docker-php-ext-enable redis

# Optionally install Xdebug (dev only). Enable via --build-arg INSTALL_XDEBUG=true.
ARG INSTALL_XDEBUG=false
RUN if [ "$INSTALL_XDEBUG" = "true" ]; then \
        pecl install xdebug \
        && docker-php-ext-enable xdebug \
        && { \
            echo 'xdebug.mode=debug'; \
            echo 'xdebug.start_with_request=trigger'; \
            echo 'xdebug.client_host=host.docker.internal'; \
            echo 'xdebug.client_port=9003'; \
            echo 'xdebug.idekey=PHPSTORM'; \
            echo 'xdebug.discover_client_host=false'; \
            echo 'xdebug.log_level=0'; \
        } > /usr/local/etc/php/conf.d/zz-xdebug.ini; \
    fi

# Cleanup build dependencies
RUN apk del $PHPIZE_DEPS && rm -rf /var/cache/apk/*

# Copy PHP configuration
COPY docker/app/php.ini /usr/local/etc/php/conf.d/custom.ini
COPY docker/app/opcache.ini /usr/local/etc/php/conf.d/opcache.ini
COPY docker/app/www.conf /usr/local/etc/php-fpm.d/www.conf

# Set working directory
WORKDIR /var/www/html

# Copy vendor from composer stage
COPY --from=vendor /app/vendor ./vendor

# Copy application code
COPY . .

# Set permissions
RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Copy and set entrypoint
COPY docker/app/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 9000

ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]
