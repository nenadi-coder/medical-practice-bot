# Use Apache so the web server stays alive
FROM php:8.2-apache

# Install database extensions
RUN apt-get update && apt-get install -y \
    libzip-dev \
    unzip \
    && docker-php-ext-install pdo_mysql mysqli zip \
    && a2enmod rewrite

# Copy your files to the web directory
COPY . /var/www/html/

# Ensure permissions are correct for the web server
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
