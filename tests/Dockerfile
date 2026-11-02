FROM php:8.3-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip \
    && pecl install redis-6.1.0 \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /app
