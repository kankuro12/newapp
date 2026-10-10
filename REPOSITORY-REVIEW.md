# Repository review and research basis

Review date: 2026-10-02. Source workspace: `E:\laravel pojects\dairy main\dairy`.

Current new-app architecture: Laravel backend + Ionic React frontend in `newapp`. See
[MONOREPO-CONTRACT.md](MONOREPO-CONTRACT.md). This repository review describes the earlier read-only
dairy review; subsequent dependency installation occurs only in the separate newapp scaffold.

## Review scope and evidence quality

The owner's clarification was “read whole repo.” Review used the repository-wide existing Graphify
map and report, a fresh first-party source inventory, project instructions, domain conventions,
focused graph traversals, and direct reading of core financial flows. This is a whole-repository
architectural review, not a claim that every line of 3 million words, bundled JavaScript, or
third-party dependencies was manually audited.

The full `graphify-out/GRAPH_REPORT.md` was loaded and its sections and displayed community blocks
scanned. Targeted graph queries traced sales, purchases, stock, payment, and calendar dependencies.
Graph labels can be misleading: one “Nepali Date” community contains manufacturing classes, and a
“Payment Manager” community includes JavaScript date-library symbols. Source checks therefore govern
every reused domain decision.

Graph report claims 4,849 corpus files, approximately 3,092,131 words, 21,046 nodes, 32,626 edges,
and 4,502 communities; 1,967 thin communities are omitted. Its token-cost record is zero input/zero
output, a recorded tool metric, not proof of semantic completeness. It also records 4,439 weakly
connected nodes. These limitations make it a navigation aid, not an audit certificate.

Graph was built against `9edad0e4`; current checkout inspected is `786753e38`. It is stale relative
to HEAD and does not prove current working-tree behavior. Core facts below were checked against
current source. No graph rebuild, source mutation, live-database change, or dependency installation
was performed.

Fresh counts: 441 model files, 1,169 migration files, 2,173 view files. Counts include legacy and
duplicate files where present. Full tracked/unignored first-party inventory totals: resources2,192,
database1,176, app1,056, .github55, config31, routes11, discovered tests8, reports3, plus
root/support files. Ignored tests exist and are not represented by this discovered-test count.
`composer.json` identifies Laravel `^8.12`, PHP `^7.3|^8.0`, Passport and globally autoloaded
`app/custom.php`. `package.json` uses Laravel Mix/Webpack. None of that configuration should be
copied into the new Laravel 13 app.

## Whole-app domain map

| Existing domain                                  | New-app decision                                         | Reason                                                        |
| ------------------------------------------------ | -------------------------------------------------------- | ------------------------------------------------------------- |
| Customer/supplier/normal-party records           | One contacts table with customer/supplier flags          | One business may buy and sell to same party                   |
| Multiple POS/billing controllers                 | One document feature with explicit types                 | Same totals/posting rules across web forms                    |
| Supplier purchases, item/expense lines           | Stock purchases plus service expense documents           | Keep daily buying simple                                      |
| Customer/supplier payments, cheque workflows     | Four payment kinds, one account per payment              | Clear receipts/payments/refunds; no cheque clearing initially |
| Sales/purchase returns                           | Source-linked partial returns                            | Quantity, tax and valuation reversals remain traceable        |
| Party ledgers plus account_ledgers               | One canonical journal with party dimensions              | Avoid duplicated balance truth                                |
| staging_totals/voucher_totals                    | Indexed direct journal sums                              | Avoid initial aggregate drift and rebuild logic               |
| Item stock, center stock, stock ledgers, batches | One stock location, pool balance and immutable movements | No overlapping stock systems                                  |
| Account matchers and per-party child accounts    | Seeded system chart and party dimension                  | Owner should not configure accounting dependencies            |
| BS calendars/fiscal years/date ranges            | Port tested calendar-only helpers                        | Preserve Nepal business dates without dairy sessions          |
| Code-based permission/menu system                | Four tenant roles and policies                           | Clear small-business permissions                              |
| Company setup/settings/env feature flags         | Business row/settings per tenant                         | Never use global env for business identity                    |
| Milk collection, fat/SNF, half-month sessions    | Excluded                                                 | Dairy-specific                                                |
| Cooperative deposits/loans/shares/interest/funds | Excluded                                                 | Different regulated financial domain                          |
| Manufacturing/conversion/batch production        | Excluded                                                 | Not required for small trading MVP                            |
| HR/payroll/salary/employee challans              | Excluded                                                 | Separate future product requirement                           |
| Livestock/feeding/breeding/health                | Excluded                                                 | Unrelated                                                     |
| Restaurant KOT/tables/kitchen events             | Excluded                                                 | Special-purpose POS                                           |
| Distribution/challans/tankers/commission         | Excluded                                                 | Adds operational workflow complexity                          |
| Rent/fixed-asset/depreciation modules            | Expense categories only                                  | Full asset schedules deferred                                 |
| Offline milk/desktop sync/mobile APIs            | Online responsive app                                    | Avoid sync and duplicate financial posting                    |
| IRD queue/credit-note/printing                   | Separate compliance milestone                            | Must verify current law and approved operating process        |
| Backup/log/report infrastructure                 | Native deployment backup, audit and read-only reports    | Preserve safety with fewer moving parts                       |

