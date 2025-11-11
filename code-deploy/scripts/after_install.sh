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

DB_CONNECTION=$(echo "$SECRET_JSON" | jq -r .engine)
if [[ $DB_CONNECTION == "postgres" ]]; then
    DB_CONNECTION="pgsql"
fi
echo "APP_ENV=$(echo "$SECRET_JSON" | jq -r .environment)" >> .env
echo "DB_CONNECTION=$DB_CONNECTION" >> .env
echo "DB_HOST=$(echo "$SECRET_JSON" | jq -r .host)" >> .env
echo "DB_DATABASE=$(echo "$SECRET_JSON" | jq -r .dbname)" >> .env
echo "DB_USERNAME=$(echo "$SECRET_JSON" | jq -r .username)" >> .env
echo "DB_PASSWORD=$(echo "$SECRET_JSON" | jq -r .password)" >> .env

# Install PHP dependencies
export COMPOSER_ALLOW_SUPERUSER=1
composer install --no-dev --prefer-dist --optimize-autoloader

# Laravel optimization
php artisan key:generate
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Set directory permissions
mkdir -p storage/framework/{sessions,views,cache} storage/logs bootstrap/cache
chown -R nginx:nginx /var/www/laravel
chmod -R 775 storage bootstrap/cache
