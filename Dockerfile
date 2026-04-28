# Use the official PHP 8.2 Apache image
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

# Allow .htaccess overrides and serve subdirectories properly
RUN sed -i '/<Directory \/var\/www\/html>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' \
    /etc/apache2/apache2.conf || \
    echo '<Directory /var/www/html>\n\
    Options Indexes FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>' >> /etc/apache2/apache2.conf

# Fix PHP session directory permissions
RUN mkdir -p /var/lib/php/sessions \
    && chown -R www-data:www-data /var/lib/php/sessions \
    && chmod 770 /var/lib/php/sessions

# Set working directory
WORKDIR /var/www/html

# Copy all application files
COPY . .

# Ensure Apache can read the app files
RUN chown -R www-data:www-data /var/www/html \
    && find /var/www/html -type d -exec chmod 755 {} \; \
    && find /var/www/html -type f -exec chmod 644 {} \;

# DigitalOcean App Platform injects $PORT at runtime; substitute it at startup
CMD ["/bin/sh", "-c", \
    "PORT=${PORT:-8080} && \
    sed -i \"s/Listen 80/Listen $PORT/\" /etc/apache2/ports.conf && \
    sed -i \"s/:80/:$PORT/\" /etc/apache2/sites-available/000-default.conf && \
    apache2-foreground"]
