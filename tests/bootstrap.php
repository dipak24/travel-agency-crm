<?php

/*
 * Docker Compose loads the whole .env into the app container as real
 * environment variables (`env_file: .env`, plus DB_CONNECTION/DB_HOST/DB_PORT
 * under `environment:` — see docker-compose.yml). PHP's CLI SAPI copies those
 * into $_SERVER before any userland code runs. PHPUnit's <env force="true">
 * tags in phpunit.xml correctly override getenv() and $_ENV, but do NOT touch
 * $_SERVER — and Laravel's env() resolution reads $_SERVER, so the container's
 * values won over phpunit.xml's.
 *
 * Concretely: without this, every test using RefreshDatabase ran
 * migrate:fresh against the real dev Postgres database instead of the
 * sqlite :memory: database phpunit.xml asks for, wiping it. The same leak
 * also silently put tests on MAIL_MAILER=log (no inspectable mail),
 * QUEUE_CONNECTION=database (queued jobs never ran) and database-backed
 * cache/session. So every key phpunit.xml sets is unset here, not just the
 * DB ones. See .ai/rules/general.md.
 */
$phpunitEnvKeys = array_map(
    fn (SimpleXMLElement $env): string => (string) $env['name'],
    iterator_to_array(simplexml_load_file(__DIR__.'/../phpunit.xml')->php->env ?? [], false),
);

foreach ([...$phpunitEnvKeys, 'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'] as $key) {
    unset($_SERVER[$key]);
}

require __DIR__.'/../vendor/autoload.php';
