# Tenant platform, packages, messaging and gym design

Date: 2026-10-10. Status: written specification approved by user on 2026-10-10.

## Scope

Deliver FCM; extend existing separately authenticated superadmin tenant management;
time/product/business packages and trials; eSewa, Khalti, Stripe and PayPal tenant payments;
reusable welcome, reset and account emails; scheduled package reminders through email, WhatsApp when
phone available, and FCM; marketing campaigns through all three channels; multiple businesses per
tenant account; gym management; one business type per business with appropriate UI; updated manual
inside panel. All requirements remain delivery gates, including provider integration verification.

## Existing implementation verified by source inspection

- config/auth.php already separates tenant/users and superadmin/superadmins.
- PlatformController manages business access with password confirmation and audit.
- Tenant currently represents an isolated business, not a subscription account.
- tenant_user holds per-business membership; URL slug resolves CurrentTenant.
- ResolveTenant checks membership, disabled users, suspension and expiry.
- TenantService gives every newly created business its own 14-day trial.
- A user can already own/select several businesses. Branches preserve separate books.
- pos_profile selects existing industry modes. Gym is absent.
- Database queues exist; scheduler currently handles cache and recurring expenses.
- Provider/payment/subscription integration was absent in inspected app/config paths.
- Public static manual already appears inside the authenticated Ionic workspace.

## 1. Account and business boundaries

Add billing_accounts and billing_account_user above existing tenants. In product copy, billing
account means tenant account; existing tenant_id continues to identify a business in every financial
table. Add tenants.billing_account_id without changing existing financial IDs, URLs, membership keys
or composite ownership constraints. A billing-account role never grants access to a business ledger.
Owners explicitly manage account membership and business membership through distinct endpoints.

Backfill one account for each existing main business and attach its child branches. Do not merge
unrelated businesses merely because an owner email matches. Shared ownership can later move a
business only with explicit authorization on both sides, audit and preserved financial isolation.
Existing business access/trial dates remain honored during transition; no reset or extension of
historical trials.

Account-scoped business creation locks account before testing package capacity; branches count as
businesses. New accounts receive one package-defined trial. Additional businesses cannot restart a
trial. Account suspension denies business access; expiration preserves existing owner/accountant
read/export behavior. Billing, renewal and account identity routes remain usable after package
expiry.

## 2. Packages and entitlements

Package definitions contain name, active flag, version, integer price in supported currency minor
units, duration_days, trial_days, max_businesses and product keys. Product keys initially cover
bookkeeping, existing industry workflows and gym. Treat product-based packages as enabled
application modules, not stock item limits. Every business chooses exactly one type; enabled
products authorize corresponding server routes and navigation. Business type and package product are
separate fields.

Subscriptions snapshot purchased package terms, start/end instants and trial status. Published
package edits affect future purchases, never existing paid snapshots. One trial per billing account,
tracked persistently. Renewal starts at the later of current eligible end and confirmed payment
time. No double extension on callback retry. Downgrade below current business count requires owner
selection of retained businesses; others remain readable under expired-access rules. No automatic
deletion.

Package durations use operational timestamps, stored UTC and displayed Nepal time. Ledger dates and
gym operational business dates remain integer BS YYYYMMDD; BS membership-day calculations use the
existing calendar conversion utilities.

## 3. Superadmin

Reuse SuperAdmin, superadmin guard and existing Platform page. Add account/package/
subscription/payment/delivery/campaign management. Keep password confirmation and reason/audit for
sensitive access changes. Public registration/reset never creates or authenticates platform
identities. Platform endpoints expose operational metadata; no tenant financial impersonation.
Version-check package/campaign mutations.

## 4. Tenant payments

Separate BillingService and GatewayService from existing ledger PaymentService. Payment attempts
hold account, actor, immutable package snapshot, gateway, expected integer amount/currency, unique
reference and lifecycle state. Owner sees checkout summary before leaving for provider. Client
success redirects never grant access. Server confirmation verifies provider status, transaction
ownership, amount and currency against immutable attempt; settlement plus subscription change is
atomic. Unique provider transaction keys prevent replay across accounts or payment attempts.

Implement each named gateway using current official documentation, Laravel HTTP client, server-only
credentials, timeouts and authenticated confirmation/webhooks. Reconcile unresolved attempts through
scheduled jobs. Preserve uncertain attempts for safe retry; do not create a second charge because a
response was lost. Handle pending, failed, cancelled, verified, refunded and disputed states
explicitly. Refunds/disputes create audited entitlement adjustments; never silently alter business
accounting journals. Subscription purchases are platform billing records.

NPR remains business-ledger currency. Show only gateway currencies explicitly configured and
supported for the merchant. Any non-NPR package uses a separately configured fixed minor-unit price;
no float conversion or invented exchange rate. Provider availability and live settlement remain
credential/runtime delivery gates.

## 5. Email, WhatsApp and FCM

NotificationService owns durable delivery records and preferences; queued jobs send after
transaction commit. Reusable branded templates cover welcome, verification, password reset, password
changed, invitation, payment receipt, trial/expiry reminder and promotional campaign.
Reset/verification retain Fortify token/signature behavior. A delivery history never stores
plaintext reset tokens or provider secrets.

FCM uses user-owned browser device registrations and server HTTP v1 delivery. Permission request
follows explicit user action. Token replacement/logout/revocation cleans registrations; queued
delivery rechecks user/account membership and preference. Push payload contains generic wording and
same-origin links, no balances or secrets. Integrate messaging into existing PWA worker without
caching private API responses.

