# Simple mobile accounting app — build pack

Prepared: 2026-10-02, Asia/Kathmandu. Target: separate Laravel 13 application for small Nepal
businesses.

This pack retains original product requirements and implementation handoff. Web-first Laravel/Ionic
features are now implemented in `newapp`; current checks and remaining release gates are in
README.md and BUILD-PROGRESS.md. Product choices not explicitly supplied by the owner are fixed
defaults, clearly identified in the specification.

## Read in this order

Read [MONOREPO-CONTRACT.md](MONOREPO-CONTRACT.md) first: owner-selected Laravel API + Ionic React
architecture, paths, transport and auth contract supersede older Blade UI references.

1. [APP-SPECIFICATION.md](APP-SPECIFICATION.md) — product scope, mobile screens, tenancy, database,
   money/date rules, accounting, reversal paths, operations.
2. [IMPLEMENTATION-PLAN.md](IMPLEMENTATION-PLAN.md) — ordered tasks, concrete files, interfaces,
   required checks, completion gates.
3. [ACCEPTANCE-TESTS.md](ACCEPTANCE-TESTS.md) — numerical examples and security/concurrency/mobile
   release checks.
4. [REPOSITORY-REVIEW.md](REPOSITORY-REVIEW.md) — whole-repository mapping, verified legacy
   examples, competitor references, limitations.

APP-SPECIFICATION.md governs product behavior. IMPLEMENTATION-PLAN.md governs build order.
ACCEPTANCE-TESTS.md governs expected results. If these disagree, resolve against the specification
and record the correction before proceeding.

## Fixed starting choices

- App root and planning document folder: `E:\laravel pojects\dairy main\newapp`.
- Fresh Laravel 13; PHP 8.4 explicitly selected by owner. Use `php84` and `composer84` for every
  PHP/Composer build/check command; never fall back to default `php` (inspected as 8.0.6).
- One Laravel app, one MySQL 8.4 InnoDB database, shared tables with enforced tenant ownership.
- Laravel JSON backend in `newapp/backend`; Ionic React + TypeScript/Vite frontend in
  `newapp/frontend`; one root npm workspace. Implemented web authentication: headless Fortify +
  Sanctum stateful cookies; separate superadmin guard. No microservices, native mobile build,
  tenancy package or billing gateway in first release.
- Online mobile web app with installable manifest. Offline writes and service-worker caching of
  financial data are deferred.
- NPR only; English/Nepali; BS integer business dates; one stock pool per branch; multi-branch
  grouping and industry POS now authorised (see INDUSTRY-POS.md).
- Owner-required day-to-day forms: sale, purchase, receive/pay, expense, return/refund, transfer,
  add personal money, personal withdrawal, stock count and guided starting balances. No DR/CR or
  manual-journal input for any role; balanced accounting stays internal.
- First release includes owner onboarding, staff, contacts, items, sales, purchases, returns,
  payments, expenses, cash/bank transfers, opening balances, reports, private attachments,
  printing/export, audit, period locks, and basic platform access management.
- Regulatory invoice issuance is a separate release gate. Core launch is bookkeeping; invoice
  printouts carry the bookkeeping label until Nepal billing requirements are reviewed and enabled.

## Agent launch prompt

Copy the following into a new coding chat rooted at `E:\laravel pojects\dairy main\newapp` when
implementation is wanted:

```text
Build the standalone small-business accounting application described in
E:\laravel pojects\dairy main\newapp\APP-SPECIFICATION.md.
Read this folder's README.md, BUILD-PACK.md, MONOREPO-CONTRACT.md, IMPLEMENTATION-PLAN.md,
ACCEPTANCE-TESTS.md and REPOSITORY-REVIEW.md first. Preserve existing scaffold.
Do not modify the dairy application or connect to its database.

Execute IMPLEMENTATION-PLAN.md sequentially, one task at a time.
Use the specified interfaces, table fields, route names, accounting examples,
tenant protections, BS dates, rounding rules, and reversal restrictions.
Use APP-SPECIFICATION.md section5.3's daily-action forms. No DR/CR input,
account-side selection, balancing grid or generic manual-journal endpoint
for any role. Generate all journal sides/account mappings internally.
Use Laravel 13 with PHP 8.4, invoking php84 and composer84 explicitly;
never fall back to default php/composer or silently downgrade.
Use existing Laravel JSON backend + Ionic React/TypeScript frontend workspace,
headless Fortify with Sanctum stateful cookies, and MySQL InnoDB.
Run php84/composer84 commands from newapp/backend and workspace npm from newapp.
Translate old UI view paths using MONOREPO-CONTRACT; no interactive Blade screens.
Keep one service class per feature. No speculative abstraction or extra package.
Never stage, commit, push, reset, create/switch branches, rewrite history,
or edit .gitignore unless I explicitly authorize those actions.

Write runnable tests for financial and security invariants. Verify locking
and composite foreign keys on MySQL, not just SQLite. Track completed tasks
and actual check results in newapp\BUILD-PROGRESS.md. Keep meaningful checks
and report failures accurately. Never mark skipped checks as passing.
Stop only for an actual blocking decision or missing runtime/credential;
continue independent work while blocked. Do not deploy or migrate real data
unless explicitly instructed. Finish with changed files, behavior, checks,
and unverified items. Do not spawn subagents without separate authorization.
```

The prompt is reusable instructions, not an instruction to start coding during preparation of this
pack. A less capable agent should receive the full shared contracts plus only its current task; it
must not guess interfaces from a task title.

## Implementation status

Planning pack includes the Ionic monorepo revision and subsequent research. `php84` (PHP 8.4.2) and
`composer84` (Composer 2.8.4 using PHP 8.4.2) were verified. Business web/PWA implementation and
industry POS are delivered; see [BUILD-PROGRESS.md](BUILD-PROGRESS.md) for actual checks and
remaining scope. Tax approval, production deployment, native builds and real-data migration remain
future work.

Plan checks performed: five nonempty documents; Markdown links/fences valid;16 ordered tasks;119
acceptance identifiers and task references resolved; independent integer calculations reconcile
F01–F04, R02/R05, M05/M06 and I02. Daily-form revision removes generic journal write contracts and
adds U09–U12 checks. These are documentation/reference checks, not application tests. No dairy
application files were written by this task.
