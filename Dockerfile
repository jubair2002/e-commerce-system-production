FROM php:8.2-apache

# Set non-interactive debian frontend
ENV DEBIAN_FRONTEND=noninteractive

# Install system dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
    cron \
    default-mysql-client \
    libpng-dev \
    libjpeg-dev \
    libwebp-dev \
    libfreetype6-dev \
    libzip-dev \
    libonig-dev \
    zip \
    unzip \
    curl \
    && rm -rf /var/lib/apt/lists/*

# Configure & Install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        gd \
        mbstring \
        zip \
        opcache

# Enable Apache modules
RUN a2enmod rewrite headers expires deflate remoteip

# Copy PHP and Apache configurations
COPY docker/php.ini /usr/local/etc/php/conf.d/custom.ini
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf

# Copy cron configuration
COPY docker/cron/crontab /etc/cron.d/jinispotro-cron
RUN chmod 0644 /etc/cron.d/jinispotro-cron

# Install Composer from official image
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy application codebase
COPY . /var/www/html/

# Install composer dependencies
RUN if [ ! -d "/var/www/html/vendor" ]; then \
        composer install --no-dev --optimize-autoloader --no-interaction; \
    fi

# Ensure correct permissions for web server and uploads
RUN mkdir -p /var/www/html/assets/uploads /var/www/html/storage/cache \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 775 /var/www/html/assets/uploads /var/www/html/storage

# Copy entrypoint script
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]
