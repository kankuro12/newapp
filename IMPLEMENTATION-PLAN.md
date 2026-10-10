# Simple mobile accounting implementation plan

> For implementing agents: execute one task at a time using `superpowers:executing-plans` if
> available. Do not spawn subagents unless owner explicitly authorizes delegation. User's
> no-Git-mutation rule overrides skill commit/worktree defaults. Checkboxes track future
> implementation, not planning completion.

**Goal:** Build standalone mobile accounting for Nepal small businesses, with safe multi-tenancy and
automatic financial/stock posting.

**Architecture:** Laravel 13 JSON backend + Ionic React frontend monorepo, shared MySQL tables with
tenant constraints and five concrete feature services. Journal is financial truth; one inventory
pool per stock item; all financial effects transactional.

**Tech stack:** PHP 8.4 through `php84`, with native BCMath enabled; Composer through `composer84`;
Laravel 13; MySQL 8.4 InnoDB; headless Fortify + Sanctum cookies; Ionic React +
TypeScript/Vite/BigInt; PHPUnit and existing Vitest.

**Owner-selected revision:** Read [MONOREPO-CONTRACT.md](MONOREPO-CONTRACT.md) first. Backend
commands and PHP paths below are relative to `newapp/backend`. UI view/module paths translate to
Ionic React in `newapp/frontend/src`. API paths add `/api` to proposed business paths; client routes
remain separate. Existing scaffold must be preserved, not recreated. Business task completion is
still pending.

**Spec:** `E:\laravel pojects\dairy main\newapp\APP-SPECIFICATION.md`. Exact numerical checks:
`ACCEPTANCE-TESTS.md` in same folder.

## Global constraints

- Target root only `E:\laravel pojects\dairy main\newapp`; inspect existing files before
  scaffolding.
- Do not change dairy source/config/database. Do not share sessions, APP_KEY, storage, vendor or
  database with dairy.
- Every PHP/Composer command explicitly uses `php84`/`composer84`. Never downgrade framework or
  default to PHP8.0.
- No stage/commit/push/reset/branch creation/switch/history rewrite/.gitignore edits without
  explicit user instruction. Inspect scaffold .gitignore but do not change it.
- One concrete service per feature under `app/Service`; no interfaces/repositories/generic event
  bus.
- Business dates BS YYYYMMDD; no Carbon for business date arithmetic. UTC for operational timestamps
  only.
- Paisa integers, qty thousandths, taxes basis points; no money floats.
- All financial writes use spec5.3 dedicated daily-action forms. No role gets DR/CR inputs,
  account-side selectors or generic manual-journal entry; internal journals remain automatic.
- Tenant isolation applies to all web/query/job/file/report paths and composite FKs.
- Posted journal/movements immutable; cancellations linked reversals; draft deletion separate.
- One tenant-row lock per financial mutation; same lock for revocation/close-through.
- No future features from excluded-scope list and no regulated invoice claim.

## Review focus — mandatory failure classes

1. Two browser tabs in different businesses: submitted form remains bound to its original tenant
   (Task02).
2. Response lost after commit: same UUID returns same record; changed payload conflicts
   (Tasks04/07).
3. Partial returns and VAT/cost rounding: cumulative final partial equals original paisa (Task10).
4. Historic report after current cancellation/archive: old as-of balances remain unchanged
   (Tasks11/12).
5. Concurrent closing/staff revocation versus posting: serial tenant lock enforces final state
   (Tasks02/11/16).

## Agent execution discipline

For each task read referenced spec sections and acceptance IDs; implement only specified scope.
Write named financial/security test first, run it and verify meaningful failure; add smallest
implementation; rerun and capture pass. Tests may use one file per financial feature with a few
meaningful scenarios rather than boilerplate per-method suites. Runtime/setup/UI tasks need relevant
smoke/build/browser verification, not trivial mirrored tests.

Record task status, changed files, actual commands/results, next task and unresolved limitations in
`newapp/BUILD-PROGRESS.md`. It is an implementation log, not a new planning framework. Never mark
checkbox completed with missing files or skipped checks. Stop on failed dependency contracts; do not
compensate with alternate table/route names.

PHP/Composer commands below run from `newapp/backend`; root workspace npm commands run from
`newapp`. MySQL test DB must be independently created and named `business_book_testing`; never use
dairy DB or developer app DB for RefreshDatabase, migrate:fresh, or concurrent tests. PHPUnit config
must validate test database before destructive tests. Run installed scaffold's exact PHPUnit command
with `php84 vendor/bin/phpunit`; do not run default interpreter through Composer scripts
unintentionally.

## Shared file and interface map

Only create files when current task needs them. Migrations use ordered filenames of actual
generation; table/column contracts are fixed by specification section7.

