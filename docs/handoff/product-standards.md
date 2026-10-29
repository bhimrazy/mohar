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

- Every action that can fail shows its reason where the user acted; actions that can't succeed are disabled with
  the reason. (2026-10-18)

## Forms and ordering

- Details a registry or provider requires say in plain words who qualifies and what each choice means; when
  eligibility depends on the customer's country or business, warn before payment, not after the registry refuses.
  (2026-10-23)
- Validation errors name the field by its label and never show field keys such as `tld_attributes.id`.
  (2026-10-23)
- What a customer enters at order time is shown back in the cart and at checkout before payment, with
  identity numbers masked. (2026-10-23)
- A country is chosen by name from a list, never typed as a code; the ISO code is what is stored. (2026-10-23)

## Settings

- Every setting changes behaviour; a setting nothing reads is a bug. (2026-10-18)
- Company and business details are configurable in the admin panel (one company profile), not in server
  config files. Only infrastructure (keys, URLs, database, queues) stays in the environment. (2026-10-18)
- Destructive or high-impact actions ask for confirmation and state the consequence; irreversible deletes
  need typed confirmation, sensitive actions need password step-up, and forms warn about unsaved
  changes. (2026-10-18)
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

## Security

- Mail that carries a secret (reset links, verification, invitations, 2FA) is never copied (no CC, BCC
  or manual copies), and settings that redirect mail are in the sensitive permission tier. (2026-10-18)
- A private link reaches only its owner: it is never in the subject, staff-written text or CC copies, and
  mail that carried one cannot be resent (the action says to send a new link). (2026-10-18)
- Account-recovery endpoints give the same response for known and unknown accounts and are rate
  limited. (2026-10-18)
- Approve and decline actions on uploaded documents stay disabled until staff have opened the documents, and only
  roles with the review permission (Owner and Admin by default) see them enabled. (2026-10-21)

- Compact amounts use K / L (lakh) / Cr (crore) for INR and NPR and K / M / B otherwise, never the browser's
  locale abbreviations. A currency symbol never wraps away from its number. (2026-10-18)
- Exchange rates read "$1 = Rs 151.94" with at most 6 decimals; full precision only on hover or in exports.
  Converted prices customers see are rounded by default. (2026-10-18)

## Payments

- A payment that has started but isn't confirmed stays visible on the invoice (not only a toast), and
  the customer can't easily pay twice. (2026-10-18)
- Payment errors appear next to the chosen method in plain words with what to do next. Methods that can't
  take this invoice are disabled with the reason. (2026-10-18)
- A gateway that converts currency shows the converted amount and rate before the customer leaves our
  site. (2026-10-18)

## Brand and visuals

- Error pages (403, 404, 419, 429, 500) show the host's brand and a way back, in light and dark mode; never the
  framework's default page. (2026-10-23)
- Customer-facing surfaces (emails, PDFs, the public site, the client area and its login) never show the product's
  own name, mark or tagline unless the host chose them: they use the host's brand settings, with an empty tagline
  and no product mark by default. The product identity (Mohar name, seal, "Web hosting billing, sealed and
  settled.") appears only on staff surfaces such as the admin login. (2026-10-18)
- The public site speaks for the host, not for the billing software. Storefront headlines and copy come from
  the host's own settings, never from the product's own tagline or marketing. Every public page (home, store,
  help, announcements, status) shares one header and footer, and the header lists what the host sells (Catalog
  product groups, domains, support). (2026-10-18)
- The product name and logo come from `BrandProvider` and are configurable; nothing hard-codes
  "Mohar" or "Laravel". (2026-10-17)
- Colours are calm and easy on the eyes. No neon or harsh contrast, and both light and dark modes
  work. (2026-10-17)
- Charts use shadcn Chart (recharts) with theme tokens and look as good as the design
  directions. (2026-10-17)
- Every email (auth, billing, orders, services, domains, support, notifications) uses one branded,
  modern template: brand logo and name from `BrandProvider`, theme accent, clear key facts and one
  primary action, responsive, readable in dark mode, with a plain-text alternative. (2026-10-18)
- Every email the system sends uses an editable template; mail sent outside the template system (framework
  notifications included) is a bug. Plain-text parts are written text, never raw Markdown or HTML. (2026-10-18)
  Named exceptions: the staff invitation, the security notice and the mail server test email (#532). The test
  must fail synchronously with the transport's own error, so a template failure cannot hide it; it goes only to an
  address staff type and uses the branded layout. (2026-10-29)
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

## Background jobs and reports

- A background job that stops partway keeps the operator's remaining work, offers one action to continue it, and its
  report stays reachable after the next run. (2026-10-20, #133 product review)
- Reports never hide failures: failed and skipped items are listed first or behind their own filter, never only in
  the activity log. (2026-10-20)
- Stored messages are full sentences that still read correctly later; relative times ("just now") are rendered at
  view time, never stored. (2026-10-20)
- A destructive (red) tone marks only what failed, never successful lines. (2026-10-20)
- Every date, including dates inside sentences, uses the app's date format. (2026-10-20)
- Demo and sample data match the market the product leads with: host, clients, currency, time zone, date format and domain names are Nepali, and no seeded invoice bills a `.np` domain, and no showcase invoice features a refund until credit notes exist. (proposed 2026-10-22, owner to confirm)

## Simulators and test doubles

- Simulators of third-party services never change or weaken product behaviour: no production footprint, no
  "simulator mode" branches in drivers, overrides only from the environment and refused in production, and the
  full security checks (address guard, pinning, signatures, lookup) stay on against them. (2026-10-18)

## Work products

- Each topic has one artifact page; update it rather than creating a new page. (2026-10-18)
- Markdown docs use kebab-case file names, except `CLAUDE.md`. (2026-10-18)
- Docs and reports are factual and plain, with no exaggeration or filler. (2026-10-18)
- Progress updates to the owner include screenshots. (2026-10-17)
