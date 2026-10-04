# Screen fit and scrolling

Owner requirement, 4 October 2026: avoid scrolling on any screen where possible.
Preserve complete content, validation, readable text and touch targets. Compact
whitespace first, then use task steps, tabs, paging and optional disclosures.
Never hide overflow to make a screen appear complete. Expanded details, errors,
large text, an open keyboard, long invoices/reports and long lists may need
vertical scrolling. Printing must retain every row.

Layout guidance checked against official W3C explanations:
[reflow](https://www.w3.org/WAI/WCAG22/Understanding/reflow.html) and
[target size](https://www.w3.org/WAI/WCAG22/Understanding/target-size-minimum.html).
These require preserving accessible content and controls; they do not require
eliminating all vertical scrolling.

## Implemented first pass

- Signup country and required phone share one row, with separate labels/values.
- Mobile password and confirmation share a row. Signup intro is concise.
- Auth whitespace adapts to phone width and short phone/desktop height.
- Shared mobile topbar, headings, panels and primary party/product forms use
  less whitespace. Existing control heights and 16px input text remain intact.
- Manual iframe uses available height with a smaller minimum, avoiding its old
  480px minimum forcing additional outer-page scrolling on short phones.
- Existing mobile sale/purchase/order form steps and optional fields remain.

Live read-only signup measurements: 320x667, 360x740, 390x844 and 1280x800
showed content height equal to visible height and no page horizontal overflow.
Country/phone had equal top coordinates; phone remained required. Final
equal-width country adjustment was included. Sign-in and recovery also fit at
320x667 and 1280x800 in live read-only checks. Viewport override was reset.

## Remaining screen audit

This is a first pass, not proof that all screens fit. Inspect real authenticated
routes and short/long populated states. Current session is signed out. Continue
development and fixture verification without creating accounts or financial
records through the browser.

| Area | Required fit evidence / remaining work |
| --- | --- |
| Sign in / recovery / verification / account | Sign-in/recovery initial state verified; long errors, large text, verification and authenticated account forms remain |
| Business selection / branch setup | Few/many branches, primary setup/save visible |
| Dashboard / More | Primary actions first, secondary cards/tabs, short phone proof |
| Parties / products | New form, edit balances, expanded details; list paging and search at short height |
| Sale / purchase / expense / order | Every mobile step with selected party and multiple lines; review/post visible; no lost input |
| Industry POS | Each niche primary entry, cart, payment and measures; restaurant waiter/kitchen and salon schedule |
| Money / returns / counts | Source selection, confirmation, retry and primary action within practical height |
| Workflow / fulfilment / packages | Policy-aware steps, source lists, review, reversal and full slips; bill-first UI still pending |
| Regular payments | Salary/rent setup, due list, paid/unpaid posting, details |
| Reports / documents / printing | Filters and primary totals visible; page/section long results; complete prints |
| Party terms / prices / offers / followups | Optional setup and reviewed changes, paged records |
| Catalogue / imports / barcodes / labels | Reviewable setup and previews; list paging |
| Settings / openings / audit / platform | Sectioned setup, confirmations and paging; authorization preserved |
| Manual | Inner guide scroll/print, no unnecessary double scrolling |

Acceptance: test 320x667, 360x740, 390x844, desktop 1280x800, expanded
optional sections, errors, long translated labels, larger text and keyboard.
Keep relevant action visible when possible. Do not shrink buttons/text or
discard records to claim a fit. Update in-app guide with changed flows.