```text
app/
  Enums/DocumentType.php, DocumentStatus.php, PaymentKind.php, TenantRole.php
  Support/CurrentTenant.php, Money.php
  NepaliDate.php, NepaliDateHelper.php
  Models/Concerns/BelongsToTenant.php
  Models/{User,Tenant,Contact,Item,Account,Document,DocumentLine,Payment,...}.php
  Http/Middleware/ResolveTenant.php, EnsureTenantWritable.php
  Http/Controllers/{Tenant,Contact,Item,Lookup,Dashboard,Document,Return,
    Payment,Transfer,StockAdjustment,Report,Print,Attachment,Settings,
    Staff,Invitation,OpeningBalance,Account,ExpenseCategory,Accounting,
    Audit,PlatformTenant}Controller.php
  Http/Requests/{StoreDocument,UpdateDraft,PostDocument,StorePayment,
    CancelSource,StoreStockAdjustment,StoreContact,StoreItem,
    StoreOpeningBalances,StoreOwnerMoney,StoreInvitation,ReportFilter}Request.php
  Policies/{Tenant,Contact,Item,Document,Payment,Account,StockAdjustment,
    Report,Attachment}Policy.php
  Service/{Tenant,Document,Payment,Accounting,Inventory}Service.php
resources/
  views/layouts/app.blade.php, auth/*.blade.php, businesses/*.blade.php
  views/components/{form-field,money-input,bs-date-input,empty-state,
    status-badge,lookup,bottom-nav}.blade.php
  views/{contacts,items,documents,payments,returns,expenses,stock,
    reports,print,settings,platform,audit}/*.blade.php
  js/app.js, document-form.js, lookup.js
  css/app.css, print.css
lang/en/app.php, lang/ne/app.php
routes/web.php
tests/Unit/MoneyTest.php, NepaliDateTest.php
tests/Feature/{Authentication,TenantIsolation,TenantRole,OpeningBalance,
  Inventory,PurchasePosting,SalePosting,PaymentAllocation,ReturnPosting,
  Cancellation,PeriodLock,Reports,Attachment,PlatformAccess}Test.php
tests/Integration/MySqlConcurrencyTest.php
```

Names in braces indicate concrete multiple files. Do not introduce controllers/views unrelated to
existing features. Generic field/status components are only reused actual UI controls; no
schema-generated form engine.

Value arrays, documented with PHPDoc rather than DTO classes:

```php
// JournalLineInput: account_id:int, contact_id:?int,
// debit_paisa:int, credit_paisa:int, memo:?string.
// SourceRef: type:string (allowlist), id:int, event:'post'|'reverse'.
// Mutation input always has validated UUID + canonical normalized payload.
// DocumentInput: type, contact_id, business_date_bs, due_date_bs?,
// lines[{item_id?|expense_category_id?, qty:string, unit_price:string,
// line_discount_amount?|line_discount_bps?, tax_category, tax_bps}],
// invoice_discount_amount?|invoice_discount_bps?, vat_recoverable,
// supplier_bill_number?, supplier_bill_date_bs?, notes?.
// PostingInput: expected_version:int, expected_total_paisa:int,
// paid_now:string, money_account_id?:int, tendered?:string.
// PaymentInput: kind, contact_id?, money_account_id,
// destination_account_id?, amount:string, business_date_bs, reference?,
// allocations[{document_id:int, amount:string}], allow_unallocated:bool.
// OwnerMoneyInput: kind:'contribution'|'withdrawal', amount:string,
// money_account_id:int, business_date_bs, notes?. No journal/account sides.
// OpeningInput: date_bs, cash_bank[{money_account_id,amount:string}],
// stock[{item_id,qty:string,value:string}],
// parties[{contact_id,channel:'customer'|'supplier',
// direction:'party_owes_business'|'business_owes_party',amount:string}].
// Opening journal sides/account mappings generated internally only.
```

Quantities/money enter as strings; service calculates integer normalized arrays. Extra client
total/stock/tenant/account-side fields ignored or rejected. Do not use arrays as excuse for
ambiguous units.

### Service interfaces established by tasks

