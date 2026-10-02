# Acceptance tests — exact numerical and release checks

Transport revision: [MONOREPO-CONTRACT.md](MONOREPO-CONTRACT.md) governs Laravel JSON API + Ionic React. Run PHP checks from `newapp/backend`; mobile checks target Ionic frontend. Add CSRF/session/API error checks during Task01; no business acceptance is completed by scaffold tests.

Each scenario starts fresh unless explicitly continued. Amounts displayed below are NPR; persisted ledger values multiply by100. Quantities persist thousandths. Compare integer paisa exactly, never approximate float assertions. Test on independent MySQL `business_book_testing`; no dairy DB access.

Future implementing agent records actual result/evidence per ID in BUILD-PROGRESS.md. This planning pack does not claim application tests were run.

## A. Environment/authentication

| ID | Setup/action | Required result |
|---|---|---|
| A01 | `php84 -v`, `composer84 --version`, installed Laravel version | PHP8.4, Composer uses PHP8.4, Laravel13.x; no php8.0 fallback |
| A02 | Register with is_platform_admin=true or arbitrary tenant/role fields | User ordinary; unauthorized fields rejected/ignored; no membership privilege escalation |
| A03 | Unverified user visits tenant URL | Email verification required; no business data |
| A04 | Login then logout/reset password | Session regenerated on login; invalidated on logout; reset token single-use/expiry honored |
| A05 | Multiple invalid logins | Fortify throttle; no sensitive account-existence detail |

## T. Tenant and role isolation

| ID | Setup/action | Required result |
|---|---|---|
| T01 | Two tenants, different contacts/items/docs/accounts; request other slug/ID | Nonmember/foreign row404; no metadata or records leaked |
| T02 | TabA form URL TenantA; TabB switches session TenantB; submit TabA | Post remains TenantA if membership valid; never silently TenantB |
| T03 | Insert TenantA doc line with TenantB item/contact/account via raw SQL | Composite FK failure; zero successful cross-tenant record |
| T04 | Cashier requests lookup/item details/dashboard/sales export | Selling price/qty/own sales allowed; costs/margins/full statements/bank balances absent; protected routes403 |
| T05 | Report/export filters include other tenant ID/party/item, malicious sort | Foreign ID404/422; sort allowlist; all rows scoped |
| T06 | TenantA requests TenantB attachment/download/print |404; file not read/streamed |
| T07 | Job runs for TenantA then TenantB in same worker | Explicit context per job, cleared finally; no static tenant carryover |
| T08 | Run tenant model query without CurrentTenant context in CLI | Fails closed, no unscoped financial result |
| T09 | Invitation wrong verified email, expired token, duplicate acceptance, revoked invite | Reject first three unauthorized cases; accepted token cannot create duplicate membership; no plaintext token stored |
| T10 | Owner removes/demotes last active owner | Reject; at least one owner remains; platform admin does not bypass financial membership |

## M. Money and totals

| ID | Input / calculation | Exact expected result |
|---|---|---|
| M01 | Money parse `123.45`, `0.01`, `१२३.४५`, ` 12.5 ` |12345,1,12345,1250 paisa |
| M02 | Inputs `1e3`, `1,000`, `NaN`, `12.345`, blank, negative where forbidden |422/no write; no float/coercion acceptance |
| M03 | multiplyDivide(1,1,2); multiplyDivide(-1,1,2) |1 and -1; ties away from zero |
| M04 | Three lines NPR1 each; invoice discount NPR0.01 | Allocated paisa `[1,0,0]` in stable line order; final bases `[99,100,100]`; total NPR2.99 |
| M05 | Qty0.125 x NPR10, line discount10%, invoice discount0.01, tax13% | Gross125p, line discount13p, base112p, invoice discount1p, net111p, tax14p, line total125p |
| M06 | Original qty3, net base100p, tax13p, COGS40p; three returns qty1 | Base `[33,34,33]`, tax `[4,5,4]`, cost `[13,14,13]`; exact totals100/13/40 |
| M07 | Discount exceeds gross, negative tax, >100 lines, qty>1,000,000, amount caps exceeded | Validation422 and zero effects |
| M08 | Client sends total1 for server total450, or forged stock/paid/due | Server recalculates; stale expected_total rejected with correct preview; no trusted client aggregate |

Largest-remainder test must tie on stored stable position, not DB retrieval order. Return rounding test uses cumulative target-minus-prior amounts, not independent proportional rounding.

## D. BS dates and locks

