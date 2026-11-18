#!/bin/sh
set -e

# Remove any leftover static local .env
rm -f /var/www/.env

# Write runtime environment variables provided by Railway into /var/www/.env
printenv | grep -E '^(APP_|DB_|SESSION_|CACHE_|QUEUE_|LOG_|PORT|BCRYPT_|SANCTUM_)' > /var/www/.env || true

# Clear cached config so Laravel binds to the fresh environment
php artisan config:clear || true

# Run database migrations against the cloud MySQL database
php artisan migrate --force || true

# Start the web server on the assigned port
exec php artisan serve --host=0.0.0.0 --port=${PORT:-8080}
