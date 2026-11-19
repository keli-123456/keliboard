ARG SWOOLE_BASE
FROM mlocati/php-extension-installer AS installer
FROM ${SWOOLE_BASE}
COPY --from=installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pcntl posix mbstring