| ID | Input/action | Required result |
|---|---|---|
| D01 | `20830115`, `2083-01-15`, Nepali-digit equivalent | Same integer20830115; display Baisakh15 |
| D02 | Blank, seven/nine digits, `2083/01/15`, month13, day99, unsupported year | Reject; no normalize-through-invalid or silent today |
| D03 | Each supported year's first/last month boundaries; real month-length fixtures | Last valid day accepted; next day rejected; no hardcoded30/31 |
| D04 |20830331 if valid calendar day vs20830401 | First belongs FY2082/2083; second FY2083/2084; invalid day rejected rather than used as fixture |
| D05 | Close through20830410, try new source20830410, cancel older original using today | Reject both new locked-date source and older-source cancel; today doesn't bypass original protection |
| D06 | Last stock date20830502, purchase booking20830501 / invoice external date20830429 | Backdated booking rejected; booking20830502 with earlier external supplier date allowed |

Calendar conversion fixtures must cite checked original table/reference dates. Do not invent AD conversion values for these tests. Test Nepal-midnight today using explicit clock input/fixture; UTC operational timestamp remains distinct.

## O. Openings and masters

| ID | Setup/action | Expected |
|---|---|---|
| O01 | Opening Cash5000; finalize | DrCash5000/CrOpeningEquity5000; source immutable |
| O02 | Opening stock10 units value1000 and AR200/AP300 | Inventory1000, AR200, AP300; equity balance900; item movement matches GL; never double-count inventory header |
| O03 | All-zero opening set | Finalized usable business; no zero journal/lines |
| O04 | Opening cash100, customer credit20, supplier advance30 | Cash100; ARcredit20 reclassified liability20; APdebit30 reclassified asset30; equity110; no AR/AP netting |
| O05 | Edit posted opening, change stock item's base unit/kind, archive referenced master | Financial edit denied; kind/unit change denied; archive allowed by policy while historical balances retained |

## F. Main accounting scenarios

### F01 — no-VAT purchase, partial sale and expense

Begin opening cash5000/Opening Equity5000; finalized. Purchase10 units@100, total1000; pay600. Sale3@150,total450; receive200. Expense Rent50, fully paid. All records same valid open date; no discounts/tax.

| Account/state | After purchase | After sale | After expense |
|---|---:|---:|---:|
| Cash |4400.00|4600.00|4550.00|
| Inventory qty/value |10 /1000.00|7 /700.00|7 /700.00|
| AR |0.00|250.00|250.00|
| AP |400.00|400.00|400.00|
| Net sales |0.00|450.00|450.00|
| COGS |0.00|300.00|300.00|
| Operating expense |0.00|0.00|50.00|
| Net profit |0.00|150.00|100.00|

Final assets=4550+250+700=5500. Liabilities400 +equity5000 +profit100=5500. Trial debit/credit sums equal exactly. Purchase isn't operating expense. Receipt/payments don't change profit. Paid expense system payee has zero AP; supplier's400 remains.

### F02 — service sale with bookkeeping tax

Opening cash0 finalized. Tax recording explicitly enabled with fixture13%. Service sale net100/tax13,total113, fully paid. DrAR113,CrSales100,CrOutputVAT13; receiptDrCash113/CrAR113. No stock or COGS. Cash113, AR0, tax liability13, profit100; assets113=liability13+equity100.

### F03 — recoverable purchase tax and partial sale

Opening cash5000. Purchase10@100, VAT130 recoverable,total1130 paid in full. Sell3@150, VAT58.50,total508.50, receive200.

Expected: cash4070; inventory7 units/value700; InputVAT130; AR308.50; OutputVAT58.50; AP0; Sales450; COGS300; profit150. Assets4070+700+130+308.50=5208.50. Liabilities58.50 +opening equity5000 +profit150=5208.50. VAT not revenue/expense in this recoverable example.

### F04 — nonrecoverable purchase tax

Opening cash5000. Purchase10@100,VAT130 nonrecoverable,total1130 paid. Sale3@150 with no sales tax, fully paid. Pool purchase value1130, outflow cost339, closing qty7/value791. Cash4320; profit450-339=111; assets4320+791=5111=opening equity5000+profit111. No InputVAT asset.

## I. Stock cost and movement tests

