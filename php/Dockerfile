ARG PHP_BASE_IMAGE=php:8.3.36-fpm-alpine
FROM ${PHP_BASE_IMAGE}

# Reject stale locally supplied base images as well as vulnerable cached tags.
RUN php -r 'exit(PHP_VERSION_ID >= 80336 && PHP_VERSION_ID < 80400 ? 0 : 1);'

# Install system dependencies and PHP extensions
RUN set -eux; \
    apk add --no-cache \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
        oniguruma-dev \
        icu-dev \
        bash \
        zlib-dev \
        curl-dev \
        libzip-dev; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" \
        pdo \
        pdo_mysql \
        gd \
        intl \
        zip

# Install composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# List contents to verify composer installation
RUN ls -l /usr/local/bin

# Copy custom PHP configuration
COPY php/custom.ini /usr/local/etc/php/conf.d/custom.ini

# Set working directory
WORKDIR /var/www/html

# Copy composer files
COPY composer.json composer.lock* /var/www/html/

# Copy entrypoint script
COPY php/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Set entrypoint
ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
