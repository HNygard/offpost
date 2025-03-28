#!/bin/sh
set -e

# Wait for PostgreSQL to be ready
echo "Waiting for PostgreSQL..."
for i in $(seq 1 30); do
    if php -r "
        \$host=getenv('DB_HOST') ?: 'postgres';
        \$port=getenv('DB_PORT') ?: '5432';
        \$dbname=getenv('DB_NAME') ?: 'offpost';
        \$user=getenv('DB_USER') ?: 'offpost';
        \$pass=trim(file_get_contents(getenv('DB_PASSWORD_FILE') ?: '/run/secrets/postgres_password'));
        \$dsn=\"pgsql:host=\$host;port=\$port;dbname=\$dbname\";
        try {
            new PDO(\$dsn, \$user, \$pass);
            echo 'connected';
        } catch (Exception \$e) {
            exit(1);
        }
    " 2>/dev/null; then
        break
    fi
    echo "Retrying in 1 second..."
    sleep 1
done

# Run database migrations
echo "Running database migrations..."
php /php-frontend/migrations/migrate.php

# In development, create example data and grant access
if [ "$ENVIRONMENT" = "development" ]; then
    echo "Development environment detected. Creating development data..."
    php /php-frontend/migrations/create-dev-data.php
    if [ $? -ne 0 ]; then
        echo "ERROR: Failed to create development data. Exiting."
        exit 1
    fi
    
    echo "Granting thread access to dev-user-id..."
    php /php-frontend/grant-thread-access.php dev-user-id
    if [ $? -ne 0 ]; then
        echo "ERROR: Failed to grant thread access. Exiting."
        exit 1
    fi
fi

# Apache gets grumpy about PID files pre-existing
rm -f /var/run/apache2/apache2.pid

# Start Apache as root (required) but run workers as www-data
exec apache2-foreground