```php
// CurrentTenant (Task02)
set(Tenant $tenant): void;
id(): int;
clear(): void;

// Money / calendar (Task03)
Money::parse(string $value): int;
Money::format(int $paisa): string;
Money::multiplyDivide(int $a, int $b, int $denominator): int;
NepaliDate::normalize(string|int $value): int;
NepaliDate::today(): int;
NepaliDate::monthRange(int $dateBs): array; // [startBs,endBs]
NepaliDate::fiscalYearRange(int $dateBs): array;
NepaliDate::fiscalYearLabel(int $dateBs): string;
NepaliDate::daysBetween(int $fromBs, int $toBs): int;

// TenantService (Tasks02/15)
create(int $actorId, array $businessInput): Tenant;
invite(int $tenantId, int $actorId, array $input): Invitation;
acceptInvitation(int $actorId, string $token): Tenant;
updateMembership(int $tenantId, int $actorId, int $userId, array $input): void;
updateAccess(int $tenantId, int $platformActorId, array $input): void;

// AccountingService (Tasks04/05/11)
seedChart(int $tenantId): void;
lockTenant(int $tenantId, int $actorId): Tenant; // transaction required
assertOpenDate(Tenant $tenant, int $dateBs): void;
replayMutation(int $tenantId, int $actorId, string $uuid,
    string $operation, array $normalizedInput): ?array;
recordMutation(int $tenantId, int $actorId, string $uuid,
    string $operation, array $normalizedInput, string $type, int $id): void;
post(int $tenantId, int $actorId, int $dateBs, array $source,
    array $lines, string $description): JournalEntry;
reverse(int $tenantId, int $actorId, int $journalId,
    int $dateBs, string $reason): JournalEntry;
finalizeOpenings(int $tenantId, int $actorId, string $uuid): void;
postOwnerEntry(int $tenantId, int $actorId, array $input, string $uuid): JournalEntry;
cancelOwnerEntry(int $tenantId, int $actorId, int $journalId,
    int $dateBs, string $reason, string $uuid): JournalEntry;
closeThrough(int $tenantId, int $actorId, int $dateBs, string $reason): void;

// InventoryService (Task06)
outflowCost(int $poolQtyMilli, int $poolValuePaisa, int $qtyMilli): int;
receive(int $tenantId, int $actorId, int $itemId, int $dateBs,
    int $qtyMilli, int $valuePaisa, array $source): StockMovement;
issue(int $tenantId, int $actorId, int $itemId, int $dateBs,
    int $qtyMilli, array $source): StockMovement;
reverse(int $tenantId, int $actorId, int $movementId,
    int $dateBs): StockMovement;
adjust(int $tenantId, int $actorId, array $input, string $uuid): StockAdjustment;
cancelAdjustment(int $tenantId, int $actorId, int $adjustmentId,
    int $dateBs, string $reason, string $uuid): StockAdjustment;

// DocumentService (Tasks07/08/10/11)
calculate(array $normalizedInput): array; // exact totals + normalized lines
saveDraft(int $tenantId, int $actorId, array $input, string $uuid): Document;
updateDraft(int $tenantId, int $actorId, int $documentId,
    int $expectedVersion, array $input, string $uuid): Document;
post(int $tenantId, int $actorId, int $documentId,
    array $postingInput, string $uuid): Document;
createAndPost(int $tenantId, int $actorId, array $input,
    array $postingInput, string $uuid): Document;
cloneDraft(int $tenantId, int $actorId, int $documentId, string $uuid): Document;
createReturn(int $tenantId, int $actorId, int $sourceDocumentId,
    array $returnInput, string $uuid): Document;
cancel(int $tenantId, int $actorId, int $documentId,
    int $dateBs, string $reason, string $uuid): Document;
deleteDraft(int $tenantId, int $actorId, int $documentId): void;

// PaymentService (Task09)
post(int $tenantId, int $actorId, array $input, string $uuid): Payment;
cancel(int $tenantId, int $actorId, int $paymentId,
    int $dateBs, string $reason, string $uuid): Payment;
invoiceBalance(int $tenantId, int $documentId, ?int $asOfBs=null): array;
// returns signed_due_paisa, due_paisa, credit_paisa, net_settled_paisa.
```

Accounting `post`/inventory `receive`/`issue` participate in existing transaction; source high-level
command establishes transaction and lock. Low-level `lockTenant` must not insist opening setup
already finalized, because opening/membership/close commands need it; source posting explicitly
checks finalized/open dates after lock. No cyclic service constructor dependency: InventoryService's
receive/issue/reverse never creates GL; document caller assembles journal. InventoryService's
high-level adjust/finalize opening commands can call AccountingService through method
injection/container when needed, without AccountingService constructor depending on
InventoryService. Shared primitives do not dispatch other feature controllers.

For opening stock, AccountingService finalization calls inventory receive inside transaction;
InventoryService primitive must not call AccountingService again. `source` for movements contains
exactly one document_line_id/stock_adjustment_id/opening_balance_id. Owner money sources use their
own header ID assigned before completing insert, with owner_entry_kind contribution/withdrawal;
nullable source_id during in-transaction header creation, filled before commit. Never commit
source_id null for posted journal. Low-level journal post/reverse interfaces are internal, never
HTTP journal-input endpoints.

## Task01 — Runtime, clean Laravel13 and authentication

**Read:** spec1–3,4.3,15; acceptance A01.

**Files:** Laravel scaffold `composer.json`, `bootstrap/app.php`, `routes/web.php`,
`config/{app,database,session,fortify}.php`, `.env.example`;
`app/Providers/FortifyServiceProvider.php`; published Fortify actions;
`resources/views/auth/*.blade.php`; `tests/Feature/AuthenticationTest.php`; `BUILD-PROGRESS.md`.

**Consumes:** empty/owner-approved target directory and existing php84/composer84. **Produces:**
Laravel13 booting, verified user login, independent MySQL dev/test connections.

- [ ] Check `php84 -v`, `composer84 --version`, `php84 -m`, Node version; verify PHP8.4, bcmath,
      pdo_mysql, mbstring, openssl, intl if formatting uses it and 64-bit integer size. Declare
      `ext-bcmath` in composer.json. Document actual paths; do not upgrade runtime without need.
- [ ] Inspect existing `newapp/backend` and `newapp/frontend`; preserve scaffold and lockfiles. Do
      not rerun clone/create-project over them. Use only approved target/database.
