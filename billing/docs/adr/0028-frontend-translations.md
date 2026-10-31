# ADR 0028 — Translations for the frontend, mail and PDFs

- Status: Accepted · 2026-10-31 (owner; the PDF engine spike and who translates are deferred) · Owner: Principal architect. From the 10-29 reviews
  (`docs/reviews/2026-10-29-oss-comparison.md` §7 and follow-up 1; `docs/reviews/2026-10-29-whmcs-parity.md` §2.18).
- Related: ADR 0003 (Inertia and React), ADR 0007 (pins and cooldown), ADR 0013 (company clock), ADR 0017
  (background work), ADR 0020 (hot-path reads), ADR 0024 (fiscal documents), ADR 0025 (reports; Devanagari in PDFs),
  ADR 0027 (fragments). Roadmap wave 4: "Translations (Nepali and Hindi first) and language overrides".

## Context

What exists on `develop` at 88601f81:

- **PHP.** About 1,410 `__()` / `trans()` calls in `app/`, all with the English text as the key
  (`__('Payment received. Thank you.')`), a few `trans_choice()`. There is no `billing/lang/` directory, so every call
  falls back to its key. `config/app.php` reads `APP_LOCALE` (default `en`).
- **React.** 159 page files under `resources/js/pages` (360 `.tsx` files in all) and no translation function anywhere:
  every string is hard-coded English. Shared props already carry display settings: `name` (from `BrandProvider`),
  `currencyFormats`, `dateFormat` (`HandleInertiaRequests::share`), applied by `lib/shared-formatting.ts`.
  `lib/date.ts` holds an English `MONTHS` array.
- **Mail.** Templates are stored per locale (`EmailTemplateTranslation`, unique on template and locale), with the
  fallback chain recipient locale → `notifications.default_locale` setting → `en` (`EmailTemplate::translationFor`).
  `RecipientResolver` puts `clients.language` on the `Recipient`. `MailDates` formats with Carbon's
  `translatedFormat` in the given locale. The mail frame's own labels go through `__()` (`MailFramer`). The layout
  sets `lang="{{ $locale }}"`.
- **Who has a language.** `clients.language` (string(10), default `en`), set by the importer and `ClientResource`.
  `users` has no locale column, so staff have none.
- **Formatting.** Money goes through the currency's configured display (lakh grouping is a currency setting, ADR 0002,
  `MoneyFormatter` and `lib/money.ts`). Dates use the "Date format" setting (`DateFormats`) in the company time zone
  (ADR 0013). Bikram Sambat dates exist in Billing only (`Billing/Support/BikramSambat*`, English month names, used on
  fiscal invoices).
- **PDFs.** dompdf 3.1.6 through `barryvdh/laravel-dompdf`, `DejaVu Sans`. DejaVu has no Devanagari glyphs and dompdf
  does no OpenType shaping (ADR 0025, Known limitations). The owner decided on 2026-10-23 not to add a font or change
  the PDF engine for reports. Render sites: `InvoicePdf`, `TaxNotePdf`, `Support/Reports/PdfExportWriter`.

Other projects: FOSSBilling extracts gettext strings into a POT file that CI checks for freshness; Paymenter ships 24
locales in Laravel `lang/` files synced through a translation platform. Every React page added before Mohar has a
mechanism is a page to retrofit, so the mechanism is decided now and the Nepali translation comes later.

## Options

### A. Laravel JSON files, shared to React by Inertia, with a small in-repo `t()` (recommended)

One catalogue per locale (`lang/ne.json`), read by `__()` on the server and by a `t()` helper of about 150 lines in
`resources/js/lib/i18n.ts`. The helper copies Laravel's rules: `:name` / `:Name` / `:NAME` placeholders and the
`|` plural syntax with `{0}` and `[2,*]` ranges.

- One string, one translation: "Total" in a PDF and "Total" on a page are the same key, so translators see each string
  once, and in one format.
- English is the key, which matches the 1,410 existing calls. English needs no catalogue file and sends no bytes.
- No new package, so no ADR 0007 approval, cooldown or upgrade churn.
- Costs: we own the plural rules on the JS side, a rich-text component, and the extraction check. There is no ICU
  `select` or gender syntax; Nepali and Hindi do not need it for this product's strings.

### B. i18next with react-i18next (or FormatJS / react-intl)

Mature: plurals through `Intl.PluralRules`, a `<Trans>` component, lazy namespaces, an extraction tool
(`i18next-parser`).

