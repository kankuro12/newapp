# C1b billing-first, dispatch, packages and backorders

4 October 2026. Required continuation of C1, not implemented by C1a. Work inline; no Git mutation,
subagents, new dependencies or dairy changes. Public guide must keep this flow marked in progress
until actual controls and checks ship.

## Research and chosen bookkeeping policy

[Zoho packages](https://www.zoho.com/us/inventory/help/sales-orders/packages.html) supports several
packages per order and separates awaiting shipment, shipped and delivered states.
[Zoho shipments](https://www.zoho.com/us/inventory/help/sales-orders/shipments.html) supports manual
shipping and grouping packages from the same order. Both full pages verified. The Odoo19 HTML page
failed again, but its
[official source](https://raw.githubusercontent.com/odoo/documentation/19.0/content/applications/sales/sales/invoicing/invoicing_policy.rst)
now verifies ordered-quantity and delivered-quantity policies, partial deliveries and backorders in
full. No vendor journal internals are inferred from those product features.

[IFRS15 overview](https://www.ifrs.org/issued-standards/list-of-standards/ifrs-15-revenue-from-contracts-with-customers/)
describes revenue recognition with transfer of promised goods/services. This motivates an app design
choice: a bill before fulfilment must not manufacture profit while the goods/work remain
unfulfilled. This is a bookkeeping default, not a claim of Nepal statutory invoice or all-contract
IFRS compliance.

Freeze `delivery_first` or `bill_first` on the first staged physical/financial action. Existing
stage/bill history backfills delivery_first, including reversed history. New orders choose the first
action; copies require fresh review. Original immediate full-bill conversion stays available before
staging. A bill-first invoice creates party dues/actual payment and current invoice tax entries,
without moving stock. Its sales base waits in a system liability until explicit customer
handover/service completion. Supplier goods/work value waits in a system asset until
receipt/completion. Recoverable purchase VAT remains outside held inventory cost; other tax remains
included. Tax/statutory release gates already in the specification remain applicable.

Direct customer handover/completion recognizes agreed sales base and actual moving-average cost
once. Package shipment moves goods to an in-transit asset; confirmed delivery recognizes remaining
agreed base/cost without another stock movement. Packing alone changes no money/stock. It reserves
exact billed dispatch capacity; stock availability is rechecked at shipment. General stock
reservation/allocation across POS is separate C2 inventory work; do not imply packing protects the
item pool from other daily sales.

## Owned data and service integration

Keep FulfilmentService as the staged feature owner. Reuse DocumentService for trusted prepared
invoices/credit/cancel, InventoryService for physical moves and AccountingService for tenant
locks/UUID replay/system journals.

- Nullable immutable fulfilment_policy on business_workflows/documents; all service writes whitelist
  it internally. Add bill-first system accounts to new tenant chart and collision-safe
  existing-tenant migration.
- Allow workflow_bill_allocations.fulfilment_line_id null for pre-billed ordered quantity. Original
  order position remains distinct even for duplicate items. Non-null delivery-first allocations
  retain existing invariants.
- workflow_dispatch_allocations: composite own workflow/document_line/ fulfilment_line links,
  qty_milli, original inventory cost, pending purchase cost and allocated agreed sales base. Many
  invoice lines may feed one actual order-line action. No cost inferred from today's price or item
  ID alone.
- workflow_return_allocations: own return document_line and original billed line, optional exact
  dispatch allocation, qty/cost/base/pending values. Null dispatch means credit unfulfilled quantity
  only; non-null names actual goods/ work. Retain these rows after reversal and filter active source
  statuses.
- workflow_packages and package_lines: owned order/billed-line links, immutable reviewed quantities
  while packed, versions, BS dates, actor, reference/notes, manual carrier/tracking,
  packed/shipped/delivered/cancelled state and exact shipment stage link. Recognized delivery
  journal is owned/auditable/reversible. Package cancellation never silently deletes shipment or
  invoice history.
- Source action snapshots retain item/unit, party and business provenance. Do not derive old
  cancelled slip identity from a now-editable source order.

Every financial action requires current direct branch membership, role/parent ownership,
opening/period/date gates, current order/source versions, integer quantity limits and reviewed HMAC
proof. Actor-aware original UUID replay must work after lost reply and fail after membership/role
revocation. No private API cache or offline posting. Cost context stays private to authorized roles.

## Returns and reversal invariants

Choose an exact delivery source for a physical billed return. Choose unfulfilled credit explicitly
for quantities that never left stock/completed work. Never receive nonexistent goods. Physical
return cost comes from the original named dispatch allocation with cumulative exact residual; later
dispatch at another average cost must not change earlier return costs. Purchase physical returns
still issue actual moving-average stock and use existing gain/loss differences. Unreceived credits
clear exact remaining held source value, not invented stock.

Credits/returns keep billed order capacity closed for replacement. Unfulfilled credits reduce
dispatch capacity; physically returned dispatched goods do not reopen it. Reversing a credit
restores only its own allocation. Packed capacity must be released before crediting its unshipped
quantities. Invoice cancellation blocks active physical/package dependencies. Physical cancellation
blocks its active physical returns and delivery recognition; cancel dependents in reverse order and
preserve existing stock chronology/period restrictions. Guard migration rollback against any history
rather than erase posting/reversal links.

## Numerical acceptance

Package API execution contract: reviewed pack at `workflow/{id}/packages[/preview]`; show at
`package/{id}`; reviewed ship and deliver at `package/{id}/{ship|deliver}[/preview]`; explicit
`undo-delivery` and `cancel` with package and parent versions, BS date and reason. Packing is sales
stock only; services use direct completion. Carrier/tracking are manual, with no external shipment
integration. Packed lines remain immutable: cancel an unshipped package and review a replacement to
change its quantities.

Shipment uses exact package billed-line allocations, excluding only its own packed reservation from
capacity checks. Recheck actual stock and reviewed cost under the tenant lock. Recognize
inventory-to-transit only at shipment. Delivery confirmation posts transit-to-COGS and
held-base-to-sales, with no second stock move. Preserve original source snapshots and journal
history.

Undo delivered recognition before cancelling a shipped package. Posted physical returns block
delivery undo until their own reversal. Shipment cancellation uses existing later-stock/reversal
gates and reverses its transit journal; packing cancellation has no stock/journal effect. Never
silently revert one package when a different one consumed later item activity. In-transit goods
cannot be credited as unfulfilled or returned as confirmed customer delivery; cancel a reversible
shipment first, or confirm actual delivery when it happened and use its named physical return. A
dedicated undelivered-shipment return is still required before enabling package controls: actual
returned goods can arrive after later stock activity made original-shipment reversal unavailable. It
must receive original source cost from transit, clear only the actual credit base from held sales
and never invent customer delivery. Freeze the return's unfulfilled/transit/completed source mode,
recognize only the remaining package quantity/cost/base and avoid cyclic undo/cancel dependencies.
Include zero-paisa and partial-return residuals, late stock activity and return reversal tests.
Progress must distinguish packed, shipped, delivered and credited quantity; an unconfirmed shipment
has zero customer-returnable quantity. Cashier retry must recheck original source-bill ownership as
well as current membership and parent ownership.

Sale: opening10kg/costNPR500; order5kg atNPR100. Bill3kg forNPR300: receivable300, unfulfilled
liability300, sales/COGS0, stock10kg/cost500. Hand over 2kg: sales200, COGS100, liability100,
stock8kg/cost400. Credit1kg never handed over: receivable200, liability0, stock unchanged. Return1kg
from that handover: receivable100, sales100, COGS50, stock9kg/cost450. Replacement capacity stays
closed; the original order still has2kg not yet billed. Later cost changes must not reprice this
source return. Reversal of return/credit/handover/bill restores the original balances exactly, with
dependent actions cancelled first.

Purchase: opening10kg/cost500; bill5kg atNPR50 before receipt. Payable250, held asset250, stock
unchanged. Receive2kg: held150, stock12kg/cost600. Credit1kg unreceived: payable200, held100, stock
unchanged. Receive remaining2kg: held0, stock14kg/cost700. Cover recoverable/nonrecoverable VAT,
same-item positions, zero-cost goods, services, frozen offers and fractional source residual
pennies.

Package: bill5kg; pack2kg without stock/journal change. Ship: stock falls once, cost enters transit,
sales remains unfulfilled. Confirm customer delivery: transit clears to COGS and agreed base becomes
sales; stock stays unchanged. Multiple packages/partial shipments reconcile source bill capacity.
Backorder print shows original ordered, billed, credited-unfulfilled, packed, shipped, delivered and
remaining quantities; it must not claim undelivered goods arrived.

## Ordered execution and completion gates

1. RED tests for invoice-first sale/purchase/service, actual dispatch/receipt, credits versus
   physical returns, exact cost and safe reversal. Preserve all existing delivery-first/legacy
   tests. Current missing endpoints prove gap.
2. Additive policy/allocation/account schema and real composite-FK/rollback/ existing-tenant
   backfill tests. Test DB only until meaningful financial tests pass; no real/dairy data changes.
3. Reviewed ordered-quantity invoices and billed-source physical actions, deferred recognition,
   credit/return allocations and cancellation integration. Do not enable incomplete return behavior
   or a stock-only shortcut.
4. Security/replay/race cases, including stale same-total terms, archived sources, foreign
   sources/branches, cashiers, revoked replay and concurrent terminal bill/dispatch/credit
   quantities. Separate processes, not an in-memory race.
5. Reviewed packages/reserved dispatch capacity, shipment and delivery recognition, dependencies,
   progress/backorder print and meaningful package/race tests.
6. Ionic policy-aware order/invoice/return/package forms, remaining quantities, reviewed amounts,
   source disclosures, English/Nepali, original locked retry, 390px mobile and actual slip/backorder
   proof. Preserve pending entry if another actor changes the source; source/version changes require
   a fresh review.
7. Full appropriate backend/frontend checks, types/lint/Pint/build, real DB ownership/lock evidence,
   manual/ledger updates and live authenticated QA. Fixture-only visual checks must remain
   identified. Only then mark C1 complete.

All C2–C6 and D–H remain required; this design does not redefine the full goal.

## Current implementation checkpoint

Owned schema/backfill/rollback cases pass on the isolated MariaDB database. Ordered partial
invoices, direct confirmed billed delivery/receipt/work, source credits/returns and dependency
reversal now have backend implementation. Tests cover fixed tax treatment, same-position bills with
unequal residual pennies, later moving-average changes, foreign branch sources, changed reviewed
notes, actual handover confirmation and closed credit capacity. Supplier receipt costs retain each
bill's own held residual instead of reallocating costs by quantity.

Two-process terminal ordered-bill and billed-dispatch races, plus actual dispatch competing with
unfulfilled credit, now pass on the isolated database (2 tests, 64 assertions). Exactly one action
consumes each source capacity; stock, deferred value, revenue and receivable balances match its
winner. Evidence: artifacts/c1b4-concurrency.txt, 146177ms.

Cashier privacy, revoked membership, changed parent ownership, purchase-role downgrade and
cross-actor/changed-UUID cases now pass. A RED source-bill ownership replay test found a
fresh-preview/retry discrepancy; billed fulfilment retry now rechecks each historical source bill's
current cashier ownership. Targeted bill-first plus delivery-first billing: 34 tests / 1031
assertions, 60310ms, artifacts/c1b4-security-final.txt.

Reviewed packing, manual shipment, explicit delivery, delivery undo and package cancellation now
have a backend first pass. Shared physical posting moves stock to transit on shipment and posts
recognition only on actual confirmation. Package/date/source versions bind review; multiple packages
and exact held/cost residuals reconcile. Return dependencies and later-stock reversal gates remain.
Original snapshots, stage/return/journal history and zero-value delivery undo dates are retained.
Package cashier privacy and revoked retries are tested. Package safety/security: 12 tests / 492
assertions, 44063ms, artifacts/c1b5-package-security-final.txt. Earlier package/core regression: 38
tests / 1200 assertions, 51476ms, artifacts/c1b5-package-core.txt.

Full backend verification passed: 174 tests / 4409 assertions / 506539ms,
artifacts/c1b5-backend-full.txt. Dedicated undelivered-shipment returns, package terminal races,
additional zero-value/mixed-source gates, backorder printing and bill-first Ionic controls remain
required. The public guide still marks this flow in progress; no complete C1 or parity claim.

## C1b transit-return execution refinement — 4 October

Verified Zoho sales-return help separates physical receipt from financial credit and refund, and
shipment help includes manual delivery/undelivered states. These features do not prescribe our
journals. A dedicated reviewed transit return must name an owned bill line and exact unconfirmed
stock shipment, with explicit return_mode=transit; old numeric completed-source returns still reject
transit. New optional source modes freeze unfulfilled/transit/completed on return allocation.

Stock returns at cumulative original dispatch cost. Transit stock cost clears from goods_in_transit;
it never reverses COGS for goods not delivered. Actual financial credit releases at most this bill
line's currently held sales value; any already-recognized financial remainder stays a sales return.
Reconcile VAT, party dues and optional refund through original DocumentService return paths.

A raw dispatch base cannot alone determine delivery recognition: a fully returned one-paisa source
may have a zero-paisa financial credit while another package still contains the remaining goods.
Freeze actual recognition_sales_base_paisa per delivered dispatch allocation (null while
unconfirmed), backfilling current confirmed history from its old base. At actual handover,
cumulative bill quantity consumes confirmed dispatch quantity plus unfulfilled/transit credited
quantity, subtracting pre-delivery returns from confirmed quantities to avoid double count. Use
original bill rounded target minus prior frozen recognitions and held credit releases, clamped at
zero. Final actual handover/credit clears the residual penny. Undelivered raw source base remains
historical allocation, not booked revenue.

Package delivery cost/quantity exclude only active transit-mode source returns; completed returns do
not rewrite original delivery. Store recognition details in owned audit history before clearing
active recognition snapshots on delivery undo. A fully returned package cannot confirm invented
handover. Its derived remaining shipped and credited quantities expose that state without erasing
shipment history.

Transit returns do not block undo of the remaining delivery; completed returns do. Undo own delivery
before reversing its transit return. All active physical returns still block shipment cancellation.
Preserve normal stock chronology and refund/ partial-return reversal gates. Package date gates
include every transit-return post/cancellation, even zero-journal/cancelled history. Credits close
replacement capacity; operational quantities distinguish active transit credit permanently.

Shared return preview binds canonical money/source allocations, requested reason/ refund/date,
bill/order/source versions, stock state and invoice balance. Transit posting requires current bill
and workflow versions plus matching review HMAC; original UUID replay remains locked to its original
payload and current role. Existing ordinary returns remain compatible. RED tests cover late stock
cost, partial delivery/undo, mixed package penny residual, zero-journal dates, stale review and
source/role ownership before exposing Ionic package controls.
