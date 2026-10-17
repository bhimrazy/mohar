# ADR 0005 — Pest feature-first testing and mandatory quality gates

- Status: Accepted · 2026-10-17

## Decision
Pest (as recommended by the Laravel docs and shipped by the starter kit) with feature tests as the default,
unit tests for pure calculations. Every branch passes Pint, Larastan level 7 (no baseline/ignores), the full
test suite, TypeScript check, `vp check` and a production build before review. Review pipeline: staff
engineer review → QA → AI-slop review → CTO merge.

## Consequences
Slower individual merges, far fewer regressions on the money path.
