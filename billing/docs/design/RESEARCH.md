# Design research — billing platform visual directions

## Method & date (today is 2026-10-17)

Desk research using web search and page fetches only (no browsers, no screenshots). Sources are vendor docs, changelogs, design-system documentation, review aggregators (Capterra, GetApp, Trustpilot, G2), and forum threads (WHMCS Community, LowEndTalk). Everything below is paraphrased. **Confidence caveats:** user sentiment on billing panels is thin and often appears on vendor or competitor blogs, so treat it as directional. Reddit and WebHostingTalk threads did not show up in search, so forum sentiment comes mostly from WHMCS Community and LowEndTalk. Where a fact could not be verified (mainly tabular-figure support in some fonts), it is marked **verify**.

---

## 1. Direct market

### WHMCS admin ("Blend", v8+)
- **What it is:** The default admin theme since 8.0. The older "V4" admin theme was removed from the distribution. WHMCS describes Blend as responsive across laptop, tablet and phone. To customise it, you copy the theme folder. https://whmcs.community/topic/302896-admin-v4-theme-system-settings-showing-incorrectly/ · https://developers.whmcs.com/advanced/admin-area
- **What works:** The feature depth is unmatched. The dashboard uses pluggable widgets (System/Automation overview, badges) that modules can extend through hooks. https://whmcsdocs.devops.cpanel.net/8-13/getting-started/the-admin-dashboard · https://developers.whmcs.com/advanced/widgets
- **What feels dated or disliked:** Review aggregators repeatedly call the interface outdated, unintuitive and hard to navigate, and say new staff need time to learn it. Capterra's ease-of-use score is 3.9/5. https://www.capterra.com/p/130157/WHMCS/reviews/ · https://www.getapp.com/finance-accounting-software/a/whmcs/reviews/ · https://zoftwarehub.com/products/whmcs/reviews. In the forums, one user called the default Blend menu awful enough that they rewrote it with a hook (https://whmcs.community/topic/290824-wish-list-for-whmcs-v8/page/3/). Others complained that dashboard widgets jump around when one is minimised and that some widgets cannot be hidden (https://whmcs.community/topic/269973-progress-and-interface-design/). A LowEndTalk user called the admin UI outdated and not user-friendly (https://lowendtalk.com/discussion/comment/2124474/). Reporting is also criticised as inflexible (Capterra, above).

