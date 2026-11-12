FROM php:8.4-apache
ENV BOOK_DATA_DIR=/var/lib/cajeerbook BOOK_SECURE_COOKIE=1
WORKDIR /opt/cajeerbook
COPY . .
RUN mkdir -p /var/lib/cajeerbook && chown www-data:www-data /var/lib/cajeerbook \
 && a2enmod rewrite \
 && sed -i 's|/var/www/html|/opt/cajeerbook/public|g' /etc/apache2/sites-available/000-default.conf \
 && printf '<Directory /opt/cajeerbook/public>\nRequire all granted\nAllowOverride None\nFallbackResource /index.php\nOptions -Indexes\n</Directory>\n' > /etc/apache2/conf-available/book.conf \
 && a2enconf book
VOLUME ["/var/lib/cajeerbook"]
EXPOSE 80
