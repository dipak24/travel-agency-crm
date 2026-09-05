---
paths:
  - 'phpunit.xml,docker-compose.yml'
---

# General

## Tests need tests/bootstrap.php to unset $_SERVER DB_* keys, or they hit the real Postgres dev DB
docker-compose.yml sets DB_CONNECTION=pgsql/DB_HOST=pgsql as real container environment variables for the app service. PHP's CLI SAPI copies those into `$_SERVER` before any userland code runs. PHPUnit's `<env name="..." value="..." force="true"/>` tags in phpunit.xml DO correctly override `getenv()` and `$_ENV` — but NOT `$_SERVER`, and Laravel's `env()` resolution reads `$_SERVER`. So even with `force="true"` on every `<env>` tag, `config('database.default')` still resolved to `pgsql` inside the app container, and any test using `RefreshDatabase` ran `migrate:fresh` against the real dev Postgres database — wiping it. (Verified directly: `getenv('DB_CONNECTION')` / `$_ENV['DB_CONNECTION']` correctly showed `sqlite`, while `$_SERVER['DB_CONNECTION']` still showed `pgsql`.)

The actual fix is `tests/bootstrap.php`, referenced by phpunit.xml's `bootstrap="tests/bootstrap.php"` (not the default `vendor/autoload.php`): it explicitly `unset()`s `$_SERVER['DB_CONNECTION']`/`DB_HOST`/`DB_PORT`/`DB_DATABASE`/`DB_USERNAME`/`DB_PASSWORD` before requiring the autoloader, so Laravel's env resolution falls through to the `$_ENV`/`getenv()` values PHPUnit already set correctly. Do not revert phpunit.xml's `bootstrap` to `vendor/autoload.php`, and do not remove tests/bootstrap.php — `force="true"` on the `<env>` tags alone is NOT sufficient here, no matter how it looks like it should be.

**Before trusting that tests are isolated from Postgres after ANY change to phpunit.xml, docker-compose.yml, tests/bootstrap.php, or the Docker image**, verify directly rather than assuming: run a throwaway test that dumps `config('database.default')` (must be `sqlite`), then separately check `SELECT count(*) FROM tenants` (or similar) in the real dev Postgres database before and after a full `php artisan test` run to confirm row counts are unchanged. This bug was silently re-triggered once already (2026-09-04) after a fix that looked correct (`force="true"`) but wasn't — passing tests are not proof of DB isolation.

This has now wiped the seeded dev tenant/admin/customer data twice (2026-09-04); both times it was restored with `php artisan migrate:fresh --seed`.

To run tests/Pint/artisan for this project at all, use `docker exec travel-agency-crm-app-1 ...` — the host machine's PHP (D:\xampp\php) is 8.2.12, but composer.json requires ^8.4, so nothing runs natively on the host. The app container has no bind mount for source code (only storage/ and bootstrap/cache/ are volumes), so local file edits must be `docker cp`'d into the container (or the image rebuilt) before they're visible to commands run there.

The Laravel Boost MCP server (`database-query`, `database-schema`, `application-info`, `record-rule`, etc.) is also wired to the running `travel-agency-crm-app-1` container's filesystem/app instance, not the local checkout — e.g. `record-rule` writes `.ai/rules/*.md` inside the container. After using it, `docker cp` the result back into the local repo (`docker cp travel-agency-crm-app-1:/var/www/html/.ai/rules/. .ai/rules/`) so it's actually tracked by git.

## Git Bash on Windows mangles docker cp/exec paths unless MSYS_NO_PATHCONV=1 is exported first
On this Windows host, running `docker cp <file> travel-agency-crm-app-1:/var/www/html/...` or `docker exec travel-agency-crm-app-1 cat /var/www/html/...` from Git Bash silently mangles the container-side absolute path (MSYS path conversion rewrites `/var/www/html/...` into a Windows path like `C:/Program Files/Git/var/www/html/...` before it reaches Docker), causing `docker cp` to fail with "Could not find the file ... in container" and `docker exec` reads/writes to silently miss the real path — even though the same command sometimes appears to "succeed" with no output.

Fix: put `export MSYS_NO_PATHCONV=1` as its own line at the top of the Bash script (setting it inline per-command, e.g. `MSYS_NO_PATHCONV=1 docker cp ...`, was NOT sufficient and still failed in testing) — then `docker cp`/`docker exec` container-side absolute paths work correctly for the rest of that script.

Also: `docker cp <dir> container:<existing-dir>` copies the source as a *subdirectory* of the destination if the destination dir already exists (nesting, e.g. `RoleResource/RoleResource/`) rather than merging contents — copy the directory's *contents* (or `rm -rf` the destination first) instead of the directory itself when it may already exist.