- [ ] Install Fortify and Sanctum via `composer84`; configure headless Fortify and stateful cookie
      auth per MONOREPO-CONTRACT. Enable registration/reset/verification, Ionic auth pages, CSRF and
      JSON failures. No additional frontend starter kit or Livewire.
- [ ] Configure DB/session/cache/queue drivers and safe testing DB; add new env keys/examples. Set
      timezone display contract and app locale; APP_KEY unique.
- [ ] Write auth checks for verified-email gate, registration admin-field rejection, password reset
      and login/logout session behavior. Build mobile auth views with errors.
- [ ] Verify `composer84 check-platform-reqs`, `php84 artisan about`,
      `php84 artisan route:list --path=login`,
      `php84 vendor/bin/phpunit --filter AuthenticationTest`, `npm run build`. Expected
      Laravel13.x/PHP8.4.x; auth tests/build pass.

## Task02 — Tenant context, memberships, invitations and access policies

**Read:** spec4/7/17; acceptance T01–T10, C05–C06.

**Files:** tenant/membership/invitation migrations; `Models/{Tenant,Invitation}.php`, update User;
CurrentTenant, BelongsToTenant; ResolveTenant, EnsureTenantWritable; TenantRole enum; TenantService;
TenantController/StaffController/InvitationController; TenantPolicy and fixed permissions map
`config/permissions.php`; businesses/settings staff views; `TenantIsolationTest.php`,
`TenantRoleTest.php`; routes.

**Consumes:** authenticated verified global User. **Produces:** CurrentTenant interface, tenant
creation/switch/membership and writable-access gate. TenantService create chart callback is
connected in Task04; no financial feature exposed before then.

- [ ] Write two-tenant fixtures and route checks: foreign ID/slug404, unverified redirect, revoked
      staff denied with existing session, session switch not changing tab's URL context.
- [ ] Implement shared identity +unique memberships, request-scoped binding and fail-closed trait.
      Stamp tenant_id server-side and prohibit reassignment.
- [ ] Implement fixed role policy checks and access-state map, including cashier data omission.
      Resolve tenant for web; explicit context for CLI/job in try/finally.
- [ ] Implement invitation hash/expiry/verified-email acceptance/resend/revoke; tenant lock around
      acceptance/role changes; last-owner guard. Email only after commit, failure resendable.
- [ ] Verify `php84 vendor/bin/phpunit --filter 'TenantIsolationTest|TenantRoleTest'`. Confirm
      permissions on POST/PATCH/DELETE, not just GET. Concurrency last-owner/revocation checks added
      in Task16.

## Task03 — Exact money, quantity, totals and BS calendar

**Read:** spec6/10.2; acceptance M01–M08, D01–D06.

**Files:** Support/Money.php; NepaliDate.php/NepaliDateHelper.php calendar-only adaptation;
document-form value parser if needed; `MoneyTest.php`, `NepaliDateTest.php`; DocumentService
calculate method introduced.

**Consumes:** pure string/int inputs, no database. **Produces:** money/date APIs and calculate
normalized totals contract used by every feature.

- [ ] Write literal expected assertions: parse123.45=12345, parse Nepali digits, invalid
      comma/exponent reject; 0.5 tie rounds away; overflows/caps reject.
- [ ] Implement quantity integer-thousandth parsing and rate/basis-point bounds. BCMath exact
      products/quotients/remainders avoid intermediate overflow; final BIGINT limits checked.
      Largest-remainder invoice discount ties by line position; verify M04/M05 exactly. Match JS
      BigInt preview rounding, sending bounded digit-string expected totals.
- [ ] Inspect calendar helper runtime dependencies and rights; port only conversion/month-day
      tables, not salary/milk/session helpers. Add normalization/today/ranges/day-difference API.
- [ ] Validate BS format and actual month lengths2000–2090; verify conversion fixture provenance and
      fiscal boundary20830401. Normalize Nepali digits without liberal date parsing.
- [ ] Run `php84 vendor/bin/phpunit --filter 'MoneyTest|NepaliDateTest'`. Expected all pure tests
      pass without booting financial DB. Record authoritative fixture sources; calendar-range
      support cannot be claimed from a copied array alone.

## Task04 — Remaining schema, composite tenant constraints and chart

**Read:** spec7/8.1–8.2/9; acceptance G01–G05, T03.

**Files:** all remaining schema migrations in section7, final composite-FK migration; models/enums
for current tables; AccountingService seedChart/lock/mutation/post/reverse skeleton; system seeder;
`tests/Feature/JournalIntegrityTest.php` (add to shared file map); MySQL test configuration.

**Consumes:** Tenant/User, money/date helpers and explicit tenant context. **Produces:** real MySQL
tables, seeded tenant chart/default Cash/Bank/categories/Walk-in/expense payee and canonical journal
APIs.

- [ ] Create financial/master schemas in dependency order; attach circular journal-source references
      after tables exist. Add indexes/CHECKs/composite ownership FKs. No cascade from masters to
      finance.
- [ ] Test raw cross-tenant contact/item/account assignment fails at DB FK even without model/policy
      guard. Use MySQL actual constraints.
