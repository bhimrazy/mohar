# Email design review

Status: proposal for the owner to choose a direction (2026-10-18). Nothing here is implemented yet. The
Notifications engineer builds the chosen direction.

- **Review page:** [`index.html`](index.html). Open it locally. It shows the current emails next to directions A, B and C for each of the five emails, with switches for desktop, 375 and 320 px and for light, dark and forced inversion.
- **Reference HTML:** `directions/<a|b|c>/<email>.html`. These are real, sendable email HTML files: table-based, inline styles, a maximum width (A 640 px, B and C 600 px), fluid below it, and a fixed MSO table for Outlook.
- **Plain-text versions:** `directions/plain-text/<email>.txt`. They are the same for every direction.
- **Tools** (rerunnable, in `tools/`):
    - `build.mjs` generates all of the HTML from one dataset, `emails.mjs`.
    - `shoot.mjs` takes the screenshots and runs the overflow and button-size checks.
    - `width.mjs` produces the width comparison.
    - `contrast.mjs` checks WCAG contrast in light, dark and inverted.
    - `make-logos.mjs` and `make-doc.mjs` render the PNG logos and A's document thumbnail.
    - `webmail-pane.html` is the generic reading pane used for the in-context captures.
    - Run them through `scripts/heavy.sh node tools/<script>`. They use the Playwright install in `.worktrees/_review/pw`.