WhatsApp uses Meta Cloud API by default. Valid phone alone is insufficient: require recorded
messaging opt-in and approved provider template where required. Normalize stored country calling
code and local number into provider address. Missing phone, consent or configuration records a
skipped channel without stopping email/FCM.

Email account messages remain distinct from promotional opt-in. Promotions require channel consent
and unsubscribe controls; recheck opt-out when job executes. Campaign UI previews content, audience
count and channels before explicit send/schedule. Retry transient failures with bounded backoff;
invalid device tokens are disabled. Logs redact recipient-sensitive content and secrets.
Cancellation prevents pending jobs sending. Deduplicate successful delivery by event, recipient and
channel; provider timeout records uncertain status rather than claiming exactly-once delivery.

## 6. Scheduling

Daily package reminder evaluation in Asia/Kathmandu: 7, 3 and 1 days before expiry, then on expiry,
for trial and paid subscriptions. Record subscription/version/ threshold/recipient/channel key;
overlapping scheduler runs cannot create duplicates. Renewal cancels obsolete pending reminders; job
execution revalidates current expiry. Email, WhatsApp and FCM are independently eligible channels.
Provide account preferences and platform delivery diagnostics, with bounded retry and failure state.
Scheduled campaigns use the same durable delivery flow and current consent checks.

## 7. Gym and business-specific UI

GymService owns scoped members (linked contacts), plans, memberships, check-ins, trainers, classes,
bookings and fee billing. Plans define exact fee, BS day duration, optional visit allowance and
class eligibility. Membership activation/renewal needs explicit owner/manager action or a posted
linked sale according to configured plan; fee collections use existing DocumentService and
PaymentService. Cancellation/ refund follows original linked document reversal rules. Editing plan
prices never reprices saved membership invoices.

Check-in verifies active membership, BS validity, visit allowance and actor role; lock
member/membership to avoid concurrent double consumption. Class bookings lock class for capacity and
detect duplicate member bookings; cancellation releases seat. Daily forms: add member, join/renew,
receive fee, check in, book/cancel class. No manual journal inputs. Composite ownership keys
constrain member/plan/booking/ invoice references. Financial mutations retain UUID and current
membership checks.

Business type is required when creating a business; existing type comes from current pos_profile. UI
shell shows relevant industry tools and common accounting/settings/ billing/help. Server validates
type/product, independently of hidden navigation. Switching business aborts old requests and clears
responses. Type change requires review; block incompatible changes while live operational records
exist. Existing posted invoices and reversal/export routes remain accessible.

## 8. Delivery sequence and verification

1. Account boundary/backfill, entitlements, trial rules and platform package UI.
2. Checkout and all four provider integrations, confirmation and reconciliation.
3. Branded account emails, device registration, WhatsApp/FCM providers and preferences.
4. Scheduled expiry reminders and reviewed promotional campaigns.
5. Gym daily operations and exact fee posting/reversals.
6. Type-specific navigation, business switch verification and complete in-panel manual.

Each phase includes backend feature/security tests and meaningful frontend tests. Exercise
cross-account/business denial, revoked ownership, separate guards, concurrent limits, one-trial
rule, expiry exceptions, snapshot immutability, duplicate/mismatched/forged payment confirmations,
retry, refund, current consent, reminder renewal, token ownership, gym capacity and original-price
reversals. Run provider protocol tests using HTTP fakes plus actual sandbox smoke tests when
credentials exist. Run PHP checks via php84/composer84, frontend workspace checks, and MySQL
composite-FK/locking checks. Never present mocks as live integration proof. Update in-panel manual
with account/package/payment/push/privacy/gym flows and accurate availability notes. Verify mobile
and desktop UI, expired renewal access, PWA private-data behavior, scheduler and queue worker
deployment instructions.

## Completion gates

All named modules, channels and gateways must be implemented and tested; existing financial
protections must remain intact. Credentials, approved WhatsApp templates, merchant currency
availability, real provider sandbox tests, MySQL and browser checks are separately recorded if
unavailable. No deployment, real-data migration, Git mutations or dairy application changes are
authorized by this design.

## Provider protocol evidence (checked 2026-10-10)

- eSewa ePay V2: sign ordered total_amount,transaction_uuid,product_code with base64 HMAC-SHA256;
  verify callback signature and independently query status. Only COMPLETE is settlement;
  PENDING/AMBIGUOUS remain unresolved. Source: https://developer.esewa.com.np/pages/Epay-V2
- Khalti web checkout: initiate server-side with integer paisa amount; store pidx returned by
  initiation; confirm using authenticated lookup of stored pidx. Compare total_amount and accept
  Completed only. Source: https://docs.khalti.com/khalti-epayment/
- Stripe lists NPR for card presentment, but merchant-account availability and settlement
  configuration require separate verification. Source: https://docs.stripe.com/currencies
- PayPal supported-currency list excludes NPR. Provide an explicitly configured supported-currency
  package price (initial option USD); hide PayPal checkout when that price or merchant setup is
  absent. Never submit NPR as USD or convert by an invented rate. Source:
  https://developer.paypal.com/reports/reference/supported-currencies
- Firebase getToken accepts an existing serviceWorkerRegistration and VAPID key. Reuse the PWA
  worker to avoid conflicting root-scope workers. Browser Firebase SDK is not currently verified
  installed; dependency installation requires project authorization before implementation. Source:
  https://firebase.google.com/docs/reference/js/messaging
- Meta template documentation could not be fetched by research tool. WhatsApp template/API version
  and current policy still require official-source review; no provider-specific implementation
  behavior is certified by this design.

Research above confirms protocol design, not merchant eligibility, live provider availability,
credentials, end-to-end settlement or actual notification delivery.
