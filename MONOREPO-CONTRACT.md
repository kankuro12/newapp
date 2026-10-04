# Monorepo and Ionic implementation contract

Version1.3,2026-10-02. Laravel 13 backend, Ionic React 9 + TypeScript frontend;
web/PWA first, shared source for later Android/iOS. This supersedes older Blade
UI references and scaffold-only status. Financial/product requirements in
APP-SPECIFICATION and acceptance scenarios remain authoritative.

## Workspace and boundaries

Public user guide: frontend/public/help/user-manual.html, shown inside the Ionic workspace at /app/{branch}/help from More (UserManual.tsx).
18 current-feature sections, responsive/print styles, public demonstration images.
Guide/images are explicitly allowed static PWA assets; private API data remains
uncached. Later feature batches must update guide and availability notes.

```text
newapp/
  backend/      Laravel 13, independent Composer lock/key/storage
  frontend/     Ionic React, Vite, Capacitor config, browser/PWA UI
  package.json
  package-lock.json
```

Use one root npm workspace/lockfile. All PHP commands use php84; Composer uses
composer84. Backend paths in planning docs refer to backend directory. Preserve
existing app and never clone/create-project over it. No dairy code/database/key,
session or private storage sharing. No Git mutations without explicit request.

Sixteen services under `backend/app/Service`: AccountingService, DocumentService,
InventoryService, PaymentService, TenantService, RecurringExpenseService, PosService, RestaurantService, AppointmentService, WorkflowService, FulfilmentService, PartyService, CatalogService, ImportService, BarcodeService, BasketService. Controllers validate inputs and
marshal scoped results; services enforce roles, ownership and atomic domain
rules. CurrentTenant fails closed when missing. Financial rows use service's
explicit tenant-scoped query helper rather than many thin Eloquent models;
composite foreign keys provide database-level ownership protection.

Money uses integer paisa, qty integer thousandths, BCMath backend/BigInt frontend.
BS dates use validated YYYYMMDD integers and tested calendar conversion. Never
use floating-point money or Carbon business-date arithmetic. Generic manual
journal/DR-CR input is prohibited. Balanced journals remain internal effects.

## UI file mapping

| Feature | Current files |
|---|---|
| Auth/profile/verification | frontend/src/pages/Auth.tsx |
| Business selection/invitation | frontend/src/pages/Businesses.tsx |
| Desktop/mobile shell | frontend/src/pages/Workspace.tsx, frontend/src/App.tsx |
| Dashboard sections and paged previews | frontend/src/pages/Dashboard.tsx |
| More tool sections and in-app manual | frontend/src/pages/More.tsx, UserManual.tsx |
| Contacts/items | frontend/src/pages/Masters.tsx |
| Sale/purchase/expense editor | frontend/src/pages/DocumentForm.tsx |
| Quotes/orders/jobs | frontend/src/pages/Workflows.tsx, shared DocumentForm workflow mode |
| Delivery-first partial fulfilment/billing | frontend/src/components/FulfilmentEntry.tsx, FulfilmentPanel.tsx, frontend/src/pages/FulfilmentDetail.tsx; SourceOrderAllocation.tsx invoice/review note |
| Party terms/prices, collections/follow-ups | frontend/src/pages/PartyTools.tsx |
| Item categories, reviewed supplier reorders | frontend/src/pages/CatalogTools.tsx, shared Masters/Pos catalogue |
| Reviewed CSV parties/items, templates/export/history | frontend/src/pages/ImportTools.tsx |
| Industry POS, waiter/kitchen and appointments | frontend/src/pages/Pos.tsx |
| Alternate codes, configured scale labels and scan control | frontend/src/pages/BarcodeTools.tsx |
| Reviewed Code128 sheet/roll labels | frontend/src/pages/LabelTools.tsx, frontend/src/lib/barcode.ts |
| Lists/details/print/drafts | frontend/src/pages/Records.tsx |
| Money/returns/stock count | frontend/src/pages/DailyForms.tsx |
| Monthly salary/rent/regular payments | frontend/src/pages/RegularPayments.tsx |
| Reports | frontend/src/pages/Reports.tsx |
| Opening/settings/staff/closing/audit | frontend/src/pages/Settings.tsx |
| Separate platform UI | frontend/src/pages/Platform.tsx |
| Exact preview/request/locale | frontend/src/lib |
| Shared fields/lookup/UI | frontend/src/components |