- [ ] Implement seeded chart exactly once inside tenant creation transaction, immutable system keys
      and archived-history behavior.
- [ ] Implement whole-entry balance/ownership/line validation, source uniqueness and linked
      reversal; journal writes only through AccountingService. Add source self-ID/kind handling for
      owner money. No generic manual-journal source/method/route.
- [ ] Implement transaction-required lockTenant and mutation replay/record; normalized payload hash
      and UUID conflicts. Audit rows inside same transaction; no secrets.
- [ ] Verify fresh migrations on `business_book_testing`,
      `php84 vendor/bin/phpunit --filter JournalIntegrityTest`, `php84 artisan migrate:status`.
      Intentionally unbalanced line set must write zero headers/lines.

## Task05 — Contacts, items, accounts, categories and opening setup

**Read:** spec5/8.4/12.4; acceptance O01–O05, T04.

**Files:**
ContactController/ItemController/AccountController/ExpenseCategoryController/OpeningBalanceController;
corresponding Form Requests/policies; contacts/items/settings views; AccountingService
finalizeOpenings; `OpeningBalanceTest.php` and `MasterDataTest.php`; route entries.

**Consumes:** scoped models/chart/value helpers, InventoryService receive primitive connected after
Task06. **Produces:** minimal onboarding masters and immutable opening set; all-zero setup usable.
Task05 can implement UI/validation before stock primitive, but mark stock-opening check pending
until Task06 completes; do not expose posting yet.

- [ ] Implement contact flags/quick-add and item kinds/units/SKU/search/archive restrictions;
      masters have no editable balance/stock fields. Cashier response strips costs/dues.
- [ ] Owner adds cash/bank name and expense-category label only; server generates account
      code/category mapping. No chart-side selector or arbitrary account form; protect system
      fields/accounts and historical FKs.
- [ ] Implement four-step starting-balance form (cash/bank, stock, party dues, review) with positive
      amounts and who-owes-whom selection. Map to opening rows/journal internally; no
      debit/credit/account-code/equity inputs. Show computed business value and explicit zero-setup
      option. Finalization transaction balances Opening Equity internally.
- [ ] Enforce no ordinary posting until opening finalized, ordinary dates>=opening, one immutable
      opening set; all-zero setup no zero journal. Link zero-cost stock rows deliberately.
- [ ] Verify `php84 vendor/bin/phpunit --filter 'OpeningBalanceTest|MasterDataTest'`; stock-opening
      numerical checks complete immediately after Task06. Browser verify business->item->opening
      setup usable at390px.

## Task06 — Inventory primitives and adjustment documents

**Read:** spec10/12.2; acceptance I01–I07, O02.

**Files:** InventoryService;
StockAdjustmentController/StoreStockAdjustmentRequest/StockAdjustmentPolicy; stock views;
`InventoryTest.php`; attach Task05 stock finalization.

**Consumes:** locked tenant, existing document-line/adjustment/opening source, journal API,
quantity/money rules. **Produces:** receive/issue/reverse/outflowCost/adjust interfaces; one trusted
inventory pool.

- [ ] Write I01 moving-average purchase/outflow and exact final drain; negative stock and zero-cost
      stock cases. Assert quantity/value and GL value match.
- [ ] Implement receive/issue with sorted locked pool rows, same-date ID chronology and
      last-stock-date guard; uniqueness of source event. Primitive updates pool/movement only, no
      duplicate GL.
- [ ] Implement high-level stock adjustment using own source row +journal; reason and stale count
      confirmation. Build before/after screen.
- [ ] Wire opening stock receive and finish Task05; zero-value movements valid, no fabricated
      journal.
- [ ] Run `php84 vendor/bin/phpunit --filter 'InventoryTest|OpeningBalanceTest'`; prepare MySQL
      two-sale race in Task16, never infer lock behavior from SQLite.

## Task07 — Purchase and expense posting

**Read:** spec6–9/10.1/17; acceptance F01, F03, F04, G04, C01–C03.

**Files:** DocumentService purchase/expense/draft paths;
DocumentController/StoreDocumentRequest/UpdateDraftRequest/PostDocumentRequest/DocumentPolicy;
document and expense views; document-form.js; `PurchasePostingTest.php`.

**Consumes:** money/calendar/chart, tenant lock, inventory receive; PaymentService post contract
wired Task09. Initially verify unpaid purchase; paid path must remain disabled until Task09
integration verified. **Produces:** trusted
totals/snapshots/sequence/draft/version/post/createAndPost API for purchase/expense; common form
shell reusable for sales.

- [ ] Test unpaid10x100 purchase, recoverable/nonrecoverable tax, service line and duplicate
      supplier reference. Verify stock, AP, GL and snapshots.
- [ ] Implement draft save/edit/delete, version checks, number allocation and createAndPost outer
      transaction. Ignore client line/document totals; match expected_total before write.
- [ ] Implement purchase item/service cost rules and expense category/AP posting. Pure paid expense
      picks protected expense-payee; credit expense requires named supplier.
