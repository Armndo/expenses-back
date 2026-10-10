# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Laravel 11 REST API for personal expense tracking. PHP 8.2+, PostgreSQL, Laravel Passport OAuth authentication.

## Commands

- **Dev server**: `composer run dev` (starts PHP server, queue, Pail logs, and Vite concurrently)
- **Tests**: `./vendor/bin/pest`
- **Single test**: `./vendor/bin/pest --filter="test name"` or `./vendor/bin/pest tests/Feature/ExampleTest.php`
- **Tests hit a real DB**: `phpunit.xml` doesn't set one, so they use whatever `.env` points to (the remote dev DB). Run DB-touching tests against the local `expenses_test` database (needs migrations + `php artisan passport:client --personal` once):
  `DB_CONNECTION=pgsql_local DB_DATABASE_LOCAL=expenses_test ./vendor/bin/pest`
  `tests/Feature/AuthTokenTest.php` skips itself unless the DB is named `expenses_test`. Never run `RefreshDatabase` against `expenses` or the remote DB.
- Routes have no `/api` prefix (`apiPrefix: "/"` in `bootstrap/app.php`), so tests call `/login`, `/data`, etc. `users` requires `username` and `lastname`, which `UserFactory` doesn't fill.
- **Code formatting**: `./vendor/bin/pint`
- **Migrations**: `php artisan migrate`

## Architecture

API-only application — all routes defined in `routes/api.php`, no web UI logic.

**Auth tokens**: `POST /login` takes an optional `device` (`android` → 90-day token, anything else → `web`, 1 day) and names the token after it. `POST /logout` revokes only the current token.

**Routes** (`routes/api.php`): `POST /login` is public; everything else is under `auth:api` — `POST /logout`, `GET /info`, `GET /data`, `POST|PUT|DELETE /expenses[/{expense_id}]` and `POST|PUT|DELETE /incomes[/{income_id}]`.

**Controllers:**
- `AppController` — invokable, primary data endpoint (`GET /data?date=YYYY-MM-DD`). Defaults to the current month; the date is normalized to the month's first day. See "GET /data" below.
- `ExpenseController` — store/update/destroy for expenses (no index/show; reads go through `/data`)
- `IncomeController` — store/update/destroy for incomes, same ownership rules as expenses (source and move target must be the user's, `400 "error"` otherwise); `amount` must be numeric and non-zero, `date` a valid date. Tests in `tests/Feature/IncomeTest.php`.
- `UserController` — login/logout/info with Passport token auth

**Model relationships:**
- `User` → has many `Source`
- `Source` (bank/payment source with `cutoff` day) → has many `Expense`, has many `Income`
- `Expense` → belongs to `Source` and `Category`; has `next` (boolean, next-period billing) and `instalments` (multi-month splits)
- `Category` → has many `Expense`; has `alias`, `order`, `color`

**Database**: PostgreSQL required — queries use PG-specific functions (`date_trunc`, `interval`, `case ... when true`).

### GET /data

Response: `{ "expenses": [...sources], "categories": [...], "summary": {...} }`.

- Each source in `expenses` includes its `incomes` (within the billing period), `incomes_count`, plus `expenses`/`expenses_count` (regular) and `instalments`/`instalments_count` (expenses with non-null `instalments`). Sources are loaded once and expenses are split with `->partition()` in PHP.
- Each category carries `expenses_count` and `expenses_sum_amount`, computed in PHP from one eager-loaded expense set (instalments contribute `amount / instalments`); its `expenses` relation is hidden in the output.
- `summary` is `{ spent, income, previous_spent, total_money }` for the month: `spent` (cash plus the monthly share of instalments) and `income` are the month's own, `previous_spent` is what the previous month spent, and `total_money` is the running balance: income from `config("expenses.balance_start")` (env `BALANCE_START`, default `2026-09-01`) up to this month, minus the spent of every month from the start up to the previous one (what is already paid). `null` before the start month. An income dated in the start month acts as the opening balance. Spent months are summed one by one because instalments spread over several. Tests in `tests/Feature/SummaryTest.php`.
- **Billing period**: a month runs from `first of month + source.cutoff` days to `first of next month - 1 + cutoff`. Cutoff is per source (null behaves as 0, i.e. the calendar month), so the expense and income queries join `sources` (`$periodStart`/`$periodEnd` in `AppController` hold the income range). Incomes have no `next` or instalments.
- **`next` flag**: when true, the expense's effective date is `date + 1 month` (billed in the next period). Every date filter must use that effective date, not `date`.
- **Instalments**: an expense with `instalments = N` is active in each of N consecutive periods starting from its effective date.
- The expense period filter lives once, in `AppController::billedIn()` (qualified `expenses.` columns, query already joined with `sources`), and is shared by the sources query, the categories query and `spent()`. `period()` holds the cutoff range used by it and by the incomes queries.

## Conventions

- 2-space indentation in PHP (not PSR-12 4-space); match the surrounding file.
- Raw SQL fragments use heredocs (`<<<SQL`) inside `whereRaw`.
- Sensitive local config (`.claude/settings.local.json`, `.env`, `*.dump` DB dumps) must not be committed.

## Git Conventions

Conventional commits enforced via Commitlint + Husky (e.g., `feat(appcontroller):`, `fix:`, `chore:`). Commitizen configured in `.cz.toml`.
