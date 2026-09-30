# Docker shortcuts for the Travel Agency CRM.
# Run `make` (or `make help`) to list every command with a short description.
#
# Needs GNU make (Windows: `choco install make`) and is meant to be run from Git Bash.
# The app image has no bind mount: code changes only reach the containers after `make update`.

COMPOSE ?= docker compose
APP     ?= app
ARTISAN  = $(COMPOSE) exec $(APP) php artisan

# Stops Git Bash rewriting container paths like /var/www/html into Windows paths.
export MSYS_NO_PATHCONV := 1

.DEFAULT_GOAL := help
.PHONY: help build rebuild up start down stop restart update ps logs logs-app shell tinker \
	artisan migrate seed fresh test pint cache-clear queue-restart db-shell vendor assets \
	clean clean-local reinstall

help: ## List every command with its description
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-15s\033[0m %s\n", $$1, $$2}'

## ---- Containers --------------------------------------------------------------

build: ## Build the app/queue/scheduler images (uses Docker's layer cache)
	$(COMPOSE) build

rebuild: ## Build the images from scratch: no cache, fresh base images
	$(COMPOSE) build --no-cache --pull

up: ## Start every container in the background and wait until they are healthy
	$(COMPOSE) up -d --wait

start: up ## Same as `up`

down: ## Stop and remove the containers (database and uploads are kept)
	$(COMPOSE) down --remove-orphans

stop: ## Pause the containers without removing them
	$(COMPOSE) stop

restart: ## Restart every container (does NOT pick up code changes, see `update`)
	$(COMPOSE) restart

update: ## Rebuild changed layers and recreate the containers: deploys your latest code into Docker
	$(COMPOSE) up -d --build --wait

ps: ## Show container status and health
	$(COMPOSE) ps

logs: ## Follow the logs of every container (Ctrl+C to stop)
	$(COMPOSE) logs -f --tail=100

logs-app: ## Follow the Laravel application log (storage/logs/laravel.log)
	$(COMPOSE) exec $(APP) tail -f -n 100 storage/logs/laravel.log

shell: ## Open a shell inside the app container
	$(COMPOSE) exec $(APP) bash

## ---- Laravel -----------------------------------------------------------------

tinker: ## Open Laravel Tinker in the app container
	$(ARTISAN) tinker

artisan: ## Run any artisan command, e.g. make artisan cmd="route:list"
	$(ARTISAN) $(cmd)

migrate: ## Run new database migrations
	$(ARTISAN) migrate --force

seed: ## Run the database seeders (demo data, and HBL UAT gateway when HBL_UAT_* is set in .env)
	$(ARTISAN) db:seed --force

fresh: ## DROP every table, re-run all migrations and seed the demo data
	$(ARTISAN) migrate:fresh --seed --force

test: ## Run the test suite; narrow it with e.g. make test args="tests/Feature/HblGatewayTest.php"
	$(ARTISAN) test --compact $(args)

pint: ## Format changed PHP files with Laravel Pint
	$(COMPOSE) exec $(APP) vendor/bin/pint --dirty --format agent

cache-clear: ## Clear Laravel's config, route, view and application caches
	$(ARTISAN) optimize:clear

queue-restart: ## Tell queue workers to restart after their current job
	$(ARTISAN) queue:restart

db-shell: ## Open psql on the project's Postgres database
	$(COMPOSE) exec pgsql sh -c 'psql -U "$$POSTGRES_USER" -d "$$POSTGRES_DB"'

## ---- Local files (for the editor) -----------------------------------------

vendor: ## Copy vendor/ out of the app container, so the editor sees PHP 8.4 dependencies
	rm -rf vendor
	docker cp "$$($(COMPOSE) ps -q $(APP)):/var/www/html/vendor" ./vendor

assets: ## Reinstall node_modules and build the frontend assets locally
	npm ci
	npm run build

## ---- Clean slate ---------------------------------------------------------------

clean: ## DANGER: remove containers, built images AND volumes (database, uploads) for this project
	$(COMPOSE) down --volumes --rmi local --remove-orphans

clean-local: ## Delete local vendor/, node_modules/, public/build and cached framework files
	rm -rf vendor node_modules public/build
	rm -f bootstrap/cache/*.php
	find storage/framework/cache/data storage/framework/sessions storage/framework/views -type f ! -name .gitignore -delete

reinstall: clean clean-local rebuild up fresh vendor assets ## DANGER: wipe everything and set the project up again from scratch
	@echo "Done: app is running on $${APP_URL:-http://localhost:8000} with fresh demo data."
