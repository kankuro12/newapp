# Competitor research and sequential feature ledger

Research date: 2 October 2026. Target: everyday bookkeeping for Nepal small businesses; Ionic React web/PWA first, shared mobile source later. Owner requested all researched features listed and implementation one by one; mobile data entry takes priority.

## Method and evidence limits

Nine vendors examined using their own feature pages and help documentation. Entries describe advertised capabilities, not independent performance, usability, security or legal certification tests. Editions, countries, paid tiers and integrations differ. An unmentioned feature means not confirmed in these sources, not absent from the product. Prices, promotional statistics and vendor comparisons of rivals were deliberately not used. Swastik's current public page includes old technology references: treat it as a feature catalogue, not a verified current technical specification.

## Nepal-first comparison

Primary benchmarks: Karobar for simple Nepali shop entry, Tigg for Nepal web accounting, Swastik for local trading/inventory depth. Other vendors supply secondary workflow ideas; they do not determine tax, calendar or payment defaults.

[Karobar's official site](https://www.karobarapp.com/) confirms sales, purchases, expenses, party ledgers, stock, reports, web access, staff access, receipt images and reminders. Its [publisher's Android listing](https://play.google.com/store/apps/details?id=com.bytecaretech.merokarobar), updated 9 August 2026, additionally advertises quotations, Nepali calendar/language, multiple businesses/banks, item categories, retail/wholesale prices, transaction links, Excel import, offline entry, app lock, backup, reminders and utility calculators. These are publisher claims, not independent verification. For Business Book: quotes, local collection follow-up, flexible prices and easy mobile entry lead; online-only financial writes remain the chosen safety model.

Karobar adds three catalogue entries to the union below: **64 offline financial writes** (conflicts with current online-only contract), **65 app lock** (native device release work), **66 standalone business/interest calculators and greeting cards** (secondary utility; exact decimal inputs needed). Karobar also supplies local evidence for entries 1–4, 11, 16–17, 20–21, 24, 26, 33, 43, 49–51 and 63.

## Eight additional competitors

| Product and official source | Advertised capabilities | Lesson for this app |
|---|---|---|
| [Tigg features](https://tiggapp.com/features), [Nepal product](https://tiggapp.com/) | Quotes, sales/purchase orders, billing, allocation, returns, inventory locations/transfers, landed costs, bank-statement matching, tasks, approvals, attachments, imports, permissions, custom fields/templates, retail barcode/receipt tools, restaurant tables/kitchen/split bills, SMS, cheque register; homepage additionally advertises production, AI capture and Nepal CBMS billing. | Closest local workflow benchmark. Keep BS dates; document-to-bill flow and settlement must connect. Certification claims do not transfer to Business Book. |
| [Swastik/HiTech features](https://www.hitechnepal.com.np/swastik-business-accounting-software-features.php) | Orders, delivery/receiving documents, purchase/import costing, manufacturing/BOM/assembly, locations, BS/AD dates, credit limits/days, aging, price history, customer/product profitability, reports/export, batch/expiry/serial controls, tax registers, permissions, audit locks, backup/restore, document design and company consolidation. | Strong local inventory/reporting benchmark; adapt useful actions into simple forms. Avoid exposing its manual-voucher complexity. |
| [Vyapar business tools](https://vyaparapp.in/business-management-software) | Billing/POS, estimates, delivery notes, customer/item rates and discounts, unit conversion, barcodes, batch/expiry, stock/reorder alerts, warehouses, credit control, party balances, cash-flow/financial reports, roles and device access; Indian GST workflows. | Fast shop entry, visible payment choices and stock awareness. Indian GST is a separate jurisdiction. |
| [myBillBook](https://mybillbook.in/), [barcode help](https://knowledge.mybillbook.in/en/help/articles/3073415-how-to-generate-barcode-for-items-using-mybillbook) | Quotes, purchase orders, POS/barcode labels/scanning, batch/expiry, reminders and payment links, live ledgers, bank matching, catalogue/loyalty, cash-flow/tax reports, credit limits, manufacturing, multiple devices/users; Indian GST/e-invoicing/e-way bills. | Combine item entry and collection follow-up. Barcode hardware should work without camera permissions. |
| [Khatabook](https://khatabook.com/en/), [help](https://khatabook.com/help/en/) | Multiple businesses, customer ledger entries, reminders, payment collection/QR, reports, language options, sync/backup. Help confirms reminder and report workflows. | Make the party ledger easy to understand and follow up. UPI/provider coverage is not assumed in Nepal. |
| [Zoho Books features](https://www.zoho.com/us/books/accounting-software-features/), [quote conversion help](https://www.zoho.com/ca/books/help/quote/convert-to-inv.html) | Quotes/orders/bills, progress invoices, recurring transactions, reminders/payments, approvals, receipt capture, bank rules/reconciliation, price lists, reorder tools, projects/timesheets/budgets, portals, inventory extensions, attachments, audit/locks, reports, AI and integrations. | Reuse saved details when creating bills; make automation reviewable. Advanced/provider features depend on country, plan and configuration. |
| [QuickBooks accounting](https://quickbooks.intuit.com/accounting/), [bank help](https://quickbooks.intuit.com/learn-support/en-us/quickbooks-online/banking/), [reconciliation help](https://quickbooks.intuit.com/learn-support/en-us/help-article/statement-reconciliation/reconcile-account-quickbooks-online/L3XzsllsK_US_en_US) | Invoice/payment tracking, reminders, income/expense reports, bank imports/connections/categorisation, receipt capture, matching, period reconciliation and saved reconciliation reports. | Importing a bank line must not duplicate an already recorded payment. A reconciled period needs a visible difference and audit history. |
| [Xero all features](https://www.xero.com/us/accounting-software/all-features/), [reconciliation](https://www.xero.com/us/accounting-software/reconcile-bank-transactions/) | Quotes, invoices/reminders/payments, purchase orders, bills, expense claims, bank feeds/matching, inventory, projects/time/budgets, contacts, files, analytics, fixed assets, multicurrency, app ecosystem and mobile access; US payroll through Gusto; AI advertised in beta. | Show receivables, payables and cash together. Payment/payroll services and currency support require separate local validation. |

## Consolidated feature list

This is the deduplicated union of capabilities confirmed above, grouped into implementation-sized workflows. It is not a claim that every vendor supports every row. Source letters: T=Tigg, S=Swastik, V=Vyapar, M=myBillBook, K=Khatabook, Z=Zoho, Q=QuickBooks, X=Xero. Baseline describes inspected Business Book code before this expansion.

| # | Workflow | Evidence | Business Book baseline / next action |
|---|---|---|---|
| 1 | Mobile entry, clear labels, grouped fields | V,M,K,X | Delivered party-first optional details, bill steps and mobile POS product/cart flow; physical device review pending |
| 2 | Unified customer/supplier party | T,V,K,X | Existing; employee/rent roles also supported |
| 3 | Multiple businesses and switching | S,K | Existing, isolated membership |
| 4 | Goods/services and units | T,V,Z,X | Delivered base units, exact compatible conversion, measurement modes and configured custom packs |
| 5 | Sale and purchase bills | All except K's basic ledger focus | Existing |
| 6 | Expense and receipt attachment | T,Z,Q,X | Existing private upload; OCR separate |
| 7 | Full/partial/later settlement | T,V,M,Z,Q,X | Existing |
| 8 | Allocation, advances and separate party dues | T,S,Z | Existing |
| 9 | Sales/purchase returns and refunds | T,S,Z | Existing linked reversal paths |
| 10 | Discounts and bookkeeping tax | S,V,M,Z | Existing exact arithmetic; tax-inclusive entry pending |
| 11 | Quotations/estimates and acceptance | T,V,M,Z,X | Delivered draft/revision/expiry and staff-recorded acceptance; public approval portal pending |
| 12 | Sales orders and outstanding orders | T,S,Z | Delivered quote conversion, work status and overdue fulfilment filter |
| 13 | Purchase orders and conversion to bill | T,S,V,M,Z,X | Delivered purchase order with reviewed linked purchase bill |
| 14 | Delivery note / goods receiving note | S,V | Delivered order delivery-slip print; separate goods receiving/physical fulfilment pending |
| 15 | Partial fulfilment / progress invoices | Z | Missing; staged orders extension |
| 16 | Party credit limits and payment terms | S,V,M,Z | Delivered BS sale/purchase terms and shared posting credit guard |
| 17 | Item/customer price lists | V,Z | Delivered party rates, shared sale/purchase lists, volume/slab tiers, exact adjustments, BS dates, guided setup/assignment and reviewed bill/POS pricing. Reviewed list CSV import/export delivered. Counter basket/bundle/buy-get/selected-category offers and Nepal-time schedules delivered; alternative/category quantity groups delivered; restaurant/appointment offer checkout implemented (both phone proofs verified); manual/draft/quote/order offers delivered in B8b2b2b with current draft review and frozen approved conversion |
| 18 | Repeated/recurring sales | Z | Missing |
| 19 | Monthly salary/rent expense and payable | Recurring expense: Z | Existing owner-requested flow; full payroll separate |
| 20 | Reminders, follow-up date and history | T,V,M,K,Z,Q,X | Delivered local staff follow-up date/status/history; provider sending pending |
| 21 | Party statements, aging and overdue lists | T,S,V,M,K,Z | Delivered canonical collection list with normal payment, statement and aging links; due-task filter |
| 22 | Search and barcode keyboard entry | T,V,M | Delivered SKU/alternate-code lookup and explicit scan-to-cart; opt-in EAN-13/UPC-A weight/NPR amount formats, checksum, trusted posting |
| 23 | Barcode label printing | T,V,M | Delivered reviewed Code128 labels, item/category/supplier selection, copies and adjustable sheet/roll layouts; physical printer calibration pending |
| 24 | Stock balances, value and low-stock alerts | T,S,V,M,Z,X | Existing |
| 25 | Stock counts, adjustment and correction | T,S,Z | Existing |
| 26 | Item categories, groups, photos | T,Z | Delivered searchable categories and item/POS filters; photos and nested groups pending |
| 27 | Reorder to supplier purchase order | Z | Delivered preferred supplier, target stock, pending-order deduction and reviewed PO; unattended scheduling pending |
| 28 | Multiple units / pack conversion | T,V | Delivered item-enabled exact measurement modes and custom packs |
| 29 | Batch, serial, manufacturing and expiry dates | S,V,M | Missing; stock-model extension |
| 30 | Warehouse/store stock and transfers | T,S,V | Existing contract single location; explicit expansion needed |
| 31 | Landed/import costs | T,S | Missing; requires inventory-cost/reversal extension |
| 32 | Production, BOM and assembly | T,S,M | Missing; separate manufacturing subsystem |
| 33 | Cash/bank account and transfers | T,S,V,Z,Q,X | Existing |
| 34 | Bank CSV import, match and reconciliation | T,S,Z,Q,X | Internal reconciliation exists; statement matching missing |
| 35 | Bank matching rules | Z,Q,X | Missing; review suggestions before accounting entry |
| 36 | Live bank feeds | T,Z,Q,X | Provider agreement/credentials required |
| 37 | Cheques and post-dated settlement | T | Missing; cash changes only on clearing |
| 38 | Payment gateway / QR / payment links | K,M,Z,Q,X | Provider credentials/local availability required |
| 39 | Expense claims and reimbursements | X | Missing; approval and payable link required |
| 40 | Approvals and staff permissions | T,S,V,M,Z | Fixed staff roles exist; approval workflow missing |
| 41 | Period locks and audit history | S,Z | Existing |
| 42 | Guided opening balances | Accounting/import workflows T,S,Z | Existing |
| 43 | Dashboard and financial/stock reports | T,S,V,M,Z,Q,X | Existing |
| 44 | Cash-flow projection | X; cash-flow reporting S,V,M,Q | Missing forecast view |
| 45 | Top customers/items and profitability | S | Missing focused analytics |
| 46 | Sales/purchase trend and price history | S | Missing focused analytics |
| 47 | Budgets vs actual | Z,X projects | Missing |
| 48 | Report tags, custom fields and saved reports | T,S,Z | Missing |
| 49 | CSV/master import and data migration | T,S | Delivered reviewed atomic party/item CSV with mappings, categories/suppliers/POS settings, templates/export, duplicate/stale/rollback/retry checks. Financial/stock migration via canonical posting remains pending |
| 50 | Export, printing and reusable templates | T,S,V,M,K,Z | CSV/printing exists; branding/templates pending |
| 51 | Backup/restore and export ownership | S,K | Operational backup recipe exists; restore rehearsal pending |
| 52 | Customer/vendor portal | Z | Missing separate scoped identity and invitations |
| 53 | Tasks/deals and transaction collaboration | T,Z | Missing; payment follow-up is first task workflow |
| 54 | Projects, timesheets, mileage | Z,X | Missing service-business extension |
| 55 | Fixed assets and depreciation | X | Missing accountant-assisted action flow |
| 56 | Catalogue, loyalty and campaigns | M | Missing retail extension; messaging requires provider |
| 57 | Restaurant tables, kitchen and split bills | T | Separate restaurant product extension |
| 58 | Manufacturing/industry-specific editions | S | Separate product extension |
| 59 | Nepal statutory billing / CBMS | T | Existing release gate; certification/credentials required |
| 60 | India GST/e-way/US tax/payroll | V,M,Z,Q,X | Different jurisdictions; never silently enable for Nepal |
| 61 | Multicurrency and consolidated companies | S,Z,X | Existing contract NPR only; currency/FX accounting expansion needed |
| 62 | AI/OCR and external app/API integrations | T,M,Z,Q,X | Missing; real provider required, no fake AI or automatic posting |
| 63 | Android/iOS and cross-device access | T,V,M,Z,X | Ionic/PWA source exists; signed native builds remain release work |

## Design decisions for implementation

Core daily workflows use existing Laravel services and Ionic components. Every new record is tenant owned; financial conversions use the existing atomic posting/reversal path, integer paisa/quantity and BS dates. Quotes and orders have no ledger/stock effect until a bill posts. External sending, bank feeds, payments, OCR and tax submission require a real configured provider. Industry POS and branch grouping are now authorised and implemented in the web app; detailed sources, scope and checks are in INDUSTRY-POS.md. Currency and other listed extensions remain backlog.

Mobile: party name, phone and role choices first; other identity fields optional and collapsed. Billing: Party → Items → Review/payment, with editable steps and retained values. Desktop retains its single-page editor. No posting while progressing through steps; keyboard Enter must not accidentally post. Save actions must remain reachable above navigation/safe areas. Input keyboards follow data type; BS dates stay BS text/numeric, never Gregorian native date controls.

This adaptation follows [GOV.UK question-page guidance](https://design-system.service.gov.uk/patterns/question-pages/) on related question groups, optional labels, progress/back actions and reuse of entered answers; [W3C form grouping](https://www.w3.org/WAI/tutorials/forms/grouping/) on fieldset/legend semantics; [Ionic input guidance](https://ionicframework.com/docs/api/input) and [MDN inputmode](https://developer.mozilla.org/en-US/docs/Web/HTML/Global_attributes/inputmode) on labels and keyboard hints. These are design recommendations; physical iOS/Android keyboard behaviour still needs device tests.

## Ordered implementation ledger

Keep this list live; only mark completed after checks. No source changes committed or pushed without owner request.

1. Delivered: mobile party/item and Party → Items → Review billing organisation; retained values, validation and responsive checks.
2. Delivered: quotes, sales/purchase orders, frozen-price reviewed bills, job specifications/status and linked quote/job/delivery printouts. Partial fulfilment, receiving stock and public customer approval remain separate extensions. See NICHE-FEATURES.md.
3. Delivered: in-app collection/payment follow-up, due-task filter/history, collection list and aging links. Copyable reminder and provider sending remain pending.
4. Delivered: exact SKU/alternate code lookup and scan-to-cart, configured embedded quantity/amount scale barcodes, reviewed sheet/roll label printing. Pending: direct hardware connection and printer calibration.
5. Delivered: previewed CSV party/item import with mapping, duplicate, rollback and retry checks. Financial/stock migration remains pending.
6. Pending: bank CSV matching and statement reconciliation; no duplicate posting.
7. Delivered: BS payment terms, shared customer credit limits and exact party/item prices. Shared sale/purchase price lists, volume/slab tiers, reviewed list CSV import/export, counter basket/bundle/buy-get/selected-category offers and Nepal-time schedules. Alternative/category quantity groups delivered; restaurant/appointment offer checkout implemented (both phone proofs verified); manual/draft/quote/order offers delivered in B8b2b2b with current draft review and frozen approved conversion.

Price-list CSV research rechecked3 October2026:
[Zoho Inventory import/export help](https://www.zoho.com/in/inventory/kb/price-lists/pl-import.html)
documents sales/purchase files, sample templates, field mapping and preview.
Our implementation combines channels in explicit CSV rows, uses NPR and BS dates,
retains omitted tiers by default and offers reviewed replacement. Verified72
backend tests/1404 assertions,31 frontend tests and390px mobile save/reopen.
No bills, stock movements or payments originate from list import.

Graduated pricing research rechecked3 October2026:
[Zoho Billing pricing models](https://www.zoho.com/us/billing/help/product-catalog/plans/pricing-models.html)
distinguishes volume (one rate for all units) from tiered (separate range rates).
We adapted the latter to one-time NPR bills with integer quantity segments and
separate half-up rounding. This is an adaptation, not a claim about Zoho
Inventory billing behavior. Delivered scheme editor/CSV, POS breakdown and
explicit manual Apply; accepted prices, cancellation and returns remain frozen
to source lines. Verified76 backend tests/1488 assertions,35 frontend tests and
390px mobile preview/manual Apply. Basket offers remain required.

Basket research rechecked3 October2026:
[Square automatic discounts](https://developer.squareup.com/docs/catalog-api/cookbook/auto-apply-discounts)
documents quantity, minimum-order, combination and time-based rules;
[Loyverse discount setup](https://help.loyverse.com/help/how-create-and-configure-discounts)
documents fixed/percentage savings and staff restrictions. B8a adapts whole-basket
minimum spend to NPR, BS validity, one explicit counter selection, percentage cap
and role permission. Canonical tax allocation, immutable offer snapshot and source
returns preserve exact amounts. B8b1 adds explicit-item bundle and buy/get reward
rules, including100% reward discounts with ordinary stock and source returns.
As of B8b2b1, alternative/category quantity groups are delivered. Remaining
B8b2b2a implements restaurant/appointment offer checkout, with restaurant phone
and salon phone proofs plus API/UI checks. B8b2b2b remains reviewed manual/draft/accepted quote/order offers.
Current whole-basket offers must leave positive base; zero-total bills require
separate canonical posting rules. No claim of full promotion parity.

[Square product-set and time rules](https://developer.squareup.com/docs/catalog-api/cookbook/auto-apply-discounts)
document item/category sets, weekdays and recurring periods;
[Square happy-hour example](https://developer.squareup.com/docs/catalog-api/cookbook/auto-apply-discounts/timeframe-discounts)
demonstrates category savings in an active window. B8b2a adapts reviewed selection
to exact NPR, BS and server Nepal-time checks, rather than authorizing from device
clock. Selected category/item union discounts current matched lines once; fixed
amount/percentage up to100 respects eligible value and positive whole bill.
Overnight attribution, backdate denial, expiry recheck and committed UUID replay
are explicit local choices. Other checkout/manual/approved workflows remain
required; vendor parity is not claimed.

[Square product sets](https://developer.squareup.com/reference/square/objects/CatalogProductSet)
support any/all selections and quantities;
[Shopify buy/get](https://help.shopify.com/en/manual/discounts/discount-types/buy-x-get-y)
supports product/collection pools and lower-priced rewards. B8b2b1 adds unit-scoped
alternative/category quantity groups, overlap matching without reused quantities,
cheapest feasible rewards and immutable assignment proof. Category membership is
live; incompatible units excluded. Existing exact tax/stock/reversal rules apply.
Checkout research rechecked3 October2026:
[Loyverse sale discounts](https://help.loyverse.com/help/how-apply-discounts-during-sale)
shows configured ticket/item selection and a separate ticket saving line.
[Fresha client rewards](https://www.fresha.com/help-center/knowledge-base/clients/584-manage-client-rewards)
supports checkout rewards with selected items/services, minimum spend, expiry and
combination limits. B8b2b2a applies existing explicit offers to saved restaurant
tickets and arrived appointments. Trusted proof/BS day/version and old prices
are local adaptations. Actual rewards wallet/points and packages remain batch E.
Verified96 backend tests/2094 assertions,49 frontend tests; restaurant390px Nepali
preview240.10 after10 saving and salon140.00 from150 less10. Stale-tab cleanup
restored browser clicks; both phone proofs pass. No all-device/vendor parity claim.

8. Pending: sales/purchase analytics, cash-flow view and budgets.
9. Pending: simple approval and reimbursable expense flow.
10. Pending: recurring sales with controlled generation and duplicate protection.
11. Delivered: item categories, POS filtering and reviewed supplier reorders. Photos and nested groups remain pending.
12. Pending: staged fulfilment, unit conversion, batch/serial/expiry and landed costs; each requires stock/reversal tests before the next.
13. Pending product extensions: project/time, asset depreciation, cheques, portals, loyalty/catalogue and custom reporting fields.
14. Configuration-dependent: real messaging, payment links, bank feeds, OCR/AI and Nepal tax submission. Build only against verified providers and authorised credentials.
15. Delivered web extension: eight industry POS profiles, branch setup with separate protected books/stock/cash, restaurant waiter/kitchen tickets, and salon/barber scheduling. Foreground refresh every two seconds. Pending: shared branch transfers/consolidated reporting, FX, manufacturing, customer self-ordering and advanced restaurant split bills. See INDUSTRY-POS.md.
16. Release checks: translations, backup/restore rehearsal, target MySQL 8.4 and physical Android/iOS build/device validation.

This ledger distinguishes requested roadmap from delivered software. Existing features are not rebuilt. Research does not certify production readiness, Nepal tax compliance, provider coverage or feature parity with enterprise editions.

Party trading research: [Zoho credit-limit help](https://www.zoho.com/bh/books/help/contacts/credit-limit.html)
describes warning/restriction choices and order exposure;
[Zoho price-list help](https://www.zoho.com/in/books/help/items/price-list.html)
describes customer/vendor pricing; [Tigg purchase-bill help](https://help.tiggapp.com/article/purchase-bill)
describes payment terms and transaction tasks with assignees, due dates and history.
Our adaptation uses a hard limit on posted unpaid sales, explicit BS calendar days,
frozen approved quotes and local follow-ups. Orders do not reserve credit; grouped
price lists and sending remain separate work. These are implementation choices,
not claims that every competitor uses the same rules.

## 3 October2026 — regular sale/draft and approved quote offers

Delivered B8b2b2b: selected named offers on manual sale/draft and editable quote/
sales-order forms; current review and full saved-term check before draft posting.
Approved quote/order conversion freezes discount allocation and source proof,
even when active offer later changes. Copy/clone restores pre-offer values and
requires explicit new selection. Internal POS paths pass trusted proof once;
posted returns use original values. Purchases/expenses do not accept offers.

Primary comparison: [Zoho FSM line/transaction discounts](https://help.zoho.com/portal/en/kb/fsm/billing/articles/tax-discount-preferences)
covers estimates, work orders, appointments and invoices.
[Zoho accepted quote conversion](https://www.zoho.com/uk/invoice/help/estimate/estimate-preferences.html)
supports field retention/conversion. Our before-tax-only policy, draft re-review
and frozen approved prices are explicit app choices; after-tax/tax-inclusive
pricing, provider sending and public digital acceptance remain separate work.

Verified102 backend tests/2225 assertions,60 UI tests;390px manual/draft/quote
proofs and new18-section user manual. Guide distinguishes available/planned
features, links from More and includes safe pay-later/retry procedures. Batch B
extension complete; C–H and integration/native release gates remain active.


## Measurement configuration follow-up — 4 October 2026

[Lightspeed weighted products](https://x-series-support.lightspeedhq.com/hc/en-us/articles/25534180905243-Creating-and-selling-weighted-products-in-Retail-POS-X-Series)
documents fractional sale quantities. App now includes33 named standards with
separate US/Imperial gallons, yard dimensions, grouped English/Nepali selectors
and explicit custom pack/local measures. Conversion definitions follow
[NIST conversion guidance](https://www.nist.gov/pml/special-publication-811/nist-guide-si-appendix-b-conversion-factors).
Factors stay rational integers; canonical thousandths and original returns remain.

Full104 backend tests/2339 assertions and61 UI tests pass; final affected13,
lint/types/Pint/build verified.390px glass preview:32.5cm ×200mm =650sq_cm,
10 per unit givesNPR6500, no horizontal overflow. Only isolated QA item metadata
saved; no browser financial posting. Manual section4 updated. Special cutting
formulas, hardware and C–H remain required research/implementation work.

## C1a1 physical fulfilment follow-up — 4 October 2026

Previously reviewed [Zoho order progress](https://www.zoho.com/us/inventory/help/sales-orders/sales-order-managing.html)
and [Zoho ERP partial shipments/invoices](https://www.zoho.com/en-in/erp/help/sales/sales-orders/create-sales-orders.html)
motivate separating physical completion from billing. Current step implements
only reviewed partial delivery/receipt/unbilled-return/cancellation APIs with
owned history, single stock movement, pending-goods accounts and protected
retries. Vendor accounting internals are not inferred from those feature pages.

Full118 backend tests/2726 assertions, scoped formatting and web build pass;
separate-process delivery and existing-tenant migration backfill verified. Guide
clarifies pending partial screens. C1a2 financial allocation/returns and Ionic
screens remain pending; C1b billing-first/packages/backorders remain required.
This is backend progress, not complete C1 or competitor parity. See detailed
contract/evidence in NICHE-FEATURES.md and BUILD-PROGRESS.md.

## C1a delivered-quantity billing and Ionic flow — 4 October 2026

The previously reviewed Zoho order/invoice progress and Odoo delivered-quantity
policies now have delivery-first implementation here: multiple actual physical/
service actions, partial source-priced bills, source returns, linked history,
exact pending-cost clearing and safe cancellation. Ionic forms show available
quantities and require fresh reviewed terms; print shows actual action amounts
or explicit invoice source-allocation differences. Vendor accounting internals
remain unclaimed. Source price/tax rounding policy is this app's bookkeeping
design, separately documented in APP-SPECIFICATION6.1.1.

Full130 backend/3102 assertions and71 UI tests pass; types/lint/scoped Pint and
web build pass. 390px English bill/Nepali receipt/slip checks use isolated
posting-disabled fixtures. Live authenticated staging QA remains unverified.
Manual sections8/10/14/18 updated. C1b ordered-quantity billing before delivery,
packages/dispatch/backorders and remaining C–H features remain required; this
does not claim complete competitor parity or Nepal invoice certification.
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

## C1b security and packages first pass — 4 October 2026

Verified again from full official pages: Zoho supports multiple packages and
separate packed/shipped/delivered phases, manual carrier/tracking and marking
manual delivery undelivered after an error:
[packages](https://www.zoho.com/in/inventory/help/sales-orders/packages.html),
[shipments](https://www.zoho.com/in/inventory/help/sales-orders/shipments.html).
Its [sales-return guide](https://www.zoho.com/us/inventory/help/sales-returns/sales-returns-overview.html)
also separates return authorization, actual receipt, credit note and refund;
credit-only quantities are not received into physical stock. These are workflow
facts. Our held sales and transit journals are local bookkeeping policy, not
claims about vendor journal implementations. Odoo18 returns HTML failed during
this follow-up and is not claimed as fully verified here.

Our new backend first pass now reviews/records immutable packages, manual
shipping, actual delivery, delivery undo and source-dependent cancellation.
Stock moves once at shipment into transit; delivery recognizes agreed sales
base and original shipped cost. Package/source versions bind review; carrier
changes require a new review. Progress distinguishes packed, shipped and
confirmed quantities. Unshipped packing does not protect the stock pool from
other daily sales. Privacy, membership/parent/source replay, foreign branches,
multiple packages, pennies, repeated undo and actual-return dependencies are
covered. Source-bill ownership replay discrepancy was reproduced and fixed.

Actual checks: direct/legacy regression34/1031; package security12/492. Full
backend passed174/4409 (506539ms), c1b5-backend-full.txt. Existing in-app guide correctly
keeps billing-first/packages unavailable until Ionic controls and full gates
ship. Before exposure, implement real undelivered-shipment returns without
pretending goods reached the customer, package terminal races, remaining
zero/mixed-source gates, compact source-aware UI and complete backorder/slip
printing. This is progress on C1; all C2–C6 and D–H remain required. Manual
provider integrations, native releases and statutory gates remain unverified.