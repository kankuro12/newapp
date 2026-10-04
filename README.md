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

User guide: **More → User manual**, or [open local guide](http://127.0.0.1:5173/help/user-manual.html).
Printable, responsive18-section HTML source: [user-manual.html](frontend/public/help/user-manual.html).
Covers current daily flows, configured measurements, all8 POS profiles, dues,
salary/rent, corrections and safe original-action retry. Planned integrations and
native builds are labelled separately. Guide/screenshots are public static assets;
PWA asset cache includes them, never private business responses.

Named sale offers now cover regular bills, editable drafts, quotes and sales
orders as well as POS/restaurant/appointments. New entries require current review;
draft posting rejects changed terms until edited/reviewed. Approved quote/order
conversion preserves its agreed prices and offer. Copy restores pre-offer values
and requires new selection. Source returns/cancellation remain original-valued.

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
- Quotes, sales/purchase orders and job specifications: staff-recorded acceptance,
  frozen-price bill conversion, BS fulfilment dates/status, quote/job/delivery
  print layouts. No financial or stock effect until reviewed billing.
- Party trading: separate customer/supplier payment days, customer credit limits,
  exact agreed item prices and trusted POS recalculation. Collections link normal
  payments and aging; staff follow-ups retain status, next action and history.
- Product categories filter items and every shared POS catalogue. Preferred
  suppliers and target stock produce reviewed purchase orders, subtracting open
  orders and rejecting changed stock/prices/suppliers. Orders do not receive stock
  or record payments. Categories can be created inline while adding products.
- Automatic balanced journals, moving-average inventory, integer paisa and
  thousandths, BS dates, transaction locks, retry UUIDs, linked cancellations,
  period closing and audit history. No journal-entry input screen.
- Reviewed CSV party/item imports: map columns, correct row errors, inspect exact
  changes, then apply all rows atomically. Templates/exports retain explicit IDs,
  categories, suppliers, four party roles and measurement settings. Import history
  and original-request retry remain scoped; balances use normal accounting flows.
- Profit/loss, balance sheet, trial balance, stock, cashbook, party statements,
  dues, aging, activity and reconciliation; filtered print/CSV exports.
- Alternate product codes preserve leading zeros in lookup, POS and CSV imports.
  Branch settings configure EAN-13/UPC-A embedded quantity/NPR amount formats,
  checksum and compatible units. POS rechecks raw codes before normal posting;
  quantity, stock, retry and original return paths stay canonical. Reviewed Code128
  labels support items/category/supplier, copies, name/price/code, configurable
  sheet/roll dimensions and fresh server prices before browser printing. Direct
  scale connections and physical printer calibration remain pending.
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
backend full151/3599 before final C1b rounding fix; final targeted33/938 after fix. Final frontend71 tests/28 files after signup/manual changes;
TypeScript/build/lint and PHP formatting pass.
4 October C1a adds delivery-first partial delivery/receipt/service completion,
exact source-order bills, returns/reversals and Ionic quantity entry/progress/
slips. Original full billing remains available before staging. Mobile visual QA
uses posting-disabled fixtures; live authenticated end-to-end QA remains
unverified. Signup requires separate country/phone fields; More opens the guide within the app. C1b direct backend added; new race checks, packages/backorders and Ionic controls remain required. See
NICHE-FEATURES.md and BUILD-PROGRESS.md.
Frontend output: `frontend/dist`. Browser shell caching requires production
build; development does not register a service worker.

This machine's old npm8 can misreport nested script failures. direct installed tool entrypoints
were used for this expansion. Build retains a392KB gzip main-bundle warning;
real-device performance remains unmeasured. Production dependency audit has
3 moderate React Router 6 advisories; compatible Ionic/router remediation is a
release gate, not a forced major upgrade.

See [build evidence](BUILD-PROGRESS.md), [deployment guide](DEPLOYMENT.md),
[competitor feature inventory](COMPETITOR-RESEARCH.md), [industry POS research](INDUSTRY-POS.md), [18-niche feature inventory](NICHE-FEATURES.md), [architecture](MONOREPO-CONTRACT.md)
and [product specification](APP-SPECIFICATION.md). No public deployment, real-data
migration, native store build or statutory tax-invoice certification completed.
