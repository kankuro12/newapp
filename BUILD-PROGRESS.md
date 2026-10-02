# Build progress

2026-10-02, Asia/Kathmandu. Web-first implementation delivered in existing
Laravel 13/Ionic React workspace. This replaces earlier scaffold-only status.
Dairy source/database untouched; no Git staging, commit, push, branch or
.gitignore change performed during implementation.

## Changed implementation

| Area | Files | Result |
|---|---|---|
| Finance | backend/app/Service/{Accounting,Document,Inventory,Payment,Tenant}Service.php | Five feature services; atomic posting, stock, payment, reversal and membership rules |
| Exact values | backend/app/Support/Money.php, backend/app/NepaliDate.php, backend/config/bs-calendar.php | BCMath/BigInt contract, integer BS dates,2000–2090 calendar; licensed source retained |
| Database | backend/database/migrations, backend/.env.example, backend/phpunit.xml, backend/tests/TestCase.php | Composite tenant ownership, integer constraints, independent dev/test databases, destructive-test guard |
| Auth/access | backend/config/auth.php, backend/config/fortify.php, backend/app/Providers, backend/app/Http/Middleware/ResolveTenant.php, backend/app/Models | Fortify tenant auth; different superadmin model/provider/guard; disabled/revoked/expired checks before reads/cache |
| APIs/reports | backend/routes/api.php, backend/app/Http/Controllers | Validated/scoped daily actions, private files, canonical journal reports, safe CSV, password-protected access/closing |
| Maintenance | backend/routes/console.php, backend/app/Console/Commands/CreateSuperAdmin.php | Password-prompted platform admin creation and scheduled expired-cache cleanup |
| Ionic UI | frontend/src/{App.tsx,lib,components,pages,theme} | Responsive real-data forms, pagination, drafts/returns/cancellations, reports/settings/platform screens, core Nepali labels |
| PWA/native config | frontend/vite.config.ts, frontend/public, frontend/capacitor.config.ts, frontend/src/main.tsx | Assets-only service worker/manifest/icons; shared Ionic Capacitor configuration, native platforms pending |
| Verification | backend/tests/Feature/{BusinessFlow,ConcurrentPosting}Test.php, backend/tests/Unit/ExactValuesTest.php, frontend/src/{App.test.tsx,lib/*.test.tsx,lib/*.test.ts} | Financial/security flows, separate-process concurrency, exact previews and network retry checks |

Actual local DB server: MariaDB 10.4.19 on localhost3306. Dedicated
`business_book` / `business_book_testing`, root with empty password as supplied.
Production database target MySQL 8.4 not installed or tested here. App key,
session cookie and private storage are independent. No default superadmin
created. Development uses log email transport and one synthetic browser-test
shop; no real customer data imported.

## Verification executed

| Check | Result |
|---|---|
| php84 artisan test --compact |28 tests pass,375 assertions on isolated MariaDB test database |
| Separate-process posting | Two PHP processes attempt same cash withdrawal; one posts, other rejects; cash stays nonnegative |
| Composite foreign key | MariaDB rejects direct cross-tenant reference insert |
| Financial fixture | Cash4550 NPR, inventory700, customer due250, supplier due400, net profit100; debit=credit |
| Returns/cancellation | Three partial returns drain exact original paisa; refund/cancellation dependencies enforced; all linked reversals restore original balances |
| Valuation/rounding |338 paisa tax/discount fixture; purchase-return moving-average variance; stock gain/loss; zero starting setup; stale count rejection |
| Historical balances | Backdated withdrawal cannot make a later daily cash balance negative; confirmed bank overdraft audited and classified as liability |
| Reports | Reconciliation difference0; aging includes pending refund credits; owner history reports amounts and reversal status |
| Auth/security | Actual Fortify registration rejects admin injection; verification gate, disabled login, separate guards, changed-role retry checks, revocation/expiry before cached reads, final-owner protection |
| Files/exports/locks | Private PDF accepted, SVG rejected, foreign-tenant access rejected, CSV formula escaped, original-period/reversal locks enforced |
| Actual HTTP CSRF | Mutation without XSRF token returns419; browser registration and financial posting succeed with cookie+XSRF |
| Frontend Vitest |3 files,6 tests pass: exact preview arithmetic, uncertain mutation UUID retained across lost reply/access change, anonymous/retry session flows |
| TypeScript + Vite production build | Pass; no remaining CSS host-context warning after unused starter utilities removed |
| ESLint | Pass, zero errors/warnings |
| PHP formatting/platform | Pint passes; Composer strict validation and installed platform requirements pass |
| Browser, responsive | Register/log-mail verification, create business, finalize opening cash, add service, post paid sale, inspect real bill/dashboard; desktop1280 and mobile390 layouts |
| Browser PWA offline | Production service worker activates; inspected cache contains only12 app assets, noAPI/auth/financial data; offline shell reloads, retry restores dashboard after reconnect |
| Core Nepali navigation | Dashboard/mobile labels render after language toggle; secondary copy not fully translated |

Browser artifacts: `artifacts/dashboard-desktop.jpg`,
`artifacts/dashboard-mobile.jpg`. Fixture showed cash5150 NPR after one paid
150 NPR service sale against5000 NPR starting cash. Screenshots represent live
local API data, not a static mockup.

Tests exercise groups of acceptance rules;28 tests are not a claim that all119
release scenarios were independently executed. MySQL 8.4, Safari, native devices,
real SMTP delivery, paper-print/device usability, production backup restore,
statutory tax invoicing and store release checks remain unverified.

## Dependency and scale limits

Production npm audit:3 moderate advisories,0 high/critical. Router 6 vulnerabilities
include [open redirect](https://github.com/advisories/GHSA-wrjc-x8rr-h8h6) and
[SSR deserialization](https://github.com/advisories/GHSA-337j-9hxr-rhxg). Current
app has no SSR and uses fixed internal navigation targets, but dependencies
remain affected. Resolve with a compatible Ionic/router release before public
production deployment; audit's forced Ionic downgrade was not applied.
Composer audit reported no advisories after lock update. Full development-tool
audit needs review before CI/release use.

Main bundle approximately1.45MB/325KB gzip. Split pages when measured device
startup warrants it; no arbitrary chunk warning suppression. Tenant row lock
serializes one business's financial writes; more granular locking requires
throughput evidence. Reconciliation and party enrichment use direct sums with
known query/row ceilings; large-data performance not load-tested. Oversized
bounded reports reject above20,000 rows instead of silently truncating.

## Web-first release gates

- Re-run database migrations, locking, composite ownership and all finance tests
  on isolated MySQL 8.4/InnoDB; never reuse dairy or populated production DB.
- Resolve affected production Router dependency with Ionic-compatible upgrade;
  re-run build, auth/navigation and browser checks.
- Configure real mail, HTTPS/origin/cookies, scheduler, dedicated database account,
  private-file backups and monitored restore drill per DEPLOYMENT.md.
- Complete full Nepali secondary copy and device/keyboard/print review.
- Android/iOS: generate native platforms, implement native-safe auth transport,
  test device network/session behavior and signed builds. Capacitor config alone
  is not a verified mobile release.
- Keep statutory tax-invoice mode disabled until current requirements and
  business approval are checked. Subscription/payment gateway remains outside
  manual platform access-management scope.
## Multipurpose parties and monthly payments — 2026-10-02

Implemented contacts with independent Customer/Supplier/Employee/Rent flags,
including all four at once. Purchase still requires Supplier; employee/rent
expenses and payment allocation share payable channel. Receivables/payables
stay separate. Existing balances remain visible if roles change; outstanding
channel cannot lose its last eligible role. Lookup filters role before result limit.

Added RecurringExpenseService, controller/command, additive tables with composite
ownership and unique setup/month, and Ionic RegularPayments.tsx. Salary/Rent/Other
setup, BS monthly day/month-end, first date, auto action, pause/edit, period preview,
linked history, pay-now/later/partial; normal expense/reversal paths reused.
Automatic action uses author membership/access, no cash movement, no duplicate
month. Configuration amounts affect unrecorded months only. No new dependency.
Local dedicated business_book migration applied; root dev starts scheduler.

Checks: full backend 28 tests/375 assertions, then focused feature 5 tests/113
assertions after adding role-removal/lookup checks; 6 frontend tests, build/lint,
Pint, scheduler registration. Two-process automatic versus manual race produced
one monthly expense; database rejected cross-tenant occurrence. Short BS month,
year rollover, partial settlement/reversal, insufficient-funds rollback, future
amount edit, pause/revocation, disabled/unverified author, locked/suspended/expired
business and canceled-month suppression exercised.

Live synthetic Browser Test Shop: one four-role party, salary100 auto-accrued,
partial payment25 leaves75; rent50 recorded unpaid on same party. Checked
390px phone list/form/navigation and desktop. Screenshots:
artifacts/regular-payments-mobile.png and artifacts/party-roles-desktop.png.
MySQL8.4, native Android/iOS, real scheduler deployment and existing public-release
gates remain unverified. Browser rows are synthetic bookkeeping fixtures.

## Mobile entry and industry POS — 2026-10-02

Research: COMPETITOR-RESEARCH.md contains nine competitors and 66 deduplicated
features with delivery status. INDUSTRY-POS.md compares official POS workflows
for all eight requested business profiles and documents units/branch choices.
The feature inventory includes outstanding work; full competitor parity is not
claimed.

Implemented PosService, RestaurantService and AppointmentService, PosController,
industry routes and additive migration 2026_10_02_094324_add_industry_pos.php.
Existing tenant guards, UUID mutations and document/stock/reversal services are
reused. Parent/branch grouping does not grant implicit cross-branch access.
Each branch has independent membership, books, cash, stock and opening balances.

Ionic Pos.tsx selects general/meat/restaurant/barber/salon/milk/glass/wood screens.
Item settings enable quantity, requested amount, custom packs, length, area and
volume with compatible base units. Rational metric/imperial conversion rounds
once to integer thousandths. Custom units require explicit conversion. Frozen
measurement snapshots appear on bills and original-linked returns. Exact SKU
entry selects only an unambiguous item.

Restaurant tables/takeaway, waiter rounds, kitchen preparation/ready/served,
reasoned cancellation and unique checkout are operational until billing. Prices
freeze across rounds; displayed total uses the same grouped rounding as checkout.
Scheduling includes BS day, staff/chair hours, frozen service duration/prices,
blocked time, reschedule/cancel/arrival/no-show and unique checkout. Branch lock
serializes occupancy and overlap checks. Foreground GET refresh every two seconds,
last-update/error display and original UUID/payload retry handle live versions.

Mobile party entry puts name/phone/roles first; optional fields collapse. Daily
bill entry uses Party → Items → Review with retained values. POS hides product
tiles during measurement entry and uses product/cart tabs with a bottom cart
action. Shared picker Enter selects one match without advancing the outer form;
duplicate names require explicit selection. Native time input events now update
controlled values reliably.

Latest complete checks: backend 37 tests, 514 assertions; frontend 14 tests in
7 files; TypeScript/Vite build, ESLint and explicit-file Pint passed. Backend
covers exact mixed dimensions/custom packs, moving-average stock and original
returns, branch ownership, changed-price UUID replay, kitchen rounding/status/
unique checkout, appointment price freezing, overlap/hours, malformed inputs
and cross-tenant resource rejection. Separate processes produce one booking and
one occupied-slot rejection; database foreign keys reject foreign resources.
Frontend checks cover live-read cleanup/data retention, original mutation retry,
native time input, picker keyboard behavior, mobile steps and exact arithmetic.
Production bundle is approximately 1.51MB / 340KB gzip; chunk-size warning remains.

Dedicated development migration applied to business_book only. Synthetic browser
checks posted paid general/restaurant bills, observed waiter/kitchen changes
across two tabs, rejected overlapping appointments, saved adjacent slots, and
created a new branch with zero balances. 390px mobile calendar has no horizontal
overflow. Mixed-unit glass entry (2ft × 36in × 1) previews 6.000sq_ft and 600NPR;
320px cart has no horizontal overflow. Artifacts: mobile-party-entry.png,
restaurant-kitchen-desktop.png, appointments-mobile.png, glass-entry-mobile.png
and glass-cart-mobile.png under artifacts/. QA shop uses glass profile for preview;
QA Glass Branch remains a synthetic empty-book branch.

Limits: two-second HTTP refresh is not push delivery; scale/printer integration,
customer self-ordering, shared branch stock transfers/consolidation, automated
reminder/payment providers, native builds and remaining competitor backlog are
pending. Existing MySQL8.4, dependency, regulatory and production release gates
still apply. No new dependencies, dairy changes or Git mutations.
