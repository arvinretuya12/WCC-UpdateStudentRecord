FROM php:8.2-apache

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    zip \
    libzip-dev \
    && docker-php-ext-install zip

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Enable Apache Mod Rewrite
RUN a2enmod rewrite

WORKDIR /var/www/html

# Copy ONLY composer.json
COPY composer.json ./

# Install dependencies (ignoring local platform requirements)
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts --ignore-platform-reqs

# Now copy the rest of your PHP files
COPY . .

# Set permissions
RUN chown -R www-data:www-data /var/www/html

# Create the uploads directory and set permissions in one go
RUN mkdir -p /var/www/html/uploads && \
    chown -R www-data:www-data /var/www/html/uploads && \
    chmod -R 755 /var/www/html/uploads

EXPOSE 80