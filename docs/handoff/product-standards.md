# Product standards

Checks every user-facing change must pass. Most come from corrections the owner made. When the owner
flags something new, add it here in the same session, with the date. The `product-reviewer` agent checks
every item.

## Information and layout

- Redesigns never drop information. Reorganise it (summary, then trends, then detail) and keep
  anything moved off a page one click away. (2026-10-18)
- Dashboards are at least as rich as the design directions in `billing/docs/design/directions/`:
  KPI cards with sparklines and trends, comparison charts, breakdowns, health summaries.
  (2026-10-18)
- Admin screens are at least as capable as WHMCS's equivalent and easier to use; sidebar and
  dashboard at least as rich. (2026-10-17)

## Money and currency

- Amounts show the currency symbol, not the ISO code: `$1,234.50`, `₹1,23,456.00`, `Rs 1,23,456.00`.
  INR and NPR use lakh grouping. ISO codes appear only where a currency is being identified or chosen
  (switchers, selects, settings, exports, API, logs). (2026-10-18)
- Primary currencies are USD (default), INR and NPR; every money screen works for all three.
  (2026-10-17)
- Anything that depends on a changing value has proper settings: for example, exchange rates have
  sources, a schedule, safety limits, history and a preview before prices change. (2026-10-18)

## Brand and visuals

- The product name and logo come from `BrandProvider` and are configurable; nothing hard-codes
  "Mohar" or "Laravel". (2026-10-17)
- Colours are calm and easy on the eyes. No neon or harsh contrast, and both light and dark modes
  work. (2026-10-17)
- Charts use shadcn Chart (recharts) with theme tokens and look as good as the design
  directions. (2026-10-17)
- Third-party integrations show their official logo (from the provider's official brand or merchant
  resources), not a plain text badge. (2026-10-18)

## Work products

- Each topic has one artifact page; update it rather than creating a new page. (2026-10-18)
- Markdown docs use kebab-case file names, except `CLAUDE.md`. (2026-10-18)
- Docs and reports are factual and plain, with no exaggeration or filler. (2026-10-18)
- Progress updates to the owner include screenshots. (2026-10-17)
