# Decisions

Initial log of choices already embodied in this API. Dates are the commits that introduced them. Add a new entry in the same commit when a durable decision changes. Do not record secrets, tokens, passwords, or private hostnames.

## 2026-09-05 — Laravel, not the raw PHP SAPI

This repository is the Laravel sibling. `GET /` reports language `PHP` and framework `Laravel`. The process is served by `php artisan carolina:serve`, which calls Laravel’s `serve` command. The raw PHP built-in SAPI implementation is a different repository and is not this tree.

## 2026-09-05 — Catalog access is PDO, not Eloquent

`App\Catalog` opens one PDO connection from `DATABASE_URL` and queries PostgreSQL `v1_*` views. The SQL strings name `v1_speakers`, `v1_sponsors`, `v1_years`, `v1_talks`, `v1_sponsorships`, and `v1_year_sponsors`. There are no Eloquent models for speakers or sponsors. Queries do not name Ash resource tables or base catalog tables (`speakers`, `organizations`, `talks`). The views live in the CMS database. This repo does not vendor the OpenAPI file or the SQL seed. The contract is `priv/api/openapi.yaml` on `github.com/brightball/carolina-codes`.

## 2026-09-05 — Laravel’s own database is sqlite `:memory:`

`DB_CONNECTION` is sqlite and `DB_DATABASE` is `:memory:` in `.env.example` and `phpunit.xml`. `artisan` exits 2 before boot for `migrate`, `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `migrate:rollback`, `migrate:install`, `db:wipe`, `db:seed`, `schema:dump`, and `schema:load`. Those commands must not alter the shared Postgres catalog. Catalog SQL stays on the separate PDO connection.

## 2026-09-05 — Local listen port 4022

Local `PORT` and `PUBLIC_BASE_URL` use 4022. `Catalog::registerWithElixir()` and `carolina:serve` default to 4022 when `PORT` is unset. That keeps this sibling off the ports used by the other language APIs.

## 2026-09-05 — Five quality gates

Every commit runs five checks, as pre-commit hooks and as CI jobs: application tests (`php artisan test`), Psalm taint analysis, `composer audit --locked`, `gitleaks detect --source .`, and Pint (`vendor/bin/pint --test`). gitleaks 8.30.1 is pinned in `mise.toml`. Psalm, Pint, Pail, and Pao are Composer dev dependencies. Pail tails logs from the CLI. Pao is agent-oriented test output.

## 2026-09-22 — Register once, off the health path

`carolina:serve` starts a single `Catalog::registerWithElixir()` call, then listens. An empty `CAROLINA_URL` or `POLYGLOT_REGISTER_TOKEN` skips the call and the server still starts. A second call in the same process is ignored. A failed POST is logged (`register: failed`) and the server keeps serving. There is no heartbeat. `GET /health` returns `{"status":"ok"}`, does not open PDO, and does not register.
