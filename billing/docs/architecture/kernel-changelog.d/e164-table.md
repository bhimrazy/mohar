### E.164 calling-code table (#381)

- New `App\Support\CallingCodes`, beside `Countries`: `TABLE` (calling code => ISO countries, prefix-free),
  `split(string $phone, ?string $country = null): ?array{code, national}` for free-text phones (`+`, `00` or national
  input; separators and `(0)` ignored; shared codes resolved by `$country`; null on invalid input, never throws),
  `forCountry()` and `countries()`. Additive; registrar drivers adopt it in a later PR.
  One typed trunk 0 is dropped, except for the keep-zero codes +39 and +378, where it is part of the number.
  Null is the only failure result and covers several causes (not a phone, unknown or non-geographic code, no
  country for a national number, bad length, Australian 0011 and US 011 exit prefixes); callers tell typed-international
  from national input with `str_starts_with($typed, '+')`.
