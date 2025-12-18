#!/bin/bash
set -e

# This script executes on the EC2 instance after deployment
# Prepare Laravel environment
cd /var/www/laravel
cp .env.example .env

# Set Environment variables on instance
SECRET_JSON=$(aws secretsmanager get-secret-value \
  --secret-id production-db-credentials \
  --query SecretString \
  --output text)

export APP_URL="https://haskellsolutions.com"

export APP_ENV=$(echo "$SECRET_JSON" | jq -r .environment)
export DB_HOST=$(echo "$SECRET_JSON" | jq -r .host)
export DB_DATABASE=$(echo "$SECRET_JSON" | jq -r .dbname)
export DB_USERNAME=$(echo "$SECRET_JSON" | jq -r .username)
export DB_PASSWORD=$(echo "$SECRET_JSON" | jq -r .password)
export DB_CONNECTION=$(echo "$SECRET_JSON" | jq -r .engine)

if [[ $DB_CONNECTION == "postgres" ]]; then
    export DB_CONNECTION="pgsql"
fi

# sed -i "s|^#\?\s*APP_ENV=.*|APP_ENV=$APP_ENV|" .env
# sed -i "s|^#\?\s*DB_HOST=.*|DB_HOST=$DB_HOST|" .env
# sed -i "s|^#\?\s*DB_DATABASE=.*|DB_DATABASE=$DB_DATABASE|" .env
# sed -i "s|^#\?\s*DB_USERNAME=.*|DB_USERNAME=$DB_USERNAME|" .env
# sed -i "s|^#\?\s*DB_PASSWORD=.*|DB_PASSWORD=$DB_PASSWORD|" .env
# sed -i "s|^#\?\s*DB_CONNECTION=.*|DB_CONNECTION=$DB_CONNECTION|" .env

# Install PHP dependencies
# export COMPOSER_ALLOW_SUPERUSER=1
# composer install --no-dev --prefer-dist --optimize-autoloader

# Laravel optimization
php artisan key:generate
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Run migrations and Laravel dependencies
php artisan migrate --force
php artisan reverb:install

# Set directory permissions
mkdir -p storage/framework/{sessions,views,cache} storage/logs bootstrap/cache
chown -R nginx:nginx /var/www/laravel
chmod -R 775 storage bootstrap/cache
