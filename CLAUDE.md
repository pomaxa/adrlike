# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Stack & runtime

- **PHP 8.5** + **Symfony 8.0** + **Postgres 17** + **Mailpit** (SMTP sink).
- Everything runs inside `docker compose`. There is **no host-side PHP/Composer workflow** — always shell in via `docker compose exec`.
- Symfony 8 ecosystem caveat: some bundles haven't caught up. This project uses `doctrine/doctrine-bundle ^3.2` and `doctrine/doctrine-migrations-bundle ^4.0` which support Symfony 8; the default Symfony Flex recipe tries `^2.13` which does not resolve.

## Common commands

All commands below assume the stack is running. If you see "no such container," run `docker compose up -d --build` first.

```bash
# day-to-day
docker compose exec app composer install
docker compose exec app php bin/console cache:clear
docker compose exec app php bin/console doctrine:migrations:diff
docker compose exec app php bin/console doctrine:migrations:migrate -n
docker compose exec app php bin/console doctrine:fixtures:load -n    # seeds admin@example.com / admin

# CSV import (host path, mounted into /app)
docker compose exec app php bin/console app:import-csv /app/var/import_sample.csv
docker compose exec app php bin/console app:import-csv /app/path.csv --encoding=Windows-1251 --dry-run

# merge duplicate users (interactive: --it)
docker compose exec -it app php bin/console app:users:merge
docker compose exec app php bin/console app:users:merge <source-email|uuid|name> <target> --yes

# tests — test DB must exist first
docker compose exec -T database psql -U app -d postgres -c "CREATE DATABASE app_test OWNER app ENCODING 'UTF8';" || true
docker compose exec -T -e APP_ENV=test app php bin/console doctrine:migrations:migrate -n
docker compose exec -T -e APP_ENV=test app php bin/console cache:clear
docker compose exec -T -e APP_ENV=test app php bin/phpunit --testdox
docker compose exec -T -e APP_ENV=test app php bin/phpunit tests/Controller/DecisionControllerTest.php
docker compose exec -T -e APP_ENV=test app php bin/phpunit --filter testCreateDecisionRoundTrip

# manually dispatch the follow-up reminder job (otherwise fires daily at 08:00 via scheduler container)
docker compose exec app php bin/console messenger:dispatch 'App\Message\SendFollowUpReminders'
```

### Test-environment gotchas

- **`APP_ENV=test` must be passed as an env var** to `docker compose exec`. The compose file sets `APP_ENV=dev` on the `app` service, which overrides phpunit.dist.xml's `<server name="APP_ENV" value="test" force="true"/>` unless you override it back with `-e APP_ENV=test`.
- `docker compose down -v` wipes the volume including `app_test` — you'll need to recreate it before running tests again.

### Ports

Non-standard to avoid clashes with other local projects on the same host:

| Service   | Host port | Purpose                     |
|-----------|-----------|-----------------------------|
| `web`     | 8180      | http://localhost:8180       |
| `mailer`  | 8125      | Mailpit UI (SMTP via docker net only) |
| `database`| 5533      | Postgres (host-side access) |

## Architecture

### Domain model

- **`Decision`** is the aggregate. Metrics (`asIsMetrics`, `toBeMetrics`) are stored as JSON blobs shaped `{ar?, badrate?, avgTicket?, raw?}` — the `raw` key holds free-form text when the source data is not structured (this is the common case on imports, since the legacy CSV stores metrics as prose).
- **`DecisionHistory`** is an append-only audit log. It is **populated automatically** by `App\EventSubscriber\DecisionAuditSubscriber` on Doctrine `onFlush` — do not write history rows manually from controllers. The subscriber diffs tracked fields (see the `TRACKED_FIELDS` constant), stringifies enums/dates/users, and attributes the change to the current security user (null for CLI/import).
- **`User`** has a `placeholder` flag. Placeholder users are created by the CSV importer for names that don't yet have an account (email pattern `*@imported.local`, `password = null`). The reminder handler **skips emailing placeholder users**. Merge them into real users via `app:users:merge`.
- Roles form a hierarchy in `security.yaml`: `ROLE_ADMIN > ROLE_APPROVER > ROLE_SUBMITTER`. Admin is the only role allowed to reach `/decisions/import`.

### Follow-up lifecycle

`Decision::followUpStatus` is **derived state**, not authoritative. The truth is `followUpDate` + `followUpCompletedAt`. Whenever a decision is read or saved, the controller calls `Decision::recomputeFollowUpStatus($today)` before returning/persisting so `Pending → Overdue` transitions happen lazily. Don't hardcode `followUpStatus` writes outside `recomputeFollowUpStatus()` and `markFollowUpDone()`.

### CSV import pipeline

