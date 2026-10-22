FROM phpswoole/swoole:php8.2-alpine@sha256:f12735e2b2cca3fc85ee41ff88826ca2f84eea50d6dac7ca9c48168e2bae5389

COPY scripts/verify-php-security.php /tmp/verify-php-security.php
RUN php /tmp/verify-php-security.php && rm /tmp/verify-php-security.php

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN apk --no-cache add shadow sqlite mysql-client mysql-dev mariadb-connector-c git patch supervisor redis liburing-dev liburing

# Install PHP extensions one by one with lower optimization level for ARM64 compatibility
RUN CFLAGS="-O0" install-php-extensions pcntl && \
    CFLAGS="-O0 -g0" install-php-extensions bcmath && \
    CFLAGS="-O0 -g0" install-php-extensions gd && \
    install-php-extensions zip && \
    install-php-extensions redis && \
    addgroup -S -g 1000 www && adduser -S -G www -u 1000 www && \
    (getent group redis || addgroup -S redis) && \
    (getent passwd redis || adduser -S -G redis -H -h /data redis)

WORKDIR /www

COPY .docker /
COPY . /www

ARG SOURCE_GIT_SHA=unknown
ARG BUILD_VERSION=unknown

LABEL org.opencontainers.image.revision="${SOURCE_GIT_SHA}"

ENV KELIBOARD_SOURCE_GIT_SHA="${SOURCE_GIT_SHA}" \
    APP_VERSION="${BUILD_VERSION}"

COPY .docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

RUN php scripts/verify-php-security.php --container \
    && test -f composer.lock \
    && composer install --no-cache --no-dev --prefer-dist --no-interaction --optimize-autoloader \
    && composer check-platform-reqs --no-dev --no-interaction \
    && php artisan storage:link \
    && chown -R www:www /www \
    && chmod -R 775 /www \
    && mkdir -p /data \
    && chown redis:redis /data
    
ENV ENABLE_WEB=true \
    ENABLE_HORIZON=true \
    ENABLE_REDIS=false 

EXPOSE 7001
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"] 
