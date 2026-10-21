# Process, agents and skills review, 2026-10-21

Owner request (05:40 UTC): review the custom agents, skills and process docs built so far; find what needs
refinement, what is exaggerated, what can be generalised, and whether more agents or skills are needed.

Evidence: the 14 files in `.claude/agents/`, the two skills in `.claude/skills/`, `CLAUDE.md`, the six files in
`docs/handoff/`, the ten reviews in `docs/reviews/`, the subagent metadata for every launch so far
(`~/.claude/projects/-workspaces-whmcs/*/subagents/*.meta.json`, 220 launches), `gh pr list`, `git log origin/develop`.

## 1. What has earned its place

| Practice                                                        | Evidence                                                                                              |
| --------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------- |
| One role file per job, launched with a short brief              | 220 launches: review-board 33, security 25, module-engineer 18, re-reviewer 14, architect 12, product 9 |
| One review comment with a verdict line as the gate              | every merge since 10-19 carries a sign-off naming the items (CTO review round 3, §1)                 |
| Reviewer probes become the engineer's regression tests          | #95, #133, #152, #153: the fix PR adopted the failing probe; re-checks became a diff read plus CI     |
| Combined review plus one re-check for money, auth and migrations | open to merge in 1–2 h with 2–3 review posts, against 18–22 h and 5–9 posts before (round 3, §2)     |
| `product-standards.md` as the owner's correction log            | 40 lines of checks, each dated; the product reviewer treats every line as a hard check               |
| Periodic review round with one fixed rubric                     | the score moved 38 → 46 → 53 on the same weighted table; the 55 reported without the table was wrong |
| Handoff docs plus the `resume-session` skill                    | four restarts on 10-19 and one on 10-21 resumed without losing pushed work                           |

`general-purpose` was used 83 times, mostly research and one-off tasks (WHMCS area research, historian, changelog
backfill, Fable advisor). That is right: a role file pays off when the same brief repeats; a one-off is a brief.

## 2. Findings

### A. Docs that contradict each other or the practice

1. **`qa-design-reviewer` is retired and still everywhere.** `operating-model.md:80` retires it; the roles table
   (line 19), lifecycle step 4 (line 35) and `run-workstream` step 3 still launch it; the file exists. Used once.
2. **The CI sections describe a state that ended on 10-20 evening.** "CI paused: local merge gate" and "CI minutes
   budget" (`operating-model.md:142–168`) say the repository is private and every merge runs `scripts/merge-gate.sh`.
   The repository has been public since 10-20 evening, was renamed `bhimrazy/mohar` on 10-21, CI runs on every PR, and
   `merge-gate.sh` is not on `develop` (it is #188, a draft). `state.md:39` and `CLAUDE.md` still say `bhimrazy/whmcs`,
   private.
3. **Two gate tables disagree.** "Gates by PR type (from the 2026-10-18 Fable review)" sends every migration PR
   through `re-reviewer`; "Review policy (2026-10-20)" above it says one re-check only for money, auth and
   migrations and CTO diff verification for the rest. A reader must guess which wins.
4. **Three different agent budgets.** `operating-model.md:53`: launch while load < 8, 5–7 agents.
   `resume-session` step 5: "four running, two code and two review, load under 6" and "at most two engineers".
   `module-engineer`: size caps 3,000 / 1,500; `operating-model.md:65`: 2,000. The CTO round-3 review already noted
   that a cap overridden daily plans nothing; the files still disagree.
5. **`re-reviewer` runs the full suite; the model says CI is the only full-suite runner** (`operating-model.md:94`).
6. **`agent-log.md` stopped on 10-18 12:25** at 108 of the 220 launches; CTO round 3 recommended retiring it.
   `history.md` ends on 10-18 and points at it. `requirements.md` is "verified against the repository on 2026-10-18":
   the Stripe, deploy, importer and `.np` rows are three days stale.
7. **`devops-engineer` predates `deploy/`.** Its context is `billing/compose.yaml` and `ci.yml`; it does not know
   `deploy/` (D1–D3, blue-green), `deploy-smoke.yml`, the load-test workflow, `tests/Unit/Deploy` (the fake-`docker`
   probe harness the board wrote for #111), or that the repository is public and workflow triggers now matter.

### B. Exaggerated, misapplied or ageing text

1. **"PostgreSQL run: yes/no … otherwise the review is incomplete"** was appended to six definitions, including
   `product-reviewer` (a UI walk) and `qa-design-reviewer`. It means nothing there and makes a product review
   "incomplete" for the wrong reason. It belongs to the code reviewers only.
2. **The combined review exists only in the CTO's head.** The policy says "one combined review (code, security,
   database)". No definition says how: in practice the CTO briefs `review-board` with the security and database
   checklists. A new session would launch three reviewers again.
3. **Dated incidents inside role files**: "the codespace reboots without warning (three times on 2026-10-19)",
   "a missed Arch rule cost #115 a red run", "3.5–5.5 minutes per run on 2026-10-18". The rule matters; the date and
   count age and get copied forward.
4. **`operating-model.md` is a decision log pretending to be a rulebook.** 228 lines with "(owner asked for speed,
   2026-10-20 afternoon)" style headings in three places, superseded sections kept, and the current rule spread over
   "Review policy", "Gates by PR type" and "CI minutes budget". It should state the rules in force; the dates belong in
   `history.md` or a decision table.
