### Changed

- **Country settings are chosen by name (#418).** Default country, Seller tax country and VAT home country in Admin
  Settings are now searchable lists of country names instead of two-letter text boxes; the ISO code is still what
  is stored, so existing values keep working. The server refuses a code that is not a known country. The two tax
  countries can be cleared.
