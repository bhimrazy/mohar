### Fixed

- **eSewa "Test connection" names an unknown product code.** eSewa answers a product code it doesn't know with
  "Unable to fetch merchant key. Please try again later.", not the signature error, so the check used to say it refused
  the test booking and to try again later. It now says eSewa doesn't know the product code. No settings change.
