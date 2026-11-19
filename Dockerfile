FROM php:8.3.13-apache

RUN apt-get update \
    && apt-get install -y libicu-dev libxml2-dev libzip-dev libpng-dev libonig-dev libjpeg62-turbo libjpeg62-turbo-dev libfreetype6-dev \
    && docker-php-ext-install intl xml xmlwriter gettext mbstring zip mysqli pdo_mysql \
    && docker-php-ext-enable intl xml xmlwriter gettext mbstring zip mysqli pdo_mysql \
    && docker-php-ext-install -j$(nproc) iconv \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd \
    && docker-php-ext-enable gd

RUN a2enmod rewrite headers

# SECURITY: production PHP settings (no error details to visitors), and do not
# advertise PHP / Apache versions in response headers and error pages
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && printf 'expose_php = Off\nsession.use_strict_mode = 1\nsession.cookie_httponly = 1\n' > "$PHP_INI_DIR/conf.d/zz-security.ini" \
    && printf 'ServerTokens Prod\nServerSignature Off\nTraceEnable Off\n' > /etc/apache2/conf-available/zz-security.conf \
    && a2enconf zz-security

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /var/www/html

COPY . /var/www/html

RUN composer install --no-dev --no-interaction --optimize-autoloader

RUN chown -R www-data:www-data /var/www/html/files
RUN chown -R www-data:www-data /var/www/html/images
RUN chown -R www-data:www-data /var/www/html/repository
RUN rm -rf /var/www/html/install

COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]

CMD ["apache2-foreground"]

