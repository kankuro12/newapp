# Business Book

Small-business bookkeeping: Laravel 13 + Ionic React/TypeScript, one npm workspace.
Web and installable PWA implemented first. Same Ionic source is configured for
Capacitor; Android/iOS projects and native authentication remain a later build.

## Run locally

```powershell
npm run dev
```

Development starts backend, web UI and monthly-action scheduler together.

Open [Business Book](http://127.0.0.1:5173). Laravel runs on loopback port8000;
Vite proxies API and authentication under the frontend origin. Register your own
account, verify email, create business, finalize starting balances, then record
sales, purchases, expenses and payments. No public super-admin registration.

Local connection is configured in `backend/.env`: dedicated `business_book`
database, localhost3306, root, empty password as requested. Actual installed
server is MariaDB 10.4.19. Production target remains MySQL 8.4/InnoDB and requires
its own validation. `business_book_testing` is disposable and isolated; never
point tests at business data. Dairy database, source, key, sessions and storage
remain separate.

Mail defaults to Laravel log transport. Local verification, reset and invitation
links appear in `backend/storage/logs/laravel.log`; configured SMTP is required
for delivery. Logs containing those links are private. Development includes a
synthetic Browser Test Shop used for browser checks; it is not a production seed.

## Implemented web flows

- Registration, sign-in, verification, password recovery, profile/password update;
  separate super-admin identity table, provider and session guard.
- Business selection, staff invitations and four tenant roles; membership and
  ownership checked on server, including reports, lookups and private downloads.
- Guided starting cash, stock and party balances; contacts, items, cash/bank
  accounts and expense categories.
- Daily sale, purchase, expense, receipt/payment, refund, transfer, owner money,
  stock count and original-linked partial returns. Draft edit/copy/delete,
  version checks, private receipt attachments and printable bookkeeping bills.
- Parties support any combination of Customer, Supplier, Employee and Rent roles.
  Regular payments: add employee/salary, monthly rent or regular bill; auto-record
  expense + payable, then pay now/partially/later. Monthly history links normal
  expenses and reversals. Fixed monthly amounts, no attendance/deductions.
- Eight POS profiles: general store, meat, restaurant, barber, salon, milk, glass
  and wood. Item settings enable quantity/amount/packs/length/area/volume and
  explicit custom conversions. Dimensions and preparation notes print on bills.
- Main businesses can create branches with separate books, stock and staff.
  Restaurant waiter rounds feed kitchen status views; foreground updates refresh
  every two seconds. Barber/salon BS day schedules support service durations,
  chairs/staff, blocked time, rescheduling and overlap prevention.
- Automatic balanced journals, moving-average inventory, integer paisa and
  thousandths, BS dates, transaction locks, retry UUIDs, linked cancellations,
  period closing and audit history. No journal-entry input screen.
- Profit/loss, balance sheet, trial balance, stock, cashbook, party statements,
  dues, aging, activity and reconciliation; filtered print/CSV exports.
- Responsive desktop/mobile navigation, core English/Nepali labels, PWA shell,
  connection status and safe retry. Secondary help and advanced labels still
  use English fallback; full Nepali copy review remains pending.

## Cache behavior

Dashboard cache expires after45 seconds and is keyed by tenant, data version,
role, actor and BS day. Writes advance version; membership/access checks happen
before cache lookup. API responses use `no-store`. Service worker caches only
app shell and versioned assets, never financial responses or auth routes.
Offline mode loads shell and retains current form values; financial writes
require connection. Expired database cache rows are cleaned every5 minutes when
Laravel scheduler is running.

## Install on a fresh environment

Requirements: PHP 8.4 with BCMath and database extensions, Composer, Node >=22.12,
MySQL 8.4/InnoDB. Always invoke PHP/Composer as `php84` / `composer84`.
Use root npm install and lockfile, never install a separate frontend workspace.

```powershell
npm run install:backend
npm install
```

On a fresh installation only, copy `backend/.env.example` to `backend/.env`, set
independent database/mail/origin values, generate a unique key, then run from
`backend`:

```powershell
php84 artisan key:generate
php84 artisan migrate
php84 artisan app:create-super-admin owner@example.com --name="Platform owner"
```

Super-admin command privately prompts for password; no default credential.
Tenant sign-in is `/signin` in Ionic UI; platform sign-in is `/platform/signin`.
Do not overwrite an existing key, environment or database.

## Build and checks

```powershell
npm run build
npm run lint
npm run test:frontend
composer84 --working-dir=backend run test
```

Backend tests recreate/use `business_book_testing`; startup rejects every other
named database. No SQLite claim for financial concurrency. Latest checks:
backend 37 tests/514 assertions; frontend 14 tests in 7 files;
TypeScript/build/lint and PHP formatting pass.
Frontend output: `frontend/dist`. Browser shell caching requires production
build; development does not register a service worker.

This machine's old npm8 can misreport nested script failures. npm11 cached CLI
was used for final verification. Build retains a340KB gzip main-bundle warning;
real-device performance remains unmeasured. Production dependency audit has
3 moderate React Router 6 advisories; compatible Ionic/router remediation is a
release gate, not a forced major upgrade.

See [build evidence](BUILD-PROGRESS.md), [deployment guide](DEPLOYMENT.md),
[competitor feature inventory](COMPETITOR-RESEARCH.md), [industry POS research](INDUSTRY-POS.md), [architecture](MONOREPO-CONTRACT.md)
and [product specification](APP-SPECIFICATION.md). No public deployment, real-data
migration, native store build or statutory tax-invoice certification completed.
