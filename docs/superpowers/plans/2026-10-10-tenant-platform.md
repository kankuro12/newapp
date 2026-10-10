# Tenant Platform Implementation Plan

> For agentic workers: use superpowers:executing-plans task by task. No subagents or Git mutations
> authorized.

**Goal:** Deliver complete approved tenant platform, package billing, provider payments, messaging,
gym operations, business-specific UI and panel manual. **Architecture:** Billing accounts group
existing isolated businesses; ledger tenant_id and membership remain authoritative. Feature services
use existing Laravel transactions and exact-value helpers; Ionic uses cookie/CSRF APIs and reviewed
daily forms. **Tech stack:** Laravel 13/PHP 8.4, Ionic React/TypeScript, existing HTTP
client/database queue, MySQL target. **Spec:** ../specs/2026-10-10-tenant-platform-design.md
**Execution:** Inline, no delegation. Status: approved; inline implementation started 2026-10-10.
User approved all routine implementation decisions.

## Global constraints

- Invoke PHP as php84 and Composer as composer84.
- No dairy files/database, production migration/deployment, Git mutations or .gitignore edits.
- Integer minor-unit money, integer thousandths quantity, integer BS business dates.
- Preserve per-business membership, composite ownership, posting cleanup and reversals.
- Each feature uses one service under backend/app/Service; reuse existing financial services.
- No DR/CR or generic journal forms. No private response caching or bearer tokens in browser
  storage.
- New dependencies require authorization; Firebase SDK addition must be reviewed before
  installation.
- Read applicable .ai rules before edits; use existing Artisan generators for PHP artifacts.

## Review focus

1. Existing shared-owner businesses must not merge accidentally during account backfill (Task 1).
2. Concurrent business creation must not bypass purchased capacity (Task 2).
3. Lost payment response must not duplicate charges or extend subscription twice (Tasks 4-6).
4. Queued messages must honor later opt-outs, renewal and membership revocation (Tasks 7-10).
5. Gym refund and plan edits must preserve original financial prices and reversals (Task 11).

## Task 1: Account schema and safe legacy backfill

