FROM php:8.6-cli

# The stock image lacks pcntl, sockets and mbstring (the event loop driver
# tests need the first two for signals and socket options).
RUN apt-get update \
    && apt-get install -y --no-install-recommends libonig-dev \
    && docker-php-ext-install -j"$(nproc)" pcntl sockets mbstring \
    && rm -rf /var/lib/apt/lists/*

# Assertions on, as the suite expects (the image has no php.ini, so these are the defaults already; the file makes it
# explicit and survives a base image that ships one).
RUN printf 'zend.assertions=1\nassert.exception=1\n' > "$PHP_INI_DIR/conf.d/assertions.ini"

WORKDIR /app
