# Review rules

Shared rules for every reviewer role in `.claude/agents/` (`review-board`, `security-reviewer`, `database-reviewer`,
`re-reviewer`, `product-reviewer`, `principal-architect` on a PR, `slop-reviewer`). Each role's file holds its own
checklist; this file holds what they have in common. The brief gives the PR number, branch, worktree and focus.

## Conduct

- Read-only on the branch under review: do not modify, commit or push its code. The exceptions are a product sweep or
  database audit whose brief names a fix branch; those fixes go only to that branch. Probes and review bodies live in
  a scratch directory; `git status` in the worktree is clean when you finish.
- Never run commands in the main checkout `/workspaces/whmcs/billing` and never touch port 8000 (the owner's
  preview). Chain every `cd` with `&&`.
- Heavy commands (`npm ci`, builds, phpstan, test suites) go through `/workspaces/whmcs/scripts/heavy.sh` with
  `export XDEBUG_MODE=off`. Run targeted tests only; CI runs the full suite on SQLite and PostgreSQL.
- PostgreSQL: use the shared server with your own database, `eval "$(/workspaces/whmcs/scripts/pg.sh <slug>)"`, then
  the tests you need. Don't start private containers. Drop the database when done.
- Dev servers on the port range in your brief; stop them by port PID (`ss -ltnp | grep :<port>`), never `pkill -f`.

## Verdict

Post one review with `gh pr review <n> --comment --body-file <file>` (all agents share one GitHub account, so
approval is not possible; the verdict line is the gate). The heading names the role and the verdict, for example
`## Review board — Ready to merge` or `## Security review — Changes requested`. Then:

- **Blocking**: file:line, impact, evidence (a failing probe, an EXPLAIN, a screenshot), fix.
- **Medium / non-blocking**: same shape; these become issues, not new rounds, unless they touch money or auth.
- **Verified good**: what you checked and found sound, so the CTO can tell coverage from silence.

Code reviewers (`review-board`, `security-reviewer`, `database-reviewer`, `re-reviewer`) add one line
`PostgreSQL run: yes/no` with what ran on PostgreSQL. "No" names the reason; the review then counts as incomplete for
PRs that change queries or migrations. Product and design reviews do not carry this line.

Reply to the CTO in under 250 words: verdict and the blocking list.

## Probes become tests

A probe that reproduces a finding is pushed as a Pest test file to a branch `review/pr-<n>-probes` (based on the PR
head, tests only) and named in the review. The engineer adopts it in the fix push; the re-check is then a diff read
plus CI. Adopted probes do not count toward the PR size cap.

## Combined review

When the brief says "combined", `review-board` applies the `security-reviewer` and `database-reviewer` checklists in
the same pass and posts one review with all three sections. The CTO uses it for money, auth, permission and migration
PRs under the current policy (`operating-model.md`, "Review policy").

## Standards to review against

`billing/docs/architecture.md` (incl. PostgreSQL portability), the ADRs in `billing/docs/adr/`, the module spec in
`billing/docs/product/`, `billing/docs/security/standards.md`, `docs/handoff/product-standards.md`, and for
integrations `billing/docs/testing/integration-test-matrix.md`.

Recurring defect classes (found four days running; check them first):

- confirm-then-apply flows that act on a snapshot without a digest and a re-read under lock;
- scheduled or automation queries with no named index and no cap on work per run;
- an email address treated as proof of ownership when it was not set by a verified owner login, staff, or an import;
- deploy scripts changed without the `tests/Unit/Deploy` probes;
- PostgreSQL-only failures (column lengths, time-zone displacement) in code tested only on SQLite;
- a driver changed without its simulator and `tools/simulators/tests` following it.
