FROM node:22-bookworm-slim AS mermaid-assets
WORKDIR /build
COPY bin/install-mermaid.sh bin/install-mermaid.sh
RUN sh bin/install-mermaid.sh
FROM composer:2 AS composer-bin
FROM php:8.4-apache
ENV BOOK_DATA_DIR=/var/lib/cajeerbook BOOK_SECURE_COOKIE=1
WORKDIR /opt/cajeerbook
RUN apt-get update && apt-get install -y --no-install-recommends libonig-dev libpng-dev libjpeg62-turbo-dev libwebp-dev unzip \
 && docker-php-ext-configure gd --with-jpeg --with-webp \
 && docker-php-ext-install mbstring gd \
 && rm -rf /var/lib/apt/lists/*
COPY --from=composer-bin /usr/bin/composer /usr/local/bin/composer
COPY composer.json composer.lock ./
RUN COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader
COPY . .
COPY --from=mermaid-assets /build/public/assets/mermaid/ public/assets/mermaid/
RUN printf "upload_max_filesize=20M\npost_max_size=24M\nmemory_limit=256M\nmax_execution_time=120\ndisplay_errors=Off\nlog_errors=On\nexpose_php=Off\n" > /usr/local/etc/php/conf.d/book-media.ini
RUN mkdir -p /var/lib/cajeerbook && chown www-data:www-data /var/lib/cajeerbook \
 && a2enmod rewrite \
 && sed -i 's|/var/www/html|/opt/cajeerbook/public|g' /etc/apache2/sites-available/000-default.conf \
 && printf '<Directory /opt/cajeerbook/public>\nRequire all granted\nAllowOverride None\nFallbackResource /index.php\nOptions -Indexes -FollowSymLinks\n</Directory>\n' > /etc/apache2/conf-available/book.conf \
 && a2enconf book
VOLUME ["/var/lib/cajeerbook"]
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 CMD php -r '$c=stream_context_create(["http"=>["timeout"=>3]]);exit(@file_get_contents("http://127.0.0.1/health/ready",false,$c)===false?1:0);'
EXPOSE 80
