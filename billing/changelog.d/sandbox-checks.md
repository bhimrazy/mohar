### Fixed

- **eSewa "Test connection" says which credential is wrong.** A wrong secret key (eSewa's ES104) now names the secret
  key for the product code, not "the product code or secret key". An unknown product code, which eSewa answers with
  "Unable to fetch merchant key. Please try again later.", now shows that text with "Check the product code; if it is
  right, eSewa may be unavailable, so try again later." instead of only "refused the test booking". No settings change.
