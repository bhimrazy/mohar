### Security

- **`symfony/mime` 8.1.10 and Node 24.22.1 (CVE-2026-45070 and the Node security release).** `symfony/mime` fixes an email header injection through non-token characters in MIME parameter names. Node 24.22.1 is used in the deploy image build stage and the dev target, `.nvmrc` and the docs. Both were adopted before the 7-day cooldown with the owner's approval (ADR 0007). Operators rebuild the image to pick them up.
