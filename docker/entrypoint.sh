#!/bin/sh
set -eu

if [ ! -f .env ] && [ -f .env.example ]; then
	cp .env.example .env
fi

mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

php artisan migrate --force
php artisan storage:link || true
chown -R www-data:www-data storage bootstrap/cache

exec "$@"