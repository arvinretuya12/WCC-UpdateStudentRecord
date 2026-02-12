# Use PHP with Apache
FROM php:8.2-apache

# 1. Install system dependencies for Google API and Composer
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    zip \
    libzip-dev \
    && docker-php-ext-install zip

# 2. Install Composer (the PHP package manager)
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 3. Set the working directory
WORKDIR /var/www/html

# 4. Copy your composer files first (for faster caching)
COPY composer.json composer.lock* ./

# 5. Install the libraries (this creates the 'vendor' folder inside Docker)
RUN composer install --no-dev --optimize-autoloader

# 6. Copy the rest of your application code
COPY . .

# 7. Set permissions for Apache
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80