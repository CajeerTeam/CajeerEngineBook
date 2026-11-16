ARG NODE_BASE=node:22-bookworm-slim
ARG COMPOSER_BASE=composer:2
ARG PHP_BASE=alpine:3.24
FROM ${NODE_BASE} AS mermaid-assets
WORKDIR /build
COPY bin/install-mermaid.sh bin/install-mermaid.sh
RUN sh bin/install-mermaid.sh
FROM ${COMPOSER_BASE} AS composer-bin
FROM ${PHP_BASE} AS runtime
ENV BOOK_DATA_DIR=/var/lib/cajeerbook BOOK_SECURE_COOKIE=1
WORKDIR /opt/cajeerbook
# Use the maintained Alpine stable repositories for PHP and OS security updates.
# Preserve Debian image's UID/GID for existing volumes and secret mounts.
RUN apk upgrade --no-cache \
 && sed -i 's/^www-data:x:82:/www-data:x:33:/' /etc/group \
 && test "$(awk -F: '$1=="www-data" {print $3}' /etc/group)" = 33 \
 && adduser -S -D -H -u 33 -G www-data -s /sbin/nologin www-data \
 && apk add --no-cache apache2 ca-certificates aws-cli \
    php84 php84-apache2 php84-ctype php84-curl php84-dom php84-fileinfo php84-gd \
    php84-iconv php84-mbstring php84-openssl php84-pcntl php84-pdo php84-pdo_pgsql \
    php84-phar php84-posix php84-session php84-simplexml php84-sodium php84-tokenizer \
    php84-xml php84-xmlreader php84-xmlwriter php84-zip php84-pecl-redis \
 && mkdir -p /usr/local/bin \
 && ln -sf /usr/bin/php84 /usr/local/bin/php \
 && php -r 'if(PHP_VERSION_ID<80401 || PHP_VERSION_ID>=80500) exit(1); foreach (["ctype","curl","dom","fileinfo","gd","iconv","mbstring","openssl","pcntl","PDO","pdo_pgsql","Phar","posix","session","SimpleXML","sodium","tokenizer","xml","xmlreader","xmlwriter","zip","redis"] as $ext) { if (!extension_loaded($ext)) { fwrite(STDERR, "Missing extension: $ext\n"); exit(1); } }' \
 && test "$(id -u www-data):$(id -g www-data)" = 33:33 \
 && aws --version
FROM runtime AS dependencies
RUN apk add --no-cache unzip
COPY --from=composer-bin /usr/bin/composer /usr/local/bin/composer
COPY composer.json composer.lock ./
RUN COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader
FROM runtime AS app
COPY composer.json composer.lock ./
COPY --from=dependencies /opt/cajeerbook/vendor/ vendor/
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
COPY deploy/book-httpd.conf /etc/apache2/httpd.conf
RUN printf "upload_max_filesize=20M\npost_max_size=72M\nmax_file_uploads=200\nmemory_limit=256M\nmax_execution_time=120\ndisplay_errors=Off\nlog_errors=On\nexpose_php=Off\nsession.save_path=/var/lib/php84/sessions\n" > /etc/php84/conf.d/99-book-media.ini \
 && mkdir -p /run/apache2 /etc/apache2/conf-enabled /var/lib/php84/sessions /var/lib/cajeerbook /var/lib/cajeerbackup /var/lib/cajeerpitr \
 && chmod 0700 /var/lib/php84/sessions /var/lib/cajeerbackup /var/lib/cajeerpitr \
 && chown www-data:www-data /var/lib/php84/sessions /var/lib/cajeerbook /var/lib/cajeerbackup /var/lib/cajeerpitr \
 && httpd -t
VOLUME ["/var/lib/cajeerbook"]
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 CMD php -r '$c=stream_context_create(["http"=>["timeout"=>3]]);exit(@file_get_contents("http://127.0.0.1/health/ready",false,$c)===false?1:0);'
EXPOSE 80
CMD ["/usr/sbin/httpd", "-DFOREGROUND"]

FROM app AS test
COPY tests/ tests/
FROM app AS production
