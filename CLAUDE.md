# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Laravel 11 REST API for personal expense tracking. PHP 8.2+, PostgreSQL, Laravel Passport OAuth authentication.

## Commands

- **Dev server**: `composer run dev` (starts PHP server, queue, Pail logs, and Vite concurrently)
- **Tests**: `./vendor/bin/pest` (single file: `./vendor/bin/pest tests/Feature/DataTest.php`, single test: `--filter="test name"`)
- **Tests always run against the local `expenses_test` database**: `phpunit.xml` forces `DB_CONNECTION=pgsql_local` and `DB_DATABASE_LOCAL=expenses_test`, so no env vars are needed. The database needs its migrations (`DB_CONNECTION=pgsql_local DB_DATABASE_LOCAL=expenses_test php artisan migrate`) and the local connection settings (`DB_HOST_LOCAL`, `DB_USERNAME_LOCAL`, ... in `.env`). `Tests\TestCase` throws on every test unless the database name ends in `_test`, and creates the Passport personal access client when it is missing. Every Feature test runs inside a rolled-back transaction (`DatabaseTransactions` in `tests/Pest.php`); never use `RefreshDatabase`. A failed SQL statement poisons the Postgres transaction, so do not query after an assertion on a 400 caused by a database error.
- **Factories** (`database/factories`): `User` (password is `password`), `Source` (`->cutoff(15)`), `Category`, `Expense` (`->next()`, `->instalments(3)`, `->on("2026-10-01")`) and `Income` (`->on(...)`). Use `Source::factory()->for($user)` and `Expense::factory()->for($source)`. Tests are in `tests/Feature`: `AuthTokenTest`, `UserTest`, `ExpenseTest`, `IncomeTest`, `DataTest` (billing periods, `next`, instalments, category sums) and `SummaryTest`.
- Routes have no `/api` prefix (`apiPrefix: "/"` in `bootstrap/app.php`), so tests call `/login`, `/data`, etc.
- **Code formatting**: `./vendor/bin/pint`
- **Migrations**: `php artisan migrate`

## Architecture

API-only application — all routes defined in `routes/api.php`, no web UI logic.

**Auth tokens**: `POST /login` takes an optional `device` (`android` → 90-day token, anything else → `web`, 1 day) and names the token after it. `POST /logout` revokes only the current token.

**Routes** (`routes/api.php`): `POST /login` is public; everything else is under `auth:api` — `POST /logout`, `GET /info`, `GET /data`, `POST|PUT|DELETE /expenses[/{expense_id}]` `POST|PUT|DELETE /incomes[/{income_id}]`, `POST|PUT|DELETE /transfers[/{transfer_id}]`, `PUT /sources/{source_id}` (currency) and `PUT /rates/{currency}`.