- [ ] Force failure after stock or journal creation and assert all effects rolled back; same UUID
      same result, changed UUID payload409.
- [ ] Build mobile purchase/expense form and detail/list. Run
      `php84 vendor/bin/phpunit --filter PurchasePostingTest`, `npm run build`; paid checks finish
      Task09 before task considered complete.

## Task08 — Sales and cashier-friendly workflow

**Read:** spec5/8.3/9; acceptance F01–F02, M04, T04, U01–U04.

**Files:** DocumentService sale path; DashboardController; LookupController; sales/document view
variations; lookup.js/document-form.js; sale policy; `SalePostingTest.php`.

**Consumes:** posting/numbering/snapshots from07 and stock issue from06. **Produces:** sale
post/draft workflow with original COGS, service sales, safe lookup; cash payment connects Task09.

- [ ] Test3x150 sale from10x100 stock ->sales450,COGS300,stock700, AR450 before receipt. Service
      sale no stock/COGS.
- [ ] Implement named customer/Walk-in paths, stock item duplicate merging/rejection, <=100 lines,
      server taxes/discount and cost snapshot.
- [ ] Enforce cashier own-document boundaries and permitted receipt UI; no cost/margin/foreign
      balance data in lookup, page or JSON.
- [ ] Build bottom-nav home and sale UX, accessible picker, paid/tender/change preview, error
      preservation and same UUID retry. Paid Walk-in submission blocked until PaymentService
      integration available.
- [ ] Run `php84 vendor/bin/phpunit --filter SalePostingTest`, `npm run build`;390px walkthrough.
      Paid scenario/tender/change finish09, no partial MVP release.

## Task09 — Receipts/payments/refunds/transfers and allocations

**Read:** spec5.3/11/8.3/9; acceptance P01–P08, F01–F04, U09–U12.

**Files:** PaymentService; PaymentController/TransferController/StorePaymentRequest/PaymentPolicy;
payments views; DocumentService immediate payment integration;
AccountingController/StoreOwnerMoneyRequest and `resources/views/payments/owner-money.blade.php`
with separate add-money/personal-use forms; `PaymentAllocationTest.php` and `OwnerMoneyTest.php`.

**Consumes:** balanced journal, tenant locking/idempotency, source documents/party dimensions.
**Produces:** PaymentService API, exact invoiceBalance and dedicated daily money forms with
automatic owner postings;07/08 paid features fully enabled.

- [ ] Test partial receipt/payments, allocation caps/types/ownership, explicit unallocated
      advances/opening settlement and separate AR/AP channels.
- [ ] Implement four payment kinds+transfer with fixed account sides, active account validation,
      cash availability/bank overdraft confirmation and audits.
- [ ] Validate allocations after locks; refunds bounded by source signed credit AND overall party
      credit; cashier own invoice allocation only. No direct edit of posted allocation.
- [ ] Wire document paid_now into same outer transaction with deterministic child UUID derived from
      parent's UUID+payment event; child does not claim parent UUID. Parent mutation record owns
      full result. Verify failure in payment rolls back sale/purchase stock/number/journal.
- [ ] Implement tender/change record100 for tender150, no fictitious refund. Build Add my money/Take
      money for personal use forms with amount/method/date/note; fixed internal capital/drawings
      mapping, no journal lines or account sides accepted. Receive/Pay forms default suggested bills
      with optional Choose bills disclosure and explicit advance option.
- [ ] Run
      `php84 vendor/bin/phpunit --filter 'PaymentAllocationTest|OwnerMoneyTest|PurchasePostingTest|SalePostingTest'`.
      Expected F01 full cash/dues/profit reconciles; all daily forms omit DR/CR inputs.

## Task10 — Source-linked partial returns and refunds

**Read:** spec10.2/11/12; acceptance R01–R08, M06, C04.

**Files:** DocumentService createReturn; ReturnController; returns views; relevant request rules;
`ReturnPostingTest.php`.

**Consumes:** original posted invoice components/stock-cost snapshots, PaymentService refunds,
inventory primitives. **Produces:** sale_return/purchase_return with exact cumulative component
recovery and original invoice due updates by derivation.

- [ ] Test cumulative partial base/tax/COGS rounding; final remaining return exact; same-day
      concurrent return cap deferred to16.
- [ ] Implement source party/line/type/quantity validation and original snapshot pricing/tax, source
      archived item return allowed; no current master price substitution.
- [ ] Sale return restores original sale cost; purchase return removes current average and books
      price variance; service return reverses original expense/revenue only.
- [ ] Refund-now optional creates separate fixed-kind payment allocated original invoice atomically;
      unpaid/partpaid/fullypaid originals exercise credit rules. Closed original invoice return
      permitted on current open date.
- [ ] Run `php84 vendor/bin/phpunit --filter ReturnPostingTest`; verify R05 numerical purchase
      return variance and no-stock service branch.

## Task11 — Cancellations, date lock and financial audit

**Read:** spec9/12; acceptance X01–X09, D05, C05–C06.

