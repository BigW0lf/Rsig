FROM php:8.3-apache

RUN apt-get update && apt-get install -y \
    libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite deflate headers \
    && sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf \
    && printf '<IfModule mod_deflate.c>\n  AddOutputFilterByType DEFLATE application/json application/geo+json text/html text/css application/javascript\n</IfModule>\n' >> /etc/apache2/apache2.conf

COPY . /var/www/html/

RUN mkdir -p /var/www/html/cache \
    && chown -R www-data:www-data /var/www/html

EXPOSE 80