`App\Service\CsvImporter::import(string $rawBytes, string $encoding = 'auto', bool $dryRun = false)` is the single entry point. Both the console command (`app:import-csv`) and the web controller (`App\Controller\ImportController` at `/decisions/import`) call it. It:

1. Strips any BOM, records the detected encoding.
2. If `encoding === 'auto'`, scores candidates (`UTF-8`, `Windows-1251/1252`, `KOI8-R`, `CP866`, `ISO-8859-1/5`) by printable ratio + Cyrillic-range bonus − replacement-char penalty. Returns the best.
3. Transcodes to UTF-8 if needed.
4. Reads via `League\Csv\Reader::fromString()` with `headerOffset = 1` (row 1 is the section header like "BEFORE (AR, badrate, AVG ticket)", row 2 is the real column header, data starts row 3).
5. Deduplicates by `sha256(decided_at | lower(submitter) | change)` stored in `Decision.importHash`. Re-uploads are safe.
6. Auto-creates placeholder users for unknown submitter/approver names. The email-uniqueness loop consults both the DB and an in-memory `reservedEmails` set — needed because flush happens once at the end, so same-slug collisions within a single import won't hit the DB check.

Returns a `CsvImportResult` DTO with counters + warnings + fatal error (or null).

### Scheduler & messenger

- `App\Schedule` (marked `#[AsSchedule]`) registers a daily `SendFollowUpReminders` message at `0 8 * * *`.
- The **`scheduler` compose service** runs `php bin/console messenger:consume scheduler_default -vv --time-limit=3600` continuously — it is restarted every hour and on crash by `restart: unless-stopped`.
- `App\MessageHandler\SendFollowUpRemindersHandler` buckets decisions by `followUpOwner ?? submittedBy`, sends one email per owner summarizing overdue + next-3-days items. Placeholder users are skipped.

### Form rendering

The `/decisions/new` and `/decisions/{id}/edit` pages share `templates/decision/_form.html.twig`. The form is rendered field-by-field (not with `form_row` for the whole thing) so each section can have icons and per-field input-group prefixes. **Do not add `render_rest: false` to `form_end`** — it skips the CSRF token and breaks submission.

CSRF is configured as **stateful** in `config/packages/csrf.yaml` (not the Symfony 7.2 stateless variant, which requires a JS snippet to replace the `csrf-token` placeholder — a footgun for server-rendered login pages).

### Static assets

Plain files in `public/css/` and `public/js/` — no bundler, no Webpack Encore, no AssetMapper. They're served by nginx via `try_files`. When adding new assets, reference them with `{{ asset('...') }}`.

### User management & SSO

Admin UI at `/admin/users` (requires `ROLE_ADMIN`). Covers list/search, create, edit (with self-role-change guarded), admin password reset, and placeholder promotion. `UserController::edit` uses identity comparison (`$user === $this->getUser()`) to hide and reject role changes on the acting admin.

Entra ID SSO is **additive** — the password form keeps working alongside. Driven by `SSO_ENABLED` env flag (default `false`). Library stack: `knpuniversity/oauth2-client-bundle` + `thenetworg/oauth2-azure`. Configure via `.env.local`:

```
SSO_ENABLED=true
AZURE_TENANT_ID=...
AZURE_CLIENT_ID=...
AZURE_CLIENT_SECRET=...
AZURE_REDIRECT_URI=http://localhost:8180/login/azure/check
```

The Azure callback authenticator (`App\Security\AzureAuthenticator`) delegates user resolution to `App\Security\AzureUserResolver`: (1) email match → reuse (and promote in-place if the match is a placeholder); (2) no email match but exactly one placeholder by `fullName` → promote that placeholder, replace `@imported.local` with the SSO email; (3) otherwise create a new real user with `ROLE_SUBMITTER` and null password. UUIDs are preserved during promotion, so all decision references stay intact.

When SSO is disabled, `/login/azure` and `/login/azure/check` return 404 and the login page hides the "Sign in with Microsoft" button. The admin user list page shows a status banner (`enabled` / `misconfigured` / `not_configured`) computed by `App\Service\SsoStatusProvider` from env vars; no secrets are rendered.

## Conventions specific to this repo

- Enums (`Product`, `Department`, `FollowUpStatus`) are **string-backed** with a `label()` method. For form rendering, use `EnumType::class` with `choice_label: fn($c) => $c->label()`.
- UUIDs are **v7** (time-ordered), generated in entity constructors via `Uuid::v7()`. Route requirements use `[0-9a-f-]{36}` to match them.
- Doctrine column types: use `Types::DATE_IMMUTABLE` and `DateTimeImmutable` — never mutable `DateTime`.
- Repositories expose domain methods (`findOverdueFollowUps`, `findOneByImportHash`, `queryByFilters`) — avoid inline DQL in controllers.
