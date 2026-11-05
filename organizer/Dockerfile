FROM php:8.2.35-apache

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
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# The image carries the application and its vendor/: production runs it as built,
# development mounts organizer/src over /php-frontend (including its vendor/).
WORKDIR /php-frontend
RUN chown www-data:www-data /php-frontend

# Create composer cache directory with correct permissions for www-data user
RUN mkdir -p /var/www/.composer/cache && chown -R www-data:www-data /var/www/.composer

# Dependencies first, as their own layer: rebuilt only when composer.json/lock change
USER www-data
COPY --chown=www-data:www-data src/composer.json src/composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-autoloader --no-scripts

# Application code (src/vendor/ and src/git-sha.txt are excluded by .dockerignore)
COPY --chown=www-data:www-data src/ ./
RUN composer dump-autoload --no-dev --optimize
USER root

COPY localhost.conf /etc/apache2/conf-enabled/localhost.conf
COPY apache-resource-limits.conf /etc/apache2/conf-enabled/apache-resource-limits.conf
COPY phpdev.ini /usr/local/etc/php/conf.d/phpdev.ini
COPY docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Set resource limits for the container
# These ulimit settings provide baseline protection, but for production
# it's recommended to also set Docker runtime limits via --ulimit flags
# or resource constraints in docker-compose/kubernetes manifests
# ulimit -v: limit virtual memory (in KB) - set to 2GB to allow for more workers
# ulimit -u: limit number of processes - increased from 50 to 150 to allow:
#   - Apache workers (20)
#   - DNS resolution threads (getaddrinfo spawns threads for each DNS lookup)
#   - PHP child processes
#   - System processes
#RUN echo "ulimit -v 2097152" >> /etc/apache2/envvars && \
#    echo "ulimit -u 150" >> /etc/apache2/envvars
# => DISABLED as it stops curl to openai from working

# The commit this image was built from, shown by the web app (class/GitVersion.php).
# Last, so a new commit does not invalidate the layers above. Empty in development.
ARG GIT_SHA=
RUN if [ -n "$GIT_SHA" ]; then echo "$GIT_SHA" > /php-frontend/git-sha.txt; fi

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