- Two to four new npm packages (runtime, React binding, an ICU plugin, a parser), each needing owner approval and the
  7-day cooldown, plus their upgrades.
- A different syntax (`{{name}}`, `_one` / `_other` key suffixes) from Laravel's (`:name`, `|`). Mail, PDF,
  validation and flash strings stay in Laravel, so there are either two catalogues in two formats (strings shared by
  both sides translated twice) or a converter we write anyway.
- It solves the part we can write in an afternoon (lookup, interpolation, plurals) and leaves the hard part (one
  catalogue across PHP and React, the CI check) to us.

### C. Laravel lang files plus a generator into static per-locale bundles

A Vite plugin (in-repo, or a community package such as the "laravel-react-i18n" family) compiles `lang/*.json` into
hashed per-locale chunks, optionally with typed keys.

- The catalogue is a cached static asset, so no prop payload.
- It is build-time only. Admin language overrides (roadmap wave 4, WHMCS parity "Language overrides") change strings
  at runtime, so a runtime path is needed anyway. Switching locale loads a chunk. Community packages are small, with
  bus-factor risk, and need approval.
- The call sites (`t('...')`) are the same as in A, so A can move to this delivery later without touching pages.

## Decision

Option A. Each rule below is enforced by the check in §6 or by a test, not only in review.

### 1. Catalogue and keys

- Source strings are English and are their own keys, on both sides. `billing/lang/<locale>.json` holds the first-party
  translations, one key per line, sorted, so parallel translation PRs merge cleanly. English needs no file, apart from
  context keys (below).
- Framework strings with short keys (validation, auth, passwords, pagination) live in `billing/lang/<locale>/*.php`, the
  only PHP-array files. Validation attribute names go in that locale's `validation.php` `attributes`.
- **Context.** Where one English string needs two translations, the key carries a bracketed context,
  `"Open [ticket status]"`, and `lang/en.json` maps it to `"Open"`. The check fails a bracketed key that has no `en.json`
  entry.
- **Keys are literals.** The first argument of `__`, `trans`, `trans_choice`, `t`, `tChoice` and `<Trans k>` is a string
  literal. A dynamic key (`__($key)` in `MailFramer`) is allowed only at sites listed in the check's allow-list, and the
  possible keys must appear as literals elsewhere (`tKey('...')` in TS, an array of literals in PHP), so extraction
  stays complete.
- **The server sends translated labels.** Navigation, enum and status labels, validation errors, flash messages and API
  resources that carry labels are translated in PHP in the request locale. React translates only its own literals.
- **Packages.** `ModuleServiceProvider` loads `Resources/lang/*.json` with `loadJsonTranslationsFrom` when the folder
  exists, so a third-party package ships its translations without editing core. Laravel reads app paths after package
  paths, so `billing/lang` overrides a package string. First-party modules use the central catalogue, because their
  React pages live centrally in `resources/js/pages`.

### 2. React helper

`resources/js/lib/i18n.ts`, no dependency:

- `t(key, replace?)` returns a string. A missing key returns the key with replacements applied, as `__()` does.
- `tChoice(key, count, replace?)` follows `trans_choice`: explicit ranges first, then the plural index for the locale.
  The index comes from `Intl.PluralRules` mapped to Laravel's `MessageSelector` (both treat `ne` as one/other with
  `n == 1`, and `hi` as singular for 0 and 1). One fixture (`tests/fixtures/i18n/plurals.json`) runs in Pest and in the
  JS tests, so the two sides cannot drift.
- `<Trans k="Read the :terms before you pay." values={{ terms: <Link …>{t('terms of service')}</Link> }} />` for
  sentences that contain elements. Strings never hold HTML; neither side renders a translation unescaped (no
  `{!! __() !!}`, no `dangerouslySetInnerHTML` with a translation).
- `tKey(key)` returns the key untranslated and only marks it for extraction.
- `:brand` is filled from the shared `name` prop when the caller does not pass it (the brand rule, §5).

### 3. Delivery to React

- Shared props gain `locale` (BCP 47, for example `ne-NP`) and `translations`. `translations` is
  `Inertia::once(...)->as("i18n:{locale}:{version}")`: sent once and then reused across visits, and sent again only
  when the locale or the catalogue version changes. The version is a hash of the catalogue files, computed at deploy
  and cached, not stat-ed per request.
