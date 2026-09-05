# carolina-codes-laravel

Read-only v1 polyglot API for Carolina Code Conference. **Laravel** (PHP + `laravel/framework`, not the raw `php/` built-in SAPI sibling).

Catalog SQL uses PDO against PostgreSQL `v1_*` views. Laravel’s own `DB_CONNECTION` is sqlite `:memory:` so `artisan migrate` cannot reach the shared catalog — and `artisan` refuses migrate/wipe commands.

```bash
DATABASE_URL=postgres://postgres:postgres@127.0.0.1:5432/carolina_dev \
CAROLINA_URL=http://127.0.0.1:4000 \
POLYGLOT_REGISTER_TOKEN=dev \
PUBLIC_BASE_URL=http://127.0.0.1:4022 \
PORT=4022 \
php artisan carolina:serve --host=[::] --port=4022
```

`GET /` reports `language: "PHP"` and `framework: "Laravel"`. `GET /health` returns `{"status":"ok"}` without touching Postgres.

```bash
php artisan test --filter=PolyglotApiTest
```
