### Added

- **Card payments through NIC Asia's Cybersource gateway (#492).** A new gateway, "Cards (Cybersource, NIC Asia)",
  sends the customer to Cybersource's hosted card page (Secure Acceptance Hosted Checkout), where the card is typed;
  the masked card number and expiry that Cybersource posts back are never stored or logged. A payment is marked paid only when the Cybersource REST API
  confirms a captured sale with the same amount, currency and reference; the signed browser return and the signed
  notice to the webhook URL only trigger that check; a payment voided, reversed or authorised for less than the sale
  goes to staff review. A payment held by Decision Manager stays open until it is
  decided, and goes to staff review if it stays undecided past the grace period. A cancel on the card page ends the
  attempt so the customer can pay again. Staff can refund in full or in part from the transaction, and "Test
  connection" checks the merchant ID and REST key without moving money. Test mode uses Cybersource's test hosts and
  is refused in production.
- Upgrade: the gateway needs the merchant ID, the Secure Acceptance profile ID, access key and secret key, and a REST
  key ID and shared secret from the Business Center; it can't be activated without the REST key. In the profile, set
  the customer response page to the return URL and the merchant POST URL to the webhook URL shown on the gateway
  form. It takes NPR; set `CYBERSOURCE_CURRENCIES=NPR,USD` in the environment only if the bank agreement allows USD.
  The driver is built and tested against our simulator only; the REST search, refund and HTTP Signature details must
  be re-checked against a real Cybersource evaluation account before going live.