**Files:** DocumentService cancel, PaymentService cancel, InventoryService cancelAdjustment;
CancelSourceRequest; AccountingController closeThrough; AuditController; confirmation/audit views;
`CancellationTest.php`, `PeriodLockTest.php`.

**Consumes:** complete source effects, journal/movement reversal, locked tenant, as-of metadata.
**Produces:** conservative safe reversal paths and monotonically increasing date lock.

- [ ] Test original date closed despite today-open request; related active payments/returns block
      document cancel; latest-only stock guard; duplicate cancel no second reversal.
- [ ] Implement exact copied journal side reversal, exact original stock delta reversal in reverse
      movement order, cancellation reason/actor/effective dates and immutable source. Add
      AccountingService cancelOwnerEntry for owner sources only; reject bypass cancellation of
      document/payment/opening/stock journals. Test money addition/personal-use reversal and
      cash/date safeguards; confirmations show daily action and amount, no journal-entry grid.
- [ ] Block nonlatest partial-return cancellation and refund dependencies. Money reversal validates
      balances; no clearing linked payment silently.
- [ ] Implement close-through valid date/recent-password/reconciliation/audit, all financial service
      date guards, no GET mutations or reopening bypass.
- [ ] Run `php84 vendor/bin/phpunit --filter 'CancellationTest|PeriodLockTest'`. Include historical
      report evidence in12; concurrency final16.

## Task12 — Read-only financial/business reports

**Read:** spec11.3/13; acceptance Q01–Q09, T05, X07.

**Files:** ReportController/ReportFilterRequest/ReportPolicy; DashboardController totals; reports
views; lightweight private query methods; `ReportsTest.php`.

**Consumes:** canonical journal and movements, invoiceBalance as-of. **Produces:** every report in
spec13 with one validated/filter/pagination contract.

- [ ] Write F01/F03 expected P&L/trial/balance sheet/stock/dues assertions; include archived sources
      and future cancellation effect on earlier cutoff.
- [ ] Implement journal-first accounting reports and historical movement-based inventory; explicit
      normal sides/contra sales and negative AR/AP reclassifications.
- [ ] Implement allocations/openings/unapplied reconciliation; prove no double-counting invoice
      discounts or perpetual purchase cost. Cash transfer not income/expense.
- [ ] Implement report allowlist, inclusive BS range/strict before-start opening, stable order and
      bounded sorts. Daily statements/cashbooks use Received/Paid/Balance and contextual
      due/advance/refund labels, not DR/CR. Trial-balance technical diagnostics are read-only
      accountant reports outside daily navigation. Capture DB writes during GET and assert zero
      business writes.
- [ ] Run `php84 vendor/bin/phpunit --filter ReportsTest`. Measure representative100,000-source
      dataset query plans on MySQL; record timings before considering cache.

## Task13 — Printing, CSV, attachments and business settings

**Read:** spec13–14; acceptance E01–E07, T06, U05.

**Files:** PrintController/AttachmentController/SettingsController; AttachmentPolicy; print
views/print.css; attachment upload/download; export paths in ReportController; business-settings
views; `AttachmentTest.php`, `ExportTest.php`.

**Consumes:** snapshots, authorized parent/source queries, private filesystem and report filters.
**Produces:** native print/save-as-PDF, safe streamed CSV, private auditable attachments and
immutable old print identity.

- [ ] Implement print from snapshots with bookkeeping label, draft/cancelled watermark; A4/80mm CSS,
      no server PDF library.
- [ ] CSV BOM/tax/category/date/currency headings and formula-injection prevention; consistent
      report policy/filter,20,000-row bound.
- [ ] MIME/size/count validation, random private tenant paths, authorized download; malicious
      file/foreign ID denied. File writes after durable parent; clean failed upload orphan safely.
- [ ] Draft delete cleans files after commit; posted/cancelled keep attachments. Business logo
      validation same raster-only safety; persisted print snapshots freeze old logo identity/path if
      used.
- [ ] Run `php84 vendor/bin/phpunit --filter 'AttachmentTest|ExportTest'`; render actual prints in
      browser and inspect A4/80mm outputs; no blank Nepali glyphs/overflow.

## Task14 — Mobile polish, English/Nepali and install manifest

**Read:** spec5/14; acceptance U01–U08.

**Files:** layouts/components/screen translations, lang/en/app.php/lang/ne/app.php, app.css/app.js,
public manifest/icons generated from simple original branding (no external asset dependency).

**Consumes:** working first-release screens/forms and exact server behavior. **Produces:**
consistent390px-first UX with accessible inputs and original mobile layout.

- [ ] Fill missing English/Nepali real keys; money/date format helpers and visible role-sensitive
      error text. Nepali number input normalized server-side. Implement section5.3's action
      sheet/dedicated forms and U09–U12 checks: daily flow never enters debit/credit or arbitrary
      account codes for any role.
- [ ] Verify sticky form action/safe area, bottom navigation, modal/combobox keyboard/focus, labels,
      contrast and >=44px targets at360/390/430/768/1280.
- [ ] Preserve in-memory form on422/network error, replay same UUID after ambiguous save, stale409
      displays current draft. Business switch warns on unsaved input.
