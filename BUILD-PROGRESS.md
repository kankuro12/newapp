# Build progress

2026-10-02, Asia/Kathmandu. Web-first implementation delivered in existing Laravel 13/Ionic React
workspace. This replaces earlier scaffold-only status. Dairy source/database untouched; no Git
staging, commit, push, branch or .gitignore change performed during implementation.

## Changed implementation

| Area              | Files                                                                                                                                                            | Result                                                                                                                      |
| ----------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------- |
| Finance           | backend/app/Service/{Accounting,Document,Inventory,Payment,Tenant}Service.php                                                                                    | Five feature services; atomic posting, stock, payment, reversal and membership rules                                        |
| Exact values      | backend/app/Support/Money.php, backend/app/NepaliDate.php, backend/config/bs-calendar.php                                                                        | BCMath/BigInt contract, integer BS dates,2000–2090 calendar; licensed source retained                                       |
| Database          | backend/database/migrations, backend/.env.example, backend/phpunit.xml, backend/tests/TestCase.php                                                               | Composite tenant ownership, integer constraints, independent dev/test databases, destructive-test guard                     |
| Auth/access       | backend/config/auth.php, backend/config/fortify.php, backend/app/Providers, backend/app/Http/Middleware/ResolveTenant.php, backend/app/Models                    | Fortify tenant auth; different superadmin model/provider/guard; disabled/revoked/expired checks before reads/cache          |
| APIs/reports      | backend/routes/api.php, backend/app/Http/Controllers                                                                                                             | Validated/scoped daily actions, private files, canonical journal reports, safe CSV, password-protected access/closing       |
| Maintenance       | backend/routes/console.php, backend/app/Console/Commands/CreateSuperAdmin.php                                                                                    | Password-prompted platform admin creation and scheduled expired-cache cleanup                                               |
| Ionic UI          | frontend/src/{App.tsx,lib,components,pages,theme}                                                                                                                | Responsive real-data forms, pagination, drafts/returns/cancellations, reports/settings/platform screens, core Nepali labels |
| PWA/native config | frontend/vite.config.ts, frontend/public, frontend/capacitor.config.ts, frontend/src/main.tsx                                                                    | Assets-only service worker/manifest/icons; shared Ionic Capacitor configuration, native platforms pending                   |
| Verification      | backend/tests/Feature/{BusinessFlow,ConcurrentPosting}Test.php, backend/tests/Unit/ExactValuesTest.php, frontend/src/{App.test.tsx,lib/_.test.tsx,lib/_.test.ts} | Financial/security flows, separate-process concurrency, exact previews and network retry checks                             |

Actual local DB server: MariaDB 10.4.19 on localhost3306. Dedicated `business_book` /
`business_book_testing`, root with empty password as supplied. Production database target MySQL 8.4
not installed or tested here. App key, session cookie and private storage are independent. No
default superadmin created. Development uses log email transport and one synthetic browser-test
shop; no real customer data imported.

## Verification executed

| Check                              | Result                                                                                                                                                                                            |
| ---------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| php84 artisan test --compact       | 28 tests pass,375 assertions on isolated MariaDB test database                                                                                                                                    |
| Separate-process posting           | Two PHP processes attempt same cash withdrawal; one posts, other rejects; cash stays nonnegative                                                                                                  |
| Composite foreign key              | MariaDB rejects direct cross-tenant reference insert                                                                                                                                              |
| Financial fixture                  | Cash4550 NPR, inventory700, customer due250, supplier due400, net profit100; debit=credit                                                                                                         |
| Returns/cancellation               | Three partial returns drain exact original paisa; refund/cancellation dependencies enforced; all linked reversals restore original balances                                                       |
| Valuation/rounding                 | 338 paisa tax/discount fixture; purchase-return moving-average variance; stock gain/loss; zero starting setup; stale count rejection                                                              |
| Historical balances                | Backdated withdrawal cannot make a later daily cash balance negative; confirmed bank overdraft audited and classified as liability                                                                |
| Reports                            | Reconciliation difference0; aging includes pending refund credits; owner history reports amounts and reversal status                                                                              |
| Auth/security                      | Actual Fortify registration rejects admin injection; verification gate, disabled login, separate guards, changed-role retry checks, revocation/expiry before cached reads, final-owner protection |
| Files/exports/locks                | Private PDF accepted, SVG rejected, foreign-tenant access rejected, CSV formula escaped, original-period/reversal locks enforced                                                                  |
| Actual HTTP CSRF                   | Mutation without XSRF token returns419; browser registration and financial posting succeed with cookie+XSRF                                                                                       |
| Frontend Vitest                    | 3 files,6 tests pass: exact preview arithmetic, uncertain mutation UUID retained across lost reply/access change, anonymous/retry session flows                                                   |
| TypeScript + Vite production build | Pass; no remaining CSS host-context warning after unused starter utilities removed                                                                                                                |
| ESLint                             | Pass, zero errors/warnings                                                                                                                                                                        |
| PHP formatting/platform            | Pint passes; Composer strict validation and installed platform requirements pass                                                                                                                  |
| Browser, responsive                | Register/log-mail verification, create business, finalize opening cash, add service, post paid sale, inspect real bill/dashboard; desktop1280 and mobile390 layouts                               |
| Browser PWA offline                | Production service worker activates; inspected cache contains only12 app assets, noAPI/auth/financial data; offline shell reloads, retry restores dashboard after reconnect                       |
| Core Nepali navigation             | Dashboard/mobile labels render after language toggle; secondary copy not fully translated                                                                                                         |

Browser artifacts: `artifacts/dashboard-desktop.jpg`, `artifacts/dashboard-mobile.jpg`. Fixture
showed cash5150 NPR after one paid 150 NPR service sale against5000 NPR starting cash. Screenshots
represent live local API data, not a static mockup.

Tests exercise groups of acceptance rules;28 tests are not a claim that all119 release scenarios
were independently executed. MySQL 8.4, Safari, native devices, real SMTP delivery,
paper-print/device usability, production backup restore, statutory tax invoicing and store release
checks remain unverified.

## Dependency and scale limits

