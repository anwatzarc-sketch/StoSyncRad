# 12 — FRONTEND: MOBILE-FIRST, WHITE-LABEL, INSTALLABLE PWA

This chapter consolidates STOSYNCRAD-FRONTEND-SPEC-1.0 and adds white-label theming, enum/schema-driven forms, the admin-defined RBAC UI, setup wizard UI and reporting outputs. Where this chapter is silent, the frontend spec applies.

## 12.1 Stack and structure

Vue 3 (Composition API), TypeScript strict, Inertia.js, Tailwind CSS, Vite, one accessible component system, one PWA integration (`vite-plugin-pwa` with a first-party `injectManifest` service worker). Structure as in the frontend spec §3.2 plus `features/setup/`, `features/administration/{identity,roles,config,branding,templates,lookups}`, `features/planning/`, `features/sales/`, `features/traceability/`, and `types/*.generated.ts` (enums, schemas, permissions — generated, never hand-edited).

State: Vue reactive + composables; Inertia shared props carry `auth` (account, effective permission codes, scopes), `brand`, `locale`, `features`, `setupState`, `connection`. No global store unless proven necessary. Client state is never authoritative for stock, permissions or posting outcomes.

## 12.2 White-label theming

- `theme.css` (served from the active brand kit) defines `--ssr-*` tokens for light and dark; Tailwind config maps semantic colors to these variables; no literal brand colors in components.
- App name, short name, logos, tagline, support links from `brand` shared prop; page titles `"{page} · {brand.app_name}"`.
- Manifest from `/manifest.webmanifest` (dynamic); icons from brand kit derived assets; theme color token.
- Login page, PDF/print styles, email templates share tokens.
- Dark mode is a feature flag (`feature.dark_mode`) enabled only after contrast verification; `prefers-color-scheme` honored when enabled.
- Rebrand verification: changing the brand kit updates app shell, login, manifest, icons and printable templates without a rebuild (E2E test).

## 12.3 Schema- and enum-driven UI

- Forms are generated from `GET /meta/schemas/{model}` metadata + generated TS types: input types, required markers, max lengths, enum selects with labels, precision-limited quantity inputs, date ordering hints, `writable_by` hidden/disabled with explanation, `immutable_after` locked with reason.
- Enum selects never show raw codes; labels come from `/meta/enums` (locale-aware) and are cached per session.
- Validation messages map server `errors[]` by property path (including nested `lines.n.field`) to inline field errors and a summary; allowed values shown on `ENUM_INVALID`.
- `permitted_actions[]` from each resource drives action buttons; the UI never computes permission logic beyond hiding entries the server did not permit.

## 12.4 App shell

Mobile: top app bar (brand mark, title, warehouse context, notifications, connection state, user menu), content, bottom nav (Home, Stock, Work, Reports, More — permission-filtered; Reports hidden without `reporting.report.run`). Desktop: header (global search, warehouse context, notifications, update indicator, profile) and a sidebar with two areas, both server-filtered (`Navigation`):
- **Daily work** (default): a short, flat list — Home, Stock (search, scan, traceability, lots, serials, movements, reservations, recalls, valuation as one tabbed place), one entry per **workflow hub** (Receive, Transfers & moves, Requests & issues, Sales & delivery, Customers & billing, Counting, Purchasing — `WorkflowCatalog::HUBS`, shown when the person can start something there or read its documents), Reports.
- **Administration** (control plane, entered from the sidebar footer or the user menu, with "Back to operations"): Catalog (items and all item master data, reorder rules, price lists, tax rates, forecast inputs), Partners, Organization, People & access, Platform, Data (imports, opening balances), Oversight (audit, security events, health, reconciliation).

Warehouse context selector lists only warehouses in scope; the server still validates every request.

## 12.5 Setup wizard UI

Stepper with progress persistence, preflight panel, live brand preview (shell + login + PDF letterhead + icon), category taxonomy picker (tree with top-level checkboxes), role template importer and role builder (module/resource tree, search, select-all, implied-permission locks, consequence preview), reporting role probe button, review diff, bundle export, Lock & Launch confirmation.

## 12.6 Core screens

