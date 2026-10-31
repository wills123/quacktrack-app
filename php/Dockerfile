# Images are pinned by digest (audit H2); doc/operations/IMAGE_PINS.md says how to update them.
ARG PHP_BASE_IMAGE=php:8.3.36-fpm-alpine@sha256:aaf1ad25fc6484613be770743d1038e98e3e7e07409d78b97410eccdf3628f75
FROM ${PHP_BASE_IMAGE}

# Reject stale locally supplied base images as well as vulnerable cached tags.
RUN php -r 'exit(PHP_VERSION_ID >= 80336 && PHP_VERSION_ID < 80400 ? 0 : 1);'

# Install system dependencies and PHP extensions
RUN set -eux; \
    apk upgrade --no-cache; \
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

# Composer from its pinned official image, as in the release image (never an installer piped from the internet)
COPY --from=composer:2.10.3@sha256:b6dcd72ca98fa19d0e5b8476de179859704891fad06dd9e0b96833d4c430a5fc /usr/bin/composer /usr/local/bin/composer

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