- [ ] Add manifest/icons and honest online-required banner; no service worker or persistent
      financial cache.
- [ ] Run `npm run build`; browser verify sale->receipt->purchase->return->report in both languages.
      Record browser/version/viewports and timing; unsupported Safari testing listed as unverified.

## Task15 — Platform access and production operations

**Read:** spec4.3/15; acceptance S01–S07.

**Files:** PlatformTenantController/platform views; TenantService updateAccess;
`app/Console/Commands/CreatePlatformAdmin.php`; operational `DEPLOYMENT.md`; backup/operator scripts
only necessary to selected hosting; `PlatformAccessTest.php`; queue tenant-context check.

**Consumes:** roles/access-state middleware and audit; production host details when available.
**Produces:** safe manual SaaS access administration, documented worker/scheduler/backup/restore
process.

- [ ] Implement platform tenant/access list/update with reason and recent-password confirmation; no
      business ledger bypass or impersonation.
- [ ] Provision administrator command prompts secret privately, hashes using Laravel; no seed
      default credentials. Verify malicious registration cannot set admin flag.
- [ ] Test expired tenant read/export but write403; suspended data denied; profile/logout/recovery
      available; renewal reactivates after audited timestamp change.
- [ ] Configure after-commit invitations/database queues with tenant/actor context and cleanup;
      cancelled/expired invitation cannot accept through delayed job.
- [ ] Document separate DB/APP_KEY/private storage, maintained PHP8.4 path, HTTPS/public root,
      scheduler/worker restart, backup encryption/retention/failure monitoring and isolated restore
      drill. Deployment itself requires separate owner instruction.
- [ ] Run `php84 vendor/bin/phpunit --filter PlatformAccessTest`; run restore/health/queue checks
      only isolated authorized environment. Missing hosting credentials block operations
      verification, not local feature development.

## Task16 — Integration, MySQL races and release handoff

**Read:** all launch gates, ACCEPTANCE-TESTS.md.

**Files:** `tests/Integration/MySqlConcurrencyTest.php`; complete BUILD-PROGRESS.md/DEPLOYMENT.md;
fix only defects revealed in current app files; no separate test framework/browser package unless
existing harness requires it.

**Consumes:** complete implementation and dedicated MySQL testing DB. **Produces:** verified first
release, exact limitations and owner-reviewable app; no deployment/Git operation assumed.

- [ ] Use two independent PHP processes/connections against MySQL InnoDB to test same item
      last-stock sale, duplicate UUID, number allocation, return cap, last-owner demotion and
      close/revocation versus post. A sequential request test is not concurrency evidence.
- [ ] Inject failure at header/line/journal/stock/payment/allocation/audit stages; prove rollback
      and retry source counts. Reconcile all control balances/stock after stress scenario.
- [ ] Run `php84 vendor/bin/phpunit`, `composer84 check-platform-reqs`, `npm run build`,
      `php84 artisan route:list -v`; inspect all routes for policies/middleware and immutable
      delete/cancel separation.
- [ ] Verify fresh install and migrations in separate empty MySQL DB; never destructive commands in
      live/dev dairy database. Exercise archived/historical reports, private files, CSV injection,
      both languages, device viewports and print.
- [ ] Record each acceptance ID as automated/manual/not-run with evidence. Untested regulatory mode
      remains disabled. Missing Safari, hosting restore or operator monitoring evidence is
      disclosed, not marked pass.
- [ ] Hand off changed files, completed scope, screenshots/URLs if available, actual check results,
      risks and missing operational/compliance work. Do not stage/commit/deploy. Ask
      execution/deployment questions only when owner requests next action.

## Scope-to-task coverage

| Specification requirement                           | Implementing tasks |
| --------------------------------------------------- | ------------------ |
| Auth/runtime/local data separation                  | 01                 |
| Multi-tenancy/roles/invitations/multiple-tab safety | 02,04,16           |
| Exact money/discounts/taxes/BS dates                | 03,07,08,10        |
| Schema/FKs/chart/journal/idempotency                | 04,06–11,16        |
| Contacts/items/cash-bank/categories/openings        | 05,06              |
| Purchase/expense/sale/drafts/numbering              | 07,08,09           |
| Payments/allocation/owner entries/transfers         | 09                 |
| Stock/partial returns/refunds/valuation             | 06,10              |
| Cancellation/date lock/audit                        | 11,12              |
| Reports/P&L/trial/balance sheet/as-of               | 12                 |
| Print/CSV/private attachments/settings              | 13                 |
| Mobile/accessibility/translation/manifest           | 08,14              |
| Platform/trial/access/ops/backup                    | 15,16              |
| Concurrency/rollback/regression/release             | 16                 |

## Completion rule

Each task supplies working code and evidence, not a description of intended behavior. Tasks05/07/08
depend on later stock/payment primitives for specified integration; execute in order but keep their
pending integration checks visible and finish at06/09 before calling those tasks complete. At final
handoff every first-release acceptance has evidence or explicit limitation;
financial/tenant/concurrency failures block release. Operational/regulatory gates remain separate
and visible.
