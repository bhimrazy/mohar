# Session history

Factual record of the first build session (2026-10-17 17:44 to 2026-10-18 04:38 UTC), reconstructed from the session
transcript, `git log origin/develop`, GitHub PRs and issues, and review reports. Times are UTC. Commit author dates in
git use mixed time zones, so they are not used for ordering here.

## Timeline

### 2026-10-17

**Setup (17:44–17:58)**

- Checked the REA reverse-engineering toolkit; ran `rea-agents setup --client claude_code` after a dry run (MCP entry
  plus skill). No native analysis engine was installed.
- The owner asked to reverse engineer WHMCS to replicate it. Decoding the ionCube source and licence bypass were
  declined; a clean-room build in Laravel was agreed (see `requirements.md`, Scope).

**Foundation (17:58–18:43)**

- `composer create-project` produced an old starter kit, so the app was created with the Laravel installer
  (`laravel new` with React, Pest and Boost). Boost was half installed (no `agents` key in `boost.json`, no `CLAUDE.md`); a research agent found this and
  it was fixed.
- First commit 25a9e5d: Core module, shared kernel (contracts, DTOs, events, money in minor units, registries), RustFS
  storage, ADRs 0001–0005, worktree bootstrap script.
- Two PM agents wrote 10 module specs with acceptance criteria. Their review of the kernel found two bugs before any
  module used it: due-date drift after short months and a double-invoice guard that ignored the billing period (fixed
  in 61b8d25).
- A senior designer delivered the design system doc and 15 shared components.

**Wave 1 launch and process (18:38–19:30)**

- Seven senior engineers (Billing, Catalog, Services, Orders, Domains, Support, Platform admin) and a DevOps engineer
  started in parallel worktrees.
- 18:51: PR workflow adopted (template, reviews posted as PR comments, CTO merges with `gh pr merge --merge`). GitHub
  does not allow self-approval from the single account, so verdicts are comment reviews.
- 18:54: DevOps' PostgreSQL portability rules were broadcast to all engineers (ADR 0006); Postgres became a required
  gate. DevOps also found that client search was case-sensitive on Postgres.
- PR #1 (DevOps: Postgres parity, CI) passed staff and QA review; the AI-slop review found four inaccurate claims (for
  example `composer ci` described as running every CI gate while skipping the Postgres seed). Merged 19:27.
- 19:12: load reached about 13 on 2 CPUs (agents, Pint, headless Chromium). `scripts/heavy.sh` and
  `scripts/resource-monitor.sh` were added and every agent was told to route heavy commands through the limiter.
- 19:15: `billing/docs/product-vision.md` written (parity checklist plus differentiators).

**Specs for later waves (19:07–19:50)**

- AI module spec (`ai.md`, `laravel/ai` pinned 1.0.1), WHMCS migration spec (`migration.md`, API-only importer,
  `import:whmcs`), Nepal payments research (`payments-nepal.md`: no gateway sends server webhooks, so payments settle
  only through an authoritative lookup plus a reconciliation poller; order Khalti, eSewa, ConnectIPS).
- The gateway driver interface moved into the shared kernel so gateways can ship as extension modules.

**Dependency policy (19:27–20:43)**

- PR #9: exact pins, at least 7-day release cooldown, single-job CI. Enforcing the cooldown moved 94 locked packages
  back (Laravel 13.37 to 13.34). CI dropped from about 5 to about 2 billed minutes. The review board showed the age
  checker could be bypassed (image digests not checked, npm `resolved` URL not bound to name and version); fixed.
  Merged 20:43. Issue #11 tracks the framework bump on or after 2026-10-22.

**Wave 1 reviews and contracts v2 (19:36–21:45)**

- Module PRs opened: #3 Platform, #4 Catalog, #5 Domains (19:36), #6 Support (19:52), #7 Services and #8 Orders
  (19:57), #10 Billing (20:25).
- Reviews showed every engineer had hit the edges of the shared interfaces. ADR 0008 (contracts v2) settled them
  centrally, backward compatible (null defaults, optional trailing DTO fields). Module events needed by other modules
  were promoted to `app/Events/<Area>`.
- QA on Catalog found the shared money-input parser silently accepted bad input (`abc` saved as a free price, `12,50`
  as 100x). It was fixed once in `resources/js/lib/money.ts` with the project's first Vitest tests.
- Merged: #5 Domains 21:14, #4 Catalog 21:29, #3 Platform 21:40. Registrar research produced `registrars.md` (21:45).

