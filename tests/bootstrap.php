<?php

/*
 * Docker Compose sets DB_CONNECTION/DB_HOST/DB_PORT as real container
 * environment variables (see docker-compose.yml). PHP's CLI SAPI copies
 * those into $_SERVER before any userland code runs. PHPUnit's <env
 * force="true"> tags in phpunit.xml correctly override getenv() and
 * $_ENV, but do NOT touch $_SERVER — and Laravel's env() resolution reads
 * $_SERVER, so the stale pgsql values from Docker won.
 *
 * Concretely: without this, every test using RefreshDatabase ran
 * migrate:fresh against the real dev Postgres database instead of the
 * sqlite :memory: database phpunit.xml asks for, wiping it. See
 * .ai/rules/general.md.
 */
foreach (['DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'] as $key) {
    unset($_SERVER[$key]);
}

require __DIR__.'/../vendor/autoload.php';
