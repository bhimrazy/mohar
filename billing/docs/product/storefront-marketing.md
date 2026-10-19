# Storefront Marketing — Product Spec (v1)

Status: draft for design and engineering · Owner: Storefront workstream (new module `Storefront`) · Research read 2026-10-18
Clean-room: behaviour comes from public docs and pages of WHMCS, Blesta, HostBill, Upmind, Paymenter, FOSSBilling and eleven
hosting sites (§16). Wording, naming and schemas are ours; no vendor copy is reproduced.
Priorities: **P0** = needed before a host can go live on our public site; **P1** = first follow-up; **P2** = later.

## 1. Purpose and scope

The public site sells the host's hosting, domains and services. This module owns the marketing pages a visitor sees
before buying, the shared public layout (header and footer), the content the host controls on them, and their SEO and
performance. Catalog (products, prices), Domains (TLD prices), Orders (promotions, cart), Support (help content) and
Themes (brand, theme) keep their data and are read through contracts (§11).

**Today:** `/` is a static page (`welcome.tsx`) with its own header and no footer. Its headline is the billing product's
own tagline, its `<title>` is "Welcome", and it shows no plans, payment methods, company details or legal links. Public
pages render only in the browser: no SSR, a generic title, no meta description, no sitemap. The 404 is Laravel's default.

## 2. What the category does, and where we do better

- **Hosts run two sites.** Four of five Nepal and India hosts studied (Prabhu Host, WebHostNepal, Nest Nepal, MilesWeb)
  run a separate PHP or Next.js marketing site and re-skin WHMCS to match. WHMCS feeds such sites through JS data feeds;
  WordPress bridges cache prices (one WHMpress user saw $7.99 shown as $8). Upmind tells hosts to build their own sales
  site. _Better:_ one app, with every marketing price read live from Catalog, so marketing and cart never disagree.
- **No platform has a block-based homepage.** WHMCS uses templates, Blesta one HTML page, Paymenter one markdown field,
  and Upmind a JSON document where one syntax error resets everything. _Better:_ typed sections edited with forms, plus
  draft, preview and publish.
- **Menus are code.** WHMCS navbars change only through PHP hooks; Blesta 5.0 has a GUI editor. _Better:_ the header is
  built from live Catalog groups, and hosts add links with a picker.
- **Price honesty varies.** Shared hosts show a monthly equivalent of a 12–48-month prepaid term. Hostinger defaults to 48
  months and strikes through the renewal price as if it were the "regular" price. SiteGround prints "renews at" under
  the price. Most Nepal hosts show no renewal price, and Prabhu shows "/mo" while its cart sells only annual terms.
  _Better:_ the term total, the renewal price and a tax statement sit next to every headline price (§5).
- **Statutory details are typed by hand.** Nest shows "excl. 13% VAT", MilesWeb "excl. 18% GST". WebHostNepal's footer
  gives registration and VAT numbers; Nest and Hostinger India give none. _Better:_ one company profile feeds the footer,
  the invoices and the Organization markup, and Billing supplies each currency's tax statement.
- **Local payment marks sell** (eSewa, Khalti, Fonepay, ConnectIPS; UPI, RuPay), but as static images that go stale.
  _Better:_ the strip shows only gateways enabled for the visitor's currency, with each driver's official logo.
- **SEO and status gaps.** WHMCS has no built-in sitemap or cookie consent, and network status is login-only by default.
  _Better:_ sitemap, server-rendered head, consent only when needed, public status page by default.

## 3. Pages