**Design, themes and brand (19:48–21:21)**

- The owner found the first design dull. A principal designer researched competitors and SaaS dashboards and built
  four switchable directions (Calm Fintech, Operator Pro, Hosting Warm, Enterprise Slate), published as a private
  artifact at 20:38.
- 20:40: owner approved Calm Fintech as default and asked for themes as a selectable, customisable module. The same
  designer built the Themes module (PR #17).
- A brand designer produced two naming rounds with logos. 21:21: owner chose **Mohar**, configurable.

### 2026-10-18

- 02:19: session restart; five agents resumed. Integration screenshots of `develop` added to
  `billing/docs/screenshots/integration/`.
- Merged: #6 Support 02:38, #10 Billing 03:01, #7 Services 03:20, #17 Themes 03:33, #8 Orders 04:29. Wave 1 complete
  with 1464 tests passing locally and CI green.
- Fixes made centrally on `develop` during integration: stock released only on cancellation (closes issue #19),
  lowercase emails on every write, Billing staff role lists sensitive permissions explicitly, objects disk root no
  longer used as an S3 key prefix, `phpunit` memory limit 1G, brittle position-based dashboard tests rewritten to look
  up by key.
- 03:07: owner asked where the charts were. They were on the Themes branch and Billing registers the income chart, so
  they only appeared once both merged; a combined preview was built and the dashboard layout reworked.
- 03:24: disk down to 2.4 GB free (each worktree carries about 1 GB of `vendor` and `node_modules`); cleaned to
  9.0 GB. Merged worktrees were removed later.
- PR #22 (Billing follow-ups: quote conversion rounding issue #20, 90 days of demo payment history) opened 03:27;
  review in progress at the end of the session.
- 04:31: wave 2 started, two engineers at a time: Notifications and Nepal payments (Khalti first). Both were stopped
  by a restart at 04:33 before committing anything and were resumed at 04:34 with an instruction to commit and push
  after each milestone.
- 04:36: owner asked for this handoff package.

Progress estimates given to the owner: 35/100 (19:05), 45 (20:13), 48 (20:31), about 68 (03:17).

## Wave 1 pull requests

| PR  | Merged (UTC) | Delivered                                                           | Significant review catches                                                                                                                                                                                                                                                                                                            | Rounds                            |
| --- | ------------ | ------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------- |
| #1  | 10-17 19:27  | CI on SQLite and Postgres 18.6, compose, portability rules          | Inaccurate claims in docs and CI comments (slop review)                                                                                                                                                                                                                                                                               | 1 fix round                       |
| #9  | 10-17 20:43  | Dependency pinning, cooldown, single-job CI                         | Age checker bypassable (digests, tarball URLs); Vitest missing from CI                                                                                                                                                                                                                                                                | 1 fix round                       |
| #5  | 10-17 21:14  | Domains: TLD pricing, lifecycle, registrar abstraction, automation  | Free-domain renewal crashed the renewals run; double registration under concurrency; unknown errors retried; renewal batches skipped domains over 200; repeated search returned 500 (cache serialization hidden by the array cache in tests)                                                                                          | review, verify                    |
| #4  | 10-17 21:29  | Catalog: products, pricing, options, addons, PriceBook              | Price grid saved bad input as wrong prices; stock leak on fraud event order; product save overwrote reserved stock; `quote()` threw where the contract promises null                                                                                                                                                                  | review, re-review                 |
| #3  | 10-17 21:40  | Platform: staff, roles, currencies, settings, search, impersonation | Wildcard role escalation (Billing staff could impersonate any client); 2FA kill-switch behind a general settings permission; client merge re-pointed other modules' rows; settings that had no effect; zero-decimal currencies 100x off; re-review found a client-login takeover path                                                 | review, re-review, final fix      |
| #6  | 10-18 02:38  | Support: helpdesk, inbound email, KB, announcements, status         | Deleted internal note attachments became downloadable; guest ticket merge handed over another person's ticket; spoofed inbound replies; the first fix trusted attacker-written authentication headers                                                                                                                                 | review, 2 re-reviews              |
| #10 | 10-18 03:01  | Billing: invoices, payments, GST/VAT, gateways, automation          | Sandbox card gateway active in production (anyone could mark an invoice paid with a test card); combined payments could charge twice; payment guard skipped on most paths; cancelling an invoice destroyed applied credit; FX refunds in the wrong currency; auto-capture charged bank-transfer invoices; card data stored in session | review + security + QA, re-review |
| #7  | 10-18 03:20  | Services: lifecycle, provisioning drivers, renewals                 | Downgrade/upgrade loop minted credit; Postgres-only JSON bug with CI red while the PR checklist was ticked; `create` could re-run after an unknown outcome; replay not idempotent; credential reveal not audited                                                                                                                      | review, 2 re-reviews              |
| #17 | 10-18 03:33  | Themes module, Mohar brand, dashboard UI                            | Logo route set session cookies and wrote a session row per hit; Appearance wrote Core's company name without Core's permission; no audit of theme changes. CSS-injection probes all blocked                                                                                                                                           | review, follow-up                 |
| #8  | 10-18 04:29  | Orders: storefront, cart, promotions, checkout                      | Account contacts with support-only access could order and spend credit; fraud invoices stayed payable; accept race could provision twice; promo-code oracle; checkout 429 from a shared rate limiter; re-review found double provisioning after fraud then pending                                                                    | review, 2 re-reviews              |

Open follow-up issues: #11–#16, #18, #20, #21. Closed: #2 (tinypool advisory, fixed in PR #1), #19.

## Session restarts and recovery

| Time (UTC)  | Agents stopped                                                                                     | Recovery                                                                                                                                                                                                                                                                              |
| ----------- | -------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 10-17 21:00 | Billing, Catalog, Platform engineers; principal designer (mid `git merge develop`); brand designer | Audited each worktree (local commits, modified files, `MERGE_HEAD`) and resumed each agent with `SendMessage` stating its exact state. The design-directions page source in `/tmp` was lost and regenerated under `.worktrees/_review/`. The machine came back with 4 CPUs and 15 GB. |
| 10-18 02:19 | Services, Orders engineers; Support re-reviewer                                                    | Same audit and resume; nothing lost.                                                                                                                                                                                                                                                  |
| 10-18 02:47 | Themes QA reviewer; Services and Billing re-reviewers                                              | Resumed from saved transcripts.                                                                                                                                                                                                                                                       |
| 10-18 03:39 | (context compaction, no agent loss)                                                                | Continued from the compaction summary.                                                                                                                                                                                                                                                |
| 10-18 04:33 | Notifications and Nepal payments engineers (no code yet)                                           | Resumed; told to commit and push after each milestone. An exit 137 at 04:33 was attributed to the session ending, not memory (12 of 15 GB free).                                                                                                                                      |

## Agent roster and how it performed

All 52 subagents ran on `claude-opus-5-5` (checked in each subagent transcript); review agents set `model: opus`
explicitly, the others inherited the session model. 51 were spawned during the build plus this historian; about 83
follow-up messages were sent to running agents.

| Role                                                                          | Spawns | Evaluation (evidence)                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     |
| ----------------------------------------------------------------------------- | ------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Product managers                                                              | 2      | Good. Specs with MUST/SHOULD tags and Given/When/Then criteria became the yardstick for every review. Found two kernel bugs before use. No significant failures recorded.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 |
| Architects and researchers (Boost, AI, migration, Nepal payments, registrars) | 5      | Good. Worked from official docs and marked what they could not verify (Fonepay and IME Pay, registrar "verify" items). Specs ran over the length asked (about 400 lines vs 250–350). The AI architect's claim about Claude 5.5 parameter constraints is still unverified.                                                                                                                                                                                                                                                                                                                                                                                                                                                 |
| Senior product designer                                                       | 1      | Delivered components and an honest note that nothing had been rendered. The owner found the look basic, which led to the principal designer.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              |
| Principal product designer (directions, then Themes module)                   | 1      | Strong. Market research, four AA-checked directions, owner approved the recommendation. Resolved a large merge with Platform (single brand source, CSP nonce, route clash). Review found brand-handling and audit gaps, no security holes. Interrupted twice mid-merge.                                                                                                                                                                                                                                                                                                                                                                                                                                                   |
| Brand designer                                                                | 1      | Useful. Checked name collisions and npm/domain availability; round 2 produced Mohar. Availability checks are informal ("looks free"); no trademark search.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                |
| Senior engineers, wave 1                                                      | 7      | Fast and broad (128–170 tests per module in 45–105 minutes), and fix rounds were thorough. Recurring failures, seen on most PRs: PR descriptions claiming behaviour the code lacked (#3 said `ClientMerged` was dispatched and listed spec MUSTs as SHOULD; #6 said events had moved; #7 ticked quality gates while Postgres CI was red); test fakes that did not follow their contracts and hid bugs (#5, #7); tests on SQLite and the array cache missing production behaviour; missing locks and idempotency on money and provisioning paths in every money module; demo seeds out of step with decisions (EUR/GBP, nonexistent drivers). Several fixes introduced new bugs found only by re-review (#6, #7, #8, #10). |
| Senior engineers, wave 2                                                      | 2      | No output yet; evidence too thin to evaluate.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                             |
| DevOps engineer                                                               | 1      | Good. PR #1 had no blocking defects in staff or QA review and found a real Postgres bug; PR #9 met the cost goal. Weak point: claims of checks that did not fully check (`composer ci`, the age checker).                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 |
| Staff reviewer, QA reviewer, AI-slop reviewer (PR #1 only)                    | 3      | Accurate on a small PR. The standalone slop role was then folded into the review board, where slop findings continued (unused settings, dead code, ticked boxes).                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                         |
| Code review board (staff + security + slop)                                   | 10     | Highest value. Reproduced findings with throwaway probe tests and deleted them; caught most of the money and authorization bugs in the table above and drove ADR 0008. About 8–12 minutes per PR.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                         |
| Security reviewer                                                             | 2      | High value on Platform and Billing: 14 reproduced probes on #3, the sandbox-in-production and card-data findings on #10, and the standards that became `security/standards.md`.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                           |
| QA and design reviewer                                                        | 8      | Found runtime bugs code review missed (search 500, price grid NaN, auto-capture on bank transfer, checkout 429, 390 px overflow, receipt double discount, GST invoice gaps) and produced the dashboard and sidebar proposal. Slowest role (up to 28 minutes per PR); some "fail" verdicts rested on minor layout issues.                                                                                                                                                                                                                                                                                                                                                                                                  |
| Re-reviewer and verifier                                                      | 7      | Essential. Re-ran original probes, including real two-process Postgres races, and caught regressions from fixes and from CTO merges (duplicate `toneSurface` export).                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     |

**Briefs should keep:** read-only reviewers with throwaway probes and an explicit verdict posted on the PR; one
consolidated fix list per PR from all reviewers; a re-review before merge for anything touching money or security;
private Postgres containers per reviewer; heavy commands through `heavy.sh`.

**Briefs should change:** require the PR description to map each acceptance criterion to a test and to state only what
the code does; contract tests that fakes must pass; run the suite on Postgres and a production-like cache store before
opening a PR; a concurrency and idempotency checklist for money and provisioning paths; seeds that use current
decisions (USD/INR/NPR, existing drivers and gateways); merge `develop` early; commit and push after each milestone;
keep outputs outside `/tmp`.

## Lessons and gotchas

- `/tmp` (including the session scratchpad) is wiped on restart. Keep generators and review material in
  `.worktrees/_review/` (git-ignored, persistent).
- `pkill -f <pattern>` matched its own shell and killed it (exit 144). Kill servers by the PID listening on the port.
- CPU: about 10 agents plus headless browsers saturated 2 CPUs. Use `scripts/heavy.sh` (3 slots after the move to 4
  CPUs), run reviews in waves, and keep at most two engineers building at once.
- Disk: each worktree costs about 1 GB; remove worktrees once their PR merges.
- After merging `develop` into a branch, run `npm ci` in that worktree; stale `node_modules` caused type errors
  (missing `recharts`).
- Merge leftovers broke builds twice (duplicate export in `status-badge.tsx`; a changelog conflict left a merge
  uncommitted). Run build and lint after every merge.
- CI: Vitest under `laravel-vite-plugin` needs `LARAVEL_BYPASS_ENV_CHECK=1`; draft PRs skip CI, so push after marking
  ready; one unformatted docs commit turned `develop` red; many small pushes to `develop` wasted CI minutes, so batch.
- Laravel Boost needs `"agents": ["claude_code"]` in `boost.json` and an existing `CLAUDE.md`.
- The full suite needs `memory_limit=1G` (issue #21 tracks the growth).
- Generating PHP class names in a shell loop doubled backslashes; use Python for code generation.
- Branch protection cannot be read or set with the available token (403); required checks are unverified.
- The 7-day cooldown can hold back upstream security fixes; track each held-back fix as an issue (#11).
