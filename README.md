# Laravel Mini B2B Backend — Assessment Delivery

This repository is a **strictly B2B Alibaba-style Laravel API** (no frontend).

## Where is the application?

The Laravel application root is **`laravel-app/`**.

All install, migrate, seed, serve, route, and test commands must be run from `laravel-app/`.

There is **no** `composer.json` at the repository root. Running Composer from the repo root will fail.

| Path | Purpose |
| --- | --- |
| [`laravel-app/`](laravel-app/) | Laravel 13 application (API, migrations, seeders, tests) |
| [`laravel-app/README.md`](laravel-app/README.md) | Full setup, API, credentials, and verification guide |
| [`laravel-app/docs/`](laravel-app/docs/) | Sprint and assessment documentation |
| [`laravel-app/.env.example`](laravel-app/.env.example) | Environment template (copy to `.env`; do not commit `.env`) |

## Hosting status

**This delivery is a Git repository only.** There is **no public deployment URL** included with this assessment.

Evaluators must clone the repository and run the API locally (or on their own server) using the instructions in [`laravel-app/README.md`](laravel-app/README.md).

If you received a message that the system is “not available” or “cannot be checked” without a separate hosted URL, use the local setup below — that is the intended evaluation path.

## Evaluator quick start

Requirements: **PHP 8.4+**, Composer, SQLite (default; no external DB server required).

```bash
git clone <this-repository>
cd laravel-app

composer install
cp .env.example .env          # Windows: copy .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate:fresh --seed --force
php artisan serve
```

API base URL (local): `http://127.0.0.1:8000`

Authenticate:

```bash
curl -s -X POST http://127.0.0.1:8000/api/login \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"email":"user.a@example.com","password":"password"}'
```

Seeded demo credentials (development only): see [`laravel-app/README.md`](laravel-app/README.md#seeded-credentials-development-only).

List routes / run tests:

```bash
php artisan route:list
php artisan test
```

## Documentation map

| Topic | Location |
| --- | --- |
| Full setup + API reference | [`laravel-app/README.md`](laravel-app/README.md) |
| Sprint 0 architecture | [`laravel-app/docs/sprint-0/`](laravel-app/docs/sprint-0/) |
| Assessment 2 multi-party confidentiality | [`laravel-app/docs/assessment-2/`](laravel-app/docs/assessment-2/) |
| Sprint 17 production foundation | [`laravel-app/docs/sprint-17/`](laravel-app/docs/sprint-17/) |
| Sprint 18 company onboarding | [`laravel-app/docs/sprint-18/`](laravel-app/docs/sprint-18/) |
| Sprint 19 approval workflows | [`laravel-app/docs/sprint-19/`](laravel-app/docs/sprint-19/) |

## Delivered scope (do not expect Sprint 20+)

Completed and in this codebase: Sprint 1–16 core B2B engine, Assessment 2 confidentiality, Sprint 17 foundation, Sprint 18 onboarding, Sprint 19 approval workflows.

**Not included:** Sprint 20 notifications, public hosting, frontend UI.
