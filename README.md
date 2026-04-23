# Decision Recording

Symfony 8.0 web application for logging product/risk decisions — who decided what, expected vs. actual metrics, follow-up tracking, and email reminders for overdue follow-ups.

Replaces the shared spreadsheet workflow.

## Stack

- PHP 8.5 (FPM, Debian-based image)
- Symfony 8.0 (framework-bundle, security-bundle, doctrine-bundle 3.x, scheduler, messenger, mailer)
- PostgreSQL 17 (UTF-8, `C.UTF-8` ctype)
- Mailpit (SMTP sink + web UI for dev)
- Nginx
- Everything runs inside `docker compose` — no host-side PHP/Composer needed.

## Services (all in `compose.yaml`)

| Service     | Purpose                                            | Host port |
|-------------|----------------------------------------------------|-----------|
| `app`       | PHP-FPM (Symfony kernel)                            | —         |
| `web`       | Nginx → app                                         | 8180      |
| `database`  | Postgres 17 (UTF-8)                                 | 5533      |
| `mailer`    | Mailpit (SMTP + UI)                                 | 8125      |
| `scheduler` | `messenger:consume scheduler_default` for reminders | —         |

## Quickstart

```bash
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php bin/console doctrine:migrations:migrate -n
docker compose exec app php bin/console doctrine:fixtures:load -n          # seeds admin@example.com / admin
docker compose exec app php bin/console app:import-csv /app/var/import_sample.csv
```

Then open http://localhost:8180/login  →  `admin@example.com` / `admin`.

Mailpit UI: http://localhost:8125

## Common commands

```bash
# clear cache / rerun migrations
docker compose exec app php bin/console cache:clear
docker compose exec app php bin/console doctrine:migrations:diff
docker compose exec app php bin/console doctrine:migrations:migrate -n

# import additional CSV (auto-detects encoding: UTF-8, Windows-1251, CP866, etc.)
docker compose exec app php bin/console app:import-csv /app/path/to/file.csv
# or force an encoding:
docker compose exec app php bin/console app:import-csv /app/path/to/file.csv --encoding=Windows-1251

# trigger follow-up reminders manually
docker compose exec app php bin/console messenger:dispatch 'App\Message\SendFollowUpReminders'

# tests
docker compose exec database psql -U app -d postgres -c "CREATE DATABASE app_test OWNER app;" || true
docker compose exec -e APP_ENV=test app php bin/console doctrine:migrations:migrate -n
docker compose exec -e APP_ENV=test app php bin/phpunit
```

## Data model

- `User` — auth + placeholder entries auto-created by the CSV importer for names without an actual login.
- `Decision` — the main record. Fields mirror the legacy CSV; metrics (as-is / to-be) are JSON blobs with `ar / badrate / avgTicket / raw`.
- `DecisionHistory` — append-only audit trail, populated automatically by the `DecisionAuditSubscriber` on every update.

## Notes

- The importer auto-detects source encoding (UTF-8 BOM, Windows-1251/1252, KOI8-R, CP866, ISO-8859-1/5) and transcodes everything to UTF-8. Pass `--encoding=<name>` to override. Database and client encoding are pinned to UTF-8.
- The legacy CSV has Cyrillic submitter names stored as `?` bytes in the source (data corruption predates the import). Imported users keep the `?` names; rename via the DB or a future admin UI.
- `scheduler` service runs `messenger:consume scheduler_default` which fires `SendFollowUpReminders` daily at 08:00 UTC. The handler emails each follow-up owner a summary of overdue + upcoming items.
