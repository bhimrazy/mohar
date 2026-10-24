### Added

- **Settings choose from lists instead of typed keys (#34).** Ticket statuses that auto-close or await a staff reply,
  and taxed item kinds, are checkbox groups by name. Domain search picks its registrar by name and its lookup and
  suggestion extensions from the active TLDs; extensions typed earlier without the dot or in capitals ("com, NP")
  still show checked. A saved lookup registrar whose driver is gone is listed as "(not available)". The default support department is chosen by name, not by id.
- **Default domain contact is a form (#34).** Admin → Settings → Domain contact replaces the "Default contact (JSON)"
  box, with the country chosen by name.

### Changed

- **Domains settings are split into Domains, Domain contact, Domain sync, Domain reminders and Domain search (#34).**
  The developer-only cart route (`domains.order_route`) is no longer shown; its stored value still applies.
- **Amounts in settings are entered in the currency, not in minor units (#442).** Minimum and maximum deposit,
  maximum credit balance, minimum late fee and the fixed late fee take an amount such as 500.00, shown with the
  default currency; the same amount applies in each client's currency. Upgrade note: the late fee now has two
  settings, "Late fee percent" (`billing.late_fee.amount`, 0 to 100) and "Fixed late fee"
  (`billing.late_fee.fixed_amount`). When the type is Fixed, a migration moves the amount to the new setting and sets
  the percent to 0. While the previous release still runs during a blue-green deploy it reads that 0 as "off"; the
  next late-fee run of the new release charges any fee it skipped.
- **Tax rules and checkout choose the country by name (#442).** The tax-rule form uses the searchable country list,
  the rule list shows the country name, and an unknown country is refused.
- **"Priced for" on Website has a hint (#457 review):** it sets only the displayed tax note and the tax-inclusive
  display; invoices are taxed by the buyer's country and the tax rules.

### Fixed

- **Saving the Billing or Domains settings no longer clears list settings.** Capture retry days, overdue reminder days,
  default nameservers and expiry reminder days kept in config showed blank and were saved empty; they now show their
  values ("3, 7", one nameserver per line). An unchanged save reports "No changes to save."
- **Renewal invoice lead days for domains:** the help said an empty field meant 14 days, but the field could not be
  left empty, and a blank saved earlier fell back to a Billing setting that does not exist. The help is removed, and a
  stored blank now reads as the shipped default (30 days).
- **Website price display:** a currency with no market ("Several markets") is never shown tax-inclusive, and choosing
  the default market explicitly no longer shows "Price and tax display changed" when publishing (#457 review).
- **Searchable selects open upward near the bottom of the screen, close when scrolled under the header, and name the
  list after the field (#414).**
- **Server password, API token and access hash are checked for length when you leave the field (#407).**
