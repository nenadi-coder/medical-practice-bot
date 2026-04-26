# Use the official PHP 8.2 Apache image (serves PHP over Apache on $PORT)
FROM php:8.2-apache

# Install system dependencies and PHP extensions
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libcurl4-openssl-dev \
    libzip-dev \
    unzip \
    && docker-php-ext-install pdo_mysql mysqli zip \
    && a2enmod rewrite \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Set working directory
WORKDIR /var/www/html

# Copy all application files
COPY . .

# Ensure Apache can read the app files
RUN chown -R www-data:www-data /var/www/html

# DigitalOcean App Platform injects $PORT at runtime; substitute it at startup
# so Apache listens on the correct port.
CMD ["/bin/sh", "-c", "PORT=${PORT:-8080} && sed -i \"s/Listen 80/Listen $PORT/\" /etc/apache2/ports.conf && sed -i \"s/:80/:$PORT/\" /etc/apache2/sites-available/000-default.conf && apache2-foreground"]
