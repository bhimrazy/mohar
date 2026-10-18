# Visual directions

There are four candidate looks for the admin panel, the client area and (later) the storefront. Each one is a real theme in the app, so the CTO can compare them on working screens instead of mockups. Research is in [RESEARCH.md](RESEARCH.md).

## How to compare them

- Pages (local env, logged in): `/dev/dashboard-preview`, `/dev/invoice-preview`, `/dev/client-preview`, `/dev/components`.
- **Decision (2026-10-17): Calm Fintech is the default.** The other three ship as presets selectable per area in Admin → Settings → Appearance, alongside custom themes.
- On the dev previews the **palette button** switches theme via `?theme=<key>` (local only); elsewhere it saves the staff member's own admin theme. Light/Dark/System stays in `localStorage`/cookie (`appearance`).
- Implementation: each direction is a preset document in `app/Modules/Themes/Resources/presets/<key>.json` (schema `billing-theme` v1). The server renders its tokens as CSS variables. All directions share the same component code. Only tokens differ: colour, semantic status, charts, radius, elevation, shell, density, font.
- Screenshots are in `docs/design/directions/<id>/`: `dashboard-{light,dark}.png`, `invoice-{light,dark}.png`, `client-{light,dark}.png` (1440px wide, full page) and `dashboard-{light,dark}-mobile.png` (390px).

## What every direction shares

The work is mainly structural. It applies to all four directions, and it is where most of the "dull and basic" feeling went:

- **Sidebar.** Grouped and collapsible sections (the state is remembered). Count badges come from `NavItem` badges. A **⌘K search** entry opens a command palette (jump to any page or run a quick-create action). There is a **quick-create (+)** button fed by `Navigation::quickActions()`, and an account footer showing name and email.
- **Admin header.** Sticky and translucent, with breadcrumbs on the left and tools on the right (theme switcher, notifications, help).
- **Dashboard** built around the platform APIs:
    - A KPI row with sparklines and trend pills: MRR, income this month, outstanding, overdue (red tone).
    - An income area chart with a USD/INR/NPR switch and a previous-year comparison.
    - **Needs attention**, matching the shape `Dashboard::resolveTodos()` returns.
    - A stacked new-orders bar chart, income by currency (with the GST/VAT note), automation health (cron, provisioning, gateways, domain sync, email, queue), recent orders and tickets (`list` widget shape), and quick actions with keyboard hints.
    - On mobile, _Needs attention_ moves to the top.
- **New shared components:** `TimeSeriesChart` (the `chart` widget payload, built on shadcn Chart/Recharts), `Sparkline`, `NeedsAttention`, `ListWidget`, `Kbd`, `NavSearch`, `QuickCreate`, `ThemeSwitcher`.
- **Existing components moved onto tokens:** `StatCard` (sparkline, tone, trend pill), `StatusBadge` (semantic tokens plus `--badge-radius`), `DataTable` (density tokens, eyebrow headers), `PageHeader` (eyebrow, display font), `SectionCard`, `Money` (ledger negatives), `Page` (gap token).
- **Colour rules (CTO requirement):** soft, desaturated accents and no neon. Semantic colours are muted, and each has a `-subtle` surface and a `-text` foreground. Dark mode never uses pure `#000` or pure white text. Every pair below clears WCAG AA, and chart colours clear 3:1 against the card (WCAG 1.4.11). Ratios were computed from the token hex values with the WCAG 2.1 relative-luminance formula; the table is below.

---

## 1. Calm Fintech (`calm`)

**Rationale.** Built for operators who think of their panel as a finance tool first: agencies, resellers, anyone who lives in invoices. It is airy and quiet, with one soft indigo accent, Inter, and a white canvas on a pale lavender-grey frame. It borrows the composure of Stripe and Paddle: numbers lead, colour appears only where it means something, and the uppercase micro-labels are small. Of the four, it is the strongest answer to WHMCS's "outdated, cluttered" reputation without feeling unfamiliar.

