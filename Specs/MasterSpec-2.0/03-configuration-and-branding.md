# 03 — CONFIGURATION REGISTRY AND WHITE-LABEL BRANDING

## 3.1 Principle: 100% configurable

Any behavior that a client could reasonably want different MUST be expressed as one of:

| Mechanism | Used for | Where it lives |
|---|---|---|
| **Configuration key** | Policies, thresholds, limits, formats, toggles, defaults | `configuration_entries` table (typed, validated, versioned) |
| **Lookup** | Admin-editable lists whose *values* carry no code semantics (reasons, tags, payment terms, incoterms, industries, recipient types, carrier services) | `lookups` / `lookup_values` tables |
| **Enum label** | Display label/translation of a system enum case (semantics fixed in code) | `enum_labels` table + locale files |
| **Role** | Who may do what | `roles`, `role_permissions` |
| **Template** | Documents, emails, labels, print layouts | `templates` table (versioned, with built-in defaults) |
| **Brand kit** | Identity, assets, theme | `brand_kits` + private disk |
| **Seed** | Initial reference data | versioned seed classes |
| **Environment** | Infrastructure secrets and bindings only | `.env` / secret manager |

Hard-coded business values are a defect. The code review checklist (Chapter 15) rejects literals for anything in Appendix C.

### Layering and precedence

`code default` → `seed default` → `global configuration entry` → `branch override` → `warehouse override` → `category override` → `item override` (only for keys declared overridable at that scope). Resolution is deterministic and exposed in the UI ("effective value, inherited from …").

## 3.2 Configuration registry

Each key is declared in code (`app/Domains/Platform/Config/Keys/*.php`) with a `ConfigKeyDefinition`:

```
key:            'stock.negative_stock_policy'
type:           ConfigValueType (string|integer|decimal|boolean|enum|json|duration|percentage|money|list|secret|asset_ref)
enum_class:     NegativeStockPolicy::class  (when type = enum / list of enum)
default:        'forbid'
scopes:         [global, warehouse]
validation:     ['required', ...] + schema constraints (min, max, pattern, items)
sensitive:      false
restart_required: false
requires_permission: 'platform.config.stock.manage'
category / group / label / help: for the admin UI
since_version:  '2.0.0'
```

Storage `configuration_entries`:
- `id uuid`, `key`, `scope_type` enum `ConfigScopeType` (`global`, `branch`, `warehouse`, `category`, `item`), `scope_id uuid null`, `value jsonb`, `value_type`, `version int`, `effective_from timestamptz null`, `updated_by`, `updated_at`
- Unique `(key, scope_type, scope_id)`; history in `configuration_entry_versions` (append-only).

Rules:
- Writes go through `SetConfigurationValueAction` → validate against definition (type, enum, range, scope allowed) → compare-and-set on `version` → audit (`platform.config.changed` with before/after unless `sensitive`) → cache invalidation → outbox event `config.changed`.
- Reads go through `Config::get(key, scope?)` with a per-request memo and a tagged cache; never direct queries in domain code.
- `secret` values are encrypted at rest (Laravel encrypter) and masked in UI, audit and exports.
- `restart_required` keys display a banner until workers restart.
- `php artisan config:registry:validate` fails CI if a key is referenced in code but not declared, or declared without a default and help text.
- Admin UI: searchable by key/label/group; shows definition, effective value per scope, history, and a "reset to default".

## 3.3 Feature flags

Feature flags are configuration keys with `type: boolean` under `feature.*` (e.g. `feature.ecommerce_fulfillment`, `feature.retail_sales`, `feature.serial_tracking`, `feature.camera_scanning`, `feature.allocation_planning`, `feature.customer_returns`, `feature.multi_currency_purchasing`, `feature.dark_mode`). Disabling a feature hides navigation, blocks its endpoints with `403 FEATURE_DISABLED`, and excludes its datasets from the reporting catalog. Flags never alter ledger semantics.

## 3.4 Lookups

- `lookups` (`code`, `label`, `description`, `is_system`), `lookup_values` (`lookup_id`, `code`, `label`, `sort_order`, `is_active`, `metadata jsonb`).
- Values referenced by documents are archived, never deleted. Referential integrity via FK to `lookup_values.id`.
- System lookups (shipped) can gain values but their codes cannot be removed.
- Examples: adjustment reasons, discrepancy reasons, rejection reasons, return reasons, cancellation reasons, payment terms, incoterms, industries, departments/cost centres, carrier services, packaging types, storage categories, product tags, QC check types, priority labels.

## 3.5 Brand kit (white-label identity)

A brand kit is one versioned record (`brand_kits`) plus assets on the private disk, with exactly one `is_active`. Fields:

- Names: `app_name`, `app_short_name` (≤ 12 chars), `tagline`, `legal_footer`, `support_email`, `support_phone`, `support_url`
- Assets: `logo_light`, `logo_dark`, `logo_mark`, `favicon_source`, `pwa_icon_source` (square ≥ 512 px), `login_background` (optional), `document_letterhead_header`, `document_letterhead_footer`, `email_header`, `email_footer`
- Theme: color tokens (`brand`, `brand_contrast`, `accent`, `accent_contrast`, `surface`, `surface_muted`, `text`, `text_muted`, `border`, `focus`, `success`, `warning`, `danger`, `info`, `nav_background`, `nav_text`), optional dark-theme overrides, font family selection (`system`, `inter`, `roboto`, `custom` with uploaded WOFF2), border radius scale preset, density preset
- Locale defaults: default locale, enabled locales (overrides step 5 if present in kit)
- Metadata: `version`, `checksum`, `imported_from`, `activated_at`, `activated_by`

