# Screen fit and scrolling

Owner requirement, 4 October 2026: avoid scrolling on any screen where possible. Preserve complete
content, validation, readable text and touch targets. Compact whitespace first, then use task steps,
tabs, paging and optional disclosures. Never hide overflow to make a screen appear complete.
Expanded details, errors, large text, an open keyboard, long invoices/reports and long lists may
need vertical scrolling. Printing must retain every row.

Layout guidance checked against official W3C explanations:
[reflow](https://www.w3.org/WAI/WCAG22/Understanding/reflow.html) and
[target size](https://www.w3.org/WAI/WCAG22/Understanding/target-size-minimum.html). These require
preserving accessible content and controls; they do not require eliminating all vertical scrolling.

## Implemented first pass

- Signup country and required phone share one row, with separate labels/values.
- Mobile password and confirmation share a row. Signup intro is concise.
- Auth whitespace adapts to phone width and short phone/desktop height.
- Shared mobile topbar, headings, panels and primary party/product forms use less whitespace.
  Existing control heights and 16px input text remain intact.
- Manual iframe uses available height with a smaller minimum, avoiding its old 480px minimum forcing
  additional outer-page scrolling on short phones.
- Existing mobile sale/purchase/order form steps and optional fields remain.
- More shows Sales, Money, Stock, Business or Account tools one section at a time. Manual remains
  visible; section selection is kept in the URL. Existing tool links and role filtering are
  retained, with a POS shortcut added.
- Home opens daily actions first. Balances, recent activity and low stock have separate views;
  cashier balance view remains unavailable. Recent/stock previews page every returned row, two per
  page. Section changes retain each preview's page; shorter refreshed lists clamp to their current
  last page.
- Manual edition4 explains the new navigation and necessary scrolling cases.
- Money separates receive/pay, transfers/owner money, refunds and history. Opening a correction
  focuses its form; uncertain corrections lock inputs, close and section controls and offer the
  original retry.
- New regular-payment setup uses Payee, Amount and Schedule steps on phones; desktop shows the
  sections together. Additional setup contains label, enabled/paused control and full monthly notes.
  Final step shows payee, amount and active/paused state. Save stays in the bottom action area on
  phones. Adding a rule hides the list; editing hides monthly history. History remains available
  through its disclosure after editing. Manual edition5 explains it.

Live read-only signup measurements: 320x667, 360x740, 390x844 and 1280x800 showed content height
equal to visible height and no page horizontal overflow. Country/phone had equal top coordinates;
phone remained required. Final equal-width country adjustment was included. Sign-in and recovery
also fit at 320x667 and 1280x800 in live read-only checks. Viewport override was reset.

Read-only browser fixtures used the real More component and a current copy of Dashboard with only
its API hook replaced by fixed public sample data. Ionic shell classes, app CSS, translations and
navigation were unchanged. All four Home views in EN/NE fit 320x667: scroll height equalled client
height and width equalled viewport width. Nepali initial starting-balance link also fit. Low-stock
view additionally fit 360x740, 390x844 and 1280x800. More's longest Money section fit EN/NE at
320x667; Nepali default Sales fit the larger sizes. Cashier section visibility was inspected. Paging
exposed subsequent sample records. Screenshots: artifacts/screen-fit-home-320-ne.png and
artifacts/screen-fit-more-320-ne.png. Fixture source archived under artifacts/ screen-fit-fixture*;
no fixture route/source remains in frontend. Temporary tabs closed and viewport overrides reset. No
browser financial/account writes.

Verification: frontend76 tests/30 files, 91.24s (artifacts/screen-fit-navigation-frontend.txt);
final product TypeScript, lint and web build passed. Navigation RED was observed against the old
screens; More2 and Home3 interaction cases cover section links, role restriction, exact balance
display and access to every preview page. Build retains an existing large main-bundle warning
(392.96KB gzip).

Further fixture proof: Nepali salary Payee/Amount/Schedule fit320x667, including final review and
Save. Other-bill category/amount and final schedule also fit after removing excess reserved bottom
space; controls were not shrunk. Regular setup fit1280x800 before the final small review addition.
Money's four sections, single-record history and unsubmitted correction fit320x667. Long history,
existing-party picker, expanded options, edit variants, monthly posting/payment, errors, keyboard
and real authenticated data still require audit. Fixture API mutations were disabled; browser never
submitted setup or a financial action. Sources archived under artifacts/screen-fit-payment-fixture*
and removed from frontend. Additional screenshots: screen-fit-regular-320-ne.png and
screen-fit-money-320-ne.png. Temporary tabs/overrides cleaned up.

Latest product checks:84 tests/32 files/101.37s, artifacts/screen-fit-payments-final-frontend.txt;
final TypeScript/lint/web build passed, screen-fit-payments-final-*. Main bundle394.18KB gzip,
existing warning. Eight Money/regular interaction checks cover every action group, cashier
restriction, original correction payload/retry, salary/rent/other creation, unchanged edit
version/payload and hidden-step validation with retained payee.

## Remaining screen audit

This is a first pass, not proof that all screens fit. Inspect real authenticated routes and
short/long populated states. Current session is signed out. Continue development and fixture
verification without creating accounts or financial records through the browser.

| Area                                        | Required fit evidence / remaining work                                                                                                                                                                                             |
| ------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Sign in / recovery / verification / account | Sign-in/recovery initial state verified; long errors, large text, verification and authenticated account forms remain                                                                                                              |
| Business selection / branch setup           | Two-card paging and isolated new setup fit Nepali320x667 sample states; live branches, long names, errors and keyboard remain                                                                                                      |
| Dashboard / More                            | EN/NE short-phone fixture proof recorded above; real authenticated data, long names/amounts, access/offline banners and larger text still need audit                                                                               |
| Parties / products                          | Three entry steps and edited product review fit Nepali320x667 samples; live forms, expanded details, lists/search, errors and keyboard remain                                                                                      |
| Sale / purchase / expense / order           | Every mobile step with selected party and multiple lines; review/post visible; no lost input                                                                                                                                       |
| Industry POS                                | Each niche primary entry, cart, payment and measures; restaurant waiter/kitchen and salon schedule                                                                                                                                 |
| Money / returns / counts                    | Money sections/correction and stepped receipt/return/count public fixtures fit; exact proof below. Real authenticated entries, other money kinds, long history, pickers, expanded details, errors, keyboard and larger text remain |
| Workflow / fulfilment / packages            | Policy-aware steps, source lists, review, reversal and full slips; bill-first UI still pending                                                                                                                                     |
| Regular payments                            | New salary/other setup fixture proof above; rent/existing picker, edits, due list, monthly paid/unpaid posting, expanded details and long history remain                                                                           |
| Reports / documents / printing              | Separate report filters/results/balances and two-row paging fit simple Nepali320x667 samples; all loaded report rows retained for print. Complex/long reports, documents and actual printers remain                                |
| Party terms / prices / offers / followups   | Optional setup and reviewed changes, paged records                                                                                                                                                                                 |
| Catalogue / imports / barcodes / labels     | Reviewable setup and previews; list paging                                                                                                                                                                                         |
| Settings / openings / audit / platform      | Sectioned setup, confirmations and paging; authorization preserved                                                                                                                                                                 |
| Manual                                      | Inner guide scroll/print, no unnecessary double scrolling                                                                                                                                                                          |

Acceptance: test 320x667, 360x740, 390x844, desktop 1280x800, expanded optional sections, errors,
long translated labels, larger text and keyboard. Keep relevant action visible when possible. Do not
shrink buttons/text or discard records to claim a fit. Update in-app guide with changed flows.

## Daily payment / return / stock-count checkpoint — 4 October

DailyForms now reuses three phone entry steps; desktop keeps all three panels visible. Money: Party
or Accounts, Amount, Review. Return: Items, Reason, Review. Count: Item, Count, Review. Final Save
remains beside Back in the bottom action area. Notes, manual bill allocation, advance/overdraft and
zero-cost confirmation remain available in optional details. Count history opens separately and
returning to entry retains values. Return source lines page two at a time without dropping
selections. Named quantity errors reveal their selected line's original page.

Financial endpoints/payloads and BigInt original-price return calculation remain. All three entry
forms lock fields and step navigation while a save is uncertain, with useSave original-action retry
outside the locked fieldset. Role restrictions remain. New count success resets entry only after
confirmed response. Guide edition6 documents steps, paging, refund account and retry.

Posting-disabled public fixtures copied current DailyForms with only its API import replaced by
fixed sample hooks. Real Ionic classes, shared fields, app CSS and translations were used.
Nepali320x667: payment three steps fit; final review panel bottom454.78. Return item/reason/review
fit, including immediate-refund explanation and account after excess total spacing was removed:
final panel bottom518.66, account bottom505.66, toolbar top527. Count steps fit; final review
includes original count, actual count and extra cost, panel bottom497.97. Every measured page scroll
height equalled667 and width320. Save bottom586. Transfer account step fit320x667; English initial
return item page also fit. Payment review additionally fit360x740,390x844,1280x800; count desktop
fit1280x800. These are fixture results, not live authenticated financial QA or proof of every state.

Screenshots: artifacts/screen-fit-receipt-320-ne.png, screen-fit-return-320-ne.png,
screen-fit-count-320-ne.png. Fixture source archived as artifacts/screen-fit-daily-fixture* and
removed from frontend. Preview tab was closed and device override cleared. No browser
financial/account submission. Long names, pickers, expanded details, errors, larger text, keyboards,
populated history and other money kinds still need visual checks. Remaining screen audit above stays
active; long content keeps accessible vertical scrolling.

## Masters / businesses / reports and mobile header checkpoint — 5 October

Party entry uses Party → Roles → Review; product entry uses Product → Price / unit → Review. Desktop
retains all panels. Optional product setup and edit balances remain in disclosures. Business
selection pages two books and opens new setup separately. Reports separate Results, Filters and
Balances; tables page two rows on phones, six on desktop. All loaded rows remain available to print.
Audit still prints only the server page currently loaded, as before.

Below768px, workspace Heading hides its large title/description visually while retaining the
accessible heading. Its existing English/Nepali Back link moves before the navbar logo, preserving
destination and click guard. Other actions, including Count history, remain. The bottom Back changes
the entry step. Desktop and standalone pages without a workspace slot retain the original Back.

Posting-disabled current-source Ionic fixtures proved sample Nepali320x667 master steps, edited
product review, business paging/setup and simple report sections. Edited product and receivables
also fit360x740,390x844,1280x800. Earlier catalogue screenshots precede the final header change.
Final header proof uses current CountForm: page height667/width320, Back44x44 before logo, review
panel bottom462.19, History and final Save visible. Resize to1280x800 restores desktop heading and
page Back while preserving entered values. Current screenshot:
artifacts/mobile-heading-count-320-ne.png. Fixture sources archived under
artifacts/mobile-heading-fixture* and screen-fit-catalogue-fixture*; temporary frontend modules/tabs
removed and viewport overrides cleared.

These results do not certify live authenticated forms, every route, expanded details, long records,
validation states, larger text or on-screen keyboards. No financial/account actions were submitted
through the browser. Manual edition7 documents the new flows and mobile header. Remaining audit
above stays active.
