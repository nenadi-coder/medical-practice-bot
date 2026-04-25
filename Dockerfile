# Use the official PHP 8.2 CLI image
FROM php:8.2-cli

# Install system dependencies and PHP extensions
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libzip-dev \
    unzip \
    && docker-php-ext-install pdo_mysql mysqli zip \
    && apt-get clean

# Set working directory
WORKDIR /var/www/html

# Copy all application files
COPY . .

# Run the reminder script
CMD ["php", "send_reminders.php"]
