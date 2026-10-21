# Client identity verification (KYC) — Product Spec (v1)

Status: building · Owner: KYC workstream (module `app/Modules/Kyc`, migration block `77xxxx`) · Issue #197, owner
decisions 2026-10-21 (comment on #197) · Clean-room: our own behaviour and wording.

## 1. Purpose

A host can ask clients to prove who they are after sign-up: confirm the account email, give a phone number, and upload
two identity documents that staff check. Verification never blocks a client: ordering, paying and using services work
the same whether the client is verified or not. The main market is Nepal, so the defaults are the `+977` phone prefix and
the three common Nepali documents.

Not in v1: phone OTP, automated ID checks, blocking orders until verification is done.

## 2. Setting

- `kyc.enabled` (Admin → Settings → Identity verification, permission `kyc.settings.manage`). **Off** on fresh installs
  (owner decision 2026-10-21; the issue's "on by default" is superseded). While off, the banner, the client page and
  its routes are not available (routes answer 404), and the retention job still runs.
- `kyc.phone_country_code`, default `+977`: the prefix added to a number entered without one.

## 3. Steps (client area → Identity verification)

One page lists the steps in order, each marked done or to do:

1. **Email.** Done when the account's email is proved: it was set by staff or by an import, or an owner login on this
   account with that exact address has verified it. Otherwise the step says why and links to Account details.
2. **Phone.** A phone field, normalised to `+<country code> <number>`; a number without a prefix gets
   `kyc.phone_country_code` (a leading trunk `0` is dropped). For `+977`, a mobile number is 10 digits starting with 9
   and a landline 8 or 9 digits. Saved to the client's phone. No OTP.
3. **Identity documents.** At least **two different** types from: Citizenship certificate, National ID (NID), Driving
   licence. Each takes the document number and an image of the front and of the back (all three have a back with
   details on it; the type list keeps a per-type flag in case a later type has none).

Client access: owners, and logins with the `profile` area on the account. Every query is scoped to the current
client account.

## 4. Status

Derived, never stored, so it follows later changes (a changed email undoes the email step):

| Status         | When                                                                                   |
| -------------- | -------------------------------------------------------------------------------------- |
| Not started    | No current document.                                                                   |
| In progress    | Documents of fewer than two types, and nothing rejected.                               |
| Pending review | Current documents of two or more types, at least one waiting for staff, none rejected. |
| Rejected       | A current document was rejected; the client sees the reason and uploads it again.      |
| Verified       | Email and phone done and two or more types approved.                                   |

The banner at the top of the client area shows while the status is not Verified: "Verify your identity to finish
setting up your account" with Start verification; "Your documents are waiting for review" while pending; "A document
needs attention" when rejected.

## 5. Files

- Images only, checked by content (not by name): JPEG, PNG or WebP. SVG, PDF and anything else are refused.
  Maximum 5 MB per image.
- Stored on the private `objects` disk, encrypted with the application key (`Crypt::encryptString`) under a random
  path. The document number is encrypted at rest too. Neither the bytes, the number nor the path ever appear in an
  Inertia prop, a log line or an audit entry; clients see the last 4 characters of the number only.
- A document can be replaced while it waits for staff or after it was rejected; an approved one cannot. The replaced
  row is marked superseded in the same transaction and its files are deleted after the commit; if that fails, the
  retention job deletes them later.

## 6. Retention

Documents are kept while the client account exists, and deleted **90 days after the account is closed** (owner,
2026-10-21; the issue's 30 days is superseded), and when replaced (§5). A reopened account keeps its documents.

The daily automation task `kyc.prune_documents` (housekeeping stage): deletes superseded documents, then documents of
clients closed on or before today minus 90 days. Each document is deleted on its own (file, then row); one failure is
logged without the path and retried the next day. At most 500 documents per run, the rest the next day. It reads
`kyc_documents` through the `client_id` and `superseded_at` indexes and is idempotent.

## 7. Staff review

- New permission `kyc.documents.review` ("View identity documents and approve or reject them"), registered as
  **sensitive**: no wildcard grants it, only the exact key or `*`. Approve and reject require `password.recent`.
- Admin → Clients → Identity verification: a queue of clients with documents waiting, oldest first, then a page per
  client with each document's type, upload time and status. The number and images show only after a password
  step-up (`password.recent`, audited), for 15 minutes.
- Images open only through signed links that expire after **15 minutes**, checked again for the permission when
  opened, streamed with `Cache-Control: private, no-store` and `X-Content-Type-Options: nosniff`.
- Approve, or reject with a reason (required, up to 500 characters). The client sees the reason and uploads again.
  Decisions lock the document row and refuse a document that changed since the page was loaded.
- The audit log records each document view, approval and rejection (type and decision in the properties; never the
  number or the file).
- The admin client profile links to the client's verification page.

## 8. Acceptance criteria

1. MUST: with `kyc.enabled` off (the default), no banner shows and the client routes answer 404.
2. MUST: with it on, a client who is not verified sees the banner on every client-area page; ordering and paying are
   unaffected.
3. MUST: the email step is done only for an address set by staff or an import, or verified by an owner login with that
   address.
4. MUST: a phone number without a prefix is saved as `+977 …` by default, and an invalid number is refused with the
   reason.
5. MUST: an upload whose bytes are not JPEG, PNG or WebP is refused, whatever its name (an SVG renamed `.png` too), and
   so is an image over 5 MB.
6. MUST: stored files and document numbers are encrypted at rest; the client page and logs never contain them.
7. MUST: a client can only read and change their own account's verification (IDOR test).
8. MUST: replacing a pending or rejected document deletes the old files; an approved document cannot be replaced.
9. MUST: the status shows pending review once two different types are uploaded.
10. MUST: the retention task deletes documents 90 days after closure, not before, keeps those of reopened accounts,
    handles each document on its own, caps its work per run and is idempotent.
11. MUST (PR B): only staff with the exact `kyc.documents.review` permission (or `*`) can open the queue, a document
    link or a decision; `kyc.*` does not grant it; decisions need a recent password.
12. MUST (PR B): a document link stops working after 15 minutes and opens only the document it was signed for.
13. MUST (PR B): every view, approval and rejection writes an audit entry without the number or the file.
14. MUST (PR B): a rejection needs a reason, the client sees it, and a decision on a document that was replaced fails.