## Current source paths checked

Paths below are relative to the dairy workspace, not planned new-app files.

- `AGENTS.md`, `.github/copilot-instructions.md`, relevant accounting, purchases, dates, models,
  roles, Blade and IRD instructions, `.github/flows/feature-change.flow.md`: constraints and
  business conventions.
- `composer.json`, `package.json`: framework/dependency/build baseline.
- `routes/web.php`, route-file inventory: domain coverage and separate legacy workflows. External
  competitor URLs are websites, not routes in this repository; no dairy route behavior was inferred
  from those URLs.
- `app/Http/Controllers/Billing/BillingController.php:85`: original-document closed-date check,
  stock restoration, payment/party references and IRD cancellation branch.
- `app/Http/Controllers/Billing/BillingController.php:492`: sale creation and stock/ledger/payment
  coordination. This path assigns supplied gross/net/due values; new app must calculate trusted
  totals server-side.
- `app/Http/Controllers/Admin/SupplierController.php:352` and `:854`: purchase creation and deletion
  responsibilities.
- `app/Http/Controllers/Admin/SupplierBillController.php:58`: edits affect payment, quantity
  conversion, item stock and batches. New app keeps posted documents immutable.
- `app/Http/Controllers/AdminPurchaseReturnController.php:121` and
  `app/Http/Controllers/Admin/SalesReturnController.php:154`: linked returns and financial/stock
  reversal requirements.
- `app/PaymentManager.php`: payment account updates and cleanup links. New app uses explicit stored
  payment records and journal sources.
- `app/LedgerManage.php`: identifier registry and party-ledger abstraction. Preserve this source;
  new app needs no legacy numeric identifier registry.
- `app/custom.php:511`: `maintainStock()` coordinates item and optional center balances. New app
  uses one inventory balance per item per tenant.
- `app/custom_acounting.php:336`: GL posting helper and related deletion/update helpers. New app
  validates a full balanced journal before writing any lines.
- `app/Helpers/SalesHelper.php`: many sales settings/account mappings; new app deliberately offers a
  fixed smaller workflow.
- `app/Service/SalesDayService.php`: strict BS normalization, locks and closed-period checks. New
  app uses a simpler close-through date.
- `app/NepaliDate.php`, `app/NepaliDateHelper.php`: candidate calendar data/conversion source. These
  need isolated PHP 8.3+ adaptation, license review and fixtures before porting; imports and all
  runtime dependencies must be inspected first.
- `app/Http/Controllers/AccountingController.php`: historical aggregate/report patterns.
- `reports/shrawan-2083-voucher-trial-pl-check.md`: stale aggregate and fiscal closing/rounding
  discrepancy evidence.
- `reports/opening-balance-check-shrawan-2083.html`: historical/member-status filtering can hide
  balances; archived parties must remain in historical reports.
- `.superpowers/sdd/2026-09-15-sales-purchase-integrity/task-1-report.md`: permissions on POST,
  original date protection and atomic state-change regressions.

## Lessons carried into specification

1. Compute money once on the server, with one rounding contract; never trust hidden form totals.
2. Save document, journal, stock and immediate payment in one transaction.
3. Lock tenant financial writes and sequence numbering; retries must not duplicate records.
4. Never delete historical financial effects. Post linked reversal and preserve source/audit.
5. Closed original period cannot be bypassed by selecting today's date.
6. Keep reports read-only; opening/report viewing must never save P&L or ledger state.
7. Use journal as financial truth; no trigger-maintained report totals initially.
8. Snapshot contact/item/tax details, retain archived masters in history.
9. Every raw query, download, lookup and queued job must explicitly carry tenant ownership.
10. New app uses Laravel 13 conventions; legacy spelling and route preservation rules still protect
    the untouched dairy source.

Owner's follow-up requires daily business forms instead of DR/CR entry. Specification1.1 fixes those
forms in section5.3, removes generic manual-journal interfaces/routes, and maps starting
balances/owner money server-side. Existing dairy chart/journal screens are technical reference only,
not a UI pattern to copy. Automatic double-entry accounting and reversal rules remain internal.

## Competitor evidence, checked 2026-10-02