- **Home — "My work"** (`GET /work`): start actions the person may use; queues waiting for them with exact counts, counted where they hold the permission of the awaited action (approvals first; documents a "different actor required" SoD rule forbids them to approve are left out); alerts (below reorder point, lots expiring within 30 days, open recalls, reconciliation differences). A queue with one document opens it, otherwise its hub.
- **Workflow hubs** (`/work/{hub}`): start buttons, queue tabs with counts (`GET /work/queues/{queue}`, oldest first) and links to every document list. The queues, each document's happy path and the statuses in which each record action applies are declared in `WorkflowCatalog` and pinned to the real routes, enums and permissions by a test.
- **Record pages** of documents: a stepper of the happy path and a **next step** panel offering only the actions valid in the current status (primary first, the rest under "More"; floor actions open their task screen); edit only in editable statuses. Lists accept `filter[...]` in the URL (deep links from queues and alerts).
- **Stock search**: SKU/name/barcode/lot/serial, category tree filter, warehouse/location, condition, reorder status; results as cards (mobile) / table (desktop) with explicit quantity buckets (on hand, reserved, available, quarantine, damaged, expired, blocked, in transit).
- **Item detail** (Odoo-parity tabs + smart buttons; cost fields only with permission).
- **Receiving** guided flow (`/tasks/receiving`: pick or scan the arriving purchase order → confirm the warehouse → scan items → quantity, lot/expiry/serials as the item's policies require, condition and rejections → QC checklist → bin, scanned → review → save, submit and post when allowed; `GET /purchase-orders/{id}/receiving-sheet`); **Put-away** tasks (`/tasks/putaway`: open tasks in scope, own first; scan the bin; `GET /putaway-tasks/sheet`).
- **Transfers**: detail with quantities (requested/approved/dispatched/received/in transit/discrepancy), timeline, valid actions for state, dispatch & receipt wizards with batch verify scanning, discrepancy resolution.
- **Requests / Picking / Issues**; **Sales orders / Packing / Shipments** (feature-flagged); **Returns**.
- **Stocktaking**: count screen (blind mode hides book qty), numeric keypad focus, recount flags, variance review.
- **Adjustments**, **Relocations**, **Condition changes**.
- **Planning**: replenishment suggestions with included-quantity breakdown; allocation plan grid.
- **Traceability**: lot/serial trace timeline and branch map; recall screen.
- **Reports**: catalog, builder, preview, runs, exports (Chapter 10 §10.8).
- **Administration** (`/admin`, the control plane): master data, organization, identity & access (Chapter 04 §4.9), platform (Chapter 03 §3.9), data imports, audit log, system health.
- **Requests for people who only request stock** (`read_own`/`create_own`/`cancel_own`): their own requests through `/stock-requests/mine` endpoints — list, open, create, submit, cancel.

## 12.7 Transactional UX rules

Draft vs submit vs approve vs post are distinct actions with confirmation dialogs explaining consequences and requiring reasons where policy demands; `Posting…` transient state; `Posted` only after server confirmation; duplicate-click guard + stable idempotency key per logical attempt (generated client-side UUID v7, kept across safe retries, resolved via `GET /operations/{key}` after network loss); conflict handling refreshes server data, preserves draft input, explains `409` codes; no optimistic stock updates.

## 12.8 Barcode scanning

One `BarcodeInput` component (keyboard-wedge scanners, camera via BarcodeDetector/ZXing fallback under `feature.camera_scanning`, manual entry), used in search, receiving, QC, picking, transfer receipt (batch verify), stocktaking, packing. Scanning identifies; it never posts.

## 12.9 PWA and offline policy

Manifest from brand kit; installable; HTTPS only; service worker scope restricted; caches only versioned static assets, `theme.css`/icons by hash, and the generic offline page; network-only for navigations, APIs, auth, downloads; never caches authenticated HTML, inventory responses, reports or exports; no offline mutation queue; offline banner with explicit limitation text; update manager with deferred activation (never reload during a posting); cache cleanup on activate; documented recovery (unregister + reload route `/pwa/reset`).

## 12.10 Accessibility, responsiveness, performance

WCAG 2.2 AA; 44×44 targets; visible focus; non-color status; keyboard tables/menus; tested at 320/375/390/768/1024/1440; Core Web Vitals p75 LCP ≤ 2.5 s, INP ≤ 200 ms, CLS ≤ 0.1; lazy-load reporting builder, admin, planning and traceability bundles; debounce search; cancel stale requests; server pagination; virtualization only when needed.

## 12.11 Security hardening

No secrets in client; session cookies only; no tokens in storage; CSP (nonce-based scripts, no `unsafe-inline` scripts, restricted connect/img/font sources incl. brand asset host); XSS-safe rendering; sanitized rich text for item descriptions; report labels never rendered as HTML; browser storage limited to non-sensitive preferences; telemetry excludes payloads.

## 12.12 Tests

Unit (formatting, enum label mapping, permission-filtered nav, idempotency key persistence, offline state), component (forms from schema, enum selects, barcode input, dialogs, responsive card/table), E2E (full journeys from the frontend spec §16.3 plus: setup wizard end-to-end, rebrand without rebuild, role builder → effective access change, enum invalid feedback, report build/run/export PDF & XLSX, counter sale, e-commerce pack & ship, lot trace), PWA (manifest validity from brand kit, cache contents audit, no mutation replay, no false success).
