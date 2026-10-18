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
- Operational pages (automation, logs, queues, gateways, servers) open with summary cards and a trend
  chart where the data is time-based, then the detail list. (2026-10-18)
- Admin screens are at least as capable as WHMCS's equivalent and easier to use; sidebar and
  dashboard at least as rich. (2026-10-17)

- Every admin and client page has a breadcrumb that links back to its list. (2026-10-18)
- Pages linked from the client sidebar keep signed-in customers inside the client area. (2026-10-18)
- Never show internal keys or database IDs; show names and document numbers (INV-1247, not 208).
  (2026-10-18)
- One term per action across the app ("Log in", "Create account", "Client area"). (2026-10-18)

## Settings

- Every setting changes behaviour; a setting nothing reads is a bug. (2026-10-18)
- Settings use proper controls: selects, toggles, money inputs. Never ask for JSON, internal keys or
  minor units. (2026-10-18)

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
- Every email (auth, billing, orders, services, domains, support, notifications) uses one branded,
  modern template: brand logo and name from `BrandProvider`, theme accent, clear key facts and one
  primary action, responsive, readable in dark mode, with a plain-text alternative. (2026-10-18)
- Every email can be answered: Reply-To is a real, monitored department or support address (never
  noreply), and the footer shows the support address as text. Security emails add "If this wasn't you,
  contact …". Only company or department addresses, never staff members' personal addresses.
  (2026-10-18)
- Email content sits in a centred card with a fixed maximum width, chosen by the design between 600 and
  680px for the best line length. Wider screens only widen the background, and narrow screens shrink the
  card to fit. (2026-10-18)
- Email templates pass on every screen size (320px phone to desktop) and in light mode, dark mode and
  clients that force-invert colours (Gmail, Outlook), with no overflow and AA contrast in each.
  (2026-10-18)
- Emails and in-app messages are written for the customer: a subject that says what happened and what is
  needed, a deliberate preheader, one clear next step with a specific button label ("Pay Rs 12,769.57"),
  plain language without system jargon, polite and calm reminders, and a way to get help. (2026-10-18)
- Third-party integrations show their official logo (from the provider's official brand or merchant
  resources), not a plain text badge. (2026-10-18)

## Work products

- Each topic has one artifact page; update it rather than creating a new page. (2026-10-18)
- Markdown docs use kebab-case file names, except `CLAUDE.md`. (2026-10-18)
- Docs and reports are factual and plain, with no exaggeration or filler. (2026-10-18)
- Progress updates to the owner include screenshots. (2026-10-17)
