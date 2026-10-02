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
| General store / mini-mart | [Loyverse](https://help.loyverse.com/help/how-add-items-loyverse-back-office), [Vyapar](https://vyaparapp.in/business-management-software) | Product codes, variable quantity, store inventory, barcode entry, prices/discounts, purchase/sale bills, returns, dues, low stock, reorder, units, batches/expiry, warehouse and reports. | Counter, exact SKU, units, stock/dues exist. Categories, price lists, labels, import, terms and batch stock remain to build. |
| Meat / fish / produce | [Lightspeed weighted products](https://x-series-support.lightspeedhq.com/hc/en-us/articles/25534180905243-Creating-and-selling-weighted-products-in-Retail-POS-X-Series), [embedded barcodes](https://x-series-support.lightspeedhq.com/hc/en-us/articles/25534108363547-Adding-and-editing-multiple-scannable-barcodes) | Weighted items, per-weight pricing, variable-weight/price barcode labels, scanner entry, inventory and unit conversion. | Weight/amount entry and exact conversions exist. Add configurable scale-barcode parsing, wastage reporting and labels; actual scale/printer needs hardware. |
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
| A | Quotes, sales orders, purchase orders, approved-price conversion, simple job specification/due/status, printable quote/job/delivery slips | In progress; first shared niche workflow |
| B | Follow-up tasks, overdue collection, BS terms/credit limits, party/item prices, categories, reorder, previewed CSV imports, barcode labels/scale parsing | Build; prior shared feature backlog retained |
| C | Staged fulfilment, batches/expiry/FEFO, serial/warranty, landed costs, material issue/return, BOM/production/waste | Build; stock/reversal/concurrency evidence required for each |
| D | Laundry garment/rack tracking, tailoring measurement templates/fittings, cake/restaurant modifiers, job assignments/checklists/time, installation visits | Build using shared workflows with niche fields |
| E | Rental asset availability/returns/damage, refundable deposits, duration pricing, recurring customer orders, containers, packages/loyalty | Build; distinct accounting/availability semantics |
| F | Split restaurant bills/payments, customer catalogue/order portal, scoped customer access, approvals, bank matching, cash flow/budgets, asset depreciation/cheques | Build; remains part of extended feature ledger |
| G | Messaging/payments, hardware, maps/GPS, live rates, OCR, government submissions, external imports | Integration/domain verification; never mock a successful provider result |
| H | MySQL8.4, translations, backups/restore, native auth/builds and physical devices | Release gates retained |

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

- [ ] Backend failing tests: no financial effects, approved snapshot/order/bill,
  original UUID replay, changed payload/version, wrong tenant/party/role, expiry,
  stock/locked-period rollback, conversion once and original returns.
- [ ] Add table/service/controller with composite ownership and atomic conversion;
  run focused tests and explicit-file formatting.
- [ ] Add mobile editor/list/detail/print and reviewed bill conversion using existing
  picker, exact preview, EntrySteps and safe retry; run frontend checks/build/lint.
- [ ] Apply additive migration only to dedicated development DB; verify browser
  quote → order → job state → bill and phone print/layout with synthetic data.
- [ ] Update evidence/feature status; continue remaining batches. Do not mark the
  full goal complete on Batch A evidence alone.
