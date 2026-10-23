### E.164 calling-code table (#381)

- New `App\Support\CallingCodes`, beside `Countries`: `TABLE` (calling code => ISO countries, prefix-free),
  `split(string $phone, ?string $country = null): ?array{code, national}` for free-text phones (`+`, `00` or national
  input; separators and `(0)` ignored; shared codes resolved by `$country`; null on invalid input, never throws),
  `forCountry()` and `countries()`. Additive; registrar drivers adopt it in a later PR.
