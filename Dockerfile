# Use the official PHP 8.2 Apache image — provides a full web server
FROM php:8.2-apache

# Install system dependencies and PHP extensions
RUN apt-get update && apt-get install -y \
    libzip-dev \
    unzip \
    && docker-php-ext-install pdo_mysql mysqli zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Enable mod_rewrite so .htaccess rules work if needed
RUN a2enmod rewrite

# Prefer index.php over index.html
RUN echo "DirectoryIndex index.php index.html" > /etc/apache2/conf-available/php-index.conf \
    && a2enconf php-index

# Set working directory (Apache serves from here by default)
WORKDIR /var/www/html

# Copy all application files
COPY . .

# Make sure Apache can read the files
RUN chown -R www-data:www-data /var/www/html

# Apache listens on port 80
EXPOSE 80

# Start Apache in the foreground (keeps the container running)
CMD ["apache2-foreground"]
