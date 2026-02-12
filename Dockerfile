FROM php:8.2-apache

# Install necessary PHP extensions for Google API (like zip/intl if needed)
RUN apt-get update && apt-get install -y \
    libzip-dev \
    zip \
    && docker-php-ext-install zip

# Enable Apache mod_rewrite for clean URLs
RUN a2enmod rewrite

# Copy your project files to the container
COPY . /var/www/html/

# Set permissions for Apache
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80