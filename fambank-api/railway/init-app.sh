#!/bin/bash
set -e

php artisan migrate --force --seed
php artisan optimize:clear
php artisan config:clear
php artisan config:cache
php artisan route:cache