Use Ionic components and local CSS; normal browser form controls where adequate.
Printed bookkeeping bill uses same Ionic detail page plus print CSS. No
interactive Laravel Blade business screens, unsafe raw HTML rendering or
accounting balancing grid. English/Nepali core labels available; unfinished
secondary translations are tracked release work, not falsely certified complete.

## API and authentication

Frontend business paths: `/app/{tenantSlug}/...`. JSON paths:
`/api/app/{tenantSlug}/...`, `/api/businesses`, `/api/me`; platform metadata:
`/api/platform/...`. API route definitions live in `backend/routes/api.php` and
are loaded by web routes so every mutation receives session+CSRF protection.
No generic journal-input endpoint.

Headless Fortify handles tenant registration/login/reset/verification/profile.
Tenant guard uses users; superadmin guard uses separate SuperAdmin model/table
and provider. Public registration cannot create platform identity. Platform auth
uses `/platform-auth/login`, `/platform-auth/logout`; identity is `/api/platform/me`;
platform sign-in does not satisfy tenant auth and converse. Separate guard keys
coexist inside this application's secure session; dairy sessions are independent.

Browser APIs require Sanctum and tenant session authentication; business routes
also require verified email, current membership and access. Native bearer-only
transport is not implemented. No tokens stored in browser localStorage; only
nonsecret locale preference lives there. Cookie requests obtain Sanctum CSRF
cookie and send decoded X-XSRF-TOKEN. Same-origin frontend/backend HTTPS serving
and auth proxy paths are mandatory in production; Vite proxy is development only.

Tenant identity comes from URL plus active membership, never mutable session
selection. Disabled users and revoked members are denied before cache/lookup.
Expired access permits read/export only for owner/accountant; suspended denied.
Sensitive staff access, period close and platform access changes require current
password server-side. Platform manages metadata/access, never tenant financial
impersonation. Read controllers omit cashier cost/dues and foreign data rather
than trusting frontend hiding.

## JSON, mutations and reports

Paisa/milli/bps and IDs cross JSON as decimal strings; BS dates are validated
integers and flags booleans. Laravel pagination returns data, current_page,
last_page, total and links. Validation422, auth401, forbidden403, scoped missing404,
stale version/payload409; browser shows errors preserving values.

Server computes trusted totals and compares expected_total_paisa before posting.
Financial mutation UUID identifies actor+operation+canonical payload; same retry
returns existing result, changed payload conflicts. Frontend retains UUID after
lost reply/server failure and rejects edited retry until original resolves,
including when renewed access yields temporary403. Never queue financial writes
offline or optimistically alter balances.

Tenant row lock coordinates financial writes/numbering; document, stock,
journal/payment/audit/version effects commit together. Posted records immutable;
corrections create linked original-price returns or exact journal reversals.
Original and selected dates must remain open. Draft deletion and financial
cancellation are different paths; private files removed only after draft delete
commits. Starting balances finalize once, including zero-start setup.

Journal is canonical financial source; reading reports never modifies finance.
Inventory follows chronological moving average with nonnegative pools; refunds
cannot consume future credit. Party channels remain separate for contact that
both buys and sells. Historical balance checks prevent later-day negative cash.
Bank overdraft requires owner/accountant confirmation, audit and liability
classification. Export role enforcement and CSV formula escaping stay server-side.

## Managed cache and PWA

Dashboard45 second cache key includes tenant/data_version/role/actor/BSday.
Membership, disablement and expiry are evaluated before cached read. Financial
and master mutations advance tenant version. API/download responses no-store.
Native fetch uses credentials and no-store; changed tenant/revision aborts old
requests and clears old response data.

Service worker precaches only generated shell/JS/CSS/icons/manifest, cleans older
asset caches on activation and uses network-only auth/API. Navigation fallback
loads app shell; it never caches financial responses. Offline submit disabled,
reconnect enables safe retry. Scheduled expired DB cache cleanup every5 minutes;
operate host scheduler per DEPLOYMENT.md. Native authentication and store builds
require their own milestone rather than speculative mobile scaffolding.

## Evidence and release gates

Actual checks in BUILD-PROGRESS.md: backend full174/4409 after package/security changes, frontend76 tests/30 files after compact Home/More,
build/lint/formatting, live cookie-CSRF browser posting, mobile/desktop layout,
production assets-only offline PWA and separate-process MariaDB locking/FK test.
Latest C1a mobile visual QA uses isolated posting-disabled fixtures; live C1a
authenticated browser posting remains unverified. These checks do not certify
all119 acceptance scenarios or MySQL 8.4 readiness.

