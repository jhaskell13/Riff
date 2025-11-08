#!/bin/bash
set -e

cd /var/www/laravel

# Install PHP dependencies
export COMPOSER_ALLOW_SUPERUSER=1
composer install --no-dev --prefer-dist --optimize-autoloader

if [ ! -f .env]; then
    cp .env.example .env
fi

php artisan key:generate

# Laravel optimization
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Set directory permissions
mkdir -p storage/framework/{sessions,views,cache} storage/logs bootstrap/cache
chown -R nginx:nginx /var/www/laravel
chmod -R 775 storage bootstrap/cache
