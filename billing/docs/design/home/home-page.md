# Storefront home page: directions

Status: the owner chose **direction A** (2026-10-18). Revision 1 below brings A in line with the storefront-marketing
spec. B and C are kept as they were for reference. This is design only; no product code has changed.

## Problem

Today `/` (`resources/js/pages/welcome.tsx`) shows a headline taken from the brand tagline, which is the
billing software's own marketing line, a domain search, and two buttons. Most of the screen is empty, there
is no footer, and the header lacks the currency switcher and cart that `/store` has
(`docs/screenshots/home-page/before-home.png`).

The goals:

1. The host writes the headline and text, and sensible hosting defaults ship with it.
2. The page carries what customers come for: domains with prices, plans, help, news, status and ways to pay.
3. Home, store, domains, knowledge base, announcements and network status share one public header and footer.
4. The host controls the page in admin with proper controls.

## Research

Sources were read on 2026-10-18. Summaries are in our own words.

**WHMCS default themes.** Sources: the Twenty-One and Nexus templates on GitHub (`github.com/WHMCS/templates-twenty-one`,
`github.com/WHMCS/templates-nexus`), `developers.whmcs.com/themes/` and the 9.0 release feed.

- The home page is a utility hub. A domain banner comes first: one input, Search and Transfer buttons, and the
  first three featured TLDs with their prices.
- Below it sit three blocks:
    - product-group cards with a tagline and a Browse button, plus register and transfer cards;
    - five help tiles (announcements, network status, knowledge base, downloads, open a ticket);
    - five account tiles.
- There is no plan grid and no announcement feed on the page.
- The header has a knowledge-base search, a cart with a count, and a burger menu on phones.
- The footer has social links, a combined language and currency picker, contact, terms and the copyright line.

**Real hosts.** Sources: hostinger.com and hostinger.com/in, siteground.com, bluehost.com, hetzner.com,
digitalocean.com, milesweb.in, nestnepal.com, websnp.com and himalayanhost.com. Namecheap, Prabhu Host and
BigRock blocked automated fetches, so Prabhu Host is known only from search results.

- **Hero.** Nepali and Indian hosts say what they sell, where, and from what price. Global brands sell the brand.
- **Domain search.** It sits right under the hero, or in the header. A row of TLD prices is common in Nepal
  (Nest Nepal, Himalayan Host).
- **Plans.** Three or four cards, one "Most popular" badge, a billing-term switch and a Compare link
  (MilesWeb, Hostinger, Nest Nepal).
- **Navigation.** A dropdown per product category with one-line descriptions is the norm (SiteGround, Hetzner,
  MilesWeb). Small and WHMCS-based hosts add a Support menu (ticket, knowledge base, announcements) and an
  NPR/USD switch (WebsNP, Himalayan Host). None of the menus we could load showed prices. We add "from"
  prices because the owner asked for them and they answer the first question a buyer has.
- **Trust.** Money-back period, local support every day, uptime and review scores.
- **Payments.** Nepal shows eSewa, Khalti and Fonepay logos. India shows UPI, RuPay, GPay and PhonePe.
- **Footer.** Link columns, legal links (terms, privacy, refund), and a full address. Indian hosts show the
  GSTIN and registration number. Nepali hosts show the address and a VAT note.

**What we take from this.**

- Lead with the host's own words and a domain search with real prices.
- Show real plans with every price visible.
- Keep WHMCS's help tiles, but lower on the page.
- Make the header show what the host sells.
- We do not copy the struck-out "75% off" prices or review widgets. Those need real promotions and a real
  review source, so they wait for the storefront-marketing spec.

## Shared public layout (all directions)

One `PublicLayout` serves home, store, domains, knowledge base, announcements and network status.

- **Header**
    - Left: logo and name from `BrandProvider`.
    - Products: product groups live from Catalog, in the admin's order, with hidden groups left out.
        - With four groups or fewer, each group gets its own link (3 in the preview data).
        - With more, they go into one "Hosting" menu. Each row has the group's headline and its lowest
          price, plus Compare all plans and Search domains.
    - **Domains** menu: Register, Transfer, Pricing.
    - **Support** menu: Knowledge base, Announcements, Network status (with a live open-issue badge),
      Open a ticket, and a Contact us footer link.
    - Right: currency switcher (ISO codes, because the visitor is choosing a currency), cart with count, and
      Log in plus Create account, or Client area when signed in.
- **Phone (375px)**: logo, cart and a menu button. The menu opens a sheet that holds the groups with their
  "from" prices, Domains, Support, the currency, and Log in plus Create account.
