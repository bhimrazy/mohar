# Docs site

Public documentation for hosts who install and run the billing app. Built with
[Astro Starlight](https://starlight.astro.build) (stock theme, brand colour, Inter, the brand mark). The decision and
the comparison with other tools are in [billing/docs/product/docs-site.md](../billing/docs/product/docs-site.md).

This folder has its own `package.json` and lockfile, so the app's dependencies in `billing/` are untouched. The same
rules apply as in `billing/` (ADR 0007): exact versions, nothing newer than 7 days (`.npmrc` `min-release-age=7`),
and no install scripts (`ignore-scripts=true`). None of the packages needs one: `sharp` and `pagefind` ship their
binaries as optional platform packages.

## Work on it

```bash
cd docs-site
npm ci --ignore-scripts
npm run dev        # http://localhost:8140/
npm run build      # writes dist/
npm run preview    # serves dist/ on http://localhost:8141/ (search works only in a build)
```

Use `/workspaces/whmcs/scripts/heavy.sh npm run build` in the shared codespace.

## Write a page

Pages are MDX files in `src/content/docs/`. The sidebar order is in `astro.config.mjs`. Follow the style guide in
[billing/docs/product/docs-site.md](../billing/docs/product/docs-site.md#style-guide).

- **Product name:** never type it. Import it, `import { product } from '~/product.mjs';`, and write `{product.name}`.
  The name is set once in `src/product.mjs`.
- **Screenshots:** save `src/assets/screenshots/<name>-light.png` (and `<name>-dark.png` if you have one), then
  `<Screenshot name="<name>" alt="What the screenshot shows" />`. The component picks the image that matches the
  reader's theme, makes it zoomable, and Astro converts it to WebP at build time. Take screenshots of a running app
  whose company name is "Example Hosting", at 1280 px wide, and keep each file under 500 KB.
- **Links:** use relative links (`../stripe/`), so the site works under any base path.
- **Commands:** every command must exist in the repository at the commit being documented.

## Deploying (later)

The site is not published (owner decision, 2026-10-20). `npm run build` writes a static site to `dist/` that any
static host can serve. Build it with the address it will have:

```bash
DOCS_SITE=https://docs.example.com npm run build                       # at the root of a domain
DOCS_SITE=https://example.com DOCS_BASE=/docs npm run build            # under a path
```

To push the build to a branch a static host serves (no CI minutes for the build):

```bash
DOCS_REMOTE=<remote or URL> npm run deploy:branch              # dry run: builds and says what it would push
DOCS_REMOTE=<remote or URL> npm run deploy:branch -- --push    # force-pushes dist/ to the gh-pages branch
```

`DOCS_BRANCH` changes the branch and `DOCS_CNAME` writes a `CNAME` file. Publishing from this private repository
with GitHub Pages would make the site public; the owner decides where it goes.
