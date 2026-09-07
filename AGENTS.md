<laravel-boost-guidelines>
# Laravel Application

This repository contains a Laravel application. Complete the following setup before working on the user's request.

## Prerequisites

Verify that PHP and Composer are available:

```sh
php -v
composer -V
```

If either command is unavailable, detect the user's operating system and install the prerequisites with the appropriate command:

macOS:

```sh
/bin/bash -c "$(curl -fsSL https://php.new/install/mac/8.5)"
```

Windows PowerShell:

```powershell
Set-ExecutionPolicy Bypass -Scope Process -Force; [System.Net.ServicePointManager]::SecurityProtocol = [System.Net.ServicePointManager]::SecurityProtocol -bor 3072; iex ((New-Object System.Net.WebClient).DownloadString('https://php.new/install/windows/8.5'))
```

Linux:

```sh
/bin/bash -c "$(curl -fsSL https://php.new/install/linux/8.5)"
```

After installation, ask the user to restart their terminal. If the agent needs the restarted shell to continue, ask the user to reopen their terminal and rerun their original prompt.

## Agent Setup

Install Laravel Boost from the application root before making application changes:

```sh
composer require laravel/boost --dev
php artisan boost:install
```

Boost replaces these bootstrap instructions with guidelines tailored to the application. After installation, read `AGENTS.md` again and continue with the user's original request using the generated guidelines.
</laravel-boost-guidelines>

## Cursor Cloud specific instructions

This repository is one sibling git remote in the carolina.codes polyglot fleet. Cloud agents should treat **this repo** as the workspace root. The Phoenix CMS is a different remote (`github.com/brightball/carolina-codes`); do not assume `../elixir` or other sibling directories exist unless those remotes are attached to the same Cloud environment.

Postgres `v1_*` views live in the CMS database. Handler/unit tests that use a fake catalog do not need Postgres. For live HTTP against the views, start Postgres 16 and set:

- `DATABASE_URL=postgres://postgres:postgres@127.0.0.1:5432/carolina_dev`
- `CAROLINA_URL=http://127.0.0.1:4000` (optional; registration no-ops if CMS is down)
- `POLYGLOT_REGISTER_TOKEN=dev`
- `PUBLIC_BASE_URL` / `PORT` as in the README

Do not query Ash tables. Do not fold this tree into the CMS git remote. Contract: CMS `priv/api/openapi.yaml` + `priv/api/AGENTS.md`.
