---
name: review-round
description: Run the periodic Mohar review round — CEO, CTO, security and architecture advisors in parallel, read-only — then merge the review files, turn findings into issues or briefs, and report the score to the owner. Use when the owner asks for a review round or the last round is older than 12 hours.
---

# Review round

Rules: `docs/handoff/operating-model.md`, "Periodic reviews". Rubric: `docs/reviews/readme.md`.

1. **Check the budget.** Four read-only agents count against the agent budget (load below 8, 2 GB free, disk below
   85%). Skip a kind when nothing in its scope changed since its last file in `docs/reviews/`.

2. **Launch in parallel, in the background**, each with the model the owner currently prefers (operating model,
   "Agent budget"):

   - `ceo-advisor`: the date, the previous CEO file, the merges since it (`git log origin/develop --merges --since=…`),
     open PRs and owner decisions since. It fills the rubric table and writes `docs/reviews/<date>-ceo.md`.
   - `cto-advisor`: the same window; the previous CTO file and its recommendations to score.
   - `security-reviewer`: whole-`develop` scope (auth, permissions, money, secrets, outbound calls, webhooks, mail,
     and workflow safety while the repository is public); writes `docs/reviews/<date>-security.md`.
   - `principal-architect`: kernel surface, module boundaries, ADR drift, scalability; writes
     `docs/reviews/<date>-architecture.md`, ADRs as Proposed.

   Each writes on a branch `review/<date>-<kind>`, commits and pushes.

3. **Merge.** One docs PR from the four branches into `develop` (merge the branches into one, or one PR each if they
   conflict). Docs-only: the CI fast path runs only `vp check`.

4. **Act.** Blocking security items become fix workstreams now; other findings become issues (`gh issue create`,
   citing the review file and line) or lines in the queue in `state.md`. Owner questions go into `state.md`, "Open
   owner questions". Owner corrections that the reviews surfaced go into `product-standards.md`.

5. **Report to the owner** in under ten lines: the score with its movement and the two or three areas that moved it,
   blocking security items, the decisions needed from the owner. No score that is not the rubric sum.