| Token                          | Light                                             | Dark                                              |
| ------------------------------ | ------------------------------------------------- | ------------------------------------------------- |
| background / foreground        | `#FCFCFD` / `#1A2133`                             | `#12151D` / `#E3E6EE`                             |
| card                           | `#FFFFFF`                                         | `#181C26`                                         |
| sidebar                        | `#F3F4F8`                                         | `#0F121A`                                         |
| primary / on-primary           | `#4F4BC4` / `#FFFFFF`                             | `#8B8FEA` / `#12142A`                             |
| muted-foreground               | `#5B6478`                                         | `#9AA3B5`                                         |
| border                         | `#E4E7EE`                                         | `#272D3B`                                         |
| success (dot · text on subtle) | `#3D9A6E` · `#23704D` on `#EAF6F0`                | `#5BBF8E` · `#7FD1A8` on `#172A22`                |
| warning                        | `#D6A03A` · `#875A0E` on `#FBF3E2`                | `#E2B154` · `#EBC27A` on `#2D2619`                |
| danger                         | `#CF5A55` · `#A6332F` on `#FBEDEC`                | `#E07D78` · `#F09C97` on `#32191B`                |
| info                           | `#4B8BC9` · `#2B6297` on `#EAF2FA`                | `#6FA8E0` · `#94BFEA` on `#17253A`                |
| chart 1–6                      | `#5B5FD0 #3A97B5 #B9822F #9073CF #3E9A78 #D47396` | `#8B8FEA #5BBCD8 #E6B169 #A98FE2 #6CC2A2 #E38EAE` |

- **Typography:** Inter 400–700 (stylistic sets cv11/ss03) with tabular numbers. Headings are 600 with −0.02em tracking. Table headers and sidebar group labels are 11px uppercase eyebrows.
- **Shape and density:** 8px radius (12px cards), soft two-layer shadows, an inset content panel with a 14px radius. Comfortable density: 44px table rows, 24px card padding.
- **Pros:** The most trustworthy feel for money. It works for admins and customers alike, AA everywhere with margin, and it is the closest to what modern SaaS buyers expect.
- **Cons:** It is the safest choice and the least distinctive. Indigo is common in SaaS. It is less dense than power users might want for 25-row tables, though a per-user "compact" density toggle could fix that.
- **Screenshots:** `directions/calm/dashboard-light.png`, `dashboard-dark.png`, `invoice-light.png`, `invoice-dark.png`, `client-light.png`, `client-dark.png`, `dashboard-light-mobile.png`, `dashboard-dark-mobile.png`.

## 2. Operator Pro (`operator`)

**Rationale.** Built for support and ops staff who sit in the panel all day: hosts with large ticket queues and many servers. It is dark-first, dense (15px root, 32px rows), and keyboard-forward (⌘K, `N C` shortcuts). It uses IBM Plex Sans for UI text and **IBM Plex Mono for every figure and ID**, so money columns line up like a terminal. It takes inspiration from Linear and Cloudflare's dashboard, with a soft cornflower accent instead of a neon one. The light theme is a full equal, not an afterthought; Resend added a light mode for exactly this reason.

| Token                   | Light                                             | Dark                                              |
| ----------------------- | ------------------------------------------------- | ------------------------------------------------- |
| background / foreground | `#FAFAFB` / `#1B1E24`                             | `#15171B` / `#E2E4E8`                             |
| card                    | `#FFFFFF`                                         | `#1A1D22`                                         |
| sidebar                 | `#F3F4F6`                                         | `#111316`                                         |
| primary / on-primary    | `#3D6BCC` / `#FFFFFF`                             | `#7398E6` / `#0E1320`                             |
| muted-foreground        | `#5E636D`                                         | `#979DA8`                                         |
| border                  | `#E4E6EA`                                         | `#282C33`                                         |
| success                 | `#3E9A69` · `#24704A` on `#E9F5EE`                | `#5DB585` · `#82CBA3` on `#18271F`                |
| warning                 | `#CC9733` · `#84570C` on `#FAF2E1`                | `#D9AE5C` · `#E3BF7A` on `#2A2418`                |
| danger                  | `#C9534B` · `#A23A33` on `#FBECEA`                | `#DE7A72` · `#EE9A93` on `#2E1B1B`                |
| info                    | `#3F86C2` · `#285F91` on `#E8F1F9`                | `#6FA9DC` · `#93C0E8` on `#172333`                |
| chart 1–6               | `#3D6BCC #2F9C8C #B48327 #8E66C4 #6E9F43 #CC5F78` | `#7398E6 #4FB7A6 #D9A85B #B08BD9 #8FBF6A #DB8095` |

- **Typography:** IBM Plex Sans 400–600 with IBM Plex Mono for numbers and IDs. Labels are sentence case.
- **Shape and density:** 6px radius (10px cards), 4px badges, no shadows in dark mode (borders only), and a flush full-bleed shell. Compact density: about 32px rows, 14px card padding, 16px gaps.
- **Pros:** The most information per screen, and the best fit for a staff tool that replaces WHMCS's crowded admin. It feels fast and it is distinctive. Mono figures make reconciling amounts easy.
- **Cons:** Too technical for the client area and storefront, so it would need a friendlier preset there. The 15px root makes everything slightly smaller, which some older operators may dislike. Mono numbers are wider in narrow cards.
- **Screenshots:** `directions/operator/…` (same file names as above).

## 3. Hosting Warm (`warm`)

