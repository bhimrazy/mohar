## Summary
<!-- What this PR delivers and why. Link the spec: billing/docs/product/<module>.md -->

## Scope vs spec
- MUST:
- SHOULD:
- Deviations (and why):

## Contracts & events
<!-- Contracts implemented/consumed, events dispatched/listened. Any shared-kernel change needs CTO sign-off. -->

## Screenshots
<!-- billing/docs/screenshots/<module>/*.png -->

## Quality gates
- [ ] `vendor/bin/pint --parallel --test`
- [ ] `vendor/bin/phpstan analyse` (Larastan L7, no ignores/baseline)
- [ ] `php artisan test` (full suite)
- [ ] `npx tsc --noEmit` · `npx vp check` · `npm run build`

## Review gates
- [ ] Staff engineer review
- [ ] QA
- [ ] AI-slop review
- [ ] CTO merge
