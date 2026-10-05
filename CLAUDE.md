# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Laravel 11 REST API for personal expense tracking. PHP 8.2+, PostgreSQL, Laravel Passport OAuth authentication.

## Commands

- **Dev server**: `composer run dev` (starts PHP server, queue, Pail logs, and Vite concurrently)
- **Tests**: `./vendor/bin/pest`
- **Single test**: `./vendor/bin/pest --filter="test name"` or `./vendor/bin/pest tests/Feature/ExampleTest.php`
- **Code formatting**: `./vendor/bin/pint`
- **Migrations**: `php artisan migrate`

## Architecture

API-only application — all routes defined in `routes/api.php`, no web UI logic.

**Routes** (`routes/api.php`): `POST /login` is public; everything else is under `auth:api` — `POST /logout`, `GET /info`, `GET /data`, and `POST|PUT|DELETE /expenses[/{expense_id}]`.

**Controllers:**
- `AppController` — invokable, primary data endpoint (`GET /data?date=YYYY-MM-DD`). Defaults to the current month; the date is normalized to the month's first day. See "GET /data" below.
- `ExpenseController` — store/update/destroy for expenses (no index/show; reads go through `/data`)
- `UserController` — login/logout/info with Passport token auth

**Model relationships:**
- `User` → has many `Source`
- `Source` (bank/payment source with `cutoff` day) → has many `Expense`, has many `Income`
- `Expense` → belongs to `Source` and `Category`; has `next` (boolean, next-period billing) and `instalments` (multi-month splits)
- `Category` → has many `Expense`; has `alias`, `order`, `color`

**Database**: PostgreSQL required — queries use PG-specific functions (`date_trunc`, `interval`, `case ... when true`).

### GET /data

Response: `{ "expenses": [...sources], "categories": [...] }`.

- Each source in `expenses` includes its `incomes` (within the month), `incomes_count`, plus `expenses`/`expenses_count` (regular) and `instalments`/`instalments_count` (expenses with non-null `instalments`). Sources are loaded once and expenses are split with `->partition()` in PHP.
- Each category carries `expenses_count` and `expenses_sum_amount`, computed in PHP from one eager-loaded expense set (instalments contribute `amount / instalments`); its `expenses` relation is hidden in the output.
- **Billing period**: a month runs from `first of month + source.cutoff` days to `first of next month - 1 + cutoff`. Cutoff is per source, so the expense queries join `sources`.
- **`next` flag**: when true, the expense's effective date is `date + 1 month` (billed in the next period). Every date filter must use that effective date, not `date`.
- **Instalments**: an expense with `instalments = N` is active in each of N consecutive periods starting from its effective date.
- The expense period filter exists twice (sources query with unqualified columns, categories query with `expenses.`-qualified columns). Keep both in sync when changing billing logic.

## Conventions

- 2-space indentation in PHP (not PSR-12 4-space); match the surrounding file.
- Raw SQL fragments use heredocs (`<<<SQL`) inside `whereRaw`.
- Sensitive local config (`.claude/settings.local.json`, `.env`, `*.dump` DB dumps) must not be committed.

## Git Conventions

Conventional commits enforced via Commitlint + Husky (e.g., `feat(appcontroller):`, `fix:`, `chore:`). Commitizen configured in `.cz.toml`.