| Page              | Route                           | Content                                                                                                 | P   |
| ----------------- | ------------------------------- | ------------------------------------------------------------------------------------------------------- | --- |
| Home              | `/`                             | The host's sections, in the host's order (§6.2).                                                        | P0  |
| All plans         | `/store` (exists)               | Every visible group, billing-cycle toggle, jump bar.                                                    | P0  |
| Group page        | `/store/{group-slug}`           | Headline, tagline, plan cards (§5), comparison, group FAQ, CTA. Hidden groups: by link only, `noindex`. | P0  |
| Comparison        | group page `#compare`           | `<table>` of Catalog comparison rows (K2) by section; featured plan marked; price and renewal rows.     | P0  |
| Domains: register | `/domains`                      | Search first, featured TLDs with register and renew prices, category chips, transfer link.              | P0  |
| Domains: transfer | `/domains/transfer`             | Domain and EPP code into the existing cart flow, transfer prices, steps, KB help.                       | P0  |
| Domain pricing    | `/domains/pricing`              | Searchable, category-filtered table: TLD, register, renew, transfer (1 year). Renew is never hidden.    | P0  |
| Contact           | `/contact`                      | Channels, hours, address, open a ticket. P1: sales form creating a guest ticket in a chosen department. | P0  |
| Legal             | `/legal/{terms,privacy,refund}` | Versioned documents (§6.6). Cookie policy and acceptable use P1; SLA P2.                                | P0  |
| 404               | any unknown public URL          | Public layout, HTTP 404 (never 200), `noindex`, domain search, links to plans, KB and contact.          | P0  |
| About             | `/about`                        | Rich text, trust facts, company details.                                                                | P1  |
| Maintenance       | n/a                             | HTTP 503 with `Retry-After`, public layout without live data, contact details.                          | P1  |

## 4. Shared public layout (MUST, P0)

One layout for every public page: home, store, domains, cart, checkout, KB, announcements, network status, tickets,
legal and 404.

**Header**, left to right:

- Logo and name from `BrandProvider`.
- **Products:** with 1–3 visible groups, one link each. With 4 or more, one menu (label set by the host, default
  "Hosting"); each entry has the group icon, name, one-line tagline and from-price in the visitor's currency, and
  "Compare all plans" sits at the foot. Groups with nothing orderable are left out.
- **Domains** (Register, Transfer, Pricing) and **Support** (Knowledge base, Announcements, Network status with a badge
  for open public incidents, Open a ticket, Contact), then host-added links (§6.4).
- On the right: currency switcher (only with more than one currency; ISO code and symbol), Cart with count, and "Log in"
  plus "Create account", or "Client area" when signed in.
- Radix NavigationMenu, keyboard-operable. On phones, a sheet with the same items and from-prices.

**Status strip (P1).** While a high or critical public incident is open, a strip under the header names it and links
to the status page.

**Footer:**

- **Company block** from `CompanyProfile` (K1): trading and legal name, address, email, phone, WhatsApp, and support
  hours in company time (ADR 0013). Statutory identifiers for the company's country: Nepal registration number, PAN or
  VAT number and e-commerce listing number; India GSTIN, CIN and grievance officer.
- **Link columns:** Products (live groups, Compare all plans), Domains, Support, Account (Log in or Client area, Pay an
  invoice, Reset password), Company (About, Contact, published legal pages).
- Payment-methods strip (§5.6), the current currency's tax statement (§5.4), social links, currency switcher, and
  "© {year} {legal name}". No "Powered by" credit unless the host turns it on.

## 5. Pricing presentation rules (MUST, P0)

1. **Source.** Every price comes live from `PriceBook` or `DomainPricing` in the visitor's currency, through a cache that
   events flush (§10). Hosts never type prices into content.
2. **Plan card.** The headline price is for the selected cycle. With `catalog.show_monthly_breakdown` on, it is shown per
   month and the term total sits on the next line ("Billed $76.99 every 2 years"), never in a tooltip. Non-zero setup
   fees are shown. The card always states renewal ("Renews at $76.99 every 2 years"); without a promotion the price is
   unchanged, and saying so is a selling point.
3. **Cycle toggle.** Lists cycles offered by at least one plan in the group. Default is the host's display cycle (annual
   if offered, else the shortest); admin warns when a default longer than 12 months is chosen. Plans without the chosen
   cycle say so.
4. **Tax statement** per currency from Billing (K6), under every price group and in the footer: "Prices include 13% VAT"
   or "+13% VAT at checkout" (NPR), "+18% GST" (INR), "Tax added at checkout where applicable" (USD).
5. **Discounts.** "Save N%" only against the same plan's monthly price or from a linked Orders promotion. Strikethrough
   only while a promotion is active, and the struck price is the plan's current price, never a renewal price shown as a
   "regular" price. Promotional prices state the end date and the renewal price. Countdowns only for a real
   `expires_on`, never reset per visit. Scarcity only from real Catalog stock (SHOULD).