Karobar publicly describes sales, purchases, expenses, party ledgers, inventory, reports,
mobile/desktop use, staff access, bill images and reminders. Its accounting feature page also
discusses invoice sharing, receivables/payables and financial reports. These observations inform
user jobs; this plan does not assert how Karobar stores data or implements tenancy. Sources:
[Karobar homepage](https://www.karobarapp.com/en-us),
[accounting and inventory features](https://www.karobarapp.com/en-us/accounting-and-inventory-app).

Dhadda publicly describes products, units, stock movements, sales, purchases, payments, expenses,
customer/supplier balances, staff controls and reports for Nepal shops and SMEs. The accessible page
was a “Preparing your experience” public content shell; no authenticated product walkthrough was
available. Screen flows in the specification are proposed original designs. Source:
[Dhadda homepage](https://dhadda.app/).

No competitor accounts created, paid access used, authenticated actions performed, branding copied
or screenshots represented as tested product behavior. Prices and marketing claims are not
implementation requirements.

## Framework and regulatory sources

- [Laravel 13 release notes](https://laravel.com/framework/docs/13.x/releases): released 2026-03-17;
  PHP 8.3 minimum; compatible supported PHP range documented as 8.3–8.5.
- [Fortify](https://laravel.com/framework/docs/13.x/fortify): first-party authentication backend,
  usable with custom Blade views.
- [Database transactions](https://laravel.com/framework/docs/13.x/database),
  [query builder and locking](https://laravel.com/framework/docs/13.x/queries),
  [policies](https://laravel.com/framework/docs/13.x/authorization): framework mechanisms chosen for
  atomicity and access control.
- [IRD electronic billing procedure page](https://ird.gov.np/content/5490/tax-laws-16910453028/),
  [VAT Act index](https://ird.gov.np/category/valueaddedtaxact/?page=1),
  [IRD current notices](https://ird.gov.np/): authoritative starting points for compliance review.
  Page access does not establish that all current 2083 amendments or annexes have been reviewed. No
  compliance certification, mandated threshold or tax treatment is asserted by this pack.

Illustrative VAT examples use 13%, explicitly a calculation fixture. Registration eligibility,
input-VAT recoverability, statutory formatting, credit-note process, retention and CBMS requirements
must be confirmed for the release/business before regulated invoicing is enabled.

## Checks and remaining limits

Checked source inventory, graph freshness, core financial paths, source instructions, competitor
public pages and current Laravel documentation. Default CLI is PHP 8.0.6; owner explicitly selected
`php84` and `composer84`. Verified PHP 8.4.2 at `C:\php84\php84.exe`, Composer 2.8.4 at
`C:\php84\composer84.bat` using that PHP 8.4.2. `newapp` directory was empty when inspected. No code
executed against business database; no existing application tests/browser session were run because
deliverable is documentation.

No line-by-line security audit of entire dairy repo, production database validation, live competitor
UX test, PHP upgrade, or legal approval was completed. New product remains a proposal. Launch
requirements and exact expected financial tests are documented separately.

## Web implementation choices from competitor analysis

Public sources above were revisited during implementation on2026-10-02. This compares advertised
jobs, not authenticated competitor UX or internal security.

| User job                       | Public competitor evidence                                          | Business Book implementation                                                                                    |
| ------------------------------ | ------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------- |
| Record daily business          | Karobar and Dhadda describe sale/purchase/payment/expense recording | Dedicated daily-action forms; new sale one clear dashboard action                                               |
| Know who owes whom             | Both advertise party ledgers and due tracking                       | Separate customer/supplier channels, bill allocation preview, statements, credit/refund and aging views         |
| Know stock position            | Both describe inventory and stock movements                         | Available quantity and low-stock hints; count difference with reason; purchases/returns drive stock             |
| Use phone and larger screen    | Karobar advertises mobile and desktop access                        | One Ionic React interface: desktop sidebar, mobile bottom navigation, web/PWA first; native builds later        |
| Work with staff                | Both advertise staff access/control                                 | Four tenant roles; separate platform guard; membership checked before every financial read/write/cache/download |
| Keep bill evidence             | Karobar advertises bill images and sharing                          | Private JPG/PNG/PDF receipt attachments and printable bookkeeping bills                                         |
| Review performance             | Both advertise business reports                                     | Journal-backed P&L/balance sheet/trial, cashbook, stock, dues, reconciliation and safe CSV                      |
| Remind customers / communicate | Karobar advertises reminders and sharing                            | Payment reminders, external sharing and messaging were not implemented; require explicit later product scope    |

Design inference: strongest common job is record today's action and see remaining dues. Dashboard
therefore prioritizes New sale, Receive money, Pay supplier and Record expense; advanced
discount/tax/overdraft controls stay secondary. Guided starting balances remove accounting
terminology from setup. Reports and corrections remain accessible without exposing generic
journal-entry input.

Business Book's exact-money, tenant/cache isolation and reversal checks are verified properties of
this implementation. No claim is made that competitors lack those protections. No competitor
branding, paid accounts, screenshots, pricing comparison or authenticated UI was copied/tested.

Current implementation/evidence now lives in README.md and BUILD-PROGRESS.md. Earlier sections
describe the historical read-only dairy review and original proposal, not the current build status.
