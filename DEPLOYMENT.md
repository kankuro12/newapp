# Deployment and operations

Web-first release guide. Nothing in this file was deployed. Complete recorded
release gates in BUILD-PROGRESS.md before public use.

## Separate environment

Provision dedicated MySQL 8.4/InnoDB database, dedicated least-privilege runtime
account, independent APP_KEY, private storage and session cookie. Test database
must be a different disposable database named `business_book_testing`; tests
reject all other named databases before migrations. Never use dairy credentials,
keys, sessions, storage, or database. Retain BCMath and PDO MySQL extensions on
PHP 8.4. Invoke php84/composer84 explicitly.

Install dependencies from lockfiles. Build frontend from workspace root:

```powershell
composer84 --working-dir=backend install --no-dev --prefer-dist --no-interaction
npm ci
npm run build
```

Keep environment/secrets outside web root and source control. Use deployment
secret manager or protected .env. Supply real SMTP and sender values there;
never paste credentials into chat. Production settings include:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://books.example.com
FRONTEND_URL=https://books.example.com
SANCTUM_STATEFUL_DOMAINS=books.example.com
SESSION_COOKIE=business_book_session
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
SESSION_ENCRYPT=true
CACHE_STORE=database
MAIL_MAILER=smtp
LOG_LEVEL=warning
```

Placeholder domain must be replaced consistently. Generate APP_KEY only for a
fresh installation; preserve existing key across releases and restores. Configure
trusted reverse proxies to match hosting topology; never trust arbitrary
forwarded hosts. Restrict database/filesystem/network access at deployment.

## Serve one HTTPS origin

Serve `frontend/dist` assets and fallback `index.html` for Ionic browser routes.
Route these backend prefixes to Laravel's `backend/public/index.php` before SPA
fallback: `/api`, `/up`, `/sanctum`, `/login`, `/logout`, `/register`,
`/forgot-password`, `/reset-password`, `/email`, `/user`, `/platform-auth`.
The Ionic platform screen `/platform/signin` belongs to SPA; its API authentication
is `/platform-auth/login`.

Point PHP document root only at `backend/public`. Never expose workspace root,
.env, vendor, database dumps, logs or private `storage/app/private` attachments.
Downloads go through authorized API; no public storage link for receipt files.
Vite dev/preview and Laravel development servers are local verification tools,
not production services.

Cache hashed `/assets/*` and public icons as immutable. Serve `/index.html`,
`/sw.js` and manifest with revalidation so releases update promptly. Preserve
API/auth/download `Cache-Control: no-store`; CDN must bypass those paths and
responses with cookies. Service worker only caches explicit build assets.
Financial offline writes remain disabled. Existing installed worker may wait
until old app tabs close before new release activates.

## Release database safely

Snapshot database/private attachments and verify environment names before an
approved release. On new dedicated or explicitly approved production environment,
run from backend:

```powershell
php84 artisan migrate --force
php84 artisan config:cache
php84 artisan route:cache
php84 artisan view:cache
php84 artisan app:create-super-admin owner@example.com --name="Platform owner"
```

Admin command privately prompts for a password and creates separate identity.
Run once with real owner's email, not example. No public admin registration or
financial impersonation feature. Never run migrate:fresh or tests against live
data. Check /up, tenant login/verification, platform guard, a disposable test
business and private download after release. Clear only this application's cache
when changing release configuration; never share cache connection or prefix.

## Scheduler and mail

Run `php84 artisan schedule:run` once per minute through host scheduler using
backend working directory. Expired database cache cleanup executes every5 minutes
with overlap protection. Manual maintenance: `php84 artisan app:clean-cache`.
This implementation uses its dedicated default database for cache cleanup;
keep DB_CACHE_CONNECTION and lock connection unset. Validate scheduler logs and
row cleanup in deployed environment. Dashboard TTL45 seconds plus versioned keys
prevents stale financial snapshots; reading reports never writes financial data.

Monthly actions run every minute through `app:generate-recurring-expenses`.
Only due unpaid expense + payable is recorded; automatic action never pays cash.
Requires enabled automatic setup and current verified author with owner,
manager or accountant access. Closed periods, revoked author, suspended/expired
business or archived payee/category block posting and show setup error.
Each run catches up twelve months per setup; later runs continue backlog.
Pausing keeps earlier dues; resuming catches up missed months. Inspect scheduler
logs and Regular payments. Editing setup reauthorizes using current member.
Manual catch-up: `php84 artisan app:generate-recurring-expenses`.
Canceled monthly bills remain linked and are never automatically recreated.

Mail verification/reset/invitations currently use synchronous delivery after
successful data operations, not a custom queue system. Monitor SMTP failure and
resend paths. If delivery latency becomes material, move notifications to Laravel
queue and operate worker/retry monitoring; no queue worker required by current
business posting flow. Rate-limited auth uses application cache.

## Backup and recovery

Back up dedicated database and private attachments together, with encrypted
storage, restricted access, retention and off-host copies. Keep APP_KEY and
required environment secrets separately recoverable with restricted access.
Monitor backup completion, storage capacity, HTTP errors, failed mail and scheduler
health. Do not log passwords, whole financial request payloads or publicize mail
verification/reset links.

Before release, restore a snapshot into a NEW explicitly named isolated restore
database and private directory. Check users/memberships, balanced trial report,
stock-to-GL equality, party reconciliation, attachment downloads and current
access. Compare source counts/totals. Record date, operator and recovery timing.
Never restore over dairy, local development, testing or existing live database
without explicit migration/recovery authorization. Automated tests refuse the
restore database name; run read-only recovery checks there. After restore, revoke
old sessions as appropriate and verify independent tenant/platform sign-in.

## Native build later

`frontend/capacitor.config.ts` identifies np.businessbook.app and uses dist output.
Same Ionic pages remain shared. Before Android/iOS release, implement and verify
native authentication/backend-origin handling, secure credential storage and
logout/revocation on real devices. Current API enforces tenant web-session guard;
a bearer token alone cannot authenticate it. Do not claim native support merely
because Capacitor is installed.

Generate native projects only when native milestone begins, then build signed
Android package with Android tooling and iOS package on macOS/Xcode. Verify
BS input, print/share, uploads, keyboard, safe area, offline recovery, update and
store signing. [Capacitor workflow](https://capacitorjs.com/docs/basics/workflow).
Bookkeeping tax print is not certified statutory invoicing; keep that mode off
until applicable release requirements have been checked.