### WHMCS client area ("Twenty-One", plus "Nexus" in 9.0)
- **What it is:** Twenty-One shipped in 8.1, is built on Bootstrap 4, and is the default for new installs. Nexus arrived in 9.0, and Six (Bootstrap 3 era) is being retired. https://developers.whmcs.com/themes/ · https://whmcs.community/topic/304328-from-the-blog-whmcs-81-now-available
- **What works:** The themes are forkable on GitHub, there is a child-theme model, and there is a large marketplace of skins. https://developers.whmcs.com/themes/github/
- **What feels dated:** A Bootstrap look with generic cards, hook-injected sidebars and dense tables. A whole third-party industry exists to replace it (Lagom, many Nexus-based themes such as Navio: https://whmcs.community/topic/360015-navio-for-whmcs-premium-nexus-based-theme/). That market is itself evidence that operators think the stock theme isn't good enough.

### Lagom (RS Studio, WHMCS client theme)
- **What it is:** The best-known premium replacement client area. v2 shipped 4 styles with 5 colour schemes each (https://whmcs.community/topic/311529-lagom-whmcs-client-theme-v2-is-now-available/). Dark mode came in 2.3.0 and recolours illustrations automatically (https://lagom.rsstudio.net/blog/beta-releases-of-lagom-whmcs-client-theme-and-website-builder-2.3.0-1.1.0). Other features: collapsible header nav groups and product comparison (https://lagom.rsstudio.net/blog/new-release-lagom-whmcs-client-theme-2-2-6-and-website-builder-1-0-3), and payment-method logos in the footer (https://lagom.rsstudio.net/blog/lagom-whmcs-client-theme-2-3-4-and-website-builder-1-1-3/).
- **What works:** Polished illustrations, presets operators can choose from instead of designing, a coherent store-plus-client-area look, and fast support.
- **Dislikes:** Updates and patches don't always apply cleanly, and buyers want more assets for the price. https://www.trustpilot.com/review/rsstudio.net?page=2
- **Lesson:** Operators pay for curated presets and dark mode. They don't want a blank theme builder.

### Blesta
- **What it is:** Self-hosted and paid, with a cheaper entry price than WHMCS. https://verpex.com/blog/whmcs-alternatives
- **What works:** A reviewer who switched from WHMCS called it much cleaner, less cluttered and faster (https://www.trustpilot.com/review/www.blesta.com). Widgets can be minimised and configured (https://www.hostingadvice.com/blog/whmcs-vs-blesta-vs-billmanager-vs-others/amp/).
- **Dislikes:** Slow development pace and a weaker ecosystem than WHMCS. https://lowendtalk.com/discussion/comment/3185911/

### HostBill
- Very wide integration list. Reviews are sparse: G2 has one review that calls the interface simple and fully automated. https://www.g2.com/sellers/hostbill · https://www.softwareadvice.com/accounting/hostbill-profile/. There was too little independent UI feedback to draw conclusions.

### FOSSBilling
- Open source. The admin is built on Tabler (Bootstrap 5, MIT), and the client area uses Bootstrap with Font Awesome and Twig templates. https://fossbilling.org/docs/developing-fossbilling · https://forum.fossbilling.org/d/83-new-client-theme
- **Lesson:** "Clean admin template" is the default look of open-source panels. Generic admin kits make every panel look the same, so this is the baseline we need to beat.

### Upmind
- **What it is:** A cloud platform that combines CRM, storefront, billing and service desk, with a free tier priced by number of active clients. https://upmind.com/ · https://docs.upmind.com/for-web-hosts/whmcs-comparison
- **What works:** Users describe the UI as attractive and easy to use. The branded client area lets customers log into the control panel and change nameservers. https://community.enhance.com/d/620/30 · https://www.upmind.com/industries/web-host-billing-system
- **Dislikes:** Settings overload. One user called it complicated and bloated for small setups (same Enhance thread).
- **Lesson:** Modern visuals don't help if the settings pages aren't designed with the same care.

### Paymenter
- **What it is:** MIT-licensed and built on Laravel. The admin is reported to use Filament, and there is a themes directory. https://github.com/Paymenter/Paymenter · https://www.mintlify.com/paymenter/paymenter
- **What works:** It looks like a modern Tailwind app straight away.
- **Pitfall:** It inherits Filament's generic look, which is the same problem as Tabler for FOSSBilling.

### Clientexec
- Described as simple and easy to learn, with built-in ticketing and role-scoped staff access. Sources are mostly promotional. https://g2.com/sellers/clientexec · https://support.theory7.net/hc/en-us/articles/2072-clientexec-affordable-billing-software-starters

**Market takeaway:** People rarely complain that panels lack features. They complain about how hard it is to find things: too many menus, a widget wall on the home page, dense grey Bootstrap tables, and inflexible reports. Operators want polish that comes ready to use (presets, dark mode, curated home), and they will pay for it.

---

## 2. Best-in-class SaaS & fintech

**Stripe Dashboard.** *Ideas to borrow:* Dashboard surfaces are deliberately locked down, with limited custom colours in order to protect contrast. Brand colour is limited to a small app indicator. A side drawer (ContextView) shows extra context next to the main object, and a modal FocusView handles long tasks. There is a `?` shortcut sheet, and search shows results instantly. Stripe also designs colour in perceptually uniform spaces, not by hand-picking tints. https://docs.stripe.com/stripe-apps/design · https://docs.stripe.com/dashboard · https://stripe.com/blog/accessible-color-systems. *Avoid:* copying its indigo-on-white wholesale, because many fintech look-alikes already do.

**Linear.** *Ideas to borrow:* Each theme is generated from three inputs (base, accent, contrast) instead of about 98 hand-set variables, and it is computed in LCH so surfaces step evenly. The redesign cut back the coloured chrome and darkened text in light mode for contrast. Headings use Inter Display and body text uses Inter. Sidebar and tab density were increased. https://linear.app/now/how-we-redesigned-the-linear-ui · https://linear.app/changelog/2024-03-20-new-linear-ui. *Avoid:* hiding actions so they are only reachable by keyboard. Billing staff include part-timers who need visible buttons.

**Vercel (Geist).** *Ideas to borrow:* The colour scales assign roles by step: 100–300 for backgrounds, 400–600 for borders, 700–800 for solid fills, 900–1000 for text (https://vercel.com/geist/colors). Since February 2026 the sidebar is resizable and collapsible, uses the same tabs at team and project level, and has jump-to-any-page search (https://vercel.com/changelog/dashboard-navigation-redesign-rollout · https://vercel.com/try/new-dashboard). Browser tab icons reflect deployment status (https://vercel.com/blog/dashboard-redesign). *Avoid:* pure monochrome on financial data that needs status colour to be read.

**Paddle.** *Ideas to borrow:* The 2025 design system aimed for a minimal, clear and fully accessible dashboard, and it shipped as an opt-in preview so users could switch before it became the default. https://www.paddle.com/blog/paddle-forward-april-2025-product-updates · https://updates.paddle.com/en/a-modern-paddle-billing-dashboard-now-available-as-an-opt_in. *Avoid:* running two dashboards side by side for too long.

**Lemon Squeezy.** No official redesign documentation turned up. Third-party breakdowns describe a light, Inter-based look with a purple accent (https://claudeskills.info/skills/ihlamury/design-skills/lemonsqueezy-ui-skills/), so treat that as unverified. *Ideas to borrow:* friendly merchant-facing tone, which fits "Hosting Warm". *Avoid:* playful styling on operator-critical data.

**Chargebee ("Leap UI").** *Ideas to borrow:* Index pages scroll continuously instead of paging at 20 rows. Action panels on detail pages describe each action and box it. Summaries are hyperlinked with jump-to-section navigation. Each user can switch between the classic and new UI. https://chargebee.com/blog/the-chargebee-redesign · https://www.chargebee.com/docs/billing/2.0/kb/site-configuration/what-is-the-enhancement-on-all-index-pages-on-leap-ui. *Avoid:* infinite scroll on audit or export lists where people need a stable position.

**Plain.** An API-first B2B support tool (https://docs.plain.com). Its keyboard model could not be verified from public docs. *Idea to borrow, conceptually:* a ticket inbox built as a focused queue, which is a good model for our support module.

**Attio.** *Ideas to borrow:* Spreadsheet-like table views where each saved view has its own filters and sort, with bulk actions and sortable columns. https://attio.com/help/reference/attio-101/attio-glossary.md · https://www.saasui.design/pattern/table/attio. Typography-led and low on chrome (unofficial token claims, unverified). *Avoid:* open-ended custom objects, which go beyond our scope.

**Resend.** *Ideas to borrow:* The product is dark by default, and light mode was added once non-technical users found dark mode hard to use. Both themes are built on Radix Colors with Tailwind. https://resend.com/blog/introducing-light-mode. That is direct evidence that a dark-only theme excludes our SMB and finance users.

**Supabase.** *Ideas to borrow:* a published design system, inspired by Radix, shadcn and Geist, with documented UI patterns for empty states, tables, forms, charts, navigation and modality, plus PageContainer/PageHeader/PageSection page scaffolding. https://supabase.com/design-system · https://supabase.com/design-system/docs/ui-patterns/introduction. This is the closest public example of a shadcn-family admin, so we should copy the practice of documenting patterns.

**Cloudflare.** *Ideas to borrow:* In November 2025 Cloudflare One renamed navigation by task instead of by product (e.g. "Traffic policies", with analytics and logs under "Insights") without changing the APIs. https://developers.cloudflare.com/changelog/post/new-cloudflare-one-navigation-and-product-experience/ · https://blog.cloudflare.com/author/pete-thomas. For us, that means grouping by job ("Get paid", "Provision", "Support"), not by module name. *Avoid:* moving sections around often, since every reshuffle breaks users' muscle memory.

**DigitalOcean.** *Ideas to borrow:* Since 2018 it has used left-hand navigation and Projects to group resources by client or environment (https://docs.digitalocean.com/notes/2018/projects-launch). Since 2025 users can explore the panel before adding a payment method (https://docs.digitalocean.com/release-notes/platform/). *Avoid:* the aging blue-on-white look, which is now generic.

**Hetzner.** *Idea to borrow:* In 2025 it began merging legacy panels (Robot, konsoleH, DNS) into one Hetzner Console, product group by product group. https://www.hetzner.com/de/news/hetzner-console/ · https://docs.hetzner.cloud/changelog. That is a good migration story for WHMCS refugees: one console, phased in. *Avoid:* leaving users in mixed old and new panels for years.

---

## 3. Design systems & theme sources

**shadcn/ui theming.** The tokens come in surface/foreground pairs: `background`, `card`, `popover`, `primary`, `secondary`, `muted`, `accent`, `destructive`, plus `border`, `input`, `ring`, `chart-1..5`, eight `sidebar-*` tokens and `--radius` with derived sizes. Examples are in OKLCH. Light values live on `:root`, dark values on `.dark`, and `@theme inline` maps them to Tailwind utilities. The current base colours are Neutral, Stone, Zinc, Mauve, Olive, Mist and Taupe. https://ui.shadcn.com/docs/theming. *Use:* this is our token contract. Each direction becomes one `:root` + `.dark` token file. We would add our own semantic tokens (`success`, `warning`, `info`, each with `-foreground`, plus `-subtle` variants for badges) using the same pattern. MIT.

**tweakcn.** A visual theme editor for shadcn plus Tailwind v4, with presets. Apache-2.0. https://github.com/jnsahaj/tweakcn. *Use:* rapid prototyping of the four directions, exporting CSS variables. Treat its output as a draft and re-check contrast by hand.

**Radix Colors.** 12-step scales. Steps 1–2 are app and subtle backgrounds. Steps 3–5 are component backgrounds for normal, hover and pressed/selected. Steps 6–8 are borders, from subtle non-interactive to interactive to focus/strong. Steps 9–10 are solid fills and their hover. Step 11 is low-contrast text and step 12 is high-contrast text. On a step-2 background, step 11 is guaranteed APCA Lc 60 and step 12 Lc 90. https://www.radix-ui.com/colors/docs/palette-composition/understanding-the-scale. *Use:* the most reliable way to fill shadcn tokens. `background`=1, `card`/`sidebar`=2, `muted`/`secondary`=3, hover=4, selected=5, `border`=6, `input`=7, `ring`=8, `primary`=9, `muted-foreground`=11, `foreground`=12. Badges use subtle-bg=3 with text=11 from the same hue. MIT.

**Radix Themes.** Themes are configured with `accentColor`, `grayColor`, `radius`, `scaling` and `panelBackground`. https://www.radix-ui.com/themes/docs/theme/overview. *Use:* only as a reference for which knobs to expose. Each of our directions is effectively a preset of (accent, gray, radius, scaling/density). We would not adopt the Radix Themes components, because they compete with shadcn.

**Tailwind v4 palette.** Moved to OKLCH and the P3 gamut while keeping v3's balance between colours. Configuration lives in CSS through `@theme`. https://tailwindcss.com/blog/tailwindcss-v4. *Use:* build our scales in OKLCH so all four directions share the same lightness ramp.

**Catalyst (Tailwind Plus).** React plus Headless UI, built for Tailwind v4, works with Inertia, uses Inter as an optional font and Heroicons at 16 and 20px. https://catalyst.tailwindui.com/docs. Access requires a paid Tailwind Plus licence, so we can study it but should not paste its code into an open repository. *Use:* a reference for restrained application-UI proportions, which suit "Calm Fintech".

**Vercel Geist.** Role-by-step colour scales (above). The font is OFL-1.1 and includes Geist Mono. https://vercel.com/geist/colors · https://github.com/vercel/geist-font. *Use:* the backbone of "Enterprise Slate".

**Untitled UI React.** Built on React Aria and Tailwind v4.x. The open-source components are reported as MIT, with PRO under a separate licence (verify the LICENSE file). Components are copied into the project. https://www.untitledui.com/react/docs/introduction · https://github.com/untitleduico/react. *Use:* a reference for marketing and storefront sections and for "Hosting Warm" softness. Its accessibility layer is React Aria, not Radix, so mixing the two needs care.

**Tremor.** Copy-paste chart components built on Recharts, with a `chartColors` array and per-chart `colors` overrides. The default order is blue, emerald, violet, amber, gray, cyan, pink, lime, fuchsia. Tremor has announced it is joining Vercel. https://www.tremor.so/docs/visualizations/area-chart. *Use:* adopt its chart utilities and drive `chartColors` from our `--chart-*` tokens instead of Tailwind names.

**Origin UI.** A large set of shadcn-style Radix components (MIT per mirrors). It is now a legacy snapshot under coss.com, and active work has moved to "coss ui" on Base UI. https://github.com/syntax-syndicate/origin-ui · https://21st.dev/community/originui/library/origin-ui. *Use:* copy individual patterns (inputs, steppers, timelines). Don't depend on it.

---

## 4. Typography

All families below are on Google Fonts. Bunny Fonts works as a drop-in for the Google Fonts CSS v1 API with no logging, which suits GDPR, and that matters for EU hosts (https://fonts.bunny.net/about). Geist is confirmed on Bunny Fonts (OFL, weights 100–900 with italics: https://fonts.bunny.net/family/geist). Self-hosting through Fontsource is the safest option for the admin panel.

| Font | Licence | Source | Tabular nums | Character | Recommended for |
|---|---|---|---|---|---|
| Inter (v4, with display optical size) | OFL-1.1 | Google, Bunny, rsms.me | **Yes**: `tnum`, plus `zero`, `ss02` disambiguation, `cv01`–`cv09` digit alternates (https://rsms.me/inter/) | Neutral workhorse, very legible at 12–14px | Operator Pro (Linear uses Inter/Inter Display), Calm Fintech |
| Geist / Geist Mono | OFL-1.1 (https://github.com/vercel/geist-font) | Google, Bunny, npm `geist` | Mono is inherently tabular. Sans `tnum`: **verify** | Geometric-grotesk, technical, crisp | Enterprise Slate. Geist Mono for IDs, IPs, amounts in any direction |
| IBM Plex Sans (+ Mono) | OFL-1.1 (https://github.com/IBM/plex) | Google, Bunny, Fontsource | **verify** | Engineered and slightly corporate, wide family | Enterprise Slate alternative, Operator Pro mono |
| Manrope | OFL | Google, Bunny | **verify** | Modern, rounded geometric, friendly | Hosting Warm headings |
| Plus Jakarta Sans | OFL | Google, Bunny | Tabular figures added in v2.6 as `.tf` glyphs; check that `tnum` is exposed (https://github.com/tokotype/PlusJakartaSans/releases) | Warm, contemporary geometric | Hosting Warm (body + UI) |
| DM Sans | OFL (https://github.com/googlefonts/dm-fonts, archived April 2026) | Google, Bunny | **verify** | Low-contrast geometric, soft | Hosting Warm or storefront |
| Instrument Sans | OFL-1.1 | Google, Bunny | **Yes**: `tnum`/`pnum`, 12 stylistic sets, width axis 75–100 (https://github.com/Instrument/instrument-sans) | Sharp, editorial grotesk with a condensed width | Calm Fintech headings, storefront |

Rules: always set `font-variant-numeric: tabular-nums` on table cells, totals and KPIs, and confirm the chosen face actually changes widths when it is applied. Use a mono face for invoice numbers, IPs and hostnames. Limit each direction to one sans and one mono.

---

## 5. Data visualisation & colour

- **Accessibility floor:** Chart lines, bars, focus rings and the visual parts of controls need **3:1** contrast against adjacent colours. Lines that don't overlap don't need to contrast with each other, and disabled controls are exempt. https://www.w3.org/WAI/WCAG22/Understanding/non-text-contrast.html
- **Categorical order matters:** Carbon applies its 14-colour categorical palette in a fixed order chosen to maximise contrast between neighbouring colours, with separate light and dark values for each step. https://carbondesignsystem.com/data-visualization/color-palettes/. We should do the same: a fixed order of `--chart-1..8` with separate light and dark values.
- **Colour-blind safety:** Okabe-Ito (orange, sky blue, bluish green, yellow, blue, vermillion, reddish purple, black) is the standard colour-blind-safe categorical set. Keep to about 8 series or fewer. https://thenode.biologists.com/data-visualization-with-flying-colors/research/ · https://conceptviz.app/blog/okabe-ito-palette-hex-codes-complete-reference. Its yellow fails 3:1 on white, so in light mode either darken it or use it only for fills with outlines.
- **Practical recipe for us:**
  1. Use a single hue (the primary) for one-series charts such as MRR or revenue. This is how Stripe and Vercel keep things calm.
  2. Use categorical colours only when categories exist (gateway, product group).
  3. Keep status colours (paid, overdue, suspended) separate from chart series so that "red" always means a problem.
  4. Always pair colour with a label, icon or pattern.
  5. Build in OKLCH with matched lightness per mode: roughly L 0.55–0.65 in light mode, 0.70–0.78 in dark (following the LCH logic Linear and Stripe use).
  6. Validate under deuteranopia and protanopia simulation.

**Accessibility notes:** Normal-size text needs 4.5:1 (WCAG 1.4.3). In dark mode, muted text built from Radix step 11 on step 1 or 2 is the safe choice; going a step lower usually fails. Status badges: text from step 11 of the hue on a step-3 background usually passes 4.5:1 in both modes. Solid step-9 badges need checking, because yellow and amber step 9 need dark text. Badge outlines are decorative, so 1.4.11 doesn't require them. Don't rely on colour alone; add an icon or dot plus a word.

**Density:** Carbon defines table rows at 24, 32, 40, 48 and 64px, with toolbars and pagination matched to row size. https://carbondesignsystem.com/components/data-table/style/. Our proposal is three densities: **compact 32px** (Operator Pro default), **default 40px** (Calm Fintech, Enterprise Slate), and **comfortable 44–48px** (Hosting Warm, client area, touch). Expose density as a user preference, not only as a property of the theme. We found no official published row heights for Linear or Stripe, so claims about their pixel heights are unverified.

---

## 6. Synthesis — principles for our product

1. **Make it easy to find things before adding features.** Group navigation by job (Get paid, Provision, Support, Configure), as Cloudflare did, and add a ⌘K palette with jump-to-any-page search (Vercel, Linear).
2. **One token contract, four presets.** Every direction is a shadcn `:root`/`.dark` token set derived from (accent hue, gray family, radius, density), the Linear/Radix Themes model. No component forks per direction.
3. **Assign colour roles by step.** Map shadcn tokens to Radix or Geist steps (background, component, border, solid, text) so contrast holds whatever accent an operator picks.
4. **Design in OKLCH.** Generate scales with matched perceptual lightness so that brand recolouring by hosts can't break contrast (Stripe, Linear, Tailwind v4).
5. **Ship light and dark from day one, with neither as an afterthought.** Resend's experience shows dark-only shuts out non-technical users, and Lagom shows operators expect dark mode.
6. **Tabular, monospaced numerics everywhere money or IDs appear.** Right-align amounts and always show the currency code alongside the amount (USD/INR/NPR).
7. **Status reads as text plus colour.** A consistent badge system (subtle step-3 background with step-11 text) and one meaning per colour.
8. **The home page is a curated "needs attention" queue,** not a widget wall that rearranges itself. Widgets stay put and can be hidden and restored.
9. **Context next to the work.** Side drawers for related objects (Stripe ContextView) and focused modals for long workflows such as refunds or migrations.
10. **Saved views on every index:** filters, sort and column choice saved per view (Attio), plus bulk actions.
11. **Offer curated presets, not a theme builder,** for the client area and storefront. Hosts choose a preset and an accent; we guarantee it stays accessible.
12. **Document patterns publicly,** covering empty states, tables, forms and page scaffolds, as Supabase does.

### What to avoid
- Bootstrap-era grey card stacks, gradient headers and visible borders on every element.
- A dense admin home made of draggable widgets that reorder themselves.
- Deep nested menus that mirror module names instead of tasks.
- Brand colour in navigation chrome. Keep accent for primary actions and focus (Linear reduced its coloured chrome).
- Dark-only, or a dark mode made by inverting light mode.
- Muted text below 4.5:1, and status shown by colour alone.
- Generic admin-kit sameness (Tabler or Filament defaults), because it reads as "another open-source panel".
- Settings sprawl with no structure: Upmind is criticised for this despite its attractive UI.
- Pure monochrome where financial status must be read at a glance.
- Infinite scroll on audit or financial lists where users need a stable position.

---

## 7. How the findings map to the four directions

**Calm Fintech (Stripe-like, indigo, airy).** The safest bet for the finance-minded operator and for customers paying invoices. Indigo on Radix `indigo` or `iris` steps 9/10 with a slate gray, 40px rows, 8px radius, generous whitespace, and a single-hue revenue chart. Inter or Instrument Sans with Geist Mono for amounts. Risk: looking like a Stripe clone. Distinguish it with hosting-specific iconography and warmer illustration in empty states.

**Operator Pro (Linear-like, dense, dark-first, keyboard-driven).** For high-volume staff working tickets, provisioning and dunning. 32px rows, ⌘K plus a `?` shortcut sheet, low-chroma neutral chrome generated in LCH, Inter/Inter Display, and accent used only for focus and selection. Still ship a strong light theme and keep visible buttons for occasional staff. Fits the admin best, and fits the client area least.

**Hosting Warm (friendly, rounded, warm accent for SMB hosts).** Closest to what Lagom buyers pay for: presets, illustrations, dark mode that recolours art, and a storefront and client area that match. A warm accent (amber, orange or a terracotta step 9; check that its solid fill passes with dark text), a stone or sand gray, 12px radius, and 44–48px comfortable rows. Plus Jakarta Sans or Manrope. Best for the customer-facing surfaces. Keep the admin a notch more neutral.

**Enterprise Slate (Geist/Vercel monochrome, sharp).** For larger hosts, MSPs and procurement-led buyers. Geist plus Geist Mono, gray-alpha borders, 4–6px radius, Geist's role-by-step scale, and a 40px default with compact as an option. Monochrome chrome, but keep status hues at full strength, since pure monochrome fails billing status. Pairs well with an "audit-ready" story: precise tables and visible histories.

## 8. What we did with it (implementation notes, 2026-10-17)

- **Charts.** The CTO asked for the official shadcn/ui Chart component, so we use `components/ui/chart.tsx` with Recharts **3.10.1**, published 2026-07-25 and pinned exactly. We also pin its `react-is` peer at **19.2.8**. Like Tremor, we keep a fixed series order with colours from `--chart-1…6`. The dashboard uses an area chart with a dashed previous-year comparison line, a small stacked bar chart, and sparklines in the KPI cards. Sparklines are lazy-loaded so that pages without charts don't download Recharts.
- **Palette rules.** We started from the guidance in §5 and §6, with one extra rule from the CTO: colours should be easy on the eyes. Accents are desaturated. Semantic colours are muted, and each has a `-subtle` background and a `-text` foreground that clears 4.5:1. Dark backgrounds are never pure black (lightest is #111113) and text is never pure white. Every chart colour clears 3:1 against the card it sits on. The ratios are in `DIRECTIONS.md`.
- **Fonts.** We tested Plus Jakarta Sans and Manrope for Hosting Warm. At 12–13px UI sizes their word spaces looked cramped in our screenshots ("1pending setup"). We switched to **Figtree** (OFL), which keeps the friendly geometric feel with normal spacing.
- **Command palette and quick-create.** We built them as lightweight in-house components, not with a cmdk dependency (§2 Vercel/Linear).
