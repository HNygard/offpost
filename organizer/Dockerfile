FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
    libcurl4-gnutls-dev \
    libpq-dev

RUN docker-php-ext-install curl
RUN docker-php-ext-install pdo pdo_mysql mysqli pdo_pgsql

RUN apt-get update && apt-get install -y libc-client-dev libkrb5-dev && rm -r /var/lib/apt/lists/*
RUN docker-php-ext-configure imap --with-kerberos --with-imap-ssl \
    && docker-php-ext-install imap

RUN a2enmod rewrite

# Add user with same UID/GID as host user
RUN usermod -u 1000 www-data && groupmod -g 1000 www-data

# Configure Apache to run workers as www-data
RUN chown -R www-data:www-data /var/run/apache2 /var/lock/apache2 /var/log/apache2

COPY localhost.conf /etc/apache2/conf-enabled/localhost.conf
COPY phpdev.ini /usr/local/etc/php/conf.d/phpdev.ini
COPY docker-entrypoint.sh /usr/local/bin/
RUN mkdir -p /opt/offpost/migrations
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Copy SQL migrations, just to trigger recreation of container on deploy
COPY src/migrations/sql/ /opt/offpost/migrations/

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