6. **Payment marks.** Only gateways enabled for the visitor's currency (K7), with the driver's official, unaltered logo
   (`ProvidesLogo`); a method without a licensed logo shows its name as text. Card brands only when a card gateway
   accepting them is on.
7. **Format** per product standards: symbol not ISO code (`$`, `₹`, `Rs`), lakh grouping for INR and NPR, symbol never
   wraps away from its number.

## 6. Host-controlled content (Admin → Website)

Proper controls only: text inputs with counters, rich text (bold, italic, links, lists; stored as sanitised markdown),
pickers, toggles, date-time pickers in company time, image upload to object storage. Never HTML, JSON, keys or minor units.

### 6.1 Draft, preview, publish (MUST, P0)

- **Draft.** All website content (hero, sections, menus, banners, testimonials, FAQ, trust facts) is edited as one draft:
  `storefront_revisions: id, status (draft|published|archived), content (versioned schema), version, note, created_by,
published_by, published_at`. At most one draft; saving never changes the live site.
- **Preview.** A pane beside the editor renders the draft with live prices, at desktop or phone width, light or dark.
  "Open preview" shows the whole site with the draft at a signed URL: staff only, 60 minutes, `noindex`, with a
  "Preview — not live" bar.
- **Publish.** A dialog lists the changes ("Hero headline changed; Testimonials turned on; banner 'Dashain sale' starts
  20 Oct 09:00"); on confirm the draft goes live and `StorefrontPublished` fires. "Discard draft" and "Restore this
  version" (last 20) ask for confirmation; a restore creates a new draft. Unsaved edits warn on leave.
- **Permissions:** `storefront.content.manage` (edit, preview), `storefront.publish`. Publishes, restores and legal
  publishes are audit-logged.

### 6.2 Home sections (fixed types; drag handle plus up/down buttons to reorder; on/off toggle each)

| Section             | Host controls                                                                                                             | P   |
| ------------------- | ------------------------------------------------------------------------------------------------------------------------- | --- |
| Hero                | Eyebrow (≤40), headline (≤80, required), subtext (≤200), two CTAs (label ≤24 + destination), domain search on/off, image. | P0  |
| Featured plans      | 1–3 visible Catalog groups; their plan cards (§5) and "Compare plans".                                                    | P0  |
| Domain search       | Up to 8 featured TLDs from the enabled list, with register and renew prices.                                              | P0  |
| Why us              | 3–6 items: icon from a fixed set, title (≤40), text (≤140).                                                               | P0  |
| Trust facts         | Up to 4 label/value pairs ("Since 2009"). Uptime or money-back claims must link to a published legal or SLA page.         | P0  |
| Payment methods     | Generated (§5.6).                                                                                                         | P0  |
| Help                | Generated: KB, announcements, network status with live state, open a ticket.                                              | P0  |
| Call to action band | Headline (≤80) and a button.                                                                                              | P0  |
| Testimonials / FAQ  | Entries chosen from the libraries (§6.5).                                                                                 | P1  |
| Latest news         | 1–3 announcements from Support.                                                                                           | P1  |

**Destinations** come from a picker: a group or product, a website or legal page, domain search, a KB article or
category, Log in, Create account, or an external `https://` URL (new tab only if ticked).

**Fresh install** publishes Hero, Featured plans, Domain search, Help and Payment methods. The headline is a neutral
placeholder ("Hosting and domains from {company name}") flagged in admin as "needs your text". The product's own tagline
is never used (product standards, 2026-10-18).

### 6.3 Banners and promotions

- **Announcement bar** above the header (P0): site-wide, one at a time, dismissible per visitor. Home promo card and
  plan badges are P1.
- **Fields:** message (≤140), destination, tone (info, success, warning from theme tokens, so AA contrast holds), start
  and end in company time, and an optional linked **Orders** promotion (promotions live in Orders, not Catalog).
- **Linked promotion.** The banner shows only while the promotion is eligible (in dates, not expired manually, uses
  left), checked at render time, so ending it early removes the banner at once. The link applies the code
  (`?promo=CODE`). Cards in the promotion's scope show the first-term price (K3), end date and renewal price.
- **Motion.** No auto-advancing carousels anywhere (WCAG 2.2.2); motion respects `prefers-reduced-motion`.

### 6.4 Menus (P0; nesting under Products or Support P1)

Generated items (groups, domains, support) can be hidden or reordered, not edited. Hosts add up to 6 header links and 8
per footer column (label ≤30, destination). A link whose target is unpublished or deleted is flagged in admin and left
off the public site.

### 6.5 Libraries (P1)

- **Testimonials:** name, role and company, quote (≤300), optional photo, rating (1–5) and source link, a required "I
  have the customer's permission to publish this" tick, visible toggle. Ratings are shown but never emitted as review
  markup, because the host chooses them. Third-party rating badges (Trustpilot, Google) via allowlisted embeds are P2.
- **FAQ:** question (≤150), answer (rich text), placement (home, a group page, domains pages), order.

### 6.6 Legal pages (MUST, P0)

- Terms, Privacy and Refund (Cookie policy and Acceptable use P1), each built in or an external `https://` URL.
- `legal_documents: kind, version, title, body (markdown), effective_on, published_at, published_by`; published versions
  are immutable. Editing makes version N+1; publishing states the consequence ("Orders from {date} accept version N+1;
  earlier acceptances keep their version") and fires `LegalDocumentPublished`.
- `orders.tos_url` defaults to the built-in Terms; with `orders.require_tos` on, the order records the accepted version
  (K8). With no Terms published, dashboard and checkout settings warn. Unpublished documents are linked nowhere. No legal
  text ships with the product (open question 4).

### 6.7 Page SEO fields (P0)

Home, group, domains, About, Contact and legal pages each have a title (≤60, default "{Page} | {Brand}"), meta
description (≤160, default from the page text), share image (default brand logo) and a "Hide from search engines" toggle.

## 7. SEO (MUST, P0 unless tagged)

1. **Head in the initial HTML.** Every public response carries `<title>`, meta description, canonical, Open Graph,
   `robots` and JSON-LD rendered by the server (body SSR is open question 1). The canonical is absolute and drops query
   parameters (`?currency`, `?promo`, tracking). Cart, checkout, search results, previews, tickets and 404 are `noindex`.
2. **Titles** are unique; the home title is "{Brand} | {host tagline}". The h1 matches the page's purpose.
3. **Structured data.** Home and About: `Organization` (or `OnlineStore`) with name, legal name, URL, logo, address,
   contact point, `vatID`/`taxID`, `sameAs`, plus `WebSite`. Inner pages: `BreadcrumbList`. Group pages: `Product` and
   `Offer` per plan (default currency) as product snippets. No `FAQPage` markup (Google retired FAQ rich results in May 2026) and no review markup.
4. **Sitemap.** `/sitemap.xml` indexes page, store, KB and announcement sitemaps, with `lastmod` from real update times.
   Hidden groups, private KB, unpublished and `noindex` pages are excluded. `robots.txt` has a `Sitemap:` line, and a
   "Block search engines" switch serves staging sites.
5. **Redirects (P1):** a changed group or page slug gets a 301 from the old URL. **hreflang (P2):** only once a second
   content language exists.

## 8. Performance and accessibility

- **Core Web Vitals (MUST):** field p75 on mobile, home and group pages: LCP ≤ 2.5 s, INP ≤ 200 ms, CLS ≤ 0.1.
- **Lab budgets (SHOULD),** home page with default sections, checked in CI with tools already in the lockfile: TTFB ≤ 600
  ms warm, public JS ≤ 200 KB gzip on first load (admin code split out), total transfer ≤ 600 KB, hero image ≤ 150 KB
  in AVIF or WebP with `srcset` and explicit size (image pipeline #40).
- **Third-party scripts (MUST):** chat and analytics load after consent where needed and after first interaction or idle;
  they never block rendering.
- **WCAG 2.2 AA (MUST):** contrast 4.5:1 text and 3:1 UI; visible focus never hidden by the sticky header, banner or
  cookie bar; targets ≥ 24 px; reflow at 320 px; skip link and landmarks; comparison as a real `<table>`; prices read
  with their period ("$3.21 per month"); `lang` set.

## 9. Currency, locale and contact channels

- **Currency:** chosen with the switcher and remembered in the cart cookie; default is the company default. Crawlers and
  structured data use the default. Catalog stores a price per currency, so nothing is converted at display. P2: suggest a
  currency from the visitor's country, never redirect.
- **Locale:** English is P0, and support hours follow the host's week (Sun–Fri in Nepal). P2: Nepali and Hindi
  interface text, per-section translated content with fallback, hreflang.
- **Contact channels** live in the company profile. P0: email, phone (`tel:`), WhatsApp (`wa.me`). P1: one live-chat
  provider from a list (Tawk.to, Crisp) with a site ID; its domain joins the CSP allowlist automatically, it loads under
  §8, and only one chat button ever shows.
- **Cookie consent (P1):** shown only when a non-essential integration is on; "Reject" weighs the same as "Accept"; the
  choice lasts 6 months.

## 10. Automation and events

- **Cache:** storefront payloads per currency and published revision, 10-minute TTL as a safety net. Flushed by
  `Catalog\ProductCreated`, `ProductUpdated`, `ProductRetired`, `ProductPriceChanged`, `ProductStockDepleted`;
  `Core\ExchangeRatesUpdated`, `SettingsUpdated`; `Billing\GatewayConfigurationChanged`; `Support\AnnouncementPublished`,
  `NetworkIncidentOpened`, `NetworkIncidentUpdated`, `NetworkIncidentResolved`; and the new `ProductGroupUpdated` and
  `TldPricingChanged` (K4, K5).
- **No scheduled jobs:** banners are evaluated at render time; the sitemap is built on request and cached.
- **Emitted** in `app/Events/Storefront` (ADR 0011): `StorefrontPublished(revisionId, publishedBy, changedSections)` for
  audit, cache and webhooks; `LegalDocumentPublished(kind: LegalDocumentKind, version, effectiveOn)`, consumed by Orders
  for the current Terms version and possibly by Notifications (open question 5).

## 11. Integration contracts (changes this spec implies; compatible under ADR 0008 and 0010)

| #   | Change                                                                                                                                                                                                                   | Kind                | P   |
| --- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ------------------- | --- |
| K1  | New `Core\CompanyProfile` → `Data\Core\CompanyDetails` (names, address, country, email, phone, WhatsApp, hours, social links, statutory IDs by kind). Billing `seller.*` and `core.company_*` move behind it.            | Contract + DTO      | P0  |
| K2  | `PriceBook::storefront()` adds group `headline`, `tagline`, `icon` and product `tagline`, `is_featured`. New `PriceBook::comparison(int $groupId)`: sections of rows (label, help, value per product), owned by Catalog. | Keys + method       | P0  |
| K3  | New `Orders\PromotionDirectory`: `active()` (code, label, ends_on, product scope, cycles) and `preview(code, productId, cycle, currency): ?int`.                                                                         | Contract + DTO      | P0  |
| K4  | `DomainPricing::catalog(string $currency, ?int $clientId = null)`: TLD, category, featured, 1-year register, renew and transfer. New event `Domains\TldPricingChanged`.                                                  | Method + event      | P0  |
| K5  | New event `Catalog\ProductGroupUpdated(groupId)` (created, renamed, hidden, re-sorted).                                                                                                                                  | Event               | P0  |
| K6  | New `Billing\TaxDisplay::statement(string $currency, ?string $country = null)`: inclusive or exclusive, tax name, rate, short label.                                                                                     | Contract            | P0  |
| K7  | `GatewayDirectory::acceptanceMarks(string $currency)`: enabled gateways with label and `?IntegrationLogo`, plus accepted card brands.                                                                                    | Method              | P0  |
| K8  | `OrderPlaced` gains trailing `?int $termsVersion = null`; Orders stores the accepted version.                                                                                                                            | Trailing field      | P0  |
| K9  | New `Support\PublicHelpDirectory` (`openPublicIncidents()`, `recentAnnouncements(int)`) and a `SitemapRegistry` in `app/Support` (ADR 0012) that Support and Orders contribute URLs to.                                  | Contract + registry | P0  |

## 12. Edge cases

- **No visible groups:** Featured plans and the Products menu hide; admin says "Add a product group to show plans".
- **A featured group is hidden or retired:** the section shows the rest and admin flags it.
- **No price in the visitor's currency:** "Not available in INR" with a currency switch; never converted.
- **A promotion ends while cached:** gone within the TTL. The cart always re-quotes, so a stale price is never charged.
- **A menu target or legal page is unpublished:** the link leaves the site and admin warns. Accepted legal versions can
  never be deleted.
- **Two staff edit at once:** saving over a newer draft shows "Changed by {name} at {time}" and offers a reload.
- **Devanagari text** renders with a font fallback; counters count characters, not bytes.

## 13. Open questions for the owner

1. **SSR.** Server-rendering the body needs Inertia SSR: a Node process in production and a changed install path.
   Recommendation: render the head in Blade now (P0) and add SSR for public routes as P1.
2. **Tax-inclusive display for NPR and INR.** Indian buyers expect tax-inclusive prices, and a press report (unconfirmed)
   says Nepal's IRD requires VAT-inclusive prices. Recommendation: a per-currency setting, inclusive by default for NPR
   and INR, after a legal check.
3. **eSewa and Khalti logos.** eSewa's guideline is confidential and needs written consent; Khalti has no public kit.
   Obtain licensed assets, or show names as text meanwhile?
4. **Starter legal text:** ship outlines with placeholders, or nothing? Recommendation: nothing, plus a guidance link.
5. **Terms updates:** email active clients when a new Terms version is published?
6. **"Powered by" credit:** off by default?

## 14. Out of scope (v1)

**Drag-and-drop page builder** (free layouts, arbitrary blocks, custom CSS). A host needs a site that converts and stays
correct, not a design tool. Free layouts break the AA contrast, performance budgets and single layout this spec
guarantees, and stored HTML adds an XSS and support burden. No billing platform in the category ships one, and hosts who
want one already use WordPress. Typed sections deliver most of the value; revisit once there is data on section use.
Also out: blog and per-campaign landing pages (P2 candidates), A/B tests, analytics dashboard, reviews platform,
location-based currency redirects, plan-picker quiz, resold site builders.

## 15. Acceptance criteria

1. **MUST P0** Given 5 visible groups, when any public page loads, then the header has one Products menu listing them in
   Catalog order, each with tagline and from-price in the visitor's currency.
2. **MUST P0** Given 2 visible groups, then the header shows 2 direct links and no Products menu.
3. **MUST P0** Given a group is hidden in Catalog, when `ProductGroupUpdated` fires, then on the next request it is gone
   from header, footer, home and sitemap, and its page still loads by direct link with `noindex`.
4. **MUST P0** Given the KB, announcements, network status, ticket, cart, checkout and legal pages, then each renders the
   same header and footer as home.
5. **MUST P0** Given a fresh install, when `/` loads, then the headline is not the product's tagline and admin marks the
   hero "needs your text".
6. **MUST P0** Given the host saves a new headline, when a visitor loads `/`, then the old one shows until publish.
7. **MUST P0** Given a draft, when staff open preview, then it renders at a signed `noindex` URL; when opened signed out
   or after 60 minutes, then access is refused.
8. **MUST P0** Given staff publish, then a dialog lists the changes and asks for confirmation, and `StorefrontPublished`
   and an audit entry are recorded.
9. **MUST P0** Given a plan at $76.99 every 2 years with the monthly breakdown on, then its card shows "$3.21/mo",
   "Billed $76.99 every 2 years" and "Renews at $76.99 every 2 years".
10. **MUST P0** Given NPR with 13% exclusive VAT, then every price group and the footer say "+13% VAT at checkout", and
    amounts render like `Rs 1,23,456.00`.
11. **MUST P0** Given no active promotion, then no price on any public page is struck through.
12. **MUST P0** Given a banner linked to a promotion expiring 31 Oct, when it is 1 Nov in company time, then the banner
    and promotional prices are gone and its link no longer applies the code.
13. **MUST P0** Given Khalti and eSewa enabled for NPR only, when the visitor's currency is USD, then neither logo shows;
    when it is NPR, then both show.
14. **MUST P0** Given a company profile with a VAT number and address, then the footer shows both and the home page's
    `Organization` JSON-LD carries the same `vatID` and address.
15. **MUST P0** Given an unknown URL, then the response is HTTP 404 inside the public layout, with `noindex`.
16. **MUST P0** Given any indexable public page fetched without JavaScript, then the HTML has a unique `<title>`, a meta
    description, a canonical without query string and valid JSON-LD.
17. **MUST P0** Given `/sitemap.xml`, then it lists home, visible group pages, domains pages, published legal pages,
    public KB articles and announcements, and omits hidden groups, private articles and cart URLs.
18. **MUST P0** Given a group page, then the comparison is a `<table>` with row and column headers, and every plan has a
    price row and a renewal row for the selected cycle.
19. **MUST P0** Given `/domains/pricing` in INR, then each row shows register, renew and transfer prices in INR, and TLDs
    without an INR price are omitted with a note.
20. **MUST P0** Given Terms v2 is published and `orders.require_tos` is on, when a customer checks out, then the order
    stores version 2; after v3 is published, then that order still shows v2.
21. **MUST P0** Given a menu link to a page that is later unpublished, then the link leaves the public site and admin
    warns on that item.
22. **MUST P0** Given the home page with default sections, then lab LCP ≤ 2.5 s and CLS ≤ 0.1 on the mobile profile, and
    no third-party script loads before interaction or consent.
23. **MUST P0** Given keyboard-only use, then every menu, the cycle toggle and the currency switcher work, and focus is
    never hidden under the sticky header.
24. **MUST P0** Given any website content form, then none accepts HTML or JSON, and saved rich text is sanitised.
25. **SHOULD P1** Given an open high-priority public incident, then every public page shows the status strip and the
    Support badge shows "1".
26. **SHOULD P1** Given a group slug changes, then the old URL returns a 301 to the new one.
27. **SHOULD P1** Given a chat provider and required consent, then no chat script loads before "Accept", and "Reject" is
    as prominent as "Accept".
28. **SHOULD P1** Given a testimonial without the permission tick, then it cannot be saved as visible.

## 16. Sources (all read 2026-10-18)

- **Hosts:** hostinger.com (/, /web-hosting, /domain-name-search, /in/); siteground.com (/, /web-hosting.htm,
  /domain_names); digitalocean.com (/, /pricing, /pricing/droplets); cloudways.com/en/ and /en/pricing.php, bluehost.com
  (/, /pricing) (text only); namecheap.com knowledge-base article 10780 (marketing pages blocked); prabhuhost.com and
  portal.prabhuhost.com/store/managed-hosting; webhostnepal.com and client.webhostnepal.com/index.php/store/web-hosting;
  nestnepal.com; milesweb.in (/, /hosting/web-hosting).
- **WHMCS:** developers.whmcs.com/themes/ and /themes/navigation/; github.com/WHMCS/templates-twenty-one and
  templates-nexus (structure only); docs.whmcs.com/9-1/ order-form-templates, general-settings-ordering, data-feeds,
  friendly-urls, network-issues, marketconnect-landing-pages, configure-gst-for-india, 9.0 and 9.1 release notes;
  whmcs.community topic 327293.
- **Other platforms:** docs.blesta.com (portalcms, order-system, 5.0.0); hostbill.atlassian.net wiki 491946, 1212832,
  352944475; docs.upmind.com (creating-a-sales-website, configuring-your-store-with-ui-metadata,
  how-to-create-terms-and-conditions); github.com/Paymenter (themes/default/theme.php, app/Classes/Settings.php);
  github.com/FOSSBilling src/modules; whmpress.com; wordpress.org/plugins/whmcs-bridge (closed 2026-07-06).
- **Standards:** Google Search Central (structured data product, merchant-listing, organization, breadcrumb; search
  updates; build-sitemap; localized-versions; title-link; snippet; consolidate-duplicate-urls); web.dev/articles/vitals;
  w3.org/TR/WCAG22 with Understanding 2.5.8 and 2.2.2.
- **Regulation and brand rules:** FTC fees-rule FAQ; EU price-indication directive Art. 6a; India E-Commerce Rules 2020
  and CCPA dark-patterns guidelines 2023 (law-firm summaries); Nepal E-Commerce Act 2081 (news report, unconfirmed);
  stripe.com/legal/marks; Visa e-commerce brand standards; Mastercard Brand Center; NPCI UPI guidelines; eSewa logo
  guidelines (confidential).
