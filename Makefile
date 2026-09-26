COMPOSE = docker compose
APP     = $(COMPOSE) exec -T app

.PHONY: up down shell test lint seed load
up:      ; $(COMPOSE) up -d --build
down:    ; $(COMPOSE) down
shell:   ; $(COMPOSE) exec app bash
test:    ; $(APP) vendor/bin/pest --exclude-group=network --exclude-group=browser
lint:    ; $(APP) vendor/bin/pint --test && $(APP) vendor/bin/phpstan analyse --memory-limit=1G
seed:    ; $(APP) php artisan migrate:fresh --seed --force
# Dev-stack smoke run only (bind-mounted code, slow by design) - its numbers are not
# comparable to docs/quality.md's Capacity table, which is measured against a
# production-mode container built by scripts/perf-container.sh. The key is passed
# through the container's environment (-e LOAD_KEY, no value: docker compose reads it
# from this shell's own environment), never as a --key=... argument, so it never lands
# in `ps` output; scripts/load.php falls back to $LOAD_KEY when --key is absent.
# `export LOAD_KEY=vd_live_...` first (prefix that line with a space if your shell's
# HISTCONTROL is set to ignorespace, to keep it out of shell history too), and never
# paste a live key into a shared or recorded terminal.
load:    ; @$(COMPOSE) exec -e LOAD_KEY -T app php scripts/load.php --base=$(or $(BASE),http://localhost) --users=$(or $(USERS),100) --seconds=$(or $(SECONDS),60) --think=$(or $(THINK),200-1000)
