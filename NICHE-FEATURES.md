# Niche billing research and implementation ledger

2 October 2026, Nepal first. Owner requests more competitors, features per niche,
and implementation of everything applicable. This extends COMPETITOR-RESEARCH.md
and INDUSTRY-POS.md; it does not replace the full objective with the first batch.

Evidence is official public product/help material, including search-indexed vendor
content where direct retrieval failed. Vendor marketing is not independently
tested. Features, tiers and countries differ. This catalogue lists capabilities
identified in the reviewed sources; it is not every feature of every software
product worldwide. Missing evidence is not evidence of absence. No vendor branding,
screenshots or code copied. More niches can be added to the same ledger.

## Shared billing foundation

Current code supports parties with four simultaneous roles, items/services,
sales/purchases, drafts, discounts, bookkeeping tax, full/partial/later payments,
dues, linked returns/refunds, expenses, salary/rent accrual, cash/bank transfers,
stock count, moving-average stock, reports/export/print, attachments, audit,
period locks, separate tenant/platform guards, branches and controlled caching.
These features are reused across niches. Branch books and access are separate.
Mobile/PWA is the delivery target; native signing is still a release gate.

## Feature list per niche

| Niche | Official benchmark | Public capabilities identified | Our applicable work |
|---|---|---|---|
| General store / mini-mart | [Loyverse](https://help.loyverse.com/help/how-add-items-loyverse-back-office), [Vyapar](https://vyaparapp.in/business-management-software) | Product codes, variable quantity, store inventory, barcode entry, prices/discounts, purchase/sale bills, returns, dues, low stock, reorder, units, batches/expiry, warehouse and reports. | Counter, exact SKU, units, stock/dues, party prices, BS terms, categories, reviewed supplier reorders and master CSV imports exist. Reviewed sheet/roll labels and configured scale scans exist. Shared volume price lists exist; batch stock remains to build. |
| Meat / fish / produce | [Lightspeed weighted products](https://x-series-support.lightspeedhq.com/hc/en-us/articles/25534180905243-Creating-and-selling-weighted-products-in-Retail-POS-X-Series), [embedded barcodes](https://x-series-support.lightspeedhq.com/hc/en-us/articles/25534108363547-Adding-and-editing-multiple-scannable-barcodes) | Weighted items, per-weight pricing, variable-weight/price barcode labels, scanner entry, inventory and unit conversion. | Weight/amount entry, exact conversions, configured scale-barcode parsing and reviewed labels exist. Wastage reporting remains; actual scale/printer needs hardware. |
| Restaurant / cafe | [Tigg Restro](https://tiggapp.com/restro), [Square](https://squareup.com/us/en/point-of-sale/restaurants) | Tables, handheld waiter ordering, kitchen tickets/displays, order status, menu management, payment, split bills, online ordering and multiple locations. | Waiter rounds, kitchen states, tables/takeaway, notes and checkout exist. Add modifiers, split payment/bills, preparation timing and scoped customer ordering. |
| Barber | [Vagaro](https://www.vagaro.com/pro/calendar) | Calendar, service/staff appointments, blocked time and availability. | Walk-ins, chairs/staff, durations, BS appointments, overlap guard, reschedule/status/checkout exist. Add recurring slots, staff time and reminder review. |
| Salon / beauty | [Fresha](https://www.fresha.com/help-center/knowledge-base/calendar) | Calendar, availability, blocked time and booking status. | Existing appointment foundation; extend with client service history, packages, availability navigation and reminders. Provider deposits are separate. |
| Milk retail | [Loyverse liquids](https://help.loyverse.com/help/how-sell-liquids) | Base-volume sales, fixed portions and composition/portion inventory. | L/ml, quick portions, explicit custom containers exist. Add standing customer orders and delivery/returnable-container tracking. |
| Glass fabrication | [GlassManager](https://glassmanager.com/), [estimates](https://glassmanager.com/glass-estimating-quote-software/) | Area calculations, estimating/quotes, work orders, measurements, installation scheduling, dispatch and supplier import. | Mixed dimensions/cutting notes exist. Quote/order/job pipeline first; then installation tasks, area summaries and material-use/cut waste. |
| Timber / wood / building supply | [Epicor LumberTrack](https://www.epicor.com/en-us/industry-productivity-solutions/building-supply/lumbertrack/) | Timber sales, inventory, purchasing and production workflows. | Length/area/volume/board-foot and explicit custom units exist. Add quotes/POs, staged fulfilment, bundles and material/production tracking. Regional formulas require explicit setup. |
| Laundry / dry cleaning | [CleanCloud features](https://www.cleancloudapp.com/features) | Piece/weight pricing, surcharges, stain/damage/colour notes, garment photos/tags, rack locations, assembly, split tickets, bulk invoices, subscriptions, pickup/delivery/routes, lockers, outsourcing, branches/plant, staff hours, payroll, loyalty/promotions, reports, exports and messaging/payment/machine integrations. | Implement intake → in progress → ready → collected job cards, due date and notes, then garment-level tracking/racks/photos, recurring pickup and batch billing. Hardware/locker/machine/provider connections need real configuration. |
| Phone / computer / appliance repair | [RepairDesk features](https://www.repairdesk.co/features/), [ticket creation help](https://docs.repairdesk.co/create_a_repair_ticket_through_ticket_module) | Repair ticket creation/management, customer and device information, job tracking, retail inventory, billing and repair-shop operations. | Implement job intake, device/reference and issue notes, estimate approval, work state, due date and linked bill; then technicians/checklists, parts consumption, serial/warranty/condition history. Do not capture device passwords. |
| Tailoring / alterations | [TailorPad](https://tailorpad.com/) | Leads/CRM, customer measurements/styles/templates, fabric/trim/style prices, purchase requests/POs, stock, work orders/material lists, cut-make-trim stages, fit sessions/alterations, appointments, analytics, portals, branches and integrations. | Quote/job dates and measurements/specification notes first; then structured reusable measurements, fittings and material issue/return. |
| Printing / signage / custom merchandise | [Printavo features](https://www.printavo.com/features/), [job-status guidance](https://www.printavo.com/blog/print-shop-job-tracking-software/) | Quotes/line prices, customer approvals/proofs, invoicing/payment, production schedule/tasks, due dates, job status/ownership, artwork/files, notes/specifications, checklists and stuck-job search. | Estimate → approval → job → ready/fulfilled → bill, printable job/delivery slips; then artwork revisions, task assignment and production capacity. |
| Bakery / cake orders | [BakeSmart](https://bakesmart.com/), [cake matrix](https://bakesmart.com/cake-matrix) | Retail/wholesale orders, cake size/flavour/filling/decor pricing, customer history, production schedules/layer reports, decorator tickets, online ordering, lead-time/capacity rules, live stock, standing wholesale orders, routes, invoices/packing slips/statements and customer portal. | Capture custom-order specifications, fulfilment date, quote/job and bill first; then modifier prices, recipe/BOM, daily production totals and standing orders. |
| Equipment / event / tool rental | [Booqable features](https://booqable.com/features/) | Unique/bulk assets, barcodes/QR, availability calendars, buffer/downtime, bundles/subrentals, duration/season pricing, reservations, pickup/return, damage history, quotes/contracts/packing slips, deposits/partial payments, reports, branches, imports, online bookings, CRM/email and provider integrations. | Build distinct rentable assets/reservations/returns and refundable-deposit liability. Selling stock and supplier rent payable do not implement rentals. Reuse booking locks and billing only where semantics match. |
| Pharmacy / medical retail | [Marg pharmacy](https://margcompusoft.com/pharmacy-software.html), [batch/expiry](https://margcompusoft.com/m/batch-and-expiry-tracking-in-pharma-software/), [vendor tutorial](https://tutorial.margcompusoft.com/Home/Menuvideo?mID=189) | Batch stock/expiry, barcode billing, supplier returns before expiry, stock/reorder, medicine catalogue, prescription/patient and doctor sales records, substitute lookup, reminders and financial reports; Indian GST features. | Batch/expiry/FEFO, recall trace and packaging conversion are applicable inventory work. Prescription/dispensing and country-specific tax require separately verified Nepal requirements; never suggest medicine substitutions automatically. |
| Jewellery / gold / silver | [Aadevo jewellery](https://aadevo.in/features/jewellery) | Weight/purity, gross/net/stone weight, fine-metal conversion, gram/piece/carat prices, artisan metal issue/receipt, wastage/making charges, old-gold purchase/exchange, ornament/stone/design inventory, tags/barcodes, live rates and Indian hallmark/GST support. | Build exact jewellery calculator, typed weights/purity/making charges and paired purchase/sale exchange. Artisan metal inventory and hallmark fields need explicit rules; no automatic live rates or Indian certification claim. |
| Agrovet / farm supply / farm produce | [Nepal agribusiness vendor](https://www.purbatechlabs.com/industries/manufacturing-resources/agri-businesses) | Farmer/supplier records, product inventory, purchasing/sales, farm/crop/plot activity, inputs, harvest/yield, animal/milk/breeding/vet records, collection points, processing/QC/packaging/waste, supply traceability, cooperative payments and reports. | Farm-supply billing uses existing parties/units plus batch/expiry, delivery/orders. Collection/quality payment and traceability can be added. Cooperative banking, farm operations and veterinary treatment are distinct domains, not claims of billing completeness. |
| Electrician / plumbing / cleaning / installation | [Jobber features](https://www.getjobber.com/features/), [work orders](https://www.getjobber.com/features/jobs/), [dispatch](https://www.getjobber.com/features/service-dispatch-software/) | Requests/CRM, estimates, jobs/work orders, scheduling/dispatch, site instructions/photos/forms/checklists, crew access, time tracking, invoicing/payments, reminders, routes/GPS, online booking and integrations. | Quote/job/site specifications first; then staff/visit assignment, job checklist/time and costing. GPS, routing, provider sending and portals need specific integrations/access. |

## Implementation classification

Existing means current code path, not vendor parity. Build means locally possible
and remains required work. Integration means an honest implementation needs real
hardware/provider credentials or operational setup. Domain review means rules
must be researched before enabling a new regulated or financial domain. Neither
category means quietly dropping the feature from the objective.

| Batch | Features to implement | Status / evidence |
|---|---|---|
| A | Quotes, sales orders, purchase orders, approved-price conversion, simple job specification/due/status, printable quote/job/delivery slips | Delivered web; WorkflowTest 4 tests/106 assertions, mobile browser quote → accepted → order → ready → bill |
| B | Follow-up tasks, overdue collection, BS terms/credit limits, party/item prices, categories, reorder, previewed CSV imports, barcode labels/scale parsing | B1–B8b2b2b delivered. Restaurant/appointment, manual sale/draft and quote/order offer reviews implemented. Drafts recheck current terms; approved conversion freezes prices. Both checkout phone proofs and bill/draft/quote checks verified. User manual added; C–H remain |
| C | Staged fulfilment, batches/expiry/FEFO, serial/warranty, landed costs, material issue/return, BOM/production/waste | Build; stock/reversal/concurrency evidence required for each |
| D | Laundry garment/rack tracking, tailoring measurement templates/fittings, cake/restaurant modifiers, job assignments/checklists/time, installation visits | Build using shared workflows with niche fields |
| E | Rental asset availability/returns/damage, refundable deposits, duration pricing, recurring customer orders, containers, packages/loyalty | Build; distinct accounting/availability semantics |
| F | Split restaurant bills/payments, customer catalogue/order portal, scoped customer access, approvals, bank matching, cash flow/budgets, asset depreciation/cheques | Build; remains part of extended feature ledger |
| G | Messaging/payments, hardware, maps/GPS, live rates, OCR, government submissions, external imports | Integration/domain verification; never mock a successful provider result |
| H | MySQL8.4, translations, backups/restore, native auth/builds and physical devices | Release gates retained |

## B8b2b1 design — quantity choice groups

Bundle components and buy/get roles may select one item or a union of items and
categories. Each choice group requires one configured billed base unit and exact
positive quantity. Explicit items must share that unit; category matches exclude
other units. Pack, weight, length, area and volume entries normalize first. No
assumed regional conversions. Explicit member units/kinds are reviewed snapshots;
category membership remains live and actual matched members freeze on the bill.

Overlapping groups consume each cart quantity once. Capacitated matching resolves
broad/restricted overlaps; binary search finds maximum complete repetitions.
Buy/get matching minimizes reward unit prices while preserving buy quantities.
No loop per repetition or quantity thousandth. Save assignments in preview proof;
sum matched quantity per line before exact discount allocation. Existing UUID,
tenant lock, stock posting, original returns and cancellation remain authoritative.
Keep legacy single-item rules compatible. No migration or new dependency needed.

Research: [Square product sets](https://developer.squareup.com/reference/square/objects/CatalogProductSet)
describe any/all selections and quantity constraints;
[Shopify buy/get](https://help.shopify.com/en/manual/discounts/discount-types/buy-x-get-y)
supports product/collection pools and lower-priced rewards. Unit-scoped category
groups and overlap matching are this app's explicit adaptation.

- [x] Failing tests: overlap, fractional leftovers, cheapest feasible reward,
  live category proof, tenant/unit guards, source returns and large repetitions.
- [x] Existing service/controller and guided Ionic group editor.
- [x] Focused/full checks, phone preview and evidence. Other checkout/manual
  offer integration remains B8b2b2; batches C–H remain required.

B8b2b1 evidence (3 October):17 offer tests/532 assertions; full93 backend
tests/2020 assertions,19 frontend files/45 tests. Two guided group-entry tests
failed before UI implementation; four API cases failed before group rules/matching.
Additional100-line/20-group case verifies shared capacity and exact allocation;
500 million buy/get repetitions complete without expanding copies. Pint check,
lint and TypeScript/build pass. Main1665.85KB/gzip379.66KB warning remains.
An accidentally overlapping database run caused a missing-table test error;
final target and full suites reran sequentially and passed. Own test database
only; no new migration. Actual MariaDB10.4.19; MySQL8.4 remains unverified.

390px Nepali setup saved offer4 QA Choice groups B8b2b1: any1.5unit from QA Drinks
plus1 Test service forNPR200. Fractional cart2.501Juice +1service previews
250.10 +150.00 −100.00 =300.10, with1.001Juice outside bundle unchanged.
Phone/document width390; screenshots choice-group-mobile.jpg and
choice-bundle-preview-mobile.jpg under artifacts. Browser posted no bill;
cart cleared, temporary counter closed, setup retained, viewport reset1280.
Exact tenant/unit/stale-member guards, live membership proof, replay, stock,
original full returns and cancellation tested. No vendor parity claim.

## B8b2b2a design — reviewed restaurant and appointment offers

Extend current checkout paths first; manual bills and accepted quote/order offer
integration stays B8b2b2b. Restaurant billed quantities come from served tickets,
excluding cancelled rounds. Appointment quantities/prices come from booked
services after arrival, on appointment BS date. Current item/list prices never
replace those saved prices. One selected offer applies before canonical tax.

BasketService prepares discounts and trusted preview proof from those lines;
existing RestaurantService/AppointmentService check stage/version/ownership and
post under original tenant lock/UUID. New POST checkout/preview endpoints return
exact amounts, discount and fingerprint. Posting with an offer requires current
proof; any supplied proof checks source/version/date/customer and full offer,
even when total stays identical. Expiry rechecked before posting. Committed UUID
retry still returns old bill after expiry; normal source returns/cancel remain.
Persist existing document basket_offer_id/snapshot, no migration/dependency.

Guided Ionic checkout selects offer, fetches preview, shows deduction/tax/total
and enables payment only for current proof. Changing source/version/customer/day/
offer immediately invalidates old totals; abort old requests. Live kitchen/staff
version updates trigger review again. Uncertain save locks selection and permits
original retry. Existing no-offer API checkout remains compatible.

Research: [Loyverse ticket discounts](https://help.loyverse.com/help/how-apply-discounts-during-sale)
describe review of configured savings on ticket/item selections.
[Fresha checkout rewards](https://www.fresha.com/help-center/knowledge-base/clients/584-manage-client-rewards)
describe checkout redemption limits. This step reuses explicit app offers;
loyalty earning/redemption remains batch E rather than pretending parity.

- [x] API RED: stored prices/taxes, source changes/same-total offer proof,
  required proof, permission/tenant guards, original retries/returns/cancel.
- [x] Shared preparation plus existing checkout services/controller/routes.
- [x] UI RED: changed context disables payment, fresh proof/amount submitted,
  failed preview preserves selection, uncertain retry locks input.
- [x] Focused/full checks and restaurant mobile preview/evidence.
- [x] Salon live phone preview: stale opening tab cleaned up; input recovered.
  Both phone proofs verified. Keep B8b2b2b/C–H active.

B8b2b2a evidence (3 October): API3 new cases failed before routes/preparation;
UI3 cases failed before ReviewedCheckout. Final focused29 backend tests/734
assertions; full96/2094 in isolated business_book_testing. Frontend20 files/49
tests, including retry after a lost reply and live completed appointment.
Changed-path Pint, lint, TypeScript and build pass. Main1668.06KB/gzip380.13KB
warning remains. A paid-bill cancel fixture initially failed correctly: cancel
the linked payment before cancelling its bill. Final fixture covers that guard
and reversal. No migration/dependency added; actual MariaDB10.4.19, target8.4
unverified. Logs artifacts/b8b2b2a-* retain actual results.

390px Nepali restaurant proof: isolated QA Restaurant Checkout, order2,
served2.501 QA Momo at saved100; offer5 deducts10; trusted240.10. No horizontal
overflow; screenshot artifacts/restaurant-checkout-offer-mobile.jpg. No bill,
payment, starting balance or stock movement posted in browser. Viewport reset.
Created isolated QA Salon Checkout branch; chair save then browser checkbox
clicks on the already verified restaurant tab stopped changing state. Valid
inputs, no invalid controls and no network request during save isolate browser
interaction failure; no speculative app patch. Salon setup16 retained for retry,
temporary name cleared. Stalled opening tab15 remains unmarked for cleanup.
Follow-up: stale tab15 was removed by turn cleanup; native clicks recovered.
Chair, service and offer6 saved through UI. Arrived QA Review only appointment:
booked QA Haircut150 less10 =140.00, current BS20830617. Verified390px without
horizontal overflow; artifacts/salon-checkout-offer-mobile.jpg, tab16 deliverable,
viewport reset. No browser bill/payment/starting-balance posting. Both previews
now verified; previous browser failure retained as history, no app patch.

## B8b2b2b design and ordered plan — manual and approved offers

Goal: reuse named offers on manual sales/drafts and quote/order entry, with exact
tax/stock and approved-price conversion. Laravel13/Ionic React, existing services,
paisa/milli integers, BS dates, tenant locks and original UUID remain mandatory.
Inline execution only; no Git mutations or new service/dependency/migration.

DocumentService adds trusted review of raw manual lines through BasketService;
offer selection rejects nonzero bill-discount stacking and purchase/expense use.
One offer may follow existing line discounts. Source type/id/version/day/customer
and full offer participate in proof; selected-offer save requires current proof.
Manual draft_input stores pre-offer entry. Existing document offer columns store
canonical proof; internal POS/restaurant/appointment saves pass server-prepared
proof separately so canonical discounted lines never receive an offer twice.
Preview endpoints cover new document, existing draft edit and saved draft post.

Draft posting rechecks eligibility/permission/expiry and full saved offer/settings.
If offer changed, edit/review draft before posting; never silently reprice saved
draft on post. Current source proof required for post; committed UUID retry still
returns original after expiry. Updating/removing an offer replaces proof and
pre-offer lines atomically. Clone restores pre-offer line discounts and omits
offer, requiring a new explicit selection rather than copying expired savings.

WorkflowService previews/saves current explicit offers for editable quotes/orders.
Store server-generated canonical offer proof and raw edit input inside existing
bill_input JSON. Accepted quotes and approved orders convert their frozen prices,
offer allocations and proof even after offer changes/expiry, subject to existing
quote validity and item kind/unit guards. No client snapshot accepted. Copy/order
and original-source returns preserve proof. Customer approval is still staff
recording, not a public signature. Saved ordinary orders retain quoted prices.

DocumentForm.tsx shows real offer selection, current preview/error and named
deduction/tax/total; old proof invalidates immediately on financial context change.
Load pre-offer draft/workflow input; manual bill discount disables while offer
selected. Payment and save/draft require fresh offer review. Records.tsx draft
posting reviews saved offer and links to editing when it changed/expired.
Workflows.tsx displays frozen approval proof. English/Nepali,390px data entry.

Research: [Zoho FSM discount preferences](https://help.zoho.com/portal/en/kb/fsm/billing/articles/tax-discount-preferences)
cover line/transaction discounts across estimates, work orders, appointments and
invoices. [Zoho accepted quote conversion](https://www.zoho.com/uk/invoice/help/estimate/estimate-preferences.html)
supports conversion/field retention. Current-offer draft guards and frozen approval
are this app's explicit rules; provider sending, tax-inclusive/after-tax pricing
and public acceptance remain other ledger work rather than claimed parity.

Review focus: double application on internal POS calls; pre-offer discounts during
edit/clone; stale same-total proof; foreign/cashier ownership; frozen conversion
after expiry; source returns/cancellation; failed/uncertain preview/save states.

- [x] API RED in DocumentOfferTest: manual stock/tax/retry/returns, source proof,
  draft stale/expiry/edit/remove/post/clone, approved quote/order freeze, restricted
  roles/foreign sources and non-sale/stacking denial.
- [x] DocumentService/BasketService reviews and controller/routes; adapt internal
  Pos/Restaurant/Appointment calls to trusted proof. Preserve cleanup/reversals.
- [x] WorkflowService/controller previews, raw edit input and frozen conversion.
- [x] API GREEN/focused and full backend sequentially on protected test DB.
- [x] UI RED then DocumentForm/Records/Workflows and Nepali copy; fresh review,
  lost responses, raw edit and draft post guards; keep original retry.
- [x] Frontend checks and390px manual/draft/quote phone proofs without financial
  browser posting; exact evidence and ledger update. Then batches C–H remain.

## B8b2b2b verification and user guide

3 October2026: DocumentOfferTest6 tests/131 assertions; full protected backend
102 tests/2225 assertions. Frontend23 files/60 tests; lint, TypeScript, changed-path
Pint and production build pass. Before-offer manual values remain editable;
current proof required even for same-total offer edits. No new migration,
dependency or service. Internal POS/restaurant/appointment paths pass trusted
server proof once; source returns and cancellation checks remain green.

390px Nepali browser: regular bill150−10=140, saved nonfinancial draft9 and
quote4/QUO-000001 in QA Salon Checkout; draft posting stays disabled until opening
setup. No browser financial posting. Screenshots: artifacts/manual-bill-offer-mobile.jpg,
draft-offer-review-mobile.jpg, quote-offer-mobile.jpg, saved-quote-offer-mobile.jpg.
Viewport overrides restored. Manual source frontend/public/help/user-manual.html:
18 current-task sections, configured units,8 POS profiles, salary/rent/pay-later,
corrections and uncertain-action recovery. More links the guide; static guide/images
join the public asset cache allowlist. No private financial responses added.
Guide390px: all section anchors valid, examples loaded, no overflow; proof
artifacts/user-manual-mobile.jpg. Maintain guide as later batches land.

Full UI checks caught an old workflow mock returning lookup data for the new
offer lookup; fixture corrected. Parallel runs also hit existing checkout/label
timing limits under load; final sequential full run passes60 tests. No product
workaround or weakened assertion. Production bundle remains large.
MySQL8.4/native/provider/physical-device/translation/backup gates and batches C–H
remain active. Next: C1 staged fulfilment with independent stock/reversal checks.

## Batch A design and ordered checks

Laravel WorkflowService owns nonfinancial quotes/orders. One business_workflows
table stores tenant-owned party/item snapshots, exact totals, BS validity/due
dates, specification notes, optimistic version, parent quote and unique bill.
Reuse DocumentService preparation/calculation/posting. Accepted price snapshots
are immutable. Editing draft changes version; sent quotes must return to draft
before revision. Record acceptance by authenticated staff; it is not a public
customer signature. No order/quote changes money or stock before posting a bill.

Quote → sent → accepted → sales order (or bill). Sales order → in progress → ready
→ fulfilled → bill; purchase order → ready/fulfilled → purchase bill. Cancellation
needs reason and never reverses a linked financial bill implicitly. Order due
date means fulfilment date; billing due date is a separate reviewed input. A
cancelled linked bill stays visible and does not silently reopen the order.
Whole-order conversion initially; partial fulfilment remains batch C, explicitly
visible rather than masquerading as stock reservation.

Files: backend migration, WorkflowService, WorkflowController, existing routes,
DocumentService shared preparation, AccountingService retry roles; frontend
Workflows.tsx, shared DocumentForm workflow mode, Workspace routes, i18n and CSS.
No packages, Git mutations or subagents. Execute inline, one checkable task at a
time, maintaining the larger ledger rather than asking for repeated approvals.

- [x] Backend failing tests: no financial effects, approved snapshot/order/bill,
  original UUID replay, changed payload/version, wrong tenant/party/role, expiry,
  stock/locked-period rollback, conversion once and original returns.
- [x] Add table/service/controller with composite ownership and atomic conversion;
  run focused tests and explicit-file formatting.
- [x] Add mobile editor/list/detail/print and reviewed bill conversion using existing
  picker, exact preview, EntrySteps and safe retry; run frontend checks/build/lint.
- [x] Apply additive migration only to dedicated development DB; verify browser
  quote → order → job state → bill and phone print/layout with synthetic data.
- [x] Update evidence/feature status. Remaining batches stay active. Do not mark the
  full goal complete on Batch A evidence alone.

## Batch B1 design — party trading and collection follow-ups

Reuse party, canonical ledger and normal posting. PartyService owns optional
customer credit limit (blank unlimited, zero cash-only), independent sale/purchase
payment days and exact per-party/item rates. BS day addition uses calendar table.
Only owner/manager/accountant configure policies/rates or read collection balances.
Credit checked under tenant lock in shared bill posting after payment, including
historical exposure for backdated sales; paid sales and reversal paths remain usable.
Quote/order approval does not silently override posting limits. Agreed quote prices
remain frozen; editable new entries suggest rates, POS calculates with trusted rates.

Follow-ups capture party, optional same-party bill, BS date, channel, assignee,
status and immutable action history. They never create payments or send messages.
Collection list uses canonical net party balances, includes archived parties with
dues, and links the normal payment form. Bill due dates and task dates stay distinct.
Optimistic versions and mutation UUIDs protect retries; database tenant ownership
constraints supplement role/member checks. No new dependency, subagent or Git action.

Order: failing financial/ownership tests → migration/service/controller → shared
posting and lookup → mobile policy/pricing/follow-up pages → browser and checks.
Categories/reorder/import/labels and grouped price lists remain Batch B work.

B1 delivered checks: full backend44 tests/718 assertions; frontend8 files/16 tests;
TypeScript, selected-file lint, PHP formatting and production build passed.
Tests cover default/explicit BS due dates, blank/zero limits, partial/full payment,
backdating, drafts, independent supplier dues, exact POS amount-price conversion,
frozen accepted quotes, scoped rates/assignments, retry/history and cashier privacy.
Synthetic390px browser:6sq_ft changes600NPR to480NPR at party rate80; unpaid sale
rejects at credit limit with cart retained. Collection and follow-up screenshots
are under artifacts. Physical device/provider behavior is not verified here.

## B2 plan — categories and reviewed reorders

Goal: group products and review supplier reorders before ordinary PO creation.
CatalogService owns categories, item settings and suggestions; WorkflowService
saves POs under existing lock with fresh stock/pending-order/price checks. Existing
Laravel13/Ionic/BS/exact-money/tenant contracts apply. No packages, Git or agents.
Review focus: foreign references; archived assignments; another open PO; changed
stock/price while reviewing; retry without stock/payment effects.

- [x] RED CatalogTest: scoped category filters/archive/version/privacy; current
  stock minus outstanding orders; exact prices, stale/replayed creation and FK.
- [x] Migration, CatalogService/Controller, master filters/settings and workflow guard.
- [x] Searchable categories/setup and reorder review using Picker/calculate/safe retry.
- [x] Focused/full tests, TS/build/lint/formatting, browser proof and evidence update.

Ruling: B2 categories/reorder precedes B3 CSV imports so import review can reuse
category/supplier identities. Open unbilled POs reduce suggestions across suppliers
in same branch. No reservation/receipt effect is implied.

B2 research: [Loyverse categories](https://help.loyverse.com/help/items-categories)
describes optional product grouping; [Zoho preferred vendors](https://www.zoho.com/us/inventory/kb/items/item-preferred-vendor.html)
and [purchase-order creation](https://www.zoho.com/us/inventory/help/purchase-orders/purchase-order-creation.html)
describe supplier selection and ordering. Our adaptation adds branch-scoped target
stock, deducts every open unbilled supplier PO, and checks a reviewed fingerprint
under the existing tenant lock. Quantity and price remain explicitly editable.

B2 delivered: full backend48 tests/823 assertions; frontend9 files/18 tests,
TypeScript, selected lint, PHP formatting and production build passed. CatalogTest
4 tests/105 assertions covers category filters/archive/version/UUID, cashier
privacy, foreign references/FK, agreed rates, pending orders, cancellation/billing,
stale stock/price/supplier and duplicate/malformed requests without extra posting.
Synthetic390px browser: inline QA Drinks category, QA Juice supplier/target setup,
POS category filtering, three-step PO-000001 for10 bottles at100NPR, then pending10
and suggested0 with available stock still0. English/Nepali footer controls fit390px.
Proof under artifacts/category-pos-mobile.png, reorder-review-mobile.png,
reorder-order-mobile.png and reorder-covered-mobile.png.

B3 delivered below: reviewed CSV party/item imports with duplicate/rollback checks.
Photos, nested groups, grouped/tier prices, labels and embedded-scale barcodes are
still pending. Reorder suggestions scan open PO snapshots (known linear ceiling);
unattended scheduling and provider sending are not delivered. No native/hardware,
MySQL8.4 or production-readiness claim; overall goal stays active.

## B3 plan — reviewed CSV master imports

Reuse canonical master validation/save for individual forms and imports. CSV
templates and scoped exports identify existing rows by explicit ID; blank ID
creates. Preview reports create/update/category counts and row/column errors.
Duplicate SKU or ambiguous party name/phone requires correction, never automatic
merging. Apply revalidates all rows and reviewed state under tenant lock; commit
all or none with original UUID replay, history counts and per-record audit.
Archive/system records, stock unit/kind freezes and outstanding party roles stay
protected. Exact prices/quantities, POS units/custom conversions and four party
roles are supported. Stock quantities/balances are not raw master fields; normal
opening/count/payment paths retain their accounting/reversal requirements.

- [x] RED: preview creates nothing; exact CSV/UTF-8/quotes, updates/category links,
  duplicate/invalid/foreign/role denial, stale review and atomic retry/rollback.
- [x] Extract shared master rules/save; ImportService/Controller/history table.
- [x] Mobile file-or-paste preview, row errors/counts and explicit apply with safe
  retry; templates/export and party/item shortcuts.
- [x] Full checks, dedicated dev migration, browser proof and ledger update.

Known ceiling: 500 data rows / 1MiB per reviewed batch; larger catalogues use
multiple batches until measured volume warrants queued jobs. No dependencies.
Official research: [Loyverse CSV import/export](https://help.loyverse.com/help/importing-and-exporting)
documents templates, create/edit counts and row/column errors before confirmation.
Our import keeps bookkeeping stock movements outside raw catalogue edits.

B3 delivered: explicit column mapping/ignore and preserved mapping after source
corrections; fresh preview required after edits. Existing IDs update only this
branch; omitted fields retain old values. New items inherit niche defaults;
explicit recognized units override them. Duplicate identities and new categories
use configured database collation, including accent-equivalent names. No raw CSV
stored in history. Financial/stock CSV migration remains required through normal
opening/count/payment posting, never direct balance edits.

Fresh evidence: ImportTest7 tests/151 assertions; full backend55 tests/974
assertions, frontend10 files/19 tests; TypeScript/lint/Pint/build passed.
Synthetic390px browser mapped six headings, blocked invalid 1e3 price, retained
mapping after correction, created two items and one category. Export download
preserved dirty input. Proof: artifacts/import-errors-mobile.png,
import-review-mobile.png and import-saved-mobile.png. Main bundle gzip356.40KB
warning retained; physical devices, printers/scales, MySQL8.4 and native builds
remain unverified. B4 configurable barcode labels/scale parsing next; B–H scope
remains active, including grouped/tier prices and financial migration.

## B4 research / next implementation — barcodes and labels

[myBillBook barcode help](https://knowledge.mybillbook.in/en/help/articles/3073415-how-to-generate-barcode-for-items-using-mybillbook)
shows item-code generation followed by label preview/download/print.
[Loyverse label help](https://help.loyverse.com/help/how-print-labels-items)
supports name/price/code options, item/category/supplier selection, label counts
and browser printing. [Lightspeed barcode help](https://x-series-support.lightspeedhq.com/hc/en-us/articles/25534108363547-Adding-and-editing-multiple-scannable-barcodes)
documents multiple item codes with preserved leading zeros, import mapping, and
EAN13/UPC-A labels carrying quantity or price plus a checked final digit. Its
pre-weighed label scan is distinct from direct scale communication.

Adaptation: scoped alternate product codes, exact scan resolution in shared POS,
opt-in branch settings for embedded weight/price formats, checksum and ambiguous
code rejection. Scan data must still pass trusted quantity/price normalization
and normal posting; retained measurement snapshots and returns remain canonical.
Existing manual amount/quantity/dimension/custom-unit entry stays available.
Labels use reviewed item selection, copies, name/price/code visibility and browser
print with adjustable physical dimensions/gaps. Real printer/scale calibration
requires hardware; no automatic scale connection or provider success is implied.

- [x] Inspect existing lookup/POS/settings/print paths and installed barcode support.
- [x] RED exact codes/leading zeros, aliases/collisions/tenant access, checksum,
  embedded quantity/amount math and checkout/retry/reversal integration.
- [x] Canonical scoped code/settings service, aliases and CSV round trips.
- [x] Simple scan-to-cart, mobile controls and Nepali copy.
- [x] B4b label preview/printing, physical dimensions/gaps and copies.
- [x] Fresh B4a backend/frontend/build checks, browser proof and ledger update.

### B4a execution — exact codes and configured scale labels

BarcodeService owns scoped alias validation/save and branch rule configuration,
resolving exact SKU/alias before enabled embedded formats. Rule fields: name,
prefix, total_length12/13, product_digits, value_digits, decimals, mode
quantity/amount, and quantity unit; digit lengths must fill the code before its
checksum. Prefix overlaps are rejected. Labels carry physical sample formats,
not presumed manufacturer defaults. Leading zeros stay strings. SKU/alias
collisions and archived/foreign items fail closed. POS re-resolves raw barcode
under normal posting lock, ignoring supplied derived quantity; snapshots preserve
code/settings, normal party prices/stock/UUID/returns remain canonical.

Files: additive item_codes/tenant barcode_rules migration, BarcodeService and
BarcodeController; shared CatalogService/MasterController/ImportService integrate
aliases into individual saves, lookup and CSV round trips. PosService/Controller
accept raw scan provenance. BarcodeTools.tsx provides alias and branch-rule forms
with existing Picker/EntrySteps/useSave; Counter receives explicit scan control.
No new dependency, Git change or agents. Printed label generation follows B4b;
that requirement is retained, including physical size/gap calibration.

- [x] BarcodeTest RED: exact/zero/collision/privacy/FK, rules/checksum/overlap,
  weight/amount/units, forged measurement, stale config, UUID/return and CSV.
- [x] Service/schema/routes and canonical master/POS integration.
- [x] Mobile code/rule setup and scan→trusted cart with original retry preserved.
- [x] Focused/full checks, isolated dev migration, browser proof and docs.

B4a evidence (2026-10-03): backend60 tests/1109 assertions; frontend11 files/21
tests; TypeScript/build/lint/Pint pass. Dev migration applied to business_book;
tests use isolated business_book_testing on installed MariaDB10.4.19.
Zero-decimal formatter fixed at its shared source, covering integer grams;
numeric rule fields normalize before storage and malformed object lists reject.
Barcode checkout reduces moving-average stock, UUID retry posts once, and
partial returns restore original cost/price despite later price/config changes.
390px browser saved aliases00501/00000123 and weight20/EAN13/5+5/3/kg format,
retained unsaved scale draft while saving aliases, then resolved 0.375kg at
320.35 to120.13. Invalid checksum preserved cart; repeated scan retained focus.
English/Nepali width390 with no horizontal overflow. Inspected proof:
artifacts/barcode-setup-mobile.png, barcode-cart-mobile.png,
barcode-error-mobile.png and barcode-cart-nepali-mobile.png. Browser QA previews
only; stock0 item not billed. No real printer/scale, native device or MySQL8.4
verification. Main bundle gzip359.41KB warning remains. B4b and B–H remain active.

### B4b execution — reviewed labels and calibrated browser print

Bounded extension of barcode setup and existing browser print. Reviewed product
selection supports individual items, category and preferred supplier, explicit
copies and SKU/alternate/none code. Preview refreshes scoped names and standard
unit prices; optional configured bill tax uses canonical integer rounding. No
financial or stock posting. Code128 B for ASCII and C for even numeric codes,
checksum/stop/quiet zones, no external service or new dependency. Unsupported
Unicode SKU can use an ASCII alias or text-only label; no fabricated EAN/UPC.

Paper width/height, label width/height, columns, margins, X/Y gaps, bar height and
module width stay adjustable. Named sheet/roll presets speed mobile entry.
Validate physical fit and barcode width; refuse unreadably shrunken symbols.
Name/price/code-text options, total copies and page count appear before printing.
Native browser print / PDF; settings can be remembered locally, no item/price
snapshots stored there. Use existing Ionic controls, Picker and EntrySteps.

- [x] RED API scoped preview, unchanged books/version, fresh prices/tax, aliases,
  invalid/archived/foreign records, caps and category/supplier filtering/privacy.
- [x] RED Code128 known vectors/checksum/zeros, quiet zones/ASCII and layout fit.
- [x] Server read-only selection/preview; simple encoder and guided label page.
- [x] UI verifies fresh preview after editing, then print reviewed labels;
  physical fit and source errors retain selection, no stale ready state.
- [x] Full checks,390px English/Nepali preview, inspect print PDF/pages and proof;
  hardware/native remains unverified. Remaining B–H work stays active.

B4b evidence (2026-10-03): backend62 tests/1162 assertions; frontend13 files/24
tests; TypeScript, lint, build and explicit PHP formatting pass. Main bundle
gzip364.75KB warning remains. No dependency, Git or dairy changes. Zint Code128
symbol-table BSD notice retained in THIRD-PARTY-NOTICES.md and emitted with web
build. Review endpoints return scoped item/code/price data, never costs; supplier
filtering excludes cashiers. Caps100 items/1000 labels reject rather than truncate.

390px synthetic browser: alias00000123 remains exact, editing copies invalidates
previous proof,22 A4 labels paginate21+1. Inspected both rendered PDF pages.
Roll50×30mm proof has2 pages,1 label each, text and bars visible; PDF MediaBoxes
142.08×84.96pt (browser rounding). English/Nepali review shows canonical price
320.35/kg. Inspected artifacts/labels-review-nepali-mobile.png. No bill/payment
posted; existing0.375kg cart retained. Shared Ionic print containment, absolute
background and composited scroll layer fixed; existing invoice print inspected.
Native print action invoked, but embedded browser exposes no controllable native
dialog; dialog/printer/device behavior remains unverified. Grouped/tier pricing
is next Batch B work; C–H still required.

### B5 execution — shared lists and volume tiers

Research: [Zoho Inventory price lists](https://www.zoho.com/us/inventory/help/items/price-list.html)
supports sales/purchase lists, contact/transaction assignment, percentage changes,
individual prices and quantity bands. [Odoo POS pricelists](https://www.odoo.com/documentation/17.0/applications/sales/point_of_sale/pricing/pricelists.html)
documents customer choice and minimum quantity rules. Nepal adaptation: NPR paisa,
base-unit thousandths and optional integer BS validity dates; no currency/GST rules.

Reuse PartyService trading/pricing, mutation lock/version/UUID, scope and canonical
posting/returns. Named tenant lists: sale/purchase, enabled, signed percentage
adjustment, optional BS start/end, item fixed prices at minimum quantities. Highest
qualifying minimum prices all units (volume pricing); baseline0 supports fractional
weights.10 tiers/item,1000 rules/list, duplicate thresholds rejected. No slab or
cross-product basket aggregation implied; those models remain ledger work.

Party assignment has separate sales/purchase lists and trading version. Resolution:
validate selected/assigned list, explicit active party price first, item tier next,
list percentage on canonical standard/last purchase price next, standard fallback.
Disabled/out-of-date assigned lists fail with review guidance rather than silently
charge another price. Manual bill/quote suggestions stay explicit; saved amounts
and accepted quotes remain snapshots. POS aggregates same-item base quantity before
repricing; amount mode whose tier price changes fails, requesting quantity entry.
Fresh expected-total comparison catches changes before normal stock/dues posting.

- [x] B5a RED tests: scope/roles/DBFK, list version and replay, exact adjustment,
  fractional boundaries/duplicate caps/unit changes, party priority, dates,
  aggregate POS, amount ambiguity, stale totals and original-price returns.
- [x] B5a migration, PartyService/Controller routes, scoped suggestions/lookup and
  canonical POS repricing; full backend tests and formatting.
- [x] B5b guided list/rule editor, party assignment, bill/quote reviewed quantity
  suggestions, optional POS list selection, English/Nepali mobile proof.
- [x] B5b UI tests/build/lint and server/browser proof; update delivery ledger.

B5a evidence (2026-10-03): RED3 tests failed at absent list endpoints; GREEN3
tests/102 assertions; full backend65 tests/1264 assertions. Explicit changed-path
Pint pass. New migration2026_10_03_004348 applied to dedicated dev business_book;
tests recreate isolated business_book_testing. No frontend/dependency/Git/dairy
changes. Routes expose list create/edit/detail/pagination, assignment and scoped
suggestions. Rule read batches item names; no per-rule name query. API validation
rejects1001 rows,11 tiers/item, duplicate minima, money exponent/zero, invalid
dates and foreign rows. Scope FK independently rejects foreign party assignment.

Fractional boundary2.499→90.00;2.5→80.25. Repeated POS1+2 combines3 at80.25,
240.75 total. List change blocks stale post with409 before extra bill/payment.
Original return and accepted quote retain80.25; explicit party70.00 overrides list.
10% decrease100.01→90.01; purchase70.00 plus10%→77.00, integer rounding. Amount90
below threshold works; amount270 or amount90 plus quantity2 rejects changed tier.
Disabled/out-of-range lists and changed base-unit snapshots fail for review.
Cashier cannot manage or inspect purchase lists/prices; snapshots hide assignments.
This is backend progress. B5b screens/quantity-aware manual UI, slab/basket pricing,
list CSV and remaining C–H work are not claimed delivered.

B5b evidence (2026-10-03): guided lists/rules, channel-specific party assignments,
quantity review/apply for manual forms and POS list choice delivered. Changed units
reject stale saves. Background revisions preserve draft fields and pinned versions;
confirmed saves advance editor version. POS proof checks normalized quantity/price
and measurements, including amount changes with unchanged total. Counter requires
proof; API supports it optionally for existing callers. Full backend67/1295;
frontend16 files/30 tests; Pint/TypeScript/lint/build pass. Gzip370.05KB warning.
390px EN/NE list tiers saved/reopened,280.25/kg review kept entered320.35 until
apply, quantity edits disabled apply, POS2.500kg trusted700.63. Party assignment
retained unsaved credit draft while saved credit stayed1500.00; assignment restored.
No browser bill/payment posted. Inspected artifacts/price-list-nepali-mobile.jpg.
Native/hardware/MySQL8.4 remain unverified; embedded dirty reload stalled, fresh
saved-state read succeeded. Slab/basket pricing and list CSV remain Batch B work;
C–H remain required. Overall goal remains active.

### B6 execution — reviewed price-list CSV

Primary research: [Zoho Inventory import/export](https://www.zoho.com/in/inventory/kb/price-lists/pl-import.html)
offers sales/purchase price-list files, templates, field mapping and preview.
Use our existing three-step import UI and ImportService. UTF-8 comma CSV only;
no new spreadsheet package. User authorised sequential implementation inline.

Design: repeat list ID (updates) or name/channel (creates) on every row; optional
metadata and item ID/SKU/name, minimum quantity, NPR price and unit snapshots.
Resolve only own active items. Omitted metadata/tiers remain; explicit replace
option replaces rules for included lists only, with full before/after review.
Metadata-only rows allow percentage lists. Shared PartyService validation guards
prices, dates, versions and units. Import never assigns parties or posts money.
Limit 1 MiB,1000 rows,50 lists,10 tiers/item. Existing master limits stay500.
Export all lists without truncation; split exports over limits before reimport.
Tenant/version/digest proof, atomic apply and original UUID retry remain required.

- [x] RED backend create/update/replace/retry/export/security/stale-unit tests.
- [x] Shared list validation, reviewed import and guarded CSV export/API routes.
- [x] Import resource/replace UI, fresh-review test, EN/NE copy and management link.
- [x] Full checks and mobile browser review; record actual evidence.

Slab/basket pricing and C–H remain separate required batches.

B6 evidence (3 October): RED4 missing-resource tests, then additional RED list-name
collision test; GREEN5 tests/109 assertions. Full backend72/1404 on isolated local
MariaDB10.4.19; frontend16 files/31 tests, Pint/TypeScript/lint/build passed.
Gzip371.32KB warning persists. 390px English import saved synthetic QA CSV volume
B6, reopened list2 in Nepali:0.000kg100.25 and2.500kg90.35. No horizontal overflow
(390/390). No browser bills/payments posted. Inspected artifact
artifacts/price-list-csv-mobile.jpg. Temporary viewport reset.
MySQL8.4/native/hardware/performance release gates remain unverified.

### B8a design — reviewed whole-basket offers

[Square pricing-rule documentation](https://developer.squareup.com/docs/catalog-api/cookbook/auto-apply-discounts)
describes minimum-order, quantity, bundled and timed discounts;
[Loyverse discount setup](https://help.loyverse.com/help/how-create-and-configure-discounts)
describes fixed/percentage values and staff restrictions. Implement whole-basket
minimum-spend first; bundle/BOGO/category/time-of-day and manual workflow review
remain B8b required work, not silently represented by a spend threshold.

BasketService owns named tenant offers: enabled, fixed NPR/percent, optional
percent cap, minimum spend after line discounts before tax, optional BS start/end,
cashier_allowed, optimistic version. Owner/manager/accountant configure; cashier
reads/selects permitted offers. No stacking with another bill discount. Offer
selection is explicit in POS; active scoped settings are recalculated server-side
and included in existing preview fingerprint. Disabled/expired/ineligible offers
leave cart untouched. Config changes invalidate preview even if final money matches.
Normal DocumentService invoice-discount allocation/tax/stock/payment/return paths
apply. Save immutable offer snapshot on posted document; no provider integration.
Exact paisa/BigInt and BS dates; no packages/Git/agents or dairy changes.

Files: additive basket_offers/document snapshot migration, BasketService and
BasketController/routes, PosService/PosController, BasketOfferTest; frontend
BasketOffers.tsx setup/picker, Pos.tsx, Workspace/Settings/i18n plus UI tests.

- [x] RED fixed/percent/cap/threshold/date/role/tenant/version/fingerprint tests,
  retry, original-discount returns and cancellation.
- [x] Backend configuration, scoped read, canonical POS discount and snapshot.
- [x] Guided setup, selector and visible deduction; stale preview/UI tests.
- [x] Full verification, additive dev migration,390px proof and evidence.

B8a evidence (3 October): RED3 missing-endpoint tests,2 missing editor UI tests,
1 missing counter selector test; additional RED display regression for showing
deduction twice. GREEN basket4 tests/81 assertions; full backend80/1569 on isolated
business_book_testing (actual MariaDB10.4.19); frontend19 files/38 tests. Pint,
lint and TypeScript/build passed. Main bundle1647.43KB/gzip375.30KB warning remains.
Synthetic390px Nepali setup saved QA Basket saving B8a offer1 (30.01 discount,
200.00 minimum), then counter combined slab250.72 minus30.01 =220.71. Screenshot
artifacts/basket-offer-mobile.jpg inspected, width390/390. Under-minimum1kg cart
remained with disabled checkout; removing offer restored100.25 and checkout.
No browser financial posting; test cart cleared, temporary counter tab closed,
saved setup retained and viewport restored1280/1280. Dev business_book received
only additive2026_10_03_054749 migration; no dairy, packages or Git changes.
Dynamic server errors remain English; broader translation/H release gate retained.
No claim of restaurant/salon/manual/approved-workflow offers or bundle/BOGO parity.
B8b and C–H remain required, including MySQL8.4/native/hardware/performance checks.

### B8b1 design — item bundles and buy/get offers

[Square bundled discounts](https://developer.squareup.com/docs/catalog-api/cookbook/auto-apply-discounts/bundle-discounts)
and [Shopify buy/get rules](https://help.shopify.com/en/manual/discounts/discount-types/buy-x-get-y)
support product combinations and qualifying/reward quantities. Shopify requires
both purchase/reward products in cart and chooses cheaper reward items for shared
products. Adapt explicit-item rules to arbitrary billed base units, not count-only.
Category/alternative groups, time-of-day and other checkout/manual flows remain
B8b2, explicitly required; this step does not claim full promotion parity.

Extend existing BasketService/offer form; no new service or dependencies. Add
offer_kind basket(default)/bundle/buy_get, optional maximum_applications and JSON
rules: item, role component/buy/get, positive thousandths quantity, frozen unit/
kind/name snapshots. Bundle2–20 distinct components at one NPR price/application;
buy/get exactly one buy and one reward rule, same or different item, reward percent
up to100 including free. Roles and units checked on save and preview; edited unit
requires reviewed resave. Existing BS/minimum/staff/version/retry guards remain.

Repetitions use integer quantity division and optional cap, without per-copy loops.
For shared buy/get item, divide total by buy+get quantity; distinct items require
both quantities. Cart must contain reward; no automatic stock/item injection.
Bundle matches component quantities in stable bill order; buy/get discounts cheapest
reward price ranges first. Matched values proportionally use original rounded line
bases, then largest-remainder allocate exact discount only to eligible source rows.
Unmatched items/quantities retain price. Bundle rejects if current matched base is
no higher than offered price; every whole bill must remain positive. Free reward
rows retain positive unit price and consume normal stock. Canonical line discounts,
tax, source returns and cancellations freeze their original amounts.

POS fingerprint/snapshot covers kind, rules, matches, repeats and allocations.
Basket kind keeps invoice discount; item promotions use line discounts. Display
original item bases, deduction once, discounted tax and total; never count saving
twice. UI explains minimum quantities/base units, cap and adding reward to cart.

- [x] RED bundle repetitions/leftovers, same/different-item buy/get, fractional/slab
  rewards, cap, tax/stock/returns/cancel, units/ownership/retry/version tests.
- [x] Additive migration, canonical matching/allocation and POS integration.
- [x] Guided rule UI/selector/preview and meaningful frontend regressions.
- [x] Full checks, synthetic390px proof and evidence; keep B8b2/C–H active.

B8b1 evidence (3 October): backend offer target8 tests/170 assertions; full84
tests/1658 assertions on isolated business_book_testing (MariaDB10.4.19, target
MySQL8.4 still unverified). Frontend19 files/40 tests, explicit Pint check, lint
and TypeScript/build pass. Main1654.49KB/gzip376.97KB remains performance gate.
RED logs: b8b1-red.txt, b8b1-ui-red.txt, b8b1-pos-red.txt under artifacts.
Checks: b8b1-backend-target.txt, b8b1-backend-check.txt, b8b1-frontend-check.txt,
b8b1-ui-target.txt, b8b1-pint.txt, b8b1-lint.txt, b8b1-build.txt. Additive migration
applied only to development business_book. Synthetic390px Nepali setup saved
offer2 QA Buy get B8b1: buy1kg/get1kg free, cap1. Graduated cart2.501kg shows
250.63+0.09−100.24=150.48; width390/document390. Screenshots:
artifacts/buy-get-setup-mobile.jpg and artifacts/buy-get-counter-mobile.jpg.
Browser preview only; no financial posting. Backend tests cover ordinary stock
consumption, zero-value reward return, source-value full returns and cancellation.
Partial returns retain existing source-line proportional discount rules.
Other checkout/manual/accepted workflow offers, category/alternative groups and
time-of-day remain B8b2; C–H and native/hardware/translation/performance gates stay
active. No claim of complete competitor parity or universal measurement hardware.

### B8b2a design — selected-item/category savings and Nepal-time schedules

Research: [Square product sets](https://developer.squareup.com/docs/catalog-api/cookbook/auto-apply-discounts)
supports item/category sets; [Square time-based discounts](https://developer.squareup.com/docs/catalog-api/cookbook/auto-apply-discounts/timeframe-discounts)
supports recurring windows. Adapt explicit reviewed selection with exact NPR/BS
semantics; no automatic stacking. This extends existing BasketService/offer entry,
not a new subsystem. Alternative quantity groups and other checkout/manual/approved
workflows remain B8b2b; C–H remain active.

Add offer_kind items: one target rule selects up to100 specific items and/or20
categories, unioned without double counting. Entire matching source lines receive
fixed NPR once or percentage up to100, capped to matched base, preserving unmatched
tax/prices. Fixed amount above eligible base fails; whole bill stays positive.
Item/category references must be active and tenant-owned. Categories resolve live
membership; canonical snapshot/fingerprint freezes selection, names and actual
matched items with units/kinds. Quantity conversions remain normal POS behavior;
category monetary discounts may span different base units. Existing quantity
bundle/buy/get semantics stay unchanged.

Optional starts_time/ends_time HH:MM pair and weekdays0(Sunday)–6(Saturday) use
Asia/Kathmandu clock. Start inclusive, end exclusive; equal times invalid; empty
weekdays means every day. Crossing midnight uses starting weekday. Clock selection
must use current Nepal BS business date, preventing backdated window bypass. BS
validity remains independently checked. Snapshot records settings and current
business date, not changing minute/seconds; posting rechecks expiry under mutation.
GET exposes availability; selected unavailable offers remain reviewable/removable.
UI offers clear day/time setup, overnight explanation and refresh availability.

Files: BasketService, BasketController, additive offer migration, BasketOfferTest;
BasketOffers.tsx/tests, i18n, existing Pos selector/preview. No dependencies, new
services, dairy changes, Git mutations or agents. Execute inline in approved ledger.

- [x] RED selected union/category scope, mixed taxes/unmatched rows,100% target,
  original returns, current membership/fingerprint, schedule boundaries/overnight/
  backdate/retry tests and guided frontend payload/availability tests.
- [x] Canonical selected allocation and schedule guards with additive migration.
- [x] Guided item/category and weekdays/time entry, availability and Nepali copy.
- [x] Target/full checks, own development migration, synthetic390px browser proof
  and evidence. Keep B8b2b/C–H active.

B8b2a evidence (3 October):12 offer tests/246 assertions; full backend88 tests/
1734 assertions on isolated business_book_testing (MariaDB10.4.19, target8.4
unverified). Frontend19 files/43 tests; explicit Pint, lint, TypeScript/build pass.
Main1660.87KB/gzip378.59KB remains performance gate. Durable artifacts/b8b2a-*
logs include RED4 backend/2 UI cases, final checks and development-only additive
migration. Browser390px Nepali offer3 QA Saturday selected B8b2a saved20%, QA Juice
plus QA Drinks union, Saturday00:00–23:59. Preview2.501bottles250.10 + ordinary
service150.00 −50.02 =350.08; overlap counted once, width390/document390. Screenshots
artifacts/category-saving-mobile.jpg and artifacts/nepal-time-offer-mobile.jpg.
Browser preview only; no financial posting. Temporary counter cart cleared/tab
closed; setup retained, viewport restored. Backend checks include100% eligible
goods, original stock/source return after changed config, foreign/archived category,
live membership, inclusive start/exclusive end, overnight weekday, backdate denial
and successful original UUID replay after expiry. Alternative quantity-group
matching, other checkout/manual/approved workflow selection and C–H remain active.

### B7 execution — graduated/slab quantity pricing

Research: [Zoho Billing pricing models](https://www.zoho.com/us/billing/help/product-catalog/plans/pricing-models.html)
and [Stripe graduated pricing](https://docs.stripe.com/billing/subscriptions/metered-billing/thresholds)
distinguish one volume rate for all units from separate rates for each range.
Adapt the calculation to our one-time NPR billing; no provider integration.

Add list `pricing_scheme` volume(default)/slab. Each slab item needs minimum0;
range starts are inclusive boundaries in thousandths, each next start ends the
preceding range. Sum individually rounded range gross amounts. Example: first
2.500kg at100.25, next0.001kg at90.35 gives250.63+0.09=250.72.
Never average rates. Agreed party price still wins; absent item rules use normal
adjustment. Lists retain channel/date/unit/role/version/retry guards.

PartyService supplies exact quantity segments; scalar lookup at quantity0 gives
first rate only. POS combines item quantities before range splitting, saves
normal bill lines and per-position measurement/pricing snapshots. Fingerprint
covers ranges; changed setup requires preview. Amount entries crossing different
rates fail for quantity review, matching existing volume guard. Expanded bill
limit remains100 lines. Canonical stock/payment/cancellation/return paths apply.

Reviewed manual sale/purchase/quote/order prices split normal rows into range
quantities on explicit Apply. Preserve taxes/notes and allocate fixed discounts
exactly across split rows; percentage discounts remain percentages. Overlarge
discounts or >100 expanded rows leave entry untouched and show correction.
Entered prices and accepted documents stay until explicit application.
Duplicate item rows consolidate before splitting when tax/percentage settings
agree; fixed discounts add together. Conflicting bookkeeping settings require
review rather than silent tax/discount replacement.

Editor/CSV supports scheme and explains both methods in English/Nepali. Legacy
API inputs retain existing scheme on update; new lists default volume. CSV
omissions keep scheme; exports include it. No new dependencies/services/Git.

- [x] RED backend fractional thresholds/stock/reversal/scope/race/CSV tests.
- [x] Migration, shared pricing segments and POS/suggestions/CSV integration.
- [x] UI split/discount tests, guided scheme, cart/bill breakdown and translations.
- [x] Full checks/mobile evidence; keep basket pricing and C–H active.

B7 evidence (3 October): fractional/scope/CSV tests first failed; canonical
duplicate-item restriction and lost measurement note then failed meaningful
regressions before fixes. Full backend76 tests/1488 assertions on isolated
business_book_testing (actual MariaDB10.4.19); frontend17 files/35 tests.
Explicit Pint, lint and TypeScript/build passed. Gzip372.99KB warning remains.
390px browser POS showed2.500kg100.25 plus0.001kg90.35, total250.72, with390/390
width. Manual review retained320.35 entered price until Apply, then produced the
same two rows/total. Saved synthetic QA CSV slab B7 list2. No browser posting.
Inspected artifacts/slab-pricing-mobile.jpg; viewport reset after verification.
Basket offers and C–H remain required. MySQL8.4/native/hardware/performance gates
remain unverified.

## Measurement setup expansion — verified 4 October 2026

User clarified that each niche must choose available methods/units in setup and
support shop-defined units when needed. Six existing methods remain item-scoped:
quantity, amount, fixed pack/conversion, length, area and volume. Expanded15 to33
named base units: count/pair/dozen; metric/avoirdupois weight; litre/ml/cl and
separate US/Imperial gallons; metric/international lengths including yard;
square/cubic mm/cm/m/in/ft/yd and board foot. Regional measures remain explicit
item conversions, never guessed formulas or automatic local conversion.

Product, offer-choice and scale-format selectors share grouped English/Nepali
options. POS filters compatible families and dimensional units from server
configuration. All factors are integer ratios; conversion rounds once to0.001
billed base unit. Zero-rounded, oversized and incompatible quantities rejected.
Existing stock unit freeze, trusted totals, source snapshots, original retry and
return/cancellation paths remain authoritative. No migration/dependency added.

Research: [Lightspeed weighted products](https://x-series-support.lightspeedhq.com/hc/en-us/articles/25534180905243-Creating-and-selling-weighted-products-in-Retail-POS-X-Series)
supports fractional quantities; [NIST conversion factors](https://www.nist.gov/pml/special-publication-811/nist-guide-si-appendix-b-conversion-factors)
supports named standards and rational conversions. Expanded unit families and
grouped selectors are this app's adaptation, not universal vendor parity.

Verified: full104 backend tests/2339 assertions;24 frontend files/61 tests.
Final affected13 UI tests, lint, TypeScript and production build pass after
translation label repair. Changed-path Pint passes. Bundle1676.52KB/
382.71KB gzip remains large. Evidence artifacts/measurement-*.txt.

390px phone: six groups/33 options; isolated QA Glass Branch item8 configured
sq_cm,10 per base unit and Small sheet=650. POS32.5cm ×200mm ×1 previews
650.000sq_cm andNPR6500. Screenshot glass-measurement-preview-mobile.jpg;
390px document width. Only item metadata saved; no bill/payment/stock/opening
posting. Cart cleared and viewport restored. User guide section4 updated and
browser content verified. C–H remain active; next C1 staged fulfilment.

## C1 staged fulfilment — research, design and ordered execution

4 October 2026. Required next batch, not implemented by the measurement step.
Run inline in this checkout; no Git mutation, subagents or new dependencies.
Keep C2–C6 and D–H in scope. This section defines C1; green measurement tests
do not prove any C1 functionality.

### Evidence and existing gap

[Zoho Inventory order management](https://www.zoho.com/us/inventory/help/sales-orders/sales-order-managing.html)
tracks invoice/package/shipment progress separately and supports partial bills.
[Zoho ERP sales orders](https://www.zoho.com/en-in/erp/help/sales/sales-orders/create-sales-orders.html)
allows multiple packages, partial shipments and multiple invoices. It distinguishes
package preparation from physical stock reduction on shipment.
[Odoo19 invoicing policies](https://www.odoo.com/documentation/19.0/applications/sales/sales/invoicing/invoicing_policy.html)
documents ordered-quantity and delivered-quantity billing, partial deliveries and
backorders. Odoo19 search content verified; full-page fetch failed. Do not present
vendor accounting internals as established by these pages.

Current WorkflowService.bill posts all frozen lines once and marks converted,
using one document_id. Status fulfilled and printed delivery slips are presently
nonfinancial labels, without physical quantity movements. DocumentService posts
stock on each bill and reverses document-linked movements on cancellation.
InventoryService.reverse preserves only existing document/count/opening source
IDs. C1 therefore requires explicit stage links, exact bill allocations and
cleanup integration. Partial invoices alone do not complete staged fulfilment.

### User flow and default policies

Keep current instant full-bill flow for existing orders. A new sales/purchase
order can choose staged delivery/receipt and billing. Freeze policy at first
stock or financial activity. Accepted quotes create orders before staging.
Mobile detail shows ordered, delivered/received, billed and remaining quantities
per frozen line, then activity cards. Actions: Deliver goods / Complete service,
Receive goods, Review bill, Return unbilled goods and Cancel action. BS date,
quantity and optional reference/notes only; journals stay internal.

C1a: delivery/receipt before billing, multiple partial actions, multiple bills,
unbilled stock returns, original-price billed returns and guarded cancellation.
C1b: invoicing ordered quantity before physical delivery/receipt, optional
packages, separate packed/shipped/delivered state and backorder visibility.
C1b remains required after C1a; do not relabel C1a as complete competitor parity.
Packing alone has no stock effect. Partial completion requires real quantity;
status buttons must not fabricate delivery or financial completion.

Stage sale credit paths require an identified customer. Legacy walk-in instant
bills retain existing full-payment guard. Purchase orders require existing own
supplier. Service completion uses quantities with no stock movement. Branch
membership remains direct; no automatic cross-branch stock transfer.

### Storage, posting and reversal contract

One FulfilmentService under backend/app/Service owns staged domain rules.
Reuse WorkflowService, DocumentService and InventoryService for their existing
responsibilities. New tenant-owned stage headers/lines and bill-allocation rows
use composite tenant foreign keys to workflows, documents/lines, items and stage
sources. Frozen workflow line position identifies duplicate item lines; never
join allocations by item ID alone. Persist stage version, actor, BS date,
status/reversal links, exact qty/cost and source snapshots. Add nullable
fulfilment_line_id to stock_movements and preserve it on reverse. A new invoice
joins its exact source allocations; no public trusted-data override.

C1a sale delivery: issue stock once at current moving-average cost; internally
transfer inventory to a delivered-awaiting-bill asset. Invoice recognition moves
the allocated frozen cost from that asset into COGS and posts existing revenue,
tax, receivable and payment. Purchase receipt: receive stock once at frozen
ordered cost; offset received-awaiting-bill liability. Supplier bill clears that
liability and posts recoverable tax/payable. Nonrecoverable tax remains in cost.
Create/backfill these system accounts idempotently for this app's tenants using
a migration; never user-selected account sides. Zero-cost movements are valid,
without an empty/unbalanced journal.

C1b bill-first stock remains physically unchanged until dispatch/receipt.
Later movement consumes previously billed capacity and records its cost exactly
once. Explicit delivered-cost allocations are mandatory: existing bill returns
must not receive goods that never left stock. Cancelling a bill with dependent
physical activity requires safe reverse order, never silent quantity reset.
DocumentService.cancellation/returns delegate stage-specific allocations to
FulfilmentService inside the same tenant transaction; source-dependent guard
remains stricter where required.

Partial money policy must be proven before financial staging ships. Independent
rounding of gross and multiple discounts can produce a negative partial net;
independent tax per invoice can differ from the single quoted tax by a paisa.
RED cases must cover both, not merely whole-number quantities. Source
bookkeeping allocation: cumulative frozen net, tax, line discount and invoice
discount each scale by cumulative billed qty; derive gross as net plus discounts.
Each partial is cumulative minus already allocated; final stage consumes exact
residual. Components stay nonnegative and sum to original agreed components.
Display source-order allocation/rounding explicitly; do not silently claim each
partial tax/gross equals fresh unit-price arithmetic. Preserve original rates and
provenance. APP-SPECIFICATION section6.1.1 records this pending extension as an
explicit bookkeeping exception; ordinary bill arithmetic stays unchanged.
Review/print must disclose source allocation and fresh-arithmetic differences;
reports consume stored components. Supplier pending-cost versus allocated-base
penny variance uses existing inventory gain/loss with no second stock movement.
Prove these cases and safe reversal before enabling financial staging. Statutory
Nepal invoice certification remains a separate release gate.

Allocate stage cost cumulatively from each delivery/receipt with exact residual.
Never rerun current price lists/offers on accepted order quantities. Named offer
provenance remains attached; a partial slip must describe only its allocated
saving, not claim a complete bundle was delivered. No JavaScript/PHP floats.

Unbilled sale return receives the original stage cost and reduces pending-goods
asset; available delivery capacity reopens explicitly. Unbilled supplier return
clears original pending liability, issues actual moving-average inventory and
posts existing gain/loss variance if costs changed. Billed returns retain source
bill totals and posted-history semantics; a credit note does not silently reopen
an order for replacement. All return/cancel quantities exclude already reversed
actions and obey original-source chronology. Shipment cancellation is blocked
while active invoices consume it. Bill cancellation releases allocations only
after existing payment/return guards pass. Late inventory activity still blocks
historical reversal; use an explicit return, not unsafe snapshot overwrite.

All mutations use original UUID+payload replay, tenant lock, current membership,
role/ownership, optimistic workflow/stage versions, BS period/opening/date gates,
item kind/base-unit snapshots and exact quantity limits. Preview includes frozen
order/stage/version/selection/date/cost/offer context. Posting requires current
review proof, including same-total changes. Failed/uncertain UI saves retain entry
and lock edits until original retry resolves. No API response financial cache or
offline posting.

### Ordered implementation and verification

1. C1a RED tests in tests/Feature/FulfilmentTest.php: staged sale/purchase/service,
   fractional partials, pending stock/clearing balances, bill limits, agreed offer
   tax and residual pennies. Include standalone legacy full bill regression.
2. Migration: stage/line/bill links, stock source FK, policy and pending system
   accounts. Verify real database composite FKs/uniques and rollback cleanup on
   business_book_testing; do not migrate real/dairy data. Test existing-tenant
   account backfill and old document linkage.
3. FulfilmentService delivery/receipt/prebill return/preview; controller whitelist
   and routes under existing tenant middleware. Extend replay role resolution for
   new operation keys; never replay after membership/role revocation.
4. DocumentService trusted staged posting plus canonical cumulative allocation,
   workflow progress/history, cancellations and existing billed returns. Negative
   stock, stock cost, VAT, later movement and period locks remain guarded.
5. C1a security/race RED cases: foreign branch/party/stage/document, cashier other
   order and purchase denial, partial overdelivery/overbill, stale version/proof,
   retry after lost reply, concurrent terminal quantities, zero-cost and rollback.
6. Guided Ionic fulfilment section using existing Workflows and reusable daily
   fields/review; English/Nepali labels, selected quantities, remaining units,
   payment proof, original retry and printable stage slips. UI tests cover stale
   review/failed save and source/version changes. No misleading final bill button.
7. Full targeted/regression checks;390px sales and purchase proof. Verify physical
   stock moves once while multiple bills/returns/cancellations reconcile exact
   balances. Update public manual only for actually enabled C1a flows.
8. C1b RED+implementation for billing-before-delivery/receipt, package/dispatch
   state, dependent return/cancel limits and backorder print. Repeat meaningful
   financial/security/race/mobile checks; only then mark all C1 delivered.

Current state: C1a1 physical fulfilment, C1a2 exact staged billing/returns and
C1a3 Ionic entry/progress/slips implemented and verified below. C1a live
authenticated browser end-to-end QA remains unverified; 390px visual checks
use isolated posting-disabled fixtures. C1b bill-before-delivery,
packages/dispatch/backorders remain required. C1 overall remains active.

### C1a1 physical fulfilment backend — 4 October 2026

FulfilmentService/controller/four protected routes plus owned stage/line/stock
links now implement reviewed partial sales deliveries, purchase receipts,
service completion, original-source unbilled returns and guarded cancellation.
Stock moves once; pending-goods accounts keep sale cost/supplier receipt value
separate from COGS/party dues until future staged billing. Legacy full billing
is blocked while physical stages are active, preventing a second stock move.
Workflow edits/cancellation and premature fulfilled status are guarded.

Exact costs, final receipt pennies after fractional returns, frozen purchase
VAT treatment, zero-cost/service actions without empty journals, source
archival/renamed display units, stock chronology and original-period locks are
covered. Returns retain recorded tax treatment after tax recording is disabled;
new recoverable receipts still require it. HMAC review proof covers private
stock context without exposing cashier cost. UUID replay rechecks membership,
role and original actor; composite database FKs reject foreign branch links.
Existing-tenant pending-account backfill preserves custom code collisions.
Schema rollback refuses to erase fulfilment history.

Fresh full backend118 tests/2726 assertions (250839ms), including14 new cases.
True two-process terminal delivery: one fulfilled/one conflict, stock and journal
once. Explicit scoped Pint and final tax-edge Pint pass. Web build passes with
existing1676.52KB/382.71KB gzip chunk warning. Prior frontend61 tests remain
unchanged; this batch adds no staged UI. Guide sections10/18 clarify current
full-bill screens and pending partial screens. Only isolated business_book
received additive migration; business_book_testing used for destructive checks.

Evidence: artifacts/c1a1-backend-final.txt, c1a1-race-check.txt,
c1a1-pint-final.txt, c1a1-tax-pint.txt, c1a1-web-build.txt and
c1a1-development-migration.txt. Initial endpoint/date/archive/rounding/period/
tax RED evidence retained. This backend step does not complete C1a or C1.

### C1a2 billing and C1a3 guided Ionic flow — 4 October 2026

Owned bill allocations and trusted server-prepared document posting now bill
only active completed quantities. Same-item order positions stay distinct.
Frozen source net/tax/discounts allocate cumulatively and consume exact final
residuals. Purchase receipt-cost penny variance clears through existing gain/
loss; billing never repeats stock movement. Original offer provenance survives
disabled offers/current-price changes. Billed returns use original document
amounts/costs and track returned quantities separately from capacity.
Cancellation releases allocations only after dependent bill/return/payment,
source-period and stock guards pass. Copied bills clear source discounts for
fresh canonical review. Cashier costs stay private; replay rechecks membership
and parent ownership. Real two-process terminal bill permits one post only.

Ionic quantity forms, physical/source-return/bill review, payment terms,
original retry, progress/history and actual-action slips enabled. Ordinary
orders retain legacy full billing until staging begins. Active stages hide
unsafe full-order controls. Invoice/review source notes explain cumulative
amounts, offer allocation and rounding. English/Nepali entry labels included.

Checks: full130 backend/3102 assertions, targeted26/763, frontend71/28 files,
scoped Pint, types/lint/build pass. 390px bill review and Nepali receipt/slip
visual proof uses sample records with posting disabled; live authenticated
end-to-end staging QA remains unverified. Own additive migration applied;
test-only destructive checks guarded. Guide sections8/10/14/18 reflect enabled
delivery-first work. Detailed evidence in BUILD-PROGRESS.md/artifacts.

C1a implementation delivered with the live QA limit above. C1b remains required
before marking C1 complete; C2–C6 and D–H/native/provider/release work stays active.
## C1b direct billing-first backend checkpoint — 4 October 2026

Odoo's ordered/delivered quantity policy is now verified through its full
[official documentation source](https://raw.githubusercontent.com/odoo/documentation/19.0/content/applications/sales/sales/invoicing/invoicing_policy.rst),
recovering the earlier failed HTML fetch. Full [Zoho packages](https://www.zoho.com/us/inventory/help/sales-orders/packages.html)
and [manual shipments](https://www.zoho.com/us/inventory/help/sales-orders/shipments.html)
pages verify multiple packages and separate shipment/delivery states. These
workflow facts do not establish vendor journal internals.

Our direct backend now supports reviewed ordered partial billing before actual
stock/work fulfilment, deferred pending value, exact billed-source delivery/
receipt/work, explicit unfulfilled credits versus original physical returns,
and dependent reversals. Owned schema, immutable policy, residual pennies,
fixed recoverable VAT and original costs are covered. Zero-paisa credits retain
future fulfilment value; cumulative credits ahead of a physical rounding target
carry to the exact final residual. No provider shipment integration is claimed.

Final targeted33/938 checks and earlier full151/3599 evidence are detailed in
BUILD-PROGRESS.md. C1b Ionic forms, package states/customer delivery, backorder
prints, new terminal races and revoked replay cases remain required. C1 remains
in progress; C2–C6 and D–H remain in scope. Guide edition3 embeds within the app
and adds separate country/required phone signup instructions; billing-first
still appears pending until its controls ship.
