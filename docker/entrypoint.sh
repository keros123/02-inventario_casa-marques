#!/bin/sh
set -e

PORT="${PORT:-80}"

if [ -f /etc/apache2/ports.conf ]; then
    sed -ri "s/^Listen 80$/Listen ${PORT}/" /etc/apache2/ports.conf
fi

if [ -f /etc/apache2/sites-available/000-default.conf ]; then
    sed -ri "s/<VirtualHost \\*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf
fi

ENV_KEYS="
SUPABASE_URL
SUPABASE_ANON_KEY
SUPABASE_STORAGE_BUCKET
SUPABASE_SERVICE_ROLE_KEY
SUPABASE_DB_HOST
SUPABASE_DB_PORT
SUPABASE_DB_USER
SUPABASE_DB_PASSWORD
SUPABASE_DB_NAME
DB_DRIVER
DB_SSLMODE
DB_PREFIX
DB_TIMEZONE
DB_HOST
DB_PORT
DB_NAME
DB_USER
DB_PASSWORD
DB_CHARSET
DB_COLLATION
"

PASSENV_CONF=/etc/apache2/conf-available/docker-env.conf
: > "$PASSENV_CONF"

ENV_FILE=/var/www/html/.env
WRITE_ENV=0
if [ ! -f "$ENV_FILE" ]; then
    WRITE_ENV=1
    : > "$ENV_FILE"
fi

for key in $ENV_KEYS; do
    eval "val=\${$key-}"
    if [ -n "$val" ]; then
        echo "PassEnv $key" >> "$PASSENV_CONF"
        if [ "$WRITE_ENV" -eq 1 ]; then
            printf '%s=%s\n' "$key" "$val" >> "$ENV_FILE"
        fi
    fi
done

a2enconf docker-env >/dev/null 2>&1 || true

mkdir -p /var/www/html/public/uploads/inventario /var/www/html/public/uploads/dar-baja
chown -R www-data:www-data /var/www/html/public/uploads
if [ -f "$ENV_FILE" ]; then
    chown www-data:www-data "$ENV_FILE"
    chmod 640 "$ENV_FILE"
fi

if [ "$#" -eq 0 ]; then
    set -- apache2-foreground
fi

exec "$@"
