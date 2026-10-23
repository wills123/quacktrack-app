#!/bin/sh
set -e

# Install Composer dependencies if vendor directory is empty
if [ ! -f "/var/www/html/vendor/autoload.php" ]; then
    echo "Installing Composer dependencies..."
    cd /var/www/html
    composer install --no-dev --no-interaction --optimize-autoloader
    echo "Composer dependencies installed successfully!"
fi

# Wait for database to be ready
if [ -n "${DB_HOST:-}" ]; then
    echo "Waiting for database at ${DB_HOST}:3306..."
    max_attempts=30
    attempt=0
    while [ $attempt -lt $max_attempts ]; do
        if php -r 'try { $o = []; if (getenv("DB_SSL_CA")) { $o[PDO::MYSQL_ATTR_SSL_CA] = getenv("DB_SSL_CA"); $o[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true; } new PDO("mysql:host=" . getenv("DB_HOST") . ";port=3306", getenv("DB_USER"), getenv("DB_PASSWORD"), $o); } catch (Throwable $e) { exit(1); }'; then
            echo "Database is ready!"
            break
        fi
        attempt=$((attempt + 1))
        if [ $attempt -eq $max_attempts ]; then
            echo "Database not ready after ${max_attempts} attempts, skipping migrations"
            exec php-fpm
        fi
        echo "Database not ready, waiting... (attempt $attempt/$max_attempts)"
        sleep 2
    done
fi

# Run migrations
if [ -f "/var/www/html/app/run_migrations.php" ]; then
    echo "Running database migrations..."
    php /var/www/html/app/run_migrations.php || echo "Migration failed, but continuing..."
fi

# Start PHP-FPM
exec php-fpm
