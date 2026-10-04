# C1a3 mobile visual QA — 4 October 2026

390 × 844 in-app-browser viewport. Actual Ionic components rendered with
isolated fixture records; authenticated backend requests and posting disabled.
Fixture source: fulfilment-preview.tsx. Temporary frontend HTML removed after
QA; production build checked to exclude it. Browser viewport restored.

- English sales order: completed2.000 L, billed0.500 L, available1.500 L.
  Review selected1.250 L / NPR125.00, source-order allocation and payment terms.
  No horizontal document overflow; review panel width299px within390px viewport.
- Nepali purchase receipt: remaining3.000 L, selected1.250 L; frozen receipt tax
  treatment, translated additional details and confirmation controls visible.
- Nepali actual-quantity receipt slip:2.000 L rather than ordered5.000 L.
  No horizontal document overflow. Receiver/date/signature and quantity-only
  footer translated; footer contrast increased for legibility.
- Public guide freshly reloaded after prior same-document hash navigation kept
  old content. Edition2 delivery-first instructions and current availability
  verified. Production build contains the same updated guide.
- Live protected order route rechecked: sign-in shown. No new credentials or
  financial browser actions. Live authenticated end-to-end C1a QA unverified.

Full backend130/3102 assertions and frontend71/28 files pass. Final types/lint,
build and scoped diff checks pass. Large production chunk388.00KB gzip remains
an existing performance warning; real-phone performance remains unmeasured.