- English sends `{}`.
- First version: the whole locale catalogue. When a locale's catalogue passes 100 KB gzipped, the build writes the list
  of keys used under `resources/js` to `bootstrap/cache/` and the prop sends only those. Per ADR 0020, the server-side
  cost (one JSON decode per request in a non-English locale) is measured in the load test, with a budget of +5 ms p95 on
  the client dashboard.

### 4. Locale selection

- **Available** locales are config, because they ship with the code: `config/localization.php` lists code, native
  name, BCP 47 tag, `dir` (always `ltr`; RTL is out of scope) and `ready` (strings reviewed). **Enabled** locales and the
  **install default** are settings: `localization.enabled` and `localization.default`. The second one replaces
  `notifications.default_locale`: `EmailComposer::defaultLocale()` reads the new key, then the old one, and the old
  key is removed one release later.
- Kernel: `App\Support\Localization\Locales` (available, enabled, default, `resolve(?string): string`, which returns
  an enabled locale or the default). No new contract interface: recipients implement Laravel's
  `Illuminate\Contracts\Translation\HasLocalePreference`.
- **Request locale** (Core `SetLocale` middleware, which sets it on every request, default included, so no
  long-running process carries one over): (1) the signed-in user's `users.locale` (new, nullable, for staff and
  client logins alike); (2) in the client area, the current client account's `clients.language`; (3) for a guest, a
  `locale` cookie set by the language switcher; (4) the install default; (5) `en`. `Accept-Language` is not read. The
  middleware also sets Carbon's locale and the `<html lang>` attribute (already wired to `app()->getLocale()`), which
  WCAG 3.1.1 needs.
