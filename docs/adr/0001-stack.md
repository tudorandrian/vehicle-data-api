# ADR 0001 - Laravel 13 on PHP 8.4, MariaDB 10.5, OpenLiteSpeed parity

Date: 2026-09-16 · Status: accepted

## Context
The API must run on ordinary shared hosting (cPanel with LiteSpeed, MultiPHP, MariaDB, one cron line, no Redis, no long-running processes), be cheap to operate, and show current PHP practice. Local development must behave like that target, not like a VPS.

## Decision
- Laravel 13 (an application plus an in-repo package, ADR 0002) on PHP 8.4; `composer.json` requires `^8.4` (Pest 5 needs it and nothing verifies 8.3), CI runs 8.4 as required and 8.5 as allowed to fail.
- MariaDB 10.5, with `utf8mb4_romanian_ci` on name and label columns; the database also backs the cache and the queue (`CACHE_STORE=database`, `QUEUE_CONNECTION=database`).
- Docker Compose for development with `litespeedtech/openlitespeed:1.8.5-lsphp84` and `mariadb:10.5`: the same web server family and PHP SAPI as the target, so `public/.htaccess` works unchanged on both. *(The `mariadb:10.5` part is superseded by the 2026-09-26 amendment below: the dev stack's MariaDB tag is `VD_MARIADB_TAG`, 10.5 by default.)*
- The scheduler (`schedule:run` every minute) drives the queue worker and the periodic jobs.

Rejected: **Symfony** (fewer built-in pieces for keys, throttling, queues and scheduling on a database-only host); **Node** (no first-class runtime on the shared-hosting target); **Nginx + PHP-FPM + MySQL** locally (would hide LiteSpeed rewrite and SAPI differences until deploy).

## Consequences
Everything runs on one shared account with one cron line. Performance relies on indexes and ETags instead of Redis or a warm worker, and p95 is asserted in tests. Moving to a VPS later changes only the web server configuration, the cron line and `.env` (docs/deployment-cpanel.md §10).

## Database lifecycle (amended 2026-09-17)
MariaDB 10.5 reached end of life in June 2025. It stays the target because it is what the shared-hosting account provides (parity beats currency here) and nothing in the schema needs a newer server. CI also runs the suite on MariaDB 10.11 (LTS, allowed to fail) so an upgrade is a one-line change when the host offers it. Review this decision at the next hosting change. *(The "allowed to fail" 10.11 leg is superseded by the 2026-09-26 amendment below: both legs are required.)*

Amended 2026-09-26 (Phase 2): CI runs the suite on MariaDB 10.5 and 10.11 as two required legs. 10.5 stays the compatibility floor because the hosting account provides it; 10.11 (LTS) is the supported baseline the project is verified against, and the version to move to at the next hosting change. The dev stack tag is VD_MARIADB_TAG.