Files: create backend/database/migrations/*_create_billing_accounts.php;
backend/app/Service/BillingService.php; backend/tests/Feature/BillingAccountTest.php. Modify
TenantService.php, Models/Tenant.php, Http/Controllers/BusinessController.php, routes/api.php;
create Http/Controllers/BillingController.php.

Interfaces: BillingService::account(int $actor, int $account, array $roles): object;
BillingService::createAccount(int $actor, string $name): object; BillingService::attachBusiness(int
$actor, int $account, array $input): Tenant. API GET/POST /api/billing/accounts; GET
/api/billing/accounts/{id}; account-owner business POST /api/billing/accounts/{id}/businesses.
Existing /api/businesses stays compatible: optional billing_account_id is validated against active
account ownership; omitted ID uses the lowest-ID active owned account, creating one if absent.
Explicit account chooser uses account-specific endpoint.

- [ ] Add failing tests: foreign account gives 404; account membership grants no ledger membership;
      two unrelated legacy businesses sharing owner remain separate accounts; branches join main
      account; trial and paid expiry dates survive backfill; disabled users cannot create accounts;
      omitted account resolves deterministically; supplied foreign account never falls back.
- [ ] Run php84 artisan test --compact --filter=BillingAccountTest; confirm expected missing
      API/schema failures.
- [ ] Add accounts(name,status,trial_used_at,version), account_user(account,user,role,active) unique
      membership, tenants.billing_account_id FK. Backfill deterministically by existing main
      business, preserving financial IDs and current membership. Build own-account listing and
      creation transaction.
- [ ] Run BillingAccountTest and existing BusinessFlowTest/IndustryPosTest; all pass.

## Task 2: Package catalogue, snapshots, trials and enforcement

Files: create migration *_create_billing_packages.php; tests/Feature/SubscriptionTest.php; modify
BillingService.php, TenantService.php, ResolveTenant.php, AccountingService.php,
PlatformController.php, routes/api.php. Create middleware/RequireProduct.php.

Interfaces: BillingService::entitlements(int $account): array; BillingService::requireProduct(int
$account, string $product): void; BillingService::startTrial(int $actor,int $account,int $package):
object; BillingService::settle(int $attempt,array $verified): object (used by Task 4). Entitlement
payload: status, end_at, products, max_businesses, available_businesses.

- [ ] Test package validation, version conflict, frozen snapshots, one trial per account, no
      new-business trial restart, exact expiry boundary, suspension, expired owner/accountant reads,
      cashier expiry denial, owner renewal access, concurrent capacity, legacy compatibility and
      simultaneous branch creation/package suspension without inverted account/tenant locks.
- [ ] Confirm SubscriptionTest fails before implementation.
- [ ] Add packages(duration_days,trial_days,max_businesses,products,version,active),
      package_prices(package,currency,amount_minor),
      subscriptions(account,package_snapshot,status,start_at,end_at), entitlement business selection
      for downgrade. Keep business access flags as additional restrictions.
- [ ] Lock billing account before capacity/subscription mutation and ledger tenant afterward; use
      same lock order in all relevant paths. AccountingService::lockTenant must lock the linked
      account before tenant row; TenantService::branch currently calls mutate before create, so
      enforce order at the shared entry point and cover nested branch creation. Read-only account
      authorization must not add locks after tenant acquisition. Snapshot terms; no reset of prior
      trial eligibility. Check products only on their feature routes; retain common
      read/export/reversal paths.
- [ ] Add platform package listing/create/update routes with superadmin/password/audit/version
      checks.
- [ ] Run SubscriptionTest, BillingAccountTest and existing financial/auth suites.

## Task 3: Account/package UI and business type selection

Files: create frontend/src/pages/Billing.tsx and Billing.test.tsx; modify Businesses.tsx,
Platform.tsx, App.tsx, lib/types.ts, Workspace.tsx, More.tsx. Backend modify BusinessController.php,
TenantService.php and MasterController.php.

Interfaces: /api/billing/accounts/{id}/subscriptions and /packages return string IDs/money; Business
payload adds billing_account_id,business_type,entitlements. business_type initially maps
general,meat,restaurant,barber,salon,milk,glass,wood,gym to existing pos_profile.

- [ ] Test owner billing controls, foreign account denial, required type choice, one type only,
      package-disabled type denial, expired renewal visibility and switch aborts old responses.
- [ ] Add account selector, package summary with terms/trial/capacity, platform package forms,
      business type chooser; server defaults old callers to general for compatibility.
- [ ] Block incompatible type edits with live industry records; keep historical export/reversal.
- [ ] Run frontend focused tests, npm run lint, npm run build and backend relevant tests.

## Task 4: Checkout attempts and atomic settlement

Files: create migration *_create_subscription_payments.php; Service/GatewayService.php;
tests/Feature/BillingPaymentTest.php; modify BillingService.php, BillingController.php,
routes/api.php, config/services.php. Separate from business ledger PaymentService.

Interfaces: GatewayService::initiate(object $attempt): array; GatewayService::verify(object
$attempt,array $callback): array; verified contains
provider_transaction_id,amount_minor,currency,status. POST /api/billing/accounts/{id}/checkout; GET
/payments/{reference}; POST /payments/{reference}/confirm; callbacks under
/billing-callback/{gateway}.

- [ ] Test owner scope, immutable price snapshot, unknown currency/gateway denial, duplicate UUID
      payload conflict, callback mismatch, unique provider transaction across accounts, concurrent
      duplicate settlement and exactly one entitlement extension.
- [ ] Implement durable attempts before HTTP calls; idempotency/reference per attempt. Save provider
      identifier and state; require exact amount/currency/reference verification. Confirm settlement
      and subscription change in one account-locked transaction.
- [ ] Add retry-safe status UI; client redirect never sets active status.
- [ ] Run BillingPaymentTest; confirm existing journal tables unchanged by subscription settlement.

## Task 5: eSewa and Khalti

Files: modify GatewayService.php, config/services.php, BillingController.php; create
tests/Feature/NepalGatewayTest.php; frontend Billing.tsx.

- [ ] Tests assert ordered eSewa HMAC, callback signature rejection, server status check,
      COMPLETE-only settlement, exact decimal conversion without float; Khalti stored pidx
      authentication/lookup, Completed-only status, integer paisa and amount mismatch denial.
- [ ] Implement provider protocols from sources recorded in spec. Send only configured allowlisted
      provider URLs; timeouts recorded unresolved, never second automatic charge.
- [ ] Add signed eSewa POST form and Khalti redirect; owner payment history/status.
- [ ] Run HTTP fake tests; sandbox checkout/status smoke tests only with authorized credentials.

## Task 6: Stripe, PayPal, refunds and reconciliation

Files: modify GatewayService.php, BillingService.php, config/services.php, routes/console.php;
create Console/Commands/ReconcileSubscriptionPayments.php;
tests/Feature/InternationalGatewayTest.php; frontend Billing.tsx.

- [ ] Test Stripe server checkout retrieval, webhook signature/time tolerance, duplicate events,
      unpaid session rejection; PayPal OAuth/order/capture confirmation, merchant ownership, exact
      supported-currency amount; NPR PayPal denied before provider request.
- [ ] Implement current official Stripe Checkout and PayPal Orders APIs with HTTP client; fetch
      canonical server state for settlement; preserve unknown outcome and provider IDs.
- [ ] Tests cover refunded/disputed/reversed attempts, audited entitlement adjustments, unaffected
      tenant ledger, reconciliation replays and timeout followed by successful lookup.
- [ ] Add bounded scheduled reconciliation; explicit supported-currency fixed pricing in UI.
- [ ] Run payment suites and provider sandbox checks; document live checks separately.

## Task 7: Templates and queued account messages

Files: create Service/NotificationService.php, Jobs/SendAccountMessage.php, Mail/AccountMessage.php,
resources/views/emails/account-message.blade.php, migration *_create_message_deliveries.php;
tests/Feature/AccountEmailTest.php. Modify CreateNewUser.php, Models/User.php,
FortifyServiceProvider.php, TenantService.php.

Interfaces: NotificationService::enqueue(string $event,int $user,array $context,array $channels):
void; NotificationService::deliver(int $delivery): void. Record event/delivery key, channel,
recipient reference, state, attempts, safe error, provider ID; jobs after commit.

- [ ] Test welcome, verification, reset, changed-password, invitation and receipt templates; correct
      frontend links, escape names, preserve token/signature/throttle semantics, no plaintext
      secrets in delivery logs, rollback sends nothing, failed delivery retry.
- [ ] Implement reusable branded view and event hooks, preserving Fortify security behavior.
- [ ] Run AccountEmailTest and existing password-reset/registration tests.

## Task 8: Device registration and FCM

Files: create Http/Controllers/NotificationController.php, migration *_create_push_devices.php,
config/notifications.php, tests/Feature/FcmTest.php; modify NotificationService.php, routes/api.php,
frontend/src/lib/push.ts, existing PWA worker and Auth/account UI.

Interfaces: POST/DELETE /api/notifications/devices; GET/PATCH /api/notifications/preferences. Token
belongs to authenticated user/device; re-registration transfers ownership atomically,
logout/revocation disables registration. Server uses OAuth service account + HTTP v1.

- [ ] Test foreign token deletion denial, replacement, membership revocation, invalid token removal,
      permission denied/no browser support, logout then another login on same device, generic
      payload/same-origin link and worker network-only private routes.
- [ ] Verify Firebase dependency authorization before installation. Configure existing worker via
      serviceWorkerRegistration; request browser permission only on user button click.
- [ ] Implement server token acquisition/caching with expiry margin, redacted error handling and
      bounded retries; device/permission UI and foreground notification.
- [ ] Run backend/frontend tests, production worker build and browser foreground/background smoke
      tests.

## Task 9: WhatsApp and channel preferences

Files: modify NotificationService.php, NotificationController.php, config/notifications.php; create
tests/Feature/WhatsappTest.php; frontend account preferences.

- [ ] Verify current official Meta template protocol/version and opt-in rules before code.
- [ ] Test absent phone, invalid E.164, missing opt-in/template/config skip only WhatsApp;
      normalized country code, approved template params, retry and revoked consent at execution.
- [ ] Implement Meta Cloud API HTTP sending, configured templates/token/phone ID and redaction.
- [ ] Add independent transactional/marketing preferences; run tests and credentialed smoke test.

## Task 10: Expiry reminders and marketing campaigns

Files: create Service/CampaignService.php, Console/Commands/SendPackageReminders.php,
Console/Commands/DispatchCampaigns.php, migration *_create_marketing_campaigns.php,
tests/Feature/PackageReminderTest.php, MarketingCampaignTest.php; modify NotificationService.php,
PlatformController.php, routes/console.php, Platform.tsx.

Interfaces: CampaignService::save(int $admin,array $input,?int $id): object;
CampaignService::preview(int $admin,int $campaign): array; CampaignService::dispatch(int $campaign):
void; NotificationService::packageReminders(): int.

- [ ] Tests freeze Nepal dates: 7/3/1-day and expiry thresholds, trial/paid delivery, scheduler
      overlap, renewed expiry cancels stale queued message, disabled user skip.
- [ ] Add durable threshold keys and subscription-version revalidation; daily scheduler.
- [ ] Test campaign version/preview/audience/channel selection, explicit send, scheduled send,
      cancellation, unsubscribe signature, opt-out after enqueue, no duplicate successful delivery.
- [ ] Build platform campaign preview, schedule/send/cancel and delivery diagnostics; record
      accepted vs delivered separately and process authenticated provider receipt callbacks.
- [ ] Run message/reminder/campaign suites and worker/scheduler smoke checks.

## Task 11: Gym daily operations and financial integration

Files: create migration *_create_gym_tables.php; Service/GymService.php;
Http/Controllers/GymController.php; tests/Feature/GymTest.php; frontend/src/pages/Gym.tsx,
Gym.test.tsx; modify routes/api.php, Workspace.tsx, More.tsx.

Interfaces: GymService::member(int $actor,array $input,?int $id): array; plan(int $actor,array
$input,?int $id): array; join(int $actor,array $input,string $uuid): array; checkIn(int $actor,int
$membership,array $input,string $uuid): array; book(int $actor,int $class,int $member,string $uuid):
array; cancelBooking(int $actor,int $booking,string $uuid): array. Gym endpoints
/api/app/{tenant}/gym/{members,plans,memberships,checkins,trainers,classes,bookings}.

- [ ] Test foreign references and product/type/role denial, immutable plan/fee snapshot, valid BS
      membership range, visit allowance, duplicate/concurrent check-in, capacity, duplicate class
      booking/cancellation, archived plans and revoked membership retry.
- [ ] Create scoped tables with composite ownership FKs. Use existing tenant lock/UUID mutation and
      calendar conversion; contacts for members, item-backed plans for fee sales.
- [ ] Tests assert posted exact fee, partial receipt, original-price refund after plan edit,
      cancellation cleanup, closed-period restrictions and no generic journal endpoint.
- [ ] Integrate DocumentService/PaymentService; forms add member, join/renew, receive fee, check in,
      class booking/cancellation and trainers. Frozen invoice details remain viewable.
- [ ] Run GymTest plus financial reversal suites and focused UI tests.

## Task 12: Manual, integrated verification and operations

Files: modify frontend/public/help/user-manual.html, UserManual.tsx if navigation needs it,
DEPLOYMENT.md, BUILD-PROGRESS.md; feature UI tests and existing browser QA workflow.

- [ ] Update manual with accounts/business roles, packages/trials, all gateways, uncertain payment,
      reminders/preferences/unsubscribe, push permissions, campaigns, gym and type selection.
- [ ] Run complete backend suite, php84 vendor/bin/pint --dirty --format agent, npm run
      test:frontend, npm run lint, npm run build. Fix regressions and rerun affected checks.
- [ ] Verify MySQL target composite FKs/locking/concurrent account limit/settlement/class capacity;
      preserve legacy financial ownership and reversal behavior. SQLite is insufficient proof.
- [ ] Verify mobile/desktop business-specific navigation, switch abort, expired renewal, checkout
      returns, panel manual, PWA private-data behavior, FCM foreground/background.
- [ ] Document required worker/scheduler/HTTPS/provider environment and approved templates,
      supported merchant currencies, sandbox/live check results without exposing credentials.
- [ ] Completion audit against every spec requirement; unresolved live provider/environment gates
      remain unverified, never count mocked or skipped checks as live success. No automatic
      deployment.

## Task 13: Format all project source and documentation

User added this requirement on 2026-10-10. Apply Laravel Pint to PHP and Prettier to
frontend/configuration/documentation. Preserve application behavior, text meaning, ledger/date
arithmetic, dependency licenses and recorded test artifacts. Add root format/check scripts, verify
formatting, types, lint, relevant tests and production build. Generated output and installed
dependencies follow their generators rather than manual edits.

## Task 14: Queue all long-running application work

User added this requirement on 2026-10-10. Audit mail/notification delivery, push fan-out, WhatsApp,
campaign/reminder scheduling, payment reconciliation, imports and large report exports. Dispatch
durable ID-only work after commit where possible; encrypt jobs that must carry security links.
Recheck disabled users, memberships, ownership and consent inside workers. Keep exact monetary
inputs and business dates, mutation idempotency, reversal paths and existing scoped download access.
Move per-device FCM sends into bounded independent jobs so one provider timeout cannot exhaust
entire fan-out job. Expose queued progress and failures where users currently wait for a long
request. Require working worker/scheduler deployment and prove behavior with real queue tests.
