FROM php:8.5-fpm-bookworm

ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip bash \
    && install-php-extensions \
        intl \
        opcache \
        pdo_pgsql \
        zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_MEMORY_LIMIT=-1 \
    PATH="/app/bin:/app/vendor/bin:${PATH}"

WORKDIR /app

RUN groupadd -g 1000 app \
    && useradd -m -u 1000 -g app -s /bin/bash app \
    && chown -R app:app /app

USER app

EXPOSE 9000
CMD ["php-fpm", "-F"]