- **Mail, PDFs and queued work never use the request locale.** Mail to a client uses `clients.language` (the current
  `Recipient`); mail to staff uses `users.locale`; both then fall back to the install default. A job carries its
  locale in its payload and renders inside `withLocale($locale, …)` (Laravel's `Localizable`), never `App::setLocale`,
  because a queue worker runs the next job in the same process (ADR 0017). An arch test allows `setLocale` only in
  `SetLocale` and in the test helpers.
- **Fiscal documents** (ADR 0024) record the locale they were first rendered in, and reprints use it, so a copy matches
  the original. This needs an ADR 0024 amendment when the first non-English fiscal PDF is built.
- The public API does not localise its error messages (machine consumers parse them); `Accept-Language` is ignored
  there too.

### 5. Formatting, Nepal specifics and the brand

- **Money** keeps following the currency display (lakh grouping, symbol position) whatever the language. The UI
  language changes only words.
- **Digits** are Latin in every locale by default: amounts, invoice numbers, dates, phone numbers and PANs must
  survive copy, paste and search, and match IRD documents. JS formatters use the locale tag with `-u-nu-latn` (CLDR's
  default digits for `ne` are Devanagari). Devanagari digits are an owner decision (below), as a later display setting.
- **Plain numbers** (counts, quantities) use the grouping of the install's default currency display, so a Nepal install
  reads `12,34,567` in English and Nepali alike. `formatNumber()` joins `lib/money.ts`; the server uses `MoneyFormatter`'s
  grouping.
- **Dates** keep the "Date format" pattern and the company time zone. Month and weekday names come from the locale
  (`Intl.DateTimeFormat` in JS, replacing the `MONTHS` array; Carbon `translatedFormat` in PHP, as `MailDates` already
  does). Bikram Sambat month names go through `__()`, so `ne` shows पुस, not "Poush". BS conversion stays in Billing
  until a second module needs it; then it moves to `App\Support` as one kernel change.
- **Brand.** The product name appears in strings only as the `:brand` placeholder, filled from
  `BrandProvider::brand()->name` (PHP passes it explicitly; `t()` defaults it from the shared prop). The check fails a
  key or translation that contains the default product name or "Laravel".

### 6. Extraction and the CI check

`billing/scripts/i18n-check.mjs` in the lint job of `ci.yml`. It is plain Node and parses TSX with `typescript`, which
is already in the lockfile. PHP and Blade are scanned for literal first arguments, which the literal rule (§1) makes
reliable. It **fails** on:

1. a non-literal key outside the allow-list;
2. an unused key: present in a `lang/*.json` file but called nowhere, in PHP, Blade, TSX or a `tKey`;
3. a translation whose placeholders differ from its key's (`:count` dropped, `:name` misspelt);
4. a plural translation with a different number of `|` segments or ranges from its source;
5. a bracketed context key missing from `en.json`; the brand rule (§5); a locale file not in `config/localization.php`;
6. a user-visible literal in a TSX file that is not marked pending (§8): JSX text containing a letter, and string
   literals in `placeholder`, `title`, `alt`, `aria-label`, `aria-description`, `label` and `description` props and in
   `toast.*()` calls. One reasoned exception per line: `// i18n-ignore: <reason>` (an example value, a code sample).

It **reports** (does not fail) coverage per locale, with `--missing <locale>` listing untranslated keys. Missing keys
fail only for a locale marked `ready` in config, so a half-done Nepali file never blocks a feature PR. Once the owner
calls `ne` ready, a new English string needs its Nepali translation in the same PR, or the PR drops `ready` for that
locale.

`--export <locale>` and `--import <locale> <file>` read and write flat key-value JSON, which Weblate and Crowdin both
take, so the choice of translation platform stays open.

`--pseudo` writes a pseudo-locale `en-XA` (accented, about 35% longer), enabled only outside production. Walking a page
in it shows hard-coded strings and text that overflows its layout, before a real translation exists.

### 7. Mail and PDFs

- **Mail.** Template bodies stay per-locale database rows. The Nepali defaults ship as seeded `ne` translation rows,
  not in `lang/`. The frame, button labels, footer and merge-field labels use `__()` inside `withLocale`. No web font in
  mail: Gmail, Outlook, iOS and Android all render Devanagari with system fonts. The `MailPalette::FONT` stack is
  unchanged.
- **Web UI font.** Instrument Sans has no Devanagari, so the browser falls back to a system font. To keep weights
  consistent, ship Noto Sans Devanagari (SIL OFL 1.1) self-hosted as a font file, with
  `unicode-range: U+0900-097F, U+1CD0-1CFF, U+A8E0-A8FF`, so it downloads only on pages that contain Devanagari. That is
  an asset, not a package; the OFL text goes in `THIRD_PARTY_NOTICES.md`.
- **PDF font.** Noto Sans Devanagari (OFL 1.1: bundling and embedding are allowed, the font must not be sold on its
  own, and the licence ships with it). Mukta and Hind (both OFL) are fallbacks if its metrics fight the layout. A font
  alone is not enough.
- **PDF engine.** dompdf does no OpenType shaping. With a Devanagari font it shows the glyphs but not the script: the
  vowel sign in कि is not reordered, conjuncts such as क्ष, त्र and श्र fall apart, and the reph is misplaced. So a
  Nepali or Hindi PDF needs a shaping engine. Candidates:
    - **Gotenberg sidecar** (Chromium behind an HTTP API, Apache-2.0, image pinned by digest): the same rendering as the
      browser, HarfBuzz shaping and font fallback. Costs: a large image, Chromium's memory under concurrent renders, and
      one more internal service under ADR 0015.
    - **WeasyPrint sidecar** (BSD-3-Clause, Pango and HarfBuzz): correct shaping, good paged-media CSS, a lighter image
      than Chromium; less like the browser.
    - **mPDF** (in PHP, has Indic shaping): rejected, because it is GPL-2.0-only and Mohar is distributed under a
      proprietary licence.
    - **Browsershot** (Puppeteer and Chromium inside the app image): rejected, because it puts Node and Chromium into
      the web and worker image.

    Recommendation: keep dompdf for English documents, which changes nothing and keeps the `sizing.md` numbers. Before the
    first Nepali PDF, run a spike: render the invoice and tax-note templates with a fixed set of test strings (the
    conjuncts above, a Nepali client name, mixed Latin and Devanagari) through both sidecars. Measure p95 and memory
    against dompdf's 115–171 ms p95. The PDF seam (a small renderer interface in `app/Contracts`, used by the three render
    sites) is added in that spike's PR, not before. The same engine also lifts ADR 0025's known limitation for
    user-typed Devanagari.

### 8. Migrating the 159 pages

- **PR 0 (CTO, kernel; on English pages nothing visible changes).** `Locales`, the settings and the old-key fallback,
  `users.locale`, `SetLocale`, the `locale` and `translations` props, `i18n.ts` with the fixture, `formatNumber`
  and locale-aware month names, the `ModuleServiceProvider` lang loading, `i18n-check.mjs` in CI, and a
  `// i18n: pending` first-line marker on every existing `.tsx` under `pages`, `layouts` and `components` (not
  `components/ui`). It also adds a kernel-changelog fragment and adds a line to `review-rules.md`.