| ID | Setup/action | Exact expected result |
|---|---|---|
| I01 | Purchase10@100 then10@200; sell5 | Q20,V3000 before sale; COGS750; Q15,V2250 after |
| I02 | Pool Q3,V1 paisa; issue1 three times | Cost `[0,1,0]` under target current-pool half-away; finalQ0,V0. This tiny pool test is deliberate rounding fixture |
| I03 | Sell quantity greater than available / simultaneous final-unit sale | Insufficient sale rejects; race handled C01; never negative pool |
| I04 | Explicit zero-cost opening stock3; sell1 at positive price | Qty decreases; cost0/GLno zero COGS lines; financial sale still balanced |
| I05 | Item archived after earlier sale; return stock source | Return allowed through original source; item absent from new sale picker |
| I06 | Stock adjustment +2@100 or -1 from100/unit pool | Increase value200 with gain200; decrease value100 with loss100; reason/audit mandatory |
| I07 | Historical stock cutoff before later movements/cancel | Latest movement as-of values; today's pool not reused |

I02 uses dynamically recomputed remaining pool. Cost after first issue0: Q2,V1; half-away second cost1; third drains0. All inventory quantities thousandths in production API.

## P. Payments and allocations

| ID | Setup/action | Required result |
|---|---|---|
| P01 | Sale450, receipt200 allocated | Invoice due250, AR250; allocation itself no GL |
| P02 | Receipt201 against invoice due200, all allocated | Reject over-allocation; explicit authorized unallocated1 allowed only with confirmation |
| P03 | Same contact is customer/supplier, AR100/AP200 | Show receivable100/payable200 separately; no automatic net payable100 |
| P04 | Receipt against foreign party/type, refund greater than credit | Reject with zero effects |
| P05 | Customer advance100 without invoice | DrCash100/CrAR100; customer credit100; no fake invoice settlement; cashier cannot do it |
| P06 | Cashsale100,tender150 | Receipt100/change50; cash+100, no payment150/refund50 |
| P07 | Cash500 ->bank transfer200 | Cash300,bank200; total money500/profit0; same-account transfer rejected |
| P08 | Cash0/outgoing1; bank0/outgoing1 | Cash rejects; bank requires allowed owner/accountant overdraft confirmation; manager rejects |

## R. Returns and refunds

### R01 — partial return on partly paid sale

Use F01 state after sale, before expense: cash4600, AP400, AR250, stock7/value700, profit150. Return one unit from sale: revenue reversal150, COGS reversal100, no refund. Stock8/value800; AR100; Sales450/SalesReturns150; net profit100. Invoice effective_total300, net_settled200, due100. Cash4600 unchanged.

### R02 — return on fully paid sale, then refund

Opening cash5000; purchase10@100 pay600 ->cash4400/AP400. Sell3@150 fully paid ->cash4850/AR0/stock700. Return1 ->stock800/ARcredit150/profit100, invoice credit150. Refund150 ->cash4700/AR0/invoice signed_due0. Assets4700+800=5500; AP400+equity5000+profit100=5500. Refund records separate from return; no profit double-reversal.

### R03 — paid return doesn't force money movement

Same as R02 but no refund. Credit150 persists as customer liability reclassification; cash4850. Owner can refund later; no hidden cash withdrawal at return post.

### R04 — cumulative return cap

Original saleqty3. Returnqty2 then attemptqty2 ->second rejected; returnremaining1 allowed. Completed total net/tax/cost exactly original; old-source closed-period return today allowed, cancellation of original closed invoice denied.

### R05 — purchase return when average cost changed

Opening cash5000, no purchase payments. Purchase10@100 then10@200: qty20,value3000,AP3000. Return2 units from first purchase, original credit200; current pool removal300. DrAP200 +DrInventoryLoss100 =CrInventory300. Qty18,value2700,AP2800,loss100. Assets cash5000+inventory2700=7700; liabilities2800+equity5000-loss100=7700. No unexplained imbalance or negative quantity.

### R06 — recoverable versus nonrecoverable return tax

From F03 purchase, return1 unit before sale: reverse original base100/InputVAT13/AP113; stock9/value900. Nonrecoverable counterpart removes original credit113 (subject to current pool cost), no InputVAT reversal. Price variance required if pool differs. Original tax rate snapshot governs even after business rate changes.

### R07 — service returns

Return F02 service invoice: reverseSales100/VAT13/AR113, stock unchanged/no COGS. Refund113 when paid: cash0, tax0, profit0. Purchase service return credits original expense/tax as appropriate; no Inventory line.

### R08 — rounding and cancellation ordering

M06 partial-return cumulative values pass. Cancelling first partial while later partial active rejects; latest partial can reverse if stock latest/refund constraints pass. Repeating cancellation UUID creates no additional reversal. Returning again after valid latest-return cancellation recovers same residual components.

## G. Journal integrity and immutable sources

