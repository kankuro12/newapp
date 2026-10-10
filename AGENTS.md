# Business Book

- Read BUILD-PACK.md and MONOREPO-CONTRACT.md before implementation.
- Backend: Laravel 13. Frontend: Ionic React + TypeScript. One root npm workspace.
- Always invoke PHP as php84 and Composer as composer84; never default php/composer.
- Daily-action forms only. No DR/CR entry, balancing grid or generic journal input.
- Enforce tenant membership and ownership in Laravel on every financial path.
- One service class per feature under backend/app/Service.
- BS integer YYYYMMDD business dates; no Carbon business-date arithmetic.
- Money: integer paisa. Qty: integer thousandths. Never JavaScript/PHP money floats.
- Preserve posting cleanup and reversal paths.
- No dairy source or database changes. Do not share APP_KEY, storage or sessions.
- No Git stage/commit/push/reset, branch creation/switch, history rewrite or .gitignore edits unless
  user explicitly asks. Initial cloning was specifically authorized.
- Do not spawn subagents without user authorization.
- Short handoff: changed files, behavior, checks and unverified items.
