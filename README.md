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

Shipped PHP is the `Dockerfile` image `php:8.5-cli`. The framework is Laravel 13 (`laravel/framework` `^13`). `composer.json` also allows PHP `^8.3`; the image is the version this API ships.

Notable packages: Psalm (taint SAST), Pint, Pail, Pao (agent-oriented test output), and gitleaks.

```bash
php artisan test --filter=PolyglotApiTest
```

## Quality gates

Five checks run locally as distinct pre-commit hooks. On Gitea Actions a first-stage `prepare` job clones `GITHUB_SHA`, installs PHP extensions, Composer, `vendor/`, and gitleaks once, and publishes that tree as an artifact. The five check jobs restore that environment and each run only their command (`.gitea/workflows/precommit.yml`):

| Check | Command |
| --- | --- |
| Application tests | `php artisan test` |
| SAST (Psalm taint) | `vendor/bin/psalm --taint-analysis --no-cache --memory-limit=1G` |
| Dependency advisories | `composer audit --locked` |
| Secret detection | `gitleaks detect --source .` |
| Code style | `vendor/bin/pint --test` |

Install hooks (needs [pre-commit](https://pre-commit.com) on PATH):

```bash
composer install
mise install
pre-commit install
```

Without the Python runner, `git config core.hooksPath .githooks` runs the same five commands. Emergency skip: `SKIP=tests,sast,audit,gitleaks,pint git commit`. `gitleaks` comes from `mise.toml` (`mise install`) or any PATH install of gitleaks 8.30.1.
