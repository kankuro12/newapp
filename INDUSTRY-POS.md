# Industry POS research and implementation

2 October 2026. Nepal first: NPR, BS business dates, Asia/Kathmandu appointment times, mobile Ionic/PWA. User requested all industries, configurable measurement methods and branches. Vendor descriptions establish workflow patterns, not local tax certification or a promise to copy every vendor feature.

| Business | Official research | Screen and daily flow |
|---|---|---|
| Meat | [Lightspeed weighted products](https://x-series-support.lightspeedhq.com/hc/en-us/articles/25534180905243-Creating-and-selling-weighted-products-in-Retail-POS-X-Series) describes pricing and inventory per weight. [Embedded barcode support](https://x-series-support.lightspeedhq.com/hc/en-us/articles/25534108363547-Adding-and-editing-multiple-scannable-barcodes) distinguishes scale labels from scale integration. | Cut/product tiles → weight or requested amount → preparation note → payment. Manual scale reading initially; hardware protocols need actual device testing. |
| Restaurant | Nepal's [Tigg Restro](https://tiggapp.com/restro) lists handheld orders, table views and kitchen displays. [Square Restaurants](https://squareup.com/us/en/point-of-sale/restaurants) describes table, menu and kitchen workflows. | Table/takeaway → waiter menu → send ticket → kitchen preparing/ready → served → checkout. New rounds append tickets; prices and notes frozen. Operational tickets never post accounting. |
| General store | [Loyverse item setup](https://help.loyverse.com/help/how-add-items-loyverse-back-office) supports product codes, variable quantities and store-level inventory. | Search/name/SKU → product tiles → quantity/pack → cart → payment. Existing purchases, returns, dues and stock count remain shared. |
| Barber | [Vagaro calendar](https://www.vagaro.com/pro/calendar) supports service/staff schedules and blocked time. | Quick service tiles for walk-ins; appointment day list by barber/chair; booking, arrival, service, checkout. |
| Salon | [Fresha calendar](https://www.fresha.com/help-center/knowledge-base/calendar) groups availability, blocked time and appointment status. | Staff/day → client → services/duration → available time → booking → arrival → checkout. Prevent resource overlap in server transaction, including blocked time. |
| Milk | [Loyverse liquids](https://help.loyverse.com/help/how-sell-liquids) explains base-volume and fixed-portion sales. | Litre/ml or configured vessel/pack; quick 250ml/500ml/1L amounts when litre base selected; requested amount supported. Stock uses base volume. |
| Glass | [GlassManager](https://glassmanager.com/) describes square-foot calculation, quotes, work orders and measurement records; [estimating](https://glassmanager.com/glass-estimating-quote-software/) links measurements and quote options. | Glass type → length × width × pieces → area/base-unit quantity → cart. Record dimensions and cutting notes on bill. Installation/crew dispatch and supplier GDS import are separate competitor backlog items. |
| Wood | [Epicor LumberTrack](https://www.epicor.com/en-us/industry-productivity-solutions/building-supply/lumbertrack/) covers timber sales, inventory and production. | Timber/sheet → length, area, volume or board-foot dimensions → pieces → cart. No assumed regional timber formula: custom unit conversion must be explicitly configured. |

## Measurement contract

Common quantity, amount, pack, length, area and volume entry methods can be enabled per item. 33 named base units: unit/pair/dozen; kg/g/mg/metric tonne/pound/ounce; litre/ml/cl/US gallon/Imperial gallon; mm/cm/m/in/ft/yd; square and cubic mm/cm/m/in/ft/yd; board foot. Custom units name a positive quantity of that item's base unit, supporting bottles, trays, bundles, sheets and locally defined measures. Metric/international inch/foot/yard dimensions can be mixed by selecting a unit for each dimension. US/Imperial gallons have separate built-in codes and readable labels; ambiguous “gallon” is never guessed. Regional units require shop-defined conversions.

Exact international lengths follow [NIST](https://www.nist.gov/pml/us-surveyfoot): foot = 0.3048m; inch = 25.4mm. Board foot is 1ft × 1ft × 1in. Integer rational conversions, half-up rounding to 0.001 base unit; bill price rounds to integer paisa. Server recomputes measurement; frozen snapshot prints actual dimensions plus billed base quantity. Amount entry shows its rounded bill total for confirmation; cannot guarantee arbitrary amount equals a representable 0.001 quantity.

## Implementation sequence

1. Existing mobile party/items/review forms: retain values, large controls, optional details collapsed.
2. Branch groups and POS profiles: owner creates branch; each branch reuses protected business ledger, stock, cash, staff and opening setup. Branch access requires direct active membership. No implicit access to sibling branches. Switcher selects branch. Shared stock transfers/consolidated tax statements are not implemented by grouping branches.
3. Item measurement setup and eight tailored POS screens; common cart, trusted totals, original-price return/cancellation paths. Configurable custom packs. Persist only after server confirmation.
4. Restaurant tables, waiter ordering, append-only kitchen tickets, status and checkout. Foreground refresh every two seconds; display last successful refresh and connection failures. Optimistic versions and retry UUIDs prevent duplicate/stale orders and checkout. No offline financial queue.
5. Barber/salon resources, BS day schedule, service duration, opening hours and blocked slots; server rejects overlaps under branch lock. Reschedule/cancel require version, checkout links one existing accounting bill.
6. Run ownership, conversion, retry, checkout, overlap and reversal checks; browser mobile/desktop verification.

## Review and release limits

Primary vendor sources above were reviewed for workflows. This design reuses existing financial services rather than a second ledger. Build web first; Android/iOS use same Ionic source later. Provider payments, automated SMS, production realtime throughput, scale/printer integration and native store signing need their own verified integration. Research backlog remains in COMPETITOR-RESEARCH.md; implementation status lives in BUILD-PROGRESS.md.
