# Design System

Rules for every admin and client-area page. Components live in `@/components/app/*`
(product patterns) on top of `@/components/ui/*` (shadcn primitives). Live reference:
`resources/js/pages/dev/components.tsx`. If a pattern you need is missing, add it to
`components/app` rather than building a one-off in a page.

## 1. Principles

1. **Calm by default.** Neutral surfaces, one primary button per view, colour only for
   meaning (status, money at risk, destructive). No decorative colour, no gradients.
2. **Data first.** Tables and numbers are the product. Use tabular figures, align money
   right, keep labels short and secondary (`text-muted-foreground`).
3. **One way to do a thing.** Same status colour, same money format, same confirm dialog
   everywhere. Consistency beats local cleverness.
4. **Server is the source of truth.** Filters, sort and page live in the query string;
   flash toasts come from the server; the UI never re-computes totals or taxes.

|                 | Admin panel (staff)                                 | Client area (customers)                                 |
| --------------- | --------------------------------------------------- | ------------------------------------------------------- |
| Density         | Compact: 25 rows/page, `py-2.5` cells, more columns | Relaxed: 10–15 rows, fewer columns, larger cards        |
| Language        | Precise, terse ("Mark paid", "Suspend")             | Friendly, plain ("Pay invoice", "Get help")             |
| Primary surface | Tables + filters                                    | Cards + clear next action ("You have 1 unpaid invoice") |
| IDs             | Show numbers/IDs everywhere                         | Show only what customers recognise (invoice #, domain)  |

## 2. Page anatomy

Wrap every page in `<Page>` (`size="narrow"` for forms). Always render `<Head title>`.

### List / index

```
┌──────────────────────────────────────────────────────────────┐
│ Invoices                                      [Export] [+ New]│  PageHeader (primary right-most)
│ All invoices across clients                                  │
├──────────────────────────────────────────────────────────────┤
│ [🔍 Search invoices…] [All statuses ▾] [Gateway ▾] ✕ Clear   │  FilterBar + FilterSelect
├──────────────────────────────────────────────────────────────┤
│ INVOICE   CLIENT          STATUS     DUE          TOTAL      │  DataTable (header bg-muted/40)
│ INV-1042  Acme Hosting    ● Unpaid   Oct 24, 2026  $129.00   │  whole row clickable
│ INV-1041  Northwind       ● Paid     Oct 10, 2026 $2,499.00  │
├──────────────────────────────────────────────────────────────┤
│ Showing 26–50 of 312 invoices       ‹ Prev 1 [2] 3 … 13 Next›│  Pagination (DataTable footer)
└──────────────────────────────────────────────────────────────┘
```

- Empty with no filters → `<EmptyState>` with icon, one-line explanation and the create
  action. Empty because of filters → "No invoices match these filters" + "Clear filters".
- Row actions (kebab menu) go in a last, right-aligned column marked `hideOnMobile`.
  Never put other links inside a clickable row's cells.
- Deferred lists render `<DataTableSkeleton>` until the prop arrives.

### Detail

```
‹ Invoices
INV-1042  ● Unpaid                     [⋯] [Edit] [Record payment]
Acme Hosting Ltd · Due Oct 24, 2026
┌─ Stat/summary cards (2–4): Total · Paid · Balance · Due ──────┐
├─ Tabs or stacked SectionCards ─────────────┬─ Side column ────┤
│ Details (DescriptionList, 2 cols)          │ Client card      │
│ Line items (DataTable inside flush card)   │ Balance summary  │
│ Activity (newest first, relative times)    │                  │
└────────────────────────────────────────────┴──────────────────┘
```

- Status badge sits next to the title (`PageHeader status=`). Rare/destructive actions live in
  the `⋯` dropdown (destructive items last, after a separator, `variant="destructive"`).
- Layout: `grid gap-4 lg:grid-cols-3`, main `lg:col-span-2`. Use `Tabs` only when there are
  4+ heavy sections (client profile: Overview · Services · Domains · Invoices · Tickets · Notes).

### Create / edit form

```
‹ Clients
New client
┌ SectionCard "Contact" ─────────────────────────────────┐
│ First name [          ]   Last name [          ]       │  grid sm:grid-cols-2 gap-6
│ Email [                                   ]            │  long fields span 2
└────────────────────────────────────────────────────────┘
┌ SectionCard "Billing" ─────────────────────────────────┐
│ Currency [USD ▾]   Tax ID [        ] (optional)        │
└────────────────────────────────────────────────────────┘
─────────────────────────────────── [Cancel] [Create client]   FormActions sticky
```

- Use Inertia `<Form {...Controller.store.form()}>` and `<Field>` for label/hint/error.
- Group fields into SectionCards of ≤ 8 fields. Mark _optional_ fields, not required ones.
- Long forms: `<FormActions sticky>`. Short single-card forms: buttons in the card `footer`.
- Submit button shows `<Spinner />` and is `disabled` while `processing`.

### Dashboard

```
[Income this month ↑12%] [Overdue $3,120 ↑3] [Active services] [Open tickets]   StatCard ×4
┌ Recent orders (DataTable, 5 rows) ──────┐ ┌ Tickets awaiting reply ────┐
└─────────────────────────── View all → ──┘ └──────────────────── View all →┘
```

- Stat cards link to the filtered list they summarise (`href`). Backend cards send icon names;
  map with `navIcon(card.icon)` from `@/lib/nav-icons`.
- Client dashboard leads with what needs action (unpaid invoice, expiring domain) before stats.

### Settings

Left sub-nav (≤ 10 items) + `SectionCard`s on the right, each with its own Save in the card
footer. Toggles use `Switch` in a bordered row with title + one-line description.

### Destructive confirmation

```
┌───────────────────────────────────────────────┐
│ Terminate acme.example.com?                   │  question with the object's name
│ The account and all files are deleted on the  │  consequence + reversibility
│ server. This can't be undone.                 │
│ Type acme.example.com to confirm [          ] │  only for irreversible actions
│                       [Cancel] [Terminate service] │
└───────────────────────────────────────────────┘
```

Use `<ConfirmDialog action={Controller.destroy(model)} …/>`. It keeps the dialog open while
processing and shows the server's error inline. Use `confirmText` for terminate, delete client,
delete with payments. Never use `window.confirm`.

## 3. Status colours (single mapping — `StatusBadge`)

Always render statuses with `<StatusBadge status={model.status} />`; it accepts `snake_case`
or kebab-case and humanises the label. Add new statuses to `STATUS_TONES` in
`status-badge.tsx`, never inline colours.

| Tone             | Meaning                   | Statuses                                                                                                                                              |
| ---------------- | ------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------- |
| success (green)  | Healthy / settled         | invoice `paid` · order/service/domain/client `active` · ticket `open`, `answered` · quote `accepted`                                                  |
| warning (amber)  | Waiting on someone        | invoice `unpaid`, `partially_paid`, `payment_pending` · order/service/domain `pending` · domain `pending_transfer`, `grace` · ticket `customer_reply` |
| danger (red)     | Money or service at risk  | invoice `overdue`, `collections` · service `suspended` · `fraud` (order/service/domain) · domain `expired`, `redemption` · `failed`                   |
| info (blue)      | Informational / in motion | invoice `refunded` · quote `delivered` · ticket `in_progress`                                                                                         |
| neutral (grey)   | Finished / inert          | `cancelled` · service `terminated` · ticket/client `closed` · client `inactive` · ticket `on_hold` · domain `transferred_away` · quote `declined`     |
| outline (dashed) | Not yet issued            | invoice/quote `draft`                                                                                                                                 |

`overdue` is derived (unpaid + past due); the backend should send it as the display status.

## 4. Formatting

**Money** — the backend sends integer minor units + ISO code (ADR 0002). Render with
`<Money amount={invoice.total} currency={invoice.currency} />` or `formatMoney()`; never
divide by 100 or concatenate `$` yourself. Use `toMinor()` when a form field takes a
decimal amount.

- Right-align money columns (`align: 'right'`); always `tabular-nums` (built in).
- Negatives use a real minus: `−$25.00`, never parentheses. Credit applied / discounts are
  negative. `tone="ledger"` (credit ledger, balances) mutes zero and reds negatives.
- `signed` shows `+$5.00` for adjustments. `showCode` (`$104.00 USD`) on totals when the
  client's currency differs from the default or several currencies appear on screen.
- Missing amount renders `—`, not `$0.00`.

**Dates** — `<DateTime value={iso} />`. Date-only values (`2026-10-24`) are calendar dates and
never shift timezone.

- Absolute (`Oct 24, 2026`): due dates, expiry, renewal, issue dates, anything contractual.
- Relative (`relative` → "3 days ago", absolute in tooltip): activity, ticket replies, last login.
- Combine for urgency: `Due Oct 24, 2026 · in 7 days`.

## 5. Feedback & states

- **Toasts**: server-driven via `Inertia::flash('toast', ['type' => 'success', 'message' => …])`.
  Past tense, specific: "Invoice INV-1042 marked as paid." Errors that need action stay inline,
  not in a toast. No toast for plain navigation or validation errors.
- **Validation**: field errors under the field (`<Field error>`), sentence case, say how to fix:
  "Enter a valid hostname." not "Invalid input."
- **Processing**: disable the submit button + `<Spinner />`; keep the label ("Save changes").
  `DataTable processing` dims a table during long reloads. Never block the whole page.
- **Deferred props**: skeletons shaped like the content (`DataTableSkeleton`,
  `StatCard loading`), never a centred spinner.
- **Empty states**: say what will appear here and how it gets created. Calm, no exclamation
  marks, no jokes. Client area: "You don't have any domains yet." + "Register a domain".

## 6. Accessibility

- Every control has a visible `<Label htmlFor>`; icon-only buttons need `aria-label`.
- Pass `aria-invalid` and `aria-describedby="{id}-description"` to controls inside `<Field>`.
- Never remove focus rings; use the kit's `focus-visible:ring-[3px] ring-ring/50`.
- Status is never colour-only: badges carry text; trends carry an arrow + sr-only text.
- Text contrast ≥ 4.5:1 — use `text-muted-foreground` for secondary text, never lighter.
- Clickable table rows also expose a real `<Link>` in the primary cell (keyboard + middle-click).
- One `<h1>` per page (PageHeader); SectionCard titles are `<h2>`.

## 7. Responsive

- Mobile-first; test at 375px. Page gutters `px-4` → `md:px-6` (from `<Page>`).
- `DataTable` swaps to stacked cards below `md`: primary column becomes the card title, other
  columns become label/value pairs; mark low-value columns `hideOnMobile`.
- Header actions wrap under the title on mobile; keep ≤ 2 visible buttons, rest in `⋯`.
- Grids collapse to one column: `sm:grid-cols-2`, `lg:grid-cols-3`. No horizontal page scroll.

## 8. Theming & dark mode

- Use tokens only: `bg-background`, `bg-card`, `bg-muted`, `text-foreground`,
  `text-muted-foreground`, `border` (default colour), `ring-ring`, `text-destructive`.
- **No hard-coded neutrals** (`gray-*`, `neutral-*`, `white`, `black`). Hue colours
  (emerald/amber/red/sky) are allowed only in `status-badge`, `money` (ledger), `stat-card`
  (trend) — if you need one elsewhere, extend those components.
- Cards: `rounded-xl border bg-card shadow-xs`. Radius and spacing come from the kit; spacing
  between siblings with `gap-*`, not margins.

## 9. Copywriting

- **Buttons**: verb + noun, sentence case: "Create invoice", "Record payment", "Suspend
  service". Never "OK", "Submit", "Yes". The confirm button repeats the dialog's verb.
- **Destructive copy** names the object and the consequence; say "can't be undone" only when true.
- **Errors**: what happened + what to do. "Payment failed: card declined. Try another card."
  Never expose exceptions, SQL or gateway codes to clients (admin may see the code in details).
- **Titles**: plural nouns for lists ("Invoices"), the identifier for details ("INV-1042"),
  "New client" / "Edit client" for forms.
- Sentence case everywhere; numerals for numbers ("3 invoices"); spell it **cancelled**
  (matches the status values).

## 10. Component reference

| Component                                      | Use for                                              |
| ---------------------------------------------- | ---------------------------------------------------- |
| `Page`                                         | Page container (gutters, max width, vertical rhythm) |
| `PageHeader`                                   | Title, status, meta line, back link, actions         |
| `FilterBar`, `FilterSelect`, `useQueryParams`  | Query-string driven search/filters                   |
| `DataTable`, `DataTableSkeleton`               | Lists, line items, mobile cards                      |
| `Pagination`                                   | Laravel paginator (plain or Resource `meta`)         |
| `StatusBadge`, `statusTone()`                  | Every status                                         |
| `Money`, `formatMoney()`, `toMinor()`          | Every amount                                         |
| `DateTime`, `formatDate()`, `formatRelative()` | Every date                                           |
| `StatCard`                                     | Dashboard/detail metrics                             |
| `SectionCard`, `DescriptionList`               | Detail and form sections                             |
| `Field`, `FormActions`                         | Form fields, form button rows                        |
| `EmptyState`                                   | Empty lists/sections                                 |
| `ConfirmDialog`                                | Any destructive or irreversible action               |