**Rationale.** Built for small and medium hosts who sell to non-technical customers, and for the client area and storefront in particular. It is friendly and approachable: cream canvas, terracotta accent, Figtree, generous 16px-radius cards, pill badges, and warm-tinted shadows. Of the four, it is closest to what operators pay Lagom for (polished presets, dark mode that still feels warm), but it stays calm and keeps colour for meaning.

| Token                   | Light                                             | Dark                                              |
| ----------------------- | ------------------------------------------------- | ------------------------------------------------- |
| background / foreground | `#FBF8F4` / `#2A221D`                             | `#1B1715` / `#EEE6DE`                             |
| card                    | `#FFFFFF`                                         | `#231E1B`                                         |
| sidebar                 | `#F3EDE5`                                         | `#161311`                                         |
| primary / on-primary    | `#B4532E` / `#FFFFFF`                             | `#E08F69` / `#1E130D`                             |
| muted-foreground        | `#6B5F56`                                         | `#B1A498`                                         |
| border                  | `#ECE4DA`                                         | `#352D28`                                         |
| success                 | `#4E9A6B` · `#2E6E48` on `#EBF5EE`                | `#6DBB8B` · `#8DCDA6` on `#1D2A21`                |
| warning                 | `#D19A3A` · `#85560E` on `#FBF1DE`                | `#E0B060` · `#EBC27E` on `#30281A`                |
| danger                  | `#C8574E` · `#A23D35` on `#FAEAE7`                | `#E3857B` · `#F0A39A` on `#341F1C`                |
| info                    | `#4C88B5` · `#2C6189` on `#EAF2F8`                | `#77AAD3` · `#9BC3E3` on `#1C2631`                |
| chart 1–6               | `#C9693F #3E9A8C #B4872B #7D6BC4 #CC7393 #5C8FC4` | `#E08F69 #5DB8A9 #E5BD66 #A291DC #E08FAA #7FAEDB` |

- **Typography:** Figtree 400–700. Headings are 700 with −0.02em tracking. Labels are sentence case. Plus Jakarta Sans and Manrope were tried first; their word spacing was too tight at UI sizes.
- **Shape and density:** 12px radius (16px cards), pill badges, warm two-layer shadows, and an inset shell with a 20px radius. Relaxed density: 44–48px rows, 24px card padding.
- **Pros:** The most approachable look, with the strongest brand personality and the best client-area feel. Customers will read it as "a friendly host", not "an ERP". Dark mode is a cosy charcoal, not cold.
- **Cons:** Warm accents can clash with an operator's own brand colours, so the accent needs to be configurable. It is roomy for staff working through long tables. Terracotta sits close to the danger red: badges carry a dot and text, so meaning never depends on colour alone, but keep primary buttons out of error contexts.
- **Screenshots:** `directions/warm/…`

## 4. Enterprise Slate (`slate`)

**Rationale.** Built for larger hosts, MSPs and enterprise resellers who want a neutral, precise and "serious" tool that does not compete with their brand. It is monochrome near-black on white, with Geist and Geist Mono. Blue appears only for focus and the main chart series. Corners are sharp (6px), there are no shadows, and hairline borders separate everything, in the spirit of Vercel's Geist. Dark mode is a soft graphite (`#111113`), not black.

| Token                   | Light                                             | Dark                                              |
| ----------------------- | ------------------------------------------------- | ------------------------------------------------- |
| background / foreground | `#FFFFFF` / `#1A1A1C`                             | `#111113` / `#E6E6E9`                             |
| card                    | `#FFFFFF`                                         | `#161618`                                         |
| sidebar                 | `#FAFAFA`                                         | `#0F0F11`                                         |
| primary / on-primary    | `#1F1F22` / `#FAFAFA`                             | `#E6E6E9` / `#161618`                             |
| muted-foreground        | `#63636B`                                         | `#9D9DA4`                                         |
| border                  | `#E7E7E9`                                         | `#27272A`                                         |
| success                 | `#3F9466` · `#276B47` on `#EEF6F1`                | `#5AB383` · `#7FC9A0` on `#17221B`                |
| warning                 | `#C88E2E` · `#7E520C` on `#FBF3E4`                | `#D7A956` · `#E2BC74` on `#29231A`                |
| danger                  | `#C4504A` · `#A0362F` on `#FBEDEC`                | `#DB7A73` · `#EC9A93` on `#2C1A1A`                |
| info                    | `#3D7FC1` · `#275E94` on `#EBF2FA`                | `#6EA2DA` · `#92BBE6` on `#172231`                |
| chart 1–6               | `#2D6FD6 #8A8A92 #3F9E8F #B5832E #9A6CC8 #D06A84` | `#6B9BE3 #A3A3AB #5DB5A7 #DDB063 #AE8FDB #DD8BA0` |