| ID | Action | Expected |
|---|---|---|
| G01 | Try entry debit100/credit99.99 | Reject before write; no orphan header |
| G02 | Debit and credit both positive on one line, negative line, foreign account/contact | Reject; CHECK/FK backs app validation |
| G03 | Add same source/event twice, cancel same journal twice | Unique source/event and reversal linkage; one original/one reversal max |
| G04 | Inject exception after stock/GL/payment/audit write | Whole transaction rollback: source/sequence/pool/journal/payment/allocation/audit/mutation result unchanged |
| G05 | Edit/delete posted journal/source, submit debit/credit lines to a daily form, or request generic manual-journal endpoint | Denied; no generic journal route; daily form inputs strictly whitelisted and system sides chosen server-side |

## X. Cancellation and archival

| ID | Setup/action | Expected |
|---|---|---|
| X01 | Cancel unpaid, latest-stock sale in open period | Original retained; exact reverse journal/movement; source cancelled/date/reason/actor; original number retained |
| X02 | Cancel sale with active receipt/allocation or posted return | Reject; identify dependency; no automatic deletion/payment reversal |
| X03 | Cancel purchase with subsequent sale/stock movement | Reject; return/adjustment workflow instead |
| X04 | Cancel original date closed but effective date today open | Reject; old date guard unavoidable |
| X05 | Cancel receipt in open period | Exact reversed cash/AR; original allocations retained; current due reopens by effective date; negative cash reversal disallowed |
| X06 | Cancel stock adjustment/return with newer stock activity | Reject; exact reversal only when latest |
| X07 | Earlier report at dateD; cancel source atD+1 | ReportD unchanged; reportD+1 includes reversal; no source-status shortcut |
| X08 | Archive contact/item with old records/dues | History/snapshots/dues retained; new picker excludes; no cascaded deletion |
| X09 | DELETE posted source, GET cancel/report-side accounting write | Denied/no state change; DELETE applies draft only; GET strictly read-only |
| X10 | Cancel money addition/personal withdrawal; try same shortcut for document/payment/opening/stock journal | Owner-source exact reversal with open original/effective dates and money guards; other sources rejected and routed to feature reversal; duplicateUUID one reversal |

## Q. Reports and reconciliation

| ID | Fixture | Required assertion |
|---|---|---|
| Q01 | F01 final | P&L100; stock700; AR250/AP400; assets5500=liability+equity5500; trial balances exact |
| Q02 | F03 | VAT separate130/58.50; profit150; assets5208.50; no InputVAT counted as expense |
| Q03 | F04 | Cost includes nonrecoverable VAT; profit111; no duplicate full purchase expense |
| Q04 | Cash transfer/owner contribution/drawings | Transfer/contribution no revenue; drawings equity reduction, not operating expense |
| Q05 | Archived party with balance, customer credit and supplier advance | Included historically/current dues; positive dues/credit buckets separate; balance-sheet reclass matches controls |
| Q06 | Date-range statement | Opening strictlybefore start; start/end included; closing=opening+period changes; deterministic same-day order |
| Q07 | PriorFY profit100 +currentFY profit50 without annual posting | Prior earnings100/current earnings50; no duplicate opening/closing; equity includes150 |
| Q08 | Original invoice450, opening AR100, receipt250 allocation200/unallocated50 | Invoice due250; opening/unapplied net50; AR300=250+50; no hidden autoallocation |
| Q09 | Repeat every reportGET, including tax/P&L | No journal/document/stock mutation; query tenant/date/role scoped |

## C. MySQL concurrency — real independent connections

Every case uses synchronization/barrier and two independent connections/processes. Explain interleaving and check final state; sequential HTTP tests do not count. No SQLite-only evidence for locks/FKs.

| ID | Race | Required final outcome |
|---|---|---|
| C01 | Available1 unit, two salesqty1 | One success, other insufficient-stock rejection; qty0; exactly one sale journal/stockout |
| C02 | Same postUUID from two requests | Both reference same document; one sequence increment, one sale/receipt/movement set |
| C03 | Different UUIDs, concurrent sales same tenant/FY | Unique consecutive successful numbers; both balanced if stock sufficient |
| C04 | Originalqty3, simultaneous returnsqty2 | One accepted, second cap rejection; cumulativereturned2, not4 |
| C05 | Closing/revoking staff competes with posting | Lock serializes; post before change may succeed; post after effective lock/revoke rejects; no unauthorized post after committed change |
| C06 | Two owner demotions/removals | Only one allowed if needed; one owner remains |

Request-hash conflict with same UUID but changedamount/actor/operation ->409. A failed transaction leaves no mutation result, so corrected retry can post once. Post-commit response timeout returns original result upon retry.

