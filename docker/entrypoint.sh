#!/bin/sh
set -eu

php artisan migrate --force
php artisan storage:link || true
chown -R www-data:www-data storage bootstrap/cache

exec "$@"