**Controllers:**
- `AppController` — invokable, primary data endpoint (`GET /data?date=YYYY-MM-DD`). Defaults to the current month; the date is normalized to the month's first day. See "GET /data" below.
- `ExpenseController` — store/update/destroy for expenses (no index/show; reads go through `/data`)
- `IncomeController` — store/update/destroy for incomes, same ownership rules as expenses (source and move target must be the user's, `400 "error"` otherwise); `amount` must be numeric and non-zero, `date` a valid date. Tests in `tests/Feature/IncomeTest.php`.
- `TransferController` — store/update/destroy for transfers between two of the user's sources (`from_source_id`, `to_source_id`, `amount` > 0, `received_amount`, `date`, `description`). Both ends must be the user's and different, `400 "error"` otherwise. `received_amount` (> 0, in the destination's currency) is required when the two sources have different currencies and must be empty when they have the same one. A card payment is a transfer from an account to a card; it never changes the balance. Tests in `tests/Feature/TransferTest.php`.
- `SourceController` — `update` only (`PUT /sources/{source_id}`): sets `currency` (one of `config("expenses.currencies")`, `MXN` or `USD`) on one of the user's sources, `400 "error"` otherwise. Nothing else about a source is editable through the API. Tests in `tests/Feature/SourceTest.php`.
- `RateController` — `update` (`PUT /rates/{currency}` with `{ rate }` > 0): creates or replaces the user's exchange rate (pesos per unit) for a currency other than the base `MXN`. Tests in `tests/Feature/RateTest.php`; the conversions in `tests/Feature/CurrencyTest.php`.
- `UserController` — login/logout/info with Passport token auth

**Model relationships:**
- `User` → has many `Source` and many `ExchangeRate` (`currency`, `rate`, unique per user and currency)
- `Source` (bank/payment source with `cutoff` day) → has many `Expense`, has many `Income`
- `Expense` → belongs to `Source` and `Category`; has `next` (boolean, next-period billing) and `instalments` (multi-month splits)
- `Category` → has many `Expense`; has `alias`, `order`, `color`

**Database**: PostgreSQL required — queries use PG-specific functions (`date_trunc`, `interval`, `case ... when true`).

### GET /data

Response: `{ "expenses": [...sources], "categories": [...], "summary": {...}, "rates": {...} }`.

- Each source in `expenses` includes its `incomes` (within the billing period), `incomes_count`, plus `expenses`/`expenses_count` (regular) and `instalments`/`instalments_count` (expenses with non-null `instalments`). Sources are loaded once and expenses are split with `->partition()` in PHP.
- Each category carries `expenses_count` and `expenses_sum_amount`, computed in PHP from one eager-loaded expense set (instalments contribute `amount / instalments`); its `expenses` relation is hidden in the output.
- **Cards and accounts**: a source with a `cutoff` (even 0) is a credit card, one without is a debit account (`Source::isCard()`, exposed as `kind` = `card`/`account`).
- **Transfers**: each source also carries `transfers` and `transfers_count` for the calendar month of the date (the money moves that day, so unlike expenses the cutoff does not apply, which keeps a payment dated between two cutoffs from changing any month's balance). Each item has `direction` (`out`/`in`), `signed_amount` in that source (negative when it leaves; an incoming one is `received_amount ?? amount`), `counterpart_id`/`counterpart_name` and the raw fields to edit it.
- **Balance**: `app/Services/Summary.php` holds the money math. A source's position up to a month is its incomes, minus its billed expenses, minus the transfers out, plus the transfers in, counted from `config("expenses.balance_start")` (env `BALANCE_START`, default `2026-09-01`, with a hard-coded fallback for a stale `config:cache`). Each source gets its `balance` (that position, `null` before the start). `summary` is `{ spent, income, cash, debt, total_money }`: `spent` (cash plus the monthly share of instalments) and `income` are the month's own, `cash` is the sum of the accounts' positions, `debt` is minus the sum of the cards' positions, and `total_money` (shown as "Balance") is always `cash - debt`, the sum of all positions. All three are `null` before the start month. `summary` also has `missing_rates`. An income dated in the start month acts as the opening balance, and anything dated before the start is not counted. Spent months are summed one by one because instalments spread over several, and each instalment share is rounded to cents as the bank bills it, halves away from zero and on whole cents (`Summary::share()` in PHP, `round(amount::numeric / instalments, 2)` in the SQL sums; the web's `monthlyShare` does the same, because dividing floats turns an exact 1200.465 into 1200.4649999), so a card paid with the statement amount ends at exactly 0; positions are rounded to cents at the end. Tests in `tests/Feature/SummaryTest.php` and `BalanceTest.php`.
- **Currencies**: every source has a `currency` (`MXN` by default, `USD` for Dollar App; the list and the base currency, the first, are in `config/expenses.php`). Amounts stay in the source's own currency everywhere (`balance`, `signed_amount`, items); only `summary` and the category sums are in pesos, converted with the user's exchange rate (the current one, for all the history: it is "what I have today"). `/data` returns `rates` (`{ "USD": 17.85 }`, empty `{}` when none; the base is left out). A source whose currency has no rate makes `cash`, `debt` and `total_money` `null` and its currency appears in `summary.missing_rates`; its amounts add nothing to `spent`, `income` or the category sums meanwhile. A conversion is a transfer between currencies with `received_amount`: converting 100 USD (worth 1,785 pesos) into 1,780 pesos moves the balance only by the 5 pesos of difference. Changing the currency of a source that already has transfers does not re-check them (a transfer saved before keeps its old `received_amount`), so set it once, before using the source.
- **Billing period**: a month runs from `first of month + source.cutoff` days to `first of next month - 1 + cutoff`. Cutoff is per source (null behaves as 0, i.e. the calendar month), so the expense and income queries join `sources` (`$periodStart`/`$periodEnd` in `AppController` hold the income range). Incomes have no `next` or instalments.
- **`next` flag**: when true, the expense's effective date is `date + 1 month` (billed in the next period). Every date filter must use that effective date, not `date`.
- **Instalments**: an expense with `instalments = N` is active in each of N consecutive periods starting from its effective date.
- The expense period filter lives once, in `Summary::billedIn()` (qualified `expenses.` columns, query already joined with `sources`), and is shared by the sources query, the categories query and the spent sums. `Summary::period()` holds the cutoff range used by it and by the incomes queries; `Summary::calendarRange()` is the calendar-month range used for transfers.

## Conventions

- 2-space indentation in PHP (not PSR-12 4-space); match the surrounding file.
- Raw SQL fragments use heredocs (`<<<SQL`) inside `whereRaw`.
- Sensitive local config (`.claude/settings.local.json`, `.env`, `*.dump` DB dumps) must not be committed.

## Git Conventions

Conventional commits enforced via Commitlint + Husky (e.g., `feat(appcontroller):`, `fix:`, `chore:`). Commitizen configured in `.cz.toml`.
