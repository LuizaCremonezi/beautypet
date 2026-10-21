FROM php:8.3-apache

RUN apt-get update && apt-get install -y \
    libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql pgsql \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html

CMD ["sh", "-c", "sed -i \"s/Listen 80/Listen ${PORT:-10000}/\" /etc/apache2/ports.conf && sed -i \"s/<VirtualHost \\*:80>/<VirtualHost *:${PORT:-10000}>/\" /etc/apache2/sites-available/000-default.conf && apache2-foreground"]