- **From PR 0's merge, new files use `t()`.** The check fails a new or touched-for-the-first-time file that is not clean
  unless it carries the marker, and fails a PR that adds a marker to a file the base branch did not have marked. The
  marker set can only shrink. Markers are per file, not a shared baseline file, so parallel PRs do not conflict (ADR
  0027).
- **Order:** (1) layouts and shared components: sidebar, header, data table, empty states, pagination, form chrome.
  They appear on every page, so they give the most coverage per PR. (2) The client area and the store: auth,
  dashboard, invoices and payment, services, domains, tickets, checkout. This is the surface Nepali clients see.
  (3) The mail frame and PDF labels. (4) The admin area, module by module. One module folder per PR, about 15 to 20
  pages each, so several engineers can work in parallel without overlapping.
- **Nepali** is a separate stream: `lang/ne.json` for the client area, plus `lang/ne/validation.php` and the other
  framework files, written and checked by a native speaker. `ne` is marked `ready` only when the client area, mail and
  validation are fully translated.
- **Hindi** follows the same steps with no code change, apart from its PDFs, which need the same engine.

## Must not be added

- A second translation runtime or format (i18next, FormatJS, gettext `.po`), or a converter between formats.
- A typed-key generator or a key enum. English keys are checked by §6, not by types.
- A database translation table now. Admin overrides (a layer above the JSON) and translated catalogue content
  (product names, descriptions, knowledge base) each need their own ADR.
- Per-module locale settings, per-locale date-format settings, or a Devanagari-digits switch before the owner decides.
- A PDF-renderer contract before the spike, or a font-and-dompdf "fix" presented as Devanagari support.
- `Accept-Language` detection, and RTL styles.

## Consequences

- From PR 0, a new page costs a `t()` per string, and CI catches a missed one. The retrofit becomes a known, ordered
  list of marked files, not an audit.
- English installs change nothing, and pay no bytes and no server cost.
- Translators get one flat JSON file per locale, covering PHP and React.
- We maintain about 150 lines of helper, the plural fixture and the check script. If the ICU syntax is ever needed,
  revisit B with the call sites unchanged.
- Nepali PDFs wait for the engine decision. Nepali screens and mail do not.

## Owner decisions

Decided by the owner on 2026-10-31:

1. **Option A** (no new package). English only for now; build the mechanism.
2. **The PDF engine spike is deferred** until Nepali documents are planned. The 2026-10-23 "no PDF-engine change" decision stands until then.
3. **Fonts**: Noto Sans Devanagari, when needed.
4. **Digits**: Latin digits in the Nepali UI.
5. **Who translates and reviews Nepali**, and any translation platform: deferred.

Earlier owner decision, unchanged: clients never enter names in Devanagari.

## Amendment (#606) — 2026-11-01, the mechanism lands before PR 0's other parts

- **Split of PR 0.** #606 ships only the React helper, the `locale` and `translations` props, the catalogue cache and
  module `Resources/lang` loading. `Locales`, `SetLocale`, `users.locale`, `config/localization.php`, `i18n-check.mjs`,
  the `// i18n: pending` markers, `formatNumber` and locale-aware month names follow in later PRs.
- **Paths.** The helper is `resources/js/lib/i18n.tsx` (it holds `<Trans>`, so JSX), and the shared fixtures are
  `billing/tests/Fixtures/i18n/plurals.json` and `replacements.json` (the tracked directory is `Fixtures`). Both
  fixtures run in Pest and Vitest; the JS mirrors `Translator::makeReplacements` and `MessageSelector` exactly (one
  case-sensitive pass, longest name first, `:Name` and `:NAME` variants, Laravel's condition character class).
- **Catalogue delivery.** `php artisan optimize` runs `i18n:cache`, which writes every locale and its content-hash
  version to `bootstrap/cache/i18n.php`; `optimize:clear` removes it. The once-prop key is `i18n:{locale}:{version}`.
  Without the file the catalogue is read from `lang/` once per request (once per worker in a long-running worker).
  English sends an empty object, plus any context keys in `en.json`.
- **Ordering rule.** `SetLocale` runs in the web group before `HandleInertiaRequests` and reads the client account
  from the session (`ClientContext::SESSION_KEY`), because `share()` reads the locale once and builds the once-prop
  key from it.
- **Deferred.** The used-keys payload filter (§3) ships with `i18n-check.mjs`, not at the 100 KB threshold: Devanagari
  is escaped to `\uXXXX` in the page JSON and a third of the catalogue (mail, PDF, validation) is never rendered by React.