Derived artifacts (regenerated on activation, cached on public disk under a hashed path):
- PWA icon set (192, 256, 384, 512, maskable 512, Apple touch 180), favicon.ico + SVG, OpenGraph image
- Web manifest JSON served by `/manifest.webmanifest` from the active kit (dynamic route, `Cache-Control: public, max-age=3600`, ETag = checksum)
- `theme.css` with CSS custom properties `--ssr-*` for light and dark
- Document and email templates receive the kit through a `brand` view variable

### Zero-rebuild rule

Activating or editing a brand kit MUST NOT require recompiling frontend assets, editing templates, or restarting the application. The frontend consumes `brand` via the Inertia shared props and `theme.css` link; the service worker caches `theme.css` and icons by content hash and refreshes on manifest change.

### No hard-coded brand strings

- Templates, Vue components, notifications, PDF layouts, PWA manifest and page titles MUST reference `brand.app_name` etc. A CI grep gate fails on the literal `StoSyncRad` (and client names) outside `README`, engineering docs, package names and the `platform.codename` constant.
- Translations use placeholders: `"Welcome to :app_name"`.

## 3.6 Templates

`templates` table: `code` (e.g. `document.goods_receipt.print`, `document.transfer.dispatch_note`, `label.item.barcode`, `email.notification.approval_pending`, `email.auth.password_reset`, `report.pdf.layout.default`), `engine` enum (`safe_html`, `mjml`, `label_zpl`, `label_html`), `body`, `variables_schema jsonb`, `version`, `is_system_default`, `is_active`, `updated_by`.

- Rendering is sandboxed: client-editable bodies use a logic-less syntax (`{{ path | filter }}`, `{{#each}}`, `{{#if}}`) interpreted by `SafeTemplateRenderer` — never compiled to PHP/Blade; output is escaped; only built-in filters (dates, numbers, Code 128 barcode, QR) emit markup.
- Each template type ships a system default that can be cloned and edited; "reset to default" restores.
- Preview with sample data in the admin UI.
- PDF and label renderers consume templates; Chapter 10 reporting layouts also consume `report.pdf.layout.*`.

## 3.7 Localization

- Strings in `lang/{locale}/*.json`; client-editable overrides in `translation_overrides` (locale, key, value) applied at runtime with cache.
- Enum labels: `enum_labels` (enum class, case, locale, label, description) — admin-editable; code never prints raw case names in UI.
- Number/date/currency formatting from `locale.*` keys through one shared formatter service (backend) and one shared `utils/formatting.ts` (frontend) fed by the same keys.
- Release languages: English; Amharic and Afaan Oromo translation-ready (all strings externalized; RTL not required).

## 3.8 Config Bundle (portable deployment configuration)

A **Config Bundle** is a signed JSON document (`bundle.json`) plus an optional Brand Kit zip, exported from Step 16 or `php artisan platform:bundle:export`, and consumed by `platform:setup --bundle` or `platform:bundle:import --dry-run|--apply`.

Contents (all optional sections): `platform` (version, deployment label), `company_profile`, `brand_kit` (reference to zip + checksum), `configuration` (key → value by scope, excluding secrets unless `--include-secrets` with encryption passphrase), `lookups`, `enum_labels`, `translation_overrides`, `templates`, `organization` (branches, warehouses, locations), `catalog_foundations` (UoM, categories, tags), `document_sequences`, `roles` (with permission codes; unknown codes flagged), `report_definitions`, `integrations` (minus secrets).

- Import is validated as a whole against the registry and schemas; dry-run prints a diff; apply is transactional per section with a report.
- Bundles are the primary mechanism for **"rebrand and deploy for a new client in the easiest way"**: clone a reference bundle, change names/brand/organization, run headless setup.

## 3.9 Administration UI

Under *Administration → Platform*: Company Profile, Brand Kit (with live preview of app shell, login page, PDF letterhead, email, PWA icon), Configuration (grouped), Feature Flags, Lookups, Enum Labels, Translations, Templates, Document Numbering, Config Bundles (export/import), Setup (reopen), System Health.

All screens are permission-gated by `platform.*` permissions (Appendix B) and all changes audited.

## 3.10 Tests (mandatory)

- Every declared key has a default, help, scopes and validator; undeclared keys cannot be set.
- Scope precedence resolves as specified; `effective value` endpoint matches resolver.
- Secret keys never appear plaintext in API, audit, logs, exports or bundles without passphrase.
- Brand kit activation regenerates icons/manifest/theme, invalidates caches, and the manifest ETag changes; no asset rebuild occurred (build hash unchanged).
- CI grep gate for hard-coded brand strings passes.
- Bundle export → import on a clean instance yields an equal configuration (deep-equality, excluding ids/timestamps).
- Feature-disabled endpoints return `403 FEATURE_DISABLED`, their nav entries disappear, and their datasets vanish from the reporting catalog.
