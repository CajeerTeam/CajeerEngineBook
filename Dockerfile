ARG NODE_BASE=node:22-bookworm-slim
ARG COMPOSER_BASE=composer:2
ARG PHP_BASE=php:8.4-apache-bookworm
FROM ${NODE_BASE} AS mermaid-assets
WORKDIR /build
COPY bin/install-mermaid.sh bin/install-mermaid.sh
RUN sh bin/install-mermaid.sh
FROM ${COMPOSER_BASE} AS composer-bin
FROM ${PHP_BASE} AS runtime
ENV BOOK_DATA_DIR=/var/lib/cajeerbook BOOK_SECURE_COOKIE=1
WORKDIR /opt/cajeerbook
# Refresh OS security packages and retain only runtime libraries after extension compilation.
RUN apt-get update && apt-get upgrade -y --with-new-pkgs \
 && apt-get install -y --no-install-recommends libonig-dev libpng-dev libjpeg62-turbo-dev libwebp-dev libzip-dev libpq-dev \
    libonig5 libpng16-16 libjpeg62-turbo libwebp7 libzip4 libpq5 unzip ca-certificates awscli \
 && docker-php-ext-configure gd --with-jpeg --with-webp \
 && docker-php-ext-install mbstring gd zip pdo_pgsql pcntl \
 && pecl install redis-6.1.0 && docker-php-ext-enable redis \
 && apt-get purge -y --auto-remove libonig-dev libpng-dev libjpeg62-turbo-dev libwebp-dev libzip-dev libpq-dev \
    autoconf dpkg-dev g++ gcc libc6-dev make pkg-config re2c \
 && php -r 'foreach (["mbstring","gd","zip","pdo_pgsql","pcntl","redis"] as $ext) { if (!extension_loaded($ext)) { fwrite(STDERR, "Missing extension: $ext\n"); exit(1); } }' \
 && rm -rf /var/lib/apt/lists/* /tmp/pear
COPY --from=composer-bin /usr/bin/composer /usr/local/bin/composer
COPY composer.json composer.lock ./
RUN COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader
COPY bootstrap.php VERSION LICENSE NOTICE TRADEMARKS.md ./
COPY bin/ bin/
COPY core/ core/
COPY data/ data/
COPY public/ public/
COPY resources/ resources/
COPY sql/ sql/
COPY src/ src/
COPY templates/ templates/
COPY --from=mermaid-assets /build/public/assets/mermaid/ public/assets/mermaid/
RUN printf "upload_max_filesize=20M\npost_max_size=72M\nmax_file_uploads=200\nmemory_limit=256M\nmax_execution_time=120\ndisplay_errors=Off\nlog_errors=On\nexpose_php=Off\n" > /usr/local/etc/php/conf.d/book-media.ini
RUN mkdir -p /var/lib/cajeerbook /var/lib/cajeerbackup /var/lib/cajeerpitr && chmod 0700 /var/lib/cajeerbackup /var/lib/cajeerpitr && chown www-data:www-data /var/lib/cajeerbook /var/lib/cajeerbackup /var/lib/cajeerpitr \
 && a2enmod rewrite remoteip setenvif headers \
 && sed -i 's|/var/www/html|/opt/cajeerbook/public|g' /etc/apache2/sites-available/000-default.conf \
 && printf 'TraceEnable Off\nServerTokens Prod\nServerSignature Off\n<Directory /opt/cajeerbook/public>\nRequire all granted\nSetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1\nAllowOverride None\nFallbackResource /index.php\nOptions -Indexes -FollowSymLinks\nHeader always setifempty X-Content-Type-Options "nosniff"\n</Directory>\n' > /etc/apache2/conf-available/book.conf \
 && a2enconf book \
 && sed -i 's|CustomLog ${APACHE_LOG_DIR}/access.log combined|CustomLog ${APACHE_LOG_DIR}/access.log "%h %l %u %t %m %U %H %>s %b"|' /etc/apache2/sites-available/000-default.conf
VOLUME ["/var/lib/cajeerbook"]
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 CMD php -r '$c=stream_context_create(["http"=>["timeout"=>3]]);exit(@file_get_contents("http://127.0.0.1/health/ready",false,$c)===false?1:0);'
EXPOSE 80

FROM runtime AS test
COPY tests/ tests/
FROM runtime AS production
