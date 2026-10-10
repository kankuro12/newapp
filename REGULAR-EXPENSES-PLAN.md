# Salary and regular expenses implementation plan

> **For agentic workers:** Use superpowers:executing-plans inline. User prohibits subagents and Git
> mutations.

**Goal:** Add employee salary, monthly rent and other recurring expenses with automatic payables and
later settlement. **Architecture:** Reuse contacts as payees, expense documents and supplier-payment
allocation. One new recurring-expense feature service owns monthly setup and occurrence links;
existing services own posting and reversal. **Tech stack:** Existing Laravel 13/PHP 8.4/Ionic
React/TypeScript workspace; no new dependency. **Spec:** User's employee → salary pay + expense and
regular monthly payable request, clarified below.

## Design and constraints

- Party roles are independent Customer/Supplier/Employee/Rent flags; any combination including all
  four. Purchases require Supplier; expenses/payments accept Supplier/Employee/Rent. Receivable and
  payable channels remain separate.
- Employee means salary payee, not application login/staff access. Simple fixed gross monthly
  salary, no attendance/deductions/payroll subsystem.
- Three setup kinds: Salary, Rent, Other. Employee/landlord can be added directly or selected from
  existing payees.
- First expense date and following monthly BS day configured explicitly. Day32 means month end;
  shorter months clamp, preserving configured day next month.
- Optional automatic generation creates unpaid expense + payable only. User explicitly pays from
  cash/bank; partial and later payment supported.
- One immutable occurrence link per tenant/rule/BS month. Existing bill reused; canceled bill never
  automatically recreated.
- Expense creation plus immediate payment atomic. Insufficient funds leaves no new expense or
  payment. Retry UUID retained by existing client/server helpers.
- Scheduled actor is setup's latest authorizing member.
  Disabled/unverified/revoked/expired/suspended actors/businesses cannot post; blocked reason
  visible, no date shifting past locks.
- Pause stops future automatic charges; previous dues remain payable. Editing amount affects future
  bills only.
- Tenant membership/ownership on all endpoints and worker paths; composite foreign keys, integer
  paisa, BS integers, existing reversal/period locks.

## Review focus

Short BS months/year rollover; duplicate/manual-versus-automatic race; paused or revoked setup;
insufficient funds rollback; frozen existing bill after salary change or cancellation.

## Tasks

- [x] Financial/API tests: salary accrual/pay-later/partial pay and retry; rent monthly automatic
      catch-up; invalid/foreign/disabled access; closed-period and balance rollback; canceled month
      suppression; changed amount affects future only.
- [x] Migration: recurring_expenses + recurring_expense_occurrences, monthly uniqueness and
      composite ownership. BS next-month helper with boundary test.
- [x] RecurringExpenseService + controller + scheduler command: setup/update, period preview,
      post/pay, due generation and status/error history; reuse DocumentService and PaymentService.
- [x] Ionic Regular payments page: Employees/Rent/Other tabs, add/edit setup, month/amount/payment
      review, pay-later and linked bill history; desktop/mobile navigation and Nepali labels.
- [x] Run finance/security suite, frontend checks and live UI verification; apply additive migration
      to isolated local app DB only. Update operating docs with scheduler requirement.

No Git commit/deployment or native build in this feature.
