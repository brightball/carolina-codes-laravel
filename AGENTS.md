# Polyglot language API (Laravel)

Instructions for this read-only HTTP API. A Carolina Code Conference Elixir site can rotate onto it.

This repository is the **Laravel** sibling (PHP + `laravel/framework`), not the raw PHP SAPI sibling and not the forkable starter. It is served by `php artisan carolina:serve`. The local listen port is **4022**.

Responses are ordinary JSON. Do **not** implement Ash JSON:API (`application/vnd.api+json`). Siblings speak ordinary JSON over the v1 REST + SQL-view contract.

The **source of truth** for routes and payloads that this tree does not vendor is the CMS OpenAPI: `priv/api/openapi.yaml` and `priv/api/AGENTS.md` on `github.com/brightball/carolina-codes`. Do not add a local `openapi.yaml`. If you change the public contract, change it there.

You do **not** need a checkout of the Elixir CMS to run this API. Treat this repo as the workspace root. Do not fold this tree into the CMS git remote. Registration is best-effort: if `CAROLINA_URL` is unset or the CMS is down, log and keep serving.

## Purpose

The Phoenix app (`Carolina.Polyglot`) keeps **at most one** language API warm and reads speakers and sponsors from it. With no APIs registered, it falls back to Ash. This process must:

1. Query PostgreSQL **v1 views**, never Ash resource tables or base catalog tables.
2. Expose the starter GET routes below.
3. **Register once on boot** with the Elixir site (no heartbeat). If the site is not running, log and continue. Registration is not on the health path.

## Environment

| Variable | Example | Role |
|---|---|---|
| `DATABASE_URL` | `postgres://postgres:postgres@127.0.0.1:5432/carolina_dev` | SQL views in the CMS database |
| `CAROLINA_URL` | `http://127.0.0.1:4000` | Elixir site (optional; register no-ops if down) |
| `POLYGLOT_REGISTER_TOKEN` | `dev` | Bearer token for register |
| `PUBLIC_BASE_URL` | `http://127.0.0.1:4022` | URL Elixir will call |
| `PORT` | `4022` | Local listen port |

Handler tests that install a fake catalog do not need Postgres. For live HTTP against the views, point `DATABASE_URL` at the CMS database. The `v1_*` views live there. This repo does not pin a Postgres server version.

Laravel’s own database stays on sqlite `:memory:` (`DB_CONNECTION` / `DB_DATABASE`). Catalog SQL does not use that connection. `artisan` refuses migrate and wipe commands so they cannot alter the shared Postgres catalog.

## SQL views (query these)

`v1_speakers`, `v1_sponsors`, `v1_years`, `v1_talks`, `v1_sponsorships`, `v1_year_speakers`, `v1_year_sponsors`.

Catalog access is **PDO** in `App\Catalog`, opened from `DATABASE_URL`. Do not use Eloquent for speakers or sponsors.

Year-scoped listing filters need `languages` and `topics` on speaker rows. Year-scoped sponsor rows include `tier`.

Do not `SELECT` from `speakers`, `organizations`, `talks`, or other base tables as the public contract. The views are the API. Do not query Ash tables.

## Required HTTP routes

Wrap list payloads as `{ "data": [ ... ] }`. Unknown slugs return 404 `{ "error": "not_found" }`.

- `GET /health` — database-free liveness (`{ "status": "ok" }`). Does not open PDO and does not register.
- `GET /` — identity (language, framework, api_version, endpoints)
- `GET /v1/years`
- `GET /v1/speakers` and `GET /v1/speakers?year=`
- `GET /v1/speakers/{slug}` and `GET /v1/speakers/{year}/{slug}`
- `GET /v1/sponsors` and `GET /v1/sponsors?year=`
- `GET /v1/sponsors/{slug}` and `GET /v1/sponsors/{year}/{slug}`

`photo_path` / `logo_path` values are web paths. Return the path; the CMS usually hosts the bytes.

## Register on boot (once)

`POST {CAROLINA_URL}/internal/api-endpoints/register`

```
Authorization: Bearer {POLYGLOT_REGISTER_TOKEN}
Content-Type: application/json
```

Body fields: `language`, `language_version`, `api_version`, `framework`, `created_year`, `base_url` (`PUBLIC_BASE_URL`), `schema_version` (1), `endpoints` (list of `"GET /path"` strings).

`php artisan carolina:serve` registers once, then listens. There is no heartbeat. Elixir keep-alives the currently warm API.

If `CAROLINA_URL` is empty or the CMS is down, log and keep serving. Registration is not on the health path. A forked API is useful without a live CMS.

## Layout

| Path | Role |
|---|---|
| `app/Catalog.php` | PDO against `v1_*` views, identity, one-shot register |
| `app/Console/Commands/CarolinaServeCommand.php` | `carolina:serve` |
| `routes/api.php` | GET routes above |
| `artisan` | Refuses migrate and wipe before boot |
| `Dockerfile` | `php:8.5-cli` image; `CMD` is `php artisan carolina:serve` |
| `tests/Feature/PolyglotApiTest.php` | Contract tests, including these docs |
| CMS `priv/api/openapi.yaml` | HTTP contract (other remote) |

## Agent memory

Durable knowledge is committed Markdown in this repo. Read [MEMORY.md](MEMORY.md) and [DECISIONS.md](DECISIONS.md) before changing behavior. Update `DECISIONS.md` in the same commit when a durable decision changes.

Do not add an embedding store. Do not add Laravel Boost `.ai/rules`. Do not keep project memory only outside git. Do not install Laravel Boost or replace this file with generated framework guidelines.

## Checklist

- [x] OpenAPI paths return 200 with example-shaped JSON (404 on unknown slug)
- [x] `?year=` listing rows include `languages` / `topics` (speakers) and `tier` (sponsors)
- [x] Register runs once at process start; no-ops if the Elixir site is down
- [x] No writes; no Ash table names
- [x] `GET /health` is cheap and avoids the database