**Decision for the owner:** pick A, B or C. **Recommendation: A, "Two-card receipt".** It is built on the receipt the owner shared as a reference, adapted to our brand. The reasons are in [Recommendation](#recommendation).

---

## 1. Critique of the current emails

I rendered these from the Notifications worktree (`EmailComposer::composeSource` with `SampleContext`, five templates). Screenshots are `screenshots/current-<email>-<desktop|mobile>-<light|dark>.png`. The layout on `develop` (Themes module) is the stock Laravel mail theme with the brand name and logo added. The Notifications worktree replaces it with the branded layout reviewed here.

### Overall impression

The emails are clean and correct in intent: one card, one button, a facts table, a dark-mode scaffold and a plain-text part. But they read as **dull** because nothing in them carries meaning or emphasis. Every email has the same grey card, the same 22 px black heading and the same grey table. The amount, the most important fact in a billing email, gets the same weight as the invoice number. There is no status, no sense of urgency or reassurance, nothing about what was bought, and the only colour is the button. It looks like the default Laravel notification with a logo, which is what it is.

### Why it reads as dull, and what is missing

| Finding                                                                                                                                  | Severity | Recommendation                                                                                            |
| ---------------------------------------------------------------------------------------------------------------------------------------- | -------- | --------------------------------------------------------------------------------------------------------- |
| No status signal. "Paid", "Due in 14 days" and "Overdue" all look the same, so the reader has to read sentences to learn whether to act. | Critical | Add a status chip or line in semantic colours (success, warning, danger, info) at the top of every email. |
| The amount is buried as a 14 px table row.                                                                                               | Critical | Put the amount first, at 40 px or more, with tabular figures and the due date beside it.                  |
| Nothing shows what was bought: no line items, no tax lines (GST, VAT) and no service names.                                              | Major    | Add an itemised summary with subtotal, tax lines and total, as an invoice does.                           |
| The only colour is the button, and the accent and brand appear nowhere else.                                                             | Major    | Use colour for meaning (status) and for brand once (logo, rail or band).                                  |
| Typography is flat: 22 px heading, 15 px body, a 12 px footer, and no eyebrow or hierarchy between sections.                             | Moderate | Define a type scale (40/26/16/14/13) with uppercase eyebrows for sections.                                |
| Dates are ISO (`2026-11-01`).                                                                                                            | Moderate | Use the human format "1 Nov 2026".                                                                        |

### Bugs to fix whichever direction is chosen

| Bug                                                                                                                                                                                                                                             | Where                                                                                                 | Fix                                                                                                                          |
| ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------- |
| **The button floats left and body text wraps beside it** (password reset, ticket reply).                                                                                                                                                        | `html/button.blade.php` uses `<table class="action" align="left">`, and `align` on a table floats it. | Use a full-width block button, or an unfloated `align` on the `td`.                                                          |
| **Dark-mode footer brand name is unreadable** (`#111827` on `#0F1115`, 1.07:1).                                                                                                                                                                 | `strong` in the footer is not covered by the dark rules.                                              | Give every text element a class with a dark override (see the class map in §9).                                              |
| Footer text fails contrast in light mode (`#8A909C` on `#F4F5F7`, 2.94:1).                                                                                                                                                                      | `themes/default.css` `.footer p`                                                                      | Use `#5B6478` (5.40:1).                                                                                                      |
| **The logo is broken.** It loads `http://localhost:8000/brand/logo.png`, and the dark variant `/brand/logo-dark.png` does not exist (only `logo-dark.svg`). The `img` has no `width` attribute, so Outlook may render it at its natural 206 px. | `BrandProvider::emailLogoUrl()`, `public/brand`                                                       | Use absolute PNG URLs for light, dark and mark, with explicit `width` and `height`. SVG does not render in Gmail or Outlook. |
| Old tagline "Billing in good order." in the sample brand.                                                                                                                                                                                       | Seeded setting                                                                                        | The default is "Web hosting billing, sealed and settled."                                                                    |
| Receipt sample: "Still due $28.98" equals the amount paid.                                                                                                                                                                                      | `SampleContext`                                                                                       | Fix the sample so the balance after payment is right.                                                                        |
| Ticket reply: "Sam from Support from Technical Support replied".                                                                                                                                                                                | Template copy and context                                                                             | Use "{{ reply.author_first_name }} from {{ ticket.department }} replied".                                                    |
| The sample is all USD, even for an INR client.                                                                                                                                                                                                  | `SampleContext`                                                                                       | Use the client's currency and Money formatting (₹, Rs lakh grouping).                                                        |
| The footer has no address in the sample (`notifications.footer_address` is empty).                                                                                                                                                              | Settings                                                                                              | The address is required (product standards). Fall back to the seller address, as the code already tries to.                  |

### What works

- The structure conventions in `templates.php` (`# heading`, lead line, facts table, first link becomes the button) are a good base. Every direction below keeps them.
- Brand data comes from `BrandProvider`, and the layout already swaps light and dark logos and has a preferences link.
- There is a plain-text part, a preheader, and `color-scheme` meta tags.

---

## 2. Research

### 2.1 Transactional emails in the market (patterns, not copies)

These notes come from public documentation and published examples. Our designs are our own.

| Product                                                                                                                                               | What they do well                                                                                                                                                                                                                                                                                                                                                          | What we take                                                                                                               |
| ----------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------- |
| **Stripe** receipts and invoices ([receipts docs](https://docs.stripe.com/receipts), [customer emails](https://docs.stripe.com/invoicing/send-email)) | A small brand icon, then the amount as the hero, a summary table (number, date, payment method), line items, and one "Pay" link to a hosted page. A PDF is attached. Legal name, support email, address and privacy link are always present ("for compliance reasons, some contact information is always required on receipts"). Brand colour and accent are configurable. | Amount-first hierarchy, the key-facts table, mandatory business details in the footer, the PDF link as a secondary action. |
| **Paddle** ([invoicing help](https://www.paddle.com/help/sell/invoicing-tool/how-is-the-invoice-sent))                                                | The invoice email carries a copy of the invoice in the body plus a link to a hosted page with the PDF.                                                                                                                                                                                                                                                                     | Itemised content in the body, not only a link.                                                                             |
| **Shopify** order notifications ([customise notifications](https://help.shopify.com/en/manual/fulfillment/setup/notifications/customize))             | Logo and accent colour set once and applied to every notification; Liquid variables for order data. Order emails lead with the order number and status, then items and totals.                                                                                                                                                                                             | One template for every email, accent from settings, merge fields.                                                          |
| **Linear / Vercel** (community re-creations in [React Email](https://react.email/templates))                                                          | Monochrome and text-first: a small logo, short copy, one button, a tiny footer. Container widths are 560 px (Linear-style) and 465 px (Vercel-style).                                                                                                                                                                                                                      | Restraint: colour only where it means something.                                                                           |
| **Wise / Revolut** ([Wise transfer notifications](https://wise.com/help/articles/2553293/how-do-you-notify-me-about-a-transfer))                      | Status-first: each email tells you where the money is ("we've received your money", "your transfer is complete"), often as a step tracker.                                                                                                                                                                                                                                 | The status line and the step tracker in direction C.                                                                       |
| **Postmark templates** ([repo](https://github.com/ActiveCampaign/postmark-templates))                                                                 | Proven, tested layouts. The receipt is 570 px wide with 45 px padding, goes full width below 600 px, and has a full-width button below 500 px.                                                                                                                                                                                                                             | Width and breakpoint conventions; the attribution approach if we borrow code (MIT).                                        |
| **Owner's reference: an Anthropic receipt in Stripe's receipt layout** (shared privately, kept for reference, not copied)                             | Envelope, layout and restraint; see §2.2.                                                                                                                                                                                                                                                                                                                                  | The basis of direction A.                                                                                                  |

### 2.2 The owner's reference receipt: what we took

The owner shared a receipt email they like, from Anthropic in Stripe's receipt layout, "not to copy, keep for reference".

| Element                | In the reference                                                                                                                                                                                                                                                                                                     | What we did                                                                                                                                                                                                                                                                                                                                                                                                                                                    |
| ---------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Subject                | "Your receipt from Anthropic, PBC #2372-8960-4914": document type, company and number                                                                                                                                                                                                                                | Every subject in §6 now follows this pattern: `Your receipt from {company} #{receipt}`, `Invoice {number} from {company}: {amount} due {date}`, `Payment overdue: invoice {number} ({amount})`.                                                                                                                                                                                                                                                                |
| Sender                 | Display name is the legal name; the address is a billing alias; the PDF is attached                                                                                                                                                                                                                                  | From `{legal name} <billing@…>`, Reply-To support, and the PDF attached when the setting is on (issue #29). See §3 and §9.                                                                                                                                                                                                                                                                                                                                     |
| Page                   | A soft warm-grey background (about `#EFEEEA`)                                                                                                                                                                                                                                                                        | Direction A uses `#EFEEEA` (footer text 5.11:1, links 5.77:1). B and C keep Calm Fintech's blue-grey `#F3F4F8` so the owner can compare.                                                                                                                                                                                                                                                                                                                       |
| Header                 | The logo mark plus the company name, left-aligned with the card edge, above the card, with no band                                                                                                                                                                                                                   | Direction A does this.                                                                                                                                                                                                                                                                                                                                                                                                                                         |
| Summary card           | 16 px radius, about 56 px padding; a muted "Receipt from …" label; an amount of about 48 px; a muted "Paid {date}"; a small document sketch at the top right; a hairline; "↓ Download invoice" and "↓ Download receipt" links; label/value rows (receipt no., invoice no., payment method with card logo and last 4) | A uses a 16 px radius, 48 px padding (20 px on phones), a 48 px amount and its own document thumbnail (`assets/doc-{light,dark}.png`, our drawing). The status line is **in the status colour**, adapted to our brand ("● Paid · 18 Oct 2026, 2:14 pm"). The hairline is followed by quiet download links and key facts. Payment method shows the provider (Khalti, eSewa) or the card brand plus last 4, as a badge here and as the official logo in the app. |
| No button on a receipt | Receipts only have download links                                                                                                                                                                                                                                                                                    | **All three directions** drop the button on the receipt. Emails that need an action keep exactly one button: Pay, Reset password, View reply.                                                                                                                                                                                                                                                                                                                  |
| Second card            | "Receipt #…" with the line items                                                                                                                                                                                                                                                                                     | A's second card holds the record: line items and totals (money emails), request details (security) or ticket details (support), plus the contextual help line.                                                                                                                                                                                                                                                                                                 |
| Restraint              | Grey and black only; large type for the one number that matters                                                                                                                                                                                                                                                      | A has no accent fill except the action button and links. Colour is used only for the status line.                                                                                                                                                                                                                                                                                                                                                              |

### 2.3 Template frameworks and open-source sets

Licences and activity were checked against each repository's LICENSE file and release history in Oct 2026.

| Project                                                                                                               | Approach                                                                   | Structure it encourages                                                                                                                                                                                                                           | Outlook / dark mode                                                                                                                               | Licence | Maintained                                       |
| --------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------- | ------- | ------------------------------------------------ |
| [MJML](https://github.com/mjmlio/mjml) ([docs](https://documentation.mjml.io/))                                       | XML markup compiled to HTML by Node                                        | `mj-section` / `mj-column` rows, `mj-hero`, `mj-table`, `mj-button`; 600 px default; columns stack on mobile                                                                                                                                      | Ghost tables and VML buttons; dark-mode attributes arrive in the v6 alpha ([release](https://github.com/mjmlio/mjml/releases/tag/v6.0.0-alpha.1)) | MIT     | Yes (v5.4.1, Sep 2026)                           |
| [Foundation for Emails](https://github.com/foundation/foundation-emails) / [Inky](https://github.com/foundation/inky) | Inky tags plus SCSS, compiled. Inky 2 is in Rust with PHP bindings         | `container` / `row` / `column`. Starter templates: basic, hero, order, password, welcome, newsletter                                                                                                                                              | Hybrid MSO ghost tables, `prefers-color-scheme` in SCSS                                                                                           | MIT     | FfE is being archived in favour of Inky 2 (beta) |
| [Maizzle](https://github.com/maizzle/framework) ([docs](https://maizzle.com/docs/introduction))                       | Tailwind plus components; the build inlines and purges                     | Free-form components                                                                                                                                                                                                                              | Outlook-safe buttons, MSO helpers, dark-mode images                                                                                               | MIT     | Yes (v6.2.2, Oct 2026)                           |
| [React Email](https://github.com/resend/react-email)                                                                  | JSX components rendered to HTML                                            | Html / Head / Preview / Container / Section / Row / Column / Button / Hr                                                                                                                                                                          | Button uses `mso-padding-alt`; no documented dark-mode helper                                                                                     | MIT     | Yes (6.11.x, Oct 2026)                           |
| [Cerberus](https://github.com/emailmonday/Cerberus)                                                                   | Hand-coded, annotated HTML to copy; no build                               | Fluid, responsive and hybrid patterns                                                                                                                                                                                                             | MSO font and DPI fixes, `color-scheme` plus `prefers-color-scheme`                                                                                | MIT     | Last release 2022; commits in 2026               |
| [Postmark templates](https://github.com/ActiveCampaign/postmark-templates)                                            | Mustache templates built with MailMason, in `<style>` and inlined versions | welcome, password-reset, receipt, invoice, trial-expiring/expired, dunning, comment-notification, user-invitation, each in Basic / Basic full / Plain. The invoice uses number, date, due date, a details loop, total, action URL and support URL | `color-scheme` meta, `prefers-color-scheme`, MSO conditionals                                                                                     | MIT     | Dormant since 2022                               |
| [Mailgun templates](https://github.com/mailgun/transactional-email-templates)                                         | Plain HTML plus CSS; inline it yourself                                    | action, alert, billing                                                                                                                                                                                                                            | MSO conditionals; `prefers-color-scheme` in billing                                                                                               | MIT     | Dormant since 2022                               |

They converge on the same skeleton:

- preheader;
- logo header;
- hero (title or amount);
- short body;
- summary or details table;
- one bulletproof button;
- an optional secondary block;
- footer with company details and why you received the email.

Our standard structure (§3) is that skeleton, with a status line added.

**Recommendation: follow the patterns, adopt no framework code or runtime dependency.**

- Our emails are rendered at runtime from operator-editable Markdown through Blade. MJML, Maizzle, React Email and Inky all assume a compile step (Node, or Rust for Inky 2) run by developers, so adopting one means a new runtime dependency or a build step that operators' edits can't go through.
- The reference HTML in `directions/` is our own code. It uses widely documented techniques: ghost tables, `mso-padding-alt` buttons, `[data-ogsc]` dark rules and the preheader filler.
- If the engineer copies a specific snippet from Cerberus or the Postmark templates (both MIT), keep the MIT notice and a source link in a comment at the top of that Blade file, and note it in the PR.
- Any package (for example MJML for an offline design workflow) needs CTO approval under ADR 0007.

### 2.4 Billing-product email sets, and what our catalogue lacks

**What each product sends.** The structure notes come from the products' public docs.

| Product                                                                                                                                                                                                            | Emails relevant to us                                                                                                                                                                                                                                                                                                                                                                                                                               | Structure notes                                                                                                                                                |
| ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Stripe Billing** ([send-email](https://docs.stripe.com/invoicing/send-email), [recovery emails](https://docs.stripe.com/billing/revenue-recovery/customer-emails), [receipts](https://docs.stripe.com/receipts)) | Finalised invoice, receipt, failed payment, 3D Secure required, expiring card, unpaid invoice reminder, credit note, refund, trial ending, upcoming renewal, cancellation                                                                                                                                                                                                                                                                           | Invoice and receipt emails attach the PDFs. Every email links to a hosted page. Receipts must show legal name, support address, support email and privacy URL. |
| **Chargebee** ([email list](https://www.chargebee.com/docs/billing/1.0/customers/emails-v2-mapping))                                                                                                               | Subscription created/changed/cancelled/paused/resumed; trial and renewal reminders (1st and 2nd); invoice; payment succeeded/failed/refunded/initiated; card expiring and expired; dunning reminder; portal security emails (verification, password, email changed, account locked)                                                                                                                                                                 | Renewal reminders in two stages; "payment initiated" for bank debits.                                                                                          |
| **Paddle Billing** ([customer emails](https://www.paddle.com/help/manage/your-customers/which-emails-will-customers-receive-on-paddle-billing))                                                                    | Receipt, credit, refund; invoice updated/cancelled; subscription confirmation, price change, pause, cancel; payment failed (up to 4 notices ending in a final one); legally required trial-end, discount-end and auto-renewal reminders                                                                                                                                                                                                             | 16 languages; legally required renewal reminders 7 or 30 days ahead.                                                                                           |
| **Zoho Invoice** ([reminders](https://www.zoho.com/invoice/help/settings/reminders.html))                                                                                                                          | Three configurable reminders before or after the due date; payment thank-you                                                                                                                                                                                                                                                                                                                                                                        | Reminder timing is operator-configured.                                                                                                                        |
| **FreshBooks** ([client emails](https://support.freshbooks.com/hc/en-us/articles/360058625651), [late reminders](https://support.freshbooks.com/hc/en-us/articles/227559727))                                      | New invoice, up to 3 late reminders, payment received / declined, recurring payment, refund, credit note, estimate                                                                                                                                                                                                                                                                                                                                  | Late fees can be added to reminders.                                                                                                                           |
| **QuickBooks Online** ([reminders](https://quickbooks.intuit.com/learn-support/en-us/help-article/invoicing/send-invoice-reminders-automatically-manually/L84cQjpxo_US_en_US))                                     | Up to 3 automatic reminders, up to 90 days either side of the due date                                                                                                                                                                                                                                                                                                                                                                              | Editable subject, greeting and body with variables; optional PDF.                                                                                              |
| **WHMCS** ([email templates](https://docs.whmcs.com/9-0/system/email/email-templates/); names confirmed across its 9.0 docs)                                                                                       | Order confirmation; password reset and verification; product welcome, upgrade and termination; domain renewal notices (default 60/30/7 days before, plus 3 days after expiry), transfer initiated/completed/failed; invoice created, card invoice created, payment reminder, 1st/2nd/3rd overdue, refund, invoice modified, card declined, card expiring; ticket opened, auto-close, closed-ticket bounce; admin order, cron and automation notices | Our catalogue already covers this list one-for-one (the parity matrices).                                                                                      |
| **Blesta** ([archived list](https://web.archive.org/web/20210419190710/https://docs.blesta.com/display/user/Email+Templates))                                                                                      | Invoice delivery (paid and unpaid), invoice notices 1–3, auto-debit pending, card expiration, payment approved/declined/error, service creation/suspension/unsuspension/cancellation/scheduled cancellation; staff error notices                                                                                                                                                                                                                    | Separate "scheduled cancellation".                                                                                                                             |
| **HostBill** ([template list](https://hostbill.atlassian.net/wiki/spaces/DOCS/pages/2928967681/List+of+system+Email+templates))                                                                                    | Signup, verify, MFA enabled/disabled, invite, account deletion; invoice reminder, overdue, paid, final (VAT invoice), refund, cancelled, collections, payment failed; order new, fraud order; quote and estimate; domain bulk expiry; ticket auto-close, reply and time limit; admin chargeback, credit added                                                                                                                                       | A per-product choice of suspension and termination templates.                                                                                                  |

**Gaps in our catalogue** (the catalogue in the Notifications worktree already has order confirmation, quote delivered, credit note, domain transfer failed, invoice modified, user invitation and the payment-method-expiring reminder).

| Priority | Missing email                                                                                        | Seen in                                                                                 | Note                                                                                               |
| -------- | ---------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------- |
| High     | **Upcoming renewal / auto-renewal notice** for services (not only domains)                           | Stripe, Chargebee, Paddle (legally required in some regions), Blesta auto-debit pending | Configurable days before; says the amount and the method that will be charged.                     |
| High     | **Payment needs your action** (3D Secure / SCA), with one repeat reminder                            | Stripe                                                                                  | Relevant for Indian cards (RBI e-mandate framework) and EU cards (SCA).                            |
| High     | **Domain expiry schedule**: several pre-expiry notices (60/30/7) plus an after-expiry notice         | WHMCS, HostBill                                                                         | We have one `domain.renewal_upcoming`; make the schedule configurable and send per-domain digests. |
| High     | **Payment method expired** (as distinct from expiring)                                               | Chargebee                                                                               | Sent when a renewal will fail.                                                                     |
| Medium   | **Invoice cancelled / voided**                                                                       | Paddle, HostBill                                                                        |                                                                                                    |
| Medium   | **Scheduled cancellation reminder** (the service ends in N days)                                     | Blesta, Paddle, Chargebee                                                               | We confirm the request but don't remind before the end date.                                       |
| Medium   | **Payment pending** (bank transfer or offline payment recorded, awaiting clearance)                  | Chargebee, FreshBooks, Blesta                                                           | Useful for Nepal and India bank transfers.                                                         |
| Medium   | **Account security events**: 2FA turned on or off, email address changed                             | HostBill, Chargebee                                                                     | Security category, always sent.                                                                    |
| Medium   | **Quote accepted** (staff) and **account credit added** (client)                                     | HostBill, FreshBooks                                                                    |                                                                                                    |
| Low      | Trial ending, chargeback received (staff), service ownership transfer, account deletion confirmation | Stripe, Chargebee, Paddle (trial ending); HostBill (the rest)                           | Trial ending only if trials ship.                                                                  |

Not found in any of these products: SSL expiry and "data export ready" emails. Treat them as our own optional features, not parity gaps.

### 2.5 Email standards worth adopting

| Standard / pattern                                                                                                                                                                                                                                                                                                                                                                                                   | Who uses it                                                       | Adopt?                                                                                                                        | How / where                                                                                                                                                                                      |
| -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `color-scheme` and `supported-color-schemes` meta, plus `:root{color-scheme:light dark}` ([caniemail](https://www.caniemail.com/features/css-at-media-prefers-color-scheme/), [Litmus dark-mode guide](https://www.litmus.com/blog/the-ultimate-guide-to-dark-mode-for-email-marketers))                                                                                                                             | Apple, Stripe, most modern senders                                | **Yes**                                                                                                                       | Layout `<head>` (Notifications). Keep both names: `supported-color-schemes` is the old name, which older Apple Mail still reads.                                                                 |
| `prefers-color-scheme` dark rules duplicated under `[data-ogsc]` (text) and `[data-ogsb]` (background) for Outlook.com and the Outlook apps                                                                                                                                                                                                                                                                          | Litmus, Email on Acid                                             | **Yes**                                                                                                                       | Layout `<style>` (Notifications). Generate both from one token list, as `build.mjs` does.                                                                                                        |
| Accessibility: `lang` and `dir` on `<html>` and the wrapper, `role="article"` with `aria-roledescription="email"` and an `aria-label` of the subject, `role="presentation"` on layout tables, real `<th scope>` on data tables, one `h1`, alt text on the logo, link text that makes sense alone ("Download PDF invoice", not "click here") ([goodemailcode.com](https://www.goodemailcode.com/email-code/template)) | Good Email Code, GOV.UK Notify                                    | **Yes**                                                                                                                       | Layout and components (Notifications).                                                                                                                                                           |
| Plain-text alternative as `multipart/alternative`, written from the same data rather than stripped from the HTML. Links on their own line, a `--` separator before the footer.                                                                                                                                                                                                                                       | Everyone; Stripe, Postmark                                        | **Yes** (already present)                                                                                                     | `text/message.blade.php` (Notifications). Mirror the block order (§3).                                                                                                                           |
| Preheader: a hidden div at the top with deliberate text, padded with `&#8199;&#65279;&#847;` so the inbox preview doesn't pull in body text.                                                                                                                                                                                                                                                                         | Everyone                                                          | **Yes**                                                                                                                       | A new `preheader` field per template and locale (Notifications). Never fall back to body text.                                                                                                   |
| `List-Unsubscribe` and `List-Unsubscribe-Post: List-Unsubscribe=One-Click` ([RFC 8058](https://www.rfc-editor.org/rfc/rfc8058), [Google sender guidelines](https://support.google.com/a/answer/81126))                                                                                                                                                                                                               | Required by Gmail and Yahoo for bulk _subscribed_ mail since 2024 | **Yes, for optional categories only** (announcements, affiliate summaries, marketing). Not for invoices, security or tickets. | Mail headers plus a signed one-click endpoint that turns off that category (Notifications).                                                                                                      |
| SPF, DKIM and DMARC aligned with the From domain ([Google](https://support.google.com/a/answer/81126), [Yahoo](https://senders.yahooinc.com/best-practices/))                                                                                                                                                                                                                                                        | Required by Gmail and Yahoo                                       | **Yes**                                                                                                                       | **Deployment.** DNS records per sending domain, plus an admin health check that shows whether they pass (Notifications/DevOps).                                                                  |
| BIMI: the brand logo in the inbox ([BIMI Group](https://bimigroup.org/))                                                                                                                                                                                                                                                                                                                                             | Banks, large SaaS                                                 | **Later, optional**                                                                                                           | **Deployment.** It needs DMARC at `p=quarantine` or `reject`, an SVG Tiny PS logo, and a VMC or CMC certificate for Gmail. Document it as an operator step and add no code.                      |
| Gmail schema.org markup, e.g. `ViewAction` "View invoice" ([Gmail markup](https://developers.google.com/workspace/gmail/markup/overview), [registration](https://developers.google.com/workspace/gmail/markup/registering-with-google))                                                                                                                                                                              | Airlines, large retailers                                         | **No for now**                                                                                                                | Each sending domain must register with Google and have authenticated, steady volume. That doesn't fit a self-hosted product where every operator has its own domain. Revisit as an opt-in later. |
| Real sender and reply path: `From: <Brand> Billing <billing@…>`, `Reply-To` support, never `noreply`                                                                                                                                                                                                                                                                                                                 | Stripe (support address), Postmark guidance                       | **Yes** (owner decision)                                                                                                      | Settings → Mail (Notifications). Copy that says "reply to this email" depends on it.                                                                                                             |
| `Auto-Submitted: auto-generated` ([RFC 3834](https://www.rfc-editor.org/rfc/rfc3834)) and `X-Auto-Response-Suppress: All` on automated mail                                                                                                                                                                                                                                                                          | Helpdesks                                                         | **Yes**                                                                                                                       | Mail headers (Notifications). They stop out-of-office loops into ticket piping.                                                                                                                  |
| `Message-ID`, `In-Reply-To` and `References` on ticket mail, with a fixed `[#number]` subject                                                                                                                                                                                                                                                                                                                        | Helpdesks (Zendesk, Help Scout)                                   | **Yes** (subject already fixed)                                                                                               | Ticket mail headers (Notifications/Support). They keep replies threaded in every client.                                                                                                         |

---

## 3. Standard structure for every email

Every template follows these blocks in order, and every direction renders the same blocks. A block may be empty, but the order never changes.

| #   | Block                    | Must contain                                                                                                                                                                                                                                                                                                                                  | Notes                                                                                                                            |
| --- | ------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------- |
| 0   | **Envelope**             | A subject in the pattern "{document} from {company} #{number}" or "{what happened}: {number} ({amount})"; a deliberate preheader; From = the company's **legal name** at a billing alias (billing mail) or a general alias (other mail), never noreply; Reply-To = support; the PDF attached for invoices and receipts when the setting is on | Section 6 has the lines for every template.                                                                                      |
| 1   | **Header**               | The brand logo (light and dark PNG, styled alt text) and the reference on the right (invoice, receipt or ticket number)                                                                                                                                                                                                                       | Logo from `BrandProvider`.                                                                                                       |
| 2   | **Status**               | One status in a semantic tone: `accent` (neutral or action), `success`, `warning`, `danger`, `info`                                                                                                                                                                                                                                           | Computed by the system, not typed by the operator (§9).                                                                          |
| 3   | **Hero**                 | The amount with its label and date for money emails; otherwise a short title                                                                                                                                                                                                                                                                  | Tabular figures, the client's currency format.                                                                                   |
| 4   | **Greeting and opening** | "Hi {first name}," plus one sentence on what this is about                                                                                                                                                                                                                                                                                    | Ticket replies carry the agent's message here, with an author line.                                                              |
| 5   | **Notice** (optional)    | Consequence and date, calmly stated (overdue), or a security note                                                                                                                                                                                                                                                                             | Warning tone, at most one.                                                                                                       |
| 6   | **Progress** (C only)    | Two to three steps with dates (issued → due → paid; requested → expires)                                                                                                                                                                                                                                                                      | Also announced as text to screen readers.                                                                                        |
| 7   | **Primary action**       | One button whose label says what happens ("Pay ₹1,28,486.66", "Reset password", "View reply"); full width on phones, at least 44 px tall. **No button when there is nothing to do** (receipts).                                                                                                                                               | Quiet text links follow: "↓ Download invoice", "↓ Download receipt", and at most one secondary action ("Update payment method"). |
| 8   | **Key facts**            | Number, dates, method, customer and tax details as a label/value table                                                                                                                                                                                                                                                                        | Stacks to label-over-value below 420 px.                                                                                         |
| 9   | **What was bought**      | Line items (name, period or domain, amount), subtotal, tax lines, a bold total                                                                                                                                                                                                                                                                | Money emails only.                                                                                                               |
| 10  | **Reassurance**          | What happens next, or what to do if this is unexpected                                                                                                                                                                                                                                                                                        | "Ignore this email and your password stays the same".                                                                            |
| 11  | **Help line**            | One contextual line ("Need more time? Reply and we'll work it out with you")                                                                                                                                                                                                                                                                  | Inside the card.                                                                                                                 |
| 12  | **Footer**               | Brand name and tagline; legal name and address; "Questions? Reply to this email or write to support@…" (address as plain text); Help centre and Email preferences links; why you received this                                                                                                                                                | The same in every email. Security mail says it can't be turned off.                                                              |

---

## 4. Width

The owner asked for a fixed, centred maximum between 600 and 680 px, chosen on line length, the key-facts table and the button. I built each direction's invoice at 600, 640 and 680 px (`directions/<d>/width/`) and measured it inside a 1280 px webmail pane (`tools/width.mjs`). Screenshots are `screenshots/<d>-invoice-issued-width-<600|640|680>-gmail-light.png`.

| Direction              | Width               | Text column            | Body characters per line (16 px) | Button (desktop)                        |
| ---------------------- | ------------------- | ---------------------- | -------------------------------- | --------------------------------------- |
| A (48 px side padding) | 600 / **640** / 680 | 504 / **544** / 584 px | 68 / **74** / 80                 | 185 × 50 (inline; full width on phones) |
| B (32 px)              | **600** / 640 / 680 | **536** / 576 / 616 px | **74** / 78 / 84                 | 185 × 50 (inline; full width on phones) |
| C (36 px)              | **600** / 640 / 680 | **528** / 568 / 608 px | **73** / 78 / 84                 | full width, 50 px tall                  |

Characters per line were measured on a long sample paragraph rendered in each email's body style.

For reference: Postmark's receipt is 570 px, the React Email community re-creations use 560 px (Linear-style), 600 px (Nike-style) and 660 px (Apple-style), and MJML defaults to 600 px. The owner's reference receipt uses a wide card with generous (about 56 px) padding, which is the same trade-off A makes.

**Decision, per direction: A at 640 px; B and C at 600 px.** Each is the widest width that keeps body text within the 60–75 characters-per-line target.

- **A, 640 px (74 characters).** A's generous 48 px padding (from the reference) needs the wider card to keep a comfortable text column. The extra room also serves the key-facts rows, which carry long values (GSTINs, two-line addresses, `₹10,46,23,397.46`), and the 48 px amount. At 680 px lines reach 80 characters.
- **B and C, 600 px (74 and 73 characters).** At 640 px their narrower padding pushes lines to 78. At 600 px the facts table and line items still fit the stress sample, and C's full-width button stays button-sized rather than banner-like.
- If the owner wants one width everywhere, use 640 px and raise B's and C's side padding to 44 px. That gives the same line length.

Implementation:

- `max-width:<W>px` on a centred div, fluid below that.
- `<!--[if mso]><table width="<W>">` for Outlook desktop, which ignores `max-width`.
- **Mobile-first inline sizes.** Inline padding is 24 px and amounts are 30–36 px, with the thumbnail hidden. A `min-width:621px` media query plus an MSO-only `<style>` restores the desktop sizes. This way clients that strip `<style>` (Gmail with non-Google accounts) still fit a 375 px phone.
- Phone rules below 620 px, with extra steps at 420 px (facts stack) and 360 px (smaller amount, tighter padding).
- 320 px phones pass with the stress sample (§7).

---

## 5. The three directions

All three use the Calm Fintech tokens (indigo accent `#4F4BC4`), system fonts only (no web fonts), 16 px body text at 24 px line height, the same copy and the same footer. Colour rule: **accent = the action, semantic colour = the status.** The button is always the accent, even on an overdue notice. Red marks the state, not the action.

### A · Two-card receipt (after the owner's reference; recommended)

- **Layout:**
    - A warm-grey page.
    - The brand mark and name, left-aligned above the cards.
    - **Card 1, the summary:**
        - a muted label ("Receipt from Mohar Cloud Pvt. Ltd.", "Account security", "Reply from Technical Support");
        - a 48 px amount, or a 30 px title;
        - a status line in the status colour ("● Overdue by 3 days · was due 15 Oct 2026");
        - our own document thumbnail at the top right on money emails;
        - the greeting and opening line;
        - the notice (overdue);
        - one button where an action is needed;
        - a hairline, then "↓ Download" links;
        - key facts with no row rules.
    - **Card 2, the record:** line items and totals, request details or ticket details, then the reassurance and the help line.
    - The footer below.
- **Pros:**
    - The calmest of the three, and it matches the owner's stated taste: grey and black, one big number, lots of space.
    - It separates "what's happening and what to do" (card 1) from "the record" (card 2) without decoration.
    - It adapts cleanly to non-money emails: password reset puts the request details in card 2, and a ticket reply puts the message in card 1 and the ticket facts in card 2.
    - Little new to build.
- **Cons:**
    - Close to Stripe's familiar receipt, so it is distinctive through polish rather than novelty.
    - The status is a single coloured line, quieter than C's rail.
    - The second card adds scroll on phones; the action stays in card 1, so this costs nothing.
- **Screenshots:** `screenshots/a-<email>-<desktop|mobile|320>-<light|dark|inverted>.png`.

### B · Branded header band

- **Layout:**
    - An accent band at the top of the card, holding the brand mark, the brand name as live text, and the reference.
    - Below the band: the status chip, a title, and the greeting and opening line.
    - A **summary panel** with the amount on top and the facts in a two-column grid that stacks on phones.
    - An inline button with a secondary link beside it, then line items.
    - The ticket reply sits in a bordered message box.
- **Pros:** The most visibly branded, and it reads as "from your provider" at a glance. The panel makes key facts scannable.
- **Cons:**
    - The heaviest use of the accent. The band, the button and the indigo status chip all compete, which fights the "calm" brief.
    - The band needs special handling under forced inversion. We first used a white wordmark image, and it vanished when Gmail iOS turned the band light. The band now uses a white-tile mark plus the brand name as live text, so it works.
    - Custom brands need a mark that reads on their accent colour.
    - Title and opening tend to repeat each other.
- **Screenshots:** `screenshots/b-…` (same pattern).

### C · Status rail (our own)

- **Inspiration:** Wise and Revolut's status-first messages, Linear's restraint, and a paper receipt.
- **Layout:**
    - A 4 px **rail across the top of the card in the status colour**: indigo for due, green for paid, red for overdue, blue for awaiting reply.
    - Logo and reference in the card header.
    - A coloured status line ("● Overdue by 3 days").
    - A 44 px amount, or a title for non-money emails.
    - The greeting and opening line.
    - A **three-step progress tracker** (Issued → Due → Paid; Requested → Link works → Expires; Opened → Replied → Your turn).
    - A full-width button.
    - A **perforated tear line**.
    - The details: facts, line items, reassurance.
    - A tinted card foot with the contextual help line, and the brand footer below.
- **Pros:**
    - Colour always means something. The rail and status line tell you the state before you read a word, and the rest stays neutral, which keeps it calm.
    - The tracker answers "where am I and what's next" in every email type, not only billing.
    - The tear line separates "what to do" from "for your records", so the actionable part fits in the first screen on a phone.
    - Distinctive without being loud.
- **Cons:**
    - The most new parts to build (rail, status line, tracker, perforation).
    - The tracker needs system-computed data per template (§9).
    - In Outlook desktop the dots and notches render square: acceptable, but less polished.
- **Screenshots:** `screenshots/c-…` (same pattern).

### Screenshot index

For each direction `d` in {a, b, c} and `email` in {invoice-issued, payment-receipt, overdue-reminder, password-reset, ticket-reply}:

| File                                                                   | What                                                                                                                       |
| ---------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------- |
| `d-email-desktop-light.png`, `-desktop-dark.png`                       | 720 px viewport (the email at its maximum width plus frame), our light and dark styles                                     |
| `d-email-desktop-inverted.png`                                         | Forced-inversion simulation (Gmail iOS / Outlook desktop dark)                                                             |
| `d-email-mobile-light.png`, `-mobile-dark.png`, `-mobile-inverted.png` | 375 px at 2x                                                                                                               |
| `d-email-320-light.png`                                                | 320 px at 2x                                                                                                               |
| `d-invoice-issued-wide-light.png`                                      | 1024 px wide pane, content centred                                                                                         |
| `d-invoice-issued-gmail-light.png`                                     | Inside a generic webmail reading pane at 1280 px (light)                                                                   |
| `d-invoice-issued-images-off-light.png`                                | Images blocked                                                                                                             |
| `d-invoice-issued-no-styles-mobile-light.png`                          | `<style>` removed (no media queries): clients that strip head styles                                                       |
| `d-invoice-issued-long-320-light.png`, `-long-desktop-light.png`       | Stress sample: `INV-2026-SHARMADIGITAL-000142-B`, ₹12,34,56,789.00, a long product name and hostname, a three-line address |
| `d-invoice-issued-width-<600/640/680>-gmail-light.png`                 | Width comparison                                                                                                           |
| `current-email-<desktop/mobile>-<light/dark>.png`                      | Before: the current Notifications worktree render                                                                          |

---

## 6. Voice and messages

### Principles

1. **Write for the customer, not the system.** Say what happened and what they need to do. Never use internal words such as "service ID", "module", "cron", "provisioning" or "suspension reason code".
2. **Subject = document, company and number, then what's needed.** Use the owner's reference pattern: "Your receipt from Mohar #RCPT-2026-0311", "Invoice INV-2026-0142 from Mohar: ₹1,28,486.66 due 1 Nov 2026", "Payment overdue: invoice INV-2026-0098 ($248.00)". Never "Invoice Notification".
3. **The preheader is written on purpose.** It adds the next most useful fact, or the reassurance, and never repeats the subject or falls back to body text.
4. **Greeting by first name, then one opening sentence** on what this is about.
5. **One action, labelled with its outcome:** "Pay ₹1,28,486.66", "Reset password", "View reply". When nothing needs doing (receipts) there is no button, only quiet links: "↓ Download receipt", "↓ Download invoice". Use a secondary text link only for a genuinely different need (updating a card).
6. **Key facts in a scannable block.** Use human dates ("25 Oct 2026"), amounts with the right symbol and grouping ($, ₹ and Rs with lakh grouping), the invoice or ticket number, and what was bought.
7. **Reassure where it helps.** The reset link expires in 60 minutes; "Ignore this email and your password stays the same"; "Your files and data stay safe for 30 days".
8. **Overdue notices are polite, firm and blame-free.** State the date and the consequence calmly ("If the invoice is still unpaid on that date, we'll pause the services below"), offer help ("Need more time? Reply and we'll work it out with you"), and don't accuse ("You failed to pay").
9. **Friendly and plain.** Use contractions, no exclamation marks, no capitals for emphasis, and no "Dear valued customer".
10. **Always a way to reach a person.** In every footer: "Questions? Reply to this email or write to support@…". The sender is the company's legal name at a real, monitored alias (billing mail from `billing@…`), never noreply, with Reply-To set to support. Security mail adds "If this wasn't you, contact support@…".
11. **The 10-second test:** with images off and in plain text, can someone tell what this is, how much and by when, and what to do?

The five samples follow these rules word for word in all three directions (`tools/emails.mjs`). Their subjects and preheaders are rows in the table below.

### Subjects and preheaders for every template in the catalogue

Merge fields use the Notifications syntax. Fields marked † don't exist in `SampleContext` today, so the engineer adds them or falls back to the bracketed variant. Support subjects stay fixed as `[#{{ ticket.number }}] {{ ticket.subject }}` so threads hold together; their preheaders carry the news.

Subject pattern (the owner's reference): name the document type, the company and the number, then the amount and date when there is one.

- `Your receipt from {company} #{receipt}`
- `Invoice {number} from {company}: {amount} due {date}`
- `Payment overdue: invoice {number} ({amount})`

Keep subjects under about 70 characters where possible, with the most important words first (phones show about 40).

**Account and general**

| Template                         | Subject                                                                                          | Preheader                                                                                                         |
| -------------------------------- | ------------------------------------------------------------------------------------------------ | ----------------------------------------------------------------------------------------------------------------- |
| `auth.password_reset`            | Reset your {{ company.name }} password                                                           | This link expires in 60 minutes. If you didn't ask for it, ignore this email and your password stays the same.    |
| `auth.password_changed`          | Your {{ company.name }} password was changed                                                     | Changed on {{ system.today \| date }}. If this wasn't you, reset it now and contact {{ company.support_email }}†. |
| `auth.email_verification`        | Confirm your email address for {{ company.name }}                                                | One click confirms {{ user.email }} so your invoices and service details reach you.                               |
| `auth.user_invitation`           | {{ inviter.name }}† invited you to manage {{ client.company_name \| default(client.full_name) }} | Accept to see invoices, services and tickets for this account. The invitation expires in 7 days.                  |
| `auth.new_login_alert`           | New sign-in to your {{ company.name }} account                                                   | {{ auth.device }}† near {{ auth.location }}† at {{ auth.time \| datetime }}†. Not you? Change your password now.  |
| `client.welcome`                 | Welcome to {{ company.name }}, {{ client.first_name }}                                           | Your account is ready. Sign in to order services, pay invoices and get help.                                      |
| `order.confirmation`             | Order #{{ order.number }} from {{ company.name }}: {{ order.total }}†                            | Thanks for your order. We'll email you as soon as each service is ready.                                          |
| `quote.delivered`                | Quote {{ quote.number }} from {{ company.name }}: {{ quote.total }}†                             | {{ quote.subject }}. Valid until {{ quote.valid_until \| date }}†. Accept it online to place the order.           |
| `affiliate.activated`            | Your {{ company.name }} affiliate account is ready                                               | Share your link and earn commission on every referred order. Your dashboard has the details.                      |
| `affiliate.withdrawal_processed` | Affiliate payout sent: {{ affiliate.withdrawal_amount }}                                         | Paid by {{ payout.method }}† on {{ system.today \| date }}. It can take a few working days to arrive.             |
| `affiliate.monthly_summary`      | Your affiliate summary for {{ period.month }}†                                                   | {{ affiliate.referrals }}† referrals and {{ affiliate.commission_earned }}† earned this month.                    |

**Services**

| Template                           | Subject                                                                             | Preheader                                                                                                                                                  |
| ---------------------------------- | ----------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `service.welcome.shared_hosting`   | Your hosting for {{ service.domain \| default(service.product_name) }} is ready     | Sign-in details and nameservers inside. Point your domain to us to go live.                                                                                |
| `service.welcome.reseller_hosting` | Your reseller hosting account is ready                                              | Sign in to your control panel to create your first customer account. Details inside.                                                                       |
| `service.welcome.server`           | Your server {{ service.server_hostname \| default(service.product_name) }} is ready | IP {{ service.dedicated_ip }} and sign-in details inside. Change the password after your first sign-in.                                                    |
| `service.welcome.other`            | Your {{ service.product_name }} is ready                                            | Everything you need to get started is inside.                                                                                                              |
| `service.suspended`                | {{ service.product_name }} is paused                                                | {% if invoice %}Pay invoice {{ invoice.number }} to restore it.{% else %}Reply to this email and we'll help restore it.{% endif %} Your data is kept safe. |
| `service.unsuspended`              | {{ service.product_name }} is running again                                         | Everything is back as it was. Thanks for sorting this out.                                                                                                 |
| `service.cancellation_requested`   | We've received your request to cancel {{ service.product_name }}                    | It ends on {{ service.next_due_date \| date }}. Changed your mind? Reply before then.                                                                      |
| `service.upgrade_completed`        | Your upgrade to {{ service.product_name }} is complete                              | The new resources are live now. Your next invoice shows the new price.                                                                                     |
| `service.terminated`               | {{ service.product_name }} has been removed                                         | The service and its data were deleted on {{ system.today \| date }}. Reply if you'd like help setting up again.                                            |

**Billing**

| Template                       | Subject                                                                                                                         | Preheader                                                                                                                                                                                                                         |
| ------------------------------ | ------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `invoice.created`              | Invoice {{ invoice.number }} from {{ company.name }}: {{ invoice.total }} due {{ invoice.due_date \| date }}                    | Pay by {{ invoice.due_date \| date }} to keep your services running. The PDF invoice is attached.                                                                                                                                 |
| `invoice.created_autocharge`   | Invoice {{ invoice.number }} from {{ company.name }}: {{ invoice.total }}, paid automatically on {{ invoice.due_date \| date }} | No action needed. We'll charge your saved {{ payment.method_label }}†.                                                                                                                                                            |
| `invoice.payment_reminder`     | Reminder: invoice {{ invoice.number }} ({{ invoice.balance }}) is due {{ invoice.due_date \| date }}                            | A friendly reminder. Paying online takes a minute; reply if anything needs changing.                                                                                                                                              |
| `invoice.overdue_first`        | Payment overdue: invoice {{ invoice.number }} ({{ invoice.balance }})                                                           | It was due on {{ invoice.due_date \| date }}. Please pay by {{ invoice.suspension_date \| date }}† to keep your services running.                                                                                                 |
| `invoice.overdue_second`       | Second reminder, payment overdue: invoice {{ invoice.number }} ({{ invoice.balance }})                                          | Now {{ invoice.days_overdue }} days overdue. Services pause on {{ invoice.suspension_date \| date }}† if it stays unpaid.                                                                                                         |
| `invoice.overdue_third`        | Final reminder, payment overdue: invoice {{ invoice.number }} ({{ invoice.balance }})                                           | Services pause on {{ invoice.suspension_date \| date }}†. Need more time? Reply and we'll work it out with you.                                                                                                                   |
| `invoice.payment_confirmation` | Your receipt from {{ company.name }} #{{ payment.receipt_number }}†                                                             | Thank you. {{ payment.amount }} paid with {{ payment.method_label }}† on {{ payment.date \| date }}. {% if invoice.balance_due %}{{ invoice.balance }} is still due.{% else %}Invoice {{ invoice.number }} is settled.{% endif %} |
| `invoice.payment_failed`       | Payment didn't go through: invoice {{ invoice.number }} ({{ invoice.balance }})                                                 | {{ payment.failure_reason \| default('Your bank declined it') }}. Pay with another method by {{ invoice.due_date \| date }} to avoid interruption.                                                                                |
| `invoice.refunded`             | Refund from {{ company.name }}: {{ refund.amount }} for invoice {{ invoice.number }}                                            | It can take 5–10 working days to appear, depending on your bank.                                                                                                                                                                  |
| `invoice.modified`             | Invoice {{ invoice.number }} from {{ company.name }} was updated: {{ invoice.total }}                                           | {{ invoice.balance }} is due by {{ invoice.due_date \| date }}. The updated PDF is attached.                                                                                                                                      |
| `payment_method.expiring`      | Your {{ payment_method.label }}† expires on {{ payment_method.expiry }}†                                                        | Add a new card before then so renewals keep going through.                                                                                                                                                                        |
| `credit_note.issued`           | Credit note {{ credit_note.number }}† from {{ company.name }}: {{ credit_note.amount }}†                                        | Issued against invoice {{ invoice.number }}. The amount is now credit on your account.                                                                                                                                            |

**Domains**

| Template                           | Subject                                                                          | Preheader                                                                                                                             |
| ---------------------------------- | -------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------- |
| `domain.registration_confirmation` | {{ domain.domain }} is registered to you                                         | It's active until {{ domain.expiry_date \| date }}. DNS changes can take a few hours to reach everyone.                               |
| `domain.transfer_initiated`        | Transfer of {{ domain.domain }} has started                                      | Most transfers finish within 5–7 days. Approving the request at your current registrar speeds it up.                                  |
| `domain.transfer_completed`        | {{ domain.domain }} has moved to {{ company.name }}                              | Transfer complete. It renews on {{ domain.expiry_date \| date }}.                                                                     |
| `domain.transfer_failed`           | Transfer of {{ domain.domain }} didn't complete                                  | {{ domain.failure_reason \| default('The current registrar rejected it') }}. Here's how to fix it and try again.                      |
| `domain.renewal_confirmation`      | {{ domain.domain }} is renewed until {{ domain.expiry_date \| date }}            | Nothing else to do. Your website and email keep working.                                                                              |
| `domain.renewal_upcoming`          | Domain renewal: {{ domains.first }}† expires {{ domains.first_expiry \| date }}† | Renew now to keep your website and email working. {% if domains.count > 1 %}{{ domains.count }} domains are listed inside.{% endif %} |
| `domain.expired_notice`            | {{ domains.first }}† has expired                                                 | Your website and email at this domain have stopped. Renew by {{ domains.grace_end \| date }}† to restore them at the normal price.    |

**Support** (the subject is fixed to `[#{{ ticket.number }}] {{ ticket.subject }}` for threading; the preheader carries the news)

| Template                 | Preheader                                                                                 |
| ------------------------ | ----------------------------------------------------------------------------------------- |
| `ticket.autoresponse`    | We've received your request and will reply soon. Reply to this email to add details.      |
| `ticket.opened_by_staff` | {{ staff.first_name }}† from {{ ticket.department }} opened this ticket for you.          |
| `ticket.reply`           | {{ reply.author_first_name }}† from {{ ticket.department }} replied: {{ reply.excerpt }}† |
| `ticket.auto_closed`     | We closed this ticket because we haven't heard back. Reply to reopen it.                  |

| Template                     | Subject                                                  | Preheader                                                                                             |
| ---------------------------- | -------------------------------------------------------- | ----------------------------------------------------------------------------------------------------- |
| `ticket.closed_bounce`       | Your email wasn't added to ticket #{{ ticket.number }}   | That ticket is closed. Open a new ticket and we'll pick it up from there.                             |
| `ticket.unregistered_bounce` | We couldn't accept your email to {{ ticket.department }} | This address only takes email from customers with an account. Open a ticket from our website instead. |
| `ticket.replies_only_bounce` | Your email to {{ ticket.department }} wasn't delivered   | This address only accepts replies to existing tickets. Open a new ticket instead.                     |

**Staff** (operational, status first, no greeting needed)

| Template                             | Subject                                                                             | Preheader                                                                                                   |
| ------------------------------------ | ----------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------- |
| `staff.order_new`                    | New order #{{ order.number }}: {{ order.total }}† from {{ client.full_name }}       | Review and accept it in the admin area.                                                                     |
| `staff.order_fraud`                  | Order #{{ order.number }} needs a fraud review                                      | {{ fraud.reason }}†. Accept or cancel it in the admin area.                                                 |
| `staff.ticket_new`                   | [#{{ ticket.number }}] New ticket: {{ ticket.subject }}                             | {{ client.full_name }} · {{ ticket.department }} · {{ ticket.priority }} priority                           |
| `staff.ticket_client_reply`          | [#{{ ticket.number }}] Client reply: {{ ticket.subject }}                           | {{ client.full_name }} replied: {{ reply.excerpt }}†                                                        |
| `staff.ticket_assigned`              | [#{{ ticket.number }}] Assigned to you: {{ ticket.subject }}                        | {{ ticket.priority }} priority, opened {{ ticket.created_at \| date }}†.                                    |
| `staff.ticket_mention`               | [#{{ ticket.number }}] {{ note.author }}† mentioned you                             | "{{ note.excerpt }}"†                                                                                       |
| `staff.ticket_department_changed`    | [#{{ ticket.number }}] Moved to {{ ticket.department }}                             | It's now in your department's queue.                                                                        |
| `staff.provisioning_failed`          | Couldn't {{ failure.action }} {{ service.product_name }} for {{ client.full_name }} | {{ failure.message }}. Retry or fix it from the service page.                                               |
| `staff.registrar_action_failed`      | Registrar couldn't {{ failure.action }}† {{ domain.domain }}                        | {{ failure.message }}. Retry it from the domain page.                                                       |
| `staff.domain_sync_report`           | Domain sync: {{ sync.change_count }} changes, {{ sync.error_count }} errors         | Expiry dates and statuses were checked with each registrar.                                                 |
| `staff.free_domain_renewal_blocked`  | Free renewal blocked: {{ domain.domain }}                                           | The linked hosting isn't active, so the free renewal was skipped. Please review.                            |
| `staff.cancellation_request`         | Cancellation request: {{ service.product_name }} for {{ client.full_name }}         | {{ cancellation.type }}. Reason: "{{ cancellation.reason }}"                                                |
| `staff.affiliate_withdrawal_request` | Affiliate payout request: {{ affiliate.withdrawal_amount }}                         | From {{ affiliate.name }}†. Approve it in the admin area.                                                   |
| `staff.automation_summary`           | Daily automation {{ automation.run_at \| date }}: {{ automation.status }}           | {{ automation.summary }}†: invoices created, reminders sent, services paused, failures.                     |
| `staff.password_reset`               | Reset your {{ company.name }} staff password                                        | This link expires in 60 minutes. If you didn't ask for it, ignore this email.                               |
| `staff.invitation`                   | You're invited to the {{ company.name }} admin area                                 | Accept to set your password. The invitation expires in 7 days.                                              |
| `staff.login_failures`               | Repeated failed sign-ins for {{ security.email }}                                   | {{ security.attempts }}† attempts; the last was from {{ security.ip }}†. The account is temporarily locked. |
| `staff.client_details_changed`       | {{ client.full_name }} updated their details                                        | Changed: {{ changes.fields }}†.                                                                             |
| `staff.webhook_endpoint_disabled`    | Webhook endpoint turned off after repeated failures                                 | Last error: {{ webhook.last_error }}†. Fix it and turn it back on in Settings.                              |
| `staff.template_render_failed`       | Email template "{{ template.key }}" failed to render                                | {{ failure.message }}. The email was logged but not sent.                                                   |

---

## 7. Compatibility: screen sizes and clients

### Automated layout checks (`tools/shoot.mjs`, `tools/layout-report.json`)

Each direction was checked for all five emails at 320, 375, 414, 600, 720 and 1024 px, plus the stress sample at 320, 375, 414 and 720 px and the invoice with `<style>` removed at 375 px. The check looks for any element outside the viewport, horizontal scroll, and button size. **Result: 0 failures in 141 checks.**

- Every button is 50 px tall.
- On phones the button fills the content width: 276 px at 320, 315 px at 375 and 354 px at 414.
- With styles stripped, A's and B's buttons fall back to their inline width (185 × 50), which is still above 44 px.

Two issues were found and fixed along the way:

- A's 48 px amount and B's inline link row overflowed a 375 px phone when styles were stripped, which led to the mobile-first inline sizes.
- B's white wordmark disappeared under forced inversion, which led to the live-text brand name.

| Check                                                                                     | A                                                                    | B                                   | C                             |
| ----------------------------------------------------------------------------------------- | -------------------------------------------------------------------- | ----------------------------------- | ----------------------------- |
| 320 px (small phone)                                                                      | Pass                                                                 | Pass                                | Pass                          |
| 375 / 414 px                                                                              | Pass                                                                 | Pass                                | Pass                          |
| 600 px and up (desktop), card centred (A 640, B/C 600)                                    | Pass                                                                 | Pass                                | Pass                          |
| ~1024 px wide pane, content centred                                                       | Pass                                                                 | Pass                                | Pass                          |
| Long invoice number, long product name and hostname, ₹12,34,56,789.00, three-line address | Pass (values wrap; the amount steps down to 26 px at 360 px or less) | Pass                                | Pass                          |
| Facts reflow on narrow screens                                                            | Label over value below 420 px                                        | Two-column grid stacks below 620 px | Label over value below 420 px |
| `<style>` stripped at 375 px                                                              | Pass (24 px padding, 36 px amount, thumbnail hidden)                 | Pass (links under the button)       | Pass                          |
| Button full width on phones, at least 44 px                                               | Pass (50 px; inline on desktop)                                      | Pass (50 px; inline on desktop)     | Pass (50 px)                  |

### Light, dark and clients

"Simulated" means we captured it. "Expected" is based on documented client behaviour that we couldn't render here.

| Client / mode                                                             | A · Two-card receipt                                                                                                                                                   | B · Branded band                                                                                                                                                                | C · Status rail                                          |
| ------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------- |
| Apple Mail (macOS and iOS), light                                         | Pass (simulated)                                                                                                                                                       | Pass (simulated)                                                                                                                                                                | Pass (simulated)                                         |
| Apple Mail and iOS Mail, dark (`prefers-color-scheme`)                    | Pass (simulated): own dark palette, dark logo swapped in                                                                                                               | Pass (simulated): band darkens to `#2E2C78`                                                                                                                                     | Pass (simulated): rail and status keep their tone        |
| Outlook.com, dark (partial invert plus `[data-ogsc]`/`[data-ogsb]`)       | Expected pass: the attribute rules pin our dark palette and logo swap                                                                                                  | Expected pass                                                                                                                                                                   | Expected pass                                            |
| Outlook iOS and Android, dark                                             | Expected pass (`prefers-color-scheme` supported)                                                                                                                       | Expected pass                                                                                                                                                                   | Expected pass                                            |
| Gmail web, light (its dark theme leaves the message body alone)           | Pass (webmail-pane capture)                                                                                                                                            | Pass                                                                                                                                                                            | Pass                                                     |
| Gmail Android (partial invert: light backgrounds go dark, dark ones stay) | Pass (approximated by the inversion capture)                                                                                                                           | Pass: the band is already dark-on-light text, so it stays                                                                                                                       | Pass                                                     |
| Gmail iOS (full invert, ignores our dark rules)                           | Pass (simulated): text AA, status legible, the brand name above the card is live text, and the document thumbnail stays a light page (decorative, reads as a document) | **Workaround applied:** the band turns light lavender, so the brand name is live text (it inverts to dark) and the mark is a white tile with an indigo glyph that reads on both | Pass (simulated)                                         |
| Outlook desktop (Windows, Word engine), light                             | Workaround: fixed-width MSO table (640 or 600 px), Arial fallback, square corners, bulletproof `td` button with `mso-padding-alt`                                      | Same; card shadow dropped                                                                                                                                                       | Same; tracker dots and perforation notches render square |
| Outlook desktop, dark (Office 365 full invert, not targetable)            | ≈ inversion capture: pass                                                                                                                                              | ≈ pass (same band workaround)                                                                                                                                                   | ≈ pass                                                   |
| Yahoo / AOL (dark mode not supported)                                     | Stays light: pass                                                                                                                                                      | Stays light: pass                                                                                                                                                               | Stays light: pass                                        |
| Head styles stripped (Gmail with non-Google accounts, some webmail)       | Pass: inline styles carry it; facts stay side by side; the thumbnail stays visible                                                                                     | Pass: the two-column grid doesn't stack but remains legible                                                                                                                     | Pass                                                     |
| Images off                                                                | Pass: styled alt text "Mohar" in place of the logo                                                                                                                     | Pass: the brand name is text                                                                                                                                                    | Pass                                                     |

**Size:** every reference email is 18–26 KB of HTML, well under Gmail's 102 KB clipping limit. Keep the layout's CSS lean so operator content doesn't push long emails over it.

**Client limits we couldn't simulate:**

- Word-engine rendering in Outlook desktop (checked by code review of the MSO paths, not by render).
- Outlook.com's own colour rewriting (we only add the `[data-ogsc]`/`[data-ogsb]` overrides).
- Gmail Android's exact partial-inversion algorithm (our capture is a full inversion, which is the harsher case).
- Samsung Email's mixed dark-mode handling.

Before launch, run the chosen direction through a real-client service (Litmus or Email on Acid) or a device check on Gmail iOS and Outlook 365 Windows.

---

## 8. Accessibility review (WCAG 2.1 AA)

### Contrast

Computed by `tools/contrast.mjs` for every text and background pair the emails use. The "Forced inversion" column applies `invert(1) hue-rotate(180deg)` in sRGB to both colours. **All pairs pass AA in all three modes.**

| Pair                             | Min   | Light                      | Dark (our styles)          | Forced inversion |
| -------------------------------- | ----- | -------------------------- | -------------------------- | ---------------- |
| Body text on card                | 4.5:1 | #323A4E on #FFFFFF · 11.35 | #C9CEDA on #181C26 · 10.81 | 12.22            |
| Headings / amounts on card       | 4.5:1 | #1A2133 on #FFFFFF · 16.04 | #EEF0F5 on #181C26 · 14.94 | 15.65            |
| Muted text on card               | 4.5:1 | #5B6478 on #FFFFFF · 5.93  | #9AA3B5 on #181C26 · 6.72  | 7.62             |
| Muted text on panel              | 4.5:1 | #5B6478 on #F7F8FB · 5.59  | #9AA3B5 on #1E2330 · 6.19  | 7.31             |
| Footer text on frame             | 4.5:1 | #5B6478 on #F3F4F8 · 5.40  | #9AA3B5 on #0F121A · 7.38  | 7.14             |
| Footer text on warm frame (A)    | 4.5:1 | #5B6478 on #EFEEEA · 5.11  | #9AA3B5 on #0F121A · 7.38  | 6.85             |
| Footer link on warm frame (A)    | 4.5:1 | #4F4BC4 on #EFEEEA · 5.77  | #A9ACF0 on #0F121A · 8.78  | 8.20             |
| Brand name on warm frame (A)     | 4.5:1 | #1A2133 on #EFEEEA · 13.82 | #EEF0F5 on #0F121A · 16.42 | 14.06            |
| Link on card                     | 4.5:1 | #4F4BC4 on #FFFFFF · 6.70  | #A9ACF0 on #181C26 · 7.99  | 9.13             |
| Link on frame (footer)           | 4.5:1 | #4F4BC4 on #F3F4F8 · 6.09  | #A9ACF0 on #0F121A · 8.78  | 8.56             |
| Button label on button           | 4.5:1 | #FFFFFF on #4F4BC4 · 6.70  | #12142A on #8B8FEA · 6.22  | 9.13             |
| Button vs card (non-text)        | 3:1   | #4F4BC4 on #FFFFFF · 6.70  | #8B8FEA on #181C26 · 5.85  | 9.13             |
| Band text (B) on band            | 4.5:1 | #E2E1F8 on #4F4BC4 · 5.22  | #D4D3F5 on #2E2C78 · 8.26  | 7.35             |
| Code text on code block          | 4.5:1 | #1A2133 on #F1F2F6 · 14.34 | #EEF0F5 on #12151D · 16.01 | 14.49            |
| Chip accent: text on subtle      | 4.5:1 | #3F3BA8 on #EEEDFB · 7.55  | #B3B5F3 on #23244A · 7.61  | 9.76             |
| Status accent: text on card (C)  | 4.5:1 | #3F3BA8 on #FFFFFF · 8.74  | #B3B5F3 on #181C26 · 8.78  | 10.86            |
| Chip success: text on subtle     | 4.5:1 | #23704D on #EAF6F0 · 5.42  | #7FD1A8 on #172A22 · 8.33  | 7.99             |
| Status success: text on card (C) | 4.5:1 | #23704D on #FFFFFF · 6.01  | #7FD1A8 on #181C26 · 9.40  | 8.60             |
| Chip warning: text on subtle     | 4.5:1 | #875A0E on #FBF3E2 · 5.44  | #EBC27A on #2D2619 · 8.93  | 7.84             |
| Status warning: text on card (C) | 4.5:1 | #875A0E on #FFFFFF · 6.00  | #EBC27A on #181C26 · 10.16 | 8.43             |
| Chip danger: text on subtle      | 4.5:1 | #A6332F on #FBEDEC · 5.88  | #F09C97 on #32191B · 7.68  | 9.46             |
| Status danger: text on card (C)  | 4.5:1 | #A6332F on #FFFFFF · 6.70  | #F09C97 on #181C26 · 8.04  | 10.43            |
| Chip info: text on subtle        | 4.5:1 | #2B6297 on #EAF2FA · 5.64  | #94BFEA on #17253A · 8.00  | 8.08             |
| Status info: text on card (C)    | 4.5:1 | #2B6297 on #FFFFFF · 6.37  | #94BFEA on #181C26 · 8.84  | 8.83             |
| Callout body on warning subtle   | 4.5:1 | #323A4E on #FBF3E2 · 10.28 | #C9CEDA on #2D2619 · 9.50  | 11.36            |

### Structure and screen readers

- `lang="en"` and `dir` on `<html>` and on the `role="article"` wrapper, with `aria-roledescription="email"` and `aria-label` set to the subject.
- Every layout table has `role="presentation"`. Line items are a real data table with `<th scope="col">` ("What you're paying for", "Amount").
- One `h1` per email (the title, for non-amount emails). The amount is a styled `p`, so screen readers read it in order: label, value, date.
- Decorative status dots are `aria-hidden`; the status word itself carries the meaning, never colour alone (WCAG 1.4.1). Avatar initials are `aria-hidden`, and the author's name follows as text.
- C's progress tracker has an `aria-label` that reads the steps as text ("Issued 18 Oct (done), Due 1 Nov (now), Paid — (next)").
- The logo `alt` is the brand name, with styled alt text for images-off. B's band mark has an empty `alt` because the brand name follows as live text.
- Link text stands on its own ("Download PDF invoice", "Update payment method", "Email preferences").

### Keyboard, targets and text size

- Buttons and links are native `<a>` elements, so they are focusable in webmail, with a `:focus-visible` outline where styles are kept.
- Targets: buttons are 50 px tall and full width on phones. Footer links are 14 px text on a 22 px line with a separator between them; they are inline text links, so they fall under the inline exception in WCAG 2.5.8.
- Text: body 16/24, details 15/22, notes 14/21, footer at least 13/20. Nothing is below 13 px, and line height is at least 1.5.

### Findings to carry into implementation

| #   | Issue                                                                 | Criterion | Severity           | Recommendation                                                                                                                                                               |
| --- | --------------------------------------------------------------------- | --------- | ------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | Current dark footer brand name 1.07:1                                 | 1.4.3     | Critical (current) | Fixed in all directions by the class-based dark map                                                                                                                          |
| 2   | Current light footer text 2.94:1                                      | 1.4.3     | Major (current)    | `#5B6478`                                                                                                                                                                    |
| 3   | Custom themes can pick an accent that fails on white or on dark cards | 1.4.3     | Major              | Run the Themes module's AA guard on the email pairs too (button label on accent, link on card, chip text on subtle), and fall back to Calm Fintech values when a pair fails. |
| 4   | Operator-edited Markdown can add images without alt text              | 1.1.1     | Minor              | The template editor warns when an image has no alt text.                                                                                                                     |

---

## 9. Implementation note for the Notifications engineer

This applies to the direction the owner picks. It is written for **A** (recommended), with B and C differences at the end. Work in `resources/views/vendor/mail/` in the Notifications worktree. Don't fork per template: every email keeps going through `<x-mail::message>`.

### Map onto Laravel's mail components

| Reference block                                                                           | Laravel piece                                                 | Change                                                                                                                                                                                                                                                                                                                                               |
| ----------------------------------------------------------------------------------------- | ------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Shell (head, meta, MSO, `role="article"` wrapper, preheader, fixed-width MSO ghost table) | `html/layout.blade.php`                                       | Copy the `<head>` and wrapper from any `directions/a/*.html`. Keep `x-apple-disable-message-reformatting` and `format-detection`. The `<style>` block keeps mobile rules plus dark rules. Generate the dark rules (`@media`, `[data-ogsc]`, `[data-ogsb]`) from one PHP token array, like `darkRules()` in `tools/build.mjs`.                        |
| Brand row (mark + name) above the cards; card 1 (summary); card 2 (record); brand footer  | `html/message.blade.php`                                      | New props: `status` (tone, label), `summaryLabel`, `reference`, `help`, `reason`, `preheader`, `preferencesUrl`. The `$slot` (operator Markdown) renders in card 1. Card 2 is filled from the presenter (line items or details). The footer adds "Questions? Reply to this email or write to {support email}" (plain text) and the Help centre link. |
| Status line                                                                               | new `html/status.blade.php`                                   | "● {label} · {when}"; tone → class `tx-<tone>`, `dotc-<tone>`. Colours come from the theme's semantic tokens.                                                                                                                                                                                                                                        |
| Amount hero with document thumbnail                                                       | new `html/amount.blade.php`                                   | `value` (already formatted by Money), `when`. The thumbnail images ship with the app (`public/brand/email/doc-{light,dark}.png`).                                                                                                                                                                                                                    |
| Download links                                                                            | new `html/downloads.blade.php`                                | "↓ Download invoice / receipt" signed URLs, plus an optional secondary link. No button on receipts.                                                                                                                                                                                                                                                  |
| Progress tracker (C only)                                                                 | new `html/steps.blade.php`                                    | `steps: list<{label, date, state: done                                                                                                                                                                                                                                                                                                               | current | alert | upcoming}>`. |
| Button                                                                                    | `html/button.blade.php`                                       | Replace the floating `align="left"` table with the full-width block button (`td bgcolor` plus `mso-padding-alt`, `a` display block). This fixes the wrap bug.                                                                                                                                                                                        |
| Key facts                                                                                 | `html/table.blade.php` / Markdown table                       | Keep the Markdown table convention. The renderer adds `f-k`/`f-v` classes and the label/value styles.                                                                                                                                                                                                                                                |
| Line items                                                                                | new `html/line-items.blade.php`                               | Items plus totals, with a real `<th scope>` header. Fed from the invoice context, not Markdown.                                                                                                                                                                                                                                                      |
| Notice                                                                                    | `html/panel.blade.php` → rename to callout                    | Tone, title, text.                                                                                                                                                                                                                                                                                                                                   |
| Ticket message                                                                            | new `html/reply.blade.php`                                    | Author, role, time, body HTML (already sanitised by Support), left rule in `info`.                                                                                                                                                                                                                                                                   |
| Plain text                                                                                | `text/message.blade.php` plus text versions of each component | Follow the block order in §3. The sample output is in `directions/plain-text/`.                                                                                                                                                                                                                                                                      |

### Where the data comes from

- **The operator edits copy, the system computes state.** Status tone and label, the amount block, the tracker steps, line items and the reference come from a per-template **presenter** resolved by template key (for example `InvoicePresenter` for `invoice.*`), using the merge context. Operators keep editing Markdown for the greeting, opening, notes and help line.
- The existing conventions still apply: `# Heading` is the title for non-amount emails, the first paragraph is the opening, a table is the facts, and the first link on its own line is the primary button. Add one: a second link line becomes the secondary link.
- **New template fields:** `preheader` per locale (required, shown in the editor with a character counter, about 90 characters), and optionally `help` per locale. Seed them from §6.
- New merge fields marked † in §6, for example `invoice.days_until_due`, `invoice.suspension_date`, `payment.method_label`, `company.support_email`, `auth.device`, `auth.location`.

### Brand and theme

- `BrandProvider` must give **absolute PNG URLs** with known pixel sizes:
    - `emailLogoLight`: dark wordmark with the white halo; see `assets/logo-light.png` and `tools/make-logos.mjs`.
    - `emailLogoDark`.
    - `emailMark` (light and dark).
    - For B only, `emailMarkOnAccent`.
- Rasterise uploaded SVGs to 2x PNG when the logo is uploaded (Themes module). SVG doesn't render in Gmail or Outlook.
- Fix `public/brand/logo-dark.png`, which is missing.
- **Colours** come from the active client theme preset (Calm Fintech by default): accent, on-accent, semantic tones (dot, text, subtle) for light and dark, card, frame, border, text and muted. Custom themes go through the AA guard for the email pairs listed in §8.
- **Width:** 640 px for A; 600 px for B or C (§4). Make it a single layout constant.
- **Payment method:** a gateway's official logo (Khalti, eSewa, PayPal…) from the Gateways module at 16–20 px tall, or the card brand plus last 4. The reference HTML uses a neutral text badge in their place.

### Headers and sending

- `From`: the company's **legal name** (company profile) as the display name.
    - The address is a billing alias for billing mail (`billing@…`) and a general alias for everything else.
    - Never `noreply`.
    - `Reply-To`: the support address, or a ticket-piping address for ticket mail.
- **Attachments:** attach the invoice or receipt PDF to invoice, reminder and receipt mail when the setting is on (issue #29). The email always also carries the "↓ Download" links.
- `Auto-Submitted: auto-generated` and `X-Auto-Response-Suppress: All` on all automated mail.
- For tickets: `Message-ID`, `In-Reply-To` and `References`.
- `List-Unsubscribe` and `List-Unsubscribe-Post` (RFC 8058) only on optional categories, pointing to a signed one-click category opt-out.
- Deployment checklist, not code: SPF, DKIM and DMARC aligned with the From domain; BIMI is optional later.

### Testing

- A render test per template key: the HTML contains the status, the amount, the button URL and a footer with the address, support email and preferences link. The plain text contains the same facts.
- Reuse `tools/shoot.mjs` against the template preview route for screenshots and the overflow check at 320, 375, 414, 720 and 1024 px, including the styles-stripped pass.

### If the owner picks B or C instead

- **B:** one card with a band row (mark-on-accent image plus the brand name as **live text**, never a white wordmark image), and a summary panel component (amount plus a two-column facts grid that stacks below 620 px). The button is inline on desktop.
- **C:** one card with a 4 px status rail, logo and reference in the card header, the progress tracker (`steps`), a perforated divider and a tinted card foot for the help line. Its tracker needs the presenter to compute steps per template. Tracker dots and notches render square in Outlook desktop.

---

## Recommendation

**Choose A, "Two-card receipt".**

- It is the owner's own reference pattern, adapted to our brand, currencies and payment methods.
- It answers "dull" with hierarchy and restraint rather than decoration: one big number, a coloured status line that says what state you're in, quiet download links, and the record in its own card.
- It stays calm and easy on the eyes: grey and black, with colour only for status and the one action.
- It works for every email type. The same two cards hold a password reset (request details) and a ticket reply (message, then ticket details).
- It passes every size and mode check.
- It is the least new code to build.

**Optional:** borrow C's progress tracker for the overdue and payment-failed emails only, if the owner wants more guidance there. It fits inside card 1 without changing the layout.

- **C, "Status rail"**, is the most distinctive and informative. Choose it if the owner wants every email to show progress. It costs about two to three extra days for the per-template presenters (tracker steps).
- **B** is the most visibly branded, but it spends the accent on decoration and needs the most care under forced inversion.

**Constraints the owner should know about:**

- The status, amount and record come from per-template presenters computed by the system, not from operator Markdown. That is about one to two days of Notifications work for A (more for C).
- Custom brands need PNG logo variants (generated on upload).
- Payment-method logos come from the Gateways module.
- Outlook desktop shows square corners: functional, less polished.
