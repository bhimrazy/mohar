# Docs site — tool choice, publishing and v1 content

Status: v1 built in `docs-site/` (draft PR) · Owner: CTO session · Research read 2026-10-20
Scope: the public documentation for hosts who install and run the app. Internal process documents (`docs/handoff`,
`docs/reviews`, ADRs, specs) never go on it. No WHMCS documentation text is used; every page is written from our own
docs and the running app.

## 1. Decision

**Astro Starlight 0.42.6 on Astro 7.3.8**, in `docs-site/` at the repository root with its own `package.json`,
lockfile and `.npmrc`. It is built on a workstation and pushed to a `gh-pages` branch, so building costs no Actions
minutes. Publishing is one command: `npm run publish:pages -- --push` (§6).

Pinned direct dependencies, each published at least 7 days before 2026-10-20:

| Package                      | Version | Published  | Why                                                                       |
| ---------------------------- | ------- | ---------- | ------------------------------------------------------------------------- |
| `@astrojs/starlight`         | 0.42.6  | 2026-10-08 | the docs theme: sidebar, Pagefind search, light/dark, steps, asides, i18n |
| `astro`                      | 7.3.8   | 2026-10-08 | Starlight's framework (peer `^7.2.10`); 7.4.x is younger than 7 days      |
| `starlight-image-zoom`       | 0.16.1  | 2026-10-08 | click-to-zoom screenshots (0.16.2 of 2026-10-13 is under 7 days)          |
| `@fontsource-variable/inter` | 5.3.0   | 2026-07-19 | Inter, the font of the app's default "Calm" theme, self-hosted; no deps   |

