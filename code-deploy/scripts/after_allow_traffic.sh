#!/bin/bash
set -e

cd /var/www/laravel

php artisan migrate --force
