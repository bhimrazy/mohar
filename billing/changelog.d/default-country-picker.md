### Changed

- **Country settings are chosen by name (#418).** Default country, Seller tax country and VAT home country in Admin
  Settings are now searchable lists of country names instead of two-letter text boxes; the ISO code is still what
  is stored, so existing values keep working. The server refuses a code that is not a known country. The two tax
  countries can be cleared.
- **Country search finds codes and aliases (#418).** Every country picker (settings, client, contact and checkout-adjacent
  forms using it) matches the ISO code and common names (`USA`, `UK`, `UAE`), ignores accents (`cote` finds Côte
  d'Ivoire) and lists names starting with the typed text first. Nepal, India and the United States are listed first.
- **VAT home country is empty on a fresh install, and an EU country when EU VAT rules are on (#418).** Upgrade note:
  the built-in default `DE` is gone; installs that never saved the setting now show "Not set". Saved values are kept,
  and `UK` and `EL` are read as GB and GR. An already stored value that is not a known country no longer blocks saving
  the rest of the section.
