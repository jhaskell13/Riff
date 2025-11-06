#!/bin/bash
cd /var/www/laravel
composer install --no-dev --optimize-autoloader
php artisan optimize