Local server MariaDB 10.4.19 differs from MySQL 8.4 target. Validate intended server,
production dependency advisories, SMTP/HTTPS/scheduler, backup restore, complete
Nepali copy, browser/device/print and native signing before corresponding
release. APP-SPECIFICATION governs product behavior, ACCEPTANCE-TESTS governs
release scenarios, README/BUILD-PROGRESS records actual implementation status.
## Industry POS extension (owner authorised, 2 October 2026)

Business may be main branch or child branch (parent_tenant_id). Each branch uses existing tenant isolation, independent stock pools, cash/accounts, staff membership and starting balances. Parent membership grants no child access. Creating branches requires main-business owner. NPR/BS/exact-value contracts unchanged. Shared transfers and consolidated statutory reporting remain separate work.

Business pos_profile selects general, meat, restaurant, barber, salon, milk, glass or wood. Item pos_unit defines base quantity, pos_methods enables entry modes, pos_custom_units stores explicit positive base quantities; service_minutes sets scheduling duration. Existing item kind/unit freeze extends to base-unit changes after stock movements. No assumed regional measure conversions.

POS sale server normalizes measurements and invokes DocumentService inside existing UUID/tenant-lock transaction. Immutable line measurement_snapshot prints dimensions/notes; returns reference original measurement and retain exact original-price reversal paths. Restaurant orders and appointments have no financial effect until explicit checkout. Tickets/services freeze prices; checkout links one document. Tenant lock coordinates table occupancy, appointment overlap and financial posting; composite foreign keys enforce ownership.

Operational UI refreshes foreground GETs every two seconds without caching private responses. Version conflicts preserve input. Failed refresh shown; uncertain mutation offers original UUID/payload retry even when live versions advance. Native push/hardware/provider integrations are not claimed.

PartyService owns optional customer credit limits, independent sale/purchase BS
payment days, agreed item rates and local staff follow-ups. Configure/read balances
only as owner/manager/accountant; cashiers receive sale-price suggestions without
credit policy or purchase prices. Shared DocumentService posting checks unpaid
sales against canonical customer exposure, including later-day peaks for backdated
sales, under existing tenant lock. Supplier dues are separate. Fully paid sales
and reversals stay usable. Stored bill due dates and approved quotes remain frozen.
Collection follow-ups never settle balances or send provider messages. Composite
party/item/bill/history ownership keys supplement membership and version checks.

Shared price-list backend extends PartyService: tenant-owned sale/purchase lists,
item/minimum-base-quantity volume rates, exact percentage adjustment and optional
BS validity. Contacts have separate channel assignments; owner/manager/accountant
manage, cashier reads sale lists/suggestions only. Selection and assignment require
matching active scoped rows. Explicit agreed party prices take priority after list
validation. Disabled/expired assigned lists fail for review; saved documents and
accepted quotes preserve original prices. POS reprices combined same-item quantity
and rejects amount entries crossing a price tier. Guided list/rule setup, party
assignments, reviewed bill/quote quantity suggestions and optional POS list choice
are delivered. Counter requires server preview fingerprint before checkout. API
checks supplied fingerprints against current normalized prices/quantities, even
with unchanged money total; older callers retain canonical server total checks.
Draft versions advance only after confirmed save or explicit reload.

Reviewed price-list CSV extends ImportService and shared PartyService validation.
`imports` resource `price_lists` accepts optional `replace_rules`; default merges
item/minimum tiers. Every row repeats existing list ID or new name/channel.
Metadata-only rows are allowed. Explicit replacement changes included lists only;
fresh tenant/version/digest and full rules review precede atomic apply and retry.
CSV limits1MiB/1000 rows/50 lists; existing parties/items retain500 rows.
Guarded UTF-8 exports include old unit/kind snapshots and spreadsheet escaping;
capture tenant-scoped queries before streaming, never resolve ambient tenant then.
Export does not truncate; larger exports must be split before reimport.
Imports never assign parties, change default prices or post financial/stock data.

Price lists also support graduated `slab` ranges; legacy/new default is `volume`.
Every slab item starts at zero. PartyService returns exact quantity/rate segments
with separately rounded gross, preserving fixed party-price priority. POS combines
same-item quantities before splitting, fingerprints the complete normalized
result and stores per-position ranges plus original measurements/notes. Amount
entry crossing differing rates requires quantity review. No averaged prices.
DocumentService permits repeated item rows for price ranges, bounding combined
item quantity; the existing100-row limit, posting and reversal paths still apply.
Reviewed manual Apply consolidates matching tax/percentage settings and allocates
fixed discounts exactly; conflicting settings or stale quantities leave entry
untouched. Accepted quote prices remain frozen. Scheme participates in guarded
versioned save, CSV review/export and scoped suggestion APIs.

