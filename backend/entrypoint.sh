#!/bin/sh
set -e

# Support dynamic PORT environment variable (Render, Railway, Fly.io, Cloud Run)
if [ -n "$PORT" ]; then
    sed -i "s/Listen 80/Listen $PORT/g" /etc/apache2/ports.conf
    sed -i "s/<VirtualHost \*:80>/<VirtualHost \*:$PORT>/g" /etc/apache2/sites-available/*.conf
fi

# Run database migrations if AUTORUN_MIGRATIONS is set to true
if [ "$AUTORUN_MIGRATIONS" = "true" ]; then
    echo "Running database migrations..."
    php artisan migrate --force
    # If FIRST_RUN_SEED is set, run initial DatabaseSeeder (creates owner account & default categories)
    if [ "$FIRST_RUN_SEED" = "true" ]; then
        echo "Running initial database seeder..."
        php artisan db:seed --force
    fi
fi

# Cache configuration and routes if in production with valid APP_KEY
if [ "$APP_ENV" = "production" ] && [ -n "$APP_KEY" ]; then
    php artisan config:cache || true
    php artisan route:cache || true
fi

exec "$@"
