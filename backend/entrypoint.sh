#!/bin/sh
set -e

# Support dynamic PORT environment variable (Render, Railway, Fly.io, Cloud Run)
if [ -n "$PORT" ]; then
    sed -i "s/Listen 80/Listen $PORT/g" /etc/apache2/ports.conf
    sed -i "s/<VirtualHost \*:80>/<VirtualHost \*:$PORT>/g" /etc/apache2/sites-available/*.conf
fi

# Run database migrations if AUTORUN_MIGRATIONS is true OR if remote database (DB_URL / DB_HOST) is configured
if [ "$AUTORUN_MIGRATIONS" = "true" ] || [ -n "$DB_URL" ] || ( [ -n "$DB_HOST" ] && [ "$DB_HOST" != "127.0.0.1" ] ); then
    echo "Checking and applying database migrations..."
    php artisan migrate --force --isolated || true
    echo "Ensuring baseline reference data exists..."
    php artisan db:seed --force || true
fi

# Cache configuration and routes if in production with valid APP_KEY
if [ "$APP_ENV" = "production" ] && [ -n "$APP_KEY" ]; then
    php artisan config:cache || true
    php artisan route:cache || true
fi

exec "$@"
