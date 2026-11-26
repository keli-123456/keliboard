FROM debian:13-slim
ENV DEBIAN_FRONTEND=noninteractive COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_NO_INTERACTION=1
RUN apt-get -o Acquire::Retries=1 -o Acquire::http::Timeout=20 update \
    && apt-get -o Acquire::Retries=1 -o Acquire::http::Timeout=20 install -y --no-install-recommends \
        ca-certificates composer git python3 unzip php8.4-cli=8.4.28-1~deb13u1 \
        php8.4-bcmath php8.4-curl php8.4-gd php8.4-intl php8.4-mbstring \
        php8.4-mysql php8.4-redis php8.4-xml php8.4-zip \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY . /app/
RUN php -r 'exit(PHP_VERSION === "8.4.28" ? 0 : 1);' \
    && echo '1a7bc62c308529c9edd7f495ca544e93c53933d39570f4eb43fe92c6365f64e1  composer.lock' | sha256sum -c - \
    && echo 'dcec7e04554aa3acb6b23d9eff43e8263ae92b95846437d0f33b7439487a756e  composer.json' | sha256sum -c - \
    && composer install --no-dev --prefer-dist --no-scripts --no-plugins --no-progress \
    && test ! -e .env && test ! -e bootstrap/cache/packages.php
