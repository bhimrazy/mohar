### Added

- **connectIPS payments (#491).** Clients can pay NPR invoices from any Nepali bank account through connectIPS (NCHL).
  Staff set the merchant ID, app ID, app name and app password NCHL issues, and upload NCHL's .pfx signing
  certificate with its passphrase; register the return address the gateway form shows with NCHL for both success and
  failure. A payment is marked paid only after connectIPS's status check (validatetxn) confirms it for the stored
  amount and its details (gettxndetail) confirm the credit to the merchant. If the details are unavailable after a
  confirmed payment, the invoice stays locked against a second payment, the poller books it once they answer, and
  staff see it for review after the grace period; a failed or unconfirmed credit goes to staff review. NCHL's fee is recorded when the merchant bears it.
  The gateway form refuses a merchant ID, app ID or app name in a format NCHL would refuse. "Test connection" checks the app ID, password and key without moving money. Sandbox mode uses NCHL's
  UAT and is unavailable in production. Not yet run against NCHL: the hosts (`uat.connectips.com`,
  `login.connectips.com`) and the token format are confirmed at onboarding and in UAT.
- **Gateway-specific refund advice (#491).** A gateway without a refund API can name how to refund; for connectIPS the
  refund dialog and the gateway form say to refund by bank transfer and then record the refund.

### Changed

- **Hosted payment attempts keep the form the customer posted (#491).** For providers paid through a posted form
  (eSewa, connectIPS, Cybersource) the attempt keeps the form's fields, signatures and tokens redacted, through later
  status checks, so staff can match it with the provider's records (for example connectIPS's `TXNDATE`).
