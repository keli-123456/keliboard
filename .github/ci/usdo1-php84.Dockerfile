FROM debian:13-slim
ENV DEBIAN_FRONTEND=noninteractive COMPOSER_ALLOW_SUPERUSER=1
RUN apt-get -o Acquire::Retries=1 -o Acquire::http::Timeout=20 update \
    && apt-get -o Acquire::Retries=1 -o Acquire::http::Timeout=20 install -y --no-install-recommends \
        ca-certificates composer curl nginx openssl python3 unzip \
        php8.4-cli php8.4-fpm php8.4-bcmath php8.4-curl php8.4-gd php8.4-intl php8.4-mbstring \
        php8.4-mysql php8.4-redis php8.4-sqlite3 php8.4-xml php8.4-zip \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY . /app/
RUN test ! -e .env \
    && composer install --prefer-dist --no-interaction --no-scripts --no-plugins --no-progress \
    && test ! -e bootstrap/cache/packages.php \
    && chmod -R a-w /app
