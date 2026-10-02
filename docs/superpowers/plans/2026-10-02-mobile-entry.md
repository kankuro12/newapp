# Mobile Entry Implementation Plan

> Execute inline with superpowers:executing-plans, task by task. User forbids subagents and Git mutations; retain this checked ledger instead of commits.

**Goal:** Faster mobile data entry with visible party roles, optional details and a Party → Items → Review billing flow.
**Architecture:** Reuse MasterForm, DocumentForm, shared controls and CSS. Keep entered values in their current React state; mounted step sections are hidden only on small screens. Existing posting API and UUID retry helper remain authoritative.
**Tech stack:** Ionic React 9, React 19, TypeScript, native HTML controls, existing Vitest/Testing Library.
**Spec:** COMPETITOR-RESEARCH.md, Mobile design decisions; BUILD-PACK.md and MONOREPO-CONTRACT.md.

## Constraints and review focus

- Laravel 13/PHP php84; Ionic web first; no dependencies, subagents or Git changes.
- BS integer dates; exact BigInt preview; no change to financial posting/reversal/auth/cache rules.
- Hidden invalid fields must reveal their step or optional disclosure before focus.
- Enter/Continue before Review must never post; values survive step navigation.
- Desktop shows all sections; 320/390/767px screens have no horizontal overflow.
- Changing step/viewport must not change an unconfirmed save payload.
- English/Nepali labels and accessible grouped multi-role checkbox controls.

## Task 1: Party/item entry

Files: frontend/src/pages/Masters.tsx, components/ui.tsx, theme/app.css, lib/i18n.ts.

- [ ] Move party role fieldset above optional details; retain all four simultaneous roles.
- [ ] Optional email/address/PAN in native details; use native validation invalid event to open before focus.
- [ ] Item basics first; SKU/low-stock in details. Semantic grouping and 44px targets; no false role exclusivity.
- [ ] Mobile save/cancel action row; decimal/tel keyboard hints; optional labels; check current party edit, new party and item layouts.

## Task 2: Billing steps

Files: frontend/src/pages/DocumentForm.tsx, components/EntrySteps.tsx, components/EntrySteps.test.tsx, theme/app.css, lib/i18n.ts.

- [ ] Write/run failing interaction check: Continue and Enter move steps without submit; returning retains input; invalid hidden field reveals correct step.
- [ ] Add minimal EntrySteps component: numeric step state, named navigation, section wrappers, in-flow Continue/Back actions and screen-reader heading focus. MatchMedia mobile state only for behaviour; CSS breakpoint 767px.
- [ ] Integrate existing form sections, single total/payment submit and validation. Desktop retains existing all-section entry. Optional disclosure auto-opens on invalid.
- [ ] Run focused check to green, full frontend checks/build/lint, browser mobile/desktop visual and keyboard checks. Save proof screenshots; log actual results here and feature ledger.

## Execution notes

- Design adapted from current source and authoritative online guidance; user authorised implementation and sequential work. Higher-level autonomy instructions override skill's redundant approval/commit steps.
- No independent review delegation authorised; perform separate inline review.