5. **Model pins drift.** Ten definitions pin `model: opus`, two pin `fable`; the owner's choice changed twice on 10-21 (Fable, then Opus or Sonnet by task once Fable hit its weekly limit), which is the point: the launch-time `model` parameter overrides the pin, so the pin is only a default that is wrong whenever
   the owner's preference changes. Better: no pin, and the current preference stated once in the operating model.
6. **Descriptions say "Use on every PR that…"** for `database-reviewer`, `security-reviewer` and `principal-architect`,
   while the practice is one combined review. The descriptions are what the CTO sees when choosing; they oversell.

### C. Duplication

Three paragraphs (heavy commands through `heavy.sh` with `XDEBUG_MODE=off`; the PostgreSQL verdict line; probes
pushed to `review/pr-<n>-probes`) are repeated across five to six files and were applied unevenly: `product-reviewer`
got the PostgreSQL line, `database-reviewer` did not get the probes paragraph. One shared rules file, read by every
reviewer, removes the drift.

### D. Gaps

1. **Engineer-side checks for the defect classes reviewers keep finding** (CTO round 3, §3 and recommendation 4):
   confirm-then-apply without a digest-bound snapshot re-read under lock (#95, #133, #152, #153); unbounded work on
   scheduled paths (#152, #95); email vouched by weak proof (#69, #95, #133, #153). They are in the review, not in
   `module-engineer` or the brief template, so each engineer re-derives them.
2. **Public-repository workflow safety has no owner.** `pull_request_target`, label-triggered workflows, secrets in
   workflows and fork PRs became a concern on 10-20 evening. Neither `devops-engineer` nor `security-reviewer` names it.
3. **The periodic review round is run from memory.** The owner triggers it by hand; the steps (four advisors, merge the
   docs PR, issues, owner summary) are in `operating-model.md` prose. The CEO rubric that produces the owner's one
   number lives only inside the review files, which is how 55 was reported against a table summing to 38.
4. **Local practice is assumed, not asked.** The `.np` staff workflow (#168, #185, about 2,600 lines) was built and
   reviewed before the owner said clients register `.np` themselves. Neither `product-manager` nor the brief template
   says "state the assumed local workflow to the owner in one line before building".
5. **Host-facing docs (`docs-site/`) have no owner role.** Pages are written by whoever is free. That is acceptable
   while there are five pages; it becomes a technical-writer brief, not a new agent, when the site grows.

## 3. Generalisation

- The reviewer definitions (`review-board`, `re-reviewer`, `slop-reviewer`, `security-reviewer`) are already general:
  swap the "Standards" paragraph and they review any Laravel repository. The engineers and advisors are Mohar-specific
  by design. Making them portable is not a goal now.
- The shared rules (heavy commands, read-only conduct, verdict format, probes, PostgreSQL line) are generalised in this
  PR into `docs/handoff/review-rules.md`; each reviewer definition keeps only its own checklist.
- Repeated `general-purpose` briefs that could become a mode of an existing role: "research a WHMCS area and report"
  (10+ launches) is `product-manager` with a research-only brief; "architecture review" ran 7 times as
  `general-purpose` before the role existed. No new file needed; the brief names the mode.

## 4. Do we need more agents or skills?

No new agents. Fourteen roles for a 4-vCPU machine that runs five to seven at a time is enough; a new role is justified
when the same brief has repeated three times, and none has beyond the ones above.

One new skill, `review-round`, added in this PR: the periodic round as steps and briefs, with the CEO rubric pinned in
`docs/reviews/readme.md` so every score is the same weighted sum. `resume-session` and `run-workstream` stay; both are
corrected below.

## 5. Changes made in this PR

- Removed `.claude/agents/qa-design-reviewer.md` and `docs/handoff/agent-log.md`; `history.md` now says where the
  launch counts come from.
- `docs/handoff/review-rules.md`: the shared reviewer rules (conduct, heavy commands, verdict formats, probes,
  PostgreSQL line, combined-review mode). Each reviewer definition points at it and keeps its own checklist.
- Agent definitions: no `model:` pins (the operating model states the current owner preference);
  descriptions say when the role is used under the current policy; dated incidents replaced by the rule;
  `module-engineer` gets the three engineer-side checks and the 2,000-line cap; `review-board` gets the combined
  mode; `re-reviewer` runs targeted tests and lets CI run the suite; `devops-engineer` gets `deploy/`, the deploy
  probes and public-repository workflow safety; `product-manager` gets the local-practice question; `product-reviewer`
  loses the PostgreSQL line; `ceo-advisor` uses the pinned rubric.
- `operating-model.md` rewritten as the rules in force: one roles table, one lifecycle, one review policy table, one
  "Repository visibility and CI" section (public: CI on PRs; private: local gate once #188 merges), one agent budget,
  machine limits, periodic reviews, restarts. Dated decisions moved to a short decision table at the end.
- `run-workstream`: round-1 list matches the policy; the brief template carries the engineer-side checks, the size cap
  and the local-practice question. `resume-session`: one agent budget, model note, no retired roles.
- `CLAUDE.md` and `state.md`: repository `bhimrazy/mohar`, public; `state.md` refreshed to 10-21 (merges since 10-20
  16:00, open PRs, running agents, queue).

## 6. The `rea/` checkout

`rea/` is a git-ignored clone of morluto/rea (MIT, "Reverse Engineer Anything", package `rea-agents` 6.7.0, 173 MB),
installed on 2026-10-17 with `rea-agents setup --client claude_code`. It provides the `mcp__rea__*` MCP tools and
the global skill `~/.claude/skills/reverse-engineer-anything`. Nothing in the build uses it: no agent definition,
skill, script or doc references it beyond `history.md` and the local-only list in `state.md`.

Should we use it? Its main temptation, WHMCS's encrypted source, is out of bounds (`CLAUDE.md`). The permitted uses
are small: capturing the WHMCS public demo for parity research (`capture_web_screenshot`, `inspect_web_page`), and
inspecting our own built bundle or SQLite demo database. Playwright already covers the first in the product-manager
and product-reviewer briefs. Recommendation: keep it installed per user, out of the build, with the one-line boundary
now in `CLAUDE.md`.

Copying its skill into this repository: not recommended. The skill is generated by `rea-agents setup` and matches
the installed MCP server's tool catalog; a copy in `.claude/skills/` would drift with every REA release and does
nothing on a device without the server. The install itself (`npx rea-agents`) is also outside our pinned-dependency
rule and was approved after the fact on 10-18; a repo copy would make it look like part of the toolchain.

What its own process docs teach us (`rea/AGENTS.md`, `CONTRIBUTING.md`, `docs/tool-design.md`), folded into
`review-rules.md`, `module-engineer` and `CLAUDE.md`:

- distinguish observed, derived and inferred facts, and say explicitly what was not verified ("report only checks
  that actually ran");
- fix the producing boundary before adding downstream repairs, and remove the superseded code in the same change;
- a human owns the final diff and declares readiness; CI and automated review results do not establish review.
  Ours: the CTO sign-off names each blocking item and its evidence.
- design agent interfaces for token cost first: the smallest sufficient answer with provenance. Ours: reply limits
  on every role, verdict lines as the gate.

## 7. `AGENTS.md` and `.agents/` (owner question, verified against the Claude Code docs on 2026-10-21)

- `AGENTS.md` is read natively since Claude Code 2.1.277, but only as a fallback: "By default, Claude reads
  `AGENTS.md` only when you have no `CLAUDE.md` in your working directory or above it" (code.claude.com, Memory).
  A setting (`claude-md-and-agents-md`) makes both load. `InstructionsLoaded` hooks do not fire for it.
- `.agents/` is not read at all: the same page lists "anything under a `.agents/` directory" as not read; subagents
  load only from `.claude/agents/` and `~/.claude/agents/`; skills only from `.claude/skills/`, `~/.claude/skills/`
  and plugins. Skills follow the Agent Skills open standard as a format, not the `.agents/skills/` path. The
  `rea/.agents/skills/` folder is not in this session's skill list, which confirms it.
- Consequence: moving `.claude/agents` or `.claude/skills` to `.agents/` would silently disable every role and skill;
  renaming `CLAUDE.md` to `AGENTS.md` would work only after deleting `CLAUDE.md`, and `billing/CLAUDE.md` (Boost) would
  then shadow it for work under `billing/`. Keep `.claude/` and `CLAUDE.md`. If another tool ever needs it, a root
  `AGENTS.md` containing `@CLAUDE.md` is enough; not added now.

## 8. Recommendations not applied here (decisions)

1. **`requirements.md`**: one historian pass (about an hour) to re-verify the status column against `develop`, or
   drop the column and keep the requirement and the date only. Recommended: drop the column; `state.md` and the
   changelog carry status.
2. **`history.md`**: stop extending it. The dated reviews, `state.md` and `billing/changelog.md` record what happened
   since 10-18 in more detail than the timeline did.
3. **#188 merge gate**: merge it when the repository goes private again, or close it. The operating model now
   describes both visibility states, so the script is useful but not required while public.
4. **Tool restrictions for reviewers** (`tools:` in the frontmatter): low value, since Bash can write files; the
   read-only rule stays an instruction, verified by the `git status` clean check in `review-rules.md`.
5. **Owner's standing items** are unchanged from round 3: repository settings (auto-merge, delete branches, required
   check), branch cleanup, the Bikram Sambat and CBMS tax answers, connectIPS enrolment, a WHMCS licence for importer
   fixtures.
