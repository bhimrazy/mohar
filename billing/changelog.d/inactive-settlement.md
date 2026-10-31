### Fixed

- **Deactivating a gateway or registrar no longer strands work already in flight (#571).** A payment the client
  started before staff deactivated its gateway now settles from the provider's webhook, and the client's return lands
  on the paid invoice. A domain registration, transfer or renewal sent before its registrar account was disabled is
  still reconciled, and staff and the hourly sync can still sync that registrar's domains. Deactivating still stops
  new work: the gateway leaves checkout and can't start a payment, and the registrar takes no new register,
  transfer, renew or management command. Upgrade note: an inactive gateway now accepts webhooks and returns while it
  has a payment attempt or transaction on record, each verified with its stored credentials. One never used, never
  saved, or whose driver is no longer installed is still refused with 404.
