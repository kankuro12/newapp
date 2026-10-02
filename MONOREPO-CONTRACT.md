# Monorepo and Ionic implementation contract

Version1.3,2026-10-02. Laravel 13 backend, Ionic React 9 + TypeScript frontend;
web/PWA first, shared source for later Android/iOS. This supersedes older Blade
UI references and scaffold-only status. Financial/product requirements in
APP-SPECIFICATION and acceptance scenarios remain authoritative.

## Workspace and boundaries

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

Nine services under `backend/app/Service`: AccountingService, DocumentService,
InventoryService, PaymentService, TenantService, RecurringExpenseService, PosService, RestaurantService, AppointmentService. Controllers validate inputs and
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
| Dashboard | frontend/src/pages/Dashboard.tsx |
| Contacts/items | frontend/src/pages/Masters.tsx |
| Sale/purchase/expense editor | frontend/src/pages/DocumentForm.tsx |
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

Actual checks in BUILD-PROGRESS.md: backend 37 tests/514 assertions, frontend 14 tests,
build/lint/formatting, live cookie-CSRF browser posting, mobile/desktop layout,
production assets-only offline PWA and separate-process MariaDB locking/FK test.
These do not certify all119 acceptance scenarios or MySQL 8.4 readiness.

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