- **Network status banner**: shown under the header on every public page while an incident is open.
- **Footer**
    - Company block from the company profile: legal name, address, support email, phone and hours, PAN/VAT or
      GSTIN and registration number.
    - Link columns: Hosting (live groups), Domains, Support, Account.
    - Legal links, the copyright line with the legal name, "Prices exclude tax unless stated" (kept from today's
      footer), and a currency switcher.
    - It never shows a "powered by" line or the software's name.
- **Build**:
    - shadcn `navigation-menu` (Radix: Tab to the triggers, Enter, Space or ↓ opens, arrow keys move,
      Esc closes and returns focus) and `sheet` (Radix Dialog: focus trap, Esc closes).
    - A "Skip to content" link.
    - Phone header targets are 40px.
    - Header data (groups with "from" prices in the visitor's currency, open incident count) is one cached
      shared prop on public routes.

Screenshots: `header-support-menu-light.png`, `header-products-menu-light.png`, `header-products-menu-dark.png`
(menu mode, signed in), `header-domains-menu-dark.png`, `header-phone-sheet-light.png`, `header-phone-sheet-dark.png`.

## Sections

Each section is a separate block that the admin can switch on or off and reorder. In the mockups, add
`?sections=hero,plans,help` to any direction to preview another order.

| Section                                   | Content and data source                                                                                                                                                                                                                                               |
| ----------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Headline and domain search (always first) | Eyebrow, headline and text from Storefront settings. Domain search, then spotlight TLDs (`tlds.is_spotlight`, `badge`) with first-year prices in the visitor's currency. Links to Transfer and All prices.                                                            |
| Why choose us                             | Up to four points the host writes (icon, title, text). Hidden until the host writes them; we ship no claims on the host's behalf.                                                                                                                                     |
| Featured plans                            | One product group chosen by the host. A cycle switch (default cycle is a setting) shows each plan's per-month price and the billed amount with savings against monthly. "Most popular" comes from the product's Featured flag. Configure and order goes to the store. |
| More products                             | The other visible groups and Domains, each with its lowest price and plan count.                                                                                                                                                                                      |
| How can we help                           | Tiles for Knowledge base, Announcements, Network status (live badge), Open a ticket and Contact us. Each tile can be switched off.                                                                                                                                    |
| News and status                           | The latest published announcements (2 to 4), popular knowledge-base articles, and network status (open, scheduled, last resolved).                                                                                                                                    |
| Ways to pay                               | Active gateways shown on the order form, each with the official logo its driver ships (eSewa and Khalti today). Bank transfer and other unbranded methods use an icon and plain text. Card schemes appear only once a card driver ships official assets.              |
| Questions and answers (B)                 | Questions and answers the host writes. The switch stays disabled, with the reason shown, until at least one exists.                                                                                                                                                   |
| Closing call to action (B)                | Lowest plan price, payment logos, and a See all plans button.                                                                                                                                                                                                         |
| Your account (C)                          | Services, Domains, Pay an invoice and Support tickets, plus Log in or Create account.                                                                                                                                                                                 |

The mockups use the preview database's live values: groups and their order, cycle prices in USD and NPR,
spotlight TLDs, two published announcements (the scheduled and draft ones are excluded), knowledge-base
article titles, and the three network incidents. The brand "Acme Hosting" and the company details are
example host data.

## Directions

All three use the Calm Fintech tokens and the same header and footer, and work in light and dark and at 375px.

### A. Storefront (recommended)

Mockup `mockups/a-storefront.html`. The order is:

1. Hero: the headline and domain search with five TLD prices on the left, the most popular plan on the right.
2. Why choose us strip.
3. Featured plans with a cycle switch.
4. More products.
5. How can we help.
6. News, articles and status.
7. Ways to pay.

It balances selling with service: a buyer sees prices and a plan above the fold, and a customer finds help one
scroll down.

Screenshots: `a-desktop-light.png`, `a-desktop-dark.png`, `a-phone-light.png`, `a-phone-dark.png`.

### B. Plans first

Mockup `mockups/b-plans-first.html`, shown in NPR. The headline says what and where. Product-group tabs and plan
cards sit in the hero, followed by:

- a domain block with a register / renew / transfer table;
- more products;
- questions and answers;
- a help strip;
- a closing call to action with payment logos.

It is the closest to Nest Nepal and MilesWeb and the most sales-led. It suits hosts with one main product, but
puts less weight on support and news.

Screenshots: `b-desktop-light.png`, `b-desktop-dark.png`, `b-phone-light.png`, `b-phone-dark.png`.

### C. Customer hub

Mockup `mockups/c-customer-hub.html`. A centred search with two modes (find a domain, search help) comes first,
followed by:

- product-group cards with "from" prices;
- How can we help next to a Your account panel;
- announcements, articles and status;
- We accept.

It is the closest to WHMCS's own home page and familiar to migrating hosts. It sells less: no plan cards.

Screenshots: `c-desktop-light.png`, `c-desktop-dark.png`, `c-phone-light.png`, `c-phone-dark.png`.

### Recommendation

**A.** It is the only direction that covers every goal without trade-offs:

- the host's own headline;
- domains with prices;
- real plans with all cycle prices;
- WHMCS's help shortcuts;
- live news and status;
- payment logos.

B's FAQ and closing call to action, and C's Your account panel, are available in A as optional sections.

## Admin settings

Admin → Settings → Storefront → Home page (`mockups/admin-settings.html`, `admin-settings-{light,dark}.png`):

- **Headline**: eyebrow (40 characters), headline (80), text under it (200), with counters and a reset to the
  default text.
- **Sections**: an ordered list.
    - Each row has a drag handle plus Move up and Move down buttons for keyboard users, an Edit button and an
      on/off switch.
    - The row summary says what the section will show.
    - Featured plans opens a product group select, a first-cycle select (Monthly, Quarterly, Annually,
      2 years, 3 years) and a most-popular plan select.
    - News chooses its counts with selects.
    - Ways to pay links to Payment gateways.
- **Why choose us**: up to four rows, each with an icon select, a title and a text field.
- **Header and footer**:
    - products in the header: Automatic, Always one menu, or Always a link for each group;
    - menu name;
    - legal pages shown in the footer;
    - network status banner on or off;
    - links to Company profile and Appearance → Brand.
- **Preview**: a live preview, a View home page link, and a sticky unsaved-changes bar with Discard and Save.

No setting asks for JSON, keys or minor units. Each setting changes what the page shows.

## Default copy

These defaults ship with the product; the host can change any of them.

- Eyebrow: "Web hosting · Domains · SSL".
- Headline: "Reliable hosting for your website".
- Text: "Fast, secure hosting with clear prices and real people to help. Start with a domain, pick a plan, and
  you're online today."

Alternatives were considered: "Fast, local web hosting with support that answers" (B, for local hosts) and
"Hosting, domains and help in one place" (C, service-led).

## Accessibility check

| Pair                            | Light | Dark |
| ------------------------------- | ----- | ---- |
| Primary links on muted sections | 6.08  | 5.32 |
| Muted text on muted             | 5.39  | 6.11 |
| Status banner text              | 5.44  | 8.93 |
| Call-to-action band heading     | 8.71  | 8.99 |
| Button text on primary          | 6.70  | 6.22 |

All are WCAG AA. Other checks:

- Payment logos sit on a light chip in both modes, so official artwork is never recoloured. The chip text is
  12.07:1.
- No page scrolls sideways at 375px.
- Every image has alt text.
- Tab order is: skip link, brand, groups, Domains, Support, currency, cart, account.
- Menus open with Enter and close with Esc, and focus returns to the trigger.

The issue below is not in this page's scope:

- **Switch, unchecked state (shared component).** The shared `Switch` uses the `input` token for its
  unchecked track, which is 1.36:1 against the card. WCAG 1.4.11 needs 3:1. Raise it when that component is
  next changed.

## Decisions for the owner

1. Direction: decided, A (2026-10-18).
2. Products in the header: Automatic, then one menu from 4 groups (the spec's rule, recommended), or always a menu.
3. Basis for the "from" price: the store uses the cheapest per-month price over the longest cycle
   (`$3.21/mo, billed $76.99 every 2 years`). Keep it for the home page and menus (recommended, consistent),
   or use the monthly price.
4. Network status banner on every public page while an incident is open: on by default (recommended).
5. The company profile, which the footer reads, is not built yet. It needs a small Settings workstream
   before the footer can ship as designed.

## Revision 1: direction A against the storefront-marketing spec

Source: `billing/docs/product/storefront-marketing.md` (branch `feature/storefront-marketing`, commit 564a7b5).
Only A changed. The shared header is unchanged. A and the 404 now use the revision-1 footer; B and C keep the
original footer. A's mockup defaults to NPR. Add `?currency=USD|INR` or `?tax=inclusive` to see the other
cases. Prices are computed from the preview database's minor units, so every figure is the live value.

| Change                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    | Spec criteria met                              |
| ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------- |
| Plan cards show four lines: the per-month price, "Billed Rs 6,189.00 annually" with the saving against monthly, "Renews at Rs 6,189.00 annually", and a tax statement beside each price group. The hero plan card adds the monthly alternative. VPS shows its setup fee. Nothing is struck through.                                                                                                                                                                                                                                                                                                                       | §5.2, §5.4, §5.5; criteria 9, 10, 11           |
| Tax display, both modes, from Billing's tax rules. **Exclusive:** NPR "+13% VAT at checkout", INR "+18% GST at checkout", USD "Tax added at checkout where applicable". **Inclusive:** NPR Rs 6,993.57 with "Prices include 13% VAT". Amounts use lakh grouping.                                                                                                                                                                                                                                                                                                                                                          | §5.4, §5.7; criterion 10                       |
| Domain tiles show the first-year price and the renewal price for each featured extension.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 | §6.2 Domain search; §3 domains                 |
| Payment strip on the home page and in the footer, built from gateways enabled for the visitor's currency. eSewa and Khalti use their official logos and show only for NPR. Bank transfer is plain text because it has no brand. **Card is a placeholder**: a dashed text badge labelled "Text until official logo", until a card driver ships official card-scheme artwork.                                                                                                                                                                                                                                               | §5.6; criterion 13                             |
| The footer reads the company profile: legal name, address, email, phone, WhatsApp, support hours in Nepal time, PAN/VAT number, company registration number and e-commerce registration number. It adds a Company column (About, Contact, Terms of service, Privacy policy, Refund policy), the payment strip, "Prices in NPR: +13% VAT at checkout", social links, the currency switcher, and © with the legal name. There is no "powered by" line.                                                                                                                                                                      | §4 footer, §6.6; criteria 4, 14                |
| Trust facts (Hosting since, Data centres, Money-back) sit under the hero. The money-back point in Why us links to the published refund policy.                                                                                                                                                                                                                                                                                                                                                                                                                                                                            | §6.2 Why us, Trust facts                       |
| Hero buttons (two, set by the host) and a call-to-action band were added.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 | §6.2 Hero, Call to action band                 |
| No carousels or auto-advancing content anywhere. The cycle switch and menus change only when the visitor acts.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            | §6.3 Motion (WCAG 2.2.2)                       |
| Page head is set by the host: title "Acme Hosting \| Reliable hosting for your website", meta description, canonical without a query string, Open Graph tags, `robots`, and `Organization` plus `WebSite` JSON-LD with the same `vatID` and address as the footer.                                                                                                                                                                                                                                                                                                                                                        | §6.7, §7.1–7.3; criteria 14, 16                |
| 404 page (`mockups/404.html`) uses the public layout with `noindex`. It shows the requested path, a domain search, and links to Hosting plans, Knowledge base, Contact us and Home. The server must return HTTP 404.                                                                                                                                                                                                                                                                                                                                                                                                      | §3 404; criterion 15                           |
| Admin mockup additions: **Prices and tax** (with or without tax, a per-currency preview of what visitors see, the first billing cycle, a monthly-breakdown switch); **Legal pages** (Terms, Privacy and Refund, each published, linked or draft, built in or external); **Search and sharing** (title with a 60-character counter, description with a 160-character counter, a search-result preview, share image, hide from search engines); hero button fields; Trust facts and Call to action band in the section list; header rule changed to "3 or fewer"; a draft bar with Discard draft, Open preview and Publish. | §5.4, §6.1, §6.2, §6.6, §6.7; criteria 5–8, 24 |

**Not changed:**

- The "More products" and "News and status" sections stay in A as the owner saw them. More products is not in the
  spec's section list, and Latest news is P1 there.
- The status strip is P1 in the spec (high or critical incidents only). The preview's open incident is high
  priority, so it still shows.

**New screenshots** (`billing/docs/screenshots/home-page/`):

- Home page: `a-desktop-{light,dark}.png`, `a-phone-{light,dark}.png`.
- Tax display:
    - `a-plans-tax-exclusive-npr-light.png`
    - `a-plans-tax-inclusive-npr-light.png`
    - `a-plans-tax-exclusive-inr-light.png`
    - `a-plans-tax-inclusive-inr-dark.png`
- Footer: `a-footer-{light,dark}.png`, and `a-footer-usd-light.png`, where eSewa and Khalti are hidden for USD.
- 404 page: `404-desktop-{light,dark}.png`, `404-phone-{light,dark}.png`.
- Admin: `admin-settings-{light,dark}.png`.

**Checks:**

- There is no horizontal scroll at 375px on A, the 404 or admin.
- The keyboard order and menu behaviour are unchanged.
- The tax chip is 14.17:1 in light and 11.77:1 in dark.
- The placeholder's dashed border is 3.49:1 on the logo chip.

**New decisions for the owner:**

6. Tax display: prices without tax plus "+13% VAT at checkout" (recommended: it matches invoices and the
   current store), or prices with tax included.
7. Where the tax-display setting lives: Storefront (as mocked) or Billing → Tax. The spec's `TaxDisplay` contract
   (K6) belongs to Billing, so Billing owns it either way.
