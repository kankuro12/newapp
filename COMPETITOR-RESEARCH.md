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
| 11 | Quotations/estimates and acceptance | T,V,M,Z,X | Missing; next sales workflow |
| 12 | Sales orders and outstanding orders | T,S,Z | Missing; follows quotation |
| 13 | Purchase orders and conversion to bill | T,S,V,M,Z,X | Missing; same order workflow |
| 14 | Delivery note / goods receiving note | S,V | Missing printable linked view; separate physical fulfilment later |
| 15 | Partial fulfilment / progress invoices | Z | Missing; staged orders extension |
| 16 | Party credit limits and payment terms | S,V,M | Missing |
| 17 | Item/customer price lists | V,Z | Missing |
| 18 | Repeated/recurring sales | Z | Missing |
| 19 | Monthly salary/rent expense and payable | Recurring expense: Z | Existing owner-requested flow; full payroll separate |
| 20 | Reminders, follow-up date and history | T,V,M,K,Z,Q,X | Missing in-app follow-up; sending requires provider |
| 21 | Party statements, aging and overdue lists | T,S,V,M,K,Z | Reports exist; collection-focused screen pending |
| 22 | Search and barcode keyboard entry | T,V,M | Name/SKU lookup exists; exact barcode entry pending |
| 23 | Barcode label printing | T,V,M | Missing |
| 24 | Stock balances, value and low-stock alerts | T,S,V,M,Z,X | Existing |
| 25 | Stock counts, adjustment and correction | T,S,Z | Existing |
| 26 | Item categories, groups, photos | T,Z | Missing |
| 27 | Reorder to supplier purchase order | Z | Low-stock list exists; order shortcut pending |
| 28 | Multiple units / pack conversion | T,V | Missing |
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
| 49 | CSV/master import and data migration | T,S | Missing; preview then atomic import |
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
2. Pending: quotes, sales/purchase orders, conversion to reviewed bills and linked delivery printouts.
3. Pending: in-app collection/payment follow-up, overdue view and copyable reminder/statement.
4. Delivered: exact SKU entry in POS search. Pending: embedded scale barcodes and printable labels.
5. Pending: previewed CSV party/item import with duplicate and rollback checks.
6. Pending: bank CSV matching and statement reconciliation; no duplicate posting.
7. Pending: BS payment terms, credit limits and party/item prices.
8. Pending: sales/purchase analytics, cash-flow view and budgets.
9. Pending: simple approval and reimbursable expense flow.
10. Pending: recurring sales with controlled generation and duplicate protection.
11. Pending: item categories/photos and reorder shortcuts.
12. Pending: staged fulfilment, unit conversion, batch/serial/expiry and landed costs; each requires stock/reversal tests before the next.
13. Pending product extensions: project/time, asset depreciation, cheques, portals, loyalty/catalogue and custom reporting fields.
14. Configuration-dependent: real messaging, payment links, bank feeds, OCR/AI and Nepal tax submission. Build only against verified providers and authorised credentials.
15. Delivered web extension: eight industry POS profiles, branch setup with separate protected books/stock/cash, restaurant waiter/kitchen tickets, and salon/barber scheduling. Foreground refresh every two seconds. Pending: shared branch transfers/consolidated reporting, FX, manufacturing, customer self-ordering and advanced restaurant split bills. See INDUSTRY-POS.md.
16. Release checks: translations, backup/restore rehearsal, target MySQL 8.4 and physical Android/iOS build/device validation.

This ledger distinguishes requested roadmap from delivered software. Existing features are not rebuilt. Research does not certify production readiness, Nepal tax compliance, provider coverage or feature parity with enterprise editions.