BasketService owns tenant basket_offers, capped at100 named configurations.
Owner/manager/accountant configure fixed NPR or percentage below100, percentage
cap, minimum spend and optional BS validity. Cashiers read/apply only permitted
enabled offers. Counter selection is explicit; normalized quantity/list prices
apply first, minimum checks after line discounts before tax. A single offer becomes
the canonical invoice discount, allocated by DocumentService before tax; no extra
bill-discount stacking. Full offer/version/settings participate in POS fingerprint,
including changes which leave money identical. Disabled/expired/foreign/forbidden
or under-minimum selection cannot post. Composite document/offer ownership and
immutable basket_offer_snapshot preserve original financial/source-line reversals.
Counter shows item amounts before offer/tax, deduction once, discounted tax and
grand total. No private API cache, automatic posting or provider claim. Other
restaurant/appointment offer checkout verified in B8b2b2a, including both phone
previews. Stale browser tab cleanup restored input without an app patch. Manual/draft/quote/order offers delivered in B8b2b2b with fresh review for edits and saved drafts, and frozen approved conversion. Quantity choice groups delivered in B8b2b1.

Restaurant/appointment POST checkout/preview uses BasketService preparation of
saved ticket/booked prices, canonical taxes, source version, BS day and customer.
Served noncancelled rounds or arrived/in-service bookings only. A selected offer
requires matching fingerprint at posting, including same-total changes. Existing
UUID retry returns committed bills after offer disable/expiry. Existing document
offer columns retain proof; stock/returns/cancel rules remain authoritative.
Ionic ReviewedCheckout invalidates stale reviews, aborts superseded requests and
locks uncertain payment entry. Parent-level retry survives live source closure.

Quantity choice groups extend these existing rules with union item/category
selectors, explicit billed base unit and required quantity. Active category items
with other base units do not qualify; explicit items require matching unit/kind
snapshots. Overlapping groups share cart capacities through residual matching;
binary search determines complete repetitions. Buy/get minimizes feasible reward
unit prices while preserving buy quotas. Assignments and actual matched item
metadata participate in fingerprint and immutable bill snapshot. No per-copy
expansion, new service, migration or dependency. Guided Ionic setup exposes one
group at a time with optional help and retains existing safe retry/version rules.

Explicit item bundles and buy/get extend BasketService with offer_kind, reviewed
base-unit rules and optional maximum_applications. Bundles require2–20 distinct
components at one NPR price; buy/get has one buy and one reward rule, same or
different item, percentage up to100. Cart must contain both quantities. Integer
division determines complete repetitions; no per-copy loops. Bundle matches stable
source order; rewards match cheaper normalized ranges first. Original rounded
bases value matched quantities proportionally; largest-remainder discounts apply
only to eligible lines, leaving other prices/tax unchanged. Bundle never increases
prices and whole bill remains positive. Product promotions use canonical line
discounts; basket offers retain invoice allocation. Preview before_offer_paisa
keeps displayed original amounts and one deduction. Complete rules/matches/repeats
participate in fingerprint and immutable snapshot. Unit/kind/archival/ownership
checked at save and preview. Free rewards retain positive price, normal stock
consumption and zero-value source returns. Partial returns follow original
source-line proportions. Migration is additive; no extra dependency/cache.

Selected-item/category offers use offer_kind items and one target rule. Up to100
active scoped items and20 categories form a union; category membership resolves
current cart rows. Full matching line bases receive fixed NPR once or percentage
up to100; unmatched values/tax remain. Fixed saving cannot exceed eligible base;
whole bill stays positive. Canonical target names and actual matched item unit/
kind/category snapshots participate in fingerprint and immutable posting snapshot.
Category/item reference arrays cross JSON as strings. Normal source-line returns
and cancellation remain unchanged.

All offers may use paired HH:MM clock times and selected weekdays, in server
Asia/Kathmandu time. Start inclusive/end exclusive; overnight uses starting
weekday; empty days means every day. Scheduled selection requires current Nepal
BS date. Financial posting rechecks window inside existing tenant mutation; UUID
replay of a committed original remains valid after expiry. Fingerprint includes
schedule/date without minute/second churn. BS validity remains independent. GET
exposes current availability, and UI supports review/removal/refresh. No automatic
stacking, client-clock authorization or private caching.
