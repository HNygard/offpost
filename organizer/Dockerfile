FROM php:8.2-apache

# Install system dependencies
RUN apt-get update && apt-get install -y \
    libcurl4-gnutls-dev \
    libpq-dev \
    git \
    unzip \
    poppler-utils

RUN docker-php-ext-install curl
RUN docker-php-ext-install pdo

# Install MySQL PDO for talking to Roundcube database
RUN docker-php-ext-install pdo_mysql mysqli

# Install Postgres PDO for internal database
RUN docker-php-ext-install pdo_pgsql pgsql

# Add Debian bookworm repository to install libc-client2007e-dev package
# This is needed because libc-client-dev is no longer available in Debian trixie (used by php:8.2-apache)
# and libc-client2007e-dev (the replacement) is not yet available in trixie
RUN echo "deb http://deb.debian.org/debian bookworm main" >> /etc/apt/sources.list && \
    apt-get update && apt-get install -y libc-client2007e-dev libkrb5-dev && \
    rm -r /var/lib/apt/lists/*
RUN docker-php-ext-configure imap --with-kerberos --with-imap-ssl \
    && docker-php-ext-install imap

RUN a2enmod rewrite

# Add user with same UID/GID as host user
RUN usermod -u 1000 www-data && groupmod -g 1000 www-data

# Configure Apache to run workers as www-data
RUN chown -R www-data:www-data /var/run/apache2 /var/lock/apache2 /var/log/apache2

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set up application directory
WORKDIR /php-frontend

# Copy application files with correct ownership
COPY --chown=www-data:www-data src/ ./

# Copy test entities file for E2E tests
COPY --chown=www-data:www-data ../data/entities_test.json /data/entities_test.json

# Create vendor directory with correct permissions
RUN mkdir -p vendor && chown -R www-data:www-data .

# Install dependencies as www-data user
USER www-data
RUN composer install --no-dev --no-interaction --no-progress --optimize-autoloader
USER root

COPY localhost.conf /etc/apache2/conf-enabled/localhost.conf
COPY phpdev.ini /usr/local/etc/php/conf.d/phpdev.ini
COPY docker-entrypoint.sh /usr/local/bin/
RUN mkdir -p /opt/offpost/migrations
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Copy SQL migrations, just to trigger recreation of container on deploy
COPY src/migrations/sql/ /opt/offpost/migrations/

# Ensure correct permissions
RUN chown -R www-data:www-data /php-frontend

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