Production npm audit:3 moderate advisories,0 high/critical. Router 6 vulnerabilities include
[open redirect](https://github.com/advisories/GHSA-wrjc-x8rr-h8h6) and
[SSR deserialization](https://github.com/advisories/GHSA-337j-9hxr-rhxg). Current app has no SSR and
uses fixed internal navigation targets, but dependencies remain affected. Resolve with a compatible
Ionic/router release before public production deployment; audit's forced Ionic downgrade was not
applied. Composer audit reported no advisories after lock update. Full development-tool audit needs
review before CI/release use.

Main bundle approximately1.45MB/325KB gzip. Split pages when measured device startup warrants it; no
arbitrary chunk warning suppression. Tenant row lock serializes one business's financial writes;
more granular locking requires throughput evidence. Reconciliation and party enrichment use direct
sums with known query/row ceilings; large-data performance not load-tested. Oversized bounded
reports reject above20,000 rows instead of silently truncating.

## Web-first release gates

- Re-run database migrations, locking, composite ownership and all finance tests on isolated MySQL
  8.4/InnoDB; never reuse dairy or populated production DB.
- Resolve affected production Router dependency with Ionic-compatible upgrade; re-run build,
  auth/navigation and browser checks.
- Configure real mail, HTTPS/origin/cookies, scheduler, dedicated database account, private-file
  backups and monitored restore drill per DEPLOYMENT.md.
- Complete full Nepali secondary copy and device/keyboard/print review.
- Android/iOS: generate native platforms, implement native-safe auth transport, test device
  network/session behavior and signed builds. Capacitor config alone is not a verified mobile
  release.
- Keep statutory tax-invoice mode disabled until current requirements and business approval are
  checked. Subscription/payment gateway remains outside manual platform access-management scope.

## Multipurpose parties and monthly payments — 2026-10-02

Implemented contacts with independent Customer/Supplier/Employee/Rent flags, including all four at
once. Purchase still requires Supplier; employee/rent expenses and payment allocation share payable
channel. Receivables/payables stay separate. Existing balances remain visible if roles change;
outstanding channel cannot lose its last eligible role. Lookup filters role before result limit.

Added RecurringExpenseService, controller/command, additive tables with composite ownership and
unique setup/month, and Ionic RegularPayments.tsx. Salary/Rent/Other setup, BS monthly
day/month-end, first date, auto action, pause/edit, period preview, linked history,
pay-now/later/partial; normal expense/reversal paths reused. Automatic action uses author
membership/access, no cash movement, no duplicate month. Configuration amounts affect unrecorded
months only. No new dependency. Local dedicated business_book migration applied; root dev starts
scheduler.

Checks: full backend 28 tests/375 assertions, then focused feature 5 tests/113 assertions after
adding role-removal/lookup checks; 6 frontend tests, build/lint, Pint, scheduler registration.
Two-process automatic versus manual race produced one monthly expense; database rejected
cross-tenant occurrence. Short BS month, year rollover, partial settlement/reversal,
insufficient-funds rollback, future amount edit, pause/revocation, disabled/unverified author,
locked/suspended/expired business and canceled-month suppression exercised.

Live synthetic Browser Test Shop: one four-role party, salary100 auto-accrued, partial payment25
leaves75; rent50 recorded unpaid on same party. Checked 390px phone list/form/navigation and
desktop. Screenshots: artifacts/regular-payments-mobile.png and artifacts/party-roles-desktop.png.
MySQL8.4, native Android/iOS, real scheduler deployment and existing public-release gates remain
unverified. Browser rows are synthetic bookkeeping fixtures.

## Mobile entry and industry POS — 2026-10-02

Research: COMPETITOR-RESEARCH.md contains nine competitors and 66 deduplicated features with
delivery status. INDUSTRY-POS.md compares official POS workflows for all eight requested business
profiles and documents units/branch choices. The feature inventory includes outstanding work; full
competitor parity is not claimed.

Implemented PosService, RestaurantService and AppointmentService, PosController, industry routes and
additive migration 2026_10_02_094324_add_industry_pos.php. Existing tenant guards, UUID mutations
and document/stock/reversal services are reused. Parent/branch grouping does not grant implicit
cross-branch access. Each branch has independent membership, books, cash, stock and opening
balances.

Ionic Pos.tsx selects general/meat/restaurant/barber/salon/milk/glass/wood screens. Item settings
enable quantity, requested amount, custom packs, length, area and volume with compatible base units.
Rational metric/imperial conversion rounds once to integer thousandths. Custom units require
explicit conversion. Frozen measurement snapshots appear on bills and original-linked returns. Exact
SKU entry selects only an unambiguous item.

Restaurant tables/takeaway, waiter rounds, kitchen preparation/ready/served, reasoned cancellation
and unique checkout are operational until billing. Prices freeze across rounds; displayed total uses
the same grouped rounding as checkout. Scheduling includes BS day, staff/chair hours, frozen service
duration/prices, blocked time, reschedule/cancel/arrival/no-show and unique checkout. Branch lock
serializes occupancy and overlap checks. Foreground GET refresh every two seconds, last-update/error
display and original UUID/payload retry handle live versions.

Mobile party entry puts name/phone/roles first; optional fields collapse. Daily bill entry uses
Party → Items → Review with retained values. POS hides product tiles during measurement entry and
uses product/cart tabs with a bottom cart action. Shared picker Enter selects one match without
advancing the outer form; duplicate names require explicit selection. Native time input events now
update controlled values reliably.

Latest complete checks: backend 37 tests, 514 assertions; frontend 14 tests in 7 files;
TypeScript/Vite build, ESLint and explicit-file Pint passed. Backend covers exact mixed
dimensions/custom packs, moving-average stock and original returns, branch ownership, changed-price
UUID replay, kitchen rounding/status/ unique checkout, appointment price freezing, overlap/hours,
malformed inputs and cross-tenant resource rejection. Separate processes produce one booking and one
occupied-slot rejection; database foreign keys reject foreign resources. Frontend checks cover
live-read cleanup/data retention, original mutation retry, native time input, picker keyboard
behavior, mobile steps and exact arithmetic. Production bundle is approximately 1.51MB / 340KB gzip;
chunk-size warning remains.

Dedicated development migration applied to business_book only. Synthetic browser checks posted paid
general/restaurant bills, observed waiter/kitchen changes across two tabs, rejected overlapping
appointments, saved adjacent slots, and created a new branch with zero balances. 390px mobile
calendar has no horizontal overflow. Mixed-unit glass entry (2ft × 36in × 1) previews 6.000sq_ft and
600NPR; 320px cart has no horizontal overflow. Artifacts: mobile-party-entry.png,
restaurant-kitchen-desktop.png, appointments-mobile.png, glass-entry-mobile.png and
glass-cart-mobile.png under artifacts/. QA shop uses glass profile for preview; QA Glass Branch
remains a synthetic empty-book branch.

Limits: two-second HTTP refresh is not push delivery; scale/printer integration, customer
self-ordering, shared branch stock transfers/consolidation, automated reminder/payment providers,
native builds and remaining competitor backlog are pending. Existing MySQL8.4, dependency,
regulatory and production release gates still apply. No new dependencies, dairy changes or Git
mutations.

## Niche expansion A — quotes, orders and job cards (2 October 2026)

NICHE-FEATURES.md extends official competitor research to18 niches, with per-niche features and
implementation/integration/domain-review classification. Batch A adds
WorkflowService/WorkflowController, additive business_workflows migration, tenant routes,
Workflows.tsx and shared DocumentForm workflow mode. Quotes support draft revision, validity,
staff-recorded acceptance and immutable sales-order or bill conversion. Orders support job
specifications, fulfilment date, work status, overdue filter, quote/job/delivery print layouts and
reviewed billing. No order or quote changes money/stock. Financial conversion reuses normal
stock/payment, posting, original-price return and cancellation paths; one bill per order.

Checks: full backend41 tests/607 assertions passed. Subsequent expanded workflow suite4 tests/106
assertions passed, including tampered bill fields, changed item unit rejection, locked-date/stock
rollback, rejected-work overdue exclusion, ownership, UUID replay and original return. Frontend8
files/15 tests, TypeScript, production build, selected-file lint and PHP formatting passed. Main JS
approximately345KB gzip; chunk warning remains.

Concurrent salary test initially exposed a repeatable-read snapshot established before the tenant
lock in automatic generation. Author preflight now runs outside the transaction; rule/occurrence
reads happen after tenant locking, with changed author rejected for retry. Separate-process race
test then passed; full suite passed after correction. This preserves one monthly expense under
manual/auto race.

Dedicated development migration applied only to business_book. Synthetic browser quote QUO-000001
converts to SO-000001 with12sq_ft at100NPR, job specifications and ready state; linked reviewed bill
preserves1200NPR with payment later. Mobile job card hides prices;390px and320px views have no
horizontal overflow. Screenshot: artifacts/workflow-mobile.png. artifacts/workflow-billed.png shows
the converted order. Physical printer output remains unverified.

Limits: whole-order billing, no partial stock reservation/delivery posting, public customer
signatures or provider sending. Workflow measurements currently use base quantity plus specification
notes; specialised POS calculators remain available. Remaining batches B–H stay active in the
extended ledger. No new dependencies, Git mutations, subagents or dairy changes.

## Niche expansion B1 — party trading and collection follow-ups (2 October 2026)

Added PartyService/PartyController, additive party_trading_and_followups migration, PartyTools.tsx
and PartyTradingTest. Shared DocumentService posting enforces optional customer credit limits after
same-bill payment under tenant lock, including later historical exposure for backdated sales. Blank
limit is unlimited; zero requires fully paid sales. Supplier dues stay separate; reversals and fully
paid bills remain usable. NepaliDate.addDays derives customer/supplier due dates from BS calendar;
stored draft/bill dates and explicit overrides are preserved.

Party/item sale and purchase prices suggest new editor entries; explicit reprice keeps existing
amounts until reviewed. Trusted POS preview/checkout uses selected party prices, including
amount-to-quantity entry. Approved quotes/orders keep frozen prices when current party rate changes.
Unit/kind changes require rate review.

Collections use canonical net customer/supplier balances, including starting money, returns and
unapplied payments; archived dues remain visible. Payment, statement and aging links reuse existing
paths. Follow-ups capture optional same-party bill, BS day, staff assignment, channel, status and
append-only notes/history. Follow-ups never create payments or provider messages. Version/UUID
checks and composite ownership keys supplement server role/membership checks. Editing rate cannot
switch its channel; changing party/follow-up route resets old editor state. Snapshot JSON omits
credit policy metadata across bills, lists and quotes; cashier contact-save responses omit those
fields too.

Other changed paths: backend NepaliDate, DocumentService, PosService, BusinessController,
MasterController, PosController and routes; frontend Picker, DocumentForm/test, Masters, Records,
Workspace, Pos, types, i18n and app.css. Official references: Zoho credit-limit/price-list help and
Tigg purchase-bill/task help linked in COMPETITOR-RESEARCH.md. Categories, reorder, import,
labels/embedded barcodes and grouped price lists remain B work; batches C–H remain active.

Checks: full backend44 tests/718 assertions passed after snapshot privacy repair; focused party
suite3 tests/98 assertions passed. Frontend8 files/16 tests, TypeScript, selected-file lint,
production build and explicit-file PHP formatting passed. Main bundle348.96KB gzip; existing chunk
warning remains. Tests demonstrate terms, blank/zero limits, full/partial payment, backdating, draft
rollback, independent supplier dues, exact POS pricing, frozen quotes, foreign ownership/assignment,
UUID replay, follow-up history without payment, cashier policy/price permissions and
contact/bill/list/quote response privacy.

Migration applied only to dedicated development business_book; tests use disposable
business_book_testing. Synthetic390px browser: party3 rate80NPR/sq_ft recalculates 2ft ×3ft ×1piece
from600 to480NPR. Existing customer debt1200 and limit1500 reject an unpaid480 sale, preserving
cart/input. Paying180 saves SAL-2083-000005 with300 due; seven-day terms produce2083-06-23 BS.
Collections then show1500NPR; supplier balance remains separate. Saved local follow-up id1 records
creation and completion notes without settlement. Policy and collection screens have no390px
horizontal overflow. Proof: artifacts/collections-mobile.png, artifacts/followup-mobile.png,
artifacts/party-credit-mobile.png, artifacts/party-terms-bill-mobile.png.

Limits: local follow-ups only; grouped/quantity-tier pricing and copyable reminders pending. Credit
guard covers posted unpaid sales, not order credit reservations. Collection list is net party
balance; bill overdue detail uses linked aging report. No native/provider/hardware, MySQL8.4 or
production-readiness claim. No dependency, Git, dairy or subagent changes.

## B2 — product categories and reviewed supplier reorders

Added CatalogService/Controller, tenant-owned item_categories and composite item
category/preferred-supplier references in migration2026_10_02_163656. Existing MasterController
handles item category/supplier/target setup and scoped filters; WorkflowService checks reviewed
reorder state under the existing tenant lock. Category version/UUID, archive and duplicate-name
rules preserve attached items. Cashiers can browse category filters but cannot manage
categories/reorders or see preferred supplier/target fields. Item/supplier references fail closed
across tenants.

CatalogTools.tsx provides searchable categories, category filtering, and mobile Supplier → Items →
Review/save. Masters.tsx supports inline category creation; the shared Pos.tsx catalogue filters
categories for every profile. Picker.tsx supports category lookup with typed callbacks; shared Check
exposes disabled state. Existing exact calculator, EntrySteps, useSave and purchase-order/bill paths
are reused. Core new labels and explanatory copy have Nepali translations; product-wide copy review
remains a release gate.

Suggested quantities use target minus current stock minus all open unbilled POs in the branch.
Agreed supplier prices lead, then last purchase/catalogue fallback. Reviewed quantity/price remain
editable. Changed stock, supplier, price, target, tax or pending-order state rejects stale saves;
original UUID retries replay. POs create no stock/payment or supplier message. Cancellation restores
demand; ordinary reviewed purchase billing changes stock and removes that PO from pending.

Fresh checks: CatalogTest4 tests/105 assertions; full backend48 tests/823 assertions; frontend9
files/18 tests; TypeScript, selected-file ESLint and explicit-file Pint passed. Final production
build passed, mainJS1562.30KB / gzip352.53KB (chunk-size warning retained). Testing DB remained
disposable business_book_testing; additive migration applied only to dedicated development
business_book.

Synthetic390px browser: category QA Drinks created inline; stock product QA Juice, bottle/base unit,
supplier party3, low4, target10, price100NPR. POS category filter shows only Juice. Reviewed
PO-000001/workflow3 saves10 bottles for1000NPR. Fresh reorder shows stock0, pending10, suggested0
and disabled selection. English and Nepali footer controls stay within390px. Existing tab3 glass
cart left untouched. Inspected proof: artifacts/category-pos-mobile.png, reorder-review-mobile.png,
reorder-order-mobile.png, reorder-covered-mobile.png. Temporary viewport reset.

Limits: open PO snapshots use a linear scan with an upgrade comment; no unattended reordering.
Photos/nested groups, previewed CSV imports, grouped/tier prices, labels/scale parsing and batches
remain required backlog. B3 CSV imports next. Same-fingerprint/different-UUID serial rejection is
tested; a new PO-specific separate-process race was not run. MySQL8.4, physical devices,
provider/hardware, native builds and production audit remain unverified. No packages, Git, dairy or
subagents changed. Overall extended feature goal remains active.

## B3 — reviewed party/item CSV imports (3 October 2026, Nepal)

Added ImportService/ImportController, master_import_batches migration and ImportTest; CatalogService
now owns shared master validation/save used by MasterController and import. Routes,
ImportTools.tsx/test, Workspace/Masters links, Nepali labels, responsive CSS and same-origin
download handling updated. Dedicated development business_book received only additive
2026_10_02_172036 migration. No dairy, keys, dependencies, Git operations or agents changed.

UTF-8 CSV templates/exports support explicit IDs, all four party roles, exact prices/quantities,
categories, supplier links and POS/custom-unit settings. Preview is read-only: mapping/ignore
choices, physical row errors, before/after changes and counts. Existing IDs remain tenant scoped;
omitted fields stay as before. New items inherit niche defaults; explicit units override them.
Source corrections retain mapping when headers stay the same; edits require fresh preview. Apply
locks the tenant, revalidates version/digest and every row, commits all or none, keeps per-record
audit and count-only history, and replays original UUID. No bills, stock movements, balances or
payments are written by these imports.

RED evidence: initially missing import routes; mapping lost after CSV correction; accent-equivalent
identities accepted; formula-escaped supplier/category names failed export round trip. Regression
fixes verified. ImportTest7 tests/151 assertions cover malformed CSV/BOM/Nepali digits/quotes, exact
fields, partial updates, duplicate/zero IDs/SKUs, archive/system/foreign/role guards, frozen stock
units/party roles, stale review, unchanged export and escaped names. Forced second-row storage
failure rolls back categories/items/history/UUID/version; retry succeeds with same original UUID. No
import-specific process race was run.

Final full backend55 tests/974 assertions passed. Frontend10 files/19 tests, TypeScript, selected
ESLint and explicit-file Pint passed. Production build passed (220 modules,
mainJS1575.86KB/gzip356.40KB); size warning retained. Tests used disposable business_book_testing
and actual MariaDB10.4.19, not MySQL8.4.

Synthetic390px browser: six renamed headings mapped; invalid 1e3 price blocked review/apply;
corrected320.35 retained mapping. Batch1 created QA CSV Milk/item4 at80.25/l and QA CSV Paneer/item5
at320.35/kg with category QA CSV Drinks and supplier party3. Batch2 changed only Milk
price80.25→85.35. Normal catalogue confirmed both units/category and stock0. Export downloaded while
CSV dirty with input preserved and no leave dialog; re-upload showed five existing updates and no
changed fields. Expired overnight login renewed in another tab with CSV/mapping retained.
English/Nepali document width and scroll width both390; viewport reset. Inspected proof:
artifacts/import-errors-mobile.png, import-review-mobile.png, import-saved-mobile.png,
import-update-mobile.png, import-saved-nepali-mobile.png, import-catalogue-mobile.png; synthetic
export copied to import-export-qa.csv. Catalogue tab5 retained. Final browser inventory contained
only tab5; earlier cart/workflow tabs were unavailable, so their survival was not verified.

Official B4 barcode/label research recorded in NICHE-FEATURES.md: myBillBook code generation,
Loyverse printable label choices and Lightspeed aliases/embedded weight-price labels. Next: scoped
codes, configurable scan formats/checksums and label preview/printing. Known import ceiling500
rows/1MiB; financial/stock CSV migration must use normal posting workflows and remains required.
Grouped prices, B4 and Batch C–H remain pending. Physical devices/hardware, native builds, MySQL8.4,
complete Nepali copy and production release gates remain unverified. Overall extended
competitor/niche implementation goal stays active.

## 2026-10-03 — B4a product codes and configured scale scans

Changed: additive 2026_10_02_225951_add_barcode_codes_and_rules migration,
BarcodeService/BarcodeController, CatalogService/MasterController, ImportService/ImportController,
PosService/PosController, Money::format and API routes. Frontend BarcodeTools.tsx, Pos.tsx,
Workspace.tsx, item DTO, Nepali copy and small scan/review CSS; existing test runners cover new
behavior.

Scoped alternate codes and SKU collisions use native database identity; leading zeros and exact code
"0" survive. CSV aliases use JSON arrays and round-trip. Per-branch EAN-13/UPC-A formats support
configured prefixes/product/value digits, quantity units or NPR amount, decimals and checksum. Exact
SKU/alias wins over embedded parsing. Disabled, archived, ambiguous, foreign, incompatible-unit and
zero-value scans fail closed. Rule fields normalize numeric strings; malformed object lists reject
at validation. Integer grams exposed shared zero-decimal formatter bug; fixed there and covered by
ExactValuesTest.

Normal POS re-resolves original code, checks item/settings fingerprint and ignores forged derived
quantity before trusted preview/posting. Moving-average stock, party prices, original UUID retry and
partial original-price/cost returns remain canonical. Alias/format forms preserve each other's
drafts, protect dirty item selection and retain original uncertain-save retry. Scanner input becomes
read-only during lookup and keeps keyboard focus for repeated Enter scans.

Fresh checks: backend60 tests/1109 assertions; frontend11 files/21 tests; TypeScript/build/lint
pass. Explicit changed PHP file Pint pass after formatting. Main JS1588.05KB/gzip359.41KB warning
remains. Migration applied only to dedicated dev business_book; tests recreate isolated
business_book_testing, installed MariaDB10.4.19. MySQL8.4 remains unverified. No new dependencies or
Git changes.

390px synthetic browser: QA CSV Paneer aliases00501/00000123 saved; format QA Weight20, prefix20,
EAN13, product5/value5/decimals3/kg saved. Unsaved scale name survived another alias save. Exact
alias added1kg; embedded2000501003751 added0.375kg; combined1.375kg total440.48, independent0.375kg
total120.13. Bad-checksum2000501003752 preserved raw input and existing cart. Repeated valid scan
kept input focused and cleared it on success. English/Nepali width and scroll width390. Browser
preview only; no bill/payment created for stock0 item. Inspected screenshots:
artifacts/barcode-setup-mobile.png, barcode-cart-mobile.png, barcode-error-mobile.png,
barcode-cart-nepali-mobile.png.

B4b printed labels remain next: reviewed copies/code/name/price selection, physical dimensions/gaps
and browser printing. Hardware/native devices, direct scale communication, provider integrations and
statutory billing gates remain unverified. Extended B–H goal remains active; B4a is progress, not
app completion.

## 2026-10-03 — B4b reviewed product labels

BarcodeService/Controller/routes add read-only label selection and canonical preview: exact
SKU/alias/none, scoped active items, category/preferred supplier, integer standard price and
optional configured tax. Cashier cannot inspect supplier filtering.100 item/1000 label limits,
copied count, ownership, current codes and archived items validated. No journal, stock, payment or
version change. BarcodeTest extends boundary/price/tax/freshness/privacy checks.

LabelTools.tsx/lib/barcode.ts and their tests add Code128B/C encoding, checksum, stop/quiet zones,
preserved zeros, sheet/roll presets and calibrated dimensions, gaps/module/bar height.
Name/price/readable-code options, explicit copies and pages reviewed before printing. Edits
invalidate proof; print refreshes canonical rows and rejects physical overflow. Geometry/display
preferences only stored locally; item/price snapshots remain transient. Generated BB{item-id} code
is an alias draft until normal reviewed save, never fabricated manufacturer EAN. Workspace/barcode
links, Nepali strings and shared print CSS updated. ZintBSD3 table notice in THIRD-PARTY-NOTICES.md
ships via Vite build.

Checks: backend62 tests/1162 assertions; frontend13 files/24 tests; TS/lint/Pint pass. Build
JS1605.17KB/gzip364.75KB warning retained. A4 print PDF22 labels21+1 pages and50×30mm roll2 copies/2
pages rendered and inspected. Browser physical page rounding under0.2mm; printer calibration still
required. Shared Ionic print root containment/fixed sizing/background/compositing caused blank or
clipped pages; fixed centrally, inspected existing invoice including unit prices. 390px
English/Nepali review proof inspected, zero-padded alias retained, original 0.375kg POS cart
retained. No synthetic financial posting for these checks. Native print invoked but embedded browser
dialog/hardware unavailable to verify.

Remaining: grouped/tier pricing then Batch C–H. Physical printers/scales, direct connections, native
builds, provider/statutory gates and MySQL8.4 remain open.

## 2026-10-03 — B5a shared pricing backend

Changed PartyService, PartyController, MasterController, BusinessController, PosService/Controller,
BarcodeService/Controller and API routes. Migration 2026_10_03_004348_add_shared_price_lists adds
scoped named lists, minimum-quantity rates and separate sale/purchase contact assignments with
composite tenant FKs. PriceListTest created through Artisan. Existing trading service owns pricing;
no additional service or dependency.

List names/channel, enabled status, exact percentage changes, optional BS range, fixed item prices
and volume thresholds validate before atomic replacement. Version/UUID/tenant
lock/audit/cache-version patterns reused; standard/list/party prices resolve without money floats.
Disabled or out-of-range lists fail for review. List edits/assignments never post stock/money.
Cashier sees sale lists only; purchase selection/management denied and bill snapshots hide internal
assignments.

Normal POS combines same-item base quantities before tier selection. Amount mode crossing a tier
requires quantity entry, avoiding circular amount/price conversion. Barcode normalization receives
list/date context; expected total blocks stale posting. Original bills, quotes and returns preserve
saved amounts. Read price rules batch item names; limits1000 rules/list,10 tiers/item. Volume mode
prices all units at highest qualifying minimum; slab/basket pricing and list CSV remain work.

RED3 missing endpoints; GREEN3 tests/102 assertions. Full backend65 tests/1264 assertions pass after
explicit-path Pint. Applied migration only to dedicated dev business_book; tests use guarded
business_book_testing. No frontend edits this turn, so existing13 files/24 UI tests/build/lint
evidence remains prior B4b run. Five price routes verified by Artisan. B5b guided setup, party
assignment, reviewed bill quantity suggestions and optional POS list choice are next. No browser
proof or user-facing grouped-price completion claimed yet; full B–H goal remains active.

## 2026-10-03 — B5b shared pricing screens and checkout review

Delivered PriceLists.tsx with paginated channel lists, optional selection, guided List details →
Item prices / tiers → Review & save, fixed/volume rules and BS validity. Owner/manager/accountant
manage; cashiers view/select sales lists only. Reuse existing Picker, EntrySteps, useSave and
PartyService. No new dependencies. Party trading adds separate customer/supplier assignments,
explicit reload and original UUID retry. Draft policy, price-channel choice and assignment survive
refresh; versions stay pinned until confirmed save or explicit reload. Consecutive list saves adopt
only the confirmed version, never background revisions.

Sale/purchase/quote/order forms review combined same-item thousandths and apply current prices
explicitly. Party/list/date/quantity changes invalidate review; entered prices stay until applied.
Picker lookup receives optional list/date. Counter POS chooses list and date for scan/preview/save.
Selection immediately invalidates prior checkout totals. Server fingerprint covers normalized lines
and measurement snapshots; supplied stale proofs fail409 before posting even when an amount entry
produces the same money total with a different quantity. Counter UI requires proof; existing API
callers may omit it and retain canonical server pricing/total checks. No stale financial preview
saved to device cache.

Rule editor submits base-unit/kind snapshots. Server rejects save409 if item units changed since
selection; detail includes current unit metadata for review. Disabled/date-invalid/foreign lists
still fail through original scoped service. Posting, return cleanup and approved-quote snapshots
remain unchanged.

Changed: frontend/src/pages/PriceLists.tsx and its tests; PartyTools.tsx and tests; Pos.tsx and
tests; DocumentForm.tsx and tests; BarcodeTools.tsx; Workspace.tsx; components/Picker.tsx;
lib/types.ts, lib/i18n.ts, theme/app.css. Backend changes: PartyService.php, PosService.php,
PartyController.php, PosController.php and PriceListTest.php. Delivery/research contracts updated;
no Git or dairy changes.

Evidence: changed-path Pint pass. Full backend67 tests/1295 assertions, isolated
business_book_testing. Frontend16 files/30 tests; TypeScript, lint and web build pass. RED tests
proved lost same-total amount protection, outdated saved versions, quantity aggregation and reset
purchase-channel draft before fixes. New backend cases cover unit-race rejection and90.00
amount:1.000→0.750 units at120.00 price, unchanged90.00 total; stale proof rejected, fresh post and
original retry accepted. Check output retained in artifacts/b5-*-check.txt.
Bundle1627.04KB/gzip370.05KB; existing chunk warning remains. Actual local database MariaDB10.4.19;
target MySQL8.4 and physical/native devices remain unverified.

390px English/Nepali browser: synthetic QA volume prices B5 saved/reopened; 0.000kg320.35
and2.500kg280.25 tiers retained. No horizontal overflow (390/390). Bill quantity2.500 review
showed280.25/kg while entered320.35 remained; apply changed price; later quantity edit disabled
apply while preserving280.25. POS list change disabled checkout, then trusted2.500kg total700.63
enabled it. No browser bill/payment posted (test stock0). Party assignment save retained
unsaved1600.00 credit draft; fresh tab proved saved assignment and original1500.00 credit.
Assignment restored to original empty selection. Embedded browser stalled on a dirty-page reload;
fresh-tab verification succeeded. Real browser/device unload behavior remains unverified. Inspected
screenshot saved at artifacts/price-list-nepali-mobile.jpg. Preview server restarted only after
missing process handles/listening ports were confirmed; retained checks were rerun to capture final
results after turn interruption.

Official Zoho price-list/volume help rechecked3 October:
https://www.zoho.com/in/inventory/help/items/price-list.html Our Nepal adaptation uses NPR paisa and
BS dates. B5 volume pricing delivered; slab/basket pricing, list CSV and remaining C–H niche
features stay active.

Final fresh browser after fingerprint integration: Nepali POS preview2.500kg→700.63, checkout
enabled only with current server proof. Synthetic cart removed without posting. Party list
restoration confirmed through saved-assignment reload. Temporary viewport reset; saved-list review
retained.

## B6 — reviewed price-list CSV (3 October 2026)

Changed backend ImportService/ImportController/routes, shared PartyService list validation and new
PriceListImportTest. Frontend ImportTools/editor link/i18n and ImportTools test. No migrations,
dependencies, Git or dairy changes.

Price lists join existing source→mapping→review flow. Rows repeat list ID for updates, or
name/channel for creation; item ID/SKU/unambiguous name must agree. NPR decimal prices, thousandth
base quantities, BS dates, current unit/kind and versions use shared guards. Default merge retains
omitted tiers; explicit replace shows all before/after rules and clears included lists only.
Metadata-only rows support adjustment lists. Counts refer to lists; tier changes group on first row.
Limits1MiB/1000 rows/50 lists/10 tiers per item; master cap remains500.

Apply remains atomic under tenant lock/version/digest, with original UUID replay. No assignments,
bills, stock or money writes. Export captures scoped builders before streaming clears request
context; formula cells escaped, leading-zero SKU and snapshot round trips verified. Exports never
truncate; files over import caps need splitting. Templates/exports and preview/apply remain manager
roles only.

Tests: initial RED resource422; additional RED duplicate renamed-list preview. GREEN5 import
tests/109 assertions. Full backend72 tests/1404 assertions on business_book_testing (actual
MariaDB10.4.19); frontend16 files/31 tests. Explicit Pint, TypeScript/build and lint pass. Main
bundle1631.40KB/gzip371.32KB warning retained. Durable logs
artifacts/b6-{backend,frontend,lint,build}-check.txt.

Mobile browser390px English: two-tier CSV preview/apply returned batch3/created1. Reopened synthetic
QA CSV volume B6 list2 in Nepali, verified0.000kg100.25 and 2.500kg90.35, no horizontal
overflow390/390. Screenshot inspected/saved artifacts/price-list-csv-mobile.jpg. No financial
browser posting. Viewport reset. MySQL8.4/native/hardware/device performance remain unverified.
Slab/basket pricing and C–H ledger remain active; this completes B6 only.

## B7 — graduated/slab pricing (3 October 2026)

PartyService, PartyController and additive pricing_scheme migration provide volume/slab selection,
zero-start validation and exact marginal ranges. PosService combines item quantities,
previews/fingerprints each range, preserves original measurement notes and posts ordinary rows.
DocumentService permits repeated item rows with combined quantity bounds, retaining
stock/payment/cancel/return paths. ImportService includes scheme in CSV review/export and legacy
update defaults. Dev business_book received only additive2026_10_03_050811 migration; tests used
business_book_testing. No dairy, credentials, dependencies, Git or new services.

PriceLists editor explains ranges in EN/NE; Pos displays each quantity/rate/total. DocumentForm uses
shared pricing.ts to split reviewed quantities on Apply, merge compatible duplicate rows and
allocate fixed discounts with integer precision. Tax/percentage conflicts, oversized discounts,
stale quantities and >100 rows fail before changing entry. SlabPricingTest covers fractional
boundaries, fixed party priority, stale previews, foreign lists, CSV retention, UUID retry,
cancellation, source-line returns and accepted-quote price preservation. Frontend pricing/PriceLists
tests cover discount/tax compatibility and save.

Final full checks: backend76/1488 assertions; frontend17 files/35 tests; explicit Pint, lint and
TypeScript/build passed. Main bundle1636.77KB, gzip372.99KB warning persists. Durable
artifacts/b7-*-check.txt contain results. 390px browser saved QA CSV slab B7 list2, previewed2.501kg
as2.500@100.25 plus 0.001@90.35 =250.72, confirmed width390/390. Manual review kept entered320.35
until Apply then produced identical segment rows/total. No browser bills/payments posted. Inspected
artifacts/slab-pricing-mobile.jpg; test entries cleared and viewport reset. Basket pricing and C–H
stay active. MySQL8.4, native hardware and real-device performance remain release gates.

## B8b2b2a follow-up — salon phone proof (3 October 2026)

Stale opening tab15 removed by turn cleanup; browser clicks recovered without source/server changes.
Saved QA Chair, QA Haircut150 and offer6 QA Salon ten. Booked/arrived preview-only appointment in
isolated QA Salon Checkout branch: 150 less10 =140.00 on BS20830617. Verified390px no horizontal
overflow; saved artifacts/salon-checkout-offer-mobile.jpg. Tab16 deliverable and viewport reset. No
browser bill/payment/starting-balance/stock posting. Both restaurant/salon phone proofs now pass;
original cross-tab failure remains documented history. Existing96 backend/2094 assertions and49
frontend checks unchanged by QA setup. Next required work B8b2b2b manual/draft/accepted quote/order
offers, then C–H.

## B8b2b2a — restaurant and appointment offers (3 October 2026)

BasketService trusted checkout preparation reuses saved served-ticket/booked prices.
RestaurantService/AppointmentService retain source stage/version/date, tenant lock and original
UUID; PosController/routes add POST checkout/preview. One current offer applies before tax. Required
selected-offer fingerprint covers source/customer/date/full offer, even same-total changes. Persist
existing bill proof columns; committed retry bypasses later disabled/expired offer, while normal
source stock returns and payment-before-bill reversal guards stay intact.

Pos.tsx ReviewedCheckout + Nepali labels show named deduction, exact tax/total, fresh-proof payment
gate, aborted old previews, failed-selection preservation and uncertain-input locking. Parent retry
remains after live appointment completion. Tests: IndustryPosTest adds3 API cases;
CheckoutOffers.test.tsx4 UI cases. RED3 API/3 UI cases before implementation. Focused29 backend/734
assertions; full96 backend/2094 assertions,20 frontend files/49 tests. Pint changed-path check,
lint, TypeScript and build pass. Main1668.06KB/gzip380.13KB warning. Initial paid-bill cancellation
fixture corrected to cancel related payment first; no product reversal guard relaxed. Initial lint
command used wrong package path; rerun against installed frontend ESLint passed. Logs
artifacts/b8b2b2a-*.

Restaurant390px Nepali preview, isolated QA Restaurant Checkout: served2.501 QA Momo at100, offer5
saving10, trusted240.10; no horizontal overflow. Screenshot
artifacts/restaurant-checkout-offer-mobile.jpg, viewport reset, tab14 retained. No browser financial
posting or migration/dependency changes. Created isolated QA Salon Checkout branch for proof;
browser clicks stopped taking effect across tabs before chair save. Valid form and absent request;
restaurant checkbox also failed to change. No app patch without root-cause evidence. Cleared
temporary chair/table name; setup16 retained, stale opening15 unmarked. Salon live phone proof
remains pending, then B8b2b2b manual/draft/accepted quote/order offers. Batches C–H and target
MySQL8.4/native/provider gates remain required.

## B8b2b1 — quantity choice groups (3 October 2026)

Changed BasketService/BasketController, BasketOffers editor/selection, Nepali labels and
financial/UI tests. No migration or dependency. Legacy item rules remain compatible. Bundle and
buy/get roles accept explicit alternatives or live categories filtered by configured billed base
unit; explicit member unit/kind snapshots reject stale selection. Existing measurement conversions
normalize packs, weight, dimensions and amounts before group matching.

Capacitated matching and binary search find complete repetitions without reusing overlapping
quantities. Buy/get assigns cheapest feasible rewards while keeping buy requirements satisfied.
Group assignments and actual member snapshots enter preview fingerprint and immutable bill proof.
Matched quantities sum per source line before exact rounding/allocation; stock, taxes, returns,
cancellation and committed retry use existing posting paths.

Final sequential checks:17 offer tests/532 assertions;93 backend tests/2020 assertions on isolated
business_book_testing;19 frontend files/45 tests; Pint check, lint and TypeScript/build pass.
Main1665.85KB/gzip379.66KB warning. Initial overlapping database checks produced one missing-table
test error; final target/full runs executed sequentially and passed. Evidence in artifacts/b8b2b1-*.
MariaDB10.4.19 verified previously; target MySQL8.4 unverified.

Phone390px Nepali setup persisted offer4 QA Choice groups B8b2b1. Any1.5unit from QA Drinks
plus1service costsNPR200; cart250.10 +150 −100 =300.10 with fractional leftover at normal price.
Width390; screenshots choice-group-mobile.jpg and choice-bundle-preview-mobile.jpg. No browser
financial posting; cart cleared, counter closed, setup retained and viewport reset1280. Tests cover
overlapping restricted/broad groups, reward quotas, tenant/unit/member proof, original returns,
cancellation,500 million repetitions and20 groups sharing100 bill lines.

B8b2b2 remains: restaurant/salon checkout, manual bills and accepted-workflow offers. C–H remain
required, including batch/serial stock, niche job/rental workflows, provider/hardware/native work,
translations and target database/device checks.

## B8b2a — selected items/categories and Nepal-time schedules (3 October 2026)

Extended BasketService/BasketController and JSON ID-array serialization, additive 2026_10_03_070756
migration, BasketOffers guided entry and Nepali labels. Same service/financial paths; no dependency,
dairy or Git changes.

One target rule unions up to100 active tenant items and20 categories. Current category membership
determines eligible cart lines. Fixed NPR once or percentage up to100 discounts matching line bases
only; no overlapping double count, saving above eligible value or zero whole bill. Original
snapshots capture target names and matched item/category/units/kinds. Canonical taxes, stock, source
return and cancellation paths remain; edited offer does not change old returns.

Optional HH:MM pairs and weekdays use server Asia/Kathmandu clock; start inclusive, end exclusive,
overnight attributed to starting weekday. Equal/missing endpoints rejected. Empty weekdays means
every day; scheduled posting requires current Nepal BS date, with independent BS validity. Posting
rechecks expiry under existing mutation; committed original UUID replay remains valid after window
closes. Fingerprint includes schedule/date, avoiding minute/second churn. GET availability and
explicit refresh/review/removal are shown; no client-clock authorization or private caching.

RED4 backend cases and2 UI entry cases preceded code. Final target12 tests/246 assertions; full
backend88 tests/1734 assertions on isolated business_book_testing. Actual server MariaDB10.4.19
differs from target MySQL8.4. Frontend19 files/43 tests, targeted8 offer/preview tests; Pint check,
lint, TypeScript/build pass. Main1660.87KB, gzip378.59KB warning remains. Durable artifacts/b8b2a-*
logs record evidence. Additive migration applied only to own development business_book database.

390px Nepali setup persisted offer3 QA Saturday selected B8b2a,20%, QA Juice plus QA Drinks,
Saturday00:00–23:59. Mixed preview250.10 eligible +150.00 ordinary service −50.02 =350.08; document
width390. Screenshots category-saving-mobile.jpg and nepal-time-offer-mobile.jpg under artifacts. No
browser financial posting; temporary cart cleared/tab closed, setup retained and viewport reset1280.
Tests verify matched taxes/unmatched values,100% selected items, original stock/source returns after
config edits, live membership, foreign/archived category, boundary, overnight weekday, backdate
rejection and successful retry after expiry.

B8b2b remains required: alternative/category quantity groups and restaurant/salon/ manual/accepted
workflow offers. C–H, target database, native/hardware, complete translations and measured device
performance remain active. No parity/compliance or full application completion claim.

## B8b1 — explicit item bundles and buy/get rewards (3 October 2026)

Extended BasketService, BasketController and PosService; additive 2026_10_03_062555 migration stores
kind, repeat cap and reviewed item rules. Guided BasketOffers entry, shared selector, Pos
original-base display and Nepali labels support item bundle price and buy/get percentage up to100.
No dependency, financial-data cache, dairy change or Git mutation.

Bundle2–20 distinct items; one buy/get rule pair supports same/different items and fractional billed
base units. Integer division/cap limits repetitions. Stable bundle matching and cheapest reward
price ranges value matched quantities against original rounded line bases; exact largest-remainder
discount affects matched source rows only. Extra quantities/unmatched items preserve overall normal
value. Reward must already be in cart; no automatic injection. Bundle never upcharges; whole bill
remains positive. Canonical line discounts retain mixed tax, stock, source return and cancellation
behavior; free reward return can be zero-value. Partial returns retain existing source-line
proportional discounts. Immutable snapshot and complete fingerprint capture
rules/units/version/matches/repeats. Foreign/archived/stale-unit rules fail;
owner/manager/accountant setup and cashier permission, BS dates, minimum, optimistic version and
UUID retry remain enforced.

RED3 backend feature cases, bundle UI entry and POS original-base regression failed before
implementation. Target now8 tests/170 assertions, full backend84 tests/1658 assertions in isolated
business_book_testing. Actual local server is MariaDB10.4.19; MySQL8.4 still unverified. Frontend19
files/40 tests; targeted5 offer/preview tests; explicit Pint, lint and TypeScript/build pass.
Main1654.49KB, gzip376.97KB warning retained. Durable artifacts/b8b1-* logs record checks. Only
development business_book received additive migration.

Synthetic390px Nepali configuration persisted offer2 QA Buy get B8b1: buy1kg, get1kg100%, cap1.
Graduated2.501kg preview250.63+0.09−100.24=150.48; document width390 matches viewport390.
Screenshots artifacts/buy-get-setup-mobile.jpg and buy-get-counter-mobile.jpg. No browser financial
posting; temporary counter cart cleared/tab closed, setup retained, viewport restored1280. Backend
feature tests verify financial posting/replay, stock cancellation and original full returns.

Required next: B8b2 category/alternative/time windows, restaurant/salon and reviewed manual/approved
workflow offers. C–H, dynamic error translations, physical native/ hardware, performance and target
database checks remain active. Full competitor parity is not claimed.

## B8a — reviewed counter basket offers (3 October 2026)

BasketService/BasketController and additive2026_10_03_054749 migration provide tenant-owned named
fixed NPR/percentage offers, percentage cap, minimum spend after line discounts before tax, BS
validity and explicit cashier permission. Configuration/retry/version guards and composite
document/offer ownership protect setup. PosService quotes current normalized quantities/prices,
fingerprints all offer metadata and stores immutable document snapshot inside existing mutation.
Canonical invoice allocation/tax/stock/payments/returns/cancellation stay in DocumentService. One
selected offer; no extra bill discount stacking. Percentage below100 and discounts must leave
positive base.100 offers/business limit visible in backend validation. Dev business_book received
additive migration only.

BasketOffers.tsx provides EN/NE list/setup/selector; Workspace More links setup; Pos.tsx invalidates
old proof on offer changes and shows before-offer item amounts, single saving deduction, discounted
tax and grand total. BasketOffers/PosOffers tests verify exact values, failed/uncertain entry
retention, original retry and fresh checkout proof. Browser caught misleading discounted rows plus
separate saving; meaningful RED regression fixed display without altering financial totals. Live
Vite had cached an empty module during file write; fresh source transform recovered preview, no
restart or production behavior change.

Full backend80 tests/1569 assertions on isolated business_book_testing (actual MariaDB10.4.19).
Basket feature4/81 covers rounding/caps, threshold boundary, BS expiry, zero cap, version/same-money
changed fingerprint, cashier/tenant ownership with own valid cart items, UUID replay/payload
conflicts, mixed tax allocation, stock cancellation and source-price returns. Frontend19 files/38
tests; explicit Pint, lint and TypeScript/build passed. Bundle1647.43KB/gzip375.30KB warning
remains. Durable artifacts/b8a-*-check.txt contain final results; b8a-display-red.txt records the UI
regression. No dependencies, Git or dairy changes.

390px Nepali setup saved synthetic QA Basket saving B8a offer1:30.01 saving, 200.00 minimum.
Slab250.72 minus30.01 displayed220.71, width390/390. Under-minimum cart retained and checkout
disabled; removing offer restored100.25 and checkout. Inspected artifacts/basket-offer-mobile.jpg.
No browser financial posting. Cart cleared, temporary counter closed, saved setup retained,
viewport1280/1280 restored. Other checkout/manual/approved-workflow offers and
bundle/BOGO/category/time-of-day remain B8b. Dynamic server errors still English; translations,
target MySQL8.4, native devices/hardware/performance and all C–H features remain required.

## 3 October2026 — B8b2b2b manual offers, drafts, approved quotes; user manual

Changed: Document/Basket/Workflow services and preview controllers/routes; internal
POS/restaurant/appointment saves pass trusted canonical proof. Manual sale/draft and editable
quote/order forms review current offers. Draft posting rechecks full saved terms; edit first if
changed. Accepted quote/order conversion keeps its approved saving after expiry. Clones/copies
restore pre-offer values. No client proof snapshot accepted, no double offer application, source
returns and cancellation remain original-valued. Same committed mutation retries remain idempotent.
Draft posting now shows opening prerequisite before submit.

Frontend: shared cancellable review hook, named before-tax saving/total, immediate invalidation on
financial/source changes, original uncertain-action retry, raw draft/workflow edit values, frozen
printed proof and Nepali labels. New guide at frontend/public/help/user-manual.html:18 daily-task
sections,8 POS profiles, configured methods/custom units, party roles, monthly salary/rent payable
then later payment, corrections, browser print and connection recovery. More links guide. Only
public guide/images added to static PWA allowlist.

Fresh checks: backend102 tests/2225 assertions (139328ms), including6 new cases/ 131 assertions;
UI23 files/60 tests final sequential run (70860ms). Lint, types, changed-path Pint and production
build pass. Bundle1673.57KB/381.56KB gzip remains large. Evidence artifacts/b8b2b2b-*.txt. Initial
old workflow mock needed new offer-list response; two loaded parallel runs hit existing timing
limits. Assertions kept; final run used one worker. No product workaround.

390px UI:150−10=140 manual sale, saved draft9 and quote4/QUO-000001 in isolated QA Salon Checkout;
no journal/payment/stock posting or opening finalization. Manual/bill/draft/quote screenshots saved;
no overflow. Guide anchors valid and example images load. Guide/main-source assets copied into
production build. Both previous restaurant240.10 and salon140 checkout proofs remain valid.

No migration, dependency, service addition, Git mutation, subagent or dairy change. Goal stays
active: C–H, MySQL8.4, full translations, backups/restore, provider and native/device release gates
remain. Next C1 staged fulfilment; update guide with every verified feature batch.

## 4 October 2026 — configurable measurement expansion

Expanded33 named units from15, exact ratios and yard dimension input. Shared grouped selectors in
product/offer/scale setup; readable English/Nepali labels. Custom packs/local unit quantities remain
item-configured. No guessed regional formulas. Manual section4 documents families, mixed dimensions,
rounding, separate gallon standards and setup limitations.

RED: missing pair/square-cm units and missing readable selector options. Initial glass fixture
missed required is_customer and return route; corrected before product edits. API14 industry
tests/316 assertions pass, including17 conversion examples,
wrong-family/wrong-dimension/zero-rounded guards, mixed glass sale, original retry and exact
quantity/value return. Full104/2339 backend (140153ms); 24 files/61 frontend (111800ms), then final
affected13 checks. Pint/lint/types/ build pass. Duplicate translation key caught by final
TypeScript; cubic volume now uses distinct label from volume-pricing, no pricing behavior change.
Bundle1676.52KB/382.71KB gzip warning remains. Logs artifacts/measurement-*.

Mobile390px: six groups/33 options, shop-defined Small sheet=650sq_cm, enabled compatible methods.
QA glass item8 saved only metadata.32.5cm ×200mm ×1 gives 650.000sq_cm/NPR6500 in server-reviewed
cart. Screenshots measurement-setup-mobile, glass-measurement-entry-mobile and
glass-measurement-preview-mobile.jpg under artifacts. Cart removed, viewport reset, no financial
posting. Guide content reloaded/verified in browser. No migration/dependency/Git/subagent/dairy
changes. Goal remains active: C–H plus native/MySQL8.4/provider/device/release gates.

## 4 October 2026 — C1a1 physical fulfilment backend; C1 remains active

Changed FulfilmentService/controller, owned stage/line migration, protected routes and
Accounting/Inventory/Workflow integration. Reviewed partial delivery, receipt, service completion,
unbilled original-source return and cancellation APIs post stock once with pending-goods accounts.
Workflow versions/progress, stock/date/period limits, frozen VAT, exact receipt residuals, source
archival, HMAC private-cost proof, cashier privacy, actor/membership-aware original UUID replay and
database ownership guards verified. Legacy full bill/edit/cancel blocks active stages; premature
fulfilled status blocks remaining quantity.

RED failures reproduced missing routes, archived/unit-renamed source returns, service dates versus
unrelated stock, fractional receipt/return negative penny, archived party, zero-cost closed-period
cancellation and tax-setting change. Race fixture initially omitted required is_supplier; corrected
fixture only. Two processes now yield one fulfilled/one conflict. Migration backfill preserves
custom account codes; rollback preserves any fulfilment history. Isolated race test refreshes only
guarded business_book_testing for teardown.

Final full118 backend tests/2726 assertions,250839ms: artifacts/c1a1-backend-final.txt. Scoped Pint
pass after transient routes-file write failure; c1a1-pint-final.txt and c1a1-tax-pint.txt. Web build
pass, existing1676.52KB/382.71KB gzip warning: c1a1-web-build.txt. Frontend61 tests remain previous
measurement evidence; no staged UI code added. Public manual sections10/18 now clarify current
full-bill screens and pending partial screens; updated guide content verified in browser.

Actual Laravel database name checked as business_book before applying only
2026_10_04_015829_create_workflow_fulfilments; additive migration passes:
c1a1-development-migration.txt. Existing workflow UI smoke remains unverified: browser session
expired and protected app showed sign-in. No browser financial actions, new credentials,
dairy/dependency/Git/subagent changes.

C1a2 staged bills, owned bill allocations, bill return/cancellation integration, Ionic
entry/progress/slips and390px proof remain required. APP-SPECIFICATION 6.1.1 records pending
source-allocation money policy and penny-variance acceptance cases before financial implementation.
C1b billing-first/packages/backorders, C2–C6 and D–H plus native/MySQL8.4/provider/device/release
gates remain active.

## 4 October 2026 — C1a2 staged bills and C1a3 Ionic quantity flow

FulfilmentService now reviews/posts exact source-order partial bills through DocumentService with
owned bill allocations. Deliveries/receipts move stock once; bills clear held costs and record party
dues/actual payment. Source net, tax and discounts allocate cumulatively with exact final residuals.
Purchase penny differences use existing inventory gain/loss without another receipt. Original
offer/party/business provenance stays frozen. Billed returns retain original bill values/costs and
do not reopen order capacity. Bill/stage/source return cancellation preserves dependency, date,
period and stock guards. Cashier cost privacy, current parent ownership, revoked UUID retries and
true two-process terminal billing verified. Copies clear source allocation discounts for a fresh
canonical draft, preventing zero/negative rounding artifacts.

FulfilmentEntry/Panel and FulfilmentDetail implement mobile selected quantities,
delivery/receipt/service completion, separate partial billing, unbilled returns, guarded action
cancellation, progress/history and actual-quantity printing. Full-order
bill/edit/cancel/incomplete-fulfilled/delivery-sheet controls are restricted during active staging.
Another daily form cannot open during entry. All terms/quantities and workflow version invalidate
review; uncertain saves lock inputs and retry original UUID/payload. Additional RED cases reproduce
review disappearance after capacity/version changes and an open entry unmount when another actor
converts its order. Both now retain original pending review/ retry; resolved entries still require
fresh review after changes. SourceOrderAllocation discloses source amounts, allocated offer saving
and arithmetic differences on review and invoice print. Cancelled slips omit the now-editable source
party.

Fresh full backend130 tests/3102 assertions (305105ms): artifacts/c1a2-backend-full.txt. Targeted26
tests/763 assertions and scoped Pint pass: c1a2-backend-target.txt, c1a2-pint.txt. Full frontend71
tests/28 files (103.08s): c1a3-frontend-release.txt. Final types/lint pass: c1a3-types-complete.txt
and c1a3-lint-complete.txt. Final web build passes, 1698.72KB/388.00KB gzip with existing
large-chunk warning: c1a3-web-release.txt. RED evidence retains missing billing routes/allocation
decode, return/variance, clone rounding, worker dispatch and missing UI guards/entry. Fixture
assertions corrected for canonical string IDs and duplicate screen/print status badges.

390px browser visual QA: English partial-bill review, Nepali purchase receipt entry/review and
actual-quantity slip. Isolated sample records with posting disabled; no authenticated API financial
writes. This is visual proof only, not a live cookie-CSRF end-to-end staging test. Live access
rechecked: sign-in shown; no credentials changed. Guide edition2 sections8/10/14/18 updated and
fresh browser reload verifies delivery-first instructions/availability. Temporary preview HTML
removed and viewport restored; fixture source remains in artifacts. Production build excludes
preview HTML. Generated PWA version changes each build; only approved static assets are cached,
never private API responses. Only own business_book received additive bill-allocation migration
after fresh database-name verification: c1a2-development-migration.txt. Backend destructive checks
use business_book_testing only; dairy untouched.

C1a bookkeeping implementation verified; live authenticated end-to-end staging QA remains
unverified. C1 overall remains active: C1b bill-first/packages/ dispatch/backorders, C2–C6 and D–H
remain required, as do native/MySQL8.4/provider/ device/statutory release gates. No
dependency/Git/subagent changes.

## 4 October 2026 — C1b direct backend, signup country/phone and in-app manual

Additive bill-first policy, owned dispatch/return/package tables, nullable pre-bill allocations and
collision-safe pending accounts implemented. Four schema/backfill/composite-FK/rollback checks
passed: c1b1-schema-isolated.txt, 62 assertions. Schema fixtures use DatabaseMigrations because
MariaDB DDL implicitly commits; cleanup refreshes only the explicitly guarded test database.

FulfilmentService/DocumentService now review ordered partial bills before stock movement, defer
sales/purchase value, record confirmed actual handover/receipt/ work, and allocate exact source
costs. Unfulfilled credits do not manufacture stock; physical returns name their original dispatch,
retaining its cost after later average changes. Credit capacity closes without becoming a backorder.
Returns/stages/bills reverse in dependency order. Sources, reviewed notes, versions, tax treatment
and handover confirmation are checked. Multiple bills for the same order position share one physical
line but keep separate costs.

Additional RED cases found a recoverable-tax omission, same-position purchase cost reallocation,
misleading unbilled-return capacity, and zero-paisa credits releasing a future fulfilment penny.
Fixes retain fixed VAT, each supplier bill's own residual, explicit billed sources and actual
financial credit value. Rounding ahead of a physical target carries forward to the exact final
residual.

Signup requires separate country and phone fields. Nepal (+977) defaults; 245 country/territory
choices share pinned metadata between Ionic and Laravel. Country and national phone are stored
separately, preserving leading zeroes. Nepali digits/formatting normalize; pasted international
codes must match. No phone ownership/OTP claim. Existing users retain nullable fields. Public
registration still cannot create a platform admin. See COUNTRY-PHONE.md.

Manual edition3 updates signup instructions. More now routes to /app/{branch}/help and displays the
local guide within the Ionic workspace, with Back navigation. Its public print page/static PWA
assets remain available; private API stays uncached. 390px signup inspection confirms separate
controls, Nepal default, required phone and no horizontal overflow. No account/credential/browser
financial posting occurred. Authenticated manual-route QA remains unverified.

Full151 backend/3599 assertions passed before the final C1b penny correction:
c1b2-country-backend-full.txt, 377863ms. Final targeted33/938 assertions pass after correction,
including bill-first, existing staged billing and all five registration cases:
c1b2-penny-target-final.txt, 50170ms. Scoped Pint passes: c1b2-format-final.txt. Final frontend
types/lint and web build pass: signup-country-types.txt, signup-country-lint.txt,
signup-country-web.txt. Web bundle1711.54KB/391.85KB gzip; existing large-chunk warning remains.
Final full frontend71 tests/28 files pass after signup/manual changes (86.02s):
signup-country-frontend-full.txt.

Only own development business_book received the two additive migrations after
schema/financial/registration checks and an explicit database-name guard:
c1b2-signup-development-migration.txt. Destructive checks use business_book_testing only. Dairy,
keys, credentials, Git and dependencies untouched.

C1b remains in progress: new terminal races/revoked replay cases, packages/ shipment/customer
delivery, backorder printing and bill-first Ionic controls remain required. Guide keeps the flow
marked pending until controls ship. C2–C6 and D–H/native/MySQL8.4/provider/device/statutory release
gates remain active.

## 4 October 2026 — source concurrency and screen fit first pass

Bill-first races use two real php84 processes on business_book_testing: ordered bill versus ordered
bill, direct handover versus handover, and handover versus unfulfilled credit. Only one consumes the
same remaining quantity. Assertions reconcile held value, revenue/receivable/COGS, quantity/cost and
owned source/history counts. Passed 2 tests / 64 assertions / 146177ms:
artifacts/c1b4-concurrency.txt. Pint passed for race test and shared worker. No app financial
service or migration changed in this checkpoint. C1b cashier/ revoked-replay cases, packages and
Ionic controls remain unfinished.

Latest owner steering: country and mandatory phone on one row; avoid scrolling where possible across
screens. Signup now has compact intro and phone-height spacing, equal-width country/phone, mobile
password/confirmation row. Shared mobile chrome, panels and party/product entry whitespace reduced.
Touch target heights and 16px input text retained; errors/expanded content remain scrollable. Manual
iframe minimum reduced to 240px with viewport-relative height. Guide updated for same-row
country/phone. SCREEN-FIT.md records all remaining areas.

Live read-only final signup measurements: 320x667, 360x740, 390x844, 1280x800. Each content height
equals visible height, page width equals viewport width, phone is required and country/phone top
coordinates match. Sign-in/recovery initial states also fit 320x667 and 1280x800. Viewport override
reset; temporary public QA tab closed. No credential, registration or financial browser writes.
Shared protected-screen spacing has not yet been live checked with authenticated populated forms;
this is not an all-screen no-scroll completion claim.

Full frontend 71 tests / 28 files passed in 97.99s after row/shared spacing and manual-height
changes, before final shorter signup copy/equal-width adjustment: artifacts/screen-fit-frontend.txt.
Final copy is display text only. Final types/lint/build and scoped diff check passed:
screen-fit-final-types.txt, screen-fit-final-lint.txt, screen-fit-final-web.txt,
screen-fit-diff-check.txt. Build retains its existing large-chunk warning (1711.63KB / 391.88KB
gzip). Broad competitor scope and C2–C6/D–H remain active.

## 4 October 2026 — C1b retry ownership and package backend first pass

Billed delivery original UUID replay had a verified access discrepancy: a previously privileged
actor downgraded to cashier could replay a physical posting from another staff member's bill while
its fresh preview denied access. RED: c1b4-source-replay-red.txt, 1 failed / 13 assertions / 18326ms
(expected403, received200). FulfilmentService now checks current parent and historical billed
allocation creator/type/workflow before AccountingService returns cashier replay. Membership, role,
parent ownership and changed/cross-actor UUID cases pass. Final direct + delivery-first billing
regression: 34 tests / 1031 assertions / 60310ms, c1b4-security-final.txt. Initial added security
cases: 21/645/115762ms.

Package endpoints added under existing FulfilmentService/FulfilmentController: review/save pack,
show, review/save ship, review/save deliver, undo delivery and cancel. Pack reserves exact
billed-line capacity without journal/stock mutation; other daily sales still use that pool. Ship
rechecks real stock and posts an owned physical stage plus inventory-to-transit; deliver posts
transit-to-COGS and held-base-to-sales with no second stock move. Direct bill-first physical posting
shares its existing prepared posting body; purchase/legacy behavior kept.

Package and parent versions, source bill versions, dates, carrier/tracking and stock cost context
bind reviewed proof. Packed lines/snapshots remain immutable. Delivery undo retains journal
references and explicit BS/date/reason audit even for zero-valued journals. Reconfirmation uses
distinct source events and cannot precede undo history. Cancelling shipment uses existing
later-stock and return dependency rules. Progress distinguishes packed/shipped/confirmed quantities;
unconfirmed shipment has zero customer-returnable quantity. Cashier preview and reply omit
cost/journals; revoked original retries and foreign branches denied.

Package RED: four missing-preview404 failures, c1b5-package-red.txt (16776ms). Initial package +
direct/legacy regression: 38/1200/51476ms, c1b5-package-core.txt. Safety run: 8/9; ordinary-sale
fixture used a nonexistent route, corrected to existing documents/sale. Final safety: 9/370/41992ms.
Final package security and penny cases: 12/492/44063ms, c1b5-package-security-final.txt. Scoped Pint
passed. Full backend suite passed: 174 tests / 4409 assertions / 506539ms, c1b5-backend-full.txt,
terminal exit0. No schema/dev DB/dairy/Git changes in this backend checkpoint. Subsequent screen-fit
frontend changes are recorded below.

Package flow remains incomplete and has no Ionic controls yet. Before exposure: dedicated
undelivered-shipment returns (including later stock activity), explicit source modes and safe
recognition/cancellation dependencies; real package races; zero/mixed-source checks; policy-aware
compact Ionic forms, source-return UI, backorder/full slip printing and live authenticated QA.
C1B-IMPLEMENTATION.md records this gap. Existing in-app manual correctly marks the flow pending. All
C2–C6, D–H and SCREEN-FIT.md protected-screen audit remain active.

## 4 October — compact Home and More checkpoint

Owner requested minimal scrolling across screens. Signup country/required phone remain one row;
previous compact auth/common mobile spacing and manual height adjustment remain. More moved from
Workspace into More.tsx, showing one of five tool sections with role filtering, remembered URL
section and persistent guide link. Existing links remain; POS shortcut added. Workspace sidebar icon
import was restored and unused Heading removed after type/lint found extraction errors.

Dashboard now opens daily actions, with separate balances/activity/stock views. Cashiers cannot
select balances. Recent/stock previews page all returned rows, two per page; each page persists
across view changes and clamps on shorter data. Exact currency, source links and backend permissions
are unchanged. Starting balance link remains on initial daily-actions view; expense shortcut
remains. Guide edition4 explains Home/More sections and necessary scrolling cases.

Browser fixture proof: all Home views EN/NE320x667 without page overflow; initial Nepali starting
link also fit. Stock view360x740,390x844,1280x800 fit. Longest More Money section EN/NE320x667 fit;
default Sales fit larger sizes. Role views and next sample pages checked. Real More plus current
Dashboard source/API-data stub used existing Ionic shell/CSS. Public screenshots archived, temporary
fixtures removed from frontend and browser overrides reset. No account or financial browser writes;
actual authenticated routes/long data remain unverified.

More RED2 and Home RED3 observed against old screens. Home currency assertion was corrected to
existing rupee label; product currency unchanged. Final full frontend:76 tests/30 files/91.24s,
screen-fit-navigation-frontend.txt. Final product TypeScript/lint/build passed
(screen-fit-navigation-final-*); temporary fixture-hook type mismatch was removed when fixtures were
archived. Web main bundle1716.78KB/392.96KB gzip retains size warning. Scoped diff check exit0. Full
backend already passed174/4409/506539ms; no backend changes for screen fit.

SCREEN-FIT.md records remaining route-by-route work. No all-screen, complete C1 or competitor parity
claim. C1 transit returns/package races/UI and C2–C6/D–H remain active. No dairy/database/Git
mutations in this screen-fit checkpoint.

## 4 October — Money and regular setup screen-fit checkpoint

Records.tsx MoneyList groups all seven existing actions into receive/pay, transfer/owner and
refunds; history is a separate section with existing server paging. Selected correction replaces
history while keeping section context disabled. Its original date/reason payload and endpoint are
unchanged. Uncertain correction locks fields/close and retries original useSave action; cashier
keeps its sales-only fallback and performs no payment-list request.

RegularPayments.tsx reuses EntrySteps for new Payee/Amount/Schedule on phones and Amount/Schedule on
edits. Desktop lays sections side by side. Amount details retain label, enabled/paused flag and full
monthly rules; other bills keep their category. Final Schedule shows payee/raw entered NPR
amount/status, dates and explicit automatic-payable control. Creation/PATCH versioned payloads
remain. Mobile Save stays beside Back; setup list/history no longer stack with editing. Monthly
history remains a disclosure outside edit. RecordMonth posting is unchanged and still requires its
own fit audit. Manual edition5 documents flows.

RED Money3, then Money3 green/Regular3 red. Final targeted8 tests pass/9.32s,
screen-fit-money-regular-final-tests.txt. Cases verify salary/rent/other payload, existing
payee/flags, immutable edit references/version, amount-error step focus with retained name,
correction payload and original uncertain retry. Latest full frontend84 tests/32 files/101.37s,
screen-fit-payments-final-frontend.txt. Final TypeScript/lint/build passed; bundle1721.31KB/394.18KB
gzip retains warning.

Read-only posting-disabled source fixtures: Nepali salary3 steps fit320x667; other category step and
final review fit after excess bottom spacing reduced. Money4 sections, one-row history and
unsubmitted correction fit320x667. Regular desktop fit1280x800 before final small summary addition.
New review summary was rechecked on phone; long names/errors/options/keyboard and final desktop
summary remain unverified. Public screenshots and source fixtures archived; no temporary frontend
modules/routes remain, no browser financial/account submission. Backend unchanged after
full174/4409. No database/dairy/Git mutations. SCREEN-FIT.md and C1B-IMPLEMENTATION.md remaining
gates stay active; no all-screen/parity claim.

## 4 October — Daily payment / return / count screen-fit checkpoint

DailyForms.tsx now uses shared EntrySteps: Party/Accounts → Amount → Review, Items → Reason →
Review, and Item → Count → Review. Desktop displays all panels. Phone Save stays beside Back. Return
items page two source lines; state survives paging and rejected named quantities reveal their page.
Count history opens separately, preserving entry values. Optional
allocation/advance/overdraft/notes, refund account and zero-cost confirmation remain. Review
includes selected account or count/unit cost. Uncertain forms lock fields/navigation, exposing
original-action retry outside the lock. Endpoints, integer calculations and original posting fields
remain. No backend/database change in this checkpoint.

RED14 against old forms, then19 targeted interaction checks passed6.05s. Coverage includes all seven
money kinds, selected/manual bill allocation, notes, explicit advance, original return penny
rounding across pages, immediate refund account, later-line validation recovery, count
payload/history, role restrictions and safe uncertain retry. Latest full frontend103 tests/33
files/112.30s passed: artifacts/screen-fit-daily-final-frontend.txt. Final TypeScript/lint passed,
web build passed1729.23KB/396.09KB gzip, existing size warning. Scoped diff check passed with
line-ending notices only. Backend174/4409 remains unchanged.

Current-source posting-disabled Ionic fixtures proved Nepali320x667 payment, return and count steps,
refund explanation/account and fixed final Save. Payment review also fit360x740,390x844,1280x800;
count desktop1280x800. English initial return item page and Nepali transfer account step fit320x667.
See SCREEN-FIT.md for measured bounds and still-unverified live/long/error/picker/keyboard states.
Screenshots and fixture copies archived under artifacts/screen-fit-daily* plus receipt/return/count
PNGs; temporary frontend modules removed, tab closed and viewport reset. Manual edition6 documents
daily steps. No browser financial or account writes, dairy changes, Git mutation or whole-screen
completion claim. Broader C1–C6/D–H feature goal remains active.

## 5 October — Compact masters/reports/businesses and mobile navbar Back

Masters now use three phone steps, preserving original save payloads, four party roles, optional
product/measurement setup, edit balances and uncertainty locks. Business selection pages two books;
new setup opens separately and Cancel retains typed values. Reports separate
Results/Filters/Balances and page two rows on phones/six on desktop; loaded rows remain printable.
Existing roles, queries and exact-value calculations remain. Manual edition7 records these flows.

Requested mobile jumbotron removal applies to shared workspace Heading below768px. Its title remains
accessible; existing Back moves before the navbar logo without changing its destination or blocked
handler. Count history remains. Desktop keeps heading and original Back position. Five Heading
regression checks cover English, Nepali, original navigation, blocked navigation and
desktop/standalone fallback. RED three failures/two passes; final targeted five passed. Latest full
frontend: 123 tests/37 files/60.89s, artifacts/mobile-heading-final-frontend.txt. Build output
1737.41KB/398.11KB gzip retains the existing large-chunk warning. Fresh TypeScript, full frontend
lint and root web build each exited0 after final product changes. Scoped whitespace verification
followed documentation updates.

Current-source posting-disabled fixtures measured mobile CountForm320x667 with Back44x44 before
logo, hero visually clipped, review bottom462.19 and Save visible; desktop1280x800 restored heading
and page Back with values retained. Additional catalogue fixture results and limits are in
SCREEN-FIT.md. Preview/source artifacts archived; temporary frontend fixture files/tabs removed,
viewport reset. No browser financial/account writes, backend/database or dairy changes in this
checkpoint. Historical backend174/4409 does not certify unfinished C1b transit-return edits. Broader
C1–C6/D–H feature goal and remaining screen audit stay active.

## Tenant platform extension — 2026-10-10, in progress

Full spec and implementation plan approved. Inline execution; no Git operations, subagents,
production migrations or dairy modifications.

Task 1 adds billing account schema/backfill, active account-owner authorization, account
listing/creation and account-scoped business creation. Existing ledger membership remains separate;
branches inherit parent billing account. Existing business endpoint accepts validated
billing_account_id or deterministic owned default. New account tests first failed on absent routes.
Initial implementation run: 2 of 3 passed; disabled fixture used mass assignment for intentionally
guarded disabled_at. Fixture corrected to explicit test DB update. Legacy backfill test added.
Combined account/auth/branch regression currently running; no final success claim yet.
Packages/providers/messaging/gym/UI remain pending; this account foundation does not yet enforce
account package limits or prevent per-business trial renewal. Ruling: execute in existing workspace,
no new worktree/branch/delegation — project Git/subagent restrictions persist despite blanket
routine implementation approval.

Account foundation verification: corrected BillingAccountTest passed 4 tests/24 assertions. Combined
run before fixture correction passed 39/40 (621 assertions); only failed test was guarded
disabled_at fixture, now corrected and passing. Existing 36 auth/business/registration/industry
tests and legacy backfill were passing in that combined run. No production migration executed. Task
2 next.

## Package foundation — 2026-10-10, in progress

Added duration/trial/product/business-count package catalogue, integer minor-unit prices, immutable
subscription snapshots, account trial eligibility and platform package APIs protected by existing
superadmin guard/password/version/audit. New accounts start one configured catalogue trial;
additional businesses share that expiry. Expired accounts cannot create businesses; owner/accountant
existing read/export exception and separate billing access remain. Account suspension blocks
business use. Account limits check under account lock; AccountingService now acquires account before
business lock, including nested branch creation. Restaurant/appointment/gym route product checks
added; complete type/POS selection and navigation remain Task 3 work. Legacy existing books preserve
individual access dates; additional books require packaged subscription, preventing new trial.

Verification: red SubscriptionTest demonstrated per-business trial restart and missing package
schema/APIs. Initial combined migration failed because local MariaDB disallowed second required
timestamp default; replaced required start/end with explicit UTC datetime. Corrected
account/subscription suite passed 8 tests, 51 assertions. Syntax checks passed. PHP formatter ran.
Existing regression run live at session 55668; inspect same handle before restarting. MySQL 8.4
concurrency, paid settlement/downgrade, UI and all later subsystems remain unverified/pending.
Ruling: reuse ResolveTenant for product checks rather than introduce a thin second middleware —
existing route tenant context already owns access authorization. Ruling: default catalogue trial is
14 days, 100 businesses, all current products; platform can configure terms. No invented paid price
seeded; gateways require explicit configured minor-unit prices before checkout.

Package regression result: session 55668 completed successfully, 44 tests passed, 648 assertions
(account/subscription, existing business/auth/phone/industry flows). PHP formatting passed. No
production migration executed. Next: account/package UI, business-type enforcement, then payment
settlement/providers and messaging/gym.

## Account/package UI and business creation — 2026-10-10

Added /billing overview, reachable outside expired business workspace: own accounts, frozen
subscription terms/expiry/products/business limits and exact currency prices. Added separate-guard
platform package editor with duration/trial/capacity/products, NPR/USD/EUR exact price entry,
password/reason/version and uncertain-save controls. Business creation now selects one type and
billing account; backend validates type and package product in both business endpoints and shared
TenantService. Pos profile edit rechecks product and blocks change while open restaurant orders or
live appointments exist. Existing POS already renders restaurant/barber/salon-specific screens; gym
selection exists, gym operations/navigation still pending Task 11. Panel manual now includes section
19 with accurate current availability notes.

Checks: type test first failed because request business_type was ignored; after implementation
account/subscription/industry suite passed 23 tests/371 assertions. Business chooser test first
failed on missing selector; then fixture corrected to return billing accounts separately from
businesses. Focused UI passed 3 tests. Full frontend passed 129 tests/39 files, including
exact-price package editor and uncertain-save lock. Lint found unintended unused hooks inserted into
Invitation; removed them and reran lint/build (session 84999). Initial production build passed;
final rerun result must be checked before claiming success. Browser visual/live posting and MySQL
8.4 concurrency remain unverified. Payments/messaging/gym pending.

UI final verification: session 84999 completed, lint passed and production build passed. Existing
bundle-size advisory remains. No provider/live browser validation claimed. Next implement durable
checkout/atomic settlement, then all four gateways.

## 2026-10-10 subscription checkout and Khalti provider

Durable subscription payment attempts and owner-scoped checkout/confirm routes now freeze package
terms and prices, deduplicate request UUIDs, verify provider amount, reference and currency, and
extend subscriptions once without financial ledger posting. Retained-business selection enforces
package allowance and preserves read/export access for excluded businesses. Existing financial
tenant isolation remains in place. BillingPaymentTest fixture corrected to journal_entries.

GatewayService now implements Khalti server initiation and authenticated lookup using stored pidx.
Ignores untrusted callback status/identifier, accepts integer paisa only, restricts provider
redirect host, disables HTTP redirects and avoids automatic initiation retry. Config billing.php
defaults sandbox; example merchant key remains blank. Official contract:
https://docs.khalti.com/khalti-epayment/.

Checks: account/subscription/payment/Khalti suite passed 14 tests/81 assertions; then expanded
Khalti suite passed 5 tests/11 assertions (wrong pidx, fractional paisa, forged success callback,
unsafe redirect, frozen checkout amount). Changed PHP files formatted successfully. No live provider
requests or production data migration performed. Pending: eSewa/Stripe/PayPal, checkout
frontend/return handling, reconciliation/refunds, emails/FCM/WhatsApp/campaigns, gym and completed
manual.

## 2026-10-10 four provider protocols and tenant checkout UI

Implemented GatewayService eSewa signed POST and callback signature validation, independent
COMPLETE-only status lookup, and decimal lexeme preservation before JSON decoding (no floating-point
money). Added Stripe Checkout creation/retrieval with reference/environment binding and provider
idempotency. Added PayPal OAuth, Orders, merchant/amount checks before stable-id capture and
canonical order read after capture. Unsupported currencies denied; PayPal has no NPR conversion.
Outgoing provider destinations are fixed; returned redirect hosts are allowlisted.

Billing API exposes configured gateways, owner-scoped account business selection, latest 25 payments
and existing confirm/status routes. Credential validation occurs before attempt insertion. Canonical
failure states persisted. Account lock blocks new request UUID while prior charge remains
unresolved; same UUID still replays. Explicit empty business selection is preserved instead of
silently selecting all. All provider return URLs use configured Ionic FRONTEND_URL.

BillingCheckout.tsx adds reviewed exact price/terms, provider/currency selection, retained
businesses, signed eSewa form/provider redirects, return-page status, recent history and
uncertain-original-action retry. Included in owner account sections on Billing.tsx. Frontend allows
only expected provider hosts for handoff. Panel manual and DEPLOYMENT.md updated; environment
examples contain blank keys.

Checks: final backend billing/account/subscription/four-provider suite passed 34 tests/142
assertions. Focused billing UI passed 5 tests/2 files. Full frontend passed 133 tests/40 files.
Frontend lint and production build passed (existing large-bundle advisory). Changed PHP formatted
with Pint. Real provider calls, production migrations, browser visual checkout and MySQL concurrency
not claimed. Remaining: provider reconciliation, webhooks/refunds/disputes, sandbox/live smoke
checks with merchant configuration, generic account emails, FCM/WhatsApp, package
reminders/promotions, gym, final type routing/manual completion.

## 2026-10-10 scheduled unresolved-payment reconciliation

Added migration 2026_10_10_095817_add_subscription_payment_reconciliation.php with last/next check,
token-owned lease and safe error-code fields. BillingService now selects due unresolved attempts,
atomically leases each, independently verifies provider state and reuses atomic settlement. No
replacement provider initiation. New/in-flight attempts receive two-minute grace; pending checks
defer five minutes, failures fifteen minutes, missing provider identifiers daily for manual review.
ReconcileSubscriptionPayments command bounds work (default10, maximum100); schedule runs every five
minutes without overlapping. Panel manual and deployment guide updated. Verified
payments/refund/dispute/webhook handling remain pending.

Initial reconciliation tests failed on missing schema/behavior. Reconciliation+ checkout suite then
passed 10 tests/71 assertions. Added in-flight initiation and pending-lookup deferral tests; final
billing/provider suite and schedule registration verification currently running in session2880. No
production migrations or live merchant requests performed.

Reconciliation final verification: session2880 completed successfully. Billing, account,
subscription, reconciliation and all four provider suites passed 40 tests/188 assertions.
schedule:list confirms five-minute reconciliation command registration alongside existing
application schedules. In-flight initiation and pending-check deferral regressions passed.
Production scheduler operation and real provider reconciliation remain unverified. Refund/dispute
policy still requires implemented audited entitlement adjustment (approved spec section4); no
business journal mutation or unreviewed refund-time removal added in this batch.

## 2026-10-10 reusable account email templates

Added NotificationService message catalogue and AccountMessage reusable mailable with shared branded
HTML and plaintext views. Prepared welcome, email verification, password reset, password changed,
invitation, exact package receipt, trial/expiry reminder and promotional templates. Names/body
escape in HTML; plaintext preserves real link query separators. Action/unsubscribe links restricted
to configured frontend/backend origins; unsafe protocols and external origins rejected.

AppServiceProvider now customizes existing Laravel reset and verification mail. Reset preserves
frontend token/email link, broker expiry wording and framework reset behavior. Verification retains
existing signed backend URL creation. No plaintext reset token stored in delivery history; no
delivery history introduced in this template batch. Welcome/password-change/invitation/receipt event
hooks, durable queue/preferences/history and scheduled multi-channel sending remain pending;
templates alone do not claim those messages are automatically sent.

Initial template/security tests failed before implementation. Initial five template tests then
passed (23 assertions). Expanded template and registration/password-reset regression verification
running in session34765. Changed PHP files formatted. No live account emails sent as part of
implementation checks.

Email final verification: session34765 completed successfully, 13 tests/78 assertions passed across
templates, link safety, registration and tenant-only password reset. Signed verification URL, exact
receipt formatting, HTML escaping and plaintext URL checks passed. SMTP delivery, queued event hooks
and multi-channel delivery remain unverified/pending as recorded above.

## 2026-10-10 durable account email delivery and security hooks

Added message_deliveries/notification_preferences schema, encrypted context, hashed event
deduplication and ID-only SendAccountMessage jobs. NotificationService enqueues transactionally,
dispatches after commit, recovers queue-dispatch failures, locks delivery before send, and prevents
replay of sent/unknown messages. Promotion email consent defaults off; send-time
disabled-user/membership/opt-out/subscription renewal checks skip stale work. Token
reset/verification events cannot enter durable context; existing signed/token notification flow
stays intact. SMTP failures and stale sending workers become unknown, with safe error codes and no
blind resend.

CreateNewUser atomically queues welcome. ResetUserPassword/UpdateUserPassword atomically queue
password-change notice. Corrected pre-existing current_password:web rule to tenant guard, verified
against successful tenant password change and wrong password denial. BillingService queues package
receipt in verified settlement, deduplicated by payment reference. Panel manual section20 and
deployment notes added.

Initial delivery tests failed before implementation, then 12 tests/37 assertions passed. Initial
hook tests showed missing welcome and wrong password guard; after hooks/fix, messaging/auth/payment
regression suite passed 26 tests/136 assertions. Expanded
revoked-member/renewed-subscription/job-payload checks and scheduler listing currently running in
session90302. PHP files formatted. No live SMTP, production migration, push or WhatsApp sends
performed. Remaining full goal includes reminder scheduler/preferences/history UI, invitations,
FCM/WhatsApp/campaigns, gym, audited refund adjustments/webhooks and final platform
account-management verification.

Durable-email final verification: session90302 completed successfully. Expanded
messaging/auth/payment/reconciliation checks passed35 tests/189 assertions. Scheduler lists
one-minute account-message dispatcher alongside payment polling. Queued membership revocation,
renewal suppression, payload privacy, rollback, event replay, wrong/correct tenant password and
uncertain-send checks passed. Live SMTP/queue worker operation remains unverified;
FCM/WhatsApp/campaigns/gym and other recorded full-goal items remain pending.

### Firebase HTTP v1 transport — 2026-10-10

- Added FcmService: service-account RS256 OAuth, fixed Google endpoints, bounded timeouts, redirects
  disabled, exact device targeting, safe provider result codes.
- UNREGISTERED classified separately; ambiguous transport outcomes never reported sent.
- Configuration placeholders in backend/.env.example; no real credentials added.
- Verified FcmServiceTest: 3 tests, 6 assertions. Windows test command requires OPENSSL_CONF
  pointing at installed OpenSSL config.
- Device registration regression verified: 5 tests, 34 assertions.
- Still pending: queue/device delivery integration, frontend registration/preferences, Firebase live
  verification, WhatsApp, reminders/campaigns, gym and remaining full-platform acceptance.

### FCM queue integration — in progress

- Added message_device_deliveries migration and per-device status records; queue routes FCM
  separately from email.
- Delivery rechecks push consent and current device ownership; UNREGISTERED revokes matching token
  only. Repeat parent jobs never resend terminal deliveries.
- New FcmDeliveryTest covers per-device send/replay, invalid-token revocation and promotional
  optout.
- Verification pending: first mixed run conflicted with overlapping test database reset; stopped
  both owned test processes. Serial FCM verification running; do not count conflicted runs as
  passing.

FCM queue verification: corrected MySQL unique index name; serial FcmDeliveryTest passed 2 tests / 7
assertions. Existing email suite still requires serial regression rerun. No live Firebase delivery
verified.

### Browser FCM and notification panel — 2026-10-10

- Added account Notifications page, independent operational/promotional channel choices, owned
  device list/revocation and explicit browser push permission.
- Current Firebase registration API uses FID; backend target_kind preserves legacy token
  registrations while new registration replaces same device identity.
- Root module sw.js bundles Firebase; worker keeps financial/API/auth routes network-only.
  Foreground account notices scoped to signed-in user; generic operational push avoids
  financial/private details.
- Tenant logout revokes browser device; server Logout event additionally handles lost enrollment
  response using owned device UUID.
- OAuth cache stores encrypted access token with two-minute expiry margin and clears rejected
  credentials.
- Verified earlier: backend 23 tests / 78 assertions, frontend 138 tests / 42 files, worker privacy
  2 tests, logout backend regression 1 test / 5 assertions.
- Final regression after minimal Firebase app/messaging dependency change still pending. Live
  Firebase delivery, real browser visuals and provider credentials remain unverified.

Final FCM/browser batch checks: backend messaging/device/mail suites 31 tests / 109 assertions
passed; complete frontend 43 files / 141 tests passed; lint and production build passed using
bundled Node24.19.0. Built sw.js has no unresolved placeholders, preserves API/auth bypass, and
includes updated manual/third-party notices. Minimal @firebase/app0.16.3 and
@firebase/messaging0.13.3 replace full Firebase package, removing unused Firestore dependency chain.
No real Firebase delivery or deployment performed. Remaining task scope includes WhatsApp, reminder
scheduler/campaigns, gym, billing refund/dispute handling, platform account-management audit,
business-type routing and final full-platform/live validation.

Dependency security follow-up: compatible concurrently10.0.6 patch pins shell-quote1.12.0; fresh
audit confirms zero critical vulnerabilities; ten other advisories remain. Unused Firestore packages
removed. Root npm workspace/lock retained; no Git operations or production migrations. FCM live
checks and resilience audit (including bounded per-device queue fanout/retry timing) remain release
verification items alongside full platform objective.

### Added requirements: formatting and long-running queue work

User expanded full objective on 2026-10-10: format all project files and use queue for every
long-running application process. Added root Prettier configuration and format/check scripts;
Laravel Pint covers PHP, Prettier covers frontend, HTML, JSON/configuration and Markdown. Formatted
account HTML email template separately. Generated caches/dependencies and recorded QA logs follow
generators and stay out of routine formatting. Queue audit confirms synchronous invitations,
report/export paths and imports need assessment; push fan-out needs separate bounded device jobs.
Encrypted Laravel notification jobs are supported by current framework. These remain required work,
alongside WhatsApp, campaigns/reminders, gym and final full-platform acceptance. No goal completion
or production deployment claimed.

Formatting verification: Prettier check and PHP Pint check passed; post-format frontend 43 files /
141 tests, lint and production build passed. Initial full backend run returned 256 tests: 113
passed, 143 schema errors (missing/duplicate MySQL tables), so it is not a passing full-suite
result. Another active test runner was observed against same database. Added process-held test-suite
lock preserving database safety guard; independent OS-lock probe passed (1 test / 1 assertion).
Existing external run left untouched; isolated full backend rerun remains pending. Critical
shell-quote advisory cleared by concurrently10.0.6; fresh audit still lists ten non-critical
advisories. Notification live verification and expanded queue/platform work remain incomplete.

### Queue conversion: subscription reconciliation

Scheduler now dispatches unique, ID-only ReconcileSubscriptionPayment jobs; provider verification
moved to workers. Jobs use after-commit dispatch,60-second timeout and bounded retry/backoff.
Existing token-owned attempt lease, exact payment settlement and no-replacement-charge rules
preserved. Command reports queue dispatch outcome; provider failures stay in payment history.
Non-mutating queue regression passed1 test/5 assertions; full reconciliation regression running
serially after previous observed runner ended. Other slow application paths still require queue
conversion; full goal remains active.

Queued reconciliation verified: 7 tests / 51 assertions passed. Full backend suite relaunched with
process-level shared-database lock; result pending. No provider live requests or production
migrations.
