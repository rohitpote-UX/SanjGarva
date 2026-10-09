#!/bin/sh
set -e

# Support dynamic PORT environment variable (Render, Railway, Fly.io, Cloud Run)
if [ -n "$PORT" ]; then
    sed -i "s/Listen 80/Listen $PORT/g" /etc/apache2/ports.conf
    sed -i "s/<VirtualHost \*:80>/<VirtualHost \*:$PORT>/g" /etc/apache2/sites-available/*.conf
fi

# Resolve remote database URL or host from standard cloud/Vercel/Neon variables
RESOLVED_DB_URL="${DB_URL:-${DATABASE_URL:-${POSTGRES_URL:-${POSTGRES_PRISMA_URL:-${POSTGRES_URL_NON_POOLING:-${NEON_DATABASE_URL:-${NEON_URL:-${DATABASE_URI:-${POSTGRESQL_URL:-${PG_URL:-${DB_CONNECTION_STRING:-}}}}}}}}}}}"
RESOLVED_DB_HOST="${DB_HOST:-${POSTGRES_HOST:-}}"

if [ -n "$RESOLVED_DB_URL" ]; then
    export DB_URL="$RESOLVED_DB_URL"
    export DATABASE_URL="$RESOLVED_DB_URL"
    if [ -z "$DB_CONNECTION" ]; then
        export DB_CONNECTION="pgsql"
    fi
fi

# Ensure an APP_KEY is available (fallback to dynamic key if not configured in Vercel)
if [ -z "$APP_KEY" ]; then
    echo "Notice: APP_KEY is not set in environment. Generating a runtime key..."
    export APP_KEY="$(php artisan key:generate --show)"
fi

# Run database migrations if AUTORUN_MIGRATIONS is true OR if remote database is configured
if [ "$AUTORUN_MIGRATIONS" = "true" ] || [ -n "$RESOLVED_DB_URL" ] || ( [ -n "$RESOLVED_DB_HOST" ] && [ "$RESOLVED_DB_HOST" != "127.0.0.1" ] && [ "$RESOLVED_DB_HOST" != "localhost" ] ); then
    echo "Checking and applying database migrations..."
    # Note: Do NOT use --isolated here. When CACHE_STORE=database, --isolated attempts to acquire an atomic
    # lock in the cache_locks table, which causes a fatal query exception if the cache table has not yet been created.
    if php artisan migrate --force; then
        echo "Database migrations applied successfully."
        echo "Ensuring baseline reference data exists..."
        php artisan db:seed --force || echo "Warning: Database seeding reported notices."
    else
        echo "ERROR: php artisan migrate failed. Verify database connectivity and credentials."
    fi
fi

# Cache configuration and routes if in production with valid APP_KEY
if [ "$APP_ENV" = "production" ] && [ -n "$APP_KEY" ]; then
    echo "Caching Laravel configuration and routes for production..."
    php artisan config:cache || echo "Warning: config:cache could not be completed."
    php artisan route:cache || echo "Warning: route:cache could not be completed."
fi

exec "$@"