## E. Files/print/export

| ID | Action | Expected |
|---|---|---|
| E01 | Change business/contact/item details after old bill | Old print uses snapshots; new bill uses new data |
| E02 | Print draft/cancelled/Nepali bill atA4/80mm | Correct watermark/labels, no text clipping/missing glyphs; BOOKKEEPING INVOICE label retained |
| E03 | Upload JPEG/PNG/PDF<=5MB then download as authorized role | Private authorized stream, safe filename; no public file URL |
| E04 | Upload SVG/HTML/executable/mislabeled file/>5MB/sixth file | Reject; no executable content stored/exposed |
| E05 | CSV text `=1+1`, leading spaces then`@SUM`, tab prefix, Nepali names | Formula text escaped; UTF8BOM; valid decimal money/date headings |
| E06 | Export>20,000 rows | Clear narrower-range validation; no memory-exhausting all-row load |
| E07 | Upload failure/delete draft/cancel posted | Failed upload doesn't invalidate committed financial source; draft cleans files after commit; cancelled keeps original |

## U. Mobile/accessibility manual checks

| ID | Check | Pass condition |
|---|---|---|
| U01 |360/390/430px home/forms/list | No primary horizontal scroll;44px controls;16px inputs; primary action reachable |
| U02 | Three-line sale | Contact/item/qty/payment/save clear; returningoperator goal<45s measured, otherwise record usability issue |
| U03 | Keyboard +screen-reader labels/combobox | Visible focus, Escape/Enter/arrow keys predictable; labelled fields/errors; no hover-only action |
| U04 | Slow lookup/network loss/doubletap save/stale draft | Visible loading/error; form preserved in memory; sameUUID safe retry; stale409 no overwrite |
| U05 | Print detail | Snapshots correct, totals exact and Nepali readable |
| U06 | Both English/Nepali; decimal/Nepali-digit input | All released labels/messages translated; data unchanged; currency/calendar clear |
| U07 | Installed manifest/offline | Honest network-required state; no successful offline write or cached financial-data claim |
| U08 | Cashier/owner same screen | Correct permitted actions/data, including response bodies; consistent layout |
| U09 | Inspect daily forms as owner/manager/cashier/accountant | No debit/credit/DR/CR fields, raw journal lines, balancing rows or arbitrary control-account selectors; generic journal endpoint absent |
| U10 | Run full day: starting balances ->purchase ->sale ->receive dues ->pay supplier ->expense ->review | Dedicated natural forms complete entire flow; suggested bills available; totals internally reconcile to F01; no accounting-entry screen required |
| U11 | Add personal money100, take personal money30, transfer20 | User supplies only kind/amount/method/date/note; internal capital/drawings/transfer entries correct; none treated as sales/operating expense; source cancel safe |
| U12 | Starting customer/supplier amounts and daily statements | Positive amount +plain who-owes-whom choices map correctly to O04; no side/equity input; daily statement shows Received/Paid/Balance and contextual due/advance/refund wording |

Android Chrome and iPhoneSafari/WebKit checks recorded separately. Desktop1280px and tablet768px remain functional. Unsupported browser/device tests explicitly unverified.

## S. Platform/security/operations

| ID | Check | Expected |
|---|---|---|
| S01 | Trial active->expired | Owner/accountant read/print/export allowed; business mutations403; security/profile/logout available |
| S02 | Suspended tenant /manual renewal | No business data; renewal audited; auth recovery still works |
| S03 | Platform admin without business membership | Tenant/access management only; ledger/attachment/print deny; no impersonation bypass |
| S04 | Audit/session/log inspection | Reasons/actor/time recorded; passwords/rawtokens/secretPAN data absent from operational logs; HTTPSsecure HttpOnlycookies |
| S05 | Backup/isolated restore drill | DB/files/keys recover; journal/stock/tenant checks pass; no production/shared DB overwritten |
| S06 | Queue invitation failure/retry, worker handles multiple tenants | Correct context/resend/status; no duplicate membership; finance unaffected |
| S07 | Scheduler/worker/health/public-root checks | Required operator processes running, health nonrevealing, `.env` inaccessible, backup failure detection configured |

## Release evidence template

For each ID record testfile/testname or manual steps, actual result, environment/time and limitation. Financial/tenant/concurrency failure blocks first release. Tax compliance is a separate gate: this pack's bookkeeping tax fixture does not prove registration, tax eligibility, invoice approval or CBMS acceptance.

Planning-only checks may verify file integrity, links, task coverage and reference calculations. Those checks must not be reported as implemented app acceptance passing.
