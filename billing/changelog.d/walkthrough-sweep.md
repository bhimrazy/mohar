### Changed

- **The admin Setup menu runs from configuration to logs.** Settings, Appearance and Website come first, then staff,
  the catalogue, money (currencies, tax rules, Nepal VAT, gateways) and email; Automation, Email log and Gateway log
  close the group.
- **The demo data reads as one Nepali host.** Seeded services use the catalogue's product names (Starter, Pro, Business,
  VPS S), the demo admin is Anil Thapa, the shared servers sit in Kathmandu and the second data centre is Singapore,
  the home page lists eSewa and Khalti among the ways to pay, and the seeded automation history ends with a scheduler
  tick, so health reads "stopped" instead of "never ran".

### Fixed

- **Service pages name the payment gateway.** The admin service page showed a gateway key such as `bank_transfer`, and
  the edit form asked staff to type one; both now use the gateway's name, picked from the enabled gateways.
- **The client area shows the host's brand only.** Its sidebar showed the product's own mark beside the host's name
  when the host had not uploaded one; it now shows the host's mark or the host's initial.
- **Checkout shows the eSewa and Khalti logos** next to their payment choices, as the rest of the store does.
- **The order summary adds up.** With a client-group discount it listed the discounted price above the discount line;
  it now lists the plan's price, then the discount, then the total.
- **Store plan cards keep "Save 20%" and prices on one line,** and the admin invoice's transactions keep the amount in
  view.
- **Seeded hosting plans point at welcome emails that exist** (the product page said "template not found").
- **Automation says "company time"**, which is the time zone its daily run uses, and the domain renewal setting no longer
  shows an internal setting key in its help text.