- **Typography:** Geist 400–600 with −0.03em heading tracking, and Geist Mono for IDs. Labels are sentence case.
- **Shape and density:** 6px radius (10px cards), 4px badges, no card shadows, and a flush shell. Medium density: about 38px rows, 20px card padding.
- **Pros:** Timeless, it looks expensive, and it is brand-neutral, which makes it the easiest for operators to re-skin with their own accent. It has the highest text contrast of the four.
- **Cons:** It can read as cold or austere to small-business customers. Monochrome primary buttons have less pull for calls to action like "Pay invoice". It sits very close to the Vercel aesthetic, which many products now copy.
- **Screenshots:** `directions/slate/…`

---

## Contrast (WCAG 2.1)

These are computed from the token hex values. AA requires 4.5:1 for text and 3:1 for chart marks against the card.

| Direction / mode | fg on bg | muted on card | muted on muted | on-primary | sidebar text | success text | warning text | danger text | info text | lowest chart vs card |
| ---------------- | -------- | ------------- | -------------- | ---------- | ------------ | ------------ | ------------ | ----------- | --------- | -------------------- |
| Calm light       | 15.64    | 5.93          | 5.39           | 6.70       | 9.13         | 5.42         | 5.44         | 5.88        | 5.64      | 3.14                 |
| Calm dark        | 14.62    | 6.72          | 6.11           | 6.22       | 10.12        | 8.33         | 8.93         | 7.68        | 8.00      | 5.85                 |
| Operator light   | 16.01    | 6.03          | 5.38           | 5.03       | 8.08         | 5.37         | 5.63         | 5.73        | 5.86      | 3.13                 |
| Operator dark    | 14.10    | 6.20          | 5.72           | 6.50       | 8.44         | 8.17         | 8.80         | 7.51        | 8.26      | 5.92                 |
| Warm light       | 14.75    | 6.18          | 5.41           | 4.98       | 8.12         | 5.47         | 5.62         | 5.53        | 5.84      | 3.26                 |
| Warm dark        | 14.41    | 6.78          | 6.29           | 7.19       | 9.31         | 8.12         | 8.69         | 7.66        | 8.25      | 5.99                 |
| Slate light      | 17.38    | 5.95          | 5.42           | 15.75      | 8.43         | 5.82         | 6.14         | 6.02        | 5.98      | 3.23                 |
| Slate dark       | 15.14    | 6.71          | 6.17           | 14.51      | 8.10         | 8.40         | 8.65         | 7.57        | 8.00      | 6.39                 |

Status-text ratios are measured on their `-subtle` badge surface. Chart palettes avoid red/green-only pairs: each direction's first three series differ in both hue and lightness, and the legends always carry labels.

## Recommendation

**Make Calm Fintech the product default, and ship Hosting Warm and Enterprise Slate as selectable presets for the client area and storefront. Add Operator Pro's compact density as a per-staff preference rather than a separate look.**

Why:

1. The product promise is "never less than WHMCS, and clearly nicer". The research says the main complaint about WHMCS is how it looks and navigates: outdated, cluttered, hard to learn. Calm Fintech fixes that most directly and puts nobody off, staff or customers. Its AA margins are comfortable, and its indigo accent is soft enough to sit beside most host brands.
2. Operators pay Lagom for **presets**, not a theme builder. All four directions share one token contract, so offering Warm and Slate as client-area presets costs almost nothing. Warm suits SMB hosts and Slate suits MSPs and enterprise.
3. Operator Pro's best idea is density plus mono figures for staff, not a dark, technical brand. We can ship that as a "compact" density setting (the `--cell-py`, `--card-p` and `--root-font-size` tokens already exist) on top of Calm, which gives power users their 32px rows without splitting the visual identity.

The next step, after the CTO picks, is to make that direction the `:root` default in `app.css`, keep the other files as presets, and move the real admin and client dashboards onto the new widgets.

## Needs CTO approval

- **npm dependencies:** `recharts@3.10.1`, published 2026-07-25 and pinned exactly. This is the Recharts version the shadcn Chart component asked for. We also add its peer `react-is@19.2.8` (published 2026-07-21, pinned exactly) so Recharts doesn't fall back to react-is 17 under React 19. The shadcn CLI also tried to add a bogus `cn` package; it was removed.
- **Fonts (OFL, no npm packages):** Inter, IBM Plex Sans, IBM Plex Mono, Figtree, Geist and Geist Mono. They are downloaded from Bunny Fonts at build time by the existing `laravel-vite-plugin` font pipeline and self-hosted from `public/build`, so no third-party requests happen at runtime. Only the default face (Instrument Sans) is preloaded. Once a direction is chosen, we should drop the unused families.