The lockfile has 361 entries (280 installed on linux-x64; the rest are other platforms' binaries). All 361 were
checked against the registry on 2026-10-20: the newest is `postcss` 8.5.30 of 2026-10-12, and every `resolved` URL is
on `registry.npmjs.org`. `npm install --ignore-scripts` works: no package needs an install script (`sharp` and
`pagefind` ship binaries as optional platform packages). `npm audit` reports one moderate advisory
(GHSA-rj75-hqrm-r3gf, quadratic CPU in `postcss-selector-parser` < 7.1.6, reached through Expressive Code). It only
affects parsing our own CSS at build time; nothing reaches readers. No 6.x fix exists, and forcing 7.x under
`postcss-nested` 6 would be untested, so it waits for an upstream bump.

### Why Starlight

1. **Look.** It is the stock theme of Astro's own docs and of Cloudflare, Netlify, Biome and sharp docs: a standard,
   mature docs layout (sidebar, "On this page", search dialog, light/dark/auto) with no decoration to strip. Our
   changes are the brand colour, Inter and the brand mark (§5).
2. **Runtime fit.** Node is already in the repo. The npm rules in ADR 0007 work unchanged (`save-exact`,
   `min-release-age=7`, `ignore-scripts`, lockfile), and Dependabot's npm support can watch the folder.
3. **Search** is Pagefind, built at build time into static files. No service, no key, works on GitHub Pages.
4. **Task pages.** `<Steps>` renders numbered steps with screenshots inside them, which is the page shape the style
   guide asks for (§8). Asides, tabs (Docker / without Docker) and code blocks with copy buttons are built in.
5. **Images.** Astro converts screenshots to WebP at build time, and our `Screenshot` component shows the light or
   dark capture to match the reader's theme.
6. **Maintenance.** Released by the Astro core team; 0.42.1 to 0.42.6 shipped between 2026-09-15 and 2026-10-08.
   Still 0.x, so minor versions can change config; pinning and the cooldown cover that.
7. **i18n later.** Built in, with fallback to the default language for untranslated pages. Nepali (`ne`) has no UI
   string pack yet; we would add `src/content/i18n/ne.json` (Starlight's English file has 26 UI strings, plus Pagefind's search strings).

### Runner-up: Zensical (full evaluation, owner request 2026-10-20)

Zensical is the new generator from the Material for MkDocs team (Zensical LLC: Martin Donath, Timothée Mazzucotelli,
Katharina Linnenkugel). **FastAPI does use it**: FastAPI's `pyproject.toml` docs group lists `zensical >=0.0.42`,
its `uv.lock` pins `zensical` 0.0.65 and has no `mkdocs-material`, `scripts/docs.py` runs `zensical build`, the
migration was PR #15563 (merged 2026-05-19), and the live site's generator tag reads `zensical-0.0.65`. FastAPI still
uses an `mkdocs.yml` with `theme.name: material` and `variant: classic`, builds 13 languages as separate sites, and
hosts on Cloudflare Pages, not GitHub Pages.

| Criterion            | Starlight 0.42.6                                                               | Zensical 0.0.70                                                                                                                                        |
| -------------------- | ------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Out-of-the-box look  | standard and plain; Astro, Cloudflare, Netlify docs                            | the "modern" variant (default, Inter, Lucide icons) is polished; "classic" is Material's look. Both clear the "standard, not AI slop" bar              |
| Search               | Pagefind, static, no service                                                   | its own client-side engine, offline, no service; the search UI is English-only for now                                                                 |
| Screenshots          | WebP conversion; zoom via `starlight-image-zoom`; light/dark via our component | GLightbox zoom built in; `#only-light` / `#only-dark` built in; no image optimisation yet ("optimize" in progress)                                     |
| Admonitions, steps   | asides; `<Steps>` numbered steps; tabs                                         | admonitions, collapsible blocks, content tabs; numbered steps are plain ordered lists                                                                  |
| Maturity             | 0.x, Astro core team, years of releases                                        | 0.0.x, "Development Status: 3 - Alpha"; 0.1.0 "dependable release line" is announced for 2026-11-05, together with paid Studio and Spark products      |
| Cadence and breakage | six patch releases between 2026-09-15 and 2026-10-08                           | a release every 3–4 days (20 between 2026-07-30 and 2026-10-16); 4 releases labelled breaking in 68, plus behaviour changes (Python 3.11+ from 0.0.68) |
| 7-day cooldown       | passes (0.42.6 of 2026-10-08)                                                  | passes with 0.0.70 (2026-10-12); 0.0.71 (2026-10-16) does not                                                                                          |
| Supply chain         | 361 lock entries (npm)                                                         | **11 packages** (pip: zensical + click, deepmerge, jinja2, markdown, pathspec, pygments, pymdown-extensions, pyyaml, tomli, markupsafe), Rust core     |
| Toolchain            | Node 24.21.0, already pinned in `billing/.nvmrc`                               | Python ≥ 3.11 and a hashed `requirements.txt` or `uv.lock` in its own folder; Python is not part of the repo's toolchain or dependency checks today    |
| Local build to Pages | plain `dist/`; our script pushes it                                            | plain `site/`; the official guide shows only an Actions workflow and there is no `gh-deploy` command, but the same push script would work (inferred)   |
| Versioning, i18n     | community `starlight-versions`; i18n built in                                  | fork of `mike` until native versioning; one language per build                                                                                         |

**Verdict: Starlight stays.** Zensical is not clearly better for us. It wins on supply-chain size (11 packages against 361) and has zoom and light/dark images built in, and its modern theme looks as good. It loses on maturity (alpha, a
release every few days, a commercial launch two weeks away), on toolchain (a second language runtime and lockfile
format that our dependency check, Dependabot setup and CI do not cover today), and on image optimisation. Revisit
after Zensical 0.1.x has run for a quarter: the content is plain Markdown plus three components, so moving would cost
a day.

### The other candidates

| Tool                      | Why not                                                                                                                                                                                                                    |
| ------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Mintlify                  | hosted only; a static export exists only on Enterprise, has no search and must sit at a domain root. Starter is free (custom domain, search); Pro is $450/month billed yearly. CLI under Elastic-2.0. 921 npm lock entries |
| VitePress 1.6.4           | clean default theme and local MiniSearch, but the stable line has had no release since 2025-08-05 and 2.0 is still alpha (alpha.20, 2026-09-04). No image zoom, no light/dark content images                               |
| Docusaurus 3.10.2         | 1,275 npm lock entries; local search only through community plugins (Algolia is the official one); zoom via community plugins. Built-in versioning and `docusaurus deploy` to gh-pages are its strengths                   |
| Material for MkDocs 9.7.7 | in maintenance mode: feature development ended with 9.7.0 (2025-11-11), critical fixes until 2027-05-05. MkDocs 2.0 drops plugins and YAML config and is incompatible with it                                              |
| mdBook 0.5.4              | one Rust binary and built-in search, but a book layout (Rust Book style) rather than a product docs site; no image zoom, light/dark images, versioning or i18n                                                             |
| Nextra 4.6.1              | Next.js static export needs `images.unoptimized` and a custom Pagefind step; npm `latest` lags the repo (4.6.5); 482 lock entries                                                                                          |
| Fumadocs 16.17.7          | good components, but a Next.js app to maintain, releases several times a week (six between 2026-10-13 and 2026-10-19), static search downloads the whole index; 328 lock entries                                           |

### Comparison table

Dependency counts are entries in a lockfile resolved on 2026-10-20 with `npm install --package-lock-only
--ignore-scripts` (nothing downloaded or run; includes other platforms' optional binaries), or the wheel set from
`pip install --dry-run --only-binary=:all:` for Python tools.

| Tool                | Latest (date)             | Licence     | Static + gh-pages without Actions                 | Local search, no service  | Image zoom           | Light/dark images        | Versioning               | i18n (Nepali)                   | Dependencies  | Runtime  | Cost                             |
| ------------------- | ------------------------- | ----------- | ------------------------------------------------- | ------------------------- | -------------------- | ------------------------ | ------------------------ | ------------------------------- | ------------- | -------- | -------------------------------- |
| **Astro Starlight** | 0.42.6 (2026-10-08)       | MIT         | yes; guide shows Actions, `dist/` push works      | Pagefind                  | plugin (HiDeoo)      | component (ours)         | community plugin         | built in; `ne` strings to add   | 361 npm       | Node     | free                             |
| Zensical            | 0.0.71 (2026-10-16)       | MIT         | yes (inferred); guide shows Actions only          | own engine                | built in (GLightbox) | `#only-light/#only-dark` | `mike` fork              | one language per build; no `ne` | 11 pip        | Python   | free; paid Studio from Nov       |
| VitePress           | 1.6.4 (2025-08-05)        | MIT         | yes; guide shows Actions                          | MiniSearch                | no                   | logo only                | no                       | built in; labels per locale     | 173 npm       | Node     | free                             |
| Docusaurus          | 3.10.2 (2026-07-10)       | MIT         | yes; `docusaurus deploy` pushes gh-pages          | community plugins only    | community plugins    | `ThemedImage`            | built in                 | built in; no `ne` pack          | 1,275 npm     | Node     | free                             |
| Material for MkDocs | 9.7.7 (2026-07-17)        | MIT         | yes; `mkdocs gh-deploy`                           | lunr                      | glightbox plugin     | `#only-light/#only-dark` | `mike`                   | 69 UI languages; no `ne`        | 29 pip        | Python   | free; maintenance mode           |
| mdBook              | 0.5.4 (2026-07-06)        | MPL-2.0     | yes; commit output to a branch                    | elasticlunr               | no                   | no                       | no                       | third-party helpers             | 1 binary      | Rust bin | free                             |
| Nextra              | 4.6.1 (2025-12-04, npm)   | MIT         | yes, with `output: 'export'` caveats              | Pagefind (postbuild step) | built in             | not documented           | no                       | routing only with export        | 482 npm       | Node     | free                             |
| Fumadocs            | 16.17.7 (2026-10-19)      | MIT         | yes, static mode needs server and client changes  | built-in static index     | component            | not documented           | no (folders or branches) | `uiTranslations()` packs        | 328 npm       | Node     | free                             |
| Mintlify            | CLI 4.2.1072 (2026-10-20) | Elastic-2.0 | no (Enterprise export only, root path, no search) | hosted                    | built in             | two `<img>` with classes | built in                 | no `ne`                         | 921 npm (CLI) | hosted   | Starter $0; Pro $450/mo (yearly) |

## 2. GitHub Pages facts (read 2026-10-20)

- **Plans.** Pages from a **private** repository needs GitHub Pro, Team or Enterprise; GitHub Free allows Pages only
  from public repositories. Pro lists "GitHub Pages" among its private-repository features.
- **Visibility.** A Pages site is public on the internet even when its repository is private. Access-controlled
  Pages exist only for organisations on Enterprise Cloud, so not for this personal account.
- **The account.** `gh api repos/bhimrazy/whmcs` reports `private: true`, `has_pages: false`; `.../pages` returns 404,
  so Pages is not on. The API token cannot read the plan. GitHub Pro includes 3,000 Actions minutes a month (Free has
  2,000), which matches what the owner sees, so the account is most likely on Pro. Pro also includes protected
  branches and required reviewers on private repositories and costs $4/month (the current pricing page no longer
  lists Pro; the figure is from GitHub's plan-change FAQ).
- **Publishing sources.** "Deploy from a branch" (a branch, with `/` or `/docs`) or "GitHub Actions" (a custom
  workflow uploading an artifact). For a branch deploy GitHub runs its own `pages-build-deployment` workflow.
  **Whether that costs minutes on a private repository is unclear**: the current Actions billing page lists use "for
  GitHub Pages" among the free uses without a public-repository qualifier, while GitHub's August 2022 announcement
  said Pages builds on private repositories consume minutes. A pushed static build with `.nojekyll` skips Jekyll, so
  the run is short either way. Check the account's usage report after the first publish.
- **`.nojekyll`.** Without it, Jekyll drops folders starting with `_`, which would remove Astro's `_astro/` assets.
  The publish script writes it.
- **Base path.** A project site is served at `https://<owner>.github.io/<repo>/`, so the build needs `base: '/<repo>'`
  (our default is `/whmcs`, overridable with `DOCS_BASE`). A user site (`<owner>.github.io` repository) or a custom
  domain serves from `/`.
- **Custom domain.** For branch publishing, a `CNAME` file in the branch carries the domain (our script writes it from
  `DOCS_CNAME`); for Actions publishing the file is ignored and the domain is set in settings. DNS: a `CNAME` record to
  `<owner>.github.io` for a subdomain, or `A` records 185.199.108.153, .109.153, .110.153, .111.153 (and the matching
  `AAAA` records) for an apex. Verify the domain under the account's Pages settings (a `TXT` record) to stop takeover,
  avoid wildcard DNS records, and turn on "Enforce HTTPS" once the certificate is issued.
- **Limits.** Published site up to 1 GB; soft limit of 100 GB bandwidth a month; soft limit of 10 builds an hour for
  branch builds. Pages is not meant to run an online business or SaaS: documentation is fine, hosting the app is not.

## 3. Where it lives

```text
docs-site/
  package.json, package-lock.json, .npmrc (copy of billing/.npmrc), .nvmrc (24.21.0)
  astro.config.mjs        site, base, sidebar, brand
  src/product.mjs         the product name, the only place it is written
  src/styles/theme.css    brand colour, font, screenshot frame
  src/components/Screenshot.astro
  src/assets/mark.svg, src/assets/screenshots/*.png
  src/content/docs/**.mdx the pages
  scripts/publish.sh      local build → gh-pages
  README.md               how to work on it and publish
```

It sits outside `billing/` so the app's dependency tree, CI and Dependabot entries are untouched.

## 4. Product name

The name is not final (trademark pending). It is written once, in `docs-site/src/product.mjs`. The site title, the
page text (`{product.name}` in MDX) and therefore the search index read it. Screenshots show a host's own brand
("Example Hosting"), which is what a host sees in the app, so they need no retake on rename. PR #80's
`deploy/compose.prod.yaml` sets the compose project `name: mohar`, so its volumes are called `mohar_*`; the backup
page finds the volume with `docker volume ls --filter name=_rustfs-data` instead of naming it. Renaming the product
should also rename that project (a one-line change in #80, plus a volume migration for existing installs).

## 5. Look

Stock Starlight with three deliberate changes: the accent colour from the app's default "Calm" theme (`#4F4BC4`
light, `#8B8FEA` dark, from `app/Modules/Themes/Resources/presets/calm.json`), Inter (the Calm theme's font), and the
brand mark from `public/brand/mark.svg` with the product name removed from its SVG title. No hero, no feature cards,
no gradients, no emoji. The landing page is a short intro, a numbered "where to start" list and a list of guides.

## 6. Publishing flow (zero Actions minutes for the build)

1. Build and check locally: `cd docs-site && npm ci --ignore-scripts && npm run build` (through `scripts/heavy.sh` in
   the codespace). `npm run preview` serves the build on port 8141, with search.
2. Dry run: `npm run publish:pages`. It builds and prints what it would push.
3. Publish: `npm run publish:pages -- --push`. It writes `.nojekyll` (and `CNAME` when `DOCS_CNAME` is set) and
   force-pushes `dist/` as a single commit to `gh-pages` on `DOCS_REMOTE` (default `origin`). The branch never grows.
4. Once, by the owner: Settings → Pages → Deploy from a branch → `gh-pages`, `/ (root)`.

Not done in this PR: Pages is not turned on and nothing was pushed to `gh-pages`. The site would be public.

**Alternative that avoids the open questions:** publish to a separate public repository (for example
`<owner>/<name>-docs`) by setting `DOCS_REMOTE` to its URL. Pages on a public repository works on any plan, the
private repository stays as it is, and the URL does not contain `whmcs` (§10, Q2).

## 7. v1 content

Host-facing only. Every page was written for this site from our own docs and the running app; commands were checked
against the repository (develop at `3fda1bb8`, and PR #80's branch at `4cbb2be9` for the Docker pages), and the copy
was reviewed by a separate slop check whose findings are fixed.

| Page                               | Draws from                                                                         | Screenshots (light / dark)                                                                                |
| ---------------------------------- | ---------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------- |
| Introduction                       | `product-vision.md`, `feature-map.md`                                              | dashboard (new, both)                                                                                     |
| Get started → Requirements         | `devops/install.md`, `devops/deployment-options.md`, `devops/production.md` (#80)  | none                                                                                                      |
| Install with Docker Compose        | `devops/production.md`, `deploy/.env.production.example` (#80)                     | none                                                                                                      |
| Install on a server without Docker | `devops/install.md` §1–6, `devops/local-setup.md` (trusted proxies)                | none                                                                                                      |
| Sign in for the first time         | `devops/install.md` §7                                                             | dashboard (new, both)                                                                                     |
| Set up → Company and brand         | `install.md` §7, Settings → Company, Appearance                                    | settings-company (new, both), appearance (new, light)                                                     |
| Currencies                         | `currencies` screenshots, Currencies page                                          | currencies (new, both)                                                                                    |
| Tax                                | `product/billing.md` §5, Settings → Billing, Tax rules                             | tax-rules (new, light)                                                                                    |
| Products                           | `product/catalog.md`, product editor                                               | product-groups (new, light), product-pricing (new, both)                                                  |
| Payment gateways (overview)        | `product/billing.md` §6, gateway edit page                                         | gateways (new, both)                                                                                      |
| Stripe                             | `product/payments-stripe.md` §2, §4, driver settings help                          | gateway-stripe (new, light)                                                                               |
| eSewa, Khalti                      | `product/payments-nepal.md` §2.1–2.2, §8, driver settings help                     | none (the gateway page is the same shape as Stripe's)                                                     |
| Bank transfer                      | bank transfer driver, invoice page                                                 | gateway-bank (new, light)                                                                                 |
| Connect a cPanel/WHM server        | `product/provisioning-cpanel.md` §2, §8                                            | server-cpanel (new, both); test-connection (cropped from `screenshots/services/test-connection-passed-*`) |
| Import accounts from cPanel        | `product/provisioning-cpanel.md`, import page (#101)                               | import-accounts (cropped from `screenshots/services/import-accounts-all-*`)                               |
| Day to day → Invoices and payments | `product/billing.md` §3–4, invoice page                                            | invoices (new, light), invoice (new, light)                                                               |
| Automation and the daily run       | `product/billing.md` §7, Automation page, `install.md` §6                          | automation (new, both, cropped to the charts)                                                             |
| Support tickets                    | `product/support.md` §2–3, §6, §16                                                 | tickets (new, light)                                                                                      |
| Run your install → Upgrade         | `devops/install.md` "Upgrading", `devops/production.md` "Updating", "Rolling back" | none                                                                                                      |
| Backups and restore                | `devops/install.md` "Backups", `devops/production.md` "Backups", "Restoring"       | none                                                                                                      |
| Security checklist                 | `security/standards.md` "Go-live checklist"                                        | none                                                                                                      |
| Troubleshooting                    | `devops/install.md` and `devops/production.md` troubleshooting tables              | none                                                                                                      |

New screenshots were taken from a running copy of develop on port 8150, with the company name set to "Example
Hosting", at 1280 px. Two were cropped from existing captures (sidebar removed, because those show the old brand).
Still to take: the client's payment pages for eSewa and Khalti (the existing ones in `screenshots/payments-nepal/`
show the old brand), the Stripe Checkout redirect, and the import step once 3b (#95, #133) merges.

**Depends on PR #80.** The Docker pages and the Docker tabs of Upgrade and Backups document `deploy/compose.prod.yaml`,
which is in #80. Merge this PR after #80, or hold those pages back.

## 8. Style guide

- Plain English. Short sentences. Second person ("you"), present tense.
- One task per page. The title says the task ("Connect a cPanel/WHM server"), not the feature.
- Numbered steps (`<Steps>`) for anything done in order. One action per step; the expected result in the same step.
- A screenshot goes right after the step it shows, inside that step. Only where it helps someone find a control or
  check a result. Alt text says what the screenshot shows.
- Name UI elements exactly as the app labels them, in bold, with the path: **Setup → Gateways → Stripe**.
- Every command must exist in the repository at the documented commit. Copy it from the source doc, run it or check
  it, then paste it.
- No marketing claims or adjectives about the product ("fast", "powerful", "seamless", "easy"). Say what it does.
- No internal process, ADR, PR or agent references on the site. Link to other pages with relative links.
- Never write the product name; use `{product.name}`.
- Warnings (`<Aside caution>`) only for data loss, security or money.

## 9. Acceptance criteria

- MUST: `npm ci --ignore-scripts && npm run build` succeeds in `docs-site/` with no network service at runtime.
- MUST: every direct and locked dependency is pinned and at least 7 days old; no install scripts are needed.
- MUST: no file under `billing/` changes except this spec and its screenshots.
- MUST: search works in the built site without an external service.
- MUST: light and dark mode, desktop and phone, render without horizontal page scroll.
- MUST: the product name appears only in `src/product.mjs` in the site's sources.
- MUST: every internal link resolves (checked on the build: 0 broken).
- MUST: publishing is one documented command and needs no Actions minutes to build.
- SHOULD: screenshots match the reader's theme where a dark capture exists, and zoom on click.
- SHOULD: Dependabot or the dependency check covers `docs-site/` (follow-up, §10 Q5).

## 10. Questions for the owner

1. **Publish publicly now?** A Pages site from this private repository is public. Confirm before anyone turns Pages on.
2. **Where should it live?** The project URL would be `bhimrazy.github.io/whmcs/`, which puts a competitor's trademark
   in our docs address. Options: a custom domain (for example `docs.<product domain>`), or a separate public
   repository named after the product. Both work with the same script.
3. **How do hosts get the code?** The install pages say "the release you are installing". The repository is private;
   is there a download, a public mirror, or an image registry for hosts?
4. **Minutes check.** After the first publish, check Settings → Billing → Actions usage for `pages-build-deployment`.
   If it does count, the separate public repository (Q2) avoids it.
5. **Dependency coverage.** `billing/scripts/check-dependency-age.mjs` and Dependabot read only `billing/`. Extend
   them to `docs-site/` (CTO change), or accept a manual check on each docs dependency bump?
6. **Nepali.** When should the docs get a Nepali version? It needs translated pages and a Starlight UI string file.

## 11. Out of scope (v1)

API and developer documentation, module/driver authoring guides, the WHMCS importer guide (not built yet), client-
facing help (that lives in the app's knowledge base), versioned docs, translations, analytics.

## 12. Sources (all read 2026-10-20)

Tool docs and release data:

- Starlight: https://starlight.astro.build/guides/site-search/, /reference/configuration/, /guides/customization/,
  /guides/css-and-tailwind/, /guides/i18n/, /guides/overriding-components/, /resources/plugins/,
  /resources/showcase/; https://github.com/withastro/starlight (`props.css`, `util.css`, `createTranslationSystem.ts`);
  https://docs.astro.build/en/guides/deploy/github/, https://docs.astro.build/en/guides/images/;
  https://github.com/HiDeoo/starlight-image-zoom; https://sharp.pixelplumbing.com/install
- Zensical: https://zensical.org/docs/get-started/, /docs/upgrade/, /docs/usage/cli/, /docs/usage/build/,
  /docs/setup/search/, /docs/setup/colors/, /docs/setup/fonts/, /docs/setup/logo-and-icons/, /docs/setup/language/,
  /docs/authoring/images/, /docs/authoring/admonitions/, /docs/publish-your-site/, /docs/compatibility/mkdocs/,
  /docs/compatibility/mkdocs/plugins/, /docs/compatibility/mkdocs/mike/, https://zensical.org/upcoming-changes/,
  https://zensical.org/about/team/; https://github.com/zensical/zensical (releases);
  https://pypi.org/pypi/zensical/json
- FastAPI: https://raw.githubusercontent.com/fastapi/fastapi/master/pyproject.toml, `uv.lock`, `docs/en/mkdocs.yml`,
  `scripts/docs.py`, `.github/workflows/build-docs.yml`, `.github/workflows/deploy-docs.yml`;
  https://github.com/fastapi/fastapi/pull/15563; https://fastapi.tiangolo.com/
- Material for MkDocs and MkDocs: https://squidfunk.github.io/mkdocs-material/,
  /blog/2025/11/05/zensical/, /blog/2025/11/11/insiders-now-free-for-everyone/, /blog/2026/02/18/mkdocs-2.0/,
  /publishing-your-site/, /setup/setting-up-site-search/, /reference/images/, /setup/changing-the-language/,
  /setup/setting-up-versioning/; https://www.mkdocs.org/user-guide/deploying-your-docs/;
  https://github.com/mkdocs/mkdocs/discussions/4077; https://pypi.org/pypi/mkdocs/json,
  https://pypi.org/pypi/mkdocs-material/json
- VitePress: https://vitepress.dev/guide/deploy, /reference/default-theme-search, /guide/markdown, /guide/i18n;
  https://github.com/vuejs/vitepress (CHANGELOG, theme CSS)
- Docusaurus: https://docusaurus.io/docs/deployment, /docs/search, /docs/markdown-features/assets,
  /docs/api/plugins/@docusaurus/plugin-ideal-image, /docs/i18n/introduction, /docs/versioning,
  /community/resources, /showcase
- mdBook: https://rust-lang.github.io/mdBook/format/configuration/renderers.html, /continuous-integration.html,
  /guide/reading.html; https://github.com/google/mdbook-i18n-helpers
- Nextra: https://nextra.site/docs/guide/static-exports, /docs/guide/search, /docs/guide/i18n, /docs/guide/image;
  https://nextjs.org/docs/app/api-reference/config/next-config-js/basePath
- Fumadocs: https://fumadocs.dev/docs, /docs/deploying/static, /docs/search/orama, /docs/ui/components/image-zoom,
  /docs/internationalization
- Mintlify: https://www.mintlify.com/pricing, /docs/installation, /docs/deploy/export,
  /docs/api/static-export/overview, /docs/customize/custom-domain, /docs/create/image-embeds,
  /docs/organize/navigation
- npm registry metadata (versions, dates, licences, dependencies): https://registry.npmjs.org/{vitepress,
  @astrojs/starlight, astro, @docusaurus/core, nextra, fumadocs-core, mint, starlight-image-zoom,
  @fontsource-variable/inter, sharp, pagefind, postcss-selector-parser}; GitHub releases of rust-lang/mdBook

GitHub Pages and plans:

- https://docs.github.com/en/pages/getting-started-with-github-pages/what-is-github-pages, /github-pages-limits,
  /configuring-a-publishing-source-for-your-github-pages-site, /creating-a-github-pages-site,
  /changing-the-visibility-of-your-github-pages-site, /using-custom-workflows-with-github-pages,
  /securing-your-github-pages-site-with-https
- https://docs.github.com/en/pages/setting-up-a-github-pages-site-with-jekyll/about-github-pages-and-jekyll
- https://docs.github.com/en/pages/configuring-a-custom-domain-for-your-github-pages-site/managing-a-custom-domain-for-your-github-pages-site,
  /verifying-your-custom-domain-for-github-pages
- https://docs.github.com/en/get-started/learning-about-github/githubs-plans,
  /faq-about-changes-to-githubs-plans; https://docs.github.com/en/billing/concepts/product-billing/github-actions;
  https://github.com/pricing; https://github.blog/news-insights/product-news/github-pages-now-uses-actions-by